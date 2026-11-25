<?php

namespace IdeiaCv\BugCatcher\Tests;

use IdeiaCv\BugCatcher\Client;
use IdeiaCv\BugCatcher\Facades\BugCatcher;
use IdeiaCv\BugCatcher\Transport\HttpTransport;
use RuntimeException;

/**
 * Sends to a real IdeiaBugCatcher server. Runs only with
 * IDEIABUGCATCHER_E2E_ENDPOINT=http://localhost:8000 IDEIABUGCATCHER_E2E_KEY=<key> vendor/bin/phpunit
 */
class EndToEndTest extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('IDEIABUGCATCHER_E2E_ENDPOINT') || ! getenv('IDEIABUGCATCHER_E2E_KEY')) {
            $this->markTestSkipped('Set IDEIABUGCATCHER_E2E_ENDPOINT and IDEIABUGCATCHER_E2E_KEY to run.');
        }

        parent::setUp();
    }

    public function test_events_are_accepted_by_the_server(): void
    {
        $transport = new HttpTransport(getenv('IDEIABUGCATCHER_E2E_ENDPOINT'), getenv('IDEIABUGCATCHER_E2E_KEY'), 5);
        BugCatcher::setTransport($transport);

        BugCatcher::leaveBreadcrumb('Antes do erro');
        BugCatcher::notifyException(new RuntimeException('Teste ponta a ponta', 0, new \LogicException('Causa')));

        $this->assertSame(202, app(Client::class)->lastStatus(), (string) $transport->lastError);
    }

    public function test_a_wrong_key_is_refused(): void
    {
        BugCatcher::setTransport(new HttpTransport(getenv('IDEIABUGCATCHER_E2E_ENDPOINT'), 'chave-errada', 5));
        BugCatcher::notifyError('X', 'Y');

        $this->assertSame(401, app(Client::class)->lastStatus());
    }
}
