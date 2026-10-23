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
 *            2026-10-23 02:14 +03, Task A11, task 596: also assigns the manual SMS dialog data (kwtsms_order_send)
 *            and the last result (kwtsms_order_send_result) for hooks/orders/details_tools.post.tpl and
 *            tabs_extra.post.tpl.
 * USAGE:     Runs after core's orders controller for every backend orders.* request; acts on GET orders.details only.
 * DEPENDS:   Admin\{RequestGate,OrderSmsTab}, Tygh::$app['kwtsms.logs.store'] and ['kwtsms.logs.debug'].
 */

use Tygh\Addons\Kwtsms\Admin\OrderSmsSender; // 2026-10-23 02:14 +03: Task A11, task 596
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
$kwtsms_can_view = static function (string $privilege): bool {
    return fn_check_current_user_access($privilege) === true;
};
$kwtsms_denied = RequestGate::deny(
    RequestGate::GET,
    'logs',
    $kwtsms_auth,
    Registry::get('runtime.company_id'),
    $kwtsms_can_view,
    null,
    null
);

// 2026-10-23 02:14 +03: Task A11, task 596. The manual SMS dialog: only to an administrator the gate would let POST
// kwtsms.send_order_sms (root on All storefronts, manage_kwtsms_gateway; the same string stands in for both security
// hashes because nothing is posted here) who may also view this order. Nothing is written; a failure is logged and the
// order page renders without the button.
if ($kwtsms_order_id > 0) {
    try {
        $kwtsms_send_denied = RequestGate::deny(
            RequestGate::POST,
            'send_order_sms',
            $kwtsms_auth,
            Registry::get('runtime.company_id'),
            $kwtsms_can_view,
            'dialog',
            'dialog'
        );
        if (
            $kwtsms_send_denied === null
            && fn_check_current_user_access('view_orders') === true
            && fn_check_company_id('orders', 'order_id', $kwtsms_order_id)
        ) {
            $kwtsms_dialog = OrderSmsSender::fromContainer(Tygh::$app)->dialogData($kwtsms_order_id);
            if ($kwtsms_dialog !== null) {
                Tygh::$app['view']->assign('kwtsms_order_send', $kwtsms_dialog);
                $kwtsms_session = Tygh::$app['session'];
                if (
                    isset($kwtsms_session['kwtsms_order_sms_result'])
                    && is_array($kwtsms_session['kwtsms_order_sms_result'])
                ) {
                    $kwtsms_result = $kwtsms_session['kwtsms_order_sms_result'];
                    unset($kwtsms_session['kwtsms_order_sms_result']);
                    if ((int) ($kwtsms_result['order_id'] ?? 0) === $kwtsms_order_id) {
                        Tygh::$app['view']->assign('kwtsms_order_send_result', $kwtsms_result);
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('kwtsms: order SMS dialog failed: ' . get_class($e) . ': ' . $e->getMessage() . ' in '
            . basename($e->getFile()) . ':' . $e->getLine());
    }
}

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
