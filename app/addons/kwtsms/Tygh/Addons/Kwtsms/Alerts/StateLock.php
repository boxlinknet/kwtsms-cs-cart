<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Alerts/StateLock.php
 * CREATED:   2026-10-23 05:47 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The database lock under which the low-balance crossing key (kwtsms_low_balance_sms) is read, changed
 *            and written: MariaDB GET_LOCK('kwtsms_lbal_<16 hex>', 2), one lock per store database. The same
 *            mechanism as Sms\CapLock (this class only changes the lock name).
 * WHY:       Cleanup CA1-3 (A9 M1): AdminAlerts::raise() (a web request) and LowBalanceSms::dispatchPending() (the
 *            cron) both read-modify-write one JSON key; a crossing recorded between the cron's read and its write was
 *            overwritten and never texted.
 * USAGE:     (new StateLock())->runLocked(function () { ...read, change, write... });  // false = busy, work did not
 * run
 * DEPENDS:   Sms\CapLock.
 */

namespace Tygh\Addons\Kwtsms\Alerts;

use Tygh\Addons\Kwtsms\Sms\CapLock;

class StateLock extends CapLock
{
    /** Lock name prefix; lockName() appends 16 hex digits that identify the store database. */
    public const STATE_LOCK_PREFIX = 'kwtsms_lbal_';

    public function lockName(): string
    {
        [$db, $prefix] = $this->databaseIdentity();

        return self::STATE_LOCK_PREFIX . substr(md5($db . $prefix), 0, 16);
    }
}
