<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/OrderSmsTab.php
 * CREATED:   2026-10-21 05:53 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The read-only "SMS" tab of the admin order page: every attempt of the order and of its parent (and, for a
 *            Multi-Vendor checkout, of its child orders), newest first, with the same labels as the Logs page.
 * WHY:       PRD 6.8 and 4.3 (template hook orders:tabs_extra, verified in the 4.21.2 source: views/orders/details.tpl
 *            renders {hook name="orders:tabs_extra"} after the order form and before common/tabsbox.tpl, so the tab
 *            content sits outside the order's own form). Shown only to a root administrator who may open the Logs
 *            (view_kwtsms_logs, RequestGate's rules); nothing on the tab writes anything.
 * USAGE:     $tab = OrderSmsTab::fromContainer(Tygh::$app)->viewData(72);
 *            // ['order_id' => 72, 'parent_id' => 71, 'rows' => [...LogsPage::decorate('attempts')...], 'more' =>
 *            false]
 * DEPENDS:   Admin\LogsPage (labels), Log\AdminLogStoreInterface; CS-Cart db_get_field() for the parent id.
 */

namespace Tygh\Addons\Kwtsms\Admin;

use Tygh\Addons\Kwtsms\Log\AdminLogStoreInterface;

class OrderSmsTab
{
    /** Attempts the tab lists (an order rarely has more than a handful). */
    public const LIMIT = 200;

    /** @var AdminLogStoreInterface */
    private $store;

    /** @var LogsPage */
    private $page;

    public function __construct(AdminLogStoreInterface $store, LogsPage $page)
    {
        $this->store = $store;
        $this->page = $page;
    }

    /**
     * @param \ArrayAccess<string,mixed> $app Tygh::$app
     */
    public static function fromContainer($app): self
    {
        return new self($app['kwtsms.logs.store'], LogsPage::fromContainer($app));
    }

    /**
     * @return array{order_id:int,parent_id:int,rows:array<int,array<string,mixed>>,more:bool}
     */
    public function viewData(int $order_id): array
    {
        $parent_id = $this->parentOf($order_id);
        $rows = $this->store->attemptsForOrder($order_id, $parent_id, self::LIMIT + 1);
        $more = count($rows) > self::LIMIT;
        $out = [];
        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $one = $this->page->decorate('attempts', $row, false);
            $one['of_parent'] = $parent_id > 0 && (int) $row['order_id'] === $parent_id;
            $one['of_child'] = (int) $row['order_id'] !== $order_id && (int) $row['parent_order_id'] === $order_id;
            $out[] = $one;
        }

        return ['order_id' => $order_id, 'parent_id' => $parent_id, 'rows' => $out, 'more' => $more];
    }

    /** The parent order id of an order (0 when it has none). A seam: tests replace it. */
    protected function parentOf(int $order_id): int
    {
        return $order_id > 0 && function_exists('db_get_field')
            ? (int) db_get_field('SELECT parent_order_id FROM ?:orders WHERE order_id = ?i', $order_id)
            : 0;
    }
}
