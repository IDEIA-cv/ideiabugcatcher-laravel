<?php

namespace IdeiaCv\BugCatcher\Facades;

use IdeiaCv\BugCatcher\Client;
use IdeiaCv\BugCatcher\Transport\MemoryTransport;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void notifyException(\Throwable $throwable, ?callable $callback = null)
 * @method static void notifyError(string $name, string $message, ?callable $callback = null)
 * @method static void leaveBreadcrumb(string $name, ?string $type = null, array $metaData = [])
 * @method static void clearBreadcrumbs()
 * @method static Client registerCallback(callable $callback)
 * @method static Client setAppVersion(?string $version)
 * @method static string|null getAppVersion()
 * @method static Client setReleaseStage(?string $stage)
 * @method static string getReleaseStage()
 * @method static Client setNotifyReleaseStages(?array $stages)
 * @method static Client setContext(?string $context)
 * @method static Client setMetaData(array $metaData, bool $merge = true)
 * @method static Client setUser(?array $user)
 * @method static void flush()
 *
 * @see Client
 */
class BugCatcher extends Facade
{
    /**
     * Keeps events in memory instead of sending them, for the project's own tests.
     * Read them back from the returned transport's $events.
     */
    public static function fake(): MemoryTransport
    {
        $transport = new MemoryTransport();

        /** @var Client $client */
        $client = static::getFacadeRoot();
        $client->setTransport($transport);
        $client->sendImmediately();

        return $transport;
    }

    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
