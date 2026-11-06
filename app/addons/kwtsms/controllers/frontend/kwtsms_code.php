<?php

/*
 * FILE:      app/addons/kwtsms/controllers/frontend/kwtsms_code.php
 * CREATED:   2026-10-24 23:53 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The storefront controller kwtsms_code, mode send: asks for an SMS code for a phone (purpose register or
 *            phone_change) and answers with the JSON body of Codes\CodeRequest.
 * WHY:       Task M1.8a (SafatMall E1, E2, E3, I3). The endpoint spends credits, so it checks everything it can before
 *            any work: POST only (405 first), our own hash_equals() of the session security hash (403, nothing
 *            counted), the purpose (400), the purpose's feature switch (404), and the core CAPTCHA scenario
 *            kwtsms_sms_code when the admin ticked it (P3-20: no antibot rule, the controller asks itself, because a
 *            rule that fails without terminate lets the controller run). Then CodeRequest::handle() does the rest.
 * USAGE:     POST index.php?dispatch=kwtsms_code.send  security_hash=<session hash>  purpose=register|phone_change
 *            phone=<as typed>  [cf-turnstile-response=<token>]   ->  JSON {"ok":true,...} or {"ok":false,"error":...}
 *            Status: 200 for every answer of CodeRequest (an input error or a wait is not a server error), 400 bad
 *            purpose, 403 bad hash or a phone change without a login, 404 feature off, 405 not POST, 500 internal.
 * DEPENDS:   func.php (fn_kwtsms_code_json), Codes\CodeRequest, Codes\CodeAvailability, Security\ClientIp,
 *            Tygh::$app['kwtsms.config'|'kwtsms.code_request'|'kwtsms.code_availability'|'session'|'antibot'].
 */

use Tygh\Addons\Kwtsms\Antibot\SuspicionGate;
use Tygh\Addons\Kwtsms\Codes\BlindCodeRequest; // 2026-11-04 19:13 +03: Task M2.1, task 596
use Tygh\Addons\Kwtsms\Codes\CodeRequest;
use Tygh\Addons\Kwtsms\Codes\CodeTiming; // 2026-11-04 19:13 +03: Task M2.1, task 596
use Tygh\Addons\Kwtsms\Orders\AfterResponse; // 2026-11-04 19:13 +03: Task M2.1, task 596
use Tygh\Addons\Kwtsms\Security\ClientIp;
use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

/** @var string $mode */

// 2026-11-04 19:13 +03: Task M2.1 (P3-43), task 596. The request start for the padding of the sign-in and recovery
// answers (every answer of those purposes lasts at least CodeTiming::FLOOR_MS when the work runs in the request).
$kwtsms_timing = new CodeTiming();
$kwtsms_timing->start();

// Before any work: a verb other than POST gets 405 and "Allow: POST".
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fn_kwtsms_code_json(405, ['ok' => false]);
}
if ($mode !== 'send') {
    fn_kwtsms_code_json(404, ['ok' => false]);
}

try {
    $kwtsms_app = Tygh::$app;
    $kwtsms_session = $kwtsms_app['session'];

    // PRD 10: our own constant-time check of the session security hash, whatever core's anti_csrf switch says.
    $kwtsms_expected = isset($kwtsms_session['security_hash']) ? (string) $kwtsms_session['security_hash'] : '';
    $kwtsms_given = isset($_POST['security_hash']) && is_string($_POST['security_hash']) ? $_POST['security_hash'] : '';
    if ($kwtsms_expected === '' || !hash_equals($kwtsms_expected, $kwtsms_given)) {
        fn_kwtsms_code_json(403, ['ok' => false]);
    }

    $kwtsms_purpose = isset($_POST['purpose']) && is_string($_POST['purpose']) ? $_POST['purpose'] : '';
    if (!in_array($kwtsms_purpose, CodeRequest::PURPOSES, true)) {
        fn_kwtsms_code_json(400, ['ok' => false]);
    }

    $kwtsms_config = $kwtsms_app['kwtsms.config'];
    $kwtsms_storefront = isset($kwtsms_app['storefront']) ? $kwtsms_app['storefront'] : null;
    $kwtsms_storefront_id = is_object($kwtsms_storefront) && isset($kwtsms_storefront->storefront_id)
        ? (int) $kwtsms_storefront->storefront_id : 0;
    // 2026-11-04 18:20 +03: Task M2.0 (P3-59), task 596. The switch of the purpose (CodeRequest::FEATURE).
    $kwtsms_feature = CodeRequest::featureOn($kwtsms_config, $kwtsms_purpose);
    if (!$kwtsms_feature || $kwtsms_app['kwtsms.code_availability']->state($kwtsms_storefront_id) === 'off') {
        fn_kwtsms_code_json(404, ['ok' => false]);
    }

    $kwtsms_auth = isset($kwtsms_session['auth']) && is_array($kwtsms_session['auth']) ? $kwtsms_session['auth'] : [];
    $kwtsms_user_id = isset($kwtsms_auth['user_id']) ? (int) $kwtsms_auth['user_id'] : 0;
    $kwtsms_lang = defined('CART_LANGUAGE') ? (string) CART_LANGUAGE : 'en';
    if ($kwtsms_purpose === CodeRequest::PURPOSE_PHONE_CHANGE && $kwtsms_user_id <= 0) {
        fn_kwtsms_code_json(403, ['ok' => false, 'error' => 'login']);
    }

    // The admin ticked the core scenario: the CAPTCHA comes before the first SMS (P3-8 "always", P3-20).
    // 2026-10-25 01:22 +03: Task M1.8a fix round 1 (review I1, ruling P3-R10), task 596. Every request is checked by
    // the active driver itself, not through fn_image_verification(): core skips that check for a logged-in customer and
    // after one pass (hide_if_logged, hide_after_validation), which would send SMS with no CAPTCHA. The request counts
    // as checked only when a check really ran and passed; otherwise the suspicion gate still runs in handle(). The
    // driver sets no core notice, and no setting is read or changed.
    $kwtsms_checked = false;
    $kwtsms_scenario = isset($kwtsms_app['antibot'])
        ? SuspicionGate::checkScenario($kwtsms_app['antibot'], $_REQUEST) : null;
    if ($kwtsms_scenario === false) {
        fn_kwtsms_code_json(200, [
            'ok' => false,
            'error' => 'captcha',
            'message' => (string) __('kwtsms.code.captcha', [], $kwtsms_lang),
        ]);
    }
    $kwtsms_checked = $kwtsms_scenario === true;

    $kwtsms_phone = isset($_POST['phone']) && is_string($_POST['phone']) ? $_POST['phone'] : '';

    // 2026-11-06 02:54 +03: Task M2.4 fix 1 (security review C1), task 596. The code of a repay (an order paid again,
    // the page has no cart): the request names the order and the phone is the ORDER's, read here and never taken
    // from the request. The visitor must own the order and the mode must ask for the payment; any other case gets
    // the neutral "unavailable" answer and nothing is sent.
    if ($kwtsms_purpose === CodeRequest::PURPOSE_CHECKOUT && !empty($_POST['order_id'])) {
        $kwtsms_repay_phone = fn_kwtsms_repay_code_phone(
            $_POST['order_id'],
            $_POST['payment_id'] ?? 0,
            $kwtsms_auth
        );
        if ($kwtsms_repay_phone === '') {
            fn_kwtsms_code_json(200, [
                'ok' => false,
                'error' => 'unavailable',
                'message' => (string) __('kwtsms.code.unavailable', [], $kwtsms_lang),
            ]);
        }
        $kwtsms_phone = $kwtsms_repay_phone;
    }

    // 2026-11-04 19:13 +03: Task M2.1 (P3-42, P3-43, P3-45), task 596. The purposes login and recover are answered by
    // the blind request: the body is decided before any account lookup and is the same for every valid phone; the work
    // for a phone that has an eligible account (issue and send) runs after the response (fpm, litespeed) or in the
    // request with every answer padded to 3000 ms (no finish function). Never a bypass.
    if (in_array($kwtsms_purpose, BlindCodeRequest::PURPOSES, true)) {
        $kwtsms_blind = $kwtsms_app['kwtsms.blind_code_request'];
        $kwtsms_body = $kwtsms_blind->handle(
            $kwtsms_purpose,
            $kwtsms_phone,
            $_REQUEST,
            CodeRequest::sessionHash(),
            ClientIp::current(),
            $kwtsms_storefront_id,
            $kwtsms_lang,
            $kwtsms_checked
        );
        $kwtsms_mode = $kwtsms_blind->mode();
        if (AfterResponse::afterResponse($kwtsms_mode)) {
            register_shutdown_function(static function () use ($kwtsms_blind, $kwtsms_mode): void {
                AfterResponse::finish($kwtsms_mode);
                $kwtsms_blind->afterResponse();
            });
        } else {
            $kwtsms_blind->finishInline($kwtsms_timing);
        }
        fn_kwtsms_code_json(200, $kwtsms_body);
    }

    $kwtsms_body = $kwtsms_app['kwtsms.code_request']->handle(
        $kwtsms_purpose,
        $kwtsms_phone,
        $_REQUEST,
        $kwtsms_user_id,
        CodeRequest::sessionHash(),
        ClientIp::current(),
        $kwtsms_storefront_id,
        $kwtsms_lang,
        $kwtsms_checked
    );
    fn_kwtsms_code_json(200, $kwtsms_body);
} catch (\Throwable $e) {
    fn_kwtsms_log_hook_error('kwtsms_code.send', $e);
    fn_kwtsms_code_json(500, ['ok' => false]);
}
