<?php

namespace IdeiaCv\BugCatcher\Tests;

use IdeiaCv\BugCatcher\Console\MigrateFromBugsnagCommand;

class MigrateFromBugsnagTest extends TestCase
{
    private function rewrite(string $source): array
    {
        return (new MigrateFromBugsnagCommand())->rewrite($source);
    }

    public function test_imported_facade_and_calls_are_renamed(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Services;

use Bugsnag\BugsnagLaravel\Facades\Bugsnag;
use Bugsnag\Report;

class Sisp
{
    public function pagar()
    {
        try {
            $this->x();
        } catch (\Exception $e) {
            Bugsnag::notifyException($e);
            Bugsnag::notifyException($e, function (Report $report) {
                $report->setSeverity('error');
            });
            $this->Bugsnag::foo();
        }
    }
}
PHP;
        [$out, $calls] = $this->rewrite($source);

        $this->assertSame(2, $calls);
        $this->assertStringContainsString('use IdeiaCv\BugCatcher\Facades\BugCatcher;', $out);
        $this->assertStringContainsString('use IdeiaCv\BugCatcher\Report;', $out);
        $this->assertStringContainsString('            BugCatcher::notifyException($e);', $out);
        $this->assertStringContainsString('$this->Bugsnag::foo();', $out);
        $this->assertStringNotContainsString('use Bugsnag', $out);
    }

    public function test_root_alias_fully_qualified_names_and_aliases(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Http\Controllers;

use Bugsnag\BugsnagLaravel\Facades\Bugsnag as Reporter;

class A
{
    public function a($e)
    {
        \Bugsnag::notifyException($e, fn (\Bugsnag\Report $r) => $r->setContext('x'));
        \Bugsnag\BugsnagLaravel\Facades\Bugsnag::leaveBreadcrumb('y', \Bugsnag\Breadcrumbs\Breadcrumb::USER_TYPE);
        Reporter::notifyError('A', 'B');
    }
}
PHP;
        [$out, $calls] = $this->rewrite($source);

        $this->assertSame(2, $calls);
        $this->assertStringContainsString('use IdeiaCv\BugCatcher\Facades\BugCatcher as Reporter;', $out);
        $this->assertStringContainsString('\IdeiaCv\BugCatcher\Facades\BugCatcher::notifyException($e, fn (\IdeiaCv\BugCatcher\Report $r)', $out);
        $this->assertStringContainsString('\IdeiaCv\BugCatcher\Facades\BugCatcher::leaveBreadcrumb(\'y\', \IdeiaCv\BugCatcher\Breadcrumb::USER_TYPE);', $out);
        $this->assertStringContainsString('Reporter::notifyError', $out);
        $this->assertStringNotContainsString('Bugsnag', $out);
    }

    public function test_files_without_a_namespace_use_the_full_class_name(): void
    {
        [$out, $calls] = $this->rewrite("<?php\n\nRoute::get('/', function () {\n    Bugsnag::notifyError('A', 'B');\n});\n");

        $this->assertSame(1, $calls);
        $this->assertStringContainsString('    \IdeiaCv\BugCatcher\Facades\BugCatcher::notifyError(', $out);
    }

    public function test_the_rewritten_code_is_valid_php(): void
    {
        $source = "<?php\nnamespace App;\nuse Bugsnag;\nclass B { public function b(\$e) { Bugsnag::notifyException(\$e); } }\n";
        [$out] = $this->rewrite($source);

        $file = tempnam(sys_get_temp_dir(), 'mig').'.php';
        file_put_contents($file, $out);
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $lint, $code);
        unlink($file);

        $this->assertSame(0, $code, implode("\n", $lint));
        $this->assertStringContainsString('use IdeiaCv\BugCatcher\Facades\BugCatcher;', $out);
        $this->assertStringContainsString('BugCatcher::notifyException($e);', $out);
    }

    public function test_command_rewrites_files_on_disk_and_lists_leftovers(): void
    {
        $dir = sys_get_temp_dir().'/ibc-'.uniqid();
        mkdir($dir.'/app', 0777, true);
        file_put_contents($dir.'/app/X.php', "<?php\nnamespace App;\nuse Bugsnag\\BugsnagLaravel\\Facades\\Bugsnag;\nclass X { public function x(\$e) { Bugsnag::notifyException(\$e); app('bugsnag')->flush(); } }\n");
        $this->app->setBasePath($dir);

        $this->artisan('ideiabugcatcher:migrate-bugsnag', ['--dry-run' => true])
            ->expectsOutputToContain('[simulação] app/X.php (1 chamadas)')
            ->assertExitCode(0);
        $this->assertStringContainsString('use Bugsnag', file_get_contents($dir.'/app/X.php'));

        $this->artisan('ideiabugcatcher:migrate-bugsnag')
            ->expectsOutputToContain("app('bugsnag')")
            ->assertExitCode(0);
        $this->assertStringContainsString('BugCatcher::notifyException($e);', file_get_contents($dir.'/app/X.php'));
    }
}
