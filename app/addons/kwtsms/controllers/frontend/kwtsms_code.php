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

use Tygh\Addons\Kwtsms\Codes\CodeRequest;
use Tygh\Addons\Kwtsms\Security\ClientIp;
use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

/** @var string $mode */

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
    $kwtsms_feature = $kwtsms_purpose === CodeRequest::PURPOSE_REGISTER
        ? $kwtsms_config->registerCode() : $kwtsms_config->phoneChangeCode();
    if (!$kwtsms_feature || $kwtsms_app['kwtsms.code_availability']->state($kwtsms_storefront_id) === 'off') {
        fn_kwtsms_code_json(404, ['ok' => false]);
    }

    $kwtsms_auth = isset($kwtsms_session['auth']) && is_array($kwtsms_session['auth']) ? $kwtsms_session['auth'] : [];
    $kwtsms_user_id = isset($kwtsms_auth['user_id']) ? (int) $kwtsms_auth['user_id'] : 0;
    $kwtsms_lang = defined('CART_LANGUAGE') ? (string) CART_LANGUAGE : 'en';
    if ($kwtsms_purpose === CodeRequest::PURPOSE_PHONE_CHANGE && $kwtsms_user_id <= 0) {
        fn_kwtsms_code_json(403, ['ok' => false, 'error' => 'login']);
    }

    // The admin ticked the core scenario: the CAPTCHA comes before the first SMS (P3-8 "always", P3-20). The notice
    // core sets on a failed check belongs to a page, not to this answer: the notifications are put back as they were.
    $kwtsms_checked = false;
    if (isset($kwtsms_app['antibot']) && $kwtsms_app['antibot']->isValidationRequiredForScenario('kwtsms_sms_code')) {
        $kwtsms_notes = isset($kwtsms_session['notifications']) ? $kwtsms_session['notifications'] : null;
        $kwtsms_passed = fn_image_verification('kwtsms_sms_code', $_REQUEST);
        if ($kwtsms_notes !== null) {
            $kwtsms_session['notifications'] = $kwtsms_notes;
        } else {
            unset($kwtsms_session['notifications']);
        }
        if (!$kwtsms_passed) {
            fn_kwtsms_code_json(200, [
                'ok' => false,
                'error' => 'captcha',
                'message' => (string) __('kwtsms.code.captcha', [], $kwtsms_lang),
            ]);
        }
        $kwtsms_checked = true;
    }

    $kwtsms_phone = isset($_POST['phone']) && is_string($_POST['phone']) ? $_POST['phone'] : '';
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
