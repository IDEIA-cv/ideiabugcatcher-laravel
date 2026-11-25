<?php

namespace IdeiaCv\BugCatcher\Transport;

/**
 * POSTs batches to {endpoint}/api/v1/events with the project key. Uses cURL when available
 * (hard timeout), PHP streams otherwise. Errors are swallowed: reporting must never break the
 * application that is reporting.
 */
class HttpTransport implements Transport
{
    public ?string $lastError = null;

    public function __construct(
        private string $endpoint,
        private string $apiKey,
        private float $timeout = 3.0,
        private string $userAgent = 'ideiabugcatcher-laravel',
    ) {
    }

    public function url(): string
    {
        return rtrim($this->endpoint, '/').'/api/v1/events';
    }

    public function send(array $events): int
    {
        $this->lastError = null;

        try {
            $body = json_encode(['events' => $events], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($body === false) {
                $this->lastError = 'json_encode: '.json_last_error_msg();

                return 0;
            }

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-IdeiaBug-Key: '.$this->apiKey,
                'User-Agent: '.$this->userAgent,
            ];

            if (function_exists('gzencode')) {
                $gzipped = gzencode($body, 6);
                if ($gzipped !== false) {
                    $body = $gzipped;
                    $headers[] = 'Content-Encoding: gzip';
                }
            }

            return function_exists('curl_init') ? $this->viaCurl($body, $headers) : $this->viaStream($body, $headers);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return 0;
        }
    }

    private function viaCurl(string $body, array $headers): int
    {
        $ch = curl_init($this->url());
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) min(2000, $this->timeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_NOSIGNAL => true,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($response === false) {
            $this->lastError = curl_error($ch);
        } elseif ($status >= 300) {
            $this->lastError = 'HTTP '.$status.': '.mb_substr((string) $response, 0, 300);
        }
        curl_close($ch);

        return $status;
    }

    private function viaStream(string $body, array $headers): int
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
        ]]);

        $response = @file_get_contents($this->url(), false, $context);
        $statusLine = $http_response_header[0] ?? '';
        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $m) ? (int) $m[1] : 0;

        if ($response === false || $status === 0) {
            $this->lastError = error_get_last()['message'] ?? 'Sem resposta do servidor.';
        } elseif ($status >= 300) {
            $this->lastError = 'HTTP '.$status.': '.mb_substr((string) $response, 0, 300);
        }

        return $status;
    }
}
