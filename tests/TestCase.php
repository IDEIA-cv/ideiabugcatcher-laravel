<?php

namespace IdeiaCv\BugCatcher\Tests;

use IdeiaCv\BugCatcher\BugCatcherServiceProvider;
use IdeiaCv\BugCatcher\Facades\BugCatcher;
use IdeiaCv\BugCatcher\Transport\MemoryTransport;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected MemoryTransport $sent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sent = BugCatcher::fake();
    }

    protected function getPackageProviders($app): array
    {
        return [BugCatcherServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['BugCatcher' => BugCatcher::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ideiabugcatcher.api_key', 'test-key');
        $app['config']->set('ideiabugcatcher.project_root', dirname(__DIR__));
        $app['config']->set('ideiabugcatcher.app_version', '2.3.1');
        $app['config']->set('logging.channels.ideiabugcatcher', ['driver' => 'ideiabugcatcher']);
    }

    /** @return array<string, mixed> */
    protected function lastEvent(): array
    {
        $this->assertNotEmpty($this->sent->events, 'Nothing was sent.');

        return $this->sent->events[array_key_last($this->sent->events)];
    }
}
