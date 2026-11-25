<?php

namespace IdeiaCv\BugCatcher;

use Throwable;

/**
 * Turns PHP traces into the frames IdeiaBugCatcher groups on: the throw site first, paths
 * relative to the project root, "Class::method" names and a few lines of code around
 * in-project frames.
 */
class StacktraceBuilder
{
    private const MAX_FRAMES = 100;

    /** Frames that get source code: enough for the page, cheap to read. */
    private const MAX_CODE_FRAMES = 20;

    private string $root;

    /** @var array<string, list<string>|false> */
    private array $files = [];

    public function __construct(string $projectRoot, private int $codeLines = 3)
    {
        $this->root = rtrim(str_replace('\\', '/', $projectRoot), '/').'/';
    }

    /** @return list<array<string, mixed>> */
    public function fromThrowable(Throwable $throwable): array
    {
        return $this->build($throwable->getTrace(), $throwable->getFile(), $throwable->getLine());
    }

    /**
     * The trace of the current call, without the package's own frames.
     *
     * @return list<array<string, mixed>>
     */
    public function fromHere(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        // Skip the package's own calls (and the facade): the last of them holds the call site in
        // the project, which becomes the first frame.
        $k = -1;
        while (isset($trace[$k + 1]) && ($this->isOwnFrame($trace[$k + 1]) || $this->isFacadeFrame($trace[$k + 1]))) {
            $k++;
        }

        if ($k < 0 || ! isset($trace[$k]['file'])) {
            return $this->build($trace, '', 0);
        }

        return $this->build(array_slice($trace, $k + 1), $trace[$k]['file'], (int) ($trace[$k]['line'] ?? 0));
    }

    /**
     * Each PHP trace entry holds a call site (file/line) and the function it called; a frame
     * needs the call site plus the function that *contains* it, which is the next entry's.
     *
     * @return list<array<string, mixed>>
     */
    private function build(array $trace, string $file, int $line): array
    {
        $frames = [];
        $codeFrames = 0;

        $sites = [['file' => $file, 'line' => $line]];
        foreach ($trace as $entry) {
            $sites[] = ['file' => $entry['file'] ?? null, 'line' => $entry['line'] ?? null];
        }

        foreach ($sites as $i => $site) {
            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }
            if (! is_string($site['file']) || $site['file'] === '') {
                continue; // internal call (call_user_func, array_map, …)
            }

            $path = $this->relative($site['file']);
            $inProject = $this->inProject($site['file'], $path);

            $frame = [
                'file' => $path,
                'line' => (int) $site['line'],
                'method' => $this->method($trace[$i] ?? null),
                'in_project' => $inProject,
            ];

            if ($inProject && $this->codeLines > 0 && $codeFrames < self::MAX_CODE_FRAMES) {
                $code = $this->code($site['file'], (int) $site['line']);
                if ($code !== []) {
                    $frame['code'] = $code;
                    $codeFrames++;
                }
            }

            $frames[] = $frame;
        }

        return $frames;
    }

    private function method(?array $entry): string
    {
        if ($entry === null || ! isset($entry['function'])) {
            return '[main]';
        }

        return isset($entry['class']) ? $entry['class'].'::'.$entry['function'] : $entry['function'];
    }

    private function relative(string $file): string
    {
        $normalized = str_replace('\\', '/', $file);

        return str_starts_with($normalized, $this->root) ? substr($normalized, strlen($this->root)) : $normalized;
    }

    private function inProject(string $file, string $relative): bool
    {
        return str_starts_with(str_replace('\\', '/', $file), $this->root)
            && ! str_starts_with($relative, 'vendor/')
            && ! str_contains($relative, '/vendor/');
    }

    /** @return array<int, string> */
    private function code(string $file, int $line): array
    {
        if (! array_key_exists($file, $this->files)) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            $this->files[$file] = is_array($lines) ? $lines : false;
        }

        $lines = $this->files[$file];
        if ($lines === false || $line < 1) {
            return [];
        }

        $code = [];
        $from = max(1, $line - $this->codeLines);
        $to = min(count($lines), $line + $this->codeLines);
        for ($n = $from; $n <= $to; $n++) {
            $code[$n] = mb_substr($lines[$n - 1], 0, 200);
        }

        return $code;
    }

    private function isOwnFrame(array $entry): bool
    {
        $class = $entry['class'] ?? '';

        return str_starts_with($class, __NAMESPACE__.'\\') && ! str_starts_with($class, __NAMESPACE__.'\\Tests\\');
    }

    /** Facades, helpers and the logging stack between the caller and the package. */
    private function isFacadeFrame(array $entry): bool
    {
        $class = $entry['class'] ?? '';
        if ($class === '') {
            // Laravel's global helpers: their entry holds the call site in the project.
            return in_array($entry['function'] ?? '', ['logger', 'info'], true);
        }

        foreach (['Illuminate\\Support\\Facades\\', 'Illuminate\\Log\\', 'Monolog\\', 'Psr\\Log\\'] as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
