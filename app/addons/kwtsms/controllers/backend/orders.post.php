<?php

/*
 * FILE:      app/addons/kwtsms/controllers/backend/orders.post.php
 * CREATED:   2026-10-21 05:56 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Adds the read-only "SMS" tab to the admin order page (orders.details): every SMS attempt of the order
 *            and of
 *            its parent, with a link to the Logs detail view.
 * WHY:       PRD 6.8 and 4.3. The tab header is a navigation.tabs entry (the way core and the vendor_communication
 *            add-on
 *            add order tabs); its content is design/backend/templates/addons/kwtsms/hooks/orders/tabs_extra.post.tpl.
 *            Shown only when Admin\RequestGate would let this administrator open kwtsms.logs (root administrator on All
 *            storefronts, privilege view_kwtsms_logs), so a vendor or a storefront-bound administrator never sees SMS
 *            rows. GET only; nothing is written. A failure is logged and the order page renders without the tab.
 * USAGE:     Runs after core's orders controller for every backend orders.* request; acts on GET orders.details only.
 * DEPENDS:   Admin\{RequestGate,OrderSmsTab}, Tygh::$app['kwtsms.logs.store'] and ['kwtsms.logs.debug'].
 */

use Tygh\Addons\Kwtsms\Admin\OrderSmsTab;
use Tygh\Addons\Kwtsms\Admin\RequestGate;
use Tygh\Registry;

defined('BOOTSTRAP') or die('Access denied');

/** @var string $mode */

$kwtsms_auth = isset(Tygh::$app['session']['auth']) && is_array(Tygh::$app['session']['auth'])
    ? Tygh::$app['session']['auth']
    : [];

if ($mode !== 'details' || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== RequestGate::GET) {
    return [CONTROLLER_STATUS_OK];
}

$kwtsms_order_id = isset($_REQUEST['order_id']) && is_scalar($_REQUEST['order_id'])
    && ctype_digit((string) $_REQUEST['order_id']) ? (int) $_REQUEST['order_id'] : 0;
$kwtsms_denied = RequestGate::deny(
    RequestGate::GET,
    'logs',
    $kwtsms_auth,
    Registry::get('runtime.company_id'),
    static function (string $privilege): bool {
        return fn_check_current_user_access($privilege) === true;
    },
    null,
    null
);
if ($kwtsms_order_id < 1 || $kwtsms_denied !== null) {
    return [CONTROLLER_STATUS_OK];
}

try {
    $kwtsms_tab = OrderSmsTab::fromContainer(Tygh::$app)->viewData($kwtsms_order_id);
    $kwtsms_tabs = Registry::get('navigation.tabs');
    $kwtsms_tabs = is_array($kwtsms_tabs) ? $kwtsms_tabs : [];
    $kwtsms_tabs['kwtsms_sms'] = [
        'title' => __('kwtsms.order_tab.title'),
        'js' => true,
    ];
    Registry::set('navigation.tabs', $kwtsms_tabs);
    Tygh::$app['view']->assign('kwtsms_order_sms', $kwtsms_tab);
} catch (\Throwable $e) {
    error_log('kwtsms: order SMS tab failed: ' . get_class($e) . ': ' . $e->getMessage() . ' in '
        . basename($e->getFile()) . ':' . $e->getLine());
}

return [CONTROLLER_STATUS_OK];
