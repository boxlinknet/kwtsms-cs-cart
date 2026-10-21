<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Orders/NewOrderDetector.php
 * CREATED:   2026-10-21 01:50 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Decides whether a status change makes an order "new" (PRD 4.2 rule 1): the customer and the admin then
 *            get one kwtsms.order_placed SMS and the status SMS of that change is superseded.
 * WHY:       Core has no "order placed" event that fires once: an offline checkout is N -> O inside fn_place_order,
 *            an online payment is a later callback N -> P, a failed payment is N -> F (no order), a repay after a
 *            failure is F -> O -> P, and an admin edit is X -> N -> X with place_order = true. Rule (as implemented):
 *              new = status_to is in the placed set (core paid statuses = inventory "D", plus placed_status_extra)
 *                    AND (place_order OR status_from = N OR (status_from is not placed AND orders.repaid > 0))
 *                    AND the order is not being edited (edit_place_order flag of this request)
 *                    AND the order has no order-placed dispatch key yet.
 *            The repay clause is the one addition to the PRD wording: the frontend repay (orders.php:100-116) moves
 *            F -> O with repaid incremented, which the PRD table calls "new order once".
 * USAGE:     $d = new NewOrderDetector(NewOrderDetector::placedStatuses(fn_get_order_paid_statuses(), ['B']),
 *                     $is_edited, $was_placed);           // callables (int $order_id): bool
 *            $d->isNew($order_info, 'N', 'P', false);     // true
 *            $d->isPlaced('I');                           // false (2026-10-21 04:20 +03, fix round 1 item 3)
 *            2026-10-21 04:20 +03, Task 1.9 fix round 1 (controller ruling on review Minor 1), task 596: the repay
 *            clause stays as built: it mirrors core's e-mail at the repay's F -> O (frontend orders.php repay calls
 *            fn_change_order_status(F, O) with the default notify rules, so the customer gets core's Open e-mail);
 *            the dispatch key still limits it to one order-placed SMS per order.
 * DEPENDS:   Nothing (pure PHP 7.4); the callables are wired in ServiceProvider.
 */

namespace Tygh\Addons\Kwtsms\Orders;

class NewOrderDetector
{
    /** @var string[] */
    private $placed;

    /** @var callable|null (int $order_id): bool */
    private $is_edited;

    /** @var callable|null (int $order_id): bool */
    private $was_placed;

    /**
     * @param string[]      $placed_statuses Placed status codes (see placedStatuses())
     * @param callable|null $is_edited       (int $order_id): bool, true while edit_place_order marked the order
     * @param callable|null $was_placed      (int $order_id): bool, true when an order-placed dispatch key exists
     */
    public function __construct(array $placed_statuses, ?callable $is_edited = null, ?callable $was_placed = null)
    {
        $this->placed = self::placedStatuses($placed_statuses, []);
        $this->is_edited = $is_edited;
        $this->was_placed = $was_placed;
    }

    /**
     * Core paid statuses plus the configured extras, upper case, unique, in order, blanks dropped.
     *
     * @param array<int,mixed> $paid  fn_get_order_paid_statuses()
     * @param array<int,mixed> $extra Config::placedStatusExtra()
     *
     * @return string[]
     */
    public static function placedStatuses(array $paid, array $extra): array
    {
        $out = [];
        foreach (array_merge($paid, $extra) as $code) {
            $code = strtoupper(trim((string) $code));
            if ($code !== '' && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return $out;
    }

    /**
     * True when the status is in the placed set (core paid statuses plus placed_status_extra).
     * 2026-10-21 04:20 +03: added, Task 1.9 fix round 1 item 3 (an admin-created order re-checked), task 596.
     */
    public function isPlaced(string $status): bool
    {
        return in_array(strtoupper($status), $this->placed, true);
    }

    /**
     * @param array<string,mixed> $order_info fn_get_order_info() row (order_id, repaid)
     */
    public function isNew(array $order_info, string $status_from, string $status_to, bool $place_order): bool
    {
        $order_id = (int) ($order_info['order_id'] ?? 0);
        $from = strtoupper($status_from);
        $to = strtoupper($status_to);
        if ($order_id <= 0 || $from === $to || !in_array($to, $this->placed, true)) {
            return false;
        }

        // 2026-10-21 04:20 +03: the repay clause (from not placed, repaid > 0) mirrors core's e-mail at the repay's
        // F -> O; kept by controller ruling (fix round 1, review Minor 1), task 596.
        $enters = $place_order
            || $from === 'N'
            || (!in_array($from, $this->placed, true) && (int) ($order_info['repaid'] ?? 0) > 0);
        if (!$enters) {
            return false;
        }
        if ($this->is_edited !== null && call_user_func($this->is_edited, $order_id)) {
            return false;
        }

        return !($this->was_placed !== null && call_user_func($this->was_placed, $order_id));
    }
}
