<?php

namespace IdeiaCv\BugCatcher\Logging;

use IdeiaCv\BugCatcher\Client;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

/**
 * Monolog handler behind the "ideiabugcatcher" log channel. Works with Monolog 2 (Laravel 9)
 * and 3 (Laravel 10+): records arrive as arrays or LogRecord objects.
 */
class BugCatcherHandler extends AbstractProcessingHandler
{
    public function __construct(private Client $client, $level = 'debug', bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    /** @param array|\Monolog\LogRecord $record */
    protected function write($record): void
    {
        $data = is_array($record) ? $record : $record->toArray();

        $this->client->reportLog(
            strtolower((string) ($data['level_name'] ?? 'error')),
            (string) ($data['message'] ?? ''),
            is_array($data['context'] ?? null) ? $data['context'] : [],
        );
    }
}
