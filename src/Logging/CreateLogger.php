<?php

namespace IdeiaCv\BugCatcher\Logging;

use IdeiaCv\BugCatcher\Client;
use Illuminate\Contracts\Foundation\Application;
use Monolog\Logger;

/**
 * Builds the "ideiabugcatcher" channel:
 *   'ideiabugcatcher' => ['driver' => 'ideiabugcatcher'],          // config/logging.php
 *   LOG_STACK=daily,ideiabugcatcher                                  // .env (Laravel 11+)
 */
class CreateLogger
{
    public function __invoke(Application $app, array $config): Logger
    {
        $level = $config['level'] ?? $app['config']->get('ideiabugcatcher.log_level', 'error');

        return new Logger('ideiabugcatcher', [
            new BugCatcherHandler($app->make(Client::class), Logger::toMonologLevel($level)),
        ]);
    }
}
