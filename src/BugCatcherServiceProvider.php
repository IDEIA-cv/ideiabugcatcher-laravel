<?php

namespace IdeiaCv\BugCatcher;

use IdeiaCv\BugCatcher\Console\MigrateFromBugsnagCommand;
use IdeiaCv\BugCatcher\Console\TestCommand;
use IdeiaCv\BugCatcher\Logging\CreateLogger;
use IdeiaCv\BugCatcher\Support\Redactor;
use IdeiaCv\BugCatcher\Transport\HttpTransport;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Throwable;

class BugCatcherServiceProvider extends ServiceProvider
{
    /** @var \WeakMap<object, true>|null exception handlers already hooked */
    private static ?\WeakMap $hooked = null;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ideiabugcatcher.php', 'ideiabugcatcher');

        $this->app->singleton(Client::class, function ($app) {
            $config = $app['config']->get('ideiabugcatcher', []);

            return new Client(
                $app,
                new HttpTransport(
                    (string) ($config['endpoint'] ?? ''),
                    (string) ($config['api_key'] ?? ''),
                    (float) ($config['timeout'] ?? 3),
                    'ideiabugcatcher-laravel/'.Client::VERSION,
                ),
                $config,
                new StacktraceBuilder((string) (($config['project_root'] ?? null) ?: $app->basePath()), (int) ($config['code_context_lines'] ?? 3)),
                new Redactor((array) ($config['redact_keys'] ?? [])),
            );
        });
        $this->app->alias(Client::class, 'ideiabugcatcher');

        // Uncaught exceptions: hook Laravel's handler as soon as it exists.
        $this->app->afterResolving(ExceptionHandler::class, fn ($handler) => $this->hookExceptionHandler($handler));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ideiabugcatcher.php' => $this->app->configPath('ideiabugcatcher.php'),
        ], 'ideiabugcatcher-config');

        if ($this->app->runningInConsole()) {
            $this->commands([TestCommand::class, MigrateFromBugsnagCommand::class]);
        }

        if ($this->app->resolved(ExceptionHandler::class)) {
            $this->hookExceptionHandler($this->app->make(ExceptionHandler::class));
        }

        $this->app['log']->extend('ideiabugcatcher', function ($app, array $config) {
            return (new CreateLogger())($app, $config);
        });

        $this->registerDelivery();
        $this->registerBreadcrumbs();
    }

    private function hookExceptionHandler(object $handler): void
    {
        self::$hooked ??= new \WeakMap();
        if (isset(self::$hooked[$handler]) || ! method_exists($handler, 'reportable')) {
            return;
        }
        self::$hooked[$handler] = true;

        // Runs only for exceptions the handler would report ($dontReport is checked first).
        // Returning nothing lets the handler go on and log as usual.
        $handler->reportable(function (Throwable $e) {
            $this->app->make(Client::class)->reportUnhandled($e);
        });
    }

    private function registerDelivery(): void
    {
        $client = fn () => $this->app->make(Client::class);

        if ($this->app->runningInConsole()) {
            // Commands and queue workers: nobody waits for a response, and a worker may run for days.
            $client()->sendImmediately();
        }

        // Web requests: after the response has gone out, then at shutdown for anything later
        // (fatal errors are reported by Laravel's own shutdown handler, which runs before this one).
        $this->app->terminating(fn () => $client()->flush());

        $app = $this->app;
        register_shutdown_function(static function () use ($app) {
            try {
                if ($app->resolved(Client::class)) {
                    $app->make(Client::class)->flush();
                }
            } catch (Throwable) {
                // the application may already be torn down
            }
        });
    }

    private function registerBreadcrumbs(): void
    {
        $config = (array) $this->app['config']->get('ideiabugcatcher.breadcrumbs', []);
        $events = $this->app['events'];
        $client = fn (): Client => $this->app->make(Client::class);

        if (! empty($config['logs'])) {
            $events->listen(MessageLogged::class, function (MessageLogged $event) use ($client) {
                if (($event->context['exception'] ?? null) instanceof Throwable) {
                    return; // reported on its own
                }
                $context = $event->context;
                $client()->leaveBreadcrumb(mb_substr((string) $event->message, 0, 200), Breadcrumb::LOG_TYPE, array_filter([
                    'level' => $event->level,
                    'context' => $context ?: null,
                ]));
            });
        }

        if (! empty($config['queries'])) {
            $withBindings = ! empty($config['query_bindings']);
            $events->listen(QueryExecuted::class, function (QueryExecuted $query) use ($client, $withBindings) {
                $client()->leaveBreadcrumb('Query executed', Breadcrumb::PROCESS_TYPE, array_filter([
                    'sql' => mb_substr($query->sql, 0, 1000),
                    'time' => $query->time,
                    'connection' => $query->connectionName,
                    'bindings' => $withBindings ? $query->bindings : null,
                ], fn ($v) => $v !== null));
            });
        }

        if (! empty($config['jobs'])) {
            $events->listen(JobProcessing::class, function (JobProcessing $event) use ($client) {
                $job = $event->job;
                $client()->resetState();
                $client()->setJob([
                    'name' => $job->resolveName(),
                    'queue' => $job->getQueue(),
                    'connection' => $event->connectionName,
                    'attempts' => $job->attempts(),
                ]);
                $client()->setContext('Job: '.$job->resolveName());
                $client()->leaveBreadcrumb('Job started', Breadcrumb::PROCESS_TYPE, ['name' => $job->resolveName()]);
            });
            $after = function () use ($client) {
                $client()->flush();
                $client()->setJob(null);
                $client()->setContext(null);
            };
            $events->listen(JobProcessed::class, $after);
            $events->listen(JobFailed::class, $after);
        }

        if (! empty($config['commands'])) {
            $events->listen(CommandStarting::class, function (CommandStarting $event) use ($client) {
                if ($event->command === null || in_array($event->command, ['queue:work', 'queue:listen', 'horizon:work'], true)) {
                    return;
                }
                $client()->setContext('artisan '.$event->command);
                $client()->leaveBreadcrumb('Command started', Breadcrumb::PROCESS_TYPE, ['command' => $event->command]);
            });
        }

        // Octane keeps the app (and this client) between requests.
        $events->listen('Laravel\Octane\Events\RequestReceived', fn () => $client()->resetState());
        $events->listen('Laravel\Octane\Events\RequestTerminated', fn () => $client()->flush());
    }
}
