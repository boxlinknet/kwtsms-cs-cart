<?php

/*
 * FILE:      app/addons/kwtsms/controllers/frontend/kwtsms_phone.php
 * CREATED:   2026-11-16 23:59 +03
 * TASK:      596 (M3.21, G9, P3-79)
 * AUTHOR:    kwtSMS
 * WHAT:      The storefront controller kwtsms_phone: the page where a signed-in customer without a mobile number adds
 *            one with an SMS code (mode add), and the "Later" button (mode later).
 * WHY:       Task M3.21 (SafatMall G9). func.php fn_kwtsms_missing_phone_redirect() sends a customer here once per
 *            session. Everything is decided from the session: the user id is the signed-in one and nothing else (no
 *            user id is ever read from the request), a POST needs the session security hash (our own hash_equals(),
 *            whatever core's anti_csrf switch says), the code is bound to the session and the user id by
 *            OtpService, the number follows the rules of the profile page phone change (C3, C4, the phone index),
 *            and return_url goes through ReturnUrl::safe() (same host or a relative path, no open redirect).
 * USAGE:     GET  dispatch=kwtsms_phone.add            the page (return_url optional)
 *            POST dispatch=kwtsms_phone.add            phone=<as typed> kwtsms_code=<6 digits> security_hash=<hash>
 *            POST dispatch=kwtsms_phone.later          security_hash=<hash>   (no more prompts for 7 days)
 *            The code itself is requested by components/code_field.tpl through kwtsms_code.send (purpose phone_change:
 *            same switch, same per-IP and per-phone buckets, same CAPTCHA rules as the profile page).
 *            Status: 403 bad hash, 405 other verbs (Allow: GET, POST; later: POST); a guest, an admin, a customer
 *            who has a phone or a store with the feature off is sent to the home page.
 * DEPENDS:   func.php (fn_kwtsms_log_hook_error), Profiles\MissingPhonePrompt, Auth\ReturnUrl, Codes\CodeRequest,
 *            Security\ClientIp, Tygh::$app['kwtsms.missing_phone_prompt'|'session'|'view'], the template
 *            addons/kwtsms/views/kwtsms_phone/add.tpl, language variables kwtsms.phone_prompt.* and kwtsms.code.*.
 */

use Tygh\Addons\Kwtsms\Auth\ReturnUrl;
use Tygh\Addons\Kwtsms\Codes\CodeRequest;
use Tygh\Addons\Kwtsms\Security\ClientIp;
use Tygh\Registry;
use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

/** @var string $mode */

$kwtsms_method = $_SERVER['REQUEST_METHOD'] ?? '';
// Before any work: a verb other than GET or POST gets 405 and "Allow: GET, POST" ("Allow: POST" for later).
if (
    ($kwtsms_method !== 'GET' && $kwtsms_method !== 'POST')
    || ($mode === 'later' && $kwtsms_method !== 'POST')
) {
    if (!headers_sent()) {
        http_response_code(405);
        header($mode === 'later' ? 'Allow: POST' : 'Allow: GET, POST');
    }
    exit;
}
if ($mode !== 'add' && $mode !== 'later') {
    return [CONTROLLER_STATUS_NO_PAGE];
}

$kwtsms_app = Tygh::$app;
$kwtsms_session = $kwtsms_app['session'];

if ($kwtsms_method === 'POST') {
    // PRD 10: the session security hash, before anything is counted or read.
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

$kwtsms_prompt = $kwtsms_app['kwtsms.missing_phone_prompt'];
$kwtsms_auth = isset($kwtsms_session['auth']) && is_array($kwtsms_session['auth']) ? $kwtsms_session['auth'] : [];
// The user id comes from the session auth only. A guest, an administrator, a vendor administrator, a store with the
// feature off, or a customer who has a number already has nothing to do here.
if (!$kwtsms_prompt->eligible($kwtsms_auth)) {
    return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
}
$kwtsms_user_id = (int) $kwtsms_auth['user_id'];

$kwtsms_return = ReturnUrl::safe(
    isset($_REQUEST['return_url']) && is_string($_REQUEST['return_url']) ? $_REQUEST['return_url'] : '',
    (string) Registry::get('config.current_host'),
    '',
    defined('HTTPS')
);

try {
    if (!$kwtsms_prompt->accountNeedsPhone($kwtsms_user_id)) {
        return [CONTROLLER_STATUS_REDIRECT, $kwtsms_return !== '' ? $kwtsms_return : 'index.index'];
    }
} catch (\Throwable $e) {
    fn_kwtsms_log_hook_error('kwtsms_phone.needs', $e);

    return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
}

if ($mode === 'later') {
    $kwtsms_prompt->later($kwtsms_user_id);

    unset($kwtsms_session['kwtsms_phone_typed']); // 2026-11-17 01:10 +03: Task M3.21 fix round 1 (M3), task 596

    return [CONTROLLER_STATUS_REDIRECT, $kwtsms_return !== '' ? $kwtsms_return : 'index.index'];
}

if ($kwtsms_method === 'POST') {
    $kwtsms_phone = isset($_POST['phone']) && is_string($_POST['phone']) ? trim($_POST['phone']) : '';
    $kwtsms_code = isset($_POST['kwtsms_code']) && is_string($_POST['kwtsms_code']) ? $_POST['kwtsms_code'] : '';
    $kwtsms_back = 'kwtsms_phone.add' . ($kwtsms_return !== '' ? '?return_url=' . rawurlencode($kwtsms_return) : '');
    try {
        $kwtsms_result = $kwtsms_prompt->save(
            $kwtsms_user_id,
            $kwtsms_phone,
            $kwtsms_code,
            CodeRequest::sessionHash(),
            ClientIp::current()
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('kwtsms_phone.add', $e);
        $kwtsms_result = ['status' => 'error'];
    }
    // The numbers go through fn_html_escape (a notification message is HTML).
    switch ($kwtsms_result['status']) {
        case 'ok':
            unset($kwtsms_session['kwtsms_phone_typed']); // 2026-11-17 01:10 +03: Task M3.21 fix round 1 (M3), task 596
            fn_set_notification('N', __('notice'), __('kwtsms.phone_prompt.saved'));

            return [CONTROLLER_STATUS_REDIRECT, $kwtsms_return !== '' ? $kwtsms_return : 'index.index'];
        case 'off':
            return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
        case 'invalid':
            fn_set_notification('E', __('error'), __('kwtsms.phone_change.invalid'));
            break;
        case 'wrong':
            // 2026-11-17 12:06 +03: tester 4 fix, task 596. "0 tries left" only when tries are really used up; no code
            // requested = say so, expired = say so.
            $kwtsms_reason = (string) ($kwtsms_result['reason'] ?? '');
            if ($kwtsms_reason === 'none') {
                fn_set_notification('E', __('error'), __('kwtsms.phone_prompt.no_code'));
            } elseif ($kwtsms_reason === 'expired') {
                fn_set_notification('E', __('error'), __('kwtsms.code.expired'));
            } else {
                fn_set_notification('E', __('error'), __('kwtsms.code.wrong', [
                    '[n]' => fn_html_escape((string) (int) ($kwtsms_result['attempts_left'] ?? 0)),
                ]));
            }
            break;
        case 'locked':
            fn_set_notification('E', __('error'), __('kwtsms.code.locked', [
                '[minutes]' => fn_html_escape(
                    (string) (int) ceil(max(1, (int) ($kwtsms_result['retry_after'] ?? 0)) / 60)
                ),
            ]));
            break;
        case 'in_use':
            fn_set_notification('E', __('error'), __('kwtsms.code.in_use'));
            break;
        default:
            fn_set_notification('E', __('error'), __('kwtsms.phone_prompt.failed'));
    }
    $kwtsms_session['kwtsms_phone_typed'] = substr($kwtsms_phone, 0, 32);

    return [CONTROLLER_STATUS_REDIRECT, $kwtsms_back];
}

// GET: the page. Never cached (it carries the form and the session hash).
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
}
$kwtsms_typed = '';
if (isset($kwtsms_session['kwtsms_phone_typed'])) {
    $kwtsms_typed = is_string($kwtsms_session['kwtsms_phone_typed']) ? $kwtsms_session['kwtsms_phone_typed'] : '';
    unset($kwtsms_session['kwtsms_phone_typed']);
}
fn_add_breadcrumb(__('kwtsms.phone_prompt.title'));
$kwtsms_app['view']->assign('kwtsms_phone', $kwtsms_typed);
$kwtsms_app['view']->assign('kwtsms_return', $kwtsms_return);
// 2026-11-17 08:22 +03: Task 596 tester fix. Core maps mode "add" to the template update.tpl (fn_dispatch_controller),
// so views/kwtsms_phone/add.tpl was never found and the add page answered 404. Name the template explicitly.
$kwtsms_app['view']->assign('content_tpl', 'addons/kwtsms/views/kwtsms_phone/add.tpl');

return;
