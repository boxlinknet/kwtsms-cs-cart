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

// 2026-11-04 22:49 +03: Task M2.2a (SafatMall B3, P3-48), task 596. A vendor administrator signs in with a code
// (verify, send, cancel)
// and turns the code on or off for the own account (profile, profile_send). The controller is closed for every other
// mode of the vendor panel.
$schema['controllers']['kwtsms_twofa'] = [
    'permissions' => false,
    'modes' => [
        'verify' => ['permissions' => true],
        'send' => ['permissions' => true],
        'cancel' => ['permissions' => true],
        'profile' => ['permissions' => true],
        'profile_send' => ['permissions' => true],
    ],
];

// 2026-11-06 18:34 +03: Task M2.6 (SafatMall F4, P3-56), task 596. A vendor administrator confirms the company's SMS
// number: the three modes of kwtsms_vendor are open to the vendor panel. The controller itself answers 404 unless the
// switch vendor_phone_self_verify is ON and the session is a vendor administrator, takes the company from the session
// only and checks the security hash of every POST. The kwtsms controller stays closed to vendors above.
$schema['controllers']['kwtsms_vendor'] = [
    'permissions' => false,
    'modes' => [
        'phone' => ['permissions' => true],
        'send_code' => ['permissions' => true],
        'verify' => ['permissions' => true],
    ],
];

return $schema;
