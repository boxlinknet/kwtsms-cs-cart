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
 * USAGE:     $keys = new DispatchKeys();
 *            $keys->claim(DispatchKeys::orderPlaced(12, 'C'));                          // true once, ever
 *            $keys->claim(DispatchKeys::status('12', 'C', 'C'), DispatchKeys::STATUS_WINDOW); // true once per 24 h
 *            $keys->hasOrderPlaced(12);   // an order-placed key exists for C or A of order 12
 *            claim() and exists() are public and overridable (test doubles keep keys in memory).
 * DEPENDS:   CS-Cart db_query(), db_get_field(); table kwtsms_dispatch_keys (dispatch_key varchar(191) PK, created_at).
 */

namespace Tygh\Addons\Kwtsms\Orders;

class DispatchKeys
{
    /** Window of a status key: a status set again within 24 h, by anyone, sends nothing. */
    public const STATUS_WINDOW = 86400;

    /** Longest key the primary key column holds. */
    public const MAX_LENGTH = 191;

    /** @var callable|null */
    private $clock;

    /**
     * @param callable|null $clock Returns the current Unix time (tests); null = time()
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock;
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

    private function now(): int
    {
        return $this->clock !== null ? (int) call_user_func($this->clock) : time();
    }
}
