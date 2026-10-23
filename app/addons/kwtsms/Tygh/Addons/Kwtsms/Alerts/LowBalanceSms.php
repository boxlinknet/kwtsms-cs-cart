<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Alerts/LowBalanceSms.php
 * CREATED:   2026-10-23 00:13 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Dispatches the add-on's own event kwtsms.low_balance (an SMS to the staff phone list, receiver A) once
 *            per crossing of the low-balance threshold that AdminAlerts::raise('low_balance') recorded.
 * WHY:       Task A9 (SafatMall F5 part). The 1.0.0 alert is a bell notice and an e-mail; staff who do not read mail
 *            want an SMS too. It must never fire twice for one crossing, must not start from inside a send (no
 *            re-entrant SmsService::send()), and must not be blocked by its own cause: a low balance still sends
 *            until the balance is zero, and a zero balance sends nothing (the e-mail of 1.0.0 still goes).
 *            Storage key kwtsms_low_balance_sms (JSON): {"pending": {"at": <crossing time>, "balance": n,
 *            "threshold": n}, "sent": <crossing time already consumed>}. AdminAlerts writes "pending" at the
 *            crossing; this class writes "sent" BEFORE it dispatches (at most once per crossing: a failure after
 *            that point is never retried, so the drain_outbox cron step cannot storm). A crossing is consumed
 *            without an SMS when it is stale (older than 24 h), when the cached balance is below 1 credit, or when
 *            the balance is back at or above the threshold. Dedupe key of the event: low_balance:<at>:A (window 0).
 * USAGE:     $sms = new LowBalanceSms($store, $state, function (string $event_id, array $data): void {...});
 *            $sms->dispatchPending();   // true when an event was dispatched; called by CronRunner drain_outbox
 * DEPENDS:   Storage\KeyValueStoreInterface, Gateway\GatewayState (cached balance), a dispatch callable.
 */

namespace Tygh\Addons\Kwtsms\Alerts;

use Tygh\Addons\Kwtsms\Gateway\GatewayState;
use Tygh\Addons\Kwtsms\Sms\CapLock;
use Tygh\Addons\Kwtsms\Storage\KeyValueStoreInterface;

class LowBalanceSms
{
    public const KEY = 'kwtsms_low_balance_sms';
    public const EVENT_ID = 'kwtsms.low_balance';

    /** A crossing older than this is never texted (the cron was down or the switch was off). */
    public const MAX_AGE = 86400;

    /** @var KeyValueStoreInterface */
    private $store;

    /** @var GatewayState */
    private $state;

    /** @var callable */
    private $dispatch;

    /** @var callable|null */
    private $clock;

    /** @var CapLock|null 2026-10-23 05:47 +03: Task CA1-3 (A9 M1), task 596. The state lock; null = no lock (unit rigs). */
    private $lock;

    /**
     * @param callable      $dispatch (string $event_id, array $data): void, through event.dispatcher
     * @param callable|null $clock    Returns the current Unix time (tests); null = time()
     */
    public function __construct(
        KeyValueStoreInterface $store,
        GatewayState $state,
        callable $dispatch,
        ?callable $clock = null,
        ?CapLock $lock = null
    ) {
        $this->lock = $lock;
        $this->store = $store;
        $this->state = $state;
        $this->dispatch = $dispatch;
        $this->clock = $clock;
    }

    /**
     * Records a crossing (AdminAlerts calls this when it raises low_balance). Never throws.
     */
    public static function recordCrossing(
        KeyValueStoreInterface $store,
        int $at,
        float $balance,
        int $threshold,
        ?CapLock $lock = null
    ): void {
        try {
            // 2026-10-23 05:47 +03: Task CA1-3 (A9 M1), task 596. The read-modify-write runs under the state lock, so
            // the cron's consume step cannot overwrite a crossing recorded in between.
            self::locked($lock, static function () use ($store, $at, $balance, $threshold): void {
                $data = self::read($store->getFresh(self::KEY));
                $data['pending'] = ['at' => $at, 'balance' => $balance, 'threshold' => $threshold];
                $store->set(self::KEY, (string) json_encode($data));
            });
        } catch (\Throwable $e) {
            error_log('kwtsms: low balance crossing not recorded: ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * Dispatches kwtsms.low_balance for the pending crossing, if any. Never throws.
     *
     * @return bool True when the event was dispatched now
     */
    public function dispatchPending(): bool
    {
        try {
            // 2026-10-23 05:47 +03: Task CA1-3 (A9 M1), task 596. Read and consume under the state lock (see
            // recordCrossing()); the dispatch below runs outside it.
            $pending = null;
            $at = 0;
            self::locked($this->lock, function () use (&$pending, &$at): void {
                $data = self::read($this->store->getFresh(self::KEY));
                $found = $data['pending'] ?? null;
                if (!is_array($found) || !isset($found['at']) || !is_numeric($found['at'])) {
                    return;
                }
                $when = (int) $found['at'];
                if ($when <= 0 || $when <= (int) ($data['sent'] ?? 0)) {
                    return;
                }
                // Consumed first: whatever happens below, this crossing is never dispatched again.
                $data['sent'] = $when;
                unset($data['pending']);
                $this->store->set(self::KEY, (string) json_encode($data));
                $pending = $found;
                $at = $when;
            });
            if ($pending === null) {
                return false;
            }

            $threshold = (int) ($pending['threshold'] ?? 0);
            $cached = $this->state->balance();
            if (
                $this->now() - $at > self::MAX_AGE
                || $cached === null
                || $cached['available'] < 1.0
                || ($threshold > 0 && $cached['available'] >= $threshold)
            ) {
                return false;
            }
            call_user_func($this->dispatch, self::EVENT_ID, [
                'balance' => (int) floor($cached['available']),
                'threshold' => $threshold,
                'crossing_at' => $at,
            ]);

            return true;
        } catch (\Throwable $e) {
            error_log('kwtsms: low balance SMS failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());

            return false;
        }
    }

    /**
     * Runs $work under the lock. A lock that is busy for its wait time (or unavailable) does not drop the crossing:
     * the work runs without it, the 1.1.0 behaviour.
     * 2026-10-23 05:47 +03: Task CA1-3 (A9 M1), task 596.
     */
    private static function locked(?CapLock $lock, callable $work): void
    {
        if ($lock === null || !$lock->runLocked($work)) {
            $work();
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function read(?string $raw): array
    {
        $data = json_decode((string) $raw, true);

        return is_array($data) ? $data : [];
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }
}
