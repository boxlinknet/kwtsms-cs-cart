<?php

/*
 * FILE:      app/addons/kwtsms/controllers/common/auth.pre.php
 * CREATED:   2026-11-04 22:47 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Pre-controller of auth (both areas): on POST auth.login, a correct password of an account that needs an
 *            SMS code gets a pending second step and a redirect to the code page instead of core's login.
 * WHY:       Task M2.2a (SafatMall B3, P3-50). Core has no hook between the password check and the login. This runs
 *            before core's auth.php, repeats its first steps with the same functions (Auth\LoginHold::intercept()) and,
 *            for an account that needs a code, returns a redirect with a non-empty URL, which stops the cascade: core's
 *            login never runs, so no auth is in the session before the code. Everything else (a wrong password, a
 *            disabled account, a user without a requirement, the 2FA switches off, CONSOLE) returns nothing and core
 *            runs unchanged, byte for byte.
 * USAGE:     Loaded by CS-Cart before controllers/common/auth.php. AJAX quick_login of the storefront:
 *            force_redirection
 *            and exit, as core does at auth.php:111-118.
 * DEPENDS:   Tygh::$app['kwtsms.twofa_hold'], func.php (fn_kwtsms_log_hook_error), core Tygh::$app['ajax'].
 */

use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

/** @var string $mode */
/** @var array<string,mixed> $auth */

if ($mode !== 'login' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    return;
}

try {
    // No work at all while the 2FA switches are off: intercept() asks the policy first.
    $kwtsms_out = Tygh::$app['kwtsms.twofa_hold']->intercept(
        $_REQUEST,
        isset($auth) && is_array($auth) ? $auth : [],
        AREA
    );
} catch (\Throwable $e) {
    fn_kwtsms_log_hook_error('auth.pre', $e);

    return;
}
if ($kwtsms_out === null) {
    return;
}
// 2026-11-04 23:25 +03: Task M2.2a, task 596. Found by the browser e2e: the real login form posts redirect_url, and core's redirect
// prefers it to the URL a controller returns, so the visitor would land back on the sign-in form instead of the code page.
// Core drops it the same way after a successful login (auth.php:96). Plain HTTP clients do not post it.
unset($_REQUEST['redirect_url'], $_POST['redirect_url']);
if (AREA === 'C' && !empty($_REQUEST['quick_login']) && $kwtsms_out[1] === 'kwtsms_twofa.verify') {
    Tygh::$app['ajax']->assign('force_redirection', fn_url($kwtsms_out[1]));
    Tygh::$app['ajax']->assign('non_ajax_notifications', true);
    exit;
}

return [CONTROLLER_STATUS_REDIRECT, $kwtsms_out[1]];
