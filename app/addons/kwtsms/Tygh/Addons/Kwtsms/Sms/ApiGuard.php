<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Sms/ApiGuard.php
 * CREATED:   2026-10-20 22:25 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The gate in front of every kwtSMS call: one request slot every 200 ms (5 calls per second) for
 *            each store database, and the circuit breaker for transport failures.
 * WHY:       PRD 6.2 steps 12-13 and 6.1: the gateway blocks an IP that goes well above its rate limit, so
 *            all PHP-FPM workers and the cron of one store share one slot clock; and when kwtSMS is unreachable the
 *            store must not wait on a dead connection for every order: after the first transport failure
 *            in a request the rest of that request is deferred without HTTP, and three consecutive
 *            failing requests open the breaker for 5 minutes.
 *            The slot clock lives in storage_data (key kwtsms_api_slot, milliseconds), guarded by the
 *            database lock GET_LOCK('kwtsms_api_<16 hex>', 2): read the next free slot with a fresh read,
 *            reserve the one after it, release the lock, then sleep until the reserved slot. The breaker
 *            (kwtsms_gateway_state, through GatewayState) is also written only while holding that lock, so
 *            two requests cannot overwrite each other's count. Only ONE failure is counted per request.
 *            2026-10-20 23:18 +03, ruling R19, task 596: the lock name carries the first 16 hex digits of
 *            md5(database name . table prefix). MariaDB locks are server wide, so without that two stores
 *            on one server would block each other while each keeps its own slot clock in its own
 *            storage_data. The lock and the slot clock are therefore per store database, not per server:
 *            two stores on one server can together reach 10 calls per second.
 * USAGE:     $guard = new ApiGuard(new StorageDataStore(), $gatewayState);        // one per request
 *            $guard->blockReason();           // null | 'LOCAL_BREAKER' | 'LOCAL_DEFERRED'
 *            $guard->acquire();               // sleeps until the slot; throws ApiGuardException when blocked
 *            ... HTTP call ...
 *            $guard->recordTransportFailure();   // dns, connect, tls or timeout: once per request counts;
 *                                                // returns the breaker after it, with 'opened' = this one opened it
 *            $guard->recordReached();            // any reply from the gateway: resets the failure count
 *            $guard->runLocked($write);          // 2026-10-21 00:05 +03: $write under the same lock (GatewayState
 *                                                // suspend / resume), false = lock busy, Task 1.6, task 596
 *            Constants: LOCK_PREFIX kwtsms_api_, LOCK_WAIT_SECONDS 2, SLOT_KEY kwtsms_api_slot, SPACING_MS 210
 *            (2026-10-21 10:46 +03: was 200, Task 1.16, task 596; see the constant).
 *            lockName() is the full lock name for this store's database.
 *            A slot value that is not a number, is in the past, or is more than 10 s ahead (corrupt) is
 *            treated as "now", so a bad row can never stall the store. Suspension is NOT checked here:
 *            the /balance/ probe that ends a suspension has to go out.
 *            Test seams (protected): lock(), unlock(), nowMs(), sleepMs(), databaseIdentity().
 * DEPENDS:   Storage\KeyValueStoreInterface (getFresh), Gateway\GatewayState, CS-Cart db_get_field() for the
 *            default lock (without it the lock is "unavailable" and acquire() fails closed with LOCAL_BUSY).
 */

namespace Tygh\Addons\Kwtsms\Sms;

use Tygh\Addons\Kwtsms\Gateway\GatewayState;
use Tygh\Addons\Kwtsms\Storage\KeyValueStoreInterface;

class ApiGuard
{
    /**
     * Lock name prefix; lockName() appends 16 hex digits that identify the store database.
     * 2026-10-20 23:18 +03: was the fixed name kwtsms_api (ruling R19, task 596).
     */
    public const LOCK_PREFIX = 'kwtsms_api_';

    public const LOCK_WAIT_SECONDS = 2;

    public const SLOT_KEY = 'kwtsms_api_slot';

    /**
     * At most 5 requests per second for one store database. 2026-10-21 10:46 +03: 210 ms, was 200. With exactly 200 ms
     * the slot times are 1000 ms apart for six calls, and the millisecond rounding of the wait let six calls start
     * within 998 ms (Task 1.16: 8 processes x 10 calls on the real slot clock). Task 596.
     * 2026-10-21 13:03 +03: reworded, review M5, task 596. The 10 ms are a margin, not a guarantee: six slots are now
     * 1050 ms apart, so six calls start inside one second only when a call starts more than 50 ms after its slot (a
     * slow sleep or a busy worker); the measured spans of six were 1042 to 1049 ms.
     */
    public const SPACING_MS = 210;

    /** A reserved slot further ahead than this is a corrupt value, not a queue. */
    private const MAX_BACKLOG_MS = 10000;

    /** @var KeyValueStoreInterface */
    private $store;

    /** @var GatewayState */
    private $state;

    /** @var bool A transport failure already happened in this request */
    private $request_failed = false;

    public function __construct(KeyValueStoreInterface $store, GatewayState $state)
    {
        $this->store = $store;
        $this->state = $state;
    }

    /**
     * Why a call must not go out right now, or null when it may.
     *
     * @return string|null LOCAL_DEFERRED (earlier failure in this request), LOCAL_BREAKER (open breaker)
     */
    public function blockReason(): ?string
    {
        if ($this->request_failed) {
            return 'LOCAL_DEFERRED';
        }

        return $this->state->breaker()['open'] ? 'LOCAL_BREAKER' : null;
    }

    /**
     * Takes the next global slot and waits for it.
     *
     * @throws ApiGuardException LOCAL_DEFERRED, LOCAL_BREAKER, or LOCAL_BUSY (lock not available in 2 s)
     */
    public function acquire(): void
    {
        $reason = $this->blockReason();
        if ($reason !== null) {
            throw new ApiGuardException($reason);
        }
        if (!$this->lock()) {
            throw new ApiGuardException('LOCAL_BUSY');
        }
        try {
            $now = $this->nowMs();
            $next = (int) $this->store->getFresh(self::SLOT_KEY);
            if ($next < $now || $next - $now > self::MAX_BACKLOG_MS) {
                $next = $now;
            }
            $this->store->set(self::SLOT_KEY, (string) ($next + self::SPACING_MS));
        } finally {
            $this->unlock();
        }

        $wait = $next - $this->nowMs();
        if ($wait > 0) {
            $this->sleepMs($wait);
        }
    }

    /**
     * Records that the transport failed (dns, connect, tls, timeout). Only the first call in a request
     * counts towards the breaker; every later call in the request is deferred by blockReason().
     *
     * @return array{failures:int, open_until:int, open:bool, opened:bool}|null The breaker after this failure,
     *         where "opened" is true when this very failure closed-to-open flipped it (the caller logs that);
     *         null when nothing was written (a failure was already recorded in this request, or the lock was
     *         not available). 2026-10-20 23:18 +03: was a bool, M8, task 596.
     */
    public function recordTransportFailure(): ?array
    {
        if ($this->request_failed) {
            return null;
        }
        $this->request_failed = true;

        $breaker = $this->underLock(function (): array {
            $was_open = $this->state->breaker()['open'];
            $after = $this->state->recordTransportFailure();

            return $after + ['opened' => !$was_open && $after['open']];
        });

        return is_array($breaker) ? $breaker : null;
    }

    /**
     * Records that the gateway answered (any HTTP reply): the consecutive count restarts and the breaker
     * closes. Writes only when there is something to reset.
     */
    public function recordReached(): void
    {
        $breaker = $this->state->breaker();
        if ($breaker['failures'] === 0 && $breaker['open_until'] === 0) {
            return;
        }
        $this->underLock(function (): void {
            $this->state->recordTransportSuccess();
        });
    }

    /**
     * Runs $write while holding this store's API lock (the one the slot clock and the breaker use).
     * GatewayState::suspend() and resume() write through it (Task 1.5 review carry).
     * 2026-10-21 00:05 +03: added, Task 1.6, task 596.
     *
     * @return bool False when the lock was not available in LOCK_WAIT_SECONDS ($write did not run)
     */
    public function runLocked(callable $write): bool
    {
        return $this->underLock(function () use ($write): bool {
            $write();

            return true;
        }) === true;
    }

    /**
     * The database lock name of this store: LOCK_PREFIX plus the first 16 hex digits of
     * md5(database name . table prefix). At most 27 characters (MariaDB allows 64).
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
     * Database lock for the slot clock and the breaker state (MariaDB GET_LOCK; the name is per database).
     *
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

    /**
     * Current time in milliseconds.
     */
    protected function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    protected function sleepMs(int $ms): void
    {
        usleep($ms * 1000);
    }

    /**
     * Runs $write while holding the lock and returns what it returns; null (nothing written) when the lock is
     * not available.
     *
     * @return mixed
     */
    private function underLock(callable $write)
    {
        if (!$this->lock()) {
            return null;
        }
        try {
            return $write();
        } finally {
            $this->unlock();
        }
    }
}
