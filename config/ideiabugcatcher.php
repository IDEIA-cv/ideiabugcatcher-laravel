<?php

return [

    /*
    | Project key created in IdeiaBugCatcher (Projetos → Chaves). Without a key nothing is sent,
    | so local machines and test runs stay quiet unless they set one.
    */
    'api_key' => env('IDEIABUGCATCHER_API_KEY'),

    /*
    | Base URL of the IdeiaBugCatcher server. Events go to {endpoint}/api/v1/events.
    */
    'endpoint' => env('IDEIABUGCATCHER_ENDPOINT', 'https://bug.ideia.cv'),

    /*
    | Shown on each issue and used to mark issues "resolvido na versão X". Set it from your
    | deploy (e.g. the git tag). Falls back to config('app.version') when that exists.
    */
    'app_version' => env('IDEIABUGCATCHER_APP_VERSION'),

    /*
    | Release stage of this installation (production, staging, local, …). Defaults to APP_ENV.
    */
    'release_stage' => env('IDEIABUGCATCHER_RELEASE_STAGE'),

    /*
    | Only these stages send anything (comma-separated in .env). Empty = every stage sends;
    | the server still e-mails only for the project's notifying stages.
    */
    'notify_release_stages' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('IDEIABUGCATCHER_NOTIFY_RELEASE_STAGES', ''))
    ))),

    /*
    | Report exceptions that reach Laravel's exception handler (uncaught exceptions, report(),
    | failed queue jobs). Exceptions listed in the handler's $dontReport are never sent.
    */
    'report_unhandled' => env('IDEIABUGCATCHER_REPORT_UNHANDLED', true),

    /*
    | Exception classes (and their subclasses) that are never sent.
    */
    'ignore_exceptions' => [
        // Illuminate\Validation\ValidationException::class,
    ],

    /*
    | Keys whose values are replaced with [FILTRADO] before anything leaves the server
    | (metadata, request headers and parameters, breadcrumb metadata). Matched ignoring case;
    | a key matches when it contains one of these words.
    */
    'redact_keys' => [
        'password', 'passwd', 'pwd', 'secret', 'token', 'api_key', 'apikey', 'authorization',
        'cookie', 'csrf', 'xsrf', 'php_auth_pw', 'credit_card', 'card_number', 'cvv', 'iban',
        'palavra_passe', 'senha',
    ],

    /*
    | Request details attached to events raised during an HTTP request.
    */
    'request' => [
        'headers' => true,
        // Form/JSON input (after redaction, strings cut at 1,000 characters, files left out).
        'params' => env('IDEIABUGCATCHER_SEND_REQUEST_PARAMS', true),
    ],

    /*
    | Attach the signed-in user (only when the guard already loaded it: no extra queries).
    */
    'user' => env('IDEIABUGCATCHER_SEND_USER', true),

    /*
    | Frames under this folder (outside vendor/) count as the project's: they decide grouping
    | and get source code. Defaults to base_path().
    */
    'project_root' => null,

    /*
    | Lines of source code sent around each in-project stack frame (0 = none).
    */
    'code_context_lines' => 3,

    /*
    | Breadcrumbs: what happened before the error.
    */
    'breadcrumbs' => [
        'max' => 50,
        'logs' => true,
        'queries' => true,
        // Off by default: bindings carry user data (the server also hides them).
        'query_bindings' => env('IDEIABUGCATCHER_QUERY_BINDINGS', false),
        'jobs' => true,
        'commands' => true,
    ],

    /*
    | Log channel driver "ideiabugcatcher": log entries at this level or above become events
    | (with the logged exception's stack trace when the context has one). Lower levels are
    | still kept as breadcrumbs.
    */
    'log_level' => env('IDEIABUGCATCHER_LOG_LEVEL', 'error'),

    /*
    | Seconds to wait for the server. Events are sent after the response in web requests.
    */
    'timeout' => (float) env('IDEIABUGCATCHER_TIMEOUT', 3),

];
