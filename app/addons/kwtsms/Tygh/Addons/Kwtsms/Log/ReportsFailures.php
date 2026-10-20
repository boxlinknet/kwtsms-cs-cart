<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Log/ReportsFailures.php
 * CREATED:   2026-10-20 21:35 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Trait for the log classes: writes one line to the PHP error log when a log write fails.
 * WHY:       A log that cannot be written must never break a send, checkout or admin page, but it must not
 *            vanish silently either. The line is class, message and file:line (no trace, PRD 10) and at most
 *            three lines per request, so a dead database does not flood the error log.
 * USAGE:     use ReportsFailures;  ...  catch (\Throwable $e) { $this->reportFailure($e); return 0; }
 * DEPENDS:   Nothing.
 */

namespace Tygh\Addons\Kwtsms\Log;

trait ReportsFailures
{
    /** @var int lines written by this class in this request */
    private static $reported_failures = 0;

    private function reportFailure(\Throwable $e): void
    {
        if (self::$reported_failures >= 3) {
            return;
        }
        self::$reported_failures++;
        error_log('kwtsms log write failed: ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}
