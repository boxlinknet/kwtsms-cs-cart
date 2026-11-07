<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Auth/LoginHold.php
 * CREATED:   2026-11-04 22:39 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The two places a login is held for the second step (P3-50): onLoginUserPre() is the body of the hook
 *            login_user_pre (every fn_login_user() caller that is not the password form: it sets the user id to 0 so
 *            core logs nobody in), and intercept() is the body of the pre-controller on POST auth.login (a correct
 *            password of an account that needs a code gets a pending state and the first code instead of a login).
 *            pendingRedirect() tells before_dispatch where a login held by the hook path continues.
 * WHY:       Task M2.2a (SafatMall B3). Highest-risk code of 1.3.0: a hole lets a user skip the second factor, a bug
 *            locks administrators out. Rules kept here: nothing is read and nobody is held while the three switches
 *            are off or KWTSMS_2FA_OFF is set; CONSOLE is never held; an error after a switch is on refuses the login
 *            of the account it could not check (fail closed) and never touches anybody else; auth is never in the
 *            session before the code; the remember-me cookie of a 2FA account is deleted, never honoured.
 * USAGE:     LoginHold::onLoginUserPre($user_id)   // by reference; 0 afterwards = held
 *            $out = LoginHold::intercept($_REQUEST, $auth, AREA);  // null = let core run, else the controller return
 *            Seams (constructor): env (): array{console,area,cookie,host,secure}, users (int): array, cookie_cleaner,
 *            authenticate (array $request, array $auth): array{status,verified,user}, first_code (array $user, string
 *            $area): bool (true = a code was sent), regenerate (): void.
 * DEPENDS:   Settings\ConfigInterface, Auth\{TwoFactorPolicy,PendingLogin,ReturnUrl}, Log\DebugLog (optional), core
 *            fn_auth_routines(), fn_user_password_verify(), fn_set_notification() when not injected.
 *
 * 2026-11-05 07:27 +03: Task M2.2b (P3-52 (4), (5)), task 596. The let-in of the last root administrator raises the
 * alert twofa_degraded
 * and, on the password path, takeLetIn() tells auth.pre.php to send the administrator to the own profile; the
 * break-glass constant with a switch on raises twofa_disabled_by_config on both paths. The alert callable (string
 * $condition, array $params, bool $email, int $every_seconds): void is the 12th constructor argument (null = the
 * store's AdminAlerts). An alert that throws changes nothing.
 */

namespace Tygh\Addons\Kwtsms\Auth;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

class LoginHold
{
    /** Core's CONTROLLER_STATUS_REDIRECT (the pre-controller return value). */
    private const REDIRECT = '302';

    public const VERIFY = 'kwtsms_twofa.verify';
    public const LOGIN_FORM = 'auth.login_form';

    /** Controllers a held login may still reach: the code page itself, the sign-in forms, the code endpoints, cron. */
    private const OPEN_CONTROLLERS = ['kwtsms_twofa', 'auth', 'kwtsms_auth', 'kwtsms_code', 'kwtsms_cron'];

    /** @var ConfigInterface */
    private $config;

    /** @var TwoFactorPolicy */
    private $policy;

    /** @var PendingLogin */
    private $pending;

    /** @var DebugLog|null */
    private $log;

    /** @var callable|null */
    private $env;

    /** @var callable|null */
    private $users;

    /** @var callable|null */
    private $cookie_cleaner;

    /** @var callable|null */
    private $authenticate;

    /** @var callable|null */
    private $first_code;

    /** @var callable|null */
    private $regenerate;

    /** @var callable|null 2026-11-05 02:30 +03: Task M2.2a fix 1 (I2), task 596. (string $login, string $password): array */
    private $basic;

    /** @var callable|null 2026-11-05 07:27 +03: Task M2.2b, task 596. (string $condition, array $params, bool $email, int $every): void */
    private $alert;

    /** @var bool 2026-11-05 07:27 +03: Task M2.2b, task 596. The password path just let a last root administrator in. */
    private $let_in = false;

    public function __construct(
        ConfigInterface $config,
        TwoFactorPolicy $policy,
        PendingLogin $pending,
        ?DebugLog $log = null,
        ?callable $env = null,
        ?callable $users = null,
        ?callable $cookie_cleaner = null,
        ?callable $authenticate = null,
        ?callable $first_code = null,
        ?callable $regenerate = null,
        ?callable $basic = null,
        ?callable $alert = null
    ) {
        $this->alert = $alert;
        $this->config = $config;
        $this->policy = $policy;
        $this->pending = $pending;
        $this->log = $log;
        $this->env = $env;
        $this->users = $users;
        $this->cookie_cleaner = $cookie_cleaner;
        $this->authenticate = $authenticate;
        $this->first_code = $first_code;
        $this->regenerate = $regenerate;
        $this->basic = $basic;
    }

    // ------------------------------------------------------------------ the hook login_user_pre

    /**
     * Hook body (fn.users.php:3550): a login of an account that needs a code is turned into "nobody" unless the code
     * page just passed it. By reference on purpose: core reads the id after the hook.
     */
    public function onLoginUserPre(int &$user_id): void
    {
        if ($user_id <= 0) {
            return;
        }
        // 2026-11-05 02:15 +03: Task M2.2a fix 1 (I1), task 596. Rewritten so no exception can release an account that
        // needs a code. Before the account is known only a switch read that throws may leave the login to core, and
        // only when every switch is proved off (holdUnknown()). From the moment the requirement is known, the user id
        // is set to 0 first and everything after it (done mark, log, pending state, cookie) runs inside its own guard.
        try {
            $env = $this->environment();
            if ($env['console']) {
                return;
            }
        } catch (\Throwable $e) {
            // The environment could not be read: CONSOLE is unknown, so the account is held (nothing reads a DB here).
            $user_id = 0;

            return;
        }
        try {
            if (!$this->policy->anyOn()) {
                $this->alertIfDisabledByConfig();

                return;
            }
        } catch (\Throwable $e) {
            $this->debug('twofa.hold', ['reason' => 'switch_read_failed', 'error' => get_class($e)]);
            if ($this->switchesProvablyOff()) {
                return;
            }
            $user_id = 0;

            return;
        }
        $user = [];
        $need = TwoFactorPolicy::REFUSE;
        try {
            $user = $this->readUser($user_id);
            if ($user === []) {
                return;
            }
            $need = $this->policy->requirement($user);
        } catch (\Throwable $e) {
            // Fail closed for the account that could not be checked; nobody else is touched.
            $user_id = 0;
            $this->debug('twofa.hold', ['reason' => 'check_failed', 'error' => get_class($e)]);

            return;
        }
        if ($need === TwoFactorPolicy::LET_IN) {
            // 2026-11-05 07:27 +03: Task M2.2b (P3-52 (5)), task 596. The last root administrator without a usable
            // mobile gets in.
            $this->raise('twofa_degraded', true);
        }
        if ($need === TwoFactorPolicy::NONE || $need === TwoFactorPolicy::LET_IN) {
            return;
        }
        if ($need === TwoFactorPolicy::CODE || $need === TwoFactorPolicy::ENROLL) {
            try {
                if ($this->pending->useDone($user_id)) {
                    $this->debug('twofa.pass', ['user_id' => $user_id]);

                    return;
                }
            } catch (\Throwable $e) {
                // A done mark that cannot be read is no pass: the account stays held.
                $this->debug('twofa.hold', ['reason' => 'done_mark_failed', 'error' => get_class($e)]);
            }
        }
        $held = $user_id;
        $user_id = 0;
        $this->debug('twofa.hold', ['user_id' => $held, 'need' => $need, 'path' => $env['cookie'] ? 'cookie' : 'hook']);
        if ($need === TwoFactorPolicy::REFUSE) {
            return;
        }
        try {
            if ($env['cookie']) {
                $this->cleanCookie();

                return;
            }
            $this->pending->start($user, $env['area'], false, '', true, $need);
        } catch (\Throwable $e) {
            $this->debug('twofa.hold', ['reason' => 'pending_failed', 'error' => get_class($e)]);
        }
    }

    /** After a switch read threw: true only when every switch reads off one by one (never throws). */
    private function switchesProvablyOff(): bool
    {
        try {
            return $this->policy->switchesProvablyOff();
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ------------------------------------------------------------------ the "successful login" notice of a held login

    /**
     * 2026-11-05 02:34 +03: Task M2.2a fix 1 (M4), task 596. When the pre-controller could not judge the password
     * (authenticateRequest()
     * threw), core runs its own login, the hook holds the account, and core still sets its notice "You have been
     * successfully logged in" (app/controllers/common/auth.php, after fn_login_user()). While a hook-held state is
     * pending
     * the next request drops exactly that notice from the session list. Pure: the caller reads and writes the session.
     *
     * @param array<string|int,mixed> $notifications The session notifications (key => ['type','message',...])
     * @param string                  $message       The text of core's __('successful_login') in the current language
     *
     * @return array<string|int,mixed>
     */
    public static function withoutLoginNotice(array $notifications, string $message): array
    {
        if ($message === '') {
            return $notifications;
        }
        foreach ($notifications as $key => $notice) {
            $same = is_array($notice) && (string) ($notice['message'] ?? '') === $message;
            if ($same && ($notice['type'] ?? '') === 'N') {
                unset($notifications[$key]);
            }
        }

        return $notifications;
    }

    // ------------------------------------------------------------------ HTTP Basic sign-in of other add-ons

    /**
     * 2026-11-05 02:30 +03: Task M2.2a fix 1 (I2), task 596. The commerceml add-on signs an administrator or vendor in
     * with e-mail and password over HTTP Basic and fn_fill_auth(), never through fn_login_user(), so neither hook sees
     * it
     * (controllers/frontend/commerceml.php:60, src/Commands/AuthCommandHandler.php:76). Its pre-controller asks here.
     * True = answer 401 with no detail: the password is right and the account needs a second step that a Basic request
     * cannot give. A wrong password or an unknown login is false (core answers its own error, so nothing is revealed
     * about 2FA to somebody without the password), and so is every case where the account is not known. Once the
     * password is right, any failure refuses. Never throws.
     */
    // 2026-11-05 07:20 +03: fix 1 (I2), task 596. #[\SensitiveParameter] on the password (release gate).
    public function refusesBasicAuth(
        string $login,
        #[\SensitiveParameter]
        string $password
    ): bool {
        try {
            $env = $this->environment();
            if ($env['console'] || !$this->policy->anyOn()) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }
        try {
            $r = $this->basicAuthenticate($login, $password);
            if (empty($r['status']) || empty($r['verified']) || empty($r['user']) || !is_array($r['user'])) {
                return false;
            }
            $user = $r['user'];
        } catch (\Throwable $e) {
            $this->debug('twofa.hold', ['reason' => 'basic_authenticate_failed', 'error' => get_class($e)]);

            return false;
        }
        try {
            $need = $this->policy->requirement($user);
        } catch (\Throwable $e) {
            $this->debug('twofa.hold', ['reason' => 'check_failed', 'path' => 'basic', 'error' => get_class($e)]);

            return true;
        }
        if ($need === TwoFactorPolicy::NONE || $need === TwoFactorPolicy::LET_IN) {
            return false;
        }
        $this->debug('twofa.hold', [
            'user_id' => (int) ($user['user_id'] ?? 0), 'need' => $need, 'path' => 'basic',
        ]);

        return true;
    }

    /**
     * @return array<string,mixed> status, verified, user (the same shape as authenticateRequest())
     */
    private function basicAuthenticate(
        string $login,
        #[\SensitiveParameter]
        string $password
    ): array {
        if ($this->basic !== null) {
            return (array) call_user_func($this->basic, $login, $password);
        }
        // The calls commerceml makes (AuthCommandHandler::handle()), nothing else.
        list($status, $user_data, $user_login, , $salt) = fn_auth_routines(['user_login' => $login], []);
        $verified = $status !== false
            && !empty($user_data)
            && $login === $user_login
            && !empty($user_data['password'])
            && fn_user_password_verify(
                (int) $user_data['user_id'],
                $password,
                (string) $user_data['password'],
                (string) $salt
            );

        return [
            'status' => $status !== false,
            'verified' => $verified,
            'user' => is_array($user_data) ? $user_data : [],
        ];
    }

    // ------------------------------------------------------------------ the pre-controller (POST auth.login)

    /** @var bool 2026-11-08 00:09 +03: M2.10 G2, task 596. The first-net skip was logged in this request. */
    private $net_logged = false;

    /**
     * 2026-11-08 00:09 +03: M2.10 G2, task 596. The core functions the first net calls, which this core lacks (test
     * seam). Empty when the authentication step is injected.
     *
     * @return string[]
     */
    protected function missingNetFunctions(): array
    {
        if ($this->authenticate !== null) {
            return [];
        }
        $missing = [];
        foreach (['fn_restore_processed_user_password', 'fn_auth_routines', 'fn_user_password_verify'] as $function) {
            if (!function_exists($function)) {
                $missing[] = $function;
            }
        }

        return $missing;
    }

    /**
     * @param array<string,mixed> $request $_REQUEST of POST auth.login
     * @param array<string,mixed> $auth    The session auth array
     *
     * @return array<int,string>|null null = let core run; else the controller return value
     */
    public function intercept(array $request, array $auth, string $area): ?array
    {
        $env = $this->environment();
        $this->let_in = false;
        if ($env['console']) {
            return null;
        }
        if (!$this->policy->anyOn()) {
            $this->alertIfDisabledByConfig();

            return null;
        }
        // 2026-11-08 00:09 +03: M2.10 G2, task 596. A core without the functions this first net mirrors (core's own
        // password check): logged once, core runs; the hook login_user_pre is the second net.
        $net_missing = $this->missingNetFunctions();
        if ($net_missing !== []) {
            if (!$this->net_logged) {
                $this->net_logged = true;
                error_log('kwtsms: sign-in hold first net skipped, core functions missing: '
                    . implode(', ', $net_missing));
            }
            $this->debug('twofa.hold', ['reason' => 'net_functions_missing']);

            return null;
        }
        try {
            $r = $this->authenticateRequest($request, $auth);
        } catch (\Throwable $e) {
            // Before the password is judged nothing is known: core runs (its hook is the second net).
            $this->debug('twofa.hold', ['reason' => 'authenticate_failed', 'error' => get_class($e)]);

            return null;
        }
        if (empty($r['status']) || empty($r['verified']) || empty($r['user']) || !is_array($r['user'])) {
            return null;
        }
        $user = $r['user'];
        try {
            $need = $this->policy->requirement($user);
        } catch (\Throwable $e) {
            $this->debug('twofa.hold', ['reason' => 'check_failed', 'error' => get_class($e)]);
            $this->tell('E', 'kwtsms.twofa.check_failed');

            return [self::REDIRECT, self::LOGIN_FORM];
        }
        if ($need === TwoFactorPolicy::LET_IN) {
            // 2026-11-05 07:27 +03: Task M2.2b (P3-52 (5)), task 596. Let in with the password only; the alert says
            // why, and the caller
            // sends the administrator to the profile (takeLetIn()).
            $this->let_in = true;
            $this->raise('twofa_degraded', true);
            $this->debug('twofa.hold', [
                'user_id' => (int) ($user['user_id'] ?? 0), 'need' => 'let_in', 'path' => 'password',
            ]);

            return null;
        }
        if ($need === TwoFactorPolicy::NONE) {
            return null;
        }
        if ($need === TwoFactorPolicy::REFUSE) {
            $this->debug('twofa.hold', [
                'user_id' => (int) ($user['user_id'] ?? 0), 'need' => 'refuse', 'path' => 'password',
            ]);
            $this->tell('E', 'kwtsms.twofa.refused');

            return [self::REDIRECT, self::LOGIN_FORM];
        }
        try {
            $this->regenerateSession();
            $return = ReturnUrl::safe(
                isset($request['return_url']) && is_string($request['return_url']) ? $request['return_url'] : '',
                $env['host'],
                '',
                $env['secure']
            );
            $this->pending->start($user, $area, !empty($request['remember_me']), $return, false, $need);
        } catch (\Throwable $e) {
            $this->debug('twofa.hold', ['reason' => 'pending_failed', 'error' => get_class($e)]);
            $this->tell('E', 'kwtsms.twofa.check_failed');

            return [self::REDIRECT, self::LOGIN_FORM];
        }
        $this->debug('twofa.hold', ['user_id' => (int) ($user['user_id'] ?? 0), 'need' => $need, 'path' => 'password']);
        try {
            if ($this->sendFirstCode($user, $area)) {
                $this->pending->markSent();
            }
        } catch (\Throwable $e) {
            // The page offers "Send a new code"; the login stays held.
            $this->debug('twofa.hold', ['reason' => 'first_code_failed', 'error' => get_class($e)]);
        }

        return [self::REDIRECT, self::VERIFY];
    }

    /**
     * 2026-11-05 07:27 +03: Task M2.2b, task 596. True once after intercept() let a last root administrator in:
     * auth.pre.php then sets
     * the return URL of core's login to the own profile page (the enrolment box). Read once.
     */
    public function takeLetIn(): bool
    {
        $was = $this->let_in;
        $this->let_in = false;

        return $was;
    }

    // ------------------------------------------------------------------ alerts

    /** The break-glass constant is set while a switch is on: the alert twofa_disabled_by_config (once a day). */
    private function alertIfDisabledByConfig(): void
    {
        try {
            if ($this->policy->disabledByConfig()) {
                $this->raise('twofa_disabled_by_config', false);
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    /** An alert that throws never changes a hold (AdminAlerts dedupes: twofa_degraded once a day). */
    private function raise(string $condition, bool $email): void
    {
        try {
            if ($this->alert !== null) {
                call_user_func($this->alert, $condition, [], $email, 86400);

                return;
            }
            \Tygh\Tygh::$app['kwtsms.alerts']->raise($condition, [], $email, 86400);
        } catch (\Throwable $e) {
            $this->debug('twofa.hold', ['reason' => 'alert_failed', 'error' => get_class($e)]);
        }
    }

    // ------------------------------------------------------------------ before_dispatch

    /**
     * Where a login held by the hook path continues (a GET page view of its area outside the open controllers): the
     * code page. null = nothing to do. No query: the session only.
     */
    public function pendingRedirect(string $controller, string $method, bool $ajax): ?string
    {
        if ($method !== 'GET' || $ajax || in_array($controller, self::OPEN_CONTROLLERS, true)) {
            return null;
        }
        $state = $this->pending->get();
        if ($state === null || empty($state['hook']) || (string) $state['area'] !== $this->environment()['area']) {
            return null;
        }

        return self::VERIFY;
    }

    // ------------------------------------------------------------------ seams

    /**
     * @return array{console:bool,area:string,cookie:bool,host:string,secure:bool}
     */
    private function environment(): array
    {
        if ($this->env !== null) {
            return (array) call_user_func($this->env) + ['host' => '', 'secure' => false];
        }

        return [
            'console' => defined('CONSOLE'),
            'area' => defined('AREA') ? (string) AREA : '',
            'cookie' => defined('LOGGED_VIA_COOKIE'),
            'host' => (string) \Tygh\Registry::get('config.current_host'),
            'secure' => defined('HTTPS'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readUser(int $user_id): array
    {
        if ($this->users !== null) {
            return (array) call_user_func($this->users, $user_id);
        }
        $row = db_get_row(
            'SELECT user_id, user_type, status, company_id, is_root, phone, password_change_timestamp, lang_code'
            . ' FROM ?:users WHERE user_id = ?i',
            $user_id
        );

        return is_array($row) ? $row : [];
    }

    private function cleanCookie(): void
    {
        if ($this->cookie_cleaner !== null) {
            call_user_func($this->cookie_cleaner);

            return;
        }
        // The remember-me cookie of a 2FA account is deleted: every new browser session needs a code (Open 2).
        fn_delete_session_data(AREA . '_user_id', AREA . '_password');
    }

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $auth
     *
     * @return array<string,mixed> status (core's routines passed), verified (the password is right), user
     */
    private function authenticateRequest(array $request, array $auth): array
    {
        if ($this->authenticate !== null) {
            return (array) call_user_func($this->authenticate, $request, $auth);
        }
        // The first steps of core's POST auth.login (app/controllers/common/auth.php:31-53), same functions, no side
        // effect that core repeats differently: a failed status sets the same notices (keyed, so shown once).
        fn_restore_processed_user_password($_REQUEST, $_POST);
        list($status, $user_data, $user_login, $password, $salt) = fn_auth_routines($_REQUEST, $auth);
        $verified = $status !== false
            && !empty($user_data)
            && !empty($password)
            && fn_user_password_verify(
                (int) $user_data['user_id'],
                $password,
                (string) $user_data['password'],
                (string) $salt
            );

        return [
            'status' => $status !== false,
            'verified' => $verified,
            'user' => is_array($user_data) ? $user_data : [],
        ];
    }

    /**
     * @param array<string,mixed> $user
     */
    private function sendFirstCode(array $user, string $area): bool
    {
        if ($this->first_code !== null) {
            return (bool) call_user_func($this->first_code, $user, $area);
        }

        return (bool) \Tygh\Tygh::$app['kwtsms.twofa_flow']->sendFirstCode($user, $area);
    }

    private function regenerateSession(): void
    {
        if ($this->regenerate !== null) {
            call_user_func($this->regenerate);

            return;
        }
        \Tygh\Tygh::$app['session']->regenerateID();
    }

    private function tell(string $type, string $langvar): void
    {
        if (function_exists('fn_set_notification')) {
            fn_set_notification($type, (string) __('error'), fn_html_escape((string) __($langvar)));
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function debug(string $action, array $context): void
    {
        // 2026-11-05 02:15 +03: Task M2.2a fix 1 (I1), task 596. A log that throws must never change a hold.
        try {
            if ($this->log !== null) {
                $this->log->log('debug', $action, $action, $context);
            }
        } catch (\Throwable $e) {
            return;
        }
    }
}
