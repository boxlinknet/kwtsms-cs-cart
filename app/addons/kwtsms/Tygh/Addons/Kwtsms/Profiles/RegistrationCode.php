<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Profiles/RegistrationCode.php
 * CREATED:   2026-10-28 11:06 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The registration code check (SafatMall B1, P3-28): before a storefront account is created it needs a
 *            verified SMS code for the posted phone (register_code), and optionally a phone at all
 *            (register_phone_required). After the save it uses the code up and reports the verified phone.
 * WHY:       Task M1.10a. ProfileHooks::onUpdateUserPre() runs the code checks for every AREA C account creation
 *            (registration page, checkout add_profile, checkout.create_profile), so one check covers all three: a
 *            submit without a verified code is refused whatever the form (P3-28). A correct code is "verified", not
 *            "used", until the save succeeds (P3-5), so a form refused for another reason (a wrong password
 *            confirmation) can be submitted again with no new code.
 * USAGE:     new RegistrationCode($config, $otp, $default_cc); registered by Profiles\CodeChecks.
 *            check($ctx, $user_data): action add only. Order: phone required (own message); register_code OFF = go on;
 *            a verified code of this session for the canonical phone = go on; a posted kwtsms_code is verified with
 *            OtpService::verify() (wrong, locked, expired, none, ip_limited each have their text); no code posted:
 *            the "continue" bypass of CodeRequest (session key, until its time, only while otp_unreachable =
 *            continue) = go on; otherwise the P3-28 message.
 *            afterSave($ctx, $user_data): consume() the verified code (user id 0), returns the canonical phone it
 *            verified, '' when there was no code to use (bypass, or a feature OFF).
 * DEPENDS:   Settings\ConfigInterface, Codes\{OtpService,OtpResult,CodeRequest}, Phones\AccountPhone, __().
 */

namespace Tygh\Addons\Kwtsms\Profiles;

use Tygh\Addons\Kwtsms\Codes\CodeRequest;
use Tygh\Addons\Kwtsms\Codes\OtpResult;
use Tygh\Addons\Kwtsms\Codes\OtpService;
use Tygh\Addons\Kwtsms\Phones\AccountPhone;
use Tygh\Addons\Kwtsms\Phones\PhoneLock; // 2026-10-28 18:50 +03: Task M1.10a fix round 1 (review I3), task 596
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

final class RegistrationCode implements CodeCheckInterface
{
    public const PURPOSE = 'register';

    /** The form field the code box posts under. */
    public const POST_FIELD = 'kwtsms_code';

    /** @var ConfigInterface */
    private $config;

    /** @var OtpService */
    private $otp;

    /** @var callable fn(): string, the store default calling code */
    private $default_cc;

    /** @var callable|null fn(): string, the code the form posted ('' = none) */
    private $posted;

    /** @var callable|null fn(string $phone): int, the Unix time the "continue" bypass of $phone is valid until */
    private $skip_until;

    /** @var callable|null fn(): int, the Unix time */
    private $clock;

    /** @var PhoneLock|null 2026-10-28 18:50 +03: fix round 1 (I3), task 596. Null = no lock (unit rigs). */
    private $lock;

    /** @var array<string,bool> canonical phones this check holds a lock for */
    private $held = [];

    /** @var callable|null 2026-10-28 18:50 +03: fix round 1 (M2), task 596. fn(string $phone): void, clears the bypass of $phone */
    private $clear_skip;

    /**
     * @param callable      $default_cc fn(): string
     * @param callable|null $posted     null = the request field kwtsms_code
     * @param callable|null $skip_until null = the session key CodeRequest::BYPASS_KEY
     * @param callable|null $clock      null = time()
     */
    public function __construct(
        ConfigInterface $config,
        OtpService $otp,
        callable $default_cc,
        ?callable $posted = null,
        ?callable $skip_until = null,
        ?callable $clock = null,
        ?PhoneLock $lock = null, // 2026-10-28 18:50 +03: fix round 1 (I3, R8), task 596
        ?callable $clear_skip = null
    ) {
        $this->config = $config;
        $this->otp = $otp;
        $this->default_cc = $default_cc;
        $this->posted = $posted;
        $this->skip_until = $skip_until;
        $this->clock = $clock;
        $this->lock = $lock;
        $this->clear_skip = $clear_skip;
    }

    /** Whether this check does anything at all (a switch is ON). */
    public function enabled(): bool
    {
        return $this->config->registerCode() || $this->config->registerPhoneRequired();
    }

    public function check(array $ctx, array &$user_data): ?string
    {
        if ((string) ($ctx['action'] ?? '') !== 'add' || !$this->enabled()) {
            return null;
        }
        $lang = (string) ($ctx['lang'] ?? '');
        $raw = AccountPhone::field($user_data);
        if ($raw === '' && $this->config->registerPhoneRequired()) {
            return (string) __('kwtsms.register.error_phone_required', [], $lang);
        }
        if (!$this->config->registerCode()) {
            return null;
        }
        $phone = $this->canonical($user_data);
        $required = (string) __('kwtsms.register.code_required', [], $lang);
        if ($phone === '') {
            return $required; // nothing a code could be about
        }
        $session = (string) ($ctx['session_hash'] ?? '');
        // 2026-10-28 18:50 +03: Task M1.10a fix round 1 (review I3), task 596. The phone is locked from here to
        // the index write (or a
        // refusal; ProfileHooks calls release()), and the verified state is read INSIDE the lock, so parallel submits
        // of one verified code create exactly one account (P3-5), with C4 on or off.
        if ($this->lock !== null) {
            if (!$this->lock->acquire($phone)) {
                return (string) __('kwtsms.code.check_failed', [], $lang);
            }
            $this->held[$phone] = true;
        }
        if ($this->otp->hasVerified(self::PURPOSE, $phone, 0, $session)) {
            return null;
        }
        $code = $this->postedCode();
        if ($code === '') {
            return $this->bypassed($phone) ? null : $required;
        }
        $result = $this->otp->verify(self::PURPOSE, $phone, $code, 0, $session, (string) ($ctx['ip'] ?? ''));

        return $this->message($result, $phone, $lang);
    }

    public function afterSave(array $ctx, array $user_data): string
    {
        if ((string) ($ctx['action'] ?? '') !== 'add' || !$this->config->registerCode()) {
            return '';
        }
        $phone = $this->canonical($user_data);
        if ($phone === '') {
            return '';
        }

        $used = $this->otp->consume(self::PURPOSE, $phone, 0, (string) ($ctx['session_hash'] ?? ''));
        // 2026-10-28 18:50 +03: Task M1.10a fix round 1 (review M2), task 596. A "continue" bypass lets ONE
        // account through.
        $this->clearBypass($phone);

        return $used ? $phone : '';
    }

    /**
     * 2026-10-28 18:50 +03: Task M1.10a fix round 1 (review I3), task 596. Frees the phone locks this check took.
     * Called by ProfileHooks
     * after the index write and after every refusal. Never throws.
     */
    public function release(): void
    {
        if ($this->lock === null) {
            $this->held = [];

            return;
        }
        foreach (array_keys($this->held) as $phone) {
            try {
                $this->lock->release((string) $phone);
            } catch (\Throwable $e) {
                error_log('kwtsms: registration lock release failed: ' . get_class($e));
            }
        }
        $this->held = [];
    }

    private function clearBypass(string $phone): void
    {
        try {
            if ($this->clear_skip !== null) {
                call_user_func($this->clear_skip, $phone);

                return;
            }
            $session = \Tygh\Tygh::$app['session'];
            $all = isset($session[CodeRequest::BYPASS_KEY]) && is_array($session[CodeRequest::BYPASS_KEY])
                ? $session[CodeRequest::BYPASS_KEY] : [];
            if (isset($all[self::PURPOSE][$phone])) {
                unset($all[self::PURPOSE][$phone]);
                $session[CodeRequest::BYPASS_KEY] = $all;
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * The refusal text of a verify result, null for ok.
     */
    private function message(OtpResult $r, string $phone, string $lang): ?string
    {
        switch ($r->status) {
            case OtpResult::OK:
                return null;
            case OtpResult::WRONG:
                return (string) __('kwtsms.code.wrong', ['[n]' => (int) $r->attempts_left], $lang);
            case OtpResult::LOCKED:
                // 2026-10-28 18:39 +03: Task M1.10a, task 596. The number is inline (int) for the placeholder
                // audit of the package gates.
                return (string) __('kwtsms.code.locked', [
                    '[minutes]' => (int) max(1, ceil($r->retry_after / 60)),
                ], $lang);
            case OtpResult::IP_LIMITED:
                return (string) __('kwtsms.code.ip_limited', [], $lang);
            case OtpResult::EXPIRED:
                return (string) __('kwtsms.code.expired', [], $lang);
        }

        // none, malformed (and anything unknown): ask for the code again, naming the number it went to.
        // 2026-10-28 18:39 +03: htmlspecialchars() like fn_html_escape() (which a unit run does not have), inline for
        // the placeholder audit; a canonical phone is '+' and digits.
        return (string) __('kwtsms.code.enter_code', [
            '[phone]' => htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'),
        ], $lang);
    }

    /**
     * @param array<string,mixed> $user_data
     */
    private function canonical(array $user_data): string
    {
        return AccountPhone::canonicalOf($user_data, [], (string) call_user_func($this->default_cc));
    }

    private function postedCode(): string
    {
        if ($this->posted !== null) {
            return trim((string) call_user_func($this->posted));
        }
        $value = $_REQUEST[self::POST_FIELD] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * The "continue" bypass (P3-13): only while otp_unreachable is "continue", only for this phone, only until the
     * time CodeRequest wrote.
     */
    private function bypassed(string $phone): bool
    {
        if ($this->config->otpUnreachable() !== 'continue') {
            return false;
        }
        $now = $this->clock === null ? time() : (int) call_user_func($this->clock);
        if ($this->skip_until !== null) {
            return (int) call_user_func($this->skip_until, $phone) > $now;
        }
        try {
            $session = \Tygh\Tygh::$app['session'];
            $all = isset($session[CodeRequest::BYPASS_KEY]) && is_array($session[CodeRequest::BYPASS_KEY])
                ? $session[CodeRequest::BYPASS_KEY] : [];
        } catch (\Throwable $e) {
            return false;
        }

        return (int) ($all[self::PURPOSE][$phone] ?? 0) > $now;
    }
}
