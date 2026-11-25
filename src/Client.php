<?php

namespace IdeiaCv\BugCatcher;

use Closure;
use IdeiaCv\BugCatcher\Support\Redactor;
use IdeiaCv\BugCatcher\Transport\Transport;
use Illuminate\Contracts\Foundation\Application;
use Throwable;
use WeakMap;

/**
 * Builds events and sends them to IdeiaBugCatcher.
 *
 * Events are queued in memory and sent after the response (terminating callback, then a
 * shutdown function as a fallback); in the console they are sent straight away. The public
 * methods mirror Bugsnag's facade, so `Bugsnag::` call sites only change the class name.
 */
class Client
{
    public const VERSION = '0.1.0';

    /** The server accepts 1 MB (after gunzip); stay well below it. */
    private const MAX_EVENT_BYTES = 800_000;

    private const MAX_BATCH = 50;

    /** @var list<array<string, mixed>> */
    private array $queue = [];

    /** @var list<array<string, mixed>> */
    private array $breadcrumbs = [];

    /** @var list<callable> */
    private array $callbacks = [];

    /** @var WeakMap<Throwable, true> exceptions already sent (handler and log channel both see them) */
    private WeakMap $sent;

    private ?string $context = null;

    private array $metaData = [];

    private ?array $user = null;

    private ?array $job = null;

    private bool $sendImmediately = false;

    private bool $flushing = false;

    private int $lastStatus = 0;

    public function __construct(
        private Application $app,
        private Transport $transport,
        private array $config,
        private StacktraceBuilder $stacktraces,
        private Redactor $redactor,
    ) {
        $this->sent = new WeakMap();
    }

    // ---------------------------------------------------------------- Bugsnag-compatible API

    /**
     * Reports a caught exception (severity warning, like Bugsnag). The callback receives the
     * Report; returning false cancels it.
     */
    public function notifyException(Throwable $throwable, ?callable $callback = null): void
    {
        $this->guard(function () use ($throwable, $callback) {
            if ($this->isIgnored($throwable)) {
                return;
            }

            $report = Report::fromThrowable($throwable, $this->stacktraces)
                ->setSeverity('warning')
                ->setSeverityReason('handledException');

            $this->notify($report, $callback);
        });
    }

    /**
     * Reports an error without an exception, e.g. notifyError('Pagamento', 'SISP recusou').
     */
    public function notifyError(string $name, string $message, ?callable $callback = null): void
    {
        $this->guard(function () use ($name, $message, $callback) {
            $report = Report::fromNamedError($name, $message, $this->stacktraces->fromHere())
                ->setSeverity('warning')
                ->setSeverityReason('handledError');

            $this->notify($report, $callback);
        });
    }

    public function leaveBreadcrumb(string $name, ?string $type = null, array $metaData = []): void
    {
        $this->guard(function () use ($name, $type, $metaData) {
            $this->breadcrumbs[] = [
                'timestamp' => $this->now(),
                'name' => mb_substr($name, 0, 500),
                'type' => in_array($type, Breadcrumb::TYPES, true) ? $type : Breadcrumb::MANUAL_TYPE,
                'metaData' => $this->redactor->clean($metaData, 0, 1000),
            ];

            $max = max(1, (int) ($this->config['breadcrumbs']['max'] ?? 50));
            if (count($this->breadcrumbs) > $max) {
                $this->breadcrumbs = array_slice($this->breadcrumbs, -$max);
            }
        });
    }

    public function clearBreadcrumbs(): void
    {
        $this->breadcrumbs = [];
    }

    /** Runs for every report, before the per-call callback. */
    public function registerCallback(callable $callback): static
    {
        $this->callbacks[] = $callback;

        return $this;
    }

    public function setAppVersion(?string $version): static
    {
        $this->config['app_version'] = $version;

        return $this;
    }

    public function getAppVersion(): ?string
    {
        $version = $this->config['app_version'] ?? null;
        if ($version === null || $version === '') {
            $version = $this->app['config']->get('app.version');
        }

        return is_scalar($version) && $version !== '' ? (string) $version : null;
    }

    public function setReleaseStage(?string $stage): static
    {
        $this->config['release_stage'] = $stage;

        return $this;
    }

    public function getReleaseStage(): string
    {
        $stage = $this->config['release_stage'] ?? null;

        return is_string($stage) && $stage !== '' ? $stage : (string) $this->app->environment();
    }

    /** @param list<string>|null $stages */
    public function setNotifyReleaseStages(?array $stages): static
    {
        $this->config['notify_release_stages'] = $stages ?? [];

        return $this;
    }

    /** Context for later reports ("GET /checkout", "Job: EnviarFactura"). */
    public function setContext(?string $context): static
    {
        $this->context = $context;

        return $this;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    /** Metadata added to every later report. */
    public function setMetaData(array $metaData, bool $merge = true): static
    {
        $this->metaData = $merge ? array_replace_recursive($this->metaData, $metaData) : $metaData;

        return $this;
    }

    /** Overrides the signed-in user for later reports. */
    public function setUser(?array $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function shouldNotify(): bool
    {
        if (empty($this->config['api_key'])) {
            return false;
        }

        $stages = $this->config['notify_release_stages'] ?? [];

        return ! is_array($stages) || $stages === [] || in_array($this->getReleaseStage(), $stages, true);
    }

    // ---------------------------------------------------------------- integration points

    /** From Laravel's exception handler: uncaught exceptions, report(), failed jobs. */
    public function reportUnhandled(Throwable $throwable): void
    {
        $this->guard(function () use ($throwable) {
            if (empty($this->config['report_unhandled']) || $this->isIgnored($throwable)) {
                return;
            }

            $report = Report::fromThrowable($throwable, $this->stacktraces)
                ->setSeverity('error')
                ->setSeverityReason('unhandledException')
                ->setUnhandled(true);

            $this->notify($report);
        });
    }

    /** From the "ideiabugcatcher" log channel. */
    public function reportLog(string $level, string $message, array $context): void
    {
        $this->guard(function () use ($level, $message, $context) {
            $severity = match ($level) {
                'debug', 'info', 'notice' => 'info',
                'warning' => 'warning',
                default => 'error',
            };

            $exception = $context['exception'] ?? null;
            unset($context['exception']);

            if ($exception instanceof Throwable) {
                if ($this->isIgnored($exception)) {
                    return;
                }
                $report = Report::fromThrowable($exception, $this->stacktraces);
            } else {
                $report = Report::fromNamedError('Log '.$level, $message, $this->stacktraces->fromHere());
            }

            $report->setSeverity($severity)->setSeverityReason('log');
            if ($context !== []) {
                $report->setMetaData(['log' => ['message' => $message, 'context' => $context]]);
            }

            $this->notify($report);
        });
    }

    /** Console processes (commands, queue workers) send each report at once. */
    public function sendImmediately(bool $immediately = true): void
    {
        $this->sendImmediately = $immediately;
    }

    /** Sends everything queued. Called after the response and at shutdown. */
    public function flush(): void
    {
        if ($this->flushing || $this->queue === []) {
            return;
        }

        $this->flushing = true;
        try {
            $events = $this->queue;
            $this->queue = [];

            foreach ($this->batches($events) as $batch) {
                $this->lastStatus = $this->transport->send($batch);
            }
        } catch (Throwable) {
            // never break the host application
        } finally {
            $this->flushing = false;
        }
    }

    public function lastStatus(): int
    {
        return $this->lastStatus;
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    public function setTransport(Transport $transport): static
    {
        $this->transport = $transport;

        return $this;
    }

    /** @return list<array<string, mixed>> events waiting to be sent */
    public function queued(): array
    {
        return $this->queue;
    }

    /** The queue job being processed, shown in a "job" tab. */
    public function setJob(?array $job): static
    {
        $this->job = $job;

        return $this;
    }

    /** Drops per-request state (queue workers, Octane). */
    public function resetState(): void
    {
        $this->breadcrumbs = [];
        $this->context = null;
        $this->user = null;
        $this->job = null;
    }

    // ---------------------------------------------------------------- internals

    private function notify(Report $report, ?callable $callback = null): void
    {
        if (! $this->shouldNotify()) {
            return;
        }

        $original = $report->getOriginalError();
        if ($original !== null) {
            if (isset($this->sent[$original])) {
                return;
            }
            $this->sent[$original] = true;
        }

        $report->setAppVersion($this->getAppVersion())
            ->setReleaseStage($this->getReleaseStage())
            ->setContext($this->context ?? $this->defaultContext());

        if ($this->metaData !== []) {
            $report->setMetaData($this->metaData);
        }
        if ($this->job !== null) {
            $report->setMetaData(['job' => $this->job]);
        }

        $user = $this->user ?? $this->currentUser();
        if ($user !== null) {
            $report->setUser($user);
        }

        foreach ([...$this->callbacks, ...($callback ? [$callback] : [])] as $cb) {
            if ($cb($report) === false) {
                return;
            }
        }

        $this->queue[] = $this->toEvent($report);

        // The report itself becomes a breadcrumb for later reports in the same request.
        $this->leaveBreadcrumb($report->getName(), Breadcrumb::ERROR_TYPE, [
            'message' => mb_substr($report->getMessage(), 0, 200),
            'severity' => $report->getSeverity(),
        ]);

        if ($this->sendImmediately) {
            $this->flush();
        }
    }

    /** @return array<string, mixed> one event in the native format (docs/API.md) */
    private function toEvent(Report $report): array
    {
        $event = [
            'exception' => [
                'class' => $report->getName(),
                'message' => $report->getMessage(),
                'stacktrace' => $report->getStacktrace(),
                'previous' => $report->getPrevious(),
            ],
            'severity' => $report->getSeverity(),
            'handled' => ! $report->isUnhandled(),
            'severity_reason' => $report->getSeverityReason(),
            'release_stage' => $report->getReleaseStage(),
            'app_version' => $report->getAppVersion(),
            'context' => $report->getContext() !== null ? mb_substr($report->getContext(), 0, 500) : null,
            'hostname' => gethostname() ?: null,
            'occurred_at' => $this->now(),
            'user' => $this->redactor->clean($report->getUser(), 0, 255),
            'request' => $this->request(),
            'metadata' => $this->redactor->clean($report->getMetaData()),
            'breadcrumbs' => $this->breadcrumbs,
            'runtime' => ['versions' => array_filter([
                'php' => PHP_VERSION,
                'laravel' => $this->app->version(),
                'ideiabugcatcher' => self::VERSION,
            ])],
            'notifier' => 'ideiabugcatcher-laravel/'.self::VERSION,
            'grouping_key' => $report->getGroupingHash(),
        ];

        return $this->fit(array_filter($event, fn ($v) => $v !== null && $v !== []));
    }

    /** Drops the bulkiest parts until the event fits the server's limit. */
    private function fit(array $event): array
    {
        $steps = [
            fn (array $e) => $this->withoutCode($e),
            fn (array $e) => array_diff_key($e, ['breadcrumbs' => 1]),
            fn (array $e) => array_diff_key($e, ['metadata' => 1]),
            fn (array $e) => array_diff_key($e, ['request' => 1]),
            function (array $e) {
                $e['exception']['message'] = mb_substr((string) $e['exception']['message'], 0, 10000);
                unset($e['exception']['previous']);

                return $e;
            },
        ];

        foreach ($steps as $step) {
            if ($this->size($event) <= self::MAX_EVENT_BYTES) {
                break;
            }
            $event = $step($event);
        }

        return $event;
    }

    private function withoutCode(array $event): array
    {
        $strip = fn (array $frames) => array_map(fn ($f) => array_diff_key($f, ['code' => 1]), $frames);

        $event['exception']['stacktrace'] = $strip($event['exception']['stacktrace'] ?? []);
        foreach ($event['exception']['previous'] ?? [] as $i => $previous) {
            $event['exception']['previous'][$i]['stacktrace'] = $strip($previous['stacktrace'] ?? []);
        }

        return $event;
    }

    private function size(array $value): int
    {
        return strlen((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /** @return iterable<list<array<string, mixed>>> */
    private function batches(array $events): iterable
    {
        $batch = [];
        $bytes = 0;
        foreach ($events as $event) {
            $size = $this->size($event);
            if ($batch !== [] && (count($batch) >= self::MAX_BATCH || $bytes + $size > self::MAX_EVENT_BYTES)) {
                yield $batch;
                $batch = [];
                $bytes = 0;
            }
            $batch[] = $event;
            $bytes += $size;
        }

        if ($batch !== []) {
            yield $batch;
        }
    }

    private function request(): array
    {
        if (! $this->inHttp() || ! $this->app->bound('request')) {
            return [];
        }

        try {
            /** @var \Illuminate\Http\Request $request */
            $request = $this->app->make('request');

            $data = [
                'url' => $request->fullUrl(),
                'httpMethod' => $request->getMethod(),
                'clientIp' => $request->ip(),
                'referer' => $request->headers->get('referer'),
            ];

            if (! empty($this->config['request']['headers'])) {
                $headers = [];
                foreach ($request->headers->all() as $name => $values) {
                    $headers[$name] = implode(', ', (array) $values);
                }
                $data['headers'] = $this->redactor->clean($headers, 0, 1000);
            }

            if (! empty($this->config['request']['params'])) {
                $params = $request->request->all() + $request->query->all();
                if ($request->isJson()) {
                    $json = $request->json()->all();
                    $params = is_array($json) ? $json + $params : $params;
                }
                if ($params !== []) {
                    $data['params'] = $this->redactor->clean($params, 0, 1000);
                }
            }

            return array_filter($data, fn ($v) => $v !== null && $v !== []);
        } catch (Throwable) {
            return [];
        }
    }

    private function defaultContext(): ?string
    {
        try {
            if (! $this->inHttp()) {
                $argv = $_SERVER['argv'] ?? [];

                return is_array($argv) && isset($argv[1]) ? 'artisan '.$argv[1] : null;
            }

            if (! $this->app->bound('request')) {
                return null;
            }

            $request = $this->app->make('request');
            $route = $request->route();
            $path = is_object($route) && method_exists($route, 'uri') ? $route->uri() : $request->path();

            return $request->getMethod().' /'.ltrim((string) $path, '/');
        } catch (Throwable) {
            return null;
        }
    }

    /** The signed-in user, only if the guard already loaded it (no queries from here). */
    private function currentUser(): ?array
    {
        if (empty($this->config['user']) || ! $this->app->resolved('auth')) {
            return null;
        }

        try {
            $guard = $this->app->make('auth')->guard();
            if (! method_exists($guard, 'hasUser') || ! $guard->hasUser()) {
                return null;
            }

            $user = $guard->user();
            if ($user === null) {
                return null;
            }

            $attribute = fn (string $key) => is_object($user) && isset($user->{$key}) && is_scalar($user->{$key}) ? (string) $user->{$key} : null;

            return array_filter([
                'id' => method_exists($user, 'getAuthIdentifier') ? (string) $user->getAuthIdentifier() : $attribute('id'),
                'email' => $attribute('email'),
                'name' => $attribute('name'),
            ], fn ($v) => $v !== null && $v !== '');
        } catch (Throwable) {
            return null;
        }
    }

    /** Web request (or a test of one: tests run in the console but call routes). */
    private function inHttp(): bool
    {
        return ! $this->app->runningInConsole() || $this->app->runningUnitTests();
    }

    private function isIgnored(Throwable $throwable): bool
    {
        foreach ((array) ($this->config['ignore_exceptions'] ?? []) as $class) {
            if (is_string($class) && $throwable instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.vP');
    }

    /** Reporting must never throw into the host application. */
    private function guard(Closure $work): void
    {
        try {
            $work();
        } catch (Throwable) {
            // swallow
        }
    }
}
