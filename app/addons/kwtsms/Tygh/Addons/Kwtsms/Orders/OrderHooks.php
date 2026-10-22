<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Orders/OrderHooks.php
 * CREATED:   2026-10-21 01:57 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The logic behind the six order hooks of PRD 4.3 (func.php handlers delegate here): flag new orders before
 *            core dispatches their status event, dispatch kwtsms.order_placed with the same notify rules after it,
 *            hold admin-created orders until the admin's own Notify boxes are known, send a Multi-Vendor checkout
 *            SMS once every child exists, and record why core did not hand an SMS over (diagnostics).
 * WHY:       PRD 4.2. Hook order inside fn_change_order_status (fn.cart.php): change_order_status (2953) -> core
 *            dispatch of order.status_changed.<s> (3008) -> change_order_status_post (3038). So:
 *            - change_order_status: NewOrderDetector says new -> flag new_order.<id>; the transport then supersedes
 *              the status SMS of that order.
 *            - change_order_status_post: flagged -> clear the flag and dispatch kwtsms.order_placed with a Ruleset of
 *              the same $force_notification, except inside the admin's order_management.place_order (AREA A forces
 *              every notify rule to false there): flag manual.<id> and wait for place_order_manually_post, which
 *              carries the admin's real Notify boxes. Not flagged -> diagnostics of the status event (not for N and
 *              T, which core never dispatches, not for the restore leg of an admin edit, not for an order waiting
 *              for its order-placed SMS).
 *            - edit_place_order (1358): flag edit.<id>; an edited order is never new.
 *            - place_order_post (1724) on the parent (parent_order_id 0): the checkout SMS of a Multi-Vendor checkout
 *              placed in this request (children flagged checkout.<parent>), sent once all children exist.
 *            - change_order_status_child_order (2806): flag cascade.<child>: the parent moves every child in this
 *              request (a payment callback), so only the last child of a per_checkout status SMS writes nothing
 *              extra while the others wait.
 *            Order-placed dispatches are split by unit (CheckoutRules): a plain order (id p<order>), a suborder
 *            (id o<child>) and a checkout (id p<parent>, the parent's order_info with the child ids); a receiver
 *            that is not due in a dispatch is forced off in its Ruleset, so the transport sees each SMS once.
 *            2026-10-21 04:20 +03, Task 1.9 fix round 1, task 596:
 *            - item 3 (controller ruling): an admin-created order that moves to a status outside the placed set
 *              before place_order_manually_post (the admin picked Canceled, Declined, Failed) is no new order: its
 *              manual flag is dropped in change_order_status, so the status SMS follows its own switch, and
 *              place_order_manually_post checks the status again before it dispatches kwtsms.order_placed.
 *            - item 4: a checkout's order-placed SMS is dispatched once per request (flag checkout_sent.<parent>),
 *              and the switched_off / not_notified rows of a parent cascade are written once per checkout and
 *              status (flag diag_<S>.<parent>, rows on the parent id).
 *            2026-10-21 07:53 +03, Task 1.9 follow-up of the Task 1.14 review, task 596: no info row
 *            (order_sms.new_order, order_sms.order_placed, order_sms.manual_dropped) while SMS is off or not set
 *            up (Diagnostics::active()).
 * USAGE:     Tygh::$app['kwtsms.order_hooks']->onChangeOrderStatus($to, $from, $order_info, $rules, $place_order);
 *            (see func.php for the six handlers). The dispatcher is a callable (string $event_id, array $data,
 *            array $rules, IProvider $id) wired in ServiceProvider to core's Factory and EventDispatcher.
 * DEPENDS:   Orders\{NewOrderDetector,RuntimeFlags,Diagnostics,CheckoutRules,OrderSource},
 *            Notifications\OrderPlacedIdProvider, Templates\Defaults, Log\DebugLog.
 */

namespace Tygh\Addons\Kwtsms\Orders;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Notifications\EventsSchemaExtender;
use Tygh\Addons\Kwtsms\Notifications\OrderPlacedIdProvider;
use Tygh\Addons\Kwtsms\Templates\Defaults;

class OrderHooks
{
    private const RECEIVERS = ['C', 'A'];

    /** Every receiver core knows; one not due in a dispatch is forced off. */
    private const ALL_RECEIVERS = ['C', 'A', 'V'];

    /** @var NewOrderDetector */
    private $detector;

    /** @var RuntimeFlags */
    private $flags;

    /** @var Diagnostics */
    private $diagnostics;

    /** @var CheckoutRules */
    private $rules;

    /** @var OrderSource */
    private $orders;

    /** @var callable (string $event_id, array $data, array $rules, IProvider $provider): void */
    private $dispatch;

    /** @var DebugLog|null */
    private $log;

    public function __construct(
        NewOrderDetector $detector,
        RuntimeFlags $flags,
        Diagnostics $diagnostics,
        CheckoutRules $rules,
        OrderSource $orders,
        callable $dispatch,
        ?DebugLog $log = null
    ) {
        $this->detector = $detector;
        $this->flags = $flags;
        $this->diagnostics = $diagnostics;
        $this->rules = $rules;
        $this->orders = $orders;
        $this->dispatch = $dispatch;
        $this->log = $log;
    }

    /**
     * The notify rules as an array: false (every notification off) becomes C, A and V false.
     *
     * @param mixed $force_notification
     *
     * @return array<string,mixed>
     */
    public static function rules($force_notification): array
    {
        if ($force_notification === false) {
            return ['C' => false, 'A' => false, 'V' => false];
        }

        return is_array($force_notification) ? $force_notification : [];
    }

    /**
     * Hook change_order_status (before core dispatches the status event).
     *
     * @param array<string,mixed> $order_info
     * @param mixed               $force_notification
     */
    public function onChangeOrderStatus(
        string $status_to,
        string $status_from,
        array $order_info,
        $force_notification,
        bool $place_order
    ): void {
        $order_id = (int) ($order_info['order_id'] ?? 0);
        // 2026-10-21 04:20 +03: an admin-created order moved out of the placed set is no new order (fix round 1
        // item 3), task 596.
        if ($this->flags->has('manual', $order_id) && !$this->detector->isPlaced($status_to)) {
            $this->flags->clear('manual', $order_id);
            $this->info('order_sms.manual_dropped', 'admin-created order left the placed statuses', [
                'order_id' => $order_id, 'to' => $status_to,
            ]);
        }
        if ($this->detector->isNew($order_info, $status_from, $status_to, $place_order)) {
            $this->flags->set('new_order', $order_id, [
                'from' => $status_from, 'to' => $status_to, 'place_order' => $place_order,
            ]);
            $this->info('order_sms.new_order', 'new order', [
                'order_id' => $order_id, 'from' => $status_from, 'to' => $status_to, 'place_order' => $place_order,
            ]);
        }
    }

    /**
     * Hook change_order_status_post (after core dispatched the status event).
     *
     * @param mixed               $force_notification
     * @param array<string,mixed> $order_info
     */
    public function onChangeOrderStatusPost(
        int $order_id,
        string $status_to,
        string $status_from,
        $force_notification,
        bool $place_order,
        array $order_info
    ): void {
        $rules = self::rules($force_notification);
        if ($this->flags->has('new_order', $order_id)) {
            $this->flags->clear('new_order', $order_id);
            $actor = $this->orders->actor();
            $in_order_management = $actor['area'] === 'A' && $actor['controller'] === 'order_management'
                && $actor['mode'] === 'place_order';
            if ($place_order && $in_order_management) {
                $this->flags->set('manual', $order_id, true);

                return;
            }
            $this->dispatchPlaced($order_info, $rules, $place_order);

            return;
        }

        $to = strtoupper($status_to);
        if ($to === '' || $to === 'N' || $to === 'T') {
            return;
        }
        if ($this->flags->has('manual', $order_id) || ($place_order && $this->flags->has('edit', $order_id))) {
            return;
        }
        // 2026-10-21 04:20 +03: a parent moving its children writes one set of rows for the checkout (fix round 1
        // item 4), task 596.
        $parent_id = (int) ($order_info['parent_order_id'] ?? 0);
        if ($parent_id > 0 && $this->flags->has('cascade', $order_id)) {
            if ($this->flags->has('diag_' . $to, $parent_id)) {
                return;
            }
            $this->flags->set('diag_' . $to, $parent_id, true);
            $order_info['order_id'] = $parent_id;
        }
        $this->diagnostics->recordSkipped($order_info, EventsSchemaExtender::STATUS_PREFIX . strtolower($to), $rules);
    }

    /**
     * Hook edit_place_order: the order is being edited in the admin (X -> N -> X), never new.
     */
    public function onEditPlaceOrder(int $order_id): void
    {
        if ($order_id > 0) {
            $this->flags->set('edit', $order_id, true);
        }
    }

    /**
     * Hook place_order_post: on the parent of a Multi-Vendor checkout (parent_order_id 0), send the checkout SMS of
     * the children placed in this request.
     *
     * @param array<string,mixed> $short_order_data
     */
    public function onPlaceOrderPost(int $parent_order_id, int $order_id, array $short_order_data = []): void
    {
        if ($parent_order_id !== 0 || $order_id <= 0) {
            return;
        }
        $pending = $this->flags->get('checkout', $order_id);
        if (!is_array($pending)) {
            return;
        }
        $this->flags->clear('checkout', $order_id);
        $this->dispatchCheckout($order_id, $pending['rules'], $pending['receivers']);
    }

    /**
     * Hook place_order_manually_post: the order-placed SMS of an order the admin created, with the admin's Notify
     * customer / Notify orders department boxes.
     *
     * @param mixed $force_notification
     */
    public function onPlaceOrderManuallyPost($force_notification): void
    {
        $rules = self::rules($force_notification);
        foreach (array_keys($this->flags->all('manual')) as $order_id) {
            $this->flags->clear('manual', (int) $order_id);
            $info = $this->orders->order((int) $order_id);
            // 2026-10-21 04:20 +03: still placed? (fix round 1 item 3), task 596.
            if ($info !== [] && $this->detector->isPlaced((string) ($info['status'] ?? ''))) {
                $this->dispatchPlaced($info, $rules, true);
            }
        }
        foreach ($this->flags->all('checkout') as $parent_id => $pending) {
            $this->flags->clear('checkout', (int) $parent_id);
            $receivers = is_array($pending) ? $pending['receivers'] : self::RECEIVERS;
            $this->dispatchCheckout((int) $parent_id, $rules, $receivers);
        }
    }

    /**
     * Hook change_order_status_child_order: the parent moves this child in the current request.
     */
    public function onChangeOrderStatusChildOrder(
        int $parent_order_id,
        int $child_order_id,
        bool $change_child_status
    ): void {
        if ($change_child_status && $child_order_id > 0) {
            $this->flags->set('cascade', $child_order_id, $parent_order_id);
        }
    }

    /**
     * 2026-10-22 18:02 +03: Task A5 (R18), task 596. Hook update_shipment_before_send_notification (fn.cart.php:9091,
     * the create
     * branch of fn_update_shipment(), right before core dispatches order.shipment_updated with these rules): records
     * why no shipment SMS will be handed over (the Notify customer box is off: not_notified; the SMS switch is
     * off: switched_off), like the status events do.
     *
     * @param array<string,mixed> $order_info
     * @param mixed               $force_notification
     */
    public function onShipmentBeforeSend(array $order_info, $force_notification): void
    {
        $this->diagnostics->recordSkipped(
            $order_info,
            Defaults::EVENT_SHIPMENT,
            self::rules($force_notification),
            ['C']
        );
    }

    /**
     * kwtsms.order_placed for a new order, split by unit.
     *
     * @param array<string,mixed> $order_info
     * @param array<string,mixed> $rules
     * @param bool                $defer_checkout True while the children of the checkout are still being placed
     */
    private function dispatchPlaced(array $order_info, array $rules, bool $defer_checkout): void
    {
        $order_id = (int) ($order_info['order_id'] ?? 0);
        $parent_id = (int) ($order_info['parent_order_id'] ?? 0);
        if ($order_id <= 0) {
            return;
        }
        $by_unit = [];
        foreach (self::RECEIVERS as $receiver) {
            $by_unit[$this->rules->placedUnit($receiver, $parent_id)][] = $receiver;
        }

        foreach ([CheckoutRules::UNIT_ORDER => 'p', CheckoutRules::UNIT_SUBORDER => 'o'] as $unit => $kind) {
            if (isset($by_unit[$unit])) {
                $provider = new OrderPlacedIdProvider($order_id, $kind);
                $this->dispatch($order_info, $rules, $by_unit[$unit], $unit, [], $provider);
            }
        }
        $checkout = $by_unit[CheckoutRules::UNIT_CHECKOUT] ?? [];
        if ($checkout !== [] && $parent_id > 0) {
            if ($defer_checkout) {
                $pending = $this->flags->get('checkout', $parent_id);
                $receivers = is_array($pending) ? $pending['receivers'] : [];
                $this->flags->set('checkout', $parent_id, [
                    'rules' => is_array($pending) ? $pending['rules'] : $rules,
                    'receivers' => array_values(array_unique(array_merge($receivers, $checkout))),
                ]);
            } else {
                $this->dispatchCheckout($parent_id, $rules, $checkout);
            }
        }
    }

    /**
     * One kwtsms.order_placed for a whole Multi-Vendor checkout: the parent's order_info with the child ids.
     *
     * @param array<string,mixed> $rules
     * @param string[]            $receivers
     */
    private function dispatchCheckout(int $parent_id, array $rules, array $receivers): void
    {
        // 2026-10-21 04:20 +03: once per request: the next children of a payment cascade add nothing (fix round 1
        // item 4), task 596.
        if ($receivers === [] || $this->flags->has('checkout_sent', $parent_id)) {
            return;
        }
        $parent = $this->orders->order($parent_id);
        if ($parent === []) {
            return;
        }
        $this->flags->set('checkout_sent', $parent_id, true);
        $ids = [];
        foreach ($this->orders->children($parent_id) as $child) {
            $ids[] = (int) $child['order_id'];
        }
        $this->dispatch($parent, $rules, $receivers, CheckoutRules::UNIT_CHECKOUT, [
            'parent_order_id' => $parent_id,
            'order_ids' => $ids,
        ], new OrderPlacedIdProvider($parent_id, OrderPlacedIdProvider::KIND_PLACED));
    }

    /**
     * @param array<string,mixed> $order_info
     * @param array<string,mixed> $rules
     * @param string[]            $receivers  Receivers due in this dispatch; every other one is forced off
     * @param array<string,mixed> $meta
     */
    private function dispatch(
        array $order_info,
        array $rules,
        array $receivers,
        string $unit,
        array $meta,
        OrderPlacedIdProvider $provider
    ): void {
        foreach (self::ALL_RECEIVERS as $receiver) {
            if (!in_array($receiver, $receivers, true)) {
                $rules[$receiver] = false;
            }
        }
        $data = ['order_info' => $order_info, 'kwtsms' => ['unit' => $unit] + $meta];
        call_user_func($this->dispatch, Defaults::EVENT_PLACED, $data, $rules, $provider);
        $this->diagnostics->recordSkipped($order_info, Defaults::EVENT_PLACED, $rules, $receivers);
        $this->info('order_sms.order_placed', 'order-placed event dispatched', [
            'order_id' => (int) ($order_info['order_id'] ?? 0),
            'unit' => $unit,
            'receivers' => $receivers,
            'id' => $provider->getId(),
        ]);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function info(string $action, string $message, array $context): void
    {
        // 2026-10-21 07:53 +03: nothing written while SMS is off or not set up (Task 1.14 review follow-up), task 596.
        if ($this->log === null) {
            return;
        }
        // 2026-10-21 08:04 +03: a failing store read here never stops the hook handler (ruling R37), task 596.
        try {
            $active = $this->diagnostics->active();
        } catch (\Throwable $e) {
            FailureLog::report($this->log, 'order_sms.active_check_failed', $e);

            return;
        }
        if ($active) {
            $this->log->log('info', $action, $message, $context);
        }
    }
}
