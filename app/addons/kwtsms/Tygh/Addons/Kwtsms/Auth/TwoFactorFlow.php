<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Auth/TwoFactorFlow.php
 * CREATED:   2026-11-04 22:44 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The logic behind the controllers kwtsms_twofa of both areas: the code page of a held sign-in (challenge,
 *            sendFirstCode, resend, submit, cancel) and the opt-in / opt-out of a logged-in account (profileInfo,
 *            profileSend, profileConfirm).
 * WHY:       Task M2.2a (SafatMall B3, P3-47, P3-49, P3-51). One place, two thin controllers. Every refusal fails
 *            closed
 *            for the user who needs a code: nothing logs anybody in except a correct code of the pending state of this
 *            session, for the same account (status A, same password change time, still needing a code), after which the
 *            session id changes (fn_login_user with regenerate) and no remember-me cookie is written. The phone a code
 *            goes to is read from the database (the 2FA row, or the users row of an administrator or vendor, or the
 *            account phone of a customer), never from the request; the account id comes from the pending state or the
 *            session, never from the request.
 * USAGE:     $flow = Tygh::$app['kwtsms.twofa_flow'];
 *            $flow->challenge();                    // ['masked','area','requirement','sent','seconds_left'] or null
 *            $flow->resend();                       // the JSON body of CodeRequest::handleTwoFactor()
 *            $r = $flow->submit($typed);            // ['status','attempts_left','retry_after','redirect']
 *                                                   // status: ok | wrong | locked | ip_limited | malformed | expired |
 *                                                   // exhausted | changed | none | error
 *            $flow->cancel();
 *            $flow->profileInfo($user_id);          // the state of the opt-in box
 *            $flow->profileSend($user_id, 'enable'|'disable');   // body of the code request
 *            $flow->profileConfirm($user_id, 'enable'|'disable', $typed);   // ['status', ...] ok | off | no_phone |
 *                                                   // in_use | required | wrong | locked | ip_limited | malformed |
 *                                                   // expired | error
 *            Seams (constructor): env (): array{ip,hash,storefront_id,lang,host,secure}, users (int): array, login
 *            (int):
 *            bool, account_phone (array $user): string.
 * DEPENDS:   Settings\ConfigInterface, Auth\{TwoFactorPolicy,TwoFactorStore,PendingLogin,ReturnUrl}, Codes\{OtpService,
 *            OtpResult,CodeRequest,CodeInput}, Phones\{PhoneIndex,PhoneFormat,AccountPhone}, Log\DebugLog (optional),
 *            core
 *            fn_login_user(), db_get_row().
 */

namespace Tygh\Addons\Kwtsms\Auth;

use Tygh\Addons\Kwtsms\Codes\CodeInput;
use Tygh\Addons\Kwtsms\Codes\CodeRequest;
use Tygh\Addons\Kwtsms\Codes\OtpResult;
use Tygh\Addons\Kwtsms\Codes\OtpService;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Phones\AccountPhone;
use Tygh\Addons\Kwtsms\Phones\PhoneFormat;
use Tygh\Addons\Kwtsms\Phones\PhoneIndex;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

class TwoFactorFlow
{
    /** Seconds the done mark of a completed code lets the one fn_login_user() through the hook (P3-51). */
    public const DONE_SECONDS = 300;

    /** @var ConfigInterface */
    private $config;

    /** @var TwoFactorPolicy */
    private $policy;

    /** @var TwoFactorStore */
    private $store;

    /** @var PendingLogin */
    private $pending;

    /** @var OtpService */
    private $otp;

    /** @var CodeRequest */
    private $codes;

    /** @var PhoneIndex */
    private $index;

    /** @var DebugLog|null */
    private $log;

    /** @var callable|null */
    private $env;

    /** @var callable|null */
    private $users;

    /** @var callable|null */
    private $login;

    /** @var callable|null */
    private $account_phone;

    public function __construct(
        ConfigInterface $config,
        TwoFactorPolicy $policy,
        TwoFactorStore $store,
        PendingLogin $pending,
        OtpService $otp,
        CodeRequest $codes,
        PhoneIndex $index,
        ?DebugLog $log = null,
        ?callable $env = null,
        ?callable $users = null,
        ?callable $login = null,
        ?callable $account_phone = null
    ) {
        $this->config = $config;
        $this->policy = $policy;
        $this->store = $store;
        $this->pending = $pending;
        $this->otp = $otp;
        $this->codes = $codes;
        $this->index = $index;
        $this->log = $log;
        $this->env = $env;
        $this->users = $users;
        $this->login = $login;
        $this->account_phone = $account_phone;
    }

    /**
     * 2026-11-04 23:15 +03: Task M2.2a, task 596. The masked phone inside a left-to-right isolate (U+2066 ... U+2069,
     * written as
     * escapes), so it reads +965****3402 inside an Arabic sentence too. Callers escape it with fn_html_escape.
     */
    public static function maskLtr(string $phone): string
    {
        return "\u{2066}" . self::mask($phone) . "\u{2069}";
    }

    /**
     * 2026-11-05 02:24 +03: Task M2.2a fix 1 (C1), task 596. The key of a 2FA code in the engine: the account and the
     * phone. Cancel-all, the resend step, the wrong-attempt buckets and the lockout are all keyed by it, so another
     * account that saved the same number shares none of them. At most 11 + 16 characters (the column holds 32).
     */
    public static function subject(int $user_id, string $phone): string
    {
        return $user_id . ':' . $phone;
    }

    /** The phone as shown on the page: +965****3402 (the calling code and the last four digits). */
    public static function mask(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        $digits = is_string($digits) ? $digits : '';
        if (strlen($digits) < 8) {
            return '****';
        }

        return '+' . substr($digits, 0, 3) . '****' . substr($digits, -4);
    }

    // ------------------------------------------------------------------ the code page of a held sign-in

    /**
     * What the code page shows; null = no pending state (the caller goes back to the sign-in form).
     *
     * @return array{masked:string,masked_ltr:string,area:string,requirement:string,sent:bool,seconds_left:int}|null
     */
    public function challenge(): ?array
    {
        $state = $this->pending->get();
        if ($state === null) {
            return null;
        }
        try {
            $user = $this->readUser((int) $state['user_id']);
            $phone = $user === [] ? '' : $this->phoneFor($user, (string) $state['requirement']);
        } catch (\Throwable $e) {
            $this->debug('twofa.page', ['error' => get_class($e)]);
            $phone = '';
        }

        return [
            'masked' => $phone === '' ? '****' : self::mask($phone),
            'masked_ltr' => self::maskLtr($phone === '' ? '' : $phone),
            'area' => (string) $state['area'],
            'requirement' => (string) $state['requirement'],
            'sent' => !empty($state['sent']),
            'seconds_left' => max(0, (int) $state['expires_at'] - time()),
        ];
    }

    /**
     * The first code of a password sign-in (LoginHold::intercept()); true when a code SMS was handed over.
     *
     * @param array<string,mixed> $user
     */
    public function sendFirstCode(array $user, string $area): bool
    {
        $state = $this->pending->get();
        $need = $state === null ? 'code' : (string) $state['requirement'];
        $body = $this->sendTo($user, $need);

        return !empty($body['ok']);
    }

    /**
     * "Send a new code" on the code page.
     *
     * @return array<string,mixed> The body of CodeRequest::handleTwoFactor(), or a refusal body
     */
    public function resend(): array
    {
        $state = $this->pending->get();
        if ($state === null) {
            return ['ok' => false, 'error' => 'unavailable'];
        }
        try {
            $user = $this->readUser((int) $state['user_id']);
            if (!$this->stillValid($state, $user)) {
                $this->pending->clear();

                return ['ok' => false, 'error' => 'changed'];
            }
            $body = $this->sendTo($user, (string) $state['requirement']);
            if (!empty($body['ok'])) {
                $this->pending->markSent();
            }

            return $body;
        } catch (\Throwable $e) {
            $this->debug('twofa.resend', ['error' => get_class($e)]);

            return ['ok' => false, 'error' => 'unavailable'];
        }
    }

    public function cancel(): void
    {
        $this->pending->clear();
    }

    /**
     * The typed code of the pending sign-in. Nothing logs anybody in unless every line before the login holds.
     *
     * @return array{status:string,attempts_left:int,retry_after:int,redirect:string}
     */
    public function submit(
        #[\SensitiveParameter]
        string $raw_code
    ): array {
        $state = $this->pending->get();
        if ($state === null) {
            return $this->answer('none');
        }
        try {
            $uid = (int) $state['user_id'];
            $user = $this->readUser($uid);
            if (!$this->stillValid($state, $user)) {
                $this->pending->clear();

                return $this->answer('changed');
            }
            $need = $this->policy->requirement($user);
            if ($need !== TwoFactorPolicy::CODE && $need !== TwoFactorPolicy::ENROLL) {
                // The switch changed or the account no longer needs a code: the password is asked again.
                $this->pending->clear();

                return $this->answer('changed');
            }
            $phone = $this->phoneFor($user, $need);
            if ($phone === '') {
                $this->pending->clear();

                return $this->answer('changed');
            }
            if (CodeInput::normalize($raw_code) === null) {
                return $this->answer('malformed');
            }
            $env = $this->environment();
            $count = $this->pending->countSubmission();
            $subject = self::subject($uid, $phone);
            $result = $this->otp->verify(CodeRequest::PURPOSE_2FA, $subject, $raw_code, $uid, $env['hash'], $env['ip']);
            $refused = $result->status !== OtpResult::OK && $result->status !== OtpResult::MALFORMED;
            if ($refused && $count >= PendingLogin::MAX_SUBMISSIONS) {
                // 2026-11-04 23:00 +03: Task M2.2a (P3-51), task 596. The fifth submission that is not accepted ends
                // the pending
                // state, whatever the engine said (wrong, locked, expired): the password is asked again.
                $this->pending->clear();

                return $this->answer('exhausted');
            }
            switch ($result->status) {
                case OtpResult::OK:
                    break;
                case OtpResult::LOCKED:
                    return $this->answer('locked', 0, $result->retry_after);
                case OtpResult::IP_LIMITED:
                    return $this->answer('ip_limited', 0, $result->retry_after);
                case OtpResult::EXPIRED:
                case OtpResult::NONE:
                    return $this->answer('expired');
                case OtpResult::MALFORMED:
                    return $this->answer('malformed');
                default:
                    return $this->answer('wrong', (int) $result->attempts_left);
            }
            if (!$this->otp->consume(CodeRequest::PURPOSE_2FA, $subject, $uid, $env['hash'])) {
                return $this->answer('wrong', $this->config->otpMaxAttempts());
            }

            return $this->complete($state, $user, $need, $phone);
        } catch (\Throwable $e) {
            // Fail closed: nobody is logged in.
            $this->debug('twofa.complete', ['reason' => 'exception', 'error' => get_class($e)]);

            return $this->answer('error');
        }
    }

    // ------------------------------------------------------------------ opt-in and opt-out of a logged-in account

    /**
     * @return array{available:bool,enabled:bool,masked:string,usable_phone:bool,can_disable:bool,type:string}
     */
    public function profileInfo(int $user_id): array
    {
        $none = ['available' => false, 'enabled' => false, 'masked' => '', 'usable_phone' => false,
            'can_disable' => false, 'type' => ''];
        try {
            $user = $user_id > 0 ? $this->readUser($user_id) : [];
            if ($user === [] || !$this->policy->anyOn() || $this->policy->typeSwitch($user) === 'off') {
                return $none;
            }
            $row = $this->store->row($user_id);
            $enabled = $row !== null && (string) $row['enabled'] === 'Y';
            $account = $this->accountPhone($user);

            return [
                'available' => true,
                'enabled' => $enabled,
                'masked' => $enabled ? self::mask((string) $row['phone'])
                    : ($account === '' ? '' : self::mask($account)),
                'masked_ltr' => $enabled ? self::maskLtr((string) $row['phone'])
                    : ($account === '' ? '' : self::maskLtr($account)),
                'usable_phone' => $account !== '',
                'can_disable' => $this->policy->typeSwitch($user) !== 'required',
                'type' => (string) $user['user_type'],
            ];
        } catch (\Throwable $e) {
            $this->debug('twofa.profile', ['error' => get_class($e)]);

            return $none;
        }
    }

    /**
     * The code of an opt-in (to the account phone) or an opt-out (to the current 2FA phone). The account is the
     * signed-in one; the phone is read from the database.
     *
     * @return array<string,mixed>
     */
    public function profileSend(int $user_id, string $action): array
    {
        try {
            $phone = $this->profilePhone($user_id, $action, $refusal);
            if ($phone === '') {
                return ['ok' => false, 'error' => $refusal];
            }
            $user = $this->readUser($user_id);

            return $this->sendPlain($user, $phone, CodeRequest::PURPOSE_2FA_ENROLL);
        } catch (\Throwable $e) {
            $this->debug('twofa.profile_send', ['error' => get_class($e)]);

            return ['ok' => false, 'error' => 'unavailable'];
        }
    }

    /**
     * @return array{status:string,attempts_left:int,retry_after:int}
     */
    public function profileConfirm(
        int $user_id,
        string $action,
        #[\SensitiveParameter]
        string $raw_code
    ): array {
        $out = ['status' => 'error', 'attempts_left' => 0, 'retry_after' => 0];
        try {
            $phone = $this->profilePhone($user_id, $action, $refusal);
            if ($phone === '') {
                $out['status'] = $refusal;

                return $out;
            }
            if (CodeInput::normalize($raw_code) === null) {
                $out['status'] = 'malformed';

                return $out;
            }
            $env = $this->environment();
            $subject = self::subject($user_id, $phone);
            $result = $this->otp->verify(
                CodeRequest::PURPOSE_2FA_ENROLL,
                $subject,
                $raw_code,
                $user_id,
                $env['hash'],
                $env['ip']
            );
            if ($result->status !== OtpResult::OK) {
                $map = [OtpResult::LOCKED => 'locked', OtpResult::IP_LIMITED => 'ip_limited',
                    OtpResult::EXPIRED => 'expired', OtpResult::NONE => 'expired', OtpResult::MALFORMED => 'malformed'];
                $out['status'] = $map[$result->status] ?? 'wrong';
                $out['attempts_left'] = (int) $result->attempts_left;
                $out['retry_after'] = (int) $result->retry_after;

                return $out;
            }
            if (!$this->otp->consume(CodeRequest::PURPOSE_2FA_ENROLL, $subject, $user_id, $env['hash'])) {
                $out['status'] = 'wrong';

                return $out;
            }
            $user = $this->readUser($user_id);
            if ($action === 'enable') {
                $this->store->enable($user_id, $phone);
                if ((string) $user['user_type'] === 'C') {
                    // The proof of possession also verifies the number in the index (P3-49).
                    $this->index->upsert($user_id, $phone, true);
                }
                $this->info('twofa.enrolled', ['user_id' => $user_id, 'type' => (string) $user['user_type']]);
            } else {
                $this->store->disable($user_id);
                $this->info('twofa.disabled', ['user_id' => $user_id, 'type' => (string) $user['user_type']]);
            }
            $out['status'] = 'ok';

            return $out;
        } catch (\Throwable $e) {
            $this->debug('twofa.profile_confirm', ['error' => get_class($e)]);
            $out['status'] = 'error';

            return $out;
        }
    }

    // ------------------------------------------------------------------ pieces

    /**
     * The phone a profile code goes to, '' with the reason in $refusal.
     */
    private function profilePhone(int $user_id, string $action, ?string &$refusal = null): string
    {
        $refusal = 'error';
        if ($user_id <= 0 || ($action !== 'enable' && $action !== 'disable')) {
            return '';
        }
        $user = $this->readUser($user_id);
        $off = $user === [] || (string) $user['status'] !== 'A' || !$this->policy->anyOn()
            || $this->policy->typeSwitch($user) === 'off';
        if ($off) {
            $refusal = 'off';

            return '';
        }
        $row = $this->store->row($user_id);
        $enabled = $row !== null && (string) $row['enabled'] === 'Y';
        if ($action === 'disable') {
            if (!$enabled) {
                $refusal = 'off';

                return '';
            }
            if ($this->policy->typeSwitch($user) === 'required') {
                $refusal = 'required';

                return '';
            }

            return (string) $row['phone'];
        }
        $phone = $this->accountPhone($user);
        if ($phone === '') {
            $refusal = 'no_phone';

            return '';
        }
        if ((string) $user['user_type'] === 'C' && $this->index->ownerOf($phone, $user_id) > 0) {
            $refusal = 'in_use';

            return '';
        }
        // 2026-11-05 02:24 +03: Task M2.2a fix 1 (C1), task 596. Whoever the account is, a number that another account
        // already uses for its second step (an enrolled 2FA phone) or that belongs to an administrator or a vendor is
        // refused with the same neutral answer as a number in use: nobody enrols somebody else's number.
        if ($this->store->phoneHeldByAnother($user_id, $phone, $this->config->defaultCountryCode())) {
            $refusal = 'in_use';

            return '';
        }

        return $phone;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $user
     *
     * @return array{status:string,attempts_left:int,retry_after:int,redirect:string}
     */
    private function complete(array $state, array $user, string $need, string $phone): array
    {
        $uid = (int) $user['user_id'];
        if ($need === TwoFactorPolicy::ENROLL) {
            // Required, no row yet: the first correct code proves the number and enrols it (P3-49).
            $this->store->enable($uid, $phone);
            $this->info('twofa.enrolled', [
                'user_id' => $uid, 'type' => (string) $user['user_type'], 'path' => 'login',
            ]);
        }
        $this->assignOrders($uid);
        // The done mark lets exactly this fn_login_user() through the hook; it is used up either way.
        $this->pending->markDone($uid, self::DONE_SECONDS);
        $ok = false;
        try {
            $ok = $this->logIn($uid);
        } finally {
            $this->pending->useDone($uid);
        }
        if (!$ok) {
            $this->debug('twofa.complete', ['reason' => 'login_failed']);

            return $this->answer('error');
        }
        $this->pending->clear();
        $this->clearCodeSession();
        $this->info('twofa.login', ['user_id' => $uid, 'area' => (string) $state['area']]);
        $env = $this->environment();
        $return = ReturnUrl::safe((string) $state['return_url'], $env['host'], '', $env['secure']);

        return $this->answer('ok', 0, 0, $return !== '' ? $return : 'index.index');
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $user
     */
    private function stillValid(array $state, array $user): bool
    {
        return $user !== []
            && (string) $user['status'] === 'A'
            && (int) $user['user_id'] === (int) $state['user_id']
            && (string) $user['user_type'] === (string) $state['account_type']
            && (int) $user['password_change_timestamp'] === (int) $state['password_change_timestamp'];
    }

    /**
     * The phone the code of $need goes to: the 2FA row's, or (enrol) the account's own mobile.
     *
     * @param array<string,mixed> $user
     */
    private function phoneFor(array $user, string $need): string
    {
        if ($need === TwoFactorPolicy::ENROLL) {
            return $this->accountPhone($user);
        }
        $row = $this->store->row((int) $user['user_id']);

        return $row !== null && (string) $row['enabled'] === 'Y' ? trim((string) $row['phone']) : '';
    }

    /**
     * Administrators and vendors: users.phone. Customers: the account phone as the profile reads it (P3-17).
     *
     * @param array<string,mixed> $user
     */
    private function accountPhone(array $user): string
    {
        if ($this->account_phone !== null) {
            return (string) call_user_func($this->account_phone, $user);
        }
        $default = $this->config->defaultCountryCode();
        if ((string) $user['user_type'] === 'C' && function_exists('fn_get_user_info')) {
            $info = fn_get_user_info((int) $user['user_id'], false);

            return is_array($info) && $info !== [] ? AccountPhone::canonicalOf($info, $info, $default) : '';
        }

        return PhoneFormat::fromRaw((string) ($user['phone'] ?? ''), $default);
    }

    /**
     * @param array<string,mixed> $user
     *
     * @return array<string,mixed>
     */
    private function sendTo(array $user, string $need): array
    {
        $phone = $this->phoneFor($user, $need);
        if ($phone === '') {
            return ['ok' => false, 'error' => 'unavailable'];
        }

        return $this->sendPlain($user, $phone);
    }

    /**
     * @param array<string,mixed> $user
     *
     * @return array<string,mixed>
     */
    private function sendPlain(array $user, string $phone, string $purpose = CodeRequest::PURPOSE_2FA): array
    {
        $env = $this->environment();
        $staff = (string) $user['user_type'] !== 'C';
        $lang = $staff && (string) ($user['lang_code'] ?? '') !== '' ? (string) $user['lang_code'] : $env['lang'];

        return $this->codes->handleTwoFactor(
            $phone,
            (int) $user['user_id'],
            $staff,
            $env['hash'],
            $env['ip'],
            $env['storefront_id'],
            $lang,
            $purpose,
            self::subject((int) $user['user_id'], $phone)
        );
    }

    private function logIn(int $user_id): bool
    {
        if ($this->login !== null) {
            return (bool) call_user_func($this->login, $user_id);
        }

        // fn_login_user($id, true) regenerates the session id (fn.users.php:3553-3557); no remember-me cookie.
        return fn_login_user($user_id, true) === LOGIN_STATUS_OK;
    }

    /** Orders placed while anonymous in this session belong to the account (core does the same at auth.php:56-62). */
    private function assignOrders(int $user_id): void
    {
        if ($this->login !== null) {
            return;
        }
        $session = \Tygh\Tygh::$app['session'];
        $ids = isset($session['auth']['order_ids']) && is_array($session['auth']['order_ids'])
            ? $session['auth']['order_ids'] : [];
        foreach ($ids as $order_id) {
            db_query('UPDATE ?:orders SET user_id = ?i WHERE order_id = ?i', $user_id, (int) $order_id);
        }
    }

    private function clearCodeSession(): void
    {
        if ($this->login !== null) {
            return;
        }
        $session = \Tygh\Tygh::$app['session'];
        unset($session[\Tygh\Addons\Kwtsms\Codes\BlindCodeRequest::STEP_KEY], $session[CodeRequest::BYPASS_KEY]);
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

    /**
     * @return array{ip:string,hash:string,storefront_id:int,lang:string,host:string,secure:bool}
     */
    private function environment(): array
    {
        if ($this->env !== null) {
            return (array) call_user_func($this->env) + ['host' => '', 'secure' => false];
        }
        $storefront = isset(\Tygh\Tygh::$app['storefront']) ? \Tygh\Tygh::$app['storefront'] : null;

        return [
            'ip' => \Tygh\Addons\Kwtsms\Security\ClientIp::current(),
            'hash' => CodeRequest::sessionHash(),
            'storefront_id' => is_object($storefront) && isset($storefront->storefront_id)
                ? (int) $storefront->storefront_id : 0,
            'lang' => defined('CART_LANGUAGE') ? (string) CART_LANGUAGE : 'en',
            'host' => (string) \Tygh\Registry::get('config.current_host'),
            'secure' => defined('HTTPS'),
        ];
    }

    /**
     * @return array{status:string,attempts_left:int,retry_after:int,redirect:string}
     */
    private function answer(string $status, int $attempts_left = 0, int $retry_after = 0, string $redirect = ''): array
    {
        return [
            'status' => $status,
            'attempts_left' => $attempts_left,
            'retry_after' => $retry_after,
            'redirect' => $redirect,
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    private function debug(string $action, array $context): void
    {
        if ($this->log !== null) {
            $this->log->log('debug', $action, $action, $context);
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function info(string $action, array $context): void
    {
        if ($this->log !== null) {
            $this->log->log('info', $action, $action, $context);
        }
    }
}
