<?php

/*
 * FILE:      app/addons/kwtsms/init.php
 * CREATED:   2026-10-20 19:54 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Add-on bootstrap, run by CS-Cart on every request while the add-on is active.
 * WHY:       Hook registration and the service provider belong here. Task 1.1 ships the skeleton only,
 *            so nothing is registered yet (no hooks, no services, no side effects).
 *            2026-10-21 00:20 +03: the service provider is registered (ruling R2, Task 1.6, task 596); it only
 *            defines lazy services, so a request that sends no SMS builds nothing. Hooks arrive in Task 1.9.
 *            2026-10-21 01:58 +03: the six order hooks and the notification service extensions, Task 1.9, task 596.
 * USAGE:     Loaded automatically by CS-Cart.
 * DEPENDS:   Tygh\Addons\Kwtsms\ServiceProvider; func.php (fn_kwtsms_<hook> handlers, loaded right after this file).
 */

use Tygh\Addons\Kwtsms\ServiceProvider;
use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

// 2026-10-21 00:20 +03: kwtsms.* services (ruling R2), Task 1.6, task 596.
Tygh::$app->register(new ServiceProvider());

// 2026-10-21 01:58 +03: SMS as a native notification transport (PRD 4.1, 4.3), Task 1.9, task 596. The order
// hooks (handlers fn_kwtsms_<hook> in func.php, which delegate to Orders\OrderHooks) and the guarded extensions of
// event.events_schema (SMS column on every order status event and on kwtsms.order_placed) and
// event.notification_settings (a missing switch row means OFF, except kwtsms.order_placed for C and A).
fn_register_hooks(
    'change_order_status',
    'change_order_status_post',
    'edit_place_order',
    'place_order_manually_post',
    'place_order_post',
    'change_order_status_child_order',
    // 2026-10-22 18:02 +03: Task A5 (R18), task 596. The shipment created in the admin: diagnostics when the Notify
    // box is off.
    'update_shipment_before_send_notification',
    // 2026-10-22 19:09 +03: Task A6 (SafatMall F5 part, P2-7), task 596. A customer registered on the storefront or
    // at checkout: the
    // admin registration alert.
    'update_profile',
    // 2026-10-22 21:27 +03: Task A7 (SafatMall F5 part, P2-10), task 596. A product's stock fell across the
    // low-stock threshold: collected, one SMS per receiver after the order status change.
    'update_product_amount',
    // 2026-10-22 22:23 +03: Task A8 (SafatMall F4, PRD 5.1), task 596. The approved number of a vendor follows its
    // phone, its status and
    // its existence (Multi-Vendor only; the handlers return at once elsewhere).
    'update_company',
    'delete_company',
    'change_company_status_pre',
    // 2026-10-23 18:31 +03: M1.1b fix round 1 (I1), task 596. The retry of a failed 1.2.0 post-upgrade step (admin area
    // only, handler fn_kwtsms_before_dispatch).
    'before_dispatch',
    // 2026-10-24 17:13 +03: Task M1.6 (P3-31), task 596. A deleted account takes its phone index row and code rows
    // with it, while a code feature is ON. 2026-10-24 18:50 +03: M1.6 fix round 1 (M1): post_delete_user, after core's
    // DELETE (was delete_user).
    'post_delete_user',
    // 2026-10-24 18:46 +03: M1.6 fix round 1 (I2, ruling P3-R6), task 596. The checkout profile editor saves a profile
    // without update_profile; the phone index follows (same writer function, same switches).
    'update_user_profile_post'
);
ServiceProvider::extendNotifications(Tygh::$app);

// 2026-10-24 19:42 +03: Task M1.7a (PRD 6.15), task 596. Cloudflare Turnstile as the core antibot provider: when the
// Turnstile switch is ON, both keys are saved and the recaptcha add-on is not active, the driver replaces the null
// driver; otherwise nothing changes. Feature-detected (an older core without the interface keeps the null driver).
if (interface_exists('Tygh\\Web\\Antibot\\IAntibotDriver') && isset(Tygh::$app['antibot'])) {
    try {
        Tygh::$app->extend('antibot', function ($antibot, $app) {
            // A failure here never breaks a storefront page: the null driver stays.
            try {
                $driver = $app['kwtsms.turnstile_driver'];
                if ($driver->isSetUp()) {
                    $antibot->setDriver($driver);
                }
            } catch (\Throwable $e) {
                fn_kwtsms_log_hook_error('antibot.driver', $e);
            }

            return $antibot;
        });
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('antibot.extend', $e);
    }
}
