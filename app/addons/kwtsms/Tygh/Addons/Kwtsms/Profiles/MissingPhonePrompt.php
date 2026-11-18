<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Profiles/MissingPhonePrompt.php
 * CREATED:   2026-11-16 23:55 +03
 * TASK:      596 (M3.21, G9, P3-79)
 * AUTHOR:    kwtSMS
 * WHAT:      Asks a signed-in storefront customer who has no mobile number on the account to add one, once per
 *            session: decides who is asked (shouldPrompt), remembers "asked" and "later" per user in the session, and
 *            saves the number after a verified SMS code (save).
 * WHY:       Task M3.21 (SafatMall G9). A customer without a phone gets no order SMS. The number is added through the
 *            same checks as a phone change on the profile page: the existing normaliser (C3), the existing code check
 *            (OtpService, bound to the session and the user id, wrong-attempt counters, per-IP limits), the existing
 *            uniqueness rule (C4, phone index) and the existing writer (fn_update_user, so the update_profile hook
 *            writes the phone index with the verified flag, P3-49).
 * USAGE:     $p = new MissingPhonePrompt($config, $otp, $normalise, $owner, $account, $state_get, $state_set,
 *                                        $updater, $clock);
 *            $p->shouldPrompt($auth, $controller, $mode, $ajax, $method)   true = redirect this page view to the page
 *            $p->markShown($user_id) / $p->later($user_id)                 session marks (per user)
 *            $p->eligible($auth)                                           the add page itself (no controller list)
 *            $p->save($user_id, $raw_phone, $raw_code, $session_hash, $ip) ['status' => ok|wrong|locked|invalid|in_use|
 *                                                                            off|error, + attempts_left, retry_after]
 *            Never prompted: a guest, an administrator, a vendor administrator (user type other than C), user 1, an
 *            AJAX or non-GET request, the pages of the add flow, sign-in, profile, checkout, orders and payment
 *            callbacks, a session that was asked already, a user who chose "later" (7 days), a store with the switch
 *            missing_phone_prompt or phone_change_code OFF (the code is the proof of the number).
 * DEPENDS:   Settings\ConfigInterface, Codes\{OtpService,OtpResult}; seams for the normaliser, the uniqueness owner
 *            lookup, the account read, the session and the core write (see the constructor).
 */

namespace Tygh\Addons\Kwtsms\Profiles;

use Tygh\Addons\Kwtsms\Auth\ReturnUrl;
use Tygh\Addons\Kwtsms\Codes\OtpResult;
use Tygh\Addons\Kwtsms\Codes\OtpService;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

final class MissingPhonePrompt
{
    /** The code purpose: the add page uses the phone change purpose (same buckets, same CodeRequest endpoint). */
    public const PURPOSE = 'phone_change';

    /** The session key of the per-user marks. */
    public const SESSION_KEY = 'kwtsms_phone_prompt';

    /** 2026-11-17 00:20 +03: Task M3.21 fix round 1 (I2), task 596. A customer with a phone is not looked up again for this long. */
    public const HAS_PHONE_SECONDS = 3600;

    /** "Later" lasts this long (seconds). */
    public const LATER_SECONDS = 604800;

    /**
     * Controllers that are never redirected: the add flow itself, the code endpoint, every sign-in and two-step page,
     * the profile pages (the customer can add the number there), and the pages money and payment providers depend on.
     *
     * @var string[]
     */
    public const EXEMPT_CONTROLLERS = [
        'kwtsms_phone', 'kwtsms_code', 'kwtsms_auth', 'kwtsms_twofa', 'auth', 'profiles', 'checkout', 'cart',
        'orders', 'payment_notification', 'image_verification', 'tools', 'image', 'commerceml', 'sitemap', 'api',
    ];

    /** @var ConfigInterface */
    private $config;

    /** @var OtpService */
    private $otp;

    /** @var callable fn(string $raw): array{ok:bool,phone:string,reason:string}, the C3 normaliser */
    private $normalise;

    /** @var callable fn(string $phone, int $user_id): int, the id of another account that holds the phone (C4), 0 = free */
    private $owner;

    /** @var callable fn(int $user_id): ?array{user_type:string,status:string,phone:string}, null = no such account */
    private $account;

    /** @var callable fn(): array, the marks of the session */
    private $state_get;

    /** @var callable fn(array $marks): void */
    private $state_set;

    /** @var callable fn(int $user_id, string $phone): bool, the core write of the account phone */
    private $updater;

    /** @var callable|null fn(): int */
    private $clock;

    public function __construct(
        ConfigInterface $config,
        OtpService $otp,
        callable $normalise,
        callable $owner,
        callable $account,
        callable $state_get,
        callable $state_set,
        callable $updater,
        ?callable $clock = null
    ) {
        $this->config = $config;
        $this->otp = $otp;
        $this->normalise = $normalise;
        $this->owner = $owner;
        $this->account = $account;
        $this->state_get = $state_get;
        $this->state_set = $state_set;
        $this->updater = $updater;
        $this->clock = $clock;
    }

    /** The feature is on: its own switch, and the phone change code (the proof of the number) is on too. */
    public function enabled(): bool
    {
        return $this->config->missingPhonePrompt() && $this->config->phoneChangeCode();
    }

    /**
     * Whether $auth is a signed-in customer the feature applies to (no page, verb or session state is looked at).
     *
     * @param array<string,mixed> $auth
     */
    public function eligible(array $auth): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $user_id = isset($auth['user_id']) ? (int) $auth['user_id'] : 0;
        if ($user_id <= 1) {
            return false;
        }
        // 2026-11-17 00:20 +03: Task M3.21 fix round 1 (I1), task 596. An administrator who used "login as customer"
        // (core fn_fill_auth: act_as_user) is never prompted and cannot bind a phone to the customer's account.
        if (!empty($auth['act_as_user'])) {
            return false;
        }
        // The session says customer; the account row is read again for the phone below.
        return (string) ($auth['user_type'] ?? '') === 'C';
    }

    /**
     * True when this page view is redirected to the add page. A GET page view of a signed-in customer without a
     * phone, once per session, never an AJAX call and never a page of the exempt list.
     *
     * @param array<string,mixed> $auth
     */
    public function shouldPrompt(
        array $auth,
        string $controller,
        string $mode,
        bool $ajax,
        string $method = 'GET'
    ): bool {
        if ($ajax || strtoupper($method) !== 'GET' || !$this->eligible($auth)) {
            return false;
        }
        if (in_array(strtolower($controller), self::EXEMPT_CONTROLLERS, true)) {
            return false;
        }
        // logout and the like are modes of an exempt controller; a mode "logout" anywhere is exempt as well.
        if ($mode === 'logout') {
            return false;
        }
        $user_id = (int) $auth['user_id'];
        $mark = $this->marks()[$user_id] ?? [];
        if (!empty($mark['shown']) || (int) ($mark['later_until'] ?? 0) > $this->now()) {
            return false;
        }
        // 2026-11-17 00:20 +03: Task M3.21 fix round 1 (I2), task 596. A customer found WITH a phone is not looked up
        // again for HAS_PHONE_SECONDS (one cheap read per hour and session, not one per page view).
        if ((int) ($mark['has_phone_until'] ?? 0) > $this->now()) {
            return false;
        }
        $account = $this->readAccount($user_id);
        if ($account !== null && !$this->needsPhone($account)) {
            $this->mark($user_id, ['has_phone_until' => $this->now() + self::HAS_PHONE_SECONDS]);
        }

        return $account !== null && $this->needsPhone($account);
    }

    /**
     * 2026-11-17 00:20 +03: Task M3.21 fix round 1 (I3), task 596. Whether the request is a real top-level page
     * navigation: Accept names text/html ("*" slash "*" alone is a fetch, an image or curl), Sec-Fetch-Mode and
     * Sec-Fetch-Dest, when the browser sends them, say navigate and document, and it is not a prefetch.
     *
     * @param array<string,mixed> $server $_SERVER
     */
    public static function isPageNavigation(array $server): bool
    {
        if (stripos((string) ($server['HTTP_ACCEPT'] ?? ''), 'text/html') === false) {
            return false;
        }
        $mode = strtolower(trim((string) ($server['HTTP_SEC_FETCH_MODE'] ?? '')));
        if ($mode !== '' && $mode !== 'navigate') {
            return false;
        }
        $dest = strtolower(trim((string) ($server['HTTP_SEC_FETCH_DEST'] ?? '')));
        if ($dest !== '' && $dest !== 'document') {
            return false;
        }
        foreach (['HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ'] as $key) {
            if (
                stripos((string) ($server[$key] ?? ''), 'prefetch') !== false
                || stripos((string) ($server[$key] ?? ''), 'preview') !== false
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * 2026-11-17 00:20 +03: Task M3.21 fix round 2 (M2), task 596. The return address kept on the add page: core's
     * config.current_url ("index.php?dispatch=...", relative to the store root, the same with SEO URLs on or off and
     * under
     * a sub-directory such as /shop/, where fn_url() / fn_redirect() add the path), through ReturnUrl::safe (same host
     * only, no scheme-relative or external address). '' = none.
     */
    public static function returnTarget(string $current_url, string $current_host, bool $secure): string
    {
        $back = ReturnUrl::safe($current_url, $current_host, '', $secure);
        // The add page and the exempt flows are never a return target (no bounce back into the prompt).
        if (
            $back === ''
            || preg_match('/[?&]dispatch=(kwtsms_phone|kwtsms_code|kwtsms_auth|kwtsms_twofa)[.&]/', $back . '&') === 1
        ) {
            return '';
        }

        return $back;
    }

    /** The one redirect of this session for this user is spent. */
    public function markShown(int $user_id): void
    {
        $this->mark($user_id, ['shown' => $this->now()]);
    }

    /** "Later": no more prompts for this user for LATER_SECONDS. */
    public function later(int $user_id): void
    {
        $this->mark($user_id, ['shown' => $this->now(), 'later_until' => $this->now() + self::LATER_SECONDS]);
    }

    /** Whether this account has no phone and may be asked. */
    public function accountNeedsPhone(int $user_id): bool
    {
        $account = $this->readAccount($user_id);

        return $account !== null && $this->needsPhone($account);
    }

    /**
     * Saves the number after a verified code. The user id comes from the session auth only (the caller), the code is
     * bound to the session hash and the user id, a wrong or locked code stops here, and a number another account
     * holds (C4) is refused after the code proved the typed number is the customer's own (nothing is told about a
     * number whose code was not proved).
     *
     * @return array{status:string,attempts_left?:int,retry_after?:int}
     */
    public function save(
        int $user_id,
        string $raw_phone,
        #[\SensitiveParameter]
        string $raw_code,
        string $session_hash,
        string $ip
    ): array {
        if (!$this->enabled() || $user_id <= 1) {
            return ['status' => 'off'];
        }
        $account = $this->readAccount($user_id);
        if ($account === null || !$this->needsPhone($account)) {
            return ['status' => 'off'];
        }
        $check = (array) call_user_func($this->normalise, $raw_phone);
        $phone = !empty($check['ok']) ? (string) ($check['phone'] ?? '') : '';
        if ($phone === '') {
            return ['status' => 'invalid'];
        }
        $result = $this->otp->verify(self::PURPOSE, $phone, $raw_code, $user_id, $session_hash, $ip);
        switch ($result->status) {
            case OtpResult::OK:
                break;
            case OtpResult::LOCKED:
            case OtpResult::IP_LIMITED:
                return ['status' => 'locked', 'retry_after' => (int) $result->retry_after];
            default:
                // wrong, malformed, expired, none: one answer, with the tries left when the engine counted them.
                // 2026-11-17 12:06 +03: tester 4 fix, task 596. NONE (no code requested) and EXPIRED add a
                // 'reason' so the page says "request a code first" or "expired", not "0 tries left" (none used).
                $answer = ['status' => 'wrong', 'attempts_left' => (int) $result->attempts_left];
                if ($result->status === OtpResult::NONE || $result->status === OtpResult::EXPIRED) {
                    $answer['reason'] = $result->status;
                }

                return $answer;
        }
        if ((int) call_user_func($this->owner, $phone, $user_id) > 0) {
            return ['status' => 'in_use'];
        }
        if (!(bool) call_user_func($this->updater, $user_id, $phone)) {
            return ['status' => 'error'];
        }

        return ['status' => 'ok'];
    }

    /**
     * @param array{user_type:string,status:string,phone:string} $account
     */
    private function needsPhone(array $account): bool
    {
        return $account['user_type'] === 'C' && $account['status'] === 'A' && trim($account['phone']) === '';
    }

    /**
     * @return array{user_type:string,status:string,phone:string}|null
     */
    private function readAccount(int $user_id): ?array
    {
        $row = call_user_func($this->account, $user_id);
        if (!is_array($row)) {
            return null;
        }

        return [
            'user_type' => (string) ($row['user_type'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
        ];
    }

    /**
     * @return array<int,array<string,int>>
     */
    private function marks(): array
    {
        $all = call_user_func($this->state_get);

        return is_array($all) ? $all : [];
    }

    /**
     * @param array<string,int> $mark
     */
    private function mark(int $user_id, array $mark): void
    {
        if ($user_id <= 1) {
            return;
        }
        $all = $this->marks();
        $all[$user_id] = $mark + (array) ($all[$user_id] ?? []);
        call_user_func($this->state_set, $all);
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }
}
