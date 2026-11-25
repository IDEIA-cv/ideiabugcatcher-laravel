<?php

namespace IdeiaCv\BugCatcher;

/**
 * Breadcrumb types, with the same constant names as Bugsnag\Breadcrumbs\Breadcrumb.
 */
final class Breadcrumb
{
    public const NAVIGATION_TYPE = 'navigation';
    public const REQUEST_TYPE = 'request';
    public const PROCESS_TYPE = 'process';
    public const LOG_TYPE = 'log';
    public const USER_TYPE = 'user';
    public const STATE_TYPE = 'state';
    public const ERROR_TYPE = 'error';
    public const MANUAL_TYPE = 'manual';

    public const TYPES = [
        self::NAVIGATION_TYPE, self::REQUEST_TYPE, self::PROCESS_TYPE, self::LOG_TYPE,
        self::USER_TYPE, self::STATE_TYPE, self::ERROR_TYPE, self::MANUAL_TYPE,
    ];
}
