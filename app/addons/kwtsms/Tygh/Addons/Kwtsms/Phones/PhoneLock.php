<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Phones/PhoneLock.php
 * CREATED:   2026-10-25 04:35 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      A named database lock per canonical phone (MySQL GET_LOCK), held from the "is this number free" check
 *            until the account save has written the phone index row, and released at the end of the request at the
 *            latest.
 * WHY:       Task M1.9 (SafatMall C4, P3-11). Two registrations with the same number arriving together would both
 *            find it free and both save. The index has no unique key on the plain phone (legacy duplicates exist),
 *            so the check and the save are serialised per number instead. The lock is per phone, so unrelated
 *            registrations never wait for each other.
 * USAGE:     $lock->acquire('+96550123401');   // true = held (also when already held in this request), false = busy
 *            $lock->release('+96550123401');   // called by ProfileHooks::onUpdateProfile() after the index write
 *            A lock still held at the end of the request is released by a shutdown function (registered once).
 *            Test seams (protected): getLock(), releaseLock(), onShutdown().
 * DEPENDS:   CS-Cart db_get_field() (GET_LOCK / RELEASE_LOCK on the request's own database connection).
 */

namespace Tygh\Addons\Kwtsms\Phones;

class PhoneLock
{
    /** Seconds to wait for a number that another request is saving. */
    public const TIMEOUT = 2;

    /** @var array<string,bool> lock names this request holds */
    private $held = [];

    /** @var bool */
    private $registered = false;

    /** GET_LOCK name: 'kwtsms_ph_' + 40 hex characters (50 of the 64 a lock name may have). */
    public static function lockName(string $phone): string
    {
        return 'kwtsms_ph_' . substr(sha1($phone), 0, 40);
    }

    /**
     * True when this request now holds the lock of $phone (a phone it already holds is not asked for again); false
     * when another request keeps it for longer than TIMEOUT seconds. An empty phone has nothing to lock.
     */
    public function acquire(string $phone): bool
    {
        if ($phone === '') {
            return true;
        }
        $name = self::lockName($phone);
        if (isset($this->held[$name])) {
            return true;
        }
        if (!$this->getLock($name, self::TIMEOUT)) {
            return false;
        }
        $this->held[$name] = true;
        if (!$this->registered) {
            $this->registered = true;
            $this->onShutdown([$this, 'releaseAll']);
        }

        return true;
    }

    /** Releases the lock of $phone when this request holds it; anything else is ignored. */
    public function release(string $phone): void
    {
        $name = self::lockName($phone);
        if ($phone === '' || !isset($this->held[$name])) {
            return;
        }
        unset($this->held[$name]);
        $this->releaseLock($name);
    }

    /** Releases every lock still held (the shutdown function). */
    public function releaseAll(): void
    {
        foreach (array_keys($this->held) as $name) {
            unset($this->held[$name]);
            try {
                $this->releaseLock((string) $name);
            } catch (\Throwable $e) {
                // The connection closing at the end of the request frees the lock anyway.
            }
        }
    }

    // ------------------------------------------------------------------ the database (replaced by tests)

    protected function getLock(string $name, int $timeout): bool
    {
        return (int) db_get_field('SELECT GET_LOCK(?s, ?i)', $name, $timeout) === 1;
    }

    protected function releaseLock(string $name): void
    {
        db_get_field('SELECT RELEASE_LOCK(?s)', $name);
    }

    protected function onShutdown(callable $job): void
    {
        register_shutdown_function($job);
    }
}
