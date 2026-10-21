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
 *            2026-10-21 04:18 +03, Task 1.9 fix round 1 item 2, task 596: a session that cannot be read is logged at
 *            error level (the actor is then a guest, user 0); new OrderSource($debug_log). Test seams (protected):
 *            sessionUserId(), runtime($key).
 * DEPENDS:   CS-Cart fn_get_order_info(), fn_get_suborders_info(), db_get_field(), Tygh\Registry,
 *            Tygh::$app['session']; Log\DebugLog (optional), Orders\FailureLog.
 */

namespace Tygh\Addons\Kwtsms\Orders;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Registry;
use Tygh\Tygh;

class OrderSource
{
    /** @var DebugLog|null 2026-10-21 04:18 +03: errors are logged (fix round 1 item 2), task 596 */
    private $log;

    public function __construct(?DebugLog $log = null)
    {
        $this->log = $log;
    }

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
            $user_id = $this->sessionUserId();
        } catch (\Throwable $e) {
            // 2026-10-21 04:18 +03: logged, no longer silent (fix round 1 item 2), task 596.
            FailureLog::report($this->log, 'order_sms.actor_failed', $e);
        }

        return [
            'area' => defined('AREA') ? (string) AREA : '',
            'controller' => (string) $this->runtime('controller'),
            'mode' => (string) $this->runtime('mode'),
            'user_id' => $user_id,
            'company_id' => (int) $this->runtime('company_id'),
        ];
    }

    /**
     * The user id of the session, 0 for a guest, the cron and the CLI. May throw (a session store that is down).
     * 2026-10-21 04:18 +03: split out of actor() as a test seam, task 596.
     */
    protected function sessionUserId(): int
    {
        if (isset(Tygh::$app['session']['auth']['user_id'])) {
            return (int) Tygh::$app['session']['auth']['user_id'];
        }

        return 0;
    }

    /**
     * A runtime value of the current request (controller, mode, company_id).
     * 2026-10-21 04:18 +03: split out of actor() as a test seam, task 596.
     *
     * @return mixed
     */
    protected function runtime(string $key)
    {
        return Registry::get('runtime.' . $key);
    }
}
