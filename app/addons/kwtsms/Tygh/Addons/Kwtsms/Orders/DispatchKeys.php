<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Orders/DispatchKeys.php
 * CREATED:   2026-10-21 01:51 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Persistent dispatch keys in ?:kwtsms_dispatch_keys: claim() is true for exactly one caller, across
 *            requests and processes, so an order SMS goes out once (PRD 4.2 rule 3).
 * WHY:       The core dispatcher only de-duplicates inside one request. A payment webhook that moves three child
 *            orders in three requests, a status set twice in a day by two admins, or a repeated callback must
 *            still send one SMS. Keys:
 *              order_placed:<order id or parent id>:<receiver>   permanent
 *              status:<order id or p<parent id>>:<STATUS>:<receiver>   24 h window
 *            The claim is one INSERT ... ON DUPLICATE KEY UPDATE and the affected-rows count decides: 1 = inserted,
 *            2 = an expired key was renewed (both claim), 0 = the key is still valid (no claim). Atomic in InnoDB, so
 *            two concurrent webhooks cannot both win.
 *            2026-10-23 03:41 +03, Task A12 item 4 (R53), task 596: pruneStatus() is pruneWindowed($before, $limit):
 *            the
 *            status:, resend:, updated:, recover: and low_stock: keys (WINDOWED_PREFIXES, plus declared dedupe keys
 *            of 1 s to 2 days) older than the cut-off; order_placed:, shipment:, created:, registered: and
 *            low_balance: (PERMANENT_PREFIXES) are never pruned. new DispatchKeys($clock, $debug_log, $declared).
 * USAGE:     $keys = new DispatchKeys();
 *            $keys->claim(DispatchKeys::orderPlaced(12, 'C'));                          // true once, ever
 *            $keys->claim(DispatchKeys::status('12', 'C', 'C'), DispatchKeys::STATUS_WINDOW); // true once per 24 h
 *            $keys->hasOrderPlaced(12);   // an order-placed key exists for C or A of order 12
 *            claim() and exists() are public and overridable (test doubles keep keys in memory).
 *            2026-10-21 04:18 +03, Task 1.9 fix round 1, task 596: exists() logs a read error at error level (item
 *            2; it still answers "no key"); pruneStatus($before, $limit) deletes ONE chunk of status:* keys created
 *            before $before (ruling R32: the cron prune_logs job, 2 days = the 24 h window plus a day of margin);
 *            order_placed:* keys are permanent and never pruned. new DispatchKeys($clock, $debug_log).
 * DEPENDS:   CS-Cart db_query(), db_get_field(); table kwtsms_dispatch_keys (dispatch_key varchar(191) PK, created_at);
 *            Log\DebugLog (optional), Orders\FailureLog.
 */

namespace Tygh\Addons\Kwtsms\Orders;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Notifications\EventsSchemaExtender;

class DispatchKeys
{
    /** Window of a status key: a status set again within 24 h, by anyone, sends nothing. */
    public const STATUS_WINDOW = 86400;

    /** Longest key the primary key column holds. */
    public const MAX_LENGTH = 191;

    /**
     * 2026-10-23 03:41 +03: Task A12 item 4 (R53, R24), task 596. Prefixes of the keys that expire: the 24 h status
     * keys, the resend claims, the 10 minute updated: and recover: keys, the 24 h low-stock keys. Declared dedupe
     * keys with a window of 1 s to PRUNE_MAX_WINDOW join them at run time (windowedPrefixes()).
     */
    public const WINDOWED_PREFIXES = ['status:', 'resend:', 'updated:', 'recover:', 'low_stock:'];

    /** Prefixes of the keys that are kept for ever (an order is placed once, a shipment is created once ...). */
    public const PERMANENT_PREFIXES = ['order_placed:', 'shipment:', 'created:', 'registered:', 'low_balance:'];

    /** The prune cuts at 2 days (CronRunner::STATUS_KEY_DAYS), so a longer declared window is never pruned. */
    public const PRUNE_MAX_WINDOW = 172800;

    /**
     * One chunk of expired keys of ONE prefix, read as a range of the primary key (from the prefix to the prefix
     * with its colon raised by one character).
     * 2026-10-21 04:18 +03: added as PRUNE_STATUS_SQL (status: only), ruling R32, task 596.
     * 2026-10-23 03:41 +03: any one prefix, Task A12 item 4, task 596.
     */
    public const PRUNE_RANGE_SQL = 'DELETE FROM ?:kwtsms_dispatch_keys WHERE dispatch_key >= ?s AND dispatch_key < ?s'
        . ' AND created_at < ?i LIMIT ?i';

    /** @var callable|null */
    private $clock;

    /** @var DebugLog|null 2026-10-21 04:18 +03: read errors are logged (fix round 1 item 2), task 596 */
    private $log;

    /** @var string[] 2026-10-23 03:41 +03: Task A12 item 4, task 596. Windowed prefixes taken from declarations. */
    private $declared_prefixes = [];

    /** @var string[] 2026-10-23 06:04 +03: Cleanup CA1-9, task 596. Prefixes that a window-0 declaration also uses. */
    private $refused_prefixes = [];

    /**
     * @param callable|null $clock    Returns the current Unix time (tests); null = time()
     * @param array|null    $declared The kwtsms events schema (fn_get_schema('kwtsms', 'events')): a declared dedupe
     * key
     *                                with a window of 1 s to 2 days is pruned like the built-in windowed prefixes
     *                                (2026-10-23 03:41 +03: Task A12 item 4, R8, R24, task 596)
     */
    public function __construct(?callable $clock = null, ?DebugLog $log = null, ?array $declared = null)
    {
        $this->clock = $clock;
        $this->log = $log;
        if ($declared !== null) {
            $decls = EventsSchemaExtender::declarations($declared, true);
            // 2026-10-23 06:04 +03: Cleanup CA1-9 (A12), task 596. A prefix that any declaration uses with window 0
            // (permanent keys) is refused as a windowed prefix, built-in or declared: the prune would delete those
            // permanent keys with the windowed ones. The keys are kept (the safe direction).
            foreach ($decls as $decl) {
                $prefix = $decl->dedupePrefix();
                if (
                    $prefix !== '' && $decl->dedupeWindow() === 0
                    && !in_array($prefix, $this->refused_prefixes, true)
                ) {
                    $this->refused_prefixes[] = $prefix;
                }
            }
            foreach ($decls as $decl) {
                $prefix = $decl->dedupePrefix();
                if (
                    $prefix !== '' && $decl->dedupeWindow() > 0 && $decl->dedupeWindow() <= self::PRUNE_MAX_WINDOW
                    && !in_array($prefix, self::PERMANENT_PREFIXES, true)
                    && !in_array($prefix, self::WINDOWED_PREFIXES, true)
                    && !in_array($prefix, $this->declared_prefixes, true)
                ) {
                    $this->declared_prefixes[] = $prefix;
                }
            }
            if ($this->refused_prefixes !== []) {
                $conflict = array_values(array_intersect(
                    $this->refused_prefixes,
                    array_merge(self::WINDOWED_PREFIXES, $this->declared_prefixes)
                ));
                $this->refused_prefixes = $conflict;
                foreach ($conflict as $prefix) {
                    $this->noteConflictOnce($prefix);
                }
            }
        }
    }

    /**
     * Writes the conflict line once per store, not on every request: a permanent dispatch key marks it as written.
     * 2026-10-23 08:23 +03: Task CA1 fix round 1 (minor 3), task 596. No database function (unit rigs): no line.
     */
    private function noteConflictOnce(string $prefix): void
    {
        try {
            if (!function_exists('db_query') || !$this->claim('notice:prefix_conflict:' . $prefix, 0)) {
                return;
            }
            error_log('kwtsms: dispatch key prefix ' . $prefix
                . ' is declared with a window and with window 0; it is never pruned');
        } catch (\Throwable $e) {
            // A notice never stops the service.
        }
    }

    /**
     * Prefixes that are both windowed and used by a window-0 declaration; never pruned.
     * 2026-10-23 06:04 +03: added, Cleanup CA1-9, task 596.
     *
     * @return string[]
     */
    public function refusedPrefixes(): array
    {
        return $this->refused_prefixes;
    }

    public static function orderPlaced(int $id, string $receiver): string
    {
        return 'order_placed:' . $id . ':' . $receiver;
    }

    /**
     * @param string $scope_id The order id, or "p<parent id>" for a whole Multi-Vendor checkout
     */
    public static function status(string $scope_id, string $status, string $receiver): string
    {
        return 'status:' . $scope_id . ':' . strtoupper($status) . ':' . $receiver;
    }

    /**
     * True when this call may send: the key did not exist, or it is older than the window (window 0 = forever).
     * A key that cannot be written (database error) is not claimed: no SMS is better than a duplicate.
     */
    public function claim(string $key, int $window_s = 0): bool
    {
        if ($key === '' || strlen($key) > self::MAX_LENGTH) {
            return false;
        }
        $now = $this->now();
        try {
            if ($window_s > 0) {
                $affected = db_query(
                    'INSERT INTO ?:kwtsms_dispatch_keys (dispatch_key, created_at) VALUES (?s, ?i)'
                    . ' ON DUPLICATE KEY UPDATE created_at = IF(created_at <= ?i, ?i, created_at)',
                    $key,
                    $now,
                    $now - $window_s,
                    $now
                );
            } else {
                $affected = db_query(
                    'INSERT INTO ?:kwtsms_dispatch_keys (dispatch_key, created_at) VALUES (?s, ?i)'
                    . ' ON DUPLICATE KEY UPDATE created_at = created_at',
                    $key,
                    $now
                );
            }
        } catch (\Throwable $e) {
            error_log('kwtsms: dispatch key claim failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());

            return false;
        }

        return (int) $affected === 1 || (int) $affected === 2;
    }

    /**
     * True when the key exists and is still inside its window (window 0 = forever).
     */
    public function exists(string $key, int $window_s = 0): bool
    {
        try {
            $created = db_get_field('SELECT created_at FROM ?:kwtsms_dispatch_keys WHERE dispatch_key = ?s', $key);
        } catch (\Throwable $e) {
            // 2026-10-21 04:18 +03: logged, no longer silent (fix round 1 item 2), task 596.
            FailureLog::report($this->log, 'order_sms.dispatch_key_read_failed', $e);

            return false;
        }
        if ($created === null || $created === false || $created === '') {
            return false;
        }

        return $window_s <= 0 || (int) $created > $this->now() - $window_s;
    }

    /**
     * True when the customer or the admin order-placed key of this order id exists.
     */
    public function hasOrderPlaced(int $order_id): bool
    {
        return $this->exists(self::orderPlaced($order_id, 'C')) || $this->exists(self::orderPlaced($order_id, 'A'));
    }

    /**
     * The prefixes the prune deletes: WINDOWED_PREFIXES, then the declared windowed ones.
     * 2026-10-23 03:41 +03: added, Task A12 item 4, task 596.
     *
     * @return string[]
     */
    public function windowedPrefixes(): array
    {
        return array_values(array_diff(
            array_merge(self::WINDOWED_PREFIXES, $this->declared_prefixes),
            $this->refused_prefixes
        ));
    }

    /**
     * The half-open key range of a prefix ending in a colon: [prefix, prefix with ';' for ':').
     *
     * @return string[] [from, to]
     */
    public static function prefixRange(string $prefix): array
    {
        return [$prefix, substr($prefix, 0, -1) . ';'];
    }

    /**
     * Deletes ONE chunk (at most $limit rows in all) of the keys of the windowed prefixes created before $before;
     * order_placed:, shipment:, created:, registered: and low_balance: keys are never touched. Called by the cron
     * prune_logs job (CronRunner) until a chunk comes back short. A database error is thrown to the caller, which
     * reports it. 2026-10-21 04:18 +03: added as pruneStatus() (status: only), ruling R32, Task 1.9 fix round 1 item 6,
     * task 596. 2026-10-23 03:41 +03: every windowed prefix, one range of the primary key each, Task A12 item 4 (R53),
     * task 596.
     *
     * @return int Keys deleted; fewer than $limit means none are left
     */
    public function pruneWindowed(int $before, int $limit): int
    {
        $left = max(1, $limit);
        $total = 0;
        foreach ($this->windowedPrefixes() as $prefix) {
            if ($left <= 0) {
                break;
            }
            [$from, $to] = self::prefixRange($prefix);
            $deleted = $this->deleteRange($from, $to, max(0, $before), $left);
            $total += $deleted;
            $left -= $deleted;
        }

        return $total;
    }

    /**
     * One range delete (a test seam). 2026-10-23 03:41 +03: Task A12 item 4, task 596.
     */
    protected function deleteRange(string $from, string $to, int $before, int $limit): int
    {
        return (int) db_query(self::PRUNE_RANGE_SQL, $from, $to, $before, $limit);
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) call_user_func($this->clock) : time();
    }
}
