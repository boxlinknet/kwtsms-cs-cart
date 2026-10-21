<?php

/*
 * FILE:      app/addons/kwtsms/schemas/permissions/vendor_ultimate.post.php
 * CREATED:   2026-10-20 19:55 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Ultimate edition: denies the kwtsms and kwtsms_cron controllers to every administrator who works
 *            in a company context, that is to a storefront-bound administrator and also to the root
 *            administrator while a storefront is selected (switch_company_id).
 * WHY:       PRD 7: the add-on is for root administrators only, on All storefronts, and "denied in the
 *            Ultimate storefront context". Core evaluates this schema whenever runtime.company_id is set,
 *            before the privilege map of admin.post.php, so a user group that holds view_kwtsms still cannot
 *            open the pages. 2026-10-20 20:28 +03: the earlier condition (or
 *            fn_check_change_storefront_permission) let a root administrator through with a storefront
 *            selected, because that function only looks at the user, not at the selected company; review
 *            finding, task 596. The entry is a plain denial, like vendor_multivendor.post.php.
 *            2026-10-21 17:08 +03, final review M2, task 596: the kwtsms controller entry gains the condition
 *            fn_kwtsms_scope_switch_allowed (operator or): a GET page of a root administrator (company 0) with the page
 *            privilege reaches the controller while a storefront is selected, and the controller only answers it with
 *            the notice "Select All stores to manage kwtSMS" and a redirect to the page in All stores. Storefront-bound
 *            administrators, POST and every other method stay refused here.
 * USAGE:     Loaded by CS-Cart (fn_get_schema('permissions', 'vendor') in the Ultimate edition).
 * DEPENDS:   Nothing.
 */

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */
$schema['controllers']['kwtsms'] = [
    'permissions' => false,
    // 2026-10-21 17:08 +03: the switch to All stores for a GET page (final review M2), task 596.
    'condition'   => [
        'operator' => 'or',
        'function' => ['fn_kwtsms_scope_switch_allowed'],
    ],
];
$schema['controllers']['kwtsms_cron'] = [
    'permissions' => false,
];

return $schema;
