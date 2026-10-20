<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Orders/OrderSource.php
 * CREATED:   2026-10-21 01:55 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The few core reads the order SMS logic needs: an order, the child orders of a checkout, a profile phone,
 *            and who is doing the current request (area, controller, mode, user, vendor company).
 * WHY:       Task 1.9. Keeping them behind one small class lets the order hooks and the notifier run in unit tests
 *            with an in-memory double, and keeps the core calls (fn_get_order_info, fn_get_suborders_info) in one
 *            place. fn_get_suborders_info() reads only the orders table (no products, no taxes): cheap enough to
 *            call on every child status change of a checkout.
 * USAGE:     $orders = new OrderSource();
 *            $orders->order(12);  $orders->children(90);  $orders->profilePhone(7);  $orders->actor();
 * DEPENDS:   CS-Cart fn_get_order_info(), fn_get_suborders_info(), db_get_field(), Tygh\Registry,
 *            Tygh::$app['session'].
 */

namespace Tygh\Addons\Kwtsms\Orders;

use Tygh\Registry;
use Tygh\Tygh;

class OrderSource
{
    /**
     * @return array<string,mixed> fn_get_order_info() row, [] when the order does not exist
     */
    public function order(int $order_id): array
    {
        if ($order_id <= 0) {
            return [];
        }
        $info = fn_get_order_info($order_id);

        return is_array($info) ? $info : [];
    }

    /**
     * Child orders of a checkout: order_id, status, company_id, total, ascending by id.
     *
     * @return array<int,array<string,mixed>>
     */
    public function children(int $parent_order_id): array
    {
        if ($parent_order_id <= 0) {
            return [];
        }
        $rows = (array) fn_get_suborders_info($parent_order_id);
        usort($rows, static function ($a, $b): int {
            return (int) $a['order_id'] <=> (int) $b['order_id'];
        });

        return $rows;
    }

    public function profilePhone(int $user_id): string
    {
        if ($user_id <= 0) {
            return '';
        }

        return (string) db_get_field('SELECT phone FROM ?:users WHERE user_id = ?i', $user_id);
    }

    /**
     * Who causes the current change: area (C storefront, A admin and API and CLI), dispatch controller and mode,
     * the session user, and the runtime vendor company (Multi-Vendor vendor panel; 0 otherwise).
     *
     * @return array{area:string,controller:string,mode:string,user_id:int,company_id:int}
     */
    public function actor(): array
    {
        $user_id = 0;
        try {
            if (isset(Tygh::$app['session']['auth']['user_id'])) {
                $user_id = (int) Tygh::$app['session']['auth']['user_id'];
            }
        } catch (\Throwable $e) {
            $user_id = 0;
        }

        return [
            'area' => defined('AREA') ? (string) AREA : '',
            'controller' => (string) Registry::get('runtime.controller'),
            'mode' => (string) Registry::get('runtime.mode'),
            'user_id' => $user_id,
            'company_id' => (int) Registry::get('runtime.company_id'),
        ];
    }
}
