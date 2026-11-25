<?php

namespace IdeiaCv\BugCatcher\Transport;

interface Transport
{
    /**
     * Sends one batch ({"events": [...]}). Never throws.
     *
     * @param list<array<string, mixed>> $events
     * @return int HTTP status, or 0 when the server could not be reached
     */
    public function send(array $events): int;
}
