<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Sms/CapLock.php
 * CREATED:   2026-10-21 12:02 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The database lock under which SmsService checks the abuse caps and writes their reservations: MariaDB
 *            GET_LOCK('kwtsms_caps_<16 hex>', 2), one lock per store database (the 16 hex digits are the first of
 *            md5(database name . table prefix), as ApiGuard::lockName() does for the API slot).
 * WHY:       Ruling R41 (Task 1.16 BLOCKED-2): the per-number and store daily caps counted only the attempt rows of
 *            sends already made, and a row was written after the API call, so two requests to one number in the same
 *            second both saw room under the cap (30 orders to one number gave 6 accepted, not 5). The count and the
 *            reservation now happen in one critical section of this lock. A separate lock from the API slot lock, so a
 *            slow cap count never delays the 5 calls per second of the store.
 * USAGE:     $lock = new CapLock();
 *            $ran = $lock->runLocked(function () { ... count, decide, reserve ... });   // false = busy or unavailable,
 *                                                                                       // $work did not run
 *            $lock->lockName();   // kwtsms_caps_ + 16 hex, at most 28 characters (MariaDB allows 64)
 *            An exception thrown by $work goes on to the caller after the lock is released.
 *            Test seams (protected): lock(), unlock(), databaseIdentity().
 * DEPENDS:   CS-Cart db_get_field() (without it the lock is "unavailable" and runLocked() answers false: fail closed),
 *            Tygh\Registry (config.db_name, config.table_prefix).
 */

namespace Tygh\Addons\Kwtsms\Sms;

class CapLock
{
    /** Lock name prefix; lockName() appends 16 hex digits that identify the store database. */
    public const LOCK_PREFIX = 'kwtsms_caps_';

    /** Seconds GET_LOCK waits for a busy lock before the send is deferred (cap_unavailable). */
    public const LOCK_WAIT_SECONDS = 2;

    /**
     * Runs $work while holding the lock.
     *
     * @return bool False when the lock was busy for LOCK_WAIT_SECONDS or cannot be taken ($work did not run)
     */
    public function runLocked(callable $work): bool
    {
        if (!$this->lock()) {
            return false;
        }
        try {
            $work();
        } finally {
            $this->unlock();
        }

        return true;
    }

    /**
     * The lock name of this store's database: LOCK_PREFIX plus the first 16 hex digits of md5(database name . table
     * prefix).
     */
    public function lockName(): string
    {
        [$db, $prefix] = $this->databaseIdentity();

        return self::LOCK_PREFIX . substr(md5($db . $prefix), 0, 16);
    }

    /**
     * The database name and table prefix of the running store (empty outside CS-Cart).
     *
     * @return array{0:string,1:string}
     */
    protected function databaseIdentity(): array
    {
        if (!class_exists('Tygh\\Registry')) {
            return ['', ''];
        }

        return [(string) \Tygh\Registry::get('config.db_name'), (string) \Tygh\Registry::get('config.table_prefix')];
    }

    /**
     * @return bool True when the lock was obtained
     */
    protected function lock(): bool
    {
        if (!function_exists('db_get_field')) {
            return false;
        }

        return (int) db_get_field('SELECT GET_LOCK(?s, ?i)', $this->lockName(), self::LOCK_WAIT_SECONDS) === 1;
    }

    protected function unlock(): void
    {
        if (function_exists('db_get_field')) {
            db_get_field('SELECT RELEASE_LOCK(?s)', $this->lockName());
        }
    }
}
