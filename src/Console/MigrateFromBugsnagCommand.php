<?php

namespace IdeiaCv\BugCatcher\Console;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * Rewrites Bugsnag call sites to IdeiaBugCatcher. The facade has the same methods
 * (notifyException, notifyError, leaveBreadcrumb, registerCallback, …) and the callback's
 * Report the same setters, so only class names change.
 */
class MigrateFromBugsnagCommand extends Command
{
    protected $signature = 'ideiabugcatcher:migrate-bugsnag
        {--dry-run : Mostra o que mudaria sem gravar}
        {--path=* : Pastas a percorrer (por omissão app, routes, config, database, resources/views, tests, Modules)}';

    protected $description = 'Substitui as chamadas ao Bugsnag pelas do IdeiaBugCatcher';

    private const FACADE = 'IdeiaCv\\BugCatcher\\Facades\\BugCatcher';

    public function handle(): int
    {
        $paths = $this->option('path') ?: ['app', 'routes', 'config', 'database', 'resources/views', 'tests', 'Modules'];
        $dirs = array_values(array_filter(array_map(fn ($p) => base_path($p), $paths), 'is_dir'));

        if ($dirs === []) {
            $this->error('Nenhuma das pastas existe.');

            return self::FAILURE;
        }

        $files = Finder::create()->files()->in($dirs)->name('*.php')->exclude(['vendor', 'node_modules']);
        $dryRun = (bool) $this->option('dry-run');
        $changedFiles = 0;
        $changedCalls = 0;
        $leftovers = [];

        foreach ($files as $file) {
            $path = $file->getPathname();
            $original = (string) file_get_contents($path);
            if (stripos($original, 'bugsnag') === false) {
                continue;
            }

            [$rewritten, $calls] = $this->rewrite($original);

            if ($rewritten !== $original) {
                $changedFiles++;
                $changedCalls += $calls;
                $this->line(($dryRun ? '[simulação] ' : '').$this->relative($path)." ({$calls} chamadas)");
                if (! $dryRun) {
                    file_put_contents($path, $rewritten);
                }
            }

            foreach (preg_split('/\R/', $rewritten) as $n => $line) {
                if (stripos($line, 'bugsnag') !== false) {
                    $leftovers[] = $this->relative($path).':'.($n + 1).'  '.trim($line);
                }
            }
        }

        $this->newLine();
        $this->info(($dryRun ? 'Mudariam' : 'Alterados')." {$changedFiles} ficheiros, {$changedCalls} chamadas.");

        if ($leftovers !== []) {
            $this->newLine();
            $this->warn('Referências ao Bugsnag que precisam de revisão manual:');
            foreach (array_slice($leftovers, 0, 200) as $line) {
                $this->line('  '.$line);
            }
            if (count($leftovers) > 200) {
                $this->line('  … e mais '.(count($leftovers) - 200));
            }
        }

        $this->newLine();
        $this->line('A seguir: no .env troque BUGSNAG_* por IDEIABUGCATCHER_API_KEY, no LOG_STACK (ou config/logging.php)');
        $this->line('troque o canal "bugsnag" por "ideiabugcatcher", e depois: composer remove bugsnag/bugsnag-laravel');

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: int} rewritten source and the number of facade calls changed
     */
    public function rewrite(string $source): array
    {
        $calls = 0;
        $facade = self::FACADE;
        $count = fn (string $pattern, string $text) => preg_match_all($pattern, $text);

        // Fully qualified calls.
        $calls += $count('/\\\\?Bugsnag\\\\BugsnagLaravel\\\\Facades\\\\Bugsnag::/', $source);
        $source = preg_replace('/\\\\?Bugsnag\\\\BugsnagLaravel\\\\Facades\\\\Bugsnag::/', '\\\\'.str_replace('\\', '\\\\', $facade).'::', $source);

        // Imports with an alias: the alias keeps working, calls stay as they are.
        $source = preg_replace(
            '/^(\s*)use\s+\\\\?Bugsnag\\\\BugsnagLaravel\\\\Facades\\\\Bugsnag\s+as\s+(\w+)\s*;/m',
            '$1use '.str_replace('\\', '\\\\', $facade).' as $2;',
            $source,
        );

        // Plain imports (the facade or the root "Bugsnag" alias): rename the calls too.
        $imported = false;
        $source = preg_replace_callback(
            '/^(\s*)use\s+(?:\\\\?Bugsnag\\\\BugsnagLaravel\\\\Facades\\\\Bugsnag|\\\\?Bugsnag)\s*;/m',
            function (array $m) use (&$imported, $facade) {
                $imported = true;

                return $m[1].'use '.$facade.';';
            },
            $source,
        );

        // Root alias called with a leading backslash.
        $calls += $count('/\\\\Bugsnag::/', $source);
        $source = preg_replace('/\\\\Bugsnag::/', '\\\\'.str_replace('\\', '\\\\', $facade).'::', $source);

        // Bare "Bugsnag::" calls.
        $bare = '/(?<![\\\\\w$>])Bugsnag::/';
        if ($imported) {
            $calls += $count($bare, $source);
            $source = preg_replace($bare, 'BugCatcher::', $source);
        } elseif (! preg_match('/^\s*namespace\s+[\w\\\\]+\s*;/m', $source)) {
            // No namespace (routes, Blade, config): the global alias resolved it.
            $calls += $count($bare, $source);
            $source = preg_replace($bare, '\\\\'.str_replace('\\', '\\\\', $facade).'::', $source);
        }

        // Classes used in callbacks and breadcrumbs.
        $source = preg_replace('/(\\\\?)Bugsnag\\\\Report\b/', '$1IdeiaCv\\\\BugCatcher\\\\Report', $source);
        $source = preg_replace('/(\\\\?)Bugsnag\\\\Breadcrumbs\\\\Breadcrumb\b/', '$1IdeiaCv\\\\BugCatcher\\\\Breadcrumb', $source);

        return [$source, $calls];
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR);
    }
}
