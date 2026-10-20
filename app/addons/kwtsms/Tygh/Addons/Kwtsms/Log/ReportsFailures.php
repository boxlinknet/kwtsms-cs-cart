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
        error_log('kwtsms log write failed: ' . get_class($e) . ': ' . $this->safeMessage($e->getMessage())
            . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    }

    /**
     * 2026-10-20 22:22 +03: the logged message is cut at 300 characters and loses everything a database error
     * carries besides its reason (Task 1.4 fix round 1, task 596). CS-Cart's DatabaseException message is
     * "<reason> <b>(code)</b><p><the whole query></p>", and a query of these logs holds phone numbers and SMS
     * text. Removed: from the "<p>" or the first INSERT INTO / REPLACE INTO / DELETE FROM / SELECT / UPDATE, then
     * every tag, whitespace runs become one space, and runs of seven or more digits become "[n]" (phone lists).
     */
    private function safeMessage(string $message): string
    {
        $parts = preg_split('/<p>|\b(?:INSERT\s+INTO|REPLACE\s+INTO|DELETE\s+FROM|SELECT\s|UPDATE\s+[`\w])/', $message, 2);
        $text = (string) preg_replace('/<[^>]*>/', '', $parts === false ? $message : $parts[0]);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        $text = (string) preg_replace('/[0-9]{7,}/', '[n]', $text);

        return mb_substr($text, 0, 300, 'UTF-8');
    }
}
