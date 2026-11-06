<?php

/*
 * FILE:      app/addons/kwtsms/controllers/frontend/orders.pre.php
 * CREATED:   2026-11-06 02:54 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Pre-controller of the storefront orders: for POST orders.repay (pay an order again, possibly with another
 *            payment) it asks for the SMS code of the checkout (checkout_code) exactly as the checkout does. A refusal
 *            shows the notice and goes back to the order page; nothing is written. A code that passes is used up and
 *            the phone is remembered for this order in this session.
 * WHY:       Task M2.4 fix 1 (security review C1). Core's orders.repay (app/controllers/frontend/orders.php:40-123)
 *            writes the new payment_id into the order and starts the payment without fn_place_order(), so
 *            pre_place_order never ran: a card order that failed at the gateway could be switched to cash on delivery
 *            with no code. A returned CONTROLLER_STATUS_REDIRECT stops core's controller (fn.control.php), so the
 *            order stays as it was.
 * USAGE:     Loaded by core before controllers/frontend/orders.php. Inactive while checkout_code is off. Fail closed
 *            while it is on: an internal error refuses the repay.
 * DEPENDS:   Tygh::$app['kwtsms.config'|'kwtsms.checkout_code'|'session'], Orders\CheckoutCode::repayCart(),
 *            Codes\CodeRequest::sessionHash(), Security\ClientIp, func.php fn_kwtsms_log_hook_error().
 */

use Tygh\Addons\Kwtsms\Codes\CodeRequest;
use Tygh\Addons\Kwtsms\Orders\CheckoutCode;
use Tygh\Addons\Kwtsms\Security\ClientIp;
use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

/** @var string $mode */
/** @var array<string,mixed> $auth */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $mode === 'repay' && !empty($_REQUEST['order_id'])) {
    try {
        if (Tygh::$app['kwtsms.config']->checkoutCode() !== 'off') {
            $kwtsms_order_id = (int) $_REQUEST['order_id'];
            // Core refuses an order the visitor may not see (orders.php top); this check only has to know whom to ask.
            if ($kwtsms_order_id > 0 && fn_is_order_allowed($kwtsms_order_id, $auth)) {
                $kwtsms_order = fn_get_order_info($kwtsms_order_id);
                if (is_array($kwtsms_order) && $kwtsms_order !== []) {
                    $kwtsms_cart = CheckoutCode::repayCart(
                        $kwtsms_order + ['order_id' => $kwtsms_order_id],
                        (int) ($_REQUEST['payment_id'] ?? 0)
                    );
                    $kwtsms_actor = ['user_id' => (int) ($auth['user_id'] ?? 0)];
                    $kwtsms_hash = CodeRequest::sessionHash();
                    $kwtsms_checkout = Tygh::$app['kwtsms.checkout_code'];
                    $kwtsms_text = $kwtsms_checkout->refusal(
                        $kwtsms_cart,
                        $kwtsms_actor,
                        $kwtsms_hash,
                        ClientIp::current(),
                        defined('CART_LANGUAGE') ? (string) CART_LANGUAGE : 'en'
                    );
                    if ($kwtsms_text !== null) {
                        fn_set_notification('E', (string) __('error'), fn_html_escape($kwtsms_text));

                        $kwtsms_back = (int) ($_REQUEST['payment_id'] ?? 0);

                        return [CONTROLLER_STATUS_REDIRECT, 'orders.details?order_id=' . $kwtsms_order_id
                            . ($kwtsms_back > 0 ? '&payment_id=' . $kwtsms_back : '')];
                    }
                    // Passed: the code (if one was needed) is used up and the phone is remembered for this order.
                    $kwtsms_checkout->consume($kwtsms_order_id, $kwtsms_cart, $kwtsms_actor, $kwtsms_hash);
                }
            }
        }
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('orders.repay', $e);
        // Fail closed while the checkout code is on: the order keeps its payment.
        fn_set_notification('E', (string) __('error'), fn_html_escape((string) __('kwtsms.code.check_failed')));

        return [CONTROLLER_STATUS_REDIRECT, 'orders.details?order_id=' . (int) ($_REQUEST['order_id'] ?? 0)];
    }
}
