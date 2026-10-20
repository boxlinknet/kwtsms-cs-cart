<?php

/*
 * FILE:      app/addons/kwtsms/schemas/permissions/vendor_ultimate.post.php
 * CREATED:   2026-10-20 19:55 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Ultimate edition: denies the kwtsms and kwtsms_cron controllers to every administrator who is
 *            bound to a storefront, unless fn_check_change_storefront_permission() allows the user (the
 *            root administrator, who has no company).
 * WHY:       PRD 7: the add-on is for root administrators only. Core evaluates this schema for any
 *            administrator with a company id, before the privilege map of admin.post.php, so a user
 *            group that holds view_kwtsms still cannot open the pages.
 * USAGE:     Loaded by CS-Cart (fn_get_schema('permissions', 'vendor') in the Ultimate edition).
 * DEPENDS:   fn_check_change_storefront_permission() (core).
 */

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */
$schema['controllers']['kwtsms'] = [
    'permissions' => false,
    'condition'   => [
        'operator' => 'or',
        'function' => ['fn_check_change_storefront_permission'],
    ],
];
$schema['controllers']['kwtsms_cron'] = [
    'permissions' => false,
    'condition'   => [
        'operator' => 'or',
        'function' => ['fn_check_change_storefront_permission'],
    ],
];

return $schema;
