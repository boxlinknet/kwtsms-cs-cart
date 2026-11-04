<?php

/*
 * FILE:      app/addons/kwtsms/controllers/frontend/kwtsms_auth.php
 * CREATED:   2026-11-04 19:12 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The storefront controller kwtsms_auth, mode login: GET shows the page "Sign in with your mobile number"
 *            (phone field, the SMS code field with purpose login, a Sign in button); POST checks the code and signs the
 *            customer in (Auth\LoginByCode::complete()), then redirects to a local return_url or the home page.
 * WHY:       Task M2.1 (SafatMall B2). Every refusal comes before any work: a verb other than GET or POST answers 405,
 *            the feature OFF answers 404 for both verbs, a POST without our own hash_equals() match of the session
 *            security hash answers 403 with nothing counted (login CSRF), a customer who is signed in goes home. The
 *            code is checked blind (the same answer for a number without an account) and the login regenerates the
 *            session id. return_url is local or it is dropped (Auth\ReturnUrl).
 * USAGE:     GET  index.php?dispatch=kwtsms_auth.login[&return_url=<local>]
 *            POST index.php?dispatch=kwtsms_auth.login  security_hash=<session hash>  phone=<as typed>
 *                 kwtsms_code=<6 digits>  [return_url=<local>]
 *            Status: 200 page, 302 after a sign-in, back to the page after a refused code (with the notice) or for a
 *            customer already signed in, 403 bad hash, 404 feature off, 405 other verbs.
 * DEPENDS:   func.php (fn_kwtsms_log_hook_error), Auth\{LoginByCode,ReturnUrl}, Security\ClientIp, Codes\CodeRequest,
 *            Tygh::$app['kwtsms.config'|'kwtsms.login_by_code'|'session'|'view'], the template
 *            addons/kwtsms/views/kwtsms_auth/login.tpl, language variables kwtsms.login.* and kwtsms.code.*.
 */

use Tygh\Addons\Kwtsms\Auth\ReturnUrl;
use Tygh\Addons\Kwtsms\Codes\CodeRequest;
use Tygh\Addons\Kwtsms\Security\ClientIp;
use Tygh\Registry;
use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

/** @var string $mode */

$kwtsms_method = $_SERVER['REQUEST_METHOD'] ?? '';
// Before any work: a verb other than GET or POST gets 405 and "Allow: GET, POST".
if ($kwtsms_method !== 'GET' && $kwtsms_method !== 'POST') {
    if (!headers_sent()) {
        http_response_code(405);
        header('Allow: GET, POST');
    }
    exit;
}
if ($mode !== 'login') {
    return [CONTROLLER_STATUS_NO_PAGE];
}

$kwtsms_app = Tygh::$app;
$kwtsms_config = $kwtsms_app['kwtsms.config'];
// The feature OFF: 404 for both verbs.
if (!$kwtsms_config->loginCode()) {
    return [CONTROLLER_STATUS_NO_PAGE];
}
$kwtsms_session = $kwtsms_app['session'];
$kwtsms_auth = isset($kwtsms_session['auth']) && is_array($kwtsms_session['auth']) ? $kwtsms_session['auth'] : [];
$kwtsms_host = (string) Registry::get('config.current_host');

if ($kwtsms_method === 'POST') {
    // PRD 10: our own constant-time check of the session security hash, whatever core's anti_csrf switch says. A
    // missing or wrong hash is refused before anything is counted or read.
    $kwtsms_expected = isset($kwtsms_session['security_hash']) ? (string) $kwtsms_session['security_hash'] : '';
    $kwtsms_given = isset($_POST['security_hash']) && is_string($_POST['security_hash']) ? $_POST['security_hash'] : '';
    if ($kwtsms_expected === '' || !hash_equals($kwtsms_expected, $kwtsms_given)) {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo 'Forbidden';
        exit;
    }
}

// A signed-in customer has nothing to do here.
if (!empty($kwtsms_auth['user_id'])) {
    return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
}

$kwtsms_return = ReturnUrl::safe(
    isset($_REQUEST['return_url']) && is_string($_REQUEST['return_url']) ? $_REQUEST['return_url'] : '',
    $kwtsms_host,
    ''
);
$kwtsms_phone = '';

if ($kwtsms_method === 'POST') {
    $kwtsms_phone = isset($_POST['phone']) && is_string($_POST['phone']) ? $_POST['phone'] : '';
    $kwtsms_code = isset($_POST['kwtsms_code']) && is_string($_POST['kwtsms_code']) ? $_POST['kwtsms_code'] : '';
    try {
        $kwtsms_storefront = isset($kwtsms_app['storefront']) ? $kwtsms_app['storefront'] : null;
        $kwtsms_result = $kwtsms_app['kwtsms.login_by_code']->complete(
            $kwtsms_phone,
            $kwtsms_code,
            CodeRequest::sessionHash(),
            ClientIp::current(),
            is_object($kwtsms_storefront) && isset($kwtsms_storefront->storefront_id)
                ? (int) $kwtsms_storefront->storefront_id : 0
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('kwtsms_auth.login', $e);
        $kwtsms_result = ['status' => 'error', 'user_id' => 0, 'attempts_left' => 0, 'retry_after' => 0];
    }

    switch ($kwtsms_result['status']) {
        case 'ok':
            // The login (fn_login_user, regenerate) already ran: the session id is new. No remember-me cookie.
            fn_set_notification('N', __('notice'), __('successful_login'));

            return [CONTROLLER_STATUS_REDIRECT, $kwtsms_return !== '' ? $kwtsms_return : 'index.index'];
        case 'off':
            return [CONTROLLER_STATUS_NO_PAGE];
        // 2026-11-04 19:42 +03: Task M2.1, task 596. The numbers go through fn_html_escape (the release gate on
        // notices: a notification message is HTML); the wait is at least one second, so at least one minute shows.
        case 'wrong':
            fn_set_notification('E', __('error'), __('kwtsms.code.blind_wrong', [
                '[n]' => fn_html_escape((string) (int) $kwtsms_result['attempts_left']),
            ]));
            break;
        case 'locked':
            fn_set_notification('E', __('error'), __('kwtsms.code.locked', [
                '[minutes]' => fn_html_escape((string) (int) ceil(max(1, $kwtsms_result['retry_after']) / 60)),
            ]));
            break;
        case 'ip_limited':
            fn_set_notification('E', __('error'), __('kwtsms.code.ip_limited'));
            break;
        case 'malformed':
            fn_set_notification('E', __('error'), __('kwtsms.login.enter_both'));
            break;
        default:
            fn_set_notification('E', __('error'), __('kwtsms.login.failed'));
    }

    // 2026-11-04 19:24 +03: Task M2.1, task 596. A POST that does not sign in goes back to the page (core renders no
    // template for a POST that does not redirect: 404). The phone the customer typed travels in the session once, the
    // notice above in the session notices; the return address stays in the link.
    $kwtsms_session['kwtsms_login_phone'] = $kwtsms_phone;

    return [
        CONTROLLER_STATUS_REDIRECT,
        'kwtsms_auth.login' . ($kwtsms_return !== '' ? '?return_url=' . rawurlencode($kwtsms_return) : ''),
    ];
}

// The phone of a refused attempt is shown once.
if (isset($kwtsms_session['kwtsms_login_phone'])) {
    $kwtsms_phone = is_string($kwtsms_session['kwtsms_login_phone']) ? $kwtsms_session['kwtsms_login_phone'] : '';
    unset($kwtsms_session['kwtsms_login_phone']);
}

fn_add_breadcrumb(__('kwtsms.login.title'));
$kwtsms_view = $kwtsms_app['view'];
$kwtsms_view->assign('kwtsms_return_url', $kwtsms_return);
$kwtsms_view->assign('kwtsms_phone', $kwtsms_phone);
