<?php

/*
 * FILE:      app/addons/kwtsms/controllers/frontend/commerceml.pre.php
 * CREATED:   2026-11-05 02:26 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Pre-controller of the commerceml add-on's frontend controller: an HTTP Basic request whose password is right
 *            for an account that needs an SMS code (2FA) is answered 401 with no body and the commerceml controller never
 *            runs.
 * WHY:       Task M2.2a fix 1 (review I2, SafatMall B3). commerceml signs an administrator or vendor in with e-mail and
 *            password over HTTP Basic and fn_fill_auth() (its controllers/frontend/commerceml.php:60 and
 *            src/Commands/AuthCommandHandler.php:76), never through fn_login_user(), so neither the login_user_pre hook nor
 *            the auth.login pre-controller sees it. Core runs every active add-on's controllers/<area>/<controller>.pre.php
 *            before the controller itself (app/functions/fn.control.php, fn_init_addon_controllers(), GET_PRE_CONTROLLERS),
 *            so this file exists only to take part when commerceml is installed. Nothing happens while the 2FA switches
 *            are off or KWTSMS_2FA_OFF is set. The REST API (e-mail plus API key, a separate secret) is not held.
 * USAGE:     Loaded by CS-Cart before controllers/frontend/commerceml.php; no call from our code.
 * DEPENDS:   Tygh::$app['kwtsms.twofa_hold'] (Auth\LoginHold::refusesBasicAuth()), func.php (fn_kwtsms_log_hook_error).
 */

use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

if (empty($_SERVER['PHP_AUTH_USER'])) {
    return;
}

try {
    $kwtsms_refuse = Tygh::$app['kwtsms.twofa_hold']->refusesBasicAuth(
        (string) $_SERVER['PHP_AUTH_USER'],
        isset($_SERVER['PHP_AUTH_PW']) ? (string) $_SERVER['PHP_AUTH_PW'] : ''
    );
} catch (\Throwable $e) {
    fn_kwtsms_log_hook_error('commerceml.pre', $e);

    return;
}
if ($kwtsms_refuse) {
    // 401 with no body and no hint which rule refused it.
    header('HTTP/1.0 401 Unauthorized');
    exit;
}
