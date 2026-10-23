<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Orders/LowStockCollector.php
 * CREATED:   2026-10-22 21:27 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Collects the products that crossed the low-stock threshold during one request and turns them into at
 *            most one kwtsms.low_stock dispatch per receiver unit: one A dispatch for marketplace products
 *            (company_id 0) and for every Ultimate product, one V dispatch per vendor company in Multi-Vendor. The
 *            dispatch data is ['products' => [...], 'company_id' => int, 'more_count' => n - 1].
 * WHY:       Phase 2 Task A7 (SafatMall F5 part, P2-10, rulings R4, R20). A checkout of 40 products must send one SMS
 *            per receiver, not 40. A product alerts once per 24 h and receiver (key low_stock:<id>:<receiver>);
 *            a product whose key is taken is dropped before grouping and leaves one attempt row (reason
 *            low_stock_window). Nothing is claimed while the switch of the receiver is OFF (R20). A crossing is what
 *            core mails about (fn.cart.php:1146-1148, 4.21.2) and only the move from above to at-or-below counts:
 *            a product that is already low does not alert again on every sale.
 * USAGE:     $c->record($product_id, $company_id, $current_amount, $new_amount, $sign, $threshold);  // from the
 *            update_product_amount hook (fn_kwtsms_update_product_amount); $c->flush($dispatch) from
 *            OrderHooks::onChangeOrderStatusPost; the first record() also registers a shutdown function that flushes
 *            for every other caller. $dispatch is function (string $event_id, array $data, array $rules): void.
 * DEPENDS:   Orders\DispatchKeys (claim), injected callables for the switch, the dropped-product row and the
 *            ORDER_MANAGEMENT test, so the class runs in a unit test without CS-Cart.
 */

namespace Tygh\Addons\Kwtsms\Orders;

class LowStockCollector
{
    public const EVENT_ID = 'kwtsms.low_stock';

    /** Dispatch key prefix: low_stock:<product_id>:<receiver>. */
    public const KEY_PREFIX = 'low_stock';

    /** Seconds a product stays quiet after an alert. */
    public const WINDOW = 86400;

    /** @var DispatchKeys */
    private $keys;

    /** @var callable (string $receiver): bool */
    private $enabled;

    /** @var callable (string $reason, array $product, string $receiver): void */
    private $dropped;

    /** @var bool */
    private $is_multivendor;

    /** @var callable (): bool */
    private $in_order_management;

    /** @var callable (callable $fn): void */
    private $register_shutdown;

    /** @var callable|null */
    private $dispatch;

    /** @var array<int,array{product_id:int,company_id:int,current_amount:int,new_amount:int}> */
    private $products = [];

    /** @var bool 2026-10-23 05:45 +03: Task CA1-1, task 596 */
    private static $any_recorded = false;

    /** @var bool */
    private $shutdown_registered = false;

    /** @var callable|null 2026-10-22 23:55 +03: Task A8 fix round 1 (M2), task 596 */
    private $vendor_ready;

    /**
     * @param callable      $enabled             (string $receiver): bool, the kwtsms switch of kwtsms.low_stock
     * @param callable      $dropped             (string $reason, array $product, string $receiver): void
     * @param callable|null $in_order_management (): bool, null = defined('ORDER_MANAGEMENT')
     * @param callable|null $register_shutdown   (callable $fn): void, null = register_shutdown_function
     * @param callable|null $dispatch            used by the shutdown flush; null = no shutdown function
     */
    public function __construct(
        DispatchKeys $keys,
        callable $enabled,
        callable $dropped,
        bool $is_multivendor,
        ?callable $in_order_management = null,
        ?callable $register_shutdown = null,
        ?callable $dispatch = null,
        // 2026-10-22 23:55 +03: Task A8 fix round 1 (M2), task 596. (int $company_id): bool, true when the vendor has
        // an approved
        // number and is within its quota; null = always ready (unit rigs).
        ?callable $vendor_ready = null
    ) {
        $this->vendor_ready = $vendor_ready;
        $this->keys = $keys;
        $this->enabled = $enabled;
        $this->dropped = $dropped;
        $this->is_multivendor = $is_multivendor;
        $this->in_order_management = $in_order_management ?? static function (): bool {
            return defined('ORDER_MANAGEMENT');
        };
        $this->register_shutdown = $register_shutdown ?? static function (callable $fn): void {
            register_shutdown_function($fn);
        };
        $this->dispatch = $dispatch;
    }

    /**
     * True when a stock change crosses the threshold downwards (fn.cart.php:1146-1148 mirrored: a decrease, the
     * stock was above the threshold and is now at or below it) and the order editor is not the caller.
     */
    public function crosses(int $current_amount, int $new_amount, string $sign, int $threshold): bool
    {
        return $sign === '-'
            && $current_amount > $threshold
            && $new_amount <= $threshold
            && !(bool) call_user_func($this->in_order_management);
    }

    /**
     * 2026-10-23 05:45 +03: Task CA1-1 (A7 M7), task 596. True once any collector recorded a crossing in this
     * process: lets the update_product_amount hook skip the "+" path without building the service.
     */
    public static function anyRecorded(): bool
    {
        return self::$any_recorded;
    }

    public function record(
        int $product_id,
        int $company_id,
        int $current_amount,
        int $new_amount,
        string $sign,
        int $threshold
    ): void {
        // 2026-10-23 05:45 +03: Task CA1-1 (A7 M7), task 596. A stock revert (fn_change_order_status puts stock back
        // with "+" after a backorder/error path) that lifts a pending product above the threshold clears its
        // crossing: no SMS, no key burnt, for stock that is no longer low.
        if ($sign === '+') {
            if (isset($this->products[$product_id]) && $new_amount > $threshold) {
                unset($this->products[$product_id]);
            }

            return;
        }
        if ($product_id <= 0 || !$this->crosses($current_amount, $new_amount, $sign, $threshold)) {
            return;
        }
        self::$any_recorded = true;
        $this->products[$product_id] = [
            'product_id' => $product_id,
            'company_id' => max(0, $company_id),
            'current_amount' => $current_amount,
            'new_amount' => $new_amount,
        ];
        if (!$this->shutdown_registered && $this->dispatch !== null) {
            $this->shutdown_registered = true;
            call_user_func($this->register_shutdown, [$this, 'shutdownFlush']);
        }
    }

    /**
     * Shutdown function: flushes what no change_order_status_post flushed (a stock change outside an order status
     * change). Public only for register_shutdown_function. Never throws.
     */
    public function shutdownFlush(): void
    {
        try {
            $this->flushPending();
        } catch (\Throwable $e) {
            error_log('kwtsms: low stock flush failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    /**
     * flush() with the dispatcher given to the constructor (0 when there is none).
     */
    public function flushPending(): int
    {
        return $this->dispatch === null ? 0 : $this->flush($this->dispatch);
    }

    /**
     * Dispatches one kwtsms.low_stock per receiver unit and empties the collector.
     *
     * @param callable $dispatch function (string $event_id, array $data, array $rules): void
     *
     * @return int Dispatches made
     */
    public function flush(callable $dispatch): int
    {
        $products = array_values($this->products);
        $this->products = [];
        if ($products === []) {
            return 0;
        }

        // Group by unit: A for company 0 (and everything outside Multi-Vendor), V per vendor company.
        $units = [];
        foreach ($products as $product) {
            $company = $this->is_multivendor ? (int) $product['company_id'] : 0;
            $receiver = $company > 0 ? 'V' : 'A';
            $units[$receiver . ':' . $company][] = $product;
        }

        $made = 0;
        foreach ($units as $unit => $members) {
            list($receiver, $company) = explode(':', $unit);
            // R20: the switch first; while it is OFF nothing is claimed and nothing is written.
            if (!(bool) call_user_func($this->enabled, $receiver)) {
                continue;
            }
            $kept = [];
            // 2026-10-22 23:55 +03: Task A8 fix round 1 (M2), task 596. A vendor that cannot be texted now (no
            // approved number, or over its
            // quota) claims NO key: the dispatch still goes out so OrderNotifier writes the no_phone / rate_limited
            // attempt, and a crossing after the approval (or in the next month) is not suppressed for 24 h.
            $unclaimed = $receiver === 'V' && $this->vendor_ready !== null
                && !(bool) call_user_func($this->vendor_ready, (int) $company);
            if ($unclaimed) {
                $members = array_values($members);
                $kept = $members;
                $members = [];
            }
            foreach ($members as $product) {
                $key = self::KEY_PREFIX . ':' . $product['product_id'] . ':' . $receiver;
                if ($this->keys->claim($key, self::WINDOW)) {
                    $kept[] = $product;
                } else {
                    call_user_func($this->dropped, 'low_stock_window', $product, $receiver);
                }
            }
            if ($kept === []) {
                continue;
            }
            $rules = $receiver === 'V'
                ? ['C' => false, 'A' => false]
                : ['C' => false, 'V' => false];
            call_user_func($dispatch, self::EVENT_ID, [
                'products' => $kept,
                'company_id' => (int) $company,
                'more_count' => count($kept) - 1,
            ], $rules);
            $made++;
        }

        return $made;
    }
}
