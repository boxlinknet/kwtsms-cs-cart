<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Vendors/VendorPhoneController.php
 * CREATED:   2026-11-06 18:33 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The body of the vendor-panel controller kwtsms_vendor: phone (GET page), send_code (POST: a code to the
 *            company phone), verify (POST: the typed code confirms the number).
 * WHY:       Task M2.6 (SafatMall F4, P3-56). Order of every request: verb (405), mode (404), the feature
 *            (Multi-Vendor, switch ON, a signed-in vendor administrator, not an "act as user" session: 404), the verb
 *            of the mode (404),
 *            the own constant-time check of the session security hash on every POST (403 before anything is read or
 *            counted), then the service. The COMPANY ID COMES FROM THE SESSION AUTH ONLY (auth.company_id of a user of
 *            type V): this class never reads a company id, a phone or a user id from the request.
 * USAGE:     return \Tygh\Addons\Kwtsms\Vendors\VendorPhoneController::run($mode, $_REQUEST);   // a controller return
 *            value: [CONTROLLER_STATUS_REDIRECT, $dispatch], [CONTROLLER_STATUS_NO_PAGE] or null (render).
 *            View: addons/kwtsms/views/kwtsms_vendor/phone.tpl (variables kwtsms_vendor_phone, kwtsms_vendor_company).
 * DEPENDS:   Tygh::$app['kwtsms.vendor_phone_code'|'kwtsms.config'|'session'|'view'], core fn_set_notification(),
 *            fn_allowed_for(), Security\ClientIp, Codes\{CodeRequest,OtpResult}, language variables
 *            kwtsms.vendorphone.* and kwtsms.code.*.
 */

namespace Tygh\Addons\Kwtsms\Vendors;

use Tygh\Addons\Kwtsms\Codes\CodeRequest;
use Tygh\Addons\Kwtsms\Codes\OtpResult;
use Tygh\Addons\Kwtsms\Security\ClientIp;
use Tygh\Tygh;

class VendorPhoneController
{
    private const MODES = ['phone', 'send_code', 'verify'];

    /** The modes that only answer a POST. */
    private const POST_ONLY = ['send_code', 'verify'];

    /**
     * @param array<string,mixed> $request not read for the company, the phone or the user (kept for symmetry)
     *
     * @return array<int,string>|null
     */
    public static function run(string $mode, array $request): ?array
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if ($method !== 'GET' && $method !== 'POST') {
            if (!headers_sent()) {
                http_response_code(405);
                header('Allow: GET, POST');
            }
            exit;
        }
        if (!in_array($mode, self::MODES, true)) {
            return [CONTROLLER_STATUS_NO_PAGE];
        }
        $session = Tygh::$app['session'];
        $vendor = self::vendor($session);
        if ($vendor === null) {
            return [CONTROLLER_STATUS_NO_PAGE];
        }
        $post_only = in_array($mode, self::POST_ONLY, true);
        if (($post_only && $method !== 'POST') || (!$post_only && $method !== 'GET')) {
            return [CONTROLLER_STATUS_NO_PAGE];
        }
        if ($method === 'POST') {
            // PRD 10: our own constant-time check, before anything is read or counted.
            $expected = isset($session['security_hash']) ? (string) $session['security_hash'] : '';
            $given = isset($_POST['security_hash']) && is_string($_POST['security_hash'])
                ? $_POST['security_hash'] : '';
            if ($expected === '' || !hash_equals($expected, $given)) {
                if (!headers_sent()) {
                    http_response_code(403);
                    header('Content-Type: text/plain; charset=utf-8');
                    header('Cache-Control: no-store');
                }
                echo 'Forbidden';
                exit;
            }
        }
        try {
            $service = Tygh::$app['kwtsms.vendor_phone_code'];
            if ($mode === 'send_code') {
                return self::send($service, $vendor);
            }
            if ($mode === 'verify') {
                return self::verify($service, $vendor);
            }
            $view = Tygh::$app['view'];
            $view->assign('kwtsms_vendor_phone', $service->pageState($vendor['company_id']));

            return null;
        } catch (\Throwable $e) {
            fn_kwtsms_log_hook_error('kwtsms_vendor.' . $mode, $e);
            self::say('E', 'kwtsms.twofa.check_failed');

            return [CONTROLLER_STATUS_REDIRECT, 'kwtsms_vendor.phone'];
        }
    }

    /**
     * The signed-in vendor administrator of the session, or null (also while the feature is off, outside
     * Multi-Vendor, for a root administrator, for an anonymous request and for an "act as user" session).
     *
     * @param mixed $session
     *
     * @return array{user_id:int,company_id:int}|null
     */
    private static function vendor($session): ?array
    {
        if (!function_exists('fn_allowed_for') || !fn_allowed_for('MULTIVENDOR')) {
            return null;
        }
        if (!Tygh::$app['kwtsms.config']->vendorPhoneSelfVerify()) {
            return null;
        }
        $auth = isset($session['auth']) && is_array($session['auth']) ? $session['auth'] : [];
        $user_id = (int) ($auth['user_id'] ?? 0);
        $company_id = (int) ($auth['company_id'] ?? 0);
        if (
            $user_id <= 0 || $company_id <= 0 || (string) ($auth['user_type'] ?? '') !== 'V'
            || (string) ($auth['area'] ?? '') !== 'A' || !empty($auth['act_as_user'])
        ) {
            return null;
        }

        return ['user_id' => $user_id, 'company_id' => $company_id];
    }

    /**
     * @param array{user_id:int,company_id:int} $vendor
     *
     * @return array<int,string>
     */
    private static function send(VendorPhoneCode $service, array $vendor): array
    {
        $body = $service->request(
            $vendor['user_id'],
            $vendor['company_id'],
            CodeRequest::sessionHash(),
            ClientIp::current(),
            defined('DESCR_SL') ? (string) DESCR_SL : (defined('CART_LANGUAGE') ? (string) CART_LANGUAGE : 'en')
        );
        if (!empty($body['ok'])) {
            fn_set_notification('N', (string) __('notice'), fn_html_escape((string) ($body['message'] ?? '')));

            return [CONTROLLER_STATUS_REDIRECT, 'kwtsms_vendor.phone'];
        }
        $error = (string) ($body['error'] ?? '');
        if ($error === 'wait' && isset($body['message'])) {
            fn_set_notification('E', (string) __('error'), fn_html_escape((string) $body['message']));

            return [CONTROLLER_STATUS_REDIRECT, 'kwtsms_vendor.phone'];
        }
        $texts = [
            'no_phone' => 'kwtsms.vendorphone.no_phone', 'invalid' => 'kwtsms.vendorphone.invalid',
            'approved' => 'kwtsms.vendorphone.already', 'not_active' => 'kwtsms.vendorphone.not_active',
            'busy' => 'kwtsms.code.unavailable',
        ];
        self::say('E', $texts[$error] ?? 'kwtsms.code.unavailable');

        return [CONTROLLER_STATUS_REDIRECT, 'kwtsms_vendor.phone'];
    }

    /**
     * @param array{user_id:int,company_id:int} $vendor
     *
     * @return array<int,string>
     */
    private static function verify(VendorPhoneCode $service, array $vendor): array
    {
        $typed = isset($_POST['kwtsms_code']) && is_string($_POST['kwtsms_code']) ? $_POST['kwtsms_code'] : '';
        $r = $service->verify(
            $vendor['user_id'],
            $vendor['company_id'],
            $typed,
            CodeRequest::sessionHash(),
            ClientIp::current()
        );
        switch ($r->status) {
            case OtpResult::OK:
                self::say('N', 'kwtsms.vendorphone.done');
                break;
            case OtpResult::WRONG:
                fn_set_notification('E', (string) __('error'), __('kwtsms.code.blind_wrong', [
                    '[n]' => fn_html_escape((string) (int) $r->attempts_left),
                ]));
                break;
            case OtpResult::LOCKED:
                fn_set_notification('E', (string) __('error'), __('kwtsms.code.locked', [
                    '[minutes]' => fn_html_escape((string) (int) ceil(max(1, $r->retry_after) / 60)),
                ]));
                break;
            case OtpResult::IP_LIMITED:
                self::say('E', 'kwtsms.code.ip_limited');
                break;
            case OtpResult::MALFORMED:
                self::say('E', 'kwtsms.twofa.malformed');
                break;
            case OtpResult::EXPIRED:
            case OtpResult::NONE:
                self::say('E', 'kwtsms.twofa.expired');
                break;
            default:
                self::say('E', 'kwtsms.twofa.check_failed');
        }

        return [CONTROLLER_STATUS_REDIRECT, 'kwtsms_vendor.phone'];
    }

    private static function say(string $type, string $langvar): void
    {
        $title = (string) __($type === 'E' ? 'error' : 'notice');
        fn_set_notification($type, $title, fn_html_escape((string) __($langvar)));
    }
}
