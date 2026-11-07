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

// 2026-11-05 16:38 +03: Task M2.3 (P3-53), task 596. A recovery key handed over by the recovery by code is bound to the
// session that proved the phone: core's recovery login (auth.recover_password with an ekey, auth.ekey_login) refuses
// it from any other session with core's own "not valid" text, before core reads or deletes it. A key without a
// binding (the e-mail link, the 1.1.0 SMS link) and an unknown key are left to core exactly as before.
if (
    AREA === 'C'
    && ($mode === 'recover_password' || $mode === 'ekey_login')
    && isset($_REQUEST['ekey']) && is_string($_REQUEST['ekey']) && $_REQUEST['ekey'] !== ''
) {
    // 2026-11-07 19:05 +03: branch review M2 of 1.3.0, task 596. The check moved to fn_kwtsms_recover_key_refused(),
    // which does nothing (no query) while recover_code is off.
    if (fn_kwtsms_recover_key_refused($_REQUEST['ekey'])) {
        fn_set_notification('E', __('error'), __('text_ekey_not_valid'));

        return [CONTROLLER_STATUS_REDIRECT, 'auth.recover_password'];
    }
}

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
    // 2026-11-05 07:27 +03: Task M2.2b (P3-52 (5)), task 596. A last root administrator let in with the password only
    // is sent to the own
    // profile (the enrolment box); core's login then redirects to the return URL. The admin area only.
    try {
        if (AREA !== 'C' && Tygh::$app['kwtsms.twofa_hold']->takeLetIn()) {
            $_REQUEST['return_url'] = 'kwtsms_twofa.profile';
        }
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('auth.pre', $e);
    }

    return;
}
// 2026-11-04 23:25 +03: Task M2.2a, task 596. Found by the browser e2e: the real login form posts redirect_url, and
// core's redirect
// prefers it to the URL a controller returns, so the visitor would land back on the sign-in form instead of the code
// page.
// Core drops it the same way after a successful login (auth.php:96). Plain HTTP clients do not post it.
unset($_REQUEST['redirect_url'], $_POST['redirect_url']);
if (AREA === 'C' && !empty($_REQUEST['quick_login']) && $kwtsms_out[1] === 'kwtsms_twofa.verify') {
    Tygh::$app['ajax']->assign('force_redirection', fn_url($kwtsms_out[1]));
    Tygh::$app['ajax']->assign('non_ajax_notifications', true);
    exit;
}

return [CONTROLLER_STATUS_REDIRECT, $kwtsms_out[1]];
