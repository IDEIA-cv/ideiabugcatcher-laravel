# ideiacv/ideiabugcatcher-laravel

Laravel client for IdeiaBugCatcher. Replaces `bugsnag/bugsnag-laravel`: same facade methods
(`notifyException`, `notifyError`, `leaveBreadcrumb`, `registerCallback`, …) and the same
`Report` setters in callbacks, so switching is a rename.

PHP 8.0+, Laravel 9 to 13. No dependencies beyond Laravel itself (uses cURL when available).

## What it sends

- Uncaught exceptions (anything Laravel's exception handler reports, including failed queue
  jobs), with severity `error`, `handled: false`.
- `BugCatcher::notifyException($e)` from catch blocks: severity `warning`, like Bugsnag.
- Chained causes (`getPrevious()`, up to 10), each with its own stack trace.
- Stack frames relative to the project root, `in_project` for frames outside `vendor/`, and
  3 lines of code around each project frame.
- PHP and Laravel versions, app version, release stage, hostname.
- Request (URL, method, IP, headers, input), route as context (`POST /checkout/{pedido}`),
  signed-in user (id, e-mail, name: only if the guard already loaded it).
- Breadcrumbs: log messages, queries (without bindings), jobs, commands, your own.
- Passwords, tokens, cookies, authorization headers and similar keys are replaced with
  `[FILTRADO]` **before** leaving your server (`redact_keys`).

Web requests send after the response has gone out; commands and queue workers send at once.
Reporting never throws into your application.

## Install

The package is not on Packagist: add this repository to the project's `composer.json` once.

```json
"repositories": [
    {"type": "vcs", "url": "https://github.com/IDEIA-cv/ideiabugcatcher-laravel"}
]
```

```bash
composer require ideiacv/ideiabugcatcher-laravel:^0.1
```

The repository is private, so Composer needs a GitHub token with read access to it
(`composer config --global github-oauth.github.com <token>`, or `auth.json` on servers and CI).

```dotenv
IDEIABUGCATCHER_API_KEY=<key from IdeiaBugCatcher → Projetos → Chaves>
IDEIABUGCATCHER_ENDPOINT=https://bug.ideia.cv
IDEIABUGCATCHER_APP_VERSION=1.4.0        # optional, e.g. set by your deploy from the git tag
```

```bash
php artisan ideiabugcatcher:test          # sends a test error and shows the server's answer
```

That's all for uncaught exceptions. To also turn `Log::error(...)` into events, add the log
channel:

```php
// config/logging.php → 'channels'
'ideiabugcatcher' => ['driver' => 'ideiabugcatcher'],
```

```dotenv
LOG_STACK=daily,ideiabugcatcher           # Laravel 11+; on 9/10 add it to the "stack" channel
```

Optional: `php artisan vendor:publish --tag=ideiabugcatcher-config`.

## Use

```php
use IdeiaCv\BugCatcher\Facades\BugCatcher;
use IdeiaCv\BugCatcher\Report;

try {
    $sisp->pagar($pedido);
} catch (\Throwable $e) {
    BugCatcher::notifyException($e, function (Report $report) use ($pedido) {
        $report->setSeverity('error');
        $report->setMetaData(['pedido' => ['id' => $pedido->id]]);
    });
}

BugCatcher::notifyError('PagamentoRecusado', 'A SISP recusou o pagamento');
BugCatcher::leaveBreadcrumb('Pagamento iniciado', \IdeiaCv\BugCatcher\Breadcrumb::USER_TYPE, ['valor' => 1500]);

// Every report (e.g. in AppServiceProvider::boot):
BugCatcher::registerCallback(fn (Report $r) => $r->setMetaData(['tenant' => ['id' => tenant()->id]]));
```

A callback that returns `false` cancels the report. `Report` also has `setUser()`,
`setContext()`, `setGroupingHash()` (same hash = same issue), `setAppVersion()`.

### Tests in your project

```php
$sent = BugCatcher::fake();
// ... code under test ...
$this->assertSame('PagamentoRecusado', $sent->events[0]['exception']['class']);
```

## Migrating from Bugsnag

```bash
composer require ideiacv/ideiabugcatcher-laravel:^0.1   # after adding the repository above
php artisan ideiabugcatcher:migrate-bugsnag --dry-run     # list what would change
php artisan ideiabugcatcher:migrate-bugsnag
```

The command rewrites `use Bugsnag\BugsnagLaravel\Facades\Bugsnag;`, the root `Bugsnag`
alias, `\Bugsnag::…`, `Bugsnag\Report` and `Bugsnag\Breadcrumbs\Breadcrumb` in `app`, `routes`,
`config`, `database`, `resources/views`, `tests` and `Modules`, then lists any remaining
`Bugsnag` mentions to fix by hand (e.g. `app('bugsnag')`). Then:

1. `.env`: replace `BUGSNAG_API_KEY`/`BUGSNAG_ENDPOINT` with `IDEIABUGCATCHER_API_KEY`
   (and `IDEIABUGCATCHER_ENDPOINT` if not bug.ideia.cv).
2. Log stack: replace the `bugsnag` channel with `ideiabugcatcher`.
3. `composer remove bugsnag/bugsnag-laravel`, run the tests, `php artisan ideiabugcatcher:test`.

Each project reports to one system at a time: deploy the switch in one go.

## Configuration

| `.env` | Default | |
|---|---|---|
| `IDEIABUGCATCHER_API_KEY` | — | Without it nothing is sent. |
| `IDEIABUGCATCHER_ENDPOINT` | `https://bug.ideia.cv` | Server base URL. |
| `IDEIABUGCATCHER_APP_VERSION` | `config('app.version')` | Version shown on issues. |
| `IDEIABUGCATCHER_RELEASE_STAGE` | `APP_ENV` | |
| `IDEIABUGCATCHER_NOTIFY_RELEASE_STAGES` | all | e.g. `production,staging`. |
| `IDEIABUGCATCHER_SEND_REQUEST_PARAMS` | `true` | Request input (after redaction). |
| `IDEIABUGCATCHER_SEND_USER` | `true` | |
| `IDEIABUGCATCHER_QUERY_BINDINGS` | `false` | Query values in breadcrumbs. |
| `IDEIABUGCATCHER_LOG_LEVEL` | `error` | Minimum level for the log channel. |
| `IDEIABUGCATCHER_TIMEOUT` | `3` | Seconds. |

The config file also has `ignore_exceptions`, `redact_keys`, `project_root` and the breadcrumb
switches.

## Development

```bash
composer install
vendor/bin/phpunit
```

Against a running IdeiaBugCatcher server (needs a project key):

```bash
IDEIABUGCATCHER_E2E_ENDPOINT=http://localhost:8000 IDEIABUGCATCHER_E2E_KEY=<key> vendor/bin/phpunit --filter EndToEnd
```

Releases are git tags (`v0.1.0`); bump `Client::VERSION` with each tag.
