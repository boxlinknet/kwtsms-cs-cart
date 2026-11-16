<?php

/*
 * FILE:      app/addons/kwtsms/func.php
 * CREATED:   2026-10-20 19:53 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Procedural functions of the kwtSMS add-on: the install and uninstall handlers named in
 *            addon.xml and the renderer of the visible "info" setting.
 * WHY:       addon.xml cannot express a random value (cron_key) or the HTML of the settings info item.
 *            Services and hooks arrive in later tasks; this file stays free of business logic.
 *            2026-10-20 20:46 +03: reworded when the table sweep was removed from fn_kwtsms_uninstall()
 *            (addon.xml names every table), task 596.
 * USAGE:     Loaded by CS-Cart: fn_kwtsms_install() and fn_kwtsms_uninstall() by the add-on installer,
 *            fn_kwtsms_settings_info() by Admin > Add-ons > kwtSMS (settings page).
 *            2026-10-21 00:20 +03: fn_kwtsms_send(), the public send API of the extension contract (PRD 5.2), Task
 *            1.6, task 596:  $r = fn_kwtsms_send(['+96550123401'], 'Your text', ['event_id' => 'my_addon.shipped']);
 *            2026-10-21 01:13 +03: fn_kwtsms_cron_run() and the helpers of the controller kwtsms_cron.run (cron key
 *            check, JSON reply, throttled log of a refused request), Task 1.7, task 596:
 *            php /var/www/cscart/admin.php --dispatch=kwtsms_cron.run
 *            2026-10-21 01:58 +03: the six order hook handlers fn_kwtsms_change_order_status(), ..._post(),
 *            fn_kwtsms_edit_place_order(), fn_kwtsms_place_order_manually_post(), fn_kwtsms_place_order_post(),
 *            fn_kwtsms_change_order_status_child_order() (PRD 4.3), delegating to Tygh::$app['kwtsms.order_hooks'],
 *            Task 1.9, task 596.
 * DEPENDS:   CS-Cart 4.18.1 to 4.21.2 core (Tygh\Settings, fn_url, fn_html_escape), Tygh::$app['kwtsms.sms'],
 *            Tygh::$app['kwtsms.cron'].
 */

use Tygh\Addons\Kwtsms\Codes\OtpService; // 2026-10-24 17:13 +03: Task M1.6, task 596
use Tygh\Addons\Kwtsms\Cron\CronKey;
use Tygh\Addons\Kwtsms\Sms\ApiErrors;
use Tygh\Addons\Kwtsms\Sms\SendResult;
use Tygh\Addons\Kwtsms\Sms\SmsService;
use Tygh\Addons\Kwtsms\Templates\TemplateRepository;
use Tygh\Languages\Languages;
use Tygh\Registry; // 2026-10-22 21:27 +03: Task A7, task 596
use Tygh\Settings;
use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

/**
 * Install handler (addon.xml functions, for="install"). Runs after the tables, privileges and settings
 * exist.
 *
 * Generates the cron key: 32 random hex characters, new on every install, never a static default.
 * Seeds the default SMS templates of every installed language (2026-10-20 23:12 +03, Task 1.8, task 596).
 * Nothing else is seeded: notification switches are fail-closed and need no rows (PRD 4.1).
 *
 * @return void
 */
function fn_kwtsms_install(): void
{
    // 2026-10-20 19:53 +03: cron_key generated here (PRD 9), task 596.
    Settings::instance(['company_id' => 0, 'storefront_id' => 0])
        ->updateValue('cron_key', bin2hex(random_bytes(16)), 'kwtsms');

    // 2026-10-20 23:12 +03: the shipped default SMS texts are copied into the template tables for every
    // installed language (PRD 6.4), INSERT IGNORE, so a second run changes nothing. A failure here must not
    // undo the install: the admin can load the defaults from the Templates page. Task 1.8, task 596.
    // 2026-10-21 00:05 +03: moved into fn_kwtsms_seed_default_templates(), which also tells the admin.
    fn_kwtsms_seed_default_templates();

    // 2026-10-24 21:13 +03: Task M1.7b (P3-36), task 596. The CAPTCHA scenario of the SMS code request is ticked on
    // install, so the mode is "always" by default. A failure here never undoes the install: the admin can tick it in
    // Settings > Image verification.
    try {
        fn_kwtsms_tick_sms_code_scenario();
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('install.tick_scenario', $e);
    }
}

/**
 * Seed the shipped default SMS texts for every installed language. Never throws: a failure goes to the PHP error
 * log (class, message, file:line, no trace) and to the admin as an error notification (langvar
 * kwtsms.templates_seed_failed), and the install carries on, because the texts can be loaded later from the
 * Templates page.
 * 2026-10-21 00:05 +03: extracted from fn_kwtsms_install() and the notification added, Task 1.8 fix round 1
 * item 6, task 596.
 *
 * @param TemplateRepository|null $templates Repository to seed (a test passes one that fails); null = the real one
 *
 * @return bool True when seeded
 */
function fn_kwtsms_seed_default_templates(?TemplateRepository $templates = null): bool
{
    try {
        $templates = $templates ?? new TemplateRepository();
        $templates->seedDefaults(array_map('strval', array_keys(Languages::getAll())));

        return true;
    } catch (\Throwable $e) {
        error_log('kwtsms: seeding the default templates failed: ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        fn_set_notification('E', __('error'), __('kwtsms.templates_seed_failed'));

        return false;
    }
}

/**
 * Uninstall handler (addon.xml functions, for="uninstall"). Runs before the queries of addon.xml and
 * before the settings and language values are removed.
 *
 * Intentionally empty in 1.0.0: every table, privilege, grant, notification row, storage key and language
 * value is removed by the uninstall queries of addon.xml, by exact name, so the add-on can never drop
 * something it does not own (no sweep over the "kwtsms_" prefix). Cleanup that SQL cannot express (for
 * example persistent admin notices) is added here by the task that creates it.
 *
 * @return void
 */
function fn_kwtsms_uninstall(): void
{
    // 2026-10-20 20:28 +03: the table sweep was removed, addon.xml drops exactly the nine tables (review
    // finding: the sweep was broader than "our tables" and duplicated addon.xml), task 596.
    // 2026-10-24 23:01 +03: M1.7b fix round 1 (review M3), task 596. The Image verification scenario this add-on added
    // leaves every list it was added to. A failure never blocks the uninstall.
    try {
        fn_kwtsms_untick_sms_code_scenario();
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('uninstall.untick_scenario', $e);
    }
}

/**
 * Renderer of the "info" setting: a button that opens the add-on's own Settings page. Every other
 * setting of the add-on is hidden and is edited there.
 *
 * @return string HTML, every dynamic part escaped
 */
function fn_kwtsms_settings_info(): string
{
    // 2026-10-20 19:53 +03: link to kwtsms.settings (PRD 9), task 596.
    $url = fn_url('kwtsms.settings');

    return '<a class="btn btn-primary" href="' . fn_html_escape($url) . '">'
        . fn_html_escape(__('kwtsms.open_settings'))
        . '</a>';
}

/*
 * Order hooks (PRD 4.3), registered in init.php. Each handler delegates to Tygh\Addons\Kwtsms\Orders\OrderHooks and
 * never throws: an SMS problem must not break a checkout, a payment callback or an admin save. Core line numbers
 * are CS-Cart 4.21.2.
 * 2026-10-21 01:58 +03: added, Task 1.9, task 596.
 */

/**
 * Hook change_order_status (fn.cart.php:2953), before core dispatches order.status_changed.<status>: flags a new
 * order so its status SMS is superseded by the order-placed SMS.
 *
 * @param string              $status_to          New status (B when the stock ran out)
 * @param string              $status_from        Old status
 * @param array<string,mixed> $order_info         fn_get_order_info() row
 * @param mixed               $force_notification Notify rules
 * @param array<string,mixed> $order_statuses     Unused
 * @param bool                $place_order        True inside fn_place_order()
 */
function fn_kwtsms_change_order_status(
    $status_to = null,
    $status_from = null,
    $order_info = null,
    $force_notification = null,
    $order_statuses = null,
    $place_order = null
) {
    // 2026-10-23 13:17 +03: final review A, Important 3 (I3), task 596. A core that passes fewer arguments (4.20.1 is
    // unverified) must not
    // throw ArgumentCountError into checkout: trailing parameters default, and a missing needed one skips quietly
    // (logged once).
    if (fn_kwtsms_hook_args_missing('change_order_status', func_num_args(), 6)) {
        return;
    }
    try {
        Tygh::$app['kwtsms.order_hooks']->onChangeOrderStatus(
            (string) $status_to,
            (string) $status_from,
            is_array($order_info) ? $order_info : [],
            $force_notification,
            (bool) $place_order
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('change_order_status', $e);
    }
}

/**
 * Hook change_order_status_post (fn.cart.php:3038): dispatches kwtsms.order_placed for a flagged order with the same
 * notify rules, records why no status SMS was handed over (diagnostics), clears the flag.
 *
 * @param int                 $order_id
 * @param string              $status_to
 * @param string              $status_from
 * @param mixed               $force_notification
 * @param bool                $place_order
 * @param array<string,mixed> $order_info
 * @param mixed               $edp_data           Unused
 */
function fn_kwtsms_change_order_status_post(
    $order_id = null,
    $status_to = null,
    $status_from = null,
    $force_notification = null,
    $place_order = null,
    $order_info = null,
    $edp_data = null
) {
    // 2026-10-23 13:17 +03: final review A, Important 3 (I3), task 596. A core that passes fewer arguments (4.20.1 is
    // unverified) must not
    // throw ArgumentCountError into checkout: trailing parameters default, and a missing needed one skips quietly
    // (logged once).
    if (fn_kwtsms_hook_args_missing('change_order_status_post', func_num_args(), 6)) {
        return;
    }
    try {
        Tygh::$app['kwtsms.order_hooks']->onChangeOrderStatusPost(
            (int) $order_id,
            (string) $status_to,
            (string) $status_from,
            $force_notification,
            (bool) $place_order,
            is_array($order_info) ? $order_info : []
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('change_order_status_post', $e);
    }
}

/**
 * Hook edit_place_order (fn.cart.php:1358): the admin is editing this order; it is never a new order.
 *
 * @param int $order_id
 */
function fn_kwtsms_edit_place_order($order_id = null)
{
    // 2026-10-23 13:17 +03: final review A, Important 3 (I3), task 596. A core that passes fewer arguments (4.20.1 is
    // unverified) must not
    // throw ArgumentCountError into checkout: trailing parameters default, and a missing needed one skips quietly
    // (logged once).
    if (fn_kwtsms_hook_args_missing('edit_place_order', func_num_args(), 1)) {
        return;
    }
    try {
        Tygh::$app['kwtsms.order_hooks']->onEditPlaceOrder((int) $order_id);
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('edit_place_order', $e);
    }
}

/**
 * Hook place_order_manually_post (backend order_management.php:779): the order-placed SMS of an order the admin
 * created, with the admin's Notify boxes (inside fn_place_order() AREA A forces every notify rule to false).
 *
 * @param array<string,mixed> $cart
 * @param array<string,mixed> $params
 * @param array<string,mixed> $customer_auth
 * @param string              $action
 * @param int                 $issuer_id
 * @param mixed               $force_notification The admin's notify rules
 * @param mixed               $order_info         Unused (undefined in core when no notification is required)
 * @param mixed               $edp_data           Unused
 * @param bool                $is_order_placed_notification_required Unused
 */
function fn_kwtsms_place_order_manually_post(
    $cart = null,
    $params = null,
    $customer_auth = null,
    $action = null,
    $issuer_id = null,
    $force_notification = null,
    $order_info = null,
    $edp_data = null,
    $is_order_placed_notification_required = true
) {
    // 2026-10-23 13:17 +03: final review A, Important 3 (I3), task 596. A core that passes fewer arguments (4.20.1 is
    // unverified) must not
    // throw ArgumentCountError into checkout: trailing parameters default, and a missing needed one skips quietly
    // (logged once).
    if (fn_kwtsms_hook_args_missing('place_order_manually_post', func_num_args(), 6)) {
        return;
    }
    try {
        Tygh::$app['kwtsms.order_hooks']->onPlaceOrderManuallyPost($force_notification);
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('place_order_manually_post', $e);
    }
}

/**
 * Hook place_order_post (fn.cart.php:1724): on the parent of a Multi-Vendor checkout, once every child exists, the
 * one checkout SMS.
 *
 * @param array<string,mixed> $cart
 * @param array<string,mixed> $auth
 * @param string              $action
 * @param int                 $issuer_id
 * @param int                 $parent_order_id 0 on the parent (or a plain order)
 * @param int                 $order_id
 * @param string              $order_status
 * @param array<string,mixed> $short_order_data
 * @param mixed               $notification_rules Unused (all false on a parent)
 */
function fn_kwtsms_place_order_post(
    $cart = null,
    $auth = null,
    $action = null,
    $issuer_id = null,
    $parent_order_id = null,
    $order_id = null,
    $order_status = null,
    $short_order_data = null,
    $notification_rules = null
) {
    // 2026-10-23 13:17 +03: final review A, Important 3 (I3), task 596. A core that passes fewer arguments (4.20.1 is
    // unverified) must not
    // throw ArgumentCountError into checkout: trailing parameters default, and a missing needed one skips quietly
    // (logged once).
    if (fn_kwtsms_hook_args_missing('place_order_post', func_num_args(), 8)) {
        return;
    }
    try {
        Tygh::$app['kwtsms.order_hooks']->onPlaceOrderPost(
            (int) $parent_order_id,
            (int) $order_id,
            is_array($short_order_data) ? $short_order_data : []
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('place_order_post', $e);
    }
}

/**
 * Hook change_order_status_child_order (fn.cart.php:2806): a parent order moves this child in the current request.
 *
 * @param int    $order_id            Parent order id
 * @param string $status_to
 * @param string $status_from
 * @param mixed  $force_notification
 * @param bool   $place_order
 * @param int    $child_order_id
 * @param string $child_status_to
 * @param bool   $change_child_status
 * @param string $child_status_from
 */
function fn_kwtsms_change_order_status_child_order(
    $order_id = null,
    $status_to = null,
    $status_from = null,
    $force_notification = null,
    $place_order = null,
    $child_order_id = null,
    $child_status_to = null,
    $change_child_status = null,
    $child_status_from = null
) {
    // 2026-10-23 13:17 +03: final review A, Important 3 (I3), task 596. A core that passes fewer arguments (4.20.1 is
    // unverified) must not
    // throw ArgumentCountError into checkout: trailing parameters default, and a missing needed one skips quietly
    // (logged once).
    if (fn_kwtsms_hook_args_missing('change_order_status_child_order', func_num_args(), 8)) {
        return;
    }
    try {
        Tygh::$app['kwtsms.order_hooks']->onChangeOrderStatusChildOrder(
            (int) $order_id,
            (int) $child_order_id,
            (bool) $change_child_status
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('change_order_status_child_order', $e);
    }
}

/**
 * 2026-10-22 18:02 +03: Task A5 (R18), task 596. Hook update_shipment_before_send_notification (fn.cart.php:9091):
 * the shipment was
 * created and core is about to dispatch order.shipment_updated with $force_notification. The hook create_shipment
 * (fn.cart.php:9013) runs before the shipment exists and carries no notification rules, so it cannot tell whether
 * the customer will be notified; this one can.
 *
 * @param array<string,mixed> $shipment_data
 * @param int                 $shipment_id
 * @param int                 $group_no
 * @param bool                $all_products
 * @param mixed               $force_notification
 * @param array<string,mixed> $order_info
 * @param array<string,mixed> $shipment
 */
function fn_kwtsms_update_shipment_before_send_notification(
    $shipment_data = null,
    $shipment_id = null,
    $group_no = null,
    $all_products = null,
    $force_notification = null,
    $order_info = null,
    $shipment = []
) {
    // 2026-10-23 13:17 +03: final review A, Important 3 (I3), task 596. A core that passes fewer arguments (4.20.1 is
    // unverified) must not
    // throw ArgumentCountError into checkout: trailing parameters default, and a missing needed one skips quietly
    // (logged once).
    if (fn_kwtsms_hook_args_missing('update_shipment_before_send_notification', func_num_args(), 6)) {
        return;
    }
    try {
        Tygh::$app['kwtsms.order_hooks']->onShipmentBeforeSend(
            is_array($order_info) ? $order_info : [],
            $force_notification
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('update_shipment_before_send_notification', $e);
    }
}

/**
 * 2026-10-22 19:09 +03: Task A6 (SafatMall F5 part, P2-7), task 596. Hook update_profile (fn.users.php, end of
 * fn_update_user): a
 * customer account was added or updated. A storefront or checkout registration dispatches the admin registration
 * alert (kwtsms.customer_registered); the welcome SMS itself is core's profile.created.c with the SMS transport.
 * Never throws.
 *
 * @param string              $action            add or update
 * @param array<string,mixed> $user_data
 * @param array<string,mixed> $current_user_data
 */
function fn_kwtsms_update_profile($action = null, $user_data = null, $current_user_data = [])
{
    // 2026-10-23 13:17 +03: final review A, Important 3 (I3), task 596. A core that passes fewer arguments (4.20.1 is
    // unverified) must not
    // throw ArgumentCountError into checkout: trailing parameters default, and a missing needed one skips quietly
    // (logged once).
    if (fn_kwtsms_hook_args_missing('update_profile', func_num_args(), 2)) {
        return;
    }
    try {
        Tygh::$app['kwtsms.profile_hooks']->onUpdateProfile(
            (string) $action,
            is_array($user_data) ? $user_data : [],
            is_array($current_user_data) ? $current_user_data : []
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('update_profile', $e);
    }
}

/**
 * 2026-10-28 18:54 +03: Task M1.10a fix round 1 (review I2, ruling P3-R12), task 596. Hook
 * hybrid_auth_create_user (core
 * add-on hybrid_auth, func.php:369, before it creates an account for a first social sign-in; $user_data by
 * reference). While
 * register_code is ON a social sign-up cannot carry an SMS code, so update_user_pre refuses it (the P3-28 notice). Core
 * then still e-mails a "profile created" message when the user data has an e-mail address, for an account that does not
 * exist: the e-mail is taken out of the data here, so no such mail leaves (and nothing else changes). With
 * register_code
 * OFF nothing happens. Never throws.
 *
 * @param mixed $auth_data
 * @param mixed $provider
 * @param mixed $user_data By reference
 */
function fn_kwtsms_hybrid_auth_create_user($auth_data = null, $provider = null, &$user_data = null)
{
    if (fn_kwtsms_hook_args_missing('hybrid_auth_create_user', func_num_args(), 3)) {
        return;
    }
    try {
        if (is_array($user_data) && isset(Tygh::$app['kwtsms.config']) && Tygh::$app['kwtsms.config']->registerCode()) {
            $user_data['email'] = '';
        }
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('hybrid_auth_create_user', $e);
    }
}

/**
 * 2026-10-25 04:38 +03: Task M1.9 (SafatMall C3, C4; P3-15, P3-21), task 596. Hook update_user_pre (fn.users.php,
 * fn_update_user(), before core's own checks): the phone fields of a customer account that are new or changed are
 * checked (country, one account per phone, SMS code) and rewritten to their canonical form by reference;
 * $can_update = false refuses the save. Core 4.21.2 passes all six arguments; a core that passes fewer makes the
 * check inactive (the guard logs it), never "allowed" on a half-read state. Never throws.
 *
 * @param int|string $user_id
 * @param mixed      $user_data       By reference: the posted user data
 * @param mixed      $auth            By reference (unused)
 * @param mixed      $ship_to_another
 * @param mixed      $notify_user
 * @param mixed      $can_update      By reference: set to false to refuse the save
 */
function fn_kwtsms_update_user_pre(
    $user_id = null,
    &$user_data = null,
    &$auth = null,
    $ship_to_another = false,
    $notify_user = false,
    &$can_update = true
) {
    if (fn_kwtsms_hook_args_missing('update_user_pre', func_num_args(), 6)) {
        return;
    }
    try {
        if (!is_array($user_data)) {
            return;
        }
        $allowed = $can_update !== false;
        Tygh::$app['kwtsms.profile_hooks']->onUpdateUserPre((int) $user_id, $user_data, $allowed);
        if (!$allowed) {
            $can_update = false;
        }
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('update_user_pre', $e);
        // 2026-10-29 20:08 +03: Task 596 (branch review M1, ruling P3-R16). Fail closed: while a code feature is ON
        // an error in the
        // check refuses the save (no account without a code, no phone change without a code).
        if (fn_kwtsms_code_features_on()) {
            $can_update = false;
            if (function_exists('fn_set_notification')) {
                fn_set_notification('E', (string) __('error'), fn_html_escape((string) __('kwtsms.code.check_failed')));
            }
        }
    }
}

/**
 * 2026-10-25 04:38 +03: Task M1.9 (SafatMall C3; P3-21), task 596. Hook pre_place_order (fn.cart.php,
 * fn_place_order()): in the storefront, the phone fields of the cart's user data that are new or changed are checked
 * (country) and rewritten to their canonical form by reference, so the order carries them; $allow = false stops the
 * order. C4 is not checked at checkout. Never throws.
 *
 * @param mixed $cart           By reference
 * @param mixed $allow          By reference
 * @param mixed $product_groups (unused)
 */
function fn_kwtsms_pre_place_order(&$cart = null, &$allow = null, $product_groups = [])
{
    if (fn_kwtsms_hook_args_missing('pre_place_order', func_num_args(), 2)) {
        return;
    }
    try {
        if (!is_array($cart)) {
            return;
        }
        $flag = $allow !== false;
        Tygh::$app['kwtsms.profile_hooks']->onPrePlaceOrder($cart, $flag);
        if (!$flag) {
            $allow = false;
        }
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('pre_place_order', $e);
        // 2026-11-05 21:10 +03: Task M2.4 (B6, P3-54), task 596. Fail closed while the checkout code is on: when the
        // check cannot even be built, the order is not placed (nothing exists yet, the cart is kept), except in the
        // requests that are never asked. The phone rules (C3) stay fail open as before.
        try {
            if (
                isset(Tygh::$app['kwtsms.config']) && Tygh::$app['kwtsms.config']->checkoutCode() !== 'off'
                && !\Tygh\Addons\Kwtsms\Orders\CheckoutCode::requestExempt(is_array($cart) ? $cart : [])
            ) {
                $allow = false;
                fn_set_notification('E', __('error'), fn_html_escape((string) __('kwtsms.code.check_failed')));
            }
        } catch (\Throwable $inner) {
            fn_kwtsms_log_hook_error('pre_place_order.closed', $inner);
        }
    }
}

/**
 * 2026-11-05 20:54 +03: Task M2.4 (SafatMall B6, P3-54), task 596. Hook place_order (fn.cart.php, fn_place_order(),
 * after the order row exists): the SMS code of the checkout (checkout_code) is used up for the PARENT order only, and
 * the verified phone is remembered for this order (create_profile of that order). Inactive while checkout_code is
 * off. Never throws; an older core that passes fewer arguments makes it inactive (platform_missing).
 *
 * @param mixed $order_id
 * @param mixed $action       Unused
 * @param mixed $order_status Unused
 * @param mixed $cart
 * @param mixed $auth
 */
function fn_kwtsms_place_order($order_id = null, $action = '', $order_status = '', $cart = [], $auth = [])
{
    if (fn_kwtsms_hook_args_missing('place_order', func_num_args(), 5)) {
        return;
    }
    try {
        if (!is_array($cart) || !is_array($auth) || (int) $order_id <= 0) {
            return;
        }
        $kwtsms_sid = Tygh::$app['session']->getID();
        Tygh::$app['kwtsms.checkout_code']->consume(
            (int) $order_id,
            $cart,
            $auth,
            \Tygh\Addons\Kwtsms\Codes\CodeRequest::hashOfSessionId((string) $kwtsms_sid)
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('place_order', $e);
    }
}

/**
 * 2026-11-06 03:09 +03: Task M2.4 fix 1 (security review I2), task 596. Hook
 * api_orders_create_after_add_product_to_cart (core app/Tygh/Api/Entities/Orders.php:204): an order created through
 * the REST API by a CUSTOMER token is asked for the checkout code like a storefront order. A token has no browser
 * session to bind a code to, so the only proof it can give is the account phone verified in the phone index (order
 * phone = verified account phone); anything else is refused here with a clear 403 before any cart work, and
 * pre_place_order refuses again as the second line. Administrator and vendor callers are never asked
 * (CheckoutRequest). Never throws; an error while the mode is on refuses (fail closed).
 *
 * @param mixed $params       The request parameters (payment_id)
 * @param mixed $status       By reference, the API response status
 * @param mixed $data         By reference, the API response data
 * @param mixed $valid_params By reference
 * @param mixed $auth         The API authentication data
 * @param mixed $cart         The cart built so far (user_data of the account)
 */
function fn_kwtsms_api_orders_create_after_add_product_to_cart(
    $params = [],
    &$status = null,
    &$data = null,
    &$valid_params = null,
    $auth = [],
    $cart = []
) {
    if (fn_kwtsms_hook_args_missing('api_orders_create_after_add_product_to_cart', func_num_args(), 6)) {
        return;
    }
    try {
        if (!$valid_params || !is_array($cart) || Tygh::$app['kwtsms.config']->checkoutCode() === 'off') {
            return;
        }
        $auth = is_array($auth) ? $auth : [];
        $cart['payment_id'] = (int) (is_array($params) ? ($params['payment_id'] ?? 0) : 0);
        $actor = ['user_id' => (int) ($auth['user_id'] ?? 0)];
        $checkout = Tygh::$app['kwtsms.checkout_code'];
        if (!$checkout->applies($cart, $actor) || $checkout->satisfied($cart, $actor, '')) {
            return;
        }
        $refuse = true;
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('api_orders_create', $e);
        $refuse = true;
    }
    if ($refuse) {
        $valid_params = false;
        $status = 403;
        if (!is_array($data)) {
            $data = [];
        }
        $data['message'] = (string) __('kwtsms.checkout.api_code_required');
    }
}

/**
 * 2026-11-05 20:58 +03: Task M2.4 (SafatMall B6, P3-54), task 596. For hooks/checkout/
 * final_section_customer_notes.post.tpl (modifier |fn_kwtsms_checkout_code_form, the cart as the value): whether the
 * checkout shows the SMS code box now. mode all: always; guest: only while nobody is signed in; cod: only while the
 * chosen payment is one of the listed ones (the payment change re-renders the final section, so this decides again).
 * 'verified_phone' = the digits of the signed-in customer's account phone that a code verified (the box hides itself
 * while the phone of the form is that one; the server decides, never the browser), '' otherwise. Settings and the
 * phone index only; never throws; hidden on any error (the server still asks for the code).
 *
 * @param mixed $cart
 *
 * @return array{show:bool,verified_phone:string,default_cc:string,cod?:bool}
 */
function fn_kwtsms_checkout_code_form($cart = [])
{
    $none = ['show' => false, 'verified_phone' => '', 'default_cc' => '965'];
    try {
        if (!isset(Tygh::$app['kwtsms.config']) || !defined('AREA') || AREA !== 'C' || defined('ORDER_MANAGEMENT')) {
            return $none;
        }
        $config = Tygh::$app['kwtsms.config'];
        $mode = $config->checkoutCode();
        if ($mode === 'off') {
            return $none;
        }
        $user_id = (int) (Tygh::$app['session']['auth']['user_id'] ?? 0);
        $payment_id = is_array($cart) ? (int) ($cart['payment_id'] ?? 0) : 0;
        if (
            ($mode === 'guest' && $user_id > 0)
            || ($mode === 'cod' && !in_array($payment_id, $config->checkoutCodPaymentIds(), true))
        ) {
            return $none;
        }
        $verified = '';
        if ($user_id > 1) {
            $verified = (string) preg_replace('/\D/', '', (string) db_get_field(
                'SELECT phone FROM ?:kwtsms_phone_index WHERE user_id = ?i AND verified_phone IS NOT NULL',
                $user_id
            ));
        }

        // 2026-11-06 03:23 +03: Task M2.4 fix 1 (M1), task 596. 'cod' = mode cod: the code goes to the shipping phone
        // when one is entered (CheckoutCode::phoneOf), so the box reads that input first.
        return [
            'show' => true, 'verified_phone' => $verified, 'default_cc' => (string) $config->defaultCountryCode(),
            'cod' => $mode === 'cod',
        ];
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('checkout_code_form', $e);

        return $none;
    }
}

/**
 * 2026-11-06 02:54 +03: Task M2.4 fix 1 (security review C1), task 596. For the code request of a repay
 * (kwtsms_code.send, purpose checkout, order_id): the canonical phone of the ORDER, which is the only number the
 * code of a repay can go to (never a number the request carries), or '' = answer neutrally and send nothing: the
 * order is not the visitor's, the mode does not ask for this payment, or the order is already verified for this
 * visitor. Never throws.
 *
 * @param mixed $order_id
 * @param mixed $payment_id The payment the order would be switched to
 * @param mixed $auth       The session auth
 */
function fn_kwtsms_repay_code_phone($order_id, $payment_id, $auth)
{
    try {
        $order_id = (int) $order_id;
        $auth = is_array($auth) ? $auth : [];
        if ($order_id <= 0 || !fn_is_order_allowed($order_id, $auth)) {
            return '';
        }
        $order = fn_get_order_info($order_id);
        if (!is_array($order) || $order === []) {
            return '';
        }
        $checkout = Tygh::$app['kwtsms.checkout_code'];
        $cart = \Tygh\Addons\Kwtsms\Orders\CheckoutCode::repayCart(
            $order + ['order_id' => $order_id],
            (int) $payment_id
        );
        $actor = ['user_id' => (int) ($auth['user_id'] ?? 0)];
        if (
            !$checkout->applies($cart, $actor)
            || $checkout->satisfied($cart, $actor, \Tygh\Addons\Kwtsms\Codes\CodeRequest::sessionHash())
        ) {
            return '';
        }

        return $checkout->phoneOf($cart);
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('repay_code_phone', $e);

        return '';
    }
}

/**
 * 2026-11-06 03:32 +03: Task M2.4 fix 1 (security review M3), task 596. For the code request of the checkout
 * (kwtsms_code.send, purpose checkout, no order id): true only when the session cart holds products and the checkout
 * mode asks for this cart (CheckoutCode::applies(): mode guest not for a signed-in customer, mode cod only with a
 * listed payment). Otherwise the endpoint answers neutrally and sends nothing. Never throws; false on any error.
 *
 * @param mixed $auth The session auth
 */
function fn_kwtsms_checkout_request_allowed($auth)
{
    try {
        $cart = isset(Tygh::$app['session']['cart']) && is_array(Tygh::$app['session']['cart'])
            ? Tygh::$app['session']['cart'] : [];
        if (empty($cart['products']) || !is_array($cart['products'])) {
            return false;
        }
        $actor = ['user_id' => (int) (is_array($auth) ? ($auth['user_id'] ?? 0) : 0)];

        return (bool) Tygh::$app['kwtsms.checkout_code']->applies($cart, $actor);
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('checkout_request_allowed', $e);

        return false;
    }
}

/**
 * 2026-11-06 02:54 +03: Task M2.4 fix 1 (security review C1), task 596. For hooks/orders/repay.pre.tpl (modifier
 * |fn_kwtsms_repay_code_form, the order as the value): whether the repay section shows the SMS code box and for which
 * payments. 'payments' = '*' (every payment) or the comma list of the listed cash-on-delivery ids. Hidden when the
 * order is already verified for this visitor (the signed-in account phone, or a code of this session). The server
 * decides in orders.pre.php; this only spares the customer a field they are never asked for. Never throws.
 *
 * @param mixed $order_info
 *
 * @return array{show:bool,payments:string,order_id:int}
 */
function fn_kwtsms_repay_code_form($order_info = [])
{
    $none = ['show' => false, 'payments' => '*', 'order_id' => 0];
    try {
        if (!isset(Tygh::$app['kwtsms.config']) || !defined('AREA') || AREA !== 'C' || !is_array($order_info)) {
            return $none;
        }
        $config = Tygh::$app['kwtsms.config'];
        $mode = $config->checkoutCode();
        $order_id = (int) ($order_info['order_id'] ?? 0);
        $auth = isset(Tygh::$app['session']['auth']) && is_array(Tygh::$app['session']['auth'])
            ? Tygh::$app['session']['auth'] : [];
        $user_id = (int) ($auth['user_id'] ?? 0);
        $ids = $config->checkoutCodPaymentIds();
        if (
            $mode === 'off' || $order_id <= 0 || ($mode === 'guest' && $user_id > 0)
            || ($mode === 'cod' && $ids === [])
        ) {
            return $none;
        }
        $checkout = Tygh::$app['kwtsms.checkout_code'];
        $cart = \Tygh\Addons\Kwtsms\Orders\CheckoutCode::repayCart($order_info, 0);
        $hash = \Tygh\Addons\Kwtsms\Codes\CodeRequest::sessionHash();
        if ($checkout->satisfied($cart, ['user_id' => $user_id], $hash)) {
            return $none;
        }

        return ['show' => true, 'payments' => $mode === 'cod' ? implode(',', $ids) : '*', 'order_id' => $order_id];
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('repay_code_form', $e);

        return $none;
    }
}

/**
 * 2026-11-05 20:54 +03: Task M2.4 (SafatMall B6, P3-54, ruling P3-R12), task 596. For hooks/index/head_scripts.post.tpl
 * (modifier |fn_kwtsms_hide_create_account): true while the "create account" block of the order confirmation page must
 * stay hidden, that is while register_code is ON and the order of the page was not verified by a checkout code in
 * this session. Never throws; false on any error (the block then shows, and its submit is still checked by P3-28).
 *
 * @param mixed $order_id The order of the page (request order_id), 0 elsewhere
 */
function fn_kwtsms_hide_create_account($order_id = 0)
{
    try {
        if (!isset(Tygh::$app['kwtsms.config']) || !Tygh::$app['kwtsms.config']->registerCode()) {
            return false;
        }
        // 2026-11-06 03:41 +03: Task M2.4 fix 1 (security review M4), task 596. The order is read only on the order
        // confirmation page (checkout.complete), the one page that shows the block; and its phone is made canonical
        // exactly as CheckoutCode::consume() stored it (the phone fields of the order read with the calling code of
        // their country field), so a number typed locally with a non-default country still matches.
        $on_complete = (string) \Tygh\Registry::get('runtime.controller') === 'checkout'
            && (string) \Tygh\Registry::get('runtime.mode') === 'complete';
        $canonical = '';
        if ($on_complete && (int) $order_id > 0 && Tygh::$app['kwtsms.config']->checkoutCode() !== 'off') {
            $row = db_get_row(
                'SELECT phone, b_phone, s_phone, b_country, s_country FROM ?:orders WHERE order_id = ?i',
                (int) $order_id
            );
            $checkout = Tygh::$app['kwtsms.checkout_code'];
            $canonical = is_array($row) && $row !== []
                ? $checkout->phoneOf(\Tygh\Addons\Kwtsms\Orders\CheckoutCode::repayCart($row, 0)) : '';
        }
        if ($canonical === '') {
            return true;
        }

        return !Tygh::$app['kwtsms.checkout_code']->verifiedForOrder((int) $order_id, $canonical);
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('hide_create_account', $e);

        return false;
    }
}

/**
 * 2026-10-24 18:46 +03: M1.6 fix round 1 (I2, ruling P3-R6), task 596. Hook update_user_profile_post
 * (fn_update_user_profile(), fn.users.php): a profile of an account was saved, also by the checkout profile editor
 * (checkout.update_profile) which does not fire update_profile. The phone index follows while a feature that needs
 * it is ON; inside fn_update_user it does nothing (update_profile writes). Never throws.
 *
 * @param int|string $user_id
 * @param mixed      $user_data Profile data (unused: the saved account is re-read)
 * @param mixed      $action
 */
function fn_kwtsms_update_user_profile_post($user_id = null, $user_data = null, $action = '')
{
    if (fn_kwtsms_hook_args_missing('update_user_profile_post', func_num_args(), 2)) {
        return;
    }
    try {
        Tygh::$app['kwtsms.profile_hooks']->onUpdateUserProfile(
            (int) $user_id,
            is_array($user_data) ? $user_data : [],
            (string) $action
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('update_user_profile_post', $e);
    }
}

/**
 * 2026-10-24 18:50 +03: M1.6 fix round 1 (M1), task 596: now the hook post_delete_user (after core's DELETE), so a
 * backfill batch cannot write the row between the cleanup and the delete; was fn_kwtsms_delete_user on delete_user.
 * 2026-10-24 17:13 +03: Task M1.6 (P3-31), task 596. Hook delete_user (fn.users.php, fn_delete_user): a user account
 * was deleted. While a feature that needs the phone index is ON (phone_unique, register_code, phone_change_code) the
 * account's index row and its SMS code rows go with it; while all are OFF nothing is read or written (the backfill
 * sweep removes the rest when it next runs). Never throws; user ids below 1 are ignored.
 *
 * @param int|string $user_id
 * @param mixed      $user_data The deleted account's data (unused)
 */
function fn_kwtsms_post_delete_user($user_id = null, $user_data = [], $result = null)
{
    if (fn_kwtsms_hook_args_missing('post_delete_user', func_num_args(), 1)) {
        return;
    }
    try {
        $id = (int) $user_id;
        $index = Tygh::$app['kwtsms.phone_index'];
        // 2026-11-17 00:40 +03: task M3.22 P3 (M3.18 review), task 596. The account's phone_index row goes with the
        // account whatever the switches say (an exact-id delete of our own row): a row left by a feature that was
        // switched off later no longer outlives the user. The SMS code rows stay behind the active() check.
        if ($id > 0) {
            $index->delete($id);
        }
        if ($id > 0 && $index->active()) {
            OtpService::deleteUserCodes($id);
        }
        // 2026-11-04 22:45 +03: Task M2.2a (P3-48), task 596. The account's 2FA row and backup codes go with it,
        // whatever the
        // switches say (an exact-id delete of our own rows; the table is empty on a store that never enrolled).
        if ($id > 0) {
            Tygh::$app['kwtsms.twofa_store']->delete($id);
        }
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('post_delete_user', $e);
    }
}

/**
 * 2026-10-23 00:54 +03: Task A10 (SafatMall F6, P2-4), task 596. For the storefront profile and registration form
 * (hook template
 * hooks/profiles/account_update.post.tpl, modifier |fn_kwtsms_order_sms_choice): whether to show the "SMS about my
 * order status" box and whether it is ticked. Shown only while SMS sending is switched on; ticked unless the account
 * opted out (a new account, user 0, is ticked). Never throws.
 *
 * @param int|string $user_id the logged-in user, 0 on the registration form
 *
 * @return array{show:bool,allowed:bool}
 */
function fn_kwtsms_order_sms_choice($user_id)
{
    try {
        if (!Tygh::$app['kwtsms.config']->enabled()) {
            return ['show' => false, 'allowed' => true];
        }

        // 2026-10-29 00:04 +03: Task M1.11s item 2 (SafatMall note 2), task 596. Hidden while no customer order SMS
        // (status change, order updated, shipment created) is ON for this storefront; the stored choice stays.
        $storefront = Tygh::$app['storefront'] ?? null;
        $storefront_id = is_object($storefront) && isset($storefront->storefront_id)
            ? (int) $storefront->storefront_id : 0;
        if (!Tygh::$app['kwtsms.order_sms_scope']->anyOn($storefront_id)) {
            return ['show' => false, 'allowed' => true];
        }

        return ['show' => true, 'allowed' => Tygh::$app['kwtsms.customer_prefs']->orderSmsAllowed((int) $user_id)];
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('order_sms_choice', $e);

        return ['show' => false, 'allowed' => true];
    }
}

/**
 * 2026-10-22 21:27 +03: Task A7 (SafatMall F5 part, P2-10), task 596. Hook update_product_amount
 * (app/functions/fn.cart.php:1196 in 4.21.2, fn_update_product_amount(), after core mailed its own low-stock e-mail
 * and before the products row is written). The ten arguments are those of the fn_set_hook() call there. A product
 * that crosses the low-stock threshold downwards is handed to the collector; the SMS goes out once per receiver
 * after the order status change (OrderHooks) or at shutdown. Costs two integer comparisons for a product that does
 * not cross. Never throws.
 *
 * @param int    $new_amount      New product quantity
 * @param int    $product_id      Product ID
 * @param int    $cart_id         Product cart ID
 * @param string $tracking        Inventory tracking mode
 * @param bool   $notify          Whether stock notifications are sent
 * @param array  $order_info      Order information
 * @param int    $amount_delta    Quantity change
 * @param int    $current_amount  Quantity before the change
 * @param int    $original_amount Original quantity
 * @param string $sign            - or +
 */
function fn_kwtsms_update_product_amount(
    $new_amount = null,
    $product_id = null,
    $cart_id = null,
    $tracking = null,
    $notify = null,
    $order_info = null,
    $amount_delta = null,
    $current_amount = null,
    $original_amount = null,
    $sign = null
) {
    // 2026-10-23 13:17 +03: final review A, Important 3 (I3), task 596. A core that passes fewer arguments (4.20.1 is
    // unverified) must not
    // throw ArgumentCountError into checkout: trailing parameters default, and a missing needed one skips quietly
    // (logged once).
    // 2026-10-23 14:11 +03: task A15 small fix, task 596. Needs 9 of the 10 arguments: $original_amount (9th) is
    // unused, so a core passing 9 still runs the low-stock path.
    if (fn_kwtsms_hook_args_missing('update_product_amount', func_num_args(), 9)) {
        return;
    }
    try {
        $threshold = (int) Registry::get('settings.General.low_stock_threshold');
        // 2026-10-23 05:45 +03: Task CA1-1 (A7 M7), task 596. A "+" (stock put back after a backorder/error path in
        // fn_change_order_status) above the threshold clears a crossing recorded earlier in the request; it only
        // builds the service when a crossing was recorded at all.
        if ((string) $sign === '+') {
            if ((int) $new_amount > $threshold && \Tygh\Addons\Kwtsms\Orders\LowStockCollector::anyRecorded()) {
                Tygh::$app['kwtsms.low_stock']->record(
                    (int) $product_id,
                    0,
                    (int) $current_amount,
                    (int) $new_amount,
                    '+',
                    $threshold
                );
            }

            return;
        }
        if ((string) $sign !== '-') {
            return;
        }
        // The cheap test first (no service is built for the common case). 2026-10-23 08:33 +0300: cleanup CA2-3 (A7
        // M6), task 596: this is the
        // threshold test of core (fn.cart.php:1146-1148) without its mail; the "was above" part is
        // LowStockCollector::crosses().
        if ((int) $current_amount <= $threshold || (int) $new_amount > $threshold || defined('ORDER_MANAGEMENT')) {
            return;
        }
        $collector = Tygh::$app['kwtsms.low_stock'];
        $collector->record(
            (int) $product_id,
            (int) fn_get_company_id('products', 'product_id', (int) $product_id),
            (int) $current_amount,
            (int) $new_amount,
            (string) $sign,
            $threshold
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('update_product_amount', $e);
    }
}

/*
 * Vendor hooks (Task A8, SafatMall F4). Multi-Vendor only: in CS-Cart and Ultimate a company is a storefront and
 * nothing here runs. Each handler never throws.
 * 2026-10-22 22:23 +03: added, Task A8, task 596.
 */

/**
 * 2026-10-22 22:23 +03: Task A8, task 596. Hook update_company (fn.companies.php:1052 in 4.21.2): a vendor was
 * created or saved. A
 * vendor whose phone no longer matches its approved number loses the approval (the vendor changed it in its panel,
 * or an administrator did).
 *
 * @param array<string,mixed> $company_data
 * @param int                 $company_id
 * @param string              $lang_code
 * @param string              $action        add or update
 */
function fn_kwtsms_update_company($company_data = null, $company_id = null, $lang_code = null, $action = null)
{
    // 2026-10-23 13:17 +03: final review A, Important 3 (I3), task 596. A core that passes fewer arguments (4.20.1 is
    // unverified) must not
    // throw ArgumentCountError into checkout: trailing parameters default, and a missing needed one skips quietly
    // (logged once).
    if (fn_kwtsms_hook_args_missing('update_company', func_num_args(), 4)) {
        return;
    }
    try {
        if ((string) $action !== 'update' || !fn_allowed_for('MULTIVENDOR')) {
            return;
        }
        Tygh::$app['kwtsms.vendor_phones']->onCompanyUpdated((int) $company_id);
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('update_company', $e);
    }
}

/**
 * 2026-10-22 22:23 +03: Task A8, task 596. Hook delete_company (fn.companies.php:1234): the approved number goes with
 * the vendor.
 *
 * @param int   $company_id
 * @param mixed $result      The deletion result
 * @param mixed $storefronts Unused
 */
function fn_kwtsms_delete_company($company_id = null, $result = null, $storefronts = [])
{
    // 2026-10-23 13:17 +03: final review A, Important 3 (I3), task 596. A core that passes fewer arguments (4.20.1 is
    // unverified) must not
    // throw ArgumentCountError into checkout: trailing parameters default, and a missing needed one skips quietly
    // (logged once).
    if (fn_kwtsms_hook_args_missing('delete_company', func_num_args(), 2)) {
        return;
    }
    try {
        if (!$result || !fn_allowed_for('MULTIVENDOR')) {
            return;
        }
        Tygh::$app['kwtsms.vendor_phones']->onCompanyDeleted((int) $company_id);
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('delete_company', $e);
    }
    // 2026-11-15 20:37 +03: Task M3.18 (G2), task 596. The vendor's Sender ID row goes with the vendor. Its own
    // try, so a
    // failure above never leaves the row behind.
    try {
        if ($result && fn_allowed_for('MULTIVENDOR')) {
            Tygh::$app['kwtsms.vendor_senders']->clear((int) $company_id);
        }
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('delete_company', $e);
    }
}

/**
 * 2026-10-22 22:23 +03: Task A8 (PRD 5.1, ruling R21), task 596. Hook change_company_status_pre
 * (fn.companies.php:1440): a vendor that
 * moves to a status other than A (disabled, suspended, pending, declined) loses its approved number.
 *
 * @param int    $company_id
 * @param string $status_to
 * @param string $reason      Unused
 * @param string $status_from  Unused
 * @param bool   $skip_query   Unused
 * @param bool   $notify       Unused
 */
function fn_kwtsms_change_company_status_pre(
    $company_id = null,
    $status_to = null,
    $reason = '',
    $status_from = '',
    $skip_query = false,
    $notify = true
) {
    // 2026-10-23 13:17 +03: final review A, Important 3 (I3), task 596. A core that passes fewer arguments (4.20.1 is
    // unverified) must not
    // throw ArgumentCountError into checkout: trailing parameters default, and a missing needed one skips quietly
    // (logged once).
    if (fn_kwtsms_hook_args_missing('change_company_status_pre', func_num_args(), 2)) {
        return;
    }
    try {
        if (!fn_allowed_for('MULTIVENDOR')) {
            return;
        }
        Tygh::$app['kwtsms.vendor_phones']->onCompanyStatusChange((int) $company_id, (string) $status_to);
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('change_company_status_pre', $e);
    }
}

/**
 * 2026-10-23 13:17 +03: final review A, Important 3, task 596. True (and one error-log line per hook and request) when
 * a hook
 * handler was called with fewer arguments than it needs; the handler then returns without doing anything.
 *
 * @param string $hook   Hook name
 * @param int    $given  func_num_args() of the handler
 * @param int    $needed Number of leading arguments the handler cannot work without
 */
function fn_kwtsms_hook_args_missing(string $hook, int $given, int $needed): bool
{
    if ($given >= $needed) {
        return false;
    }
    static $logged = [];
    if (!isset($logged[$hook])) {
        $logged[$hook] = true;
        error_log('kwtsms: hook ' . $hook . ' called with ' . $given . ' of the ' . $needed
            . ' arguments it needs: skipped (core version mismatch?)');
        // 2026-10-29 20:36 +03: Task 596 (4.20.1 hardening item 2). Persistent record for the Settings warning "not
        // enforced on this
        // CS-Cart version": hook => [given, needed, time]. A by-reference flag that did not arrive cannot be set, so
        // the
        // hook cannot refuse; the red Settings note is what tells the admin. Never throws.
        try {
            if (function_exists('fn_get_storage_data') && function_exists('fn_set_storage_data')) {
                $raw = fn_get_storage_data('kwtsms_platform_missing');
                $all = is_string($raw) ? json_decode($raw, true) : [];
                $all = is_array($all) ? $all : [];
                if (!isset($all[$hook])) {
                    $all[$hook] = [$given, $needed, time()];
                    fn_set_storage_data('kwtsms_platform_missing', (string) json_encode($all));
                }
            }
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    return true;
}

/**
 * One line in the PHP error log for a failed order hook: class, message, file:line, no trace.
 * 2026-10-21 01:58 +03: added, Task 1.9, task 596.
 */
function fn_kwtsms_log_hook_error(string $hook, \Throwable $e): void
{
    error_log('kwtsms: hook ' . $hook . ' failed: ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
}

/**
 * 2026-10-23 18:31 +03: M1.1b fix round 1 (I1), task 596. Hook before_dispatch (fn.control.php, before every
 * controller):
 * in the admin area, finishes a 1.2.0 upgrade whose post-upgrade step failed or did not run (stored add-on version
 * behind the files). Re-uploading the package cannot do that (the Upgrade Center refuses it once the files are copied).
 * The handler takes no parameter, so a core that passes fewer or more arguments cannot break it. Never throws.
 * 2026-10-23 19:35 +03: M1.1b fix round 2 (N1), task 596. The hook fires before core's login check, so
 * PostUpgrade::selfHeal() itself refuses everyone but a store administrator and backs off 300 s per session.
 */
function fn_kwtsms_before_dispatch()
{
    // 2026-11-04 22:45 +03: Task M2.2a (P3-50), task 596. A login held by the hook path (recovery key, e-mail OTP,
    // social login...)
    // continues on the code page: the next page view of that area is redirected there. Session only, no query; a
    // request without a pending state (every request while the 2FA switches are off) returns at the first line.
    fn_kwtsms_twofa_pending_redirect();
    if (!defined('AREA') || AREA !== 'A') {
        return;
    }
    try {
        \Tygh\Addons\Kwtsms\Upgrade\PostUpgrade::selfHeal();
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('before_dispatch', $e);
    }
}

/**
 * 2026-11-04 22:45 +03: Task M2.2a (P3-50), task 596. Redirects a page view to the code page while this session holds a
 * pending
 * second step started by a fn_login_user() path. Never throws; never in CONSOLE.
 */
function fn_kwtsms_twofa_pending_redirect()
{
    try {
        if (defined('CONSOLE') || empty(Tygh::$app['session'][\Tygh\Addons\Kwtsms\Auth\PendingLogin::STATE_KEY])) {
            return;
        }
        // 2026-11-05 02:34 +03: Task M2.2a fix 1 (M4), task 596. Core's "successfully logged in" notice of a login the
        // hook held.
        fn_kwtsms_twofa_drop_login_notice();
        $to = Tygh::$app['kwtsms.twofa_hold']->pendingRedirect(
            (string) Registry::get('runtime.controller'),
            (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            defined('AJAX_REQUEST')
        );
        if ($to !== null) {
            fn_redirect(fn_url($to));
        }
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('twofa_pending_redirect', $e);
    }
}

/**
 * 2026-11-05 02:34 +03: Task M2.2a fix 1 (M4), task 596. Drops core's "You have been successfully logged in" notice
 * while this session
 * holds a pending second step that the hook path started (the visitor is not logged in). Nothing else is touched; with
 * no
 * pending state, or a state the password form started, it returns at once. Never throws.
 */
function fn_kwtsms_twofa_drop_login_notice()
{
    try {
        $session = Tygh::$app['session'];
        $state = $session[\Tygh\Addons\Kwtsms\Auth\PendingLogin::STATE_KEY];
        if (empty($state) || !is_array($state) || empty($state['hook'])) {
            return;
        }
        $notices = $session['notifications'];
        if (!is_array($notices) || $notices === []) {
            return;
        }
        $left = \Tygh\Addons\Kwtsms\Auth\LoginHold::withoutLoginNotice($notices, (string) __('successful_login'));
        if (count($left) !== count($notices)) {
            $session['notifications'] = $left;
        }
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('twofa_drop_login_notice', $e);
    }
}

/**
 * 2026-11-05 11:15 +03: Task M2.2b fix 1 (review I1), task 596. Hook settings_update_value_by_id_pre (Tygh\Settings::
 * updateValueById, 10 arguments). Core's add-on settings form (addons.update -> fn_update_addon) and the REST settings
 * entity reach the three hidden 2FA switches with no check; their stored value is kept unless the write came through
 * the add-on's own guarded save (CsCartSettingsBackend::write). Any failure here keeps core's behaviour out of the way:
 * it is logged, never thrown.
 *
 * @param mixed               $settings          Settings instance (unused)
 * @param mixed               $object_id         Unused
 * @param mixed               $value             Unused
 * @param mixed               $company_id        Unused
 * @param mixed               $execute_functions Unused
 * @param array<string,mixed> $data              Row to be written (by reference)
 * @param array<string,mixed> $old_data          Stored object
 */
function fn_kwtsms_settings_update_value_by_id_pre(
    &$settings = null,
    &$object_id = null,
    &$value = null,
    &$company_id = null,
    &$execute_functions = null,
    &$data = [],
    &$old_data = []
) {
    if (fn_kwtsms_hook_args_missing('settings_update_value_by_id_pre', func_num_args(), 7)) {
        return;
    }
    try {
        if (is_array($data) && is_array($old_data)) {
            \Tygh\Addons\Kwtsms\Settings\CsCartSettingsBackend::holdTwoFactorSwitch($data, $old_data);
        }
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('settings_update_value_by_id_pre', $e);
    }
}

/**
 * 2026-11-04 22:45 +03: Task M2.2a (SafatMall B3, P3-50), task 596. Hook login_user_pre (fn.users.php:3550), the second
 * net of the
 * second step: every fn_login_user() caller that is not the password form (recovery key, e-mail one-time password,
 * remember-me cookie, social login, REST token, the code pages of this add-on) passes here. The arguments are by
 * reference: a user id of 0 makes core log nobody in. While the three 2FA switches are off (or KWTSMS_2FA_OFF is
 * set) this reads nothing and changes nothing. A core that passes fewer arguments than 4.21.2 does not disable the
 * hold (the user id is the first argument and still arrives by reference); the shortfall is recorded for the Settings
 * warning. An error while the hold decides leaves the login to core and is logged (the hold itself fails closed for
 * an account it knows needs a code).
 *
 * @param int|string|null      $user_id   By reference
 * @param array<string,mixed>  $udata     Unused
 * @param array<string,mixed>  $auth      Unused
 * @param string               $condition Unused
 */
function fn_kwtsms_login_user_pre(&$user_id = null, &$udata = [], &$auth = [], &$condition = '')
{
    fn_kwtsms_hook_args_missing('login_user_pre', func_num_args(), 4);
    if (func_num_args() < 1 || empty($user_id)) {
        return;
    }
    try {
        $id = (int) $user_id;
        Tygh::$app['kwtsms.twofa_hold']->onLoginUserPre($id);
        if ($id === 0) {
            $user_id = 0;
        }
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('login_user_pre', $e);
        // 2026-11-05 02:15 +03: Task M2.2a fix 1 (I1), task 596. The hold itself never throws once it knows the
        // account; this catch is for a failure before that (the service could not be built). The login is left to
        // core only when the break-glass constant is set or the three stored switches read off; otherwise the
        // account is held. $id above is a copy, so the id is zeroed here, not by the hold.
        if (!fn_kwtsms_twofa_switches_off_raw()) {
            $user_id = 0;
        }
    }
}

/**
 * 2026-11-05 02:15 +03: Task M2.2a fix 1 (I1), task 596. True when KWTSMS_2FA_OFF is set or the three 2FA switches
 * read "off" straight from the settings backend (no service container). Anything unreadable is not off.
 */
function fn_kwtsms_twofa_switches_off_raw(): bool
{
    if (defined('KWTSMS_2FA_OFF') && (bool) constant('KWTSMS_2FA_OFF')) {
        return true;
    }
    try {
        foreach (['twofa_customers', 'twofa_vendors', 'twofa_admins'] as $key) {
            // 2026-11-05 02:35 +03: Task M2.2a fix 1 (I1), task 596. Read through the settings backend the Config uses,
            // not the
            // registry copy, which can be stale within a request.
            $value = (new \Tygh\Addons\Kwtsms\Settings\CsCartSettingsBackend())->read($key);
            // twofa_vendors does not exist outside Multi-Vendor: an absent value there is off.
            if ($value === null || $value === '') {
                if ($key === 'twofa_vendors') {
                    continue;
                }

                return false;
            }
            if ($value !== 'off') {
                return false;
            }
        }
    } catch (\Throwable $e) {
        return false;
    }

    return true;
}

/**
 * 2026-11-04 22:45 +03: Task M2.2a (SafatMall B3), task 596. For the profile page hook templates (storefront
 * hooks/profiles/account_update.post.tpl, backend hooks/profiles/general_content.post.tpl; modifier
 * |fn_kwtsms_twofa_box): what the opt-in box shows for the signed-in account. Never throws; unavailable on any error.
 *
 * @param int|string $user_id The signed-in account (never read from the request)
 *
 * @return array{available:bool,enabled:bool,masked:string,usable_phone:bool,can_disable:bool,type:string}
 */
function fn_kwtsms_twofa_box($user_id)
{
    try {
        return Tygh::$app['kwtsms.twofa_flow']->profileInfo((int) $user_id);
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('twofa_box', $e);

        return ['available' => false, 'enabled' => false, 'masked' => '', 'masked_ltr' => '', 'usable_phone' => false,
            'can_disable' => false, 'type' => ''];
    }
}

/**
 * 2026-11-05 07:34 +03: Task M2.2b (P3-49), task 596. For the backend profile hook template
 * (hooks/profiles/general_content.post.tpl;
 * modifier |fn_kwtsms_twofa_reset_box): whether a root administrator with the gateway privilege sees the "Reset the
 * second
 * step" button on ANOTHER account's profile: the account has an enabled second step and is not the viewer. Never
 * throws.
 *
 * @param int|string          $target_id The profile being viewed
 * @param array<string,mixed> $auth      The viewer's auth array
 *
 * @return array{show:bool}
 */
function fn_kwtsms_twofa_reset_box($target_id, $auth = [])
{
    $none = ['show' => false];
    try {
        // 2026-11-07 19:04 +03: branch review M2 of 1.3.0, task 596. Every 2FA switch off: no query at all (settings
        // only), the button has nothing to reset. The three switches, not policy->anyOn(): with the break-glass
        // constant set a root may still need to reset a row.
        $kwtsms_cfg = Tygh::$app['kwtsms.config'];
        if (
            $kwtsms_cfg->twofaCustomers() === 'off' && $kwtsms_cfg->twofaVendors() === 'off'
            && $kwtsms_cfg->twofaAdmins() === 'off'
        ) {
            return $none;
        }
        $actor = (int) ($auth['user_id'] ?? 0);
        $target = (int) $target_id;
        $allowed = fn_check_current_user_access('manage_kwtsms_gateway') === true;
        if ($actor <= 0 || $target <= 0 || $actor === $target || !$allowed) {
            return $none;
        }
        $root = db_get_row('SELECT user_type, is_root, company_id, status FROM ?:users WHERE user_id = ?i', $actor);
        if (empty($root) || $root['user_type'] !== 'A' || $root['is_root'] !== 'Y' || (int) $root['company_id'] !== 0) {
            return $none;
        }
        $row = Tygh::$app['kwtsms.twofa_store']->row($target);

        return ['show' => $row !== null && (string) $row['enabled'] === 'Y'];
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('twofa_reset_box', $e);

        return $none;
    }
}

/**
 * 2026-11-07 19:05 +03: branch review M2 of 1.3.0, task 596 (moved out of controllers/common/auth.pre.php, the logic
 * is the one of Task M2.3 P3-53 and M2.3-fix item 1). True when a recovery key must be refused: it was handed over by
 * the recovery by code to ANOTHER session. The check runs only while recover_code is ON: with the switch off a request
 * costs no query. Decision: a bound key issued in the last RECOVERY_PASSWORD_TTL (15 minutes) before the switch was
 * turned off then works like an e-mail key (core still demands the secret key); no state is kept to extend the check.
 * Never throws; a service that cannot run refuses a key that carries the binding.
 *
 * @param string $ekey The key of the request
 */
function fn_kwtsms_recover_key_refused(
    #[\SensitiveParameter]
    $ekey
) {
    // 2026-11-07 23:48 +03: the key is marked sensitive like every key parameter (package gate), task 596.
    try {
        if (!Tygh::$app['kwtsms.config']->recoverCode()) {
            return false;
        }
    } catch (\Throwable $e) {
        // The switch cannot be read: do the check (fail closed).
        fn_kwtsms_log_hook_error('recover_key_switch', $e);
    }
    try {
        $usable = Tygh::$app['kwtsms.recover_by_code']->keyUsableInSession(
            (string) $ekey,
            \Tygh\Addons\Kwtsms\Codes\CodeRequest::sessionHash()
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('auth.pre.recover_key', $e);
        // 2026-11-05 20:35 +03: M2.3-fix item 1 (security review Minor), task 596. Was fail open (usable = true). The
        // service could not run: refuse a key whose stored data carries the session binding (direct read, a failed
        // read counts as bound); an e-mail key without a binding still goes to core.
        $usable = !\Tygh\Addons\Kwtsms\Auth\RecoverByCode::keyCarriesBinding((string) $ekey);
    }

    return !$usable;
}

/**
 * 2026-10-24 21:13 +03: Task M1.7b (P3-8, P3-25), task 596. Hook settings_variants_image_verification_use_for
 * (app/schemas/settings/variants.functions.php:183): adds the scenario "SMS code request" (kwtsms_sms_code) to the
 * list of forms Settings > Image verification protects. Install and the 1.2.0 upgrade tick it (P3-36).
 *
 * @param array<string,string>|null $objects Scenario => label (by reference)
 */
function fn_kwtsms_settings_variants_image_verification_use_for(&$objects = null)
{
    if (fn_kwtsms_hook_args_missing('settings_variants_image_verification_use_for', func_num_args(), 1)) {
        return;
    }
    try {
        if (is_array($objects)) {
            $objects['kwtsms_sms_code'] = (string) __('kwtsms.turnstile.use_for_sms_code');
        }
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('settings_variants_image_verification_use_for', $e);
    }
}

/**
 * 2026-10-24 21:13 +03: Task M1.7b (P3-36), task 596. Ticks the core image-verification scenario kwtsms_sms_code in
 * Settings > Image verification (the mode is then "always" by default: no SMS code is sent before the CAPTCHA). Adds
 * the one scenario to the ticked list and leaves every other as it is; returns true when the scenario is ticked
 * afterwards (read back). Called by the install handler and the 1.2.0 post-upgrade script, never by a request.
 * 2026-10-24 23:01 +03: M1.7b fix round 1 (review M1, M2), task 596. The global list is written with company 0 and
 * storefront 0 pinned (like cron_key), and so is the list of every storefront that has its own value.
 */
function fn_kwtsms_tick_sms_code_scenario(): bool
{
    return fn_kwtsms_set_sms_code_scenario(true);
}

/**
 * 2026-10-24 23:01 +03: M1.7b fix round 1 (review M3), task 596. The reverse, for the uninstall handler: the scenario
 * leaves the global list and every storefront list it is in; nothing else changes. True when it is in none afterwards.
 */
function fn_kwtsms_untick_sms_code_scenario(): bool
{
    return fn_kwtsms_set_sms_code_scenario(false);
}

/**
 * 2026-10-24 23:01 +03: M1.7b fix round 1 (review M1, M2, M3), task 596. Puts kwtsms_sms_code in ($on) or out of the
 * Image verification list of the global instance (company 0, storefront 0) and of each storefront (the list it
 * sees, own value or inherited; a storefront whose list is already right is not written, so no override is created).
 *
 * @return bool True when every list reads back as asked
 */
function fn_kwtsms_set_sms_code_scenario(bool $on): bool
{
    // 2026-10-29 19:47 +03: M1.13b fix round 1, task 596. The body moved to Upgrade\SmsCodeScenario so the 1.2.0
    // post-upgrade step (which runs while the OLD func.php is loaded) can call it; this stays a thin wrapper.
    return \Tygh\Addons\Kwtsms\Upgrade\SmsCodeScenario::set($on);
}

/**
 * 2026-10-24 21:13 +03: Task M1.7b (PRD 6.15), task 596. For the storefront templates (modifier
 * |fn_kwtsms_turnstile_widget): the data of the Turnstile widget of one image-verification scenario (site key,
 * Cloudflare action, widget language), or an empty array when Turnstile is not the active antibot driver. Never the
 * secret.
 * Never throws.
 *
 * @param string $option The scenario ($option of common/image_verification.tpl)
 *
 * @return array<string,string>
 */
function fn_kwtsms_turnstile_widget($option = '')
{
    try {
        if (
            !isset(Tygh::$app['antibot'])
            || !(Tygh::$app['antibot']->getDriver() instanceof \Tygh\Addons\Kwtsms\Antibot\TurnstileDriver)
        ) {
            return [];
        }

        return Tygh::$app['kwtsms.turnstile_widget']->data(
            is_string($option) ? $option : '',
            defined('CART_LANGUAGE') ? (string) CART_LANGUAGE : 'en'
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('turnstile_widget', $e);

        return [];
    }
}

/**
 * 2026-10-25 02:10 +03: Task M1.8b (SafatMall B1), task 596. For the storefront template hooks/index/scripts.post.tpl
 * (modifier |fn_kwtsms_code_features_on): true while a storefront SMS code feature (registration or phone change) is
 * switched on, so js/addons/kwtsms/code.js is loaded for the code field and for no other store. Settings only, no
 * gateway state is read. Never throws.
 */
function fn_kwtsms_code_features_on($unused = '')
{
    try {
        if (!isset(Tygh::$app['kwtsms.config'])) {
            return false;
        }
        $config = Tygh::$app['kwtsms.config'];

        // 2026-11-04 18:20 +03: Task M2.0 (P3-59), task 596. The one feature list (every code feature of 1.3.0 too).
        return $config->codeFeaturesOn();
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('code_features_on', $e);

        return false;
    }
}

/**
 * 2026-11-06 07:37 +03: Task M2.5 (SafatMall C2, P3-55), task 596. For the storefront template
 * hooks/index/scripts.post.tpl (modifier |fn_kwtsms_phone_country_data): the JSON the country selector script reads
 * (allowed countries with their localized names, the default calling code, the core phone mode), or '' while the
 * selector is off or the country check (C3) is off. Settings and core country names only; the JSON has tags,
 * ampersands and quotes hex-escaped and the template prints it in an escaped meta content attribute (head_scripts
 * hook; 2026-11-06 09:31 +03: it was a script element, the package gate refuses nofilter). Never throws.
 */
function fn_kwtsms_phone_country_data($unused = '')
{
    try {
        if (!isset(Tygh::$app['kwtsms.config'])) {
            return '';
        }
        $selector = new \Tygh\Addons\Kwtsms\Phones\CountrySelector(Tygh::$app['kwtsms.config']);
        if (!$selector->active()) {
            return '';
        }
        $lang = defined('CART_LANGUAGE') ? (string) CART_LANGUAGE : 'en';
        $json = json_encode(
            $selector->payload($lang, (string) Registry::get('settings.Appearance.phone_validation_mode')),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );

        return is_string($json) ? $json : '';
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('phone_country_data', $e);

        return '';
    }
}

/**
 * 2026-10-28 11:08 +03: Task M1.10a (SafatMall B1, P3-28), task 596. For the storefront template
 * hooks/profiles/account_update.post.tpl
 * (modifier |fn_kwtsms_register_form): what the registration page adds. 'code' = show the SMS code field (register_code
 * is ON); 'phone_input' = the form has no phone field of its own and a phone is needed (the code or the mandatory
 * phone is ON), so the template adds an input named user_data[phone] (core saves it, platform facts); 'phone_name' =
 * the name of the phone input the code field reads. Settings only. Never throws; on an error nothing is added.
 *
 * @return array{code:bool,phone_input:bool,phone_name:string}
 */
function fn_kwtsms_register_form($unused = '')
{
    $none = ['code' => false, 'phone_input' => false, 'phone_name' => 'user_data[phone]'];
    try {
        if (!isset(Tygh::$app['kwtsms.config'])) {
            return $none;
        }
        $config = Tygh::$app['kwtsms.config'];
        $code = $config->registerCode();
        if (!$code && !$config->registerPhoneRequired()) {
            return $none;
        }
        // 2026-10-28 18:52 +03: Task M1.10a fix round 1 (review I5, M1, ruling P3-R12), task 596. The own phone
        // input posts to the store's
        // account phone field (P3-17) and the quick registration form (section C only) is covered.
        $quick = Registry::get('settings.General.quick_registration') === 'Y';
        $phone = \Tygh\Addons\Kwtsms\Phones\AccountPhone::registrationPhone($quick);

        return [
            'code' => $code,
            'phone_input' => $phone['shown'] === '',
            'phone_name' => 'user_data[' . ($phone['shown'] === '' ? $phone['account'] : $phone['shown']) . ']',
        ];
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('register_form', $e);

        return $none;
    }
}

/**
 * 2026-10-28 20:27 +03: Task M1.10b (SafatMall B5, P3-17), task 596. For the storefront template
 * hooks/profiles/account_update.post.tpl (modifier |fn_kwtsms_phone_change_form): what the profile page of a signed-in
 * customer adds. 'show' = the SMS code field of the phone change (phone_change_code is ON and the profile form shows a
 * phone field); 'phone_name' = the name of that phone input. Settings only. Never throws; on an error nothing is added.
 *
 * @return array{show:bool,phone_name:string}
 */
function fn_kwtsms_phone_change_form($unused = '')
{
    $none = ['show' => false, 'phone_name' => 'user_data[phone]'];
    try {
        if (!isset(Tygh::$app['kwtsms.config']) || !Tygh::$app['kwtsms.config']->phoneChangeCode()) {
            return $none;
        }
        // 2026-10-28 22:31 +03: Task M1.10b fix round 1 (review M3), task 596. Only the input that is the account phone
        // (P3-17) is watched; a shown b_phone beside a stored users.phone is not.
        $user_id = (int) (Tygh::$app['session']['auth']['user_id'] ?? 0);
        $stored = $user_id > 0 ? (string) db_get_field('SELECT phone FROM ?:users WHERE user_id = ?i', $user_id) : '';
        $field = \Tygh\Addons\Kwtsms\Phones\AccountPhone::watchedField(
            \Tygh\Addons\Kwtsms\Phones\AccountPhone::formPhoneField('C'),
            $stored
        );

        return $field === '' ? $none : ['show' => true, 'phone_name' => 'user_data[' . $field . ']'];
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('phone_change_form', $e);

        return $none;
    }
}

/**
 * 2026-10-28 18:54 +03: Task M1.10a fix round 1 (review M3, ruling P3-R12), task 596. For
 * hooks/index/scripts.post.tpl (modifier
 * |fn_kwtsms_register_code_on): true while register_code is ON. Settings only. Never throws.
 */
function fn_kwtsms_register_code_on($unused = '')
{
    try {
        return isset(Tygh::$app['kwtsms.config']) && Tygh::$app['kwtsms.config']->registerCode();
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('register_code_on', $e);

        return false;
    }
}

/**
 * 2026-11-04 19:14 +03: Task M2.1 (SafatMall B2), task 596. For hooks/index/login_buttons.post.tpl (modifier
 * |fn_kwtsms_login_code_on): true while login_code is ON (the login form then links to the sign-in by code page).
 * Settings only. Never throws; false on any error.
 */
function fn_kwtsms_login_code_on($unused = '')
{
    try {
        return isset(Tygh::$app['kwtsms.config']) && Tygh::$app['kwtsms.config']->loginCode();
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('login_code_on', $e);

        return false;
    }
}

/**
 * 2026-11-05 16:29 +03: Task M2.3 (SafatMall B4), task 596. For hooks/index/login_buttons.post.tpl (modifier
 * |fn_kwtsms_recover_code_on): true while recover_code is ON (the login form then links to the recovery by mobile
 * number page). Settings only. Never throws; false on any error.
 */
function fn_kwtsms_recover_code_on($unused = '')
{
    try {
        return isset(Tygh::$app['kwtsms.config']) && Tygh::$app['kwtsms.config']->recoverCode();
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('recover_code_on', $e);

        return false;
    }
}

/**
 * 2026-10-24 21:13 +03: Task M1.7b (ruling P3-R7), task 596. Hook init_templater_post (fn.init.php:143): in the
 * storefront,
 * while Turnstile is the active antibot driver and the reCAPTCHA add-on is active (without keys, or its driver would
 * be the active one), core's map of template overrides sends common/image_verification.tpl to the reCAPTCHA add-on's
 * native-image template. The map is swapped, in memory for this request only, for the add-on's template, which renders
 * Turnstile and includes the reCAPTCHA template for any other driver. Nothing is written to the cache. With
 * Turnstile not configured nothing happens. Never throws.
 *
 * @param mixed $view The Smarty view (Tygh\SmartyEngine\Core)
 */
function fn_kwtsms_init_templater_post($view = null)
{
    try {
        if (
            !defined('AREA') || AREA !== 'C' || !isset(Tygh::$app['antibot'])
            || (string) Registry::get('addons.recaptcha.status') !== 'A'
            || !(Tygh::$app['antibot']->getDriver() instanceof \Tygh\Addons\Kwtsms\Antibot\TurnstileDriver)
        ) {
            return;
        }
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('init_templater_post', $e);

        return;
    }
    // 2026-10-24 22:56 +03: Task M1.7b fix round 1 (review I1), task 596. From here on Turnstile is the driver and
    // reCAPTCHA's template would render a native image the driver cannot check: unless the swap is done AND read back,
    // the previous driver is put back for this request (never a widget that does not match the driver).
    $reason = 'swap_failed';
    try {
        if (fn_kwtsms_hook_args_missing('init_templater_post', func_num_args(), 1)) {
            $reason = 'hook_args_missing';
        } elseif (is_object($view) && fn_kwtsms_swap_image_verification($view, 'file_exists')) {
            return;
        }
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('init_templater_post', $e);
        $reason = 'exception';
    }
    fn_kwtsms_antibot_fallback($reason);
}

/**
 * 2026-10-24 22:56 +03: Task M1.7b fix round 1 (review I1), task 596. Swaps core's override map entry (see above) and
 * reads the map back. True when no entry points at the reCAPTCHA add-on's override any more.
 *
 * @param mixed    $view
 * @param callable $file_exists (string $path): bool
 */
function fn_kwtsms_swap_image_verification($view, callable $file_exists): bool
{
    // 2026-10-29 20:35 +03: Task 596 (4.20.1 hardening item 4). A core without this function is a failed swap: the
    // caller puts the
    // previous antibot driver back for the request.
    if (!function_exists('fn_addon_template_overrides')) {
        return false;
    }
    // Builds core's map (or loads it from the cache) and returns the key it lives under, exactly as core does.
    fn_addon_template_overrides('common/image_verification.tpl', $view);
    $key = md5(rtrim(rtrim($view->getTemplateDir(0), '/') . '/', '/'));
    $map = Registry::get($key);
    if (!is_array($map)) {
        return false;
    }
    $swapped = \Tygh\Addons\Kwtsms\Antibot\ImageVerificationTemplate::prefer($map, $file_exists);
    if ($swapped !== $map) {
        Registry::set($key, $swapped, true);
    }
    $after = Registry::get($key);

    return is_array($after) && !\Tygh\Addons\Kwtsms\Antibot\ImageVerificationTemplate::stillTheirs($after);
}

/**
 * 2026-10-24 22:56 +03: Task M1.7b fix round 1 (review I1), task 596. The swap could not be done: the driver that was
 * active before ours (reCAPTCHA's, the native image, or the null driver) is put back for this request, so the form and
 * its check stay a pair; with none known the antibot is switched off for the request. One debug row `platform_missing`
 * and one admin bell notice per day (alert captcha_template). Never throws.
 */
function fn_kwtsms_antibot_fallback(string $reason): void
{
    try {
        $previous = \Tygh\Addons\Kwtsms\Antibot\TurnstileDriver::replaced();
        if ($previous !== null) {
            Tygh::$app['antibot']->setDriver($previous);
        } else {
            Tygh::$app['antibot']->disable();
        }
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('antibot_fallback', $e);
    }
    try {
        Tygh::$app['kwtsms.logs.debug']->log('warning', 'antibot.template_swap', 'platform_missing', [
            'reason' => $reason,
        ]);
        Tygh::$app['kwtsms.alerts']->raise('captcha_template', [], false, 86400);
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('antibot_fallback.note', $e);
    }
}

/**
 * Public send API for other add-ons (PRD 5.2 extension contract, stable from 1.0.0). The text goes through the
 * whole send pipeline with source "api": its own per-number rate bucket, at most 1000 recipients per call, and
 * the calling add-on recorded (event_id "api.<add-on>" unless the caller gives one, and the debug log).
 * 2026-10-21 00:20 +03: added, Task 1.6, task 596.
 *
 * @param array<int,mixed>    $phones  Numbers as typed (at most 1000)
 * @param string              $message The final text; it is cleaned once more and must have no {placeholder}
 * @param array<string,mixed> $context Optional: event_id, receiver, order_id, user_id, lang_code, country_iso,
 *                                     secret_values (masked in every log copy), interactive, audience
 *
 * @return array<string,mixed> SendResult::toArray(): status, accepted, outcomes, recipients, message_ids, msg_id,
 *                             points_charged, balance_after, test, error_code, error_message
 */
function fn_kwtsms_send(array $phones, string $message, array $context = []): array
{
    $caller = 'unknown';
    try {
        $frame = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        if (isset($frame['file']) && preg_match('#/addons/([a-z0-9_]{1,64})/#', (string) $frame['file'], $m) === 1) {
            $caller = $m[1];
        } elseif (isset($context['caller']) && is_string($context['caller'])) {
            $caller = preg_match('/^[a-z0-9_]{1,64}$/D', $context['caller']) === 1 ? $context['caller'] : 'unknown';
        }

        if (count($phones) > SmsService::MAX_API_RECIPIENTS) {
            Tygh::$app['kwtsms.logs.debug']->log('warning', 'sms.api_refused', 'too many recipients', [
                'caller' => $caller,
                'recipients' => count($phones),
                'max' => SmsService::MAX_API_RECIPIENTS,
            ]);

            return SendResult::refused('LOCAL_BAD_INPUT', ApiErrors::describe('LOCAL_BAD_INPUT'))->toArray();
        }

        $context['source'] = 'api';
        $context['caller'] = $caller;
        if (!isset($context['event_id']) || !is_string($context['event_id']) || $context['event_id'] === '') {
            $context['event_id'] = 'api.' . $caller;
        }
        unset($context['outbox_id']);

        return Tygh::$app['kwtsms.sms']->send($phones, $message, $context)->toArray();
    } catch (\Throwable $e) {
        // An SMS failure never breaks the caller (PRD conventions): no trace, class, message and file:line only.
        error_log('kwtsms: fn_kwtsms_send failed for ' . $caller . ': ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . basename($e->getFile()) . ':' . $e->getLine());

        return SendResult::refused('LOCAL_ERROR')->toArray();
    }
}

/**
 * Runs the cron jobs (drain_outbox, sync, prune_logs, health) under the core lock kwtsms.cron (600 s). Called by the
 * controller kwtsms_cron.run, in the CLI and over the URL mode. A failed job sets an error notification, so the CLI
 * exits non-zero; a run that finds the lock held returns locked = true and is not a failure.
 * 2026-10-21 01:13 +03: added, Task 1.7, task 596.
 *
 * @param string[] $jobs Names to run; empty = all of Tygh\Addons\Kwtsms\Cron\CronRunner::JOBS
 *
 * @return array{ok: bool, locked: bool, jobs: array<string,array<string,mixed>>, errors: string[], ms: int}
 */
function fn_kwtsms_cron_run(array $jobs = []): array
{
    try {
        $result = Tygh::$app['kwtsms.cron']->run($jobs);
    } catch (\Throwable $e) {
        error_log('kwtsms: cron run failed: ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        $result = [
            'ok' => false,
            'locked' => false,
            'jobs' => [],
            'errors' => [get_class($e) . ': ' . $e->getMessage()],
            'ms' => 0,
        ];
    }
    if (!$result['ok']) {
        fn_set_notification('E', __('error'), __('kwtsms.cron.failed', [
            '[details]' => fn_html_escape(implode('; ', $result['errors'])),
        ]));
    }

    return $result;
}

/**
 * Why a web request to kwtsms_cron.run must be refused, or null when it may run. EVERY web request needs the cron key,
 * a logged-in administrator included (the permission schema lets administrators reach this controller, and a request
 * from another site must not start a run): URL mode on, a stored key of 32+ characters, the same key in the header
 * X-Kwtsms-Cron-Key or the POST field kwtsms_cron_key, hash_equals(). The command line does not come here.
 * 2026-10-21 01:13 +03: added, Task 1.7, task 596.
 *
 * 2026-10-21 01:58 +03: POST only (PRD 10: no state change on GET). The key is checked first, so a caller without it
 * learns nothing about the method rule; a caller WITH the key who sends another method is told so (405), Task 1.7
 * fix round 1 item 6, task 596.
 *
 * @return string|null url_mode_off | no_stored_key | no_key | bad_key | method (a valid key, a method other than
 *                     POST: answer 405) | error
 */
function fn_kwtsms_cron_denial(): ?string
{
    try {
        $config = Tygh::$app['kwtsms.config'];
        $denied = CronKey::deny(
            $config->cronUrlEnabled(),
            $config->cronKey(),
            CronKey::supplied($_SERVER, $_POST)
        );
        if ($denied !== null) {
            return $denied;
        }

        return (isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '') === 'POST'
            ? null
            : 'method';
    } catch (\Throwable $e) {
        error_log('kwtsms: cron key check failed: ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . basename($e->getFile()) . ':' . $e->getLine());

        return 'error';
    }
}

/**
 * A refused cron request goes to the debug log as a warning, at most once a minute (anyone can knock, and a row per
 * knock would be a way to fill the table). URL mode off is not logged at all. A refusal for the method means the
 * caller HAS the key, so a misconfigured scheduler (GET instead of POST) shows up in the log.
 * 2026-10-21 01:13 +03: added, Task 1.7, task 596.
 */
function fn_kwtsms_cron_log_denial(string $reason): void
{
    if ($reason === 'url_mode_off') {
        return;
    }
    try {
        $store = Tygh::$app['kwtsms.storage'];
        $last = $store->getFresh('kwtsms_cron_denied');
        if ($last !== null && ctype_digit($last) && time() - (int) $last < 60) {
            return;
        }
        $store->set('kwtsms_cron_denied', (string) time());
        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ip = substr((string) preg_replace('/[^0-9a-fA-F:.]/', '', $remote), 0, 45);
        Tygh::$app['kwtsms.logs.debug']->log('warning', 'cron.denied', $reason, ['ip' => $ip]);
    } catch (\Throwable $e) {
        error_log('kwtsms: cron denial log failed: ' . get_class($e) . ': ' . $e->getMessage());
    }
}

/**
 * Answers a web request to kwtsms_cron.run and ends it: the status, and a JSON body that says ok or not and nothing
 * else. 2026-10-21 01:13 +03: added, Task 1.7, task 596. A 405 carries "Allow: POST" (2026-10-21 01:58 +03, fix round
 * 1 item 6).
 *
 * @return void Never returns
 */
function fn_kwtsms_cron_reply(int $status, bool $ok)
{
    if (!headers_sent()) {
        http_response_code($status);
        if ($status === 405) {
            header('Allow: POST');
        }
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo $ok ? '{"ok":true}' : '{"ok":false}';
    exit;
}

/**
 * 2026-10-24 23:52 +03: Task M1.8a (SafatMall E1), task 596. The JSON answer of the storefront controller
 * kwtsms_code: status, no-store headers, the body, exit. A 405 names the one allowed method.
 *
 * @param array<string,mixed> $body
 *
 * @return void Never returns
 */
function fn_kwtsms_code_json(int $status, array $body)
{
    if (!headers_sent()) {
        http_response_code($status);
        if ($status === 405) {
            header('Allow: POST');
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Permission schema condition (schemas/permissions/vendor_ultimate.post.php): true only for a GET kwtSMS page opened
 * by a root administrator (company 0) who has the page privilege while a storefront is selected, and only when the
 * request does not itself select a storefront. The controller then refuses it with the notice "Select All stores to
 * manage kwtSMS" and a redirect to the same page with switch_company_id=0 (RequestGate::switchToAllStores()). Every
 * other request in a storefront context stays refused by core. Never throws.
 * 2026-10-21 17:08 +03: added, final review M2, task 596.
 */
function fn_kwtsms_scope_switch_allowed(): bool
{
    try {
        if (isset($_GET['switch_company_id'])) {
            return false;
        }
        $auth = isset(Tygh::$app['session']['auth']) && is_array(Tygh::$app['session']['auth'])
            ? Tygh::$app['session']['auth'] : [];

        return \Tygh\Addons\Kwtsms\Admin\RequestGate::switchToAllStores(
            isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '',
            \Tygh\Registry::get('runtime.mode'),
            $auth,
            \Tygh\Registry::get('runtime.company_id'),
            static function (string $privilege): bool {
                return fn_check_current_user_access($privilege) === true;
            }
        );
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * 2026-11-06 18:39 +03: Task M2.6 (SafatMall F4, P3-56), task 596.
 * schemas/menu/menu.post.php and menu_vendor.post.php: true when the vendor panel menu shows "Confirm SMS number":
 * Multi-Vendor, the switch vendor_phone_self_verify ON, and the signed-in user is a vendor administrator (user type
 * V, a company, not an "act as user" session). Never throws; any failure means no menu entry.
 */
function fn_kwtsms_vendor_menu_allowed()
{
    try {
        if (
            !function_exists('fn_allowed_for') || !fn_allowed_for('MULTIVENDOR')
            || !Tygh::$app->offsetExists('kwtsms.config') || !Tygh::$app['kwtsms.config']->vendorPhoneSelfVerify()
        ) {
            return false;
        }
        $auth = Tygh::$app['session']['auth'] ?? [];

        return is_array($auth) && (string) ($auth['user_type'] ?? '') === 'V'
            && (int) ($auth['company_id'] ?? 0) > 0 && empty($auth['act_as_user']);
    } catch (\Throwable $e) {
        return false;
    }
}
