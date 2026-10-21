<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Orders/FailureLog.php
 * CREATED:   2026-10-21 04:18 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      One line for an error the order SMS code caught and replaced by a safe fallback: class, message and
 *            file:line (no trace), at error level in the add-on's debug log when one is wired, and always in the
 *            PHP error log (which still works when the database behind the debug log is the thing that failed).
 * WHY:       Task 1.9 fix round 1, item 2: DispatchKeys::exists(), OrderSource::actor() and the storefront
 *            language of OrderSmsData returned their fallback with no trace. The message is cut at 300 characters
 *            and runs of seven or more digits become "[n]", so a phone number in a database error never reaches
 *            a log (PRD 10).
 * USAGE:     } catch (\Throwable $e) { FailureLog::report($this->log, 'order_sms.actor_failed', $e); }
 *            Never throws.
 * DEPENDS:   Log\DebugLog (optional).
 */

namespace Tygh\Addons\Kwtsms\Orders;

use Tygh\Addons\Kwtsms\Log\DebugLog;

final class FailureLog
{
    public static function report(?DebugLog $log, string $action, \Throwable $e): void
    {
        $message = (string) preg_replace('/[0-9]{7,}/', '[n]', substr($e->getMessage(), 0, 300));
        $line = get_class($e) . ': ' . $message . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
        error_log('kwtsms: ' . $action . ': ' . $line);
        if ($log === null) {
            return;
        }
        try {
            $log->log('error', $action, $line);
        } catch (\Throwable $ignored) {
            error_log('kwtsms: ' . $action . ': the debug log could not take the line');
        }
    }
}
