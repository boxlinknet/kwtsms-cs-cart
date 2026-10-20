<?php

/*
 * FILE:      app/addons/kwtsms/schemas/permissions/vendor_multivendor.post.php
 * CREATED:   2026-10-20 19:55 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Multi-Vendor edition: denies the kwtsms controller to every vendor administrator.
 * WHY:       PRD 7: the add-on is for root administrators only. The vendor schema is evaluated for any
 *            administrator with a company id, so vendors stay out even when their user group (with the
 *            vendor_privileges add-on) holds view_kwtsms. The core vendor schema already denies unlisted
 *            controllers by default; the explicit entry keeps that true if the default ever changes.
 * USAGE:     Loaded by CS-Cart (fn_get_schema('permissions', 'vendor') in the Multi-Vendor edition).
 * DEPENDS:   Nothing.
 */

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */
$schema['controllers']['kwtsms'] = [
    'permissions' => false,
];

return $schema;
