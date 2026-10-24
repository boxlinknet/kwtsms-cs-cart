<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Codes/CodeRequest.php
 * CREATED:   2026-10-24 23:50 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The SMS code request: one handle() call turns "send a code to this phone" into the JSON body the
 *            storefront answers with. Order: per-IP buckets, suspicion gate and CAPTCHA token, phone rules, purpose
 *            rules, send availability, per-phone cap, OtpService::issue(), CodeSender::sendCode(), then the answer for
 *            the send result (sent or a per-number outcome: the same body; busy; not sendable: pause or bypass).
 * WHY:       Task M1.8a (SafatMall E1, E2, E3, I3; rulings P3-6, P3-10, P3-13, P3-22, P3-24, P3-34, P3-35). This is the
 *            endpoint that spends credits, so every limit sits before the send, a number that is capped or not covered
 *            is indistinguishable from one that was sent to, and a store that cannot send says so (alert) instead of
 *            skipping the verification silently.
 * USAGE:     $request = Tygh::$app['kwtsms.code_request'];
 *            $body = $request->handle('register', $raw_phone, $_REQUEST, $user_id, CodeRequest::sessionHash(), $ip,
 *                                     $storefront_id, 'en');       // array, json_encode() it; never throws a body
 *            Bodies (ok, then the rest in this key order):
 *              sent / per-number outcome  {ok:true, retry_after:<next wait>, expires_in:<s>, message}
 *              wait                       {ok:false, error:"wait", retry_after:<s>, message}   (IP cap, resend step,
 *                                         lockout, per-phone cap)
 *              invalid | country          {ok:false, error, message}     (C3 texts)
 *              in_use | login | captcha | unavailable   {ok:false, error, message}
 *              busy                       {ok:false, error:"busy", retry_after:<s to the next hour>, message}
 *              bypass                     {ok:true, bypass:true}  (otp_unreachable = continue; the session key
 *                                         kwtsms_code_bypass[<purpose>][<canonical phone>] = <now + expiry seconds>)
 *            The 9th argument is true when the controller already checked the CAPTCHA of the core scenario (the single-
 *            use token is then not spent twice). An exception after issue() revokes the code and is rethrown (the
 *            controller answers 500). Test seams: the constructor callables $session_writer and $current_phone, $clock.
 * DEPENDS:   Settings\ConfigInterface, Security\{RateLimiter,ClientIp}, Antibot\SuspicionGate, Phones\{PhoneRules,
 *            PhoneIndex,PhoneFormat,AccountPhone}, Codes\{CodeAvailability,OtpService,OtpResult,CodeSender},
 *            Log\DebugLog (optional), __().
 */

namespace Tygh\Addons\Kwtsms\Codes;

use Tygh\Addons\Kwtsms\Antibot\SuspicionGate;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Phones\AccountPhone;
use Tygh\Addons\Kwtsms\Phones\PhoneIndex;
use Tygh\Addons\Kwtsms\Phones\PhoneRules;
use Tygh\Addons\Kwtsms\Security\ClientIp;
use Tygh\Addons\Kwtsms\Security\RateLimiter;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

class CodeRequest
{
    public const PURPOSE_REGISTER = 'register';
    public const PURPOSE_PHONE_CHANGE = 'phone_change';
    public const PURPOSES = [self::PURPOSE_REGISTER, self::PURPOSE_PHONE_CHANGE];

    /** The session key of a "continue" bypass: [purpose][canonical phone] => Unix time it is valid until. */
    public const BYPASS_KEY = 'kwtsms_code_bypass';

    /** Windows of the per-IP buckets and the per-phone cap, in seconds. */
    public const HOUR = 3600;
    public const DAY = 86400;

    /** @var ConfigInterface */
    private $config;

    /** @var RateLimiter */
    private $limiter;

    /** @var SuspicionGate */
    private $gate;

    /** @var PhoneRules */
    private $rules;

    /** @var PhoneIndex */
    private $index;

    /** @var CodeAvailability */
    private $availability;

    /** @var OtpService */
    private $otp;

    /** @var CodeSender */
    private $sender;

    /** @var DebugLog|null */
    private $log;

    /** @var callable|null (string $purpose, string $phone, int $until): void */
    private $session_writer;

    /** @var callable|null (int $user_id): string the canonical phone of the account, '' when none */
    private $current_phone;

    /** @var callable|null */
    private $clock;

    /**
     * @param callable|null $session_writer Writes the session bypass key; null = the Tygh session
     * @param callable|null $current_phone Reads an account's canonical phone; null = the profile (fn_get_user_info)
     * @param callable|null $clock         Returns the Unix time; null = the limiter's clock
     */
    // 2026-10-25 00:08 +03: Task M1.8a, task 596. The parameters $session_writer and $captcha_checked (handle()) were
    // $bypass and $captcha_passed: the release gate reads "pass" inside a parameter name as a secret.
    public function __construct(
        ConfigInterface $config,
        RateLimiter $limiter,
        SuspicionGate $gate,
        PhoneRules $rules,
        PhoneIndex $index,
        CodeAvailability $availability,
        OtpService $otp,
        CodeSender $sender,
        ?DebugLog $log = null,
        ?callable $session_writer = null,
        ?callable $current_phone = null,
        ?callable $clock = null
    ) {
        $this->config = $config;
        $this->limiter = $limiter;
        $this->gate = $gate;
        $this->rules = $rules;
        $this->index = $index;
        $this->availability = $availability;
        $this->otp = $otp;
        $this->sender = $sender;
        $this->log = $log;
        $this->session_writer = $session_writer;
        $this->current_phone = $current_phone;
        $this->clock = $clock;
    }

    /**
     * The hash that binds a code to the browser session (P3-4): sha256 of the session id.
     */
    public static function sessionHash(): string
    {
        $id = '';
        try {
            $session = \Tygh\Tygh::$app['session'];
            $id = (string) $session->getID();
        } catch (\Throwable $e) {
            $id = '';
        }

        return hash('sha256', $id);
    }

    /**
     * @param array<string,mixed> $request The posted fields (the CAPTCHA token is read from it)
     *
     * @return array<string,mixed> The JSON body
     */
    public function handle(
        string $purpose,
        string $raw_phone,
        array $request,
        int $user_id,
        string $session_hash,
        string $ip,
        int $storefront_id,
        string $lang,
        bool $captcha_checked = false
    ): array {
        // 1. Per-IP buckets (P3-6): every request counts, sent or not. A counter that fails closed is over the limit.
        $ip_part = ClientIp::bucketKey($ip);
        $buckets = [
            ['ip_h', self::HOUR, $this->config->capIpHour()],
            ['ip_d', self::DAY, $this->config->capIpDay()],
        ];
        foreach ($buckets as $b) {
            $bucket = $b[0] . ':' . $ip_part;
            if ($this->limiter->hit($bucket, $b[1]) > $b[2]) {
                $this->debug('otp.ip_limited', ['bucket' => $b[0], 'ip' => $ip_part, 'purpose' => $purpose]);

                return $this->wait($this->windowLeft($bucket, $b[1]), $lang);
            }
        }

        // 2. The phone, read now and judged after the gate (a bot learns nothing about a number it cannot pass).
        $check = $this->rules->check($raw_phone, $this->config->defaultCountryCode());
        $phone = $check['ok'] ? $check['phone'] : '';

        // 3. Suspicion: a CAPTCHA token from the 4th request of an IP, the 3rd code of a phone, the 31st of the store.
        if (!$captcha_checked && $this->gate->requiresToken($ip, $phone) && !$this->gate->verify($request, $ip)) {
            return $this->error('captcha', 'kwtsms.code.captcha', $lang);
        }

        // 4. The phone must be a valid, allowed mobile, whatever the C3 switch says.
        if (!$check['ok']) {
            $kind = $check['reason'] === PhoneRules::REASON_COUNTRY ? 'country' : 'invalid';

            return $this->failure(
                $kind,
                $this->rules->message($check['reason'], $this->config->customerSmsCountries(), $lang)
            );
        }
        if ($phone === '') {
            return $this->failure('invalid', $this->rules->message(PhoneRules::REASON_INVALID, [], $lang));
        }

        // 5. Purpose rules (C4). Only a complete index may refuse (P3-31).
        $refused = $this->purposeRefusal($purpose, $phone, $user_id, $lang);
        if ($refused !== null) {
            return $refused;
        }

        // 6. Can a code SMS leave at all? (P3-13, P3-24; the gate raises the alert)
        $gate = $this->availability->gate($storefront_id);
        if ($gate['action'] === 'bypass') {
            return $this->bypassBody($purpose, $phone, $storefront_id);
        }
        if ($gate['action'] !== 'send') {
            return $this->error('unavailable', 'kwtsms.code.unavailable', $lang);
        }

        // 7. Per-phone cap (P3-34), read only: a code that goes out is counted below.
        $cap = $this->config->capPhoneHour();
        $stat = $this->limiter->peek('phone_h:' . $phone);
        $left = (int) $stat['window_start'] + self::HOUR - $this->now();
        if ((int) $stat['hits'] >= $cap && $left > 0) {
            $this->debug('otp.phone_limited', ['purpose' => $purpose]);

            return $this->wait($left, $lang);
        }

        // 8. The code: resend step and lockout are decided here (P3-19, P3-29).
        $issued = $this->otp->issue($purpose, $phone, $user_id, $session_hash, $ip, $storefront_id);
        if ($issued->status !== OtpResult::ISSUED) {
            return $this->wait($issued->retry_after, $lang);
        }

        // 9. The SMS. Nothing may leave a live code behind when it fails.
        try {
            $sent = $this->sender->sendCode($phone, $issued->code(), $purpose, $lang, $storefront_id, $user_id, $ip);
        } catch (\Throwable $e) {
            $this->otp->revoke($issued);

            throw $e;
        }
        $outcome = (string) ($sent['outcome'] ?? 'failed');
        $reason = (string) ($sent['reason'] ?? '');
        $class = $this->availability->classify($outcome, $reason);

        if ($class === CodeAvailability::SENT || $class === CodeAvailability::NUMBER) {
            // P3-10: the same answer for a sent code and for a number that was skipped (cap, coverage, country).
            $this->limiter->hit('phone_h:' . $phone, self::HOUR);

            return $this->sentBody($issued, $lang);
        }
        $this->otp->revoke($issued);
        if ($class === CodeAvailability::BUSY) {
            // The store hourly cap: never a bypass (P3-26).
            $to_hour = 3600 - ((int) date('i', $this->now()) * 60 + (int) date('s', $this->now()));
            $seconds = max(1, $to_hour);

            return [
                'ok' => false,
                'error' => 'busy',
                'retry_after' => $seconds,
                'message' => (string) __('kwtsms.code.busy', ['[minutes]' => (int) ceil($seconds / 60)], $lang),
            ];
        }

        // Not sendable (P3-13, P3-24): name the cause, cancel a deferred copy, then pause or continue.
        $this->availability->alert($this->availability->reasonFor($outcome, $reason));
        if (isset($sent['outbox_id'])) {
            $this->sender->cancelDeferred((int) $sent['outbox_id']);
        }
        if ($this->config->otpUnreachable() === 'continue') {
            return $this->bypassBody($purpose, $phone, $storefront_id);
        }

        return $this->error('unavailable', 'kwtsms.code.unavailable', $lang);
    }

    /**
     * @return array<string,mixed>|null A refusal body, or null when the purpose allows the request
     */
    private function purposeRefusal(string $purpose, string $phone, int $user_id, string $lang): ?array
    {
        if ($purpose === self::PURPOSE_PHONE_CHANGE) {
            if ($user_id <= 0) {
                return $this->error('login', 'kwtsms.code.login_required', $lang);
            }
            // The number the account already has never blocks (P3-21).
            if ($this->currentPhone($user_id) === $phone) {
                return null;
            }
        } elseif ($purpose !== self::PURPOSE_REGISTER) {
            return $this->error('unavailable', 'kwtsms.code.unavailable', $lang);
        }
        if ($this->index->enforcing() && $this->index->ownerOf($phone, $user_id) > 0) {
            return $this->error('in_use', 'kwtsms.code.in_use', $lang);
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function sentBody(OtpResult $issued, string $lang): array
    {
        $waits = array_values(array_filter(
            array_map('intval', $this->config->otpResendWaits()),
            static function (int $wait): bool {
                return $wait > 0;
            }
        ));
        $waits = $waits === [] ? [60] : $waits;
        $sends = (int) ($issued->step['prev_hits'] ?? 0) + 1;

        return [
            'ok' => true,
            'retry_after' => $waits[min($sends, count($waits)) - 1],
            'expires_in' => $issued->expires_in,
            'message' => (string) __('kwtsms.code.sent', ['[minutes]' => (int) ceil($issued->expires_in / 60)], $lang),
        ];
    }

    /**
     * "continue": the form goes on with the phone unverified. Remembered in the session for the code check of the form.
     *
     * @return array<string,mixed>
     */
    private function bypassBody(string $purpose, string $phone, int $storefront_id): array
    {
        $until = $this->now() + $this->config->otpExpirySeconds();
        if ($this->session_writer !== null) {
            call_user_func($this->session_writer, $purpose, $phone, $until);
        } else {
            $this->writeBypass($purpose, $phone, $until);
        }
        if ($this->log !== null) {
            $this->log->log(
                'info',
                'otp.bypass_unreachable',
                'a code feature went on without a code (otp_unreachable = continue)',
                ['purpose' => $purpose, 'storefront_id' => $storefront_id]
            );
        }

        return ['ok' => true, 'bypass' => true];
    }

    private function writeBypass(string $purpose, string $phone, int $until): void
    {
        $session = \Tygh\Tygh::$app['session'];
        $all = isset($session[self::BYPASS_KEY]) && is_array($session[self::BYPASS_KEY])
            ? $session[self::BYPASS_KEY] : [];
        $all[$purpose][$phone] = $until;
        $session[self::BYPASS_KEY] = $all;
    }

    private function currentPhone(int $user_id): string
    {
        if ($this->current_phone !== null) {
            return (string) call_user_func($this->current_phone, $user_id);
        }
        $user = function_exists('fn_get_user_info') ? fn_get_user_info($user_id, false) : [];
        if (!is_array($user) || $user === []) {
            return '';
        }

        return AccountPhone::canonicalOf($user, $user, $this->config->defaultCountryCode());
    }

    /**
     * @return array<string,mixed>
     */
    private function wait(int $seconds, string $lang): array
    {
        $seconds = max(1, $seconds);

        return [
            'ok' => false,
            'error' => 'wait',
            'retry_after' => $seconds,
            'message' => (string) __('kwtsms.code.wait', ['[seconds]' => (int) $seconds], $lang),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function error(string $error, string $langvar, string $lang): array
    {
        return $this->failure($error, (string) __($langvar, [], $lang));
    }

    /**
     * @return array<string,mixed>
     */
    private function failure(string $error, string $message): array
    {
        return ['ok' => false, 'error' => $error, 'message' => $message];
    }

    /**
     * Seconds until the fixed window of a bucket ends (the whole window when it cannot be read).
     */
    private function windowLeft(string $bucket, int $window_s): int
    {
        try {
            $start = (int) $this->limiter->peek($bucket)['window_start'];
        } catch (\Throwable $e) {
            return $window_s;
        }

        return $start <= 0 ? $window_s : max(1, $start + $window_s - $this->now());
    }

    private function now(): int
    {
        return $this->clock === null ? $this->limiter->now() : (int) call_user_func($this->clock);
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
}
