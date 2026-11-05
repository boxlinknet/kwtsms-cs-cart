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
 *
 * 2026-11-05 07:29 +03: Task M2.2b (P3-52 (3), P3-49, (4)), task 596. Four more modes, none for the storefront (404 in
 * area C):
 *            backup             GET the code page for a backup code, POST kwtsms_code=<backup code> (anonymous, a
 * pending
 *                               second step is required; counted in the same wrong-attempt buckets as an SMS code)
 *            backup_regenerate  POST kwtsms_code=<SMS code> (signed in): a new set of ten backup codes, shown once
 *            reset              POST user_id=<target> (a root administrator with manage_kwtsms_gateway, never the own
 *                               account): clears the target's second step and backup codes
 *            cli_reset          console only (unchanged)
 *            A page that shows backup codes is sent with Cache-Control: no-store and is never a redirect target.
 */

namespace Tygh\Addons\Kwtsms\Auth;

use Tygh\Tygh;

class TwoFactorController
{
    // 2026-11-05 07:29 +03: Task M2.2b, task 596. backup, backup_regenerate and reset added.
    private const MODES = [
        'verify', 'send', 'cancel', 'profile', 'profile_send', 'backup', 'backup_regenerate', 'reset',
    ];

    /** 2026-11-05 07:29 +03: Task M2.2b, task 596. Modes the storefront does not have (customers have no backup codes). */
    private const STAFF_ONLY = ['backup', 'backup_regenerate', 'reset'];

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
        if ($area === 'C' && in_array($mode, self::STAFF_ONLY, true)) {
            return [CONTROLLER_STATUS_NO_PAGE];
        }
        $post_only = in_array($mode, ['send', 'cancel', 'profile_send', 'backup_regenerate', 'reset'], true);
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
            if ($mode === 'reset') {
                return self::reset($area);
            }
            if ($mode === 'profile' || $mode === 'profile_send' || $mode === 'backup_regenerate') {
                return self::profile($mode, $area, $method);
            }

            return self::heldLogin($mode, $area, $method);
        } catch (\Throwable $e) {
            fn_kwtsms_log_hook_error('kwtsms_twofa.' . $mode, $e);
            self::say('E', 'kwtsms.twofa.check_failed');

            $own = $mode === 'profile' || $mode === 'profile_send' || $mode === 'backup_regenerate';

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
            Tygh::$app['kwtsms.logs.debug'],
            null,
            // 2026-11-05 11:27 +03: Task M2.2b fix 1 (review M3), task 596. The console reset raises twofa_reset like the
            // web reset (bell), with an e-mail too: nobody is signed in to explain who did it.
            static function (int $cleared): void {
                Tygh::$app['kwtsms.alerts']->raise('twofa_reset', ['[user]' => (string) $cleared], true, 0, (string) $cleared);
            }
        ); // 2026-11-05 07:29 +03: Task M2.2b, task 596. The CLI's account lookup is its own (the users table).
        foreach ($cli->run($request) as $line) {
            echo $line . PHP_EOL;
        }
        // 2026-11-05 07:29 +03: Task M2.2b, task 596. A usage or error line is a failed command: the exit status says
        // so.
        if ($cli->status() !== 0) {
            exit($cli->status());
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
            // 2026-11-05 07:29 +03: Task M2.2b, task 596. The backup-code page posts to the mode backup.
            $r = $mode === 'backup' ? $flow->submitBackup($typed) : $flow->submit($typed);

            return self::afterSubmit($r, $area, $mode);
        }
        if (defined('AREA') && AREA === 'C' && function_exists('fn_add_breadcrumb')) {
            fn_add_breadcrumb(__('kwtsms.twofa.title'));
        }
        $view = Tygh::$app['view'];
        $view->assign('kwtsms_twofa', $challenge);
        $view->assign('kwtsms_area', $area);
        if ($mode === 'backup') {
            // 2026-11-05 07:29 +03: Task M2.2b, task 596. The same page shell asks for a backup code.
            $view->assign('kwtsms_backup_mode', true);
            $view->assign('content_tpl', 'addons/kwtsms/views/kwtsms_twofa/verify.tpl');
        }

        return null;
    }

    /**
     * @param array<string,mixed> $r
     *
     * @return array<int,string>|null
     */
    private static function afterSubmit(array $r, string $area, string $mode = 'verify'): ?array
    {
        switch ($r['status']) {
            case 'ok':
                if ($area === 'C') {
                    fn_set_notification('N', (string) __('notice'), (string) __('successful_login'));
                }
                // 2026-11-05 07:29 +03: Task M2.2b, task 596. A first enrolment at sign-in shows the backup codes once,
                // in this very
                // response (nothing is stored or put in the session), and the way on is a link.
                if (!empty($r['backup_codes']) && is_array($r['backup_codes'])) {
                    self::showBackupCodes($r['backup_codes'], true);

                    return null;
                }
                if (isset($r['backup_left']) && (int) $r['backup_left'] <= 3) {
                    fn_set_notification('W', (string) __('warning'), __('kwtsms.twofa.backup_low', [
                        '[n]' => fn_html_escape((string) (int) $r['backup_left']),
                    ]));
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
            case 'no_backup':
                self::say('E', 'kwtsms.twofa.backup_none');

                return [CONTROLLER_STATUS_REDIRECT, 'kwtsms_twofa.verify'];
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

        return [CONTROLLER_STATUS_REDIRECT, $mode === 'backup' ? 'kwtsms_twofa.backup' : 'kwtsms_twofa.verify'];
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
        if ($mode === 'backup_regenerate') {
            // 2026-11-05 07:29 +03: Task M2.2b, task 596. New backup codes: the action is fixed here, never read from
            // the request.
            $action = 'backup';
        }
        if ($mode === 'profile_send') {
            $body = $flow->profileSend($user_id, $action);
            self::tellSend($body);

            return [CONTROLLER_STATUS_REDIRECT, 'kwtsms_twofa.profile'];
        }
        if ($method === 'POST') {
            $typed = isset($_POST['kwtsms_code']) && is_string($_POST['kwtsms_code']) ? $_POST['kwtsms_code'] : '';
            $r = $flow->profileConfirm($user_id, $action, $typed);
            self::afterProfile($r, $action);
            if ($r['status'] === 'ok' && !empty($r['backup_codes']) && is_array($r['backup_codes'])) {
                // Shown once, in this response; a reload posts the used-up code again and shows nothing.
                self::showBackupCodes($r['backup_codes'], false);

                return null;
            }

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
     * @param array<string,mixed> $r
     */
    private static function afterProfile(array $r, string $action): void
    {
        switch ($r['status']) {
            case 'ok':
                $notice = $action === 'enable' ? 'kwtsms.twofa.turned_on'
                    : ($action === 'backup' ? 'kwtsms.twofa.backup_new' : 'kwtsms.twofa.turned_off');
                fn_set_notification('N', (string) __('notice'), fn_html_escape((string) __($notice)));
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

    // ------------------------------------------------------------------ backup codes and the reset of another account

    /**
     * 2026-11-05 07:29 +03: Task M2.2b, task 596. Shows the plain codes in this response only: no-store headers, a page
     * of its own.
     *
     * @param array<int,string> $codes
     */
    private static function showBackupCodes(array $codes, bool $after_login): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
        }
        $view = Tygh::$app['view'];
        $view->assign('kwtsms_backup_codes', array_values(array_map('strval', $codes)));
        $view->assign('kwtsms_backup_after_login', $after_login);
        $view->assign('content_tpl', 'addons/kwtsms/views/kwtsms_twofa/backup_codes.tpl');
    }

    /**
     * 2026-11-05 07:29 +03: Task M2.2b (P3-49), task 596. POST kwtsms_twofa.reset: a root administrator with
     * manage_kwtsms_gateway clears
     * ANOTHER account's second step. The actor is the signed-in account (session), the target the request's user_id.
     *
     * @return array<int,string>
     */
    private static function reset(string $area): array
    {
        $session = Tygh::$app['session'];
        $auth = isset($session['auth']) && is_array($session['auth']) ? $session['auth'] : [];
        $actor = (int) ($auth['user_id'] ?? 0);
        $raw_target = isset($_REQUEST['user_id']) && is_scalar($_REQUEST['user_id'])
            ? (string) $_REQUEST['user_id'] : '';
        $target = ctype_digit($raw_target) ? (int) $raw_target : 0;
        $back = $target > 0 ? 'profiles.update?user_id=' . $target : 'index.index';
        if ($actor <= 0 || (string) ($auth['area'] ?? '') !== $area) {
            return [CONTROLLER_STATUS_REDIRECT, 'auth.login_form'];
        }
        if (fn_check_current_user_access('manage_kwtsms_gateway') !== true) {
            return [CONTROLLER_STATUS_DENIED];
        }
        $status = Tygh::$app['kwtsms.twofa_flow']->reset($actor, $target);
        if ($status === 'ok') {
            self::say('N', 'kwtsms.twofa.reset_done');
            try {
                Tygh::$app['kwtsms.alerts']->raise(
                    'twofa_reset',
                    ['[user]' => (string) $target],
                    false,
                    0,
                    (string) $target
                );
            } catch (\Throwable $e) {
                fn_kwtsms_log_hook_error('kwtsms_twofa.reset_alert', $e);
            }
        } elseif ($status === 'self') {
            self::say('E', 'kwtsms.twofa.reset_self');
        } elseif ($status === 'not_root') {
            return [CONTROLLER_STATUS_DENIED];
        } else {
            self::say('E', 'kwtsms.twofa.reset_failed');
        }

        return [CONTROLLER_STATUS_REDIRECT, $back];
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
