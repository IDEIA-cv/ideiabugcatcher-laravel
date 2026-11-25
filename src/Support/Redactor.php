<?php

namespace IdeiaCv\BugCatcher\Support;

/**
 * Replaces values of sensitive keys before anything leaves the client's server, and keeps
 * nested data small enough to send.
 */
class Redactor
{
    public const FILTERED = '[FILTRADO]';

    /** @var list<string> */
    private array $keys;

    /** @param list<string> $keys */
    public function __construct(array $keys)
    {
        $this->keys = array_values(array_filter(array_map(fn ($k) => strtolower((string) $k), $keys)));
    }

    public function isSensitive(string|int $key): bool
    {
        $key = strtolower((string) $key);
        foreach ($this->keys as $word) {
            if (str_contains($key, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redacts sensitive keys recursively and turns objects into something JSON can carry.
     */
    public function clean(mixed $value, int $depth = 0, int $maxString = 10000): mixed
    {
        if ($depth > 8) {
            return '[...]';
        }

        if (is_array($value)) {
            $clean = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if (++$count > 200) {
                    $clean['…'] = '['.(count($value) - 200).' mais]';
                    break;
                }
                $clean[$key] = $this->isSensitive($key) ? self::FILTERED : $this->clean($item, $depth + 1, $maxString);
            }

            return $clean;
        }

        if (is_string($value)) {
            return mb_strlen($value) > $maxString ? mb_substr($value, 0, $maxString).'…' : $value;
        }

        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : (string) $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? $value->value : $value->name;
        }

        if ($value instanceof \Throwable) {
            return get_class($value).': '.$value->getMessage();
        }

        if ($value instanceof \JsonSerializable) {
            return $this->clean($value->jsonSerialize(), $depth + 1, $maxString);
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            try {
                return $this->clean($value->toArray(), $depth + 1, $maxString);
            } catch (\Throwable) {
                return '['.get_class($value).']';
            }
        }

        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : '['.get_class($value).']';
        }

        return '['.gettype($value).']';
    }
}
