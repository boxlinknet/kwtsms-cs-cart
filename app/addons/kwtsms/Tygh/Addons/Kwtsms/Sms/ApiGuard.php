<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Sms/ApiGuard.php
 * CREATED:   2026-10-20 23:15 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The gate in front of every kwtSMS call: one global request slot every 200 ms (5 calls per
 *            second for the whole server), and the circuit breaker for transport failures.
 * WHY:       PRD 6.2 steps 12-13 and 6.1: the gateway blocks an IP that goes well above its rate limit, so
 *            all PHP-FPM workers and the cron share one slot clock; and when kwtSMS is unreachable the
 *            store must not wait on a dead connection for every order: after the first transport failure
 *            in a request the rest of that request is deferred without HTTP, and three consecutive
 *            failing requests open the breaker for 5 minutes.
 *            The slot clock lives in storage_data (key kwtsms_api_slot, milliseconds), guarded by the
 *            database lock GET_LOCK('kwtsms_api', 2): read the next free slot with a fresh read, reserve
 *            the one after it, release the lock, then sleep until the reserved slot. The breaker
 *            (kwtsms_gateway_state, through GatewayState) is also written only while holding that lock, so
 *            two requests cannot overwrite each other's count. Only ONE failure is counted per request.
 * USAGE:     $guard = new ApiGuard(new StorageDataStore(), $gatewayState);        // one per request
 *            $guard->blockReason();           // null | 'LOCAL_BREAKER' | 'LOCAL_DEFERRED'
 *            $guard->acquire();               // sleeps until the slot; throws ApiGuardException when blocked
 *            ... HTTP call ...
 *            $guard->recordTransportFailure();   // dns, connect, tls or timeout: once per request counts
 *            $guard->recordReached();            // any reply from the gateway: resets the failure count
 *            Constants: LOCK_NAME kwtsms_api, LOCK_WAIT_SECONDS 2, SLOT_KEY kwtsms_api_slot, SPACING_MS 200.
 *            A slot value that is not a number, is in the past, or is more than 10 s ahead (corrupt) is
 *            treated as "now", so a bad row can never stall the store. Suspension is NOT checked here:
 *            the /balance/ probe that ends a suspension has to go out.
 *            Test seams (protected): lock(), unlock(), nowMs(), sleepMs().
 * DEPENDS:   Storage\KeyValueStoreInterface (getFresh), Gateway\GatewayState, CS-Cart db_get_field() for the
 *            default lock (without it the lock is "unavailable" and acquire() fails closed with LOCAL_BUSY).
 */

namespace Tygh\Addons\Kwtsms\Sms;

use Tygh\Addons\Kwtsms\Gateway\GatewayState;
use Tygh\Addons\Kwtsms\Storage\KeyValueStoreInterface;

class ApiGuard
{
    public const LOCK_NAME = 'kwtsms_api';

    public const LOCK_WAIT_SECONDS = 2;

    public const SLOT_KEY = 'kwtsms_api_slot';

    /** 5 requests per second for the whole server. */
    public const SPACING_MS = 200;

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
     * @return bool True when the failure was written to the breaker
     */
    public function recordTransportFailure(): bool
    {
        if ($this->request_failed) {
            return false;
        }
        $this->request_failed = true;

        return $this->underLock(function (): void {
            $this->state->recordTransportFailure();
        });
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
     * Database lock for the slot clock and the breaker state (MariaDB GET_LOCK, server wide).
     *
     * @return bool True when the lock was obtained
     */
    protected function lock(): bool
    {
        if (!function_exists('db_get_field')) {
            return false;
        }

        return (int) db_get_field('SELECT GET_LOCK(?s, ?i)', self::LOCK_NAME, self::LOCK_WAIT_SECONDS) === 1;
    }

    protected function unlock(): void
    {
        if (function_exists('db_get_field')) {
            db_get_field('SELECT RELEASE_LOCK(?s)', self::LOCK_NAME);
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
     * Runs $write while holding the lock; false (nothing written) when the lock is not available.
     */
    private function underLock(callable $write): bool
    {
        if (!$this->lock()) {
            return false;
        }
        try {
            $write();
        } finally {
            $this->unlock();
        }

        return true;
    }
}
