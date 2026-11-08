<?php

/*
 * FILE:      app/addons/kwtsms/schemas/menu/menu_vendor.post.php
 * CREATED:   2026-11-06 18:40 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Adds Settings > "Confirm SMS number" to the vendor panel menu (the menu_vendor schema of the Vendor panel
 *            configurator add-on), pointing to kwtsms_vendor.phone.
 * WHY:       Task M2.6 (SafatMall F4, P3-56). A vendor administrator confirms the company's SMS number with a code. The
 *            entry exists only while vendor_phone_self_verify is ON and only for a signed-in vendor administrator
 *            (fn_kwtsms_vendor_menu_allowed()); menu.post.php carries the same entry for panels that use the core menu.
 * USAGE:     Loaded by CS-Cart when it builds the vendor panel menu (fn_get_schema('menu', 'menu_vendor')).
 * DEPENDS:   fn_kwtsms_vendor_menu_allowed() (func.php), language variable kwtsms.vendorphone.menu.
 */

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */
// 2026-11-08 05:52 +03: Task M2.9, task 596. function_exists first, as in menu.post.php (old func.php during the Upgrade Center request).
if (function_exists('fn_kwtsms_vendor_menu_allowed') && fn_kwtsms_vendor_menu_allowed()) {
    $schema['central']['settings']['items']['kwtsms.vendorphone.menu'] = [
        'href'     => 'kwtsms_vendor.phone',
        'position' => 900,
    ];
}

return $schema;
