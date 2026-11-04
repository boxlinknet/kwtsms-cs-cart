<?php

/*
 * FILE:      app/addons/kwtsms/controllers/backend/kwtsms_twofa.php
 * CREATED:   2026-11-04 22:47 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The controller kwtsms_twofa of the admin panel (administrators and vendor administrators): the code page
 *            of a held sign-in and the opt-in page.
 * WHY:       Task M2.2a (SafatMall B3). All logic is in Auth\TwoFactorController (one implementation for both areas).
 * USAGE:     GET  dispatch=kwtsms_twofa.verify      the code page (a pending second step is required)
 *            POST dispatch=kwtsms_twofa.verify      kwtsms_code=<6 digits>   security_hash=<session hash>
 *            POST dispatch=kwtsms_twofa.send        a new code to the 2FA phone of the pending state
 *            POST dispatch=kwtsms_twofa.cancel      drops the pending state
 *            GET  dispatch=kwtsms_twofa.profile     the opt-in page of the signed-in account
 *            POST dispatch=kwtsms_twofa.profile_send | profile   profile_action=enable|disable [kwtsms_code]
 * DEPENDS:   Auth\TwoFactorController, trusted_controllers schema (admin: verify, send, cancel).
 */

use Tygh\Addons\Kwtsms\Auth\TwoFactorController;

defined('BOOTSTRAP') or die('Access denied');

/** @var string $mode */

return TwoFactorController::run($mode, 'A', $_REQUEST) ?? [CONTROLLER_STATUS_OK];
