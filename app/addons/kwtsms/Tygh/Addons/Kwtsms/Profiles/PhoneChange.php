<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Profiles/PhoneChange.php
 * CREATED:   2026-10-28 20:24 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The phone change code check (SafatMall B5, P3-17): a logged-in customer who changes the account phone
 *            in the storefront needs a verified SMS code sent to the NEW number. After the save a notice SMS goes to
 *            the OLD number (own switch phone_change_notice; no link, no new number, not covered by the F6 opt-out).
 * WHY:       Task M1.10b. ProfileHooks::onUpdateUserPre() runs the code checks for every storefront account save, so
 *            one check covers the profile page and the checkout profile editor. The code is bound to the session and
 *            to the user id (OtpService), so another customer's session cannot use it. A correct code is "verified"
 *            until the save succeeds (P3-5): a form refused for another reason can be submitted again with no new code.
 * USAGE:     new PhoneChange($config, $otp, $notice_sender, $default_cc, $actor); registered by Profiles\CodeChecks.
 *            check($ctx, $user_data): action update only, phone_change_code ON, the logged-in user is ctx user_id and
 *            the account phone changes (compared in canonical form, P3-21). Order: a verified code of this session for
 *            the new canonical phone = go on; a posted kwtsms_code is verified with OtpService::verify(); no code
 *            posted: the "continue" bypass of CodeRequest = go on, else the refusal text. Inside the post-order
 *            profile update (ctx checkout_profile) a changed phone without a code is put back to the stored value with
 *            a notice and the save goes on (P3-17: the order is placed already).
 *            afterSave($ctx, $user_data): consume() the verified code (user-bound); when it was used, sends the
 *            notice to the old phone remembered by check() (the update_profile hook does not carry it) and returns the
 *            new canonical phone for the one index writer.
 * DEPENDS:   Settings\ConfigInterface, Codes\{OtpService,OtpResult,CodeRequest,CodeSender}, Phones\AccountPhone, __().
 */

namespace Tygh\Addons\Kwtsms\Profiles;

use Tygh\Addons\Kwtsms\Codes\CodeRequest;
use Tygh\Addons\Kwtsms\Codes\OtpResult;
use Tygh\Addons\Kwtsms\Codes\OtpService;
use Tygh\Addons\Kwtsms\Phones\AccountPhone;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

final class PhoneChange implements CodeCheckInterface
{
    public const PURPOSE = 'phone_change';

    /** The form field the code box posts under. */
    public const POST_FIELD = 'kwtsms_code';

    /** @var ConfigInterface */
    private $config;

    /** @var OtpService */
    private $otp;

    /** @var callable fn(string $old_phone, int $user_id, string $lang, int $storefront_id): mixed, the notice send */
    private $notice;

    /** @var callable fn(): string, the store default calling code */
    private $default_cc;

    /** @var callable fn(): int, the logged-in user id of the request */
    private $actor;

    /** @var callable|null fn(): string, the code the form posted ('' = none) */
    private $posted;

    /** @var callable|null fn(string $phone): int, the Unix time the "continue" bypass of $phone is valid until */
    private $skip_until;

    /** @var callable|null fn(): int, the Unix time */
    private $clock;

    /** @var callable|null fn(string $type, string $text): void, a customer notice (null = fn_set_notification) */
    private $notify;

    /** @var callable|null fn(string $phone): void, clears the bypass of $phone */
    private $clear_skip;

    /** @var callable|null fn(string $ip, int $user_id): ?string, null = the notice may go, else why it is skipped */
    private $notice_gate;

    /** @var array<int,array{old:string,new:string,mode:string}> user id => old stored phone, new canonical phone, code|bypass|clear */
    private $pending = [];

    /**
     * @param callable      $notice     fn(string $old_phone, int $user_id, string $lang, int $storefront_id)
     * @param callable      $default_cc fn(): string
     * @param callable      $actor      fn(): int
     * @param callable|null $posted     null = the request field kwtsms_code
     * @param callable|null $skip_until null = the session key CodeRequest::BYPASS_KEY
     * @param callable|null $clock      null = time()
     * @param callable|null $notify     null = fn_set_notification()
     * @param callable|null $clear_skip null = the session key
     * @param callable|null $notice_gate 2026-10-29 20:05 +03: Task 596 (branch review I1, P3-R14). fn(string $ip,
     *                                   int $user_id): ?string; null = no limit
     */
    public function __construct(
        ConfigInterface $config,
        OtpService $otp,
        callable $notice,
        callable $default_cc,
        callable $actor,
        ?callable $posted = null,
        ?callable $skip_until = null,
        ?callable $clock = null,
        ?callable $notify = null,
        ?callable $clear_skip = null,
        ?callable $notice_gate = null
    ) {
        $this->config = $config;
        $this->otp = $otp;
        $this->notice = $notice;
        $this->default_cc = $default_cc;
        $this->actor = $actor;
        $this->posted = $posted;
        $this->skip_until = $skip_until;
        $this->clock = $clock;
        $this->notify = $notify;
        $this->clear_skip = $clear_skip;
        $this->notice_gate = $notice_gate;
    }

    /** Whether this check does anything at all (the switch is ON). */
    public function enabled(): bool
    {
        return $this->config->phoneChangeCode();
    }

    public function check(array $ctx, array &$user_data): ?string
    {
        $this->pending = [];
        if ((string) ($ctx['action'] ?? '') !== 'update' || !$this->enabled()) {
            return null;
        }
        $user_id = (int) ($ctx['user_id'] ?? 0);
        if ($user_id <= 1 || (int) call_user_func($this->actor) !== $user_id) {
            return null;
        }
        $stored = isset($ctx['stored_user']) && is_array($ctx['stored_user']) ? $ctx['stored_user'] : [];
        $change = $this->change($user_data, $stored, (string) ($ctx['stored'] ?? ''));
        if ($change === null) {
            return null;
        }
        $lang = (string) ($ctx['lang'] ?? '');
        $old = (string) ($ctx['stored'] ?? '');
        // 2026-10-28 22:31 +03: Task M1.10b fix round 1 (review I1, ruling P3-R13), task 596. A clear needs no code
        // (nothing to verify) but the old number is told after the save. Inside the post-order update the phone is
        // put back instead.
        if ($change['clear']) {
            if (!empty($ctx['checkout_profile'])) {
                $this->putBack($user_data, ['phone', 'b_phone'], $stored);

                return null;
            }
            $this->pending[$user_id] = ['old' => $old, 'new' => '', 'mode' => 'clear'];

            return null;
        }
        $phone = $change['new'];
        if ($phone === '') {
            // 2026-10-28 22:32 +03: fix round 1 (review M1), task 596: through afterRefusal(), so the post-order update
            // resets only the phone field and goes on.
            return $this->afterRefusal(
                $ctx,
                $user_data,
                $stored,
                (string) __('kwtsms.phone_change.invalid', [], $lang),
                $lang,
                $change['field']
            ); // a code cannot go to this value
        }
        $session = (string) ($ctx['session_hash'] ?? '');
        $mode = 'code';
        $verified = $this->otp->hasVerified(self::PURPOSE, $phone, $user_id, $session);
        if (!$verified) {
            $code = $this->postedCode();
            if ($code !== '') {
                $result = $this->otp->verify(
                    self::PURPOSE,
                    $phone,
                    $code,
                    $user_id,
                    $session,
                    (string) ($ctx['ip'] ?? '')
                );
                $refusal = $this->message($result, $phone, $lang);
                if ($refusal !== null) {
                    return $this->afterRefusal($ctx, $user_data, $stored, $refusal, $lang, $change['field']);
                }
            } elseif ($this->bypassed($phone)) {
                $mode = 'bypass';
            } else {
                return $this->afterRefusal(
                    $ctx,
                    $user_data,
                    $stored,
                    (string) __('kwtsms.phone_change.code_required', [], $lang),
                    $lang,
                    $change['field']
                );
            }
        }
        $this->pending[$user_id] = ['old' => $old, 'new' => $phone, 'mode' => $mode];

        return null;
    }

    public function afterSave(array $ctx, array $user_data): string
    {
        if ((string) ($ctx['action'] ?? '') !== 'update' || !$this->enabled()) {
            return '';
        }
        $user_id = (int) ($ctx['user_id'] ?? 0);
        if (!isset($this->pending[$user_id])) {
            return ''; // check() found no change to verify in this request
        }
        $pending = $this->pending[$user_id];
        unset($this->pending[$user_id]);

        $used = false;
        if ($pending['mode'] === 'code') {
            $used = $this->otp->consume(
                self::PURPOSE,
                $pending['new'],
                $user_id,
                (string) ($ctx['session_hash'] ?? '')
            );
        }
        if ($pending['new'] !== '') {
            $this->clearBypass($pending['new']);
        }
        // 2026-10-28 22:31 +03: fix round 1 (review I1, ruling P3-R13), task 596. The notice follows every save that
        // changed the account phone: a code that was used, a "continue" bypass save, a clear. A code lost to a
        // parallel save sends nothing (the save that used it sends the notice).
        if ($pending['mode'] === 'code' && !$used) {
            return '';
        }
        if ($this->config->phoneChangeNotice() && trim($pending['old']) !== '') {
            try {
                // 2026-10-29 20:05 +03: Task 596 (branch review I1, ruling P3-R14). The notice is an SMS path: it
                // counts against the per-IP buckets and 1 per account per hour; a skip still saves the change.
                if (
                    $this->notice_gate !== null
                    && call_user_func($this->notice_gate, (string) ($ctx['ip'] ?? ''), $user_id) !== null
                ) {
                    return $pending['mode'] === 'code' ? $pending['new'] : '';
                }
                call_user_func(
                    $this->notice,
                    $pending['old'],
                    $user_id,
                    (string) ($ctx['lang'] ?? ''),
                    (int) ($ctx['storefront_id'] ?? 0)
                );
            } catch (\Throwable $e) {
                error_log('kwtsms: phone change notice failed: ' . get_class($e) . ': ' . $e->getMessage()
                    . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
            }
        }

        return $pending['mode'] === 'code' ? $pending['new'] : '';
    }

    /**
     * The editor of the checkout page (checkout.update_profile) has no refusal hook and no code field: a changed
     * account phone is refused there with the "use your profile page" text. Null when the phone does not change.
     *
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $user_data The posted profile data
     */
    public function editorRefusal(array $ctx, array $user_data): ?string
    {
        if (!$this->enabled()) {
            return null;
        }
        $user_id = (int) ($ctx['user_id'] ?? 0);
        if ($user_id <= 1 || (int) call_user_func($this->actor) !== $user_id) {
            return null;
        }
        $stored = isset($ctx['stored_user']) && is_array($ctx['stored_user']) ? $ctx['stored_user'] : [];
        if ($this->change($user_data, $stored, (string) ($ctx['stored'] ?? '')) === null) {
            return null;
        }

        return (string) __('kwtsms.phone_change.profile_only', [], (string) ($ctx['lang'] ?? ''));
    }

    /**
     * What this save does to the account phone: null when it does not change, else the new canonical phone ('' when
     * it is not a mobile), whether the save clears a stored phone, and the posted field that carries the account
     * phone. The stored phone and b_phone are overlaid with the posted ones, so a form that shows only one of them
     * leaves the other as it is.
     *
     * @param array<string,mixed> $user_data
     * @param array<string,mixed> $stored
     *
     * @return array{new:string,clear:bool,field:string}|null
     */
    private function change(array $user_data, array $stored, string $stored_phone): ?array
    {
        $after = ['phone' => (string) ($stored['phone'] ?? ''), 'b_phone' => (string) ($stored['b_phone'] ?? '')];
        foreach (['phone', 'b_phone'] as $field) {
            if (isset($user_data[$field]) && is_string($user_data[$field])) {
                $after[$field] = $user_data[$field];
            }
        }
        $after_phone = AccountPhone::field($after);
        $before = $stored === [] ? $stored_phone : AccountPhone::field($stored);
        if ($after_phone === '') {
            // 2026-10-28 22:31 +03: fix round 1 (review I1, ruling P3-R13), task 596: clearing is a change.
            return trim($before) === '' ? null : ['new' => '', 'clear' => true, 'field' => 'phone'];
        }
        $cc = (string) call_user_func($this->default_cc);
        $field = trim($after['phone']) !== '' ? 'phone' : 'b_phone';
        $hint = AccountPhone::hint($field, $user_data, $stored, $cc);
        if (!AccountPhone::changed($after_phone, $before, $hint)) {
            return null;
        }
        // 2026-10-28 20:25 +03: Task M1.10b, task 596. The posted countries stay in the data the calling code is
        // read from.
        $merged = $after + $user_data;

        return ['new' => AccountPhone::canonicalOf($merged, $stored, $cc), 'clear' => false, 'field' => $field];
    }

    /**
     * A refusal. Inside the post-order profile update the order is placed already: only the account phone field goes
     * to the stored value (P3-R11), the customer is told, and the save goes on (P3-17). Everywhere else the save is
     * refused.
     *
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $user_data
     * @param array<string,mixed> $stored
     */
    private function afterRefusal(
        array $ctx,
        array &$user_data,
        array $stored,
        string $refusal,
        string $lang,
        string $field
    ): ?string {
        if (empty($ctx['checkout_profile'])) {
            return $refusal;
        }
        $this->putBack($user_data, [$field], $stored);
        $this->tell('W', (string) __('kwtsms.phone_change.order_kept', [], $lang));

        return null;
    }

    /**
     * 2026-10-28 22:31 +03: Task M1.10b fix round 1, task 596. Puts the named posted phone fields back.
     *
     * @param array<string,mixed> $user_data
     * @param string[]            $fields
     * @param array<string,mixed> $stored
     */
    private function putBack(array &$user_data, array $fields, array $stored): void
    {
        foreach ($fields as $field) {
            if (isset($user_data[$field])) {
                $user_data[$field] = (string) ($stored[$field] ?? '');
            }
        }
    }

    private function tell(string $type, string $text): void
    {
        if ($this->notify !== null) {
            call_user_func($this->notify, $type, $text);

            return;
        }
        if (function_exists('fn_set_notification')) {
            fn_set_notification($type, (string) __($type === 'W' ? 'warning' : 'error'), fn_html_escape($text));
        }
    }

    /**
     * The refusal text of a verify result, null for ok (same texts as the registration code).
     */
    private function message(OtpResult $r, string $phone, string $lang): ?string
    {
        switch ($r->status) {
            case OtpResult::OK:
                return null;
            case OtpResult::WRONG:
                return (string) __('kwtsms.code.wrong', ['[n]' => (int) $r->attempts_left], $lang);
            case OtpResult::LOCKED:
                return (string) __('kwtsms.code.locked', [
                    '[minutes]' => (int) max(1, ceil($r->retry_after / 60)),
                ], $lang);
            case OtpResult::IP_LIMITED:
                return (string) __('kwtsms.code.ip_limited', [], $lang);
            case OtpResult::EXPIRED:
                return (string) __('kwtsms.code.expired', [], $lang);
        }

        // none, malformed (and anything unknown): ask for the code again, naming the number it went to.
        return (string) __('kwtsms.code.enter_code', [
            '[phone]' => htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'),
        ], $lang);
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
}
