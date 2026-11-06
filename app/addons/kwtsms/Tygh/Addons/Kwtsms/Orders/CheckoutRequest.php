<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Orders/CheckoutRequest.php
 * CREATED:   2026-11-06 03:03 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Which requests the checkout code (checkout_code, P3-54) never asks: exempt() is a pure decision over a
 *            description of the request (env()), the cart, the processor script of a payment and the listed
 *            cash-on-delivery payments. Exempt: the admin order editor, the console, any area but the storefront, an
 *            API call of an administrator or a vendor, and a payment provider returning to the store (controller
 *            payment_notification) for the processor of the cart's OWN payment.
 * WHY:       Task M2.4 fix 1 (security reviews I1, I2). Exempting payment_notification by controller name let a shopper
 *            open payment_notification.return&payment=<any processor that restores a session from sid and calls
 *            fn_place_order()> (core app/addons/paypal/payments/paypal_advanced.php:33-41,
 *            app/payments/skrill_func.php:34-43) and place a cash-on-delivery order with no code;
 *            exempting the API covered a customer's own API orders (core
 *            app/Tygh/Api/Entities/Orders.php:299, :487-494). A paid order must still not be lost, so the return of
 *            the cart's own processor stays exempt; every other case runs the normal check.
 * USAGE:     CheckoutRequest::exempt($cart, CheckoutRequest::env(), $script_of, $cod_ids): bool
 *              $script_of fn(int $payment_id): string, the processor script of a payment ('' = none, e.g. COD)
 *            CheckoutRequest::env(): array{area,api,order_management,console,controller,payment,user_type}
 * DEPENDS:   Tygh\Registry (env() only), Tygh::$app['session'] (env() only).
 */

namespace Tygh\Addons\Kwtsms\Orders;

final class CheckoutRequest
{
    /** User types of the API callers that are never asked (administrator, vendor administrator). */
    private const STAFF_TYPES = ['A', 'V'];

    /**
     * @param array<string,mixed> $cart
     * @param array<string,mixed> $env      env()
     * @param callable            $script_of fn(int $payment_id): string
     * @param int[]               $cod_ids  The listed cash-on-delivery payment ids
     */
    public static function exempt(array $cart, array $env, callable $script_of, array $cod_ids): bool
    {
        if (!empty($env['order_management']) || !empty($env['console'])) {
            return true;
        }
        if (!empty($env['api'])) {
            // An administrator or a vendor places orders for others; a customer token places its own and is asked.
            return in_array((string) ($env['user_type'] ?? ''), self::STAFF_TYPES, true);
        }
        if ((string) ($env['area'] ?? '') !== 'C') {
            return true;
        }
        if ((string) ($env['controller'] ?? '') !== 'payment_notification') {
            return false;
        }

        // A payment provider returning to the store: only the processor of the cart's own payment, and never a listed
        // cash-on-delivery payment. Core reduces the param to its base name and appends .php.
        $payment = trim((string) ($env['payment'] ?? ''));
        $payment_id = (int) ($cart['payment_id'] ?? 0);
        if ($payment === '' || $payment_id <= 0 || in_array($payment_id, $cod_ids, true)) {
            return false;
        }
        $script = (string) call_user_func($script_of, $payment_id);

        return $script !== '' && $script === basename(str_replace('\\', '/', $payment)) . '.php';
    }

    /**
     * What the running request is: constants, the controller, the payment param and the signed-in user type.
     *
     * @return array{area:string,api:bool,order_management:bool,console:bool,controller:string,payment:string,user_type:string}
     */
    public static function env(): array
    {
        $controller = '';
        $user_type = '';
        try {
            if (class_exists('Tygh\\Registry')) {
                $controller = (string) \Tygh\Registry::get('runtime.controller');
            }
            if (class_exists('Tygh\\Tygh') && isset(\Tygh\Tygh::$app['session'])) {
                $session = \Tygh\Tygh::$app['session'];
                $auth = isset($session['auth']) && is_array($session['auth']) ? $session['auth'] : [];
                $user_type = (string) ($auth['user_type'] ?? '');
            }
        } catch (\Throwable $e) {
            $user_type = '';
        }
        $payment = $_REQUEST['payment'] ?? '';

        return [
            'area' => defined('AREA') ? (string) AREA : '',
            'api' => defined('API'),
            'order_management' => defined('ORDER_MANAGEMENT'),
            'console' => defined('CONSOLE'),
            'controller' => $controller,
            'payment' => is_string($payment) ? $payment : '',
            'user_type' => $user_type,
        ];
    }
}
