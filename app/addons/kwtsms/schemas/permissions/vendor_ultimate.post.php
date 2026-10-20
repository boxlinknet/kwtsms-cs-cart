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
 * USAGE:     Loaded by CS-Cart (fn_get_schema('permissions', 'vendor') in the Ultimate edition).
 * DEPENDS:   Nothing.
 */

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */
$schema['controllers']['kwtsms'] = [
    'permissions' => false,
];
$schema['controllers']['kwtsms_cron'] = [
    'permissions' => false,
];

return $schema;
