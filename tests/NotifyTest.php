<?php

namespace IdeiaCv\BugCatcher\Tests;

use IdeiaCv\BugCatcher\Breadcrumb;
use IdeiaCv\BugCatcher\Facades\BugCatcher;
use IdeiaCv\BugCatcher\Report;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

class NotifyTest extends TestCase
{
    public function test_handled_exception_is_sent_as_a_warning_with_its_stacktrace(): void
    {
        $line = __LINE__ + 1;
        $e = new RuntimeException('Timeout ao contactar a SISP');
        BugCatcher::notifyException($e);

        $event = $this->lastEvent();
        $this->assertSame(RuntimeException::class, $event['exception']['class']);
        $this->assertSame('Timeout ao contactar a SISP', $event['exception']['message']);
        $this->assertSame('warning', $event['severity']);
        $this->assertTrue($event['handled']);
        $this->assertSame('handledException', $event['severity_reason']);
        $this->assertSame('2.3.1', $event['app_version']);
        $this->assertSame('testing', $event['release_stage']);
        $this->assertSame(PHP_VERSION, $event['runtime']['versions']['php']);
        $this->assertSame(app()->version(), $event['runtime']['versions']['laravel']);
        $this->assertStringStartsWith('ideiabugcatcher-laravel/', $event['notifier']);
        $this->assertNotEmpty($event['occurred_at']);

        $frame = $event['exception']['stacktrace'][0];
        $this->assertSame('tests/NotifyTest.php', $frame['file']);
        $this->assertSame($line, $frame['line']);
        $this->assertSame(self::class.'::'.__FUNCTION__, $frame['method']);
        $this->assertTrue($frame['in_project']);
        $this->assertStringContainsString('Timeout ao contactar a SISP', $frame['code'][$line]);

        $vendorFrames = array_filter($event['exception']['stacktrace'], fn ($f) => str_contains($f['file'], 'vendor/'));
        $this->assertNotEmpty($vendorFrames);
        foreach ($vendorFrames as $f) {
            $this->assertFalse($f['in_project']);
            $this->assertArrayNotHasKey('code', $f);
        }
    }

    public function test_chained_causes_are_sent_with_their_own_stacktraces(): void
    {
        $root = new InvalidArgumentException('NIF inválido');
        $middle = new LogicException('Falha a validar cliente', 0, $root);
        BugCatcher::notifyException(new RuntimeException('Falha ao gravar factura', 0, $middle));

        $previous = $this->lastEvent()['exception']['previous'];
        $this->assertSame([LogicException::class, InvalidArgumentException::class], array_column($previous, 'class'));
        $this->assertSame('NIF inválido', $previous[1]['message']);
        $this->assertSame('tests/NotifyTest.php', $previous[1]['stacktrace'][0]['file']);
    }

    public function test_callbacks_can_change_the_report_like_bugsnag_callbacks(): void
    {
        BugCatcher::registerCallback(fn (Report $r) => $r->setMetaData(['global' => ['a' => 1]]));

        BugCatcher::notifyException(new RuntimeException('x'), function (Report $report) {
            $report->setSeverity('error');
            $report->setMetaData(['pedido' => ['id' => 123, 'password' => 'segredo']]);
            $report->setUser(['id' => 42, 'email' => 'maria@example.cv']);
            $report->setContext('Checkout');
            $report->setGroupingHash('sisp-timeout');
        });

        $event = $this->lastEvent();
        $this->assertSame('error', $event['severity']);
        $this->assertSame(['global' => ['a' => 1], 'pedido' => ['id' => 123, 'password' => '[FILTRADO]']], $event['metadata']);
        $this->assertSame(['id' => 42, 'email' => 'maria@example.cv'], $event['user']);
        $this->assertSame('Checkout', $event['context']);
        $this->assertSame('sisp-timeout', $event['grouping_key']);
    }

    public function test_a_callback_returning_false_cancels_the_report(): void
    {
        BugCatcher::notifyException(new RuntimeException('x'), fn () => false);

        $this->assertSame([], $this->sent->events);
    }

    public function test_notify_error_points_at_the_caller(): void
    {
        $line = __LINE__ + 1;
        BugCatcher::notifyError('PagamentoRecusado', 'A SISP recusou o pagamento');

        $event = $this->lastEvent();
        $this->assertSame('PagamentoRecusado', $event['exception']['class']);
        $this->assertSame('A SISP recusou o pagamento', $event['exception']['message']);
        $this->assertSame('handledError', $event['severity_reason']);
        $this->assertSame('tests/NotifyTest.php', $event['exception']['stacktrace'][0]['file']);
        $this->assertSame($line, $event['exception']['stacktrace'][0]['line']);
        $this->assertSame(self::class.'::'.__FUNCTION__, $event['exception']['stacktrace'][0]['method']);
    }

    public function test_uncaught_exceptions_reach_the_handler_and_are_sent_once(): void
    {
        $e = new RuntimeException('Rebentou');
        $handler = app(ExceptionHandler::class);
        $handler->report($e);
        $handler->report($e);
        Log::channel('ideiabugcatcher')->error('Rebentou', ['exception' => $e]);

        $this->assertCount(1, $this->sent->events);
        $event = $this->lastEvent();
        $this->assertFalse($event['handled']);
        $this->assertSame('error', $event['severity']);
        $this->assertSame('unhandledException', $event['severity_reason']);
    }

    public function test_ignored_exceptions_are_not_sent(): void
    {
        config(['ideiabugcatcher.ignore_exceptions' => [LogicException::class]]);
        $this->refreshClient();

        BugCatcher::notifyException(new InvalidArgumentException('x'));
        app(ExceptionHandler::class)->report(new LogicException('y'));

        $this->assertSame([], $this->sent->events);
    }

    public function test_nothing_is_sent_without_a_key_or_outside_the_notifying_stages(): void
    {
        config(['ideiabugcatcher.api_key' => null]);
        $this->refreshClient();
        BugCatcher::notifyException(new RuntimeException('x'));
        $this->assertSame([], $this->sent->events);

        config(['ideiabugcatcher.api_key' => 'k', 'ideiabugcatcher.notify_release_stages' => ['production']]);
        $this->refreshClient();
        BugCatcher::notifyException(new RuntimeException('x'));
        $this->assertSame([], $this->sent->events);

        BugCatcher::setReleaseStage('production');
        BugCatcher::notifyException(new RuntimeException('x'));
        $this->assertCount(1, $this->sent->events);
    }

    public function test_log_channel_reports_errors_and_keeps_lower_levels_as_breadcrumbs(): void
    {
        Log::info('Pagamento iniciado', ['pedido' => 7]);
        BugCatcher::leaveBreadcrumb('Botão pagar', Breadcrumb::USER_TYPE, ['token' => 'abc']);
        Log::channel('ideiabugcatcher')->info('não chega a evento');
        Log::channel('ideiabugcatcher')->error('Saldo negativo', ['conta' => 9, 'api_key' => 'k']);

        $this->assertCount(1, $this->sent->events);
        $event = $this->lastEvent();
        $this->assertSame('Log error', $event['exception']['class']);
        $this->assertSame('Saldo negativo', $event['exception']['message']);
        $this->assertSame('log', $event['severity_reason']);
        $this->assertSame(['conta' => 9, 'api_key' => '[FILTRADO]'], $event['metadata']['log']['context']);
        $this->assertSame('tests/NotifyTest.php', $event['exception']['stacktrace'][0]['file']);

        $crumbs = array_column($event['breadcrumbs'], null, 'name');
        $this->assertSame('log', $crumbs['Pagamento iniciado']['type']);
        $this->assertSame(['level' => 'info', 'context' => ['pedido' => 7]], $crumbs['Pagamento iniciado']['metaData']);
        $this->assertSame(['token' => '[FILTRADO]'], $crumbs['Botão pagar']['metaData']);
    }

    public function test_logger_helper_points_at_the_caller(): void
    {
        config(['logging.default' => 'ideiabugcatcher']);
        $line = __LINE__ + 1;
        logger()->error('Pelo helper');

        $frame = $this->lastEvent()['exception']['stacktrace'][0];
        $this->assertSame('tests/NotifyTest.php', $frame['file']);
        $this->assertSame($line, $frame['line']);
    }

    public function test_breadcrumbs_are_capped(): void
    {
        config(['ideiabugcatcher.breadcrumbs.max' => 5]);
        $this->refreshClient();
        for ($i = 1; $i <= 8; $i++) {
            BugCatcher::leaveBreadcrumb("passo {$i}");
        }
        BugCatcher::notifyException(new RuntimeException('x'));

        $this->assertSame(['passo 4', 'passo 5', 'passo 6', 'passo 7', 'passo 8'], array_column($this->lastEvent()['breadcrumbs'], 'name'));
    }

    public function test_oversized_events_are_trimmed_below_the_server_limit(): void
    {
        BugCatcher::notifyException(new RuntimeException(str_repeat('á', 600000)), function (Report $r) {
            $r->setMetaData(['grande' => ['x' => str_repeat('y', 5000)]]);
        });

        $json = json_encode(['events' => $this->sent->events]);
        $this->assertLessThan(1_000_000, strlen($json));
        $this->assertSame(RuntimeException::class, $this->lastEvent()['exception']['class']);
    }

    public function test_a_failing_callback_never_breaks_the_application(): void
    {
        BugCatcher::notifyException(new RuntimeException('x'), function () {
            throw new LogicException('callback partido');
        });

        $this->assertSame([], $this->sent->events);
    }

    private function refreshClient(): void
    {
        $this->app->forgetInstance(\IdeiaCv\BugCatcher\Client::class);
        BugCatcher::clearResolvedInstances();
        $this->sent = BugCatcher::fake();
    }
}
