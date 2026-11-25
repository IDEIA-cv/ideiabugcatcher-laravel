<?php

namespace IdeiaCv\BugCatcher\Transport;

/**
 * Keeps events in memory instead of sending them: for tests (BugCatcher::fake()).
 */
class MemoryTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    /** @var list<list<array<string, mixed>>> */
    public array $batches = [];

    public function __construct(private int $status = 202)
    {
    }

    public function send(array $events): int
    {
        $this->batches[] = $events;
        array_push($this->events, ...$events);

        return $this->status;
    }
}
