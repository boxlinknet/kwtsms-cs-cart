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
    'change_order_status_child_order'
);
ServiceProvider::extendNotifications(Tygh::$app);
