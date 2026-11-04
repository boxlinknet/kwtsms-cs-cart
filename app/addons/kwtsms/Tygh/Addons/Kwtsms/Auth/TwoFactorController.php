<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Auth/TwoFactorController.php
 * CREATED:   2026-11-04 22:47 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The body of the controller kwtsms_twofa for both areas (the files controllers/frontend/kwtsms_twofa.php
 *            and controllers/backend/kwtsms_twofa.php call run()): verify (GET page, POST code), send (POST new code),
 *            cancel (POST), profile (GET page, POST turn on / off with a code), profile_send (POST code for the
 *            profile).
 * WHY:       Task M2.2a (SafatMall B3). One implementation, so the two areas cannot drift. Order of every request:
 *            verb (405), mode (404), own constant-time check of the session security hash on every POST (403 before
 *            anything is read or counted; the anonymous administrator code page has no core CSRF cover), then the
 *            flow. The account of a profile request is the signed-in one, never a request field; the phone a code goes
 *            to is never a request field.
 * USAGE:     return \Tygh\Addons\Kwtsms\Auth\TwoFactorController::run($mode, AREA, $_REQUEST);   // a controller
 *            return value: [CONTROLLER_STATUS_REDIRECT, $dispatch], [CONTROLLER_STATUS_NO_PAGE] or null (render).
 *            Views: addons/kwtsms/views/kwtsms_twofa/verify.tpl and profile.tpl (variables kwtsms_twofa, kwtsms_area).
 * DEPENDS:   Tygh::$app['kwtsms.twofa_flow'|'kwtsms.twofa_policy'|'session'|'view'], core fn_set_notification(),
 *            fn_add_breadcrumb() (storefront), the language variables kwtsms.twofa.* and kwtsms.code.*.
 */

namespace Tygh\Addons\Kwtsms\Auth;

use Tygh\Tygh;

class TwoFactorController
{
    private const MODES = ['verify', 'send', 'cancel', 'profile', 'profile_send'];

    /**
     * @param array<string,mixed> $request
     *
     * @return array<int,string>|null
     */
    public static function run(string $mode, string $area, array $request): ?array
    {
        // 2026-11-04 22:51 +03: Task M2.2a (P3-52 (4), break-glass first), task 596. The command line reset runs only
        // from the command
        // line of the admin area (CONSOLE); on the web it is a 404 like any unknown mode.
        if ($mode === 'cli_reset') {
            return self::cliReset($area, $request);
        }
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
        $post_only = $mode === 'send' || $mode === 'cancel' || $mode === 'profile_send';
        if ($post_only && $method !== 'POST') {
            return [CONTROLLER_STATUS_NO_PAGE];
        }
        $session = Tygh::$app['session'];
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
            if ($mode === 'profile' || $mode === 'profile_send') {
                return self::profile($mode, $area, $method);
            }

            return self::heldLogin($mode, $area, $method);
        } catch (\Throwable $e) {
            fn_kwtsms_log_hook_error('kwtsms_twofa.' . $mode, $e);
            self::say('E', 'kwtsms.twofa.check_failed');

            $own = $mode === 'profile' || $mode === 'profile_send';

            return [CONTROLLER_STATUS_REDIRECT, $own ? 'kwtsms_twofa.profile' : 'auth.login_form'];
        }
    }

    /**
     * @param array<string,mixed> $request
     *
     * @return array<int,string>|null
     */
    private static function cliReset(string $area, array $request): ?array
    {
        if ($area !== 'A' || PHP_SAPI !== 'cli' || !defined('CONSOLE')) {
            return [CONTROLLER_STATUS_NO_PAGE];
        }
        $cli = new TwoFactorCli(
            Tygh::$app['kwtsms.config'],
            Tygh::$app['kwtsms.twofa_store'],
            Tygh::$app['kwtsms.logs.debug']
        );
        foreach ($cli->run($request) as $line) {
            echo $line . PHP_EOL;
        }

        return [CONTROLLER_STATUS_OK];
    }

    // ------------------------------------------------------------------ the code page of a held sign-in

    /**
     * @return array<int,string>|null
     */
    private static function heldLogin(string $mode, string $area, string $method): ?array
    {
        $session = Tygh::$app['session'];
        $flow = Tygh::$app['kwtsms.twofa_flow'];
        $auth = isset($session['auth']) && is_array($session['auth']) ? $session['auth'] : [];
        if (!empty($auth['user_id'])) {
            // Signed in: nothing to verify. An administrator goes to the dashboard, a customer home.
            return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
        }
        if ($mode === 'cancel') {
            $flow->cancel();

            return [CONTROLLER_STATUS_REDIRECT, 'auth.login_form'];
        }
        $challenge = $flow->challenge();
        if ($challenge === null || $challenge['area'] !== $area) {
            // No pending second step (or one of the other area): the sign-in starts again.
            $flow->cancel();

            return [CONTROLLER_STATUS_REDIRECT, 'auth.login_form'];
        }
        if ($mode === 'send') {
            $body = $flow->resend();
            self::tellSend($body);
            if (($body['error'] ?? '') === 'changed') {
                self::say('E', 'kwtsms.twofa.start_again');

                return [CONTROLLER_STATUS_REDIRECT, 'auth.login_form'];
            }

            return [CONTROLLER_STATUS_REDIRECT, 'kwtsms_twofa.verify'];
        }
        if ($method === 'POST') {
            $typed = isset($_POST['kwtsms_code']) && is_string($_POST['kwtsms_code']) ? $_POST['kwtsms_code'] : '';
            $r = $flow->submit($typed);

            return self::afterSubmit($r, $area);
        }
        if (defined('AREA') && AREA === 'C' && function_exists('fn_add_breadcrumb')) {
            fn_add_breadcrumb(__('kwtsms.twofa.title'));
        }
        $view = Tygh::$app['view'];
        $view->assign('kwtsms_twofa', $challenge);
        $view->assign('kwtsms_area', $area);

        return null;
    }

    /**
     * @param array{status:string,attempts_left:int,retry_after:int,redirect:string} $r
     *
     * @return array<int,string>
     */
    private static function afterSubmit(array $r, string $area): array
    {
        switch ($r['status']) {
            case 'ok':
                if ($area === 'C') {
                    fn_set_notification('N', (string) __('notice'), (string) __('successful_login'));
                }

                return [CONTROLLER_STATUS_REDIRECT, $r['redirect'] !== '' ? $r['redirect'] : 'index.index'];
            case 'wrong':
                fn_set_notification('E', (string) __('error'), __('kwtsms.code.blind_wrong', [
                    '[n]' => fn_html_escape((string) (int) $r['attempts_left']),
                ]));
                break;
            case 'locked':
                fn_set_notification('E', (string) __('error'), __('kwtsms.code.locked', [
                    '[minutes]' => fn_html_escape((string) (int) ceil(max(1, $r['retry_after']) / 60)),
                ]));
                break;
            case 'ip_limited':
                self::say('E', 'kwtsms.code.ip_limited');
                break;
            case 'expired':
                self::say('E', 'kwtsms.twofa.expired');
                break;
            case 'malformed':
                self::say('E', 'kwtsms.twofa.malformed');
                break;
            case 'exhausted':
                self::say('E', 'kwtsms.twofa.exhausted');

                return [CONTROLLER_STATUS_REDIRECT, 'auth.login_form'];
            case 'changed':
            case 'none':
                self::say('E', 'kwtsms.twofa.start_again');

                return [CONTROLLER_STATUS_REDIRECT, 'auth.login_form'];
            default:
                self::say('E', 'kwtsms.twofa.check_failed');
        }

        return [CONTROLLER_STATUS_REDIRECT, 'kwtsms_twofa.verify'];
    }

    // ------------------------------------------------------------------ opt-in and opt-out

    /**
     * @return array<int,string>|null
     */
    private static function profile(string $mode, string $area, string $method): ?array
    {
        $session = Tygh::$app['session'];
        $auth = isset($session['auth']) && is_array($session['auth']) ? $session['auth'] : [];
        $user_id = (int) ($auth['user_id'] ?? 0);
        if ($user_id <= 0 || (string) ($auth['area'] ?? '') !== $area) {
            return [CONTROLLER_STATUS_REDIRECT, 'auth.login_form'];
        }
        $flow = Tygh::$app['kwtsms.twofa_flow'];
        $info = $flow->profileInfo($user_id);
        if (!$info['available']) {
            return [CONTROLLER_STATUS_NO_PAGE];
        }
        $action = isset($_POST['profile_action']) && is_string($_POST['profile_action'])
            ? $_POST['profile_action'] : '';
        if ($mode === 'profile_send') {
            $body = $flow->profileSend($user_id, $action);
            self::tellSend($body);

            return [CONTROLLER_STATUS_REDIRECT, 'kwtsms_twofa.profile'];
        }
        if ($method === 'POST') {
            $typed = isset($_POST['kwtsms_code']) && is_string($_POST['kwtsms_code']) ? $_POST['kwtsms_code'] : '';
            $r = $flow->profileConfirm($user_id, $action, $typed);
            self::afterProfile($r, $action);

            return [CONTROLLER_STATUS_REDIRECT, 'kwtsms_twofa.profile'];
        }
        if ($area === 'C' && function_exists('fn_add_breadcrumb')) {
            fn_add_breadcrumb(__('kwtsms.twofa.profile_title'));
        }
        $view = Tygh::$app['view'];
        $view->assign('kwtsms_twofa', $info);
        $view->assign('kwtsms_area', $area);

        return null;
    }

    /**
     * @param array{status:string,attempts_left:int,retry_after:int} $r
     */
    private static function afterProfile(array $r, string $action): void
    {
        switch ($r['status']) {
            case 'ok':
                fn_set_notification('N', (string) __('notice'), fn_html_escape((string) __(
                    $action === 'enable' ? 'kwtsms.twofa.turned_on' : 'kwtsms.twofa.turned_off'
                )));
                break;
            case 'wrong':
                fn_set_notification('E', (string) __('error'), __('kwtsms.code.blind_wrong', [
                    '[n]' => fn_html_escape((string) (int) $r['attempts_left']),
                ]));
                break;
            case 'locked':
                fn_set_notification('E', (string) __('error'), __('kwtsms.code.locked', [
                    '[minutes]' => fn_html_escape((string) (int) ceil(max(1, $r['retry_after']) / 60)),
                ]));
                break;
            case 'ip_limited':
                self::say('E', 'kwtsms.code.ip_limited');
                break;
            case 'expired':
                self::say('E', 'kwtsms.twofa.expired');
                break;
            case 'malformed':
                self::say('E', 'kwtsms.twofa.malformed');
                break;
            case 'no_phone':
                self::say('E', 'kwtsms.twofa.no_phone');
                break;
            case 'in_use':
                self::say('E', 'kwtsms.twofa.in_use');
                break;
            case 'required':
                self::say('E', 'kwtsms.twofa.required');
                break;
            default:
                self::say('E', 'kwtsms.twofa.check_failed');
        }
    }

    // ------------------------------------------------------------------ small helpers

    /**
     * The notice for the body of a code request (sent, wait, unavailable).
     *
     * @param array<string,mixed> $body
     */
    private static function tellSend(array $body): void
    {
        if (!empty($body['ok'])) {
            fn_set_notification(
                'N',
                (string) __('notice'),
                fn_html_escape((string) ($body['message'] ?? __('kwtsms.twofa.sent')))
            );

            return;
        }
        $error = (string) ($body['error'] ?? '');
        if ($error === 'wait' && isset($body['message'])) {
            fn_set_notification('E', (string) __('error'), fn_html_escape((string) $body['message']));

            return;
        }
        $refusals = ['no_phone' => 'kwtsms.twofa.no_phone', 'in_use' => 'kwtsms.twofa.in_use',
            'required' => 'kwtsms.twofa.required', 'off' => 'kwtsms.twofa.check_failed'];
        self::say('E', $refusals[$error] ?? 'kwtsms.twofa.resend_unavailable');
    }

    private static function say(string $type, string $langvar): void
    {
        $title = (string) __($type === 'E' ? 'error' : 'notice');
        fn_set_notification($type, $title, fn_html_escape((string) __($langvar)));
    }
}
