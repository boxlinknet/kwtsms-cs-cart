<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Profiles/ProfileHooks.php
 * CREATED:   2026-10-22 19:08 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Handler of the core hook update_profile: after a customer registers on the storefront (or at checkout) it
 *            dispatches the add-on's own event kwtsms.customer_registered (the admin registration alert).
 * WHY:       Phase 2 Task A6 (SafatMall F5 part, P2-7). Core has no registration event for the administrator that
 *            fires for every registration (profile.added only fires when profiles need approval). The welcome SMS
 *            (profile.created.c) is core's own event with the SMS transport attached by the declaration; only the
 *            alert needs this hook. An administrator creating a customer in the admin panel (AREA A) triggers
 *            nothing, and neither do updates, administrators or vendor users. The password fields are removed from
 *            the data before the dispatch: the SMS transport and every log below it must never see them.
 * USAGE:     new ProfileHooks($dispatch, $storefront_id, $area); onUpdateProfile($action, $user_data, $current).
 *            $dispatch(string $event_id, array $data, string $provider_id): void goes through event.dispatcher with
 *            default rules (the stored switches decide); $storefront_id(): int; $area(): string (AREA).
 *            2026-10-23 00:52 +03, Task A10, task 596 (SafatMall F6): the same hook saves the customer's choice of
 *            order-status SMS (form field user_data[kwtsms_order_sms], Y or N) into Profiles\CustomerPrefs. Only a
 *            storefront request (AREA C) of the customer themself writes it: an administrator editing the profile
 *            in the backend, or a request whose logged-in user is another account, never flips it.
 *            2026-10-24 17:13 +03, Task M1.6 (P3-31, P3-33), task 596: the same hook is the ONE writer of the phone
 *            index (kwtsms_phone_index): while PhoneIndex::active() it upserts the saved canonical account phone of a
 *            customer
 *            once per save, verified only when $code_checks->afterSave() returns that very phone. M1.9, M1.10a and
 *            M1.10b never write the index. An internal error here never breaks the core save (logged).
 *            2026-10-25 04:36 +03, Task M1.9 (SafatMall C3, C4; P3-15, P3-21, P3-30), task 596: onUpdateUserPre()
 *            (hook update_user_pre) and onPrePlaceOrder() (hook pre_place_order) check a new or changed phone before
 *            it is saved: C3 country check with the canonical rewrite (forms and checkout), C4 one account per phone
 *            (forms only, only while PhoneIndex::enforcing()), then the code check seam. The admin panel only
 *            warns. onUpdateProfile() releases the phone lock after the index write.
 * DEPENDS:   Container wiring in ServiceProvider ('kwtsms.profile_hooks'), func.php fn_kwtsms_update_profile(),
 *            Profiles\CustomerPrefs, Phones\{PhoneIndex,AccountPhone,PhoneRules,PhoneLock}, Settings\ConfigInterface,
 *            Profiles\CodeCheckInterface.
 */

namespace Tygh\Addons\Kwtsms\Profiles;

use Tygh\Addons\Kwtsms\Phones\AccountPhone;
use Tygh\Addons\Kwtsms\Phones\PhoneIndex;
use Tygh\Addons\Kwtsms\Phones\PhoneLock;
use Tygh\Addons\Kwtsms\Phones\PhoneRules;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

final class ProfileHooks
{
    public const EVENT_REGISTERED = 'kwtsms.customer_registered';

    /** Keys of the user data that may hold a password; never dispatched. */
    private const PASSWORD_KEYS = ['password', 'password1', 'password2', 'current_password', 'confirm_password'];

    /** @var callable */
    private $dispatch;

    /** @var callable */
    private $storefront_id;

    /** @var callable */
    private $area;

    /** @var CustomerPrefs|null 2026-10-23 00:52 +03: Task A10 (R8), task 596. Null = no opt-out storage (unit rigs). */
    private $prefs;

    /** @var callable|null 2026-10-23 00:52 +03: Task A10, task 596. fn(): int, the logged-in user id of the request. */
    private $actor;

    /** @var PhoneIndex|null 2026-10-24 17:13 +03: Task M1.6 (P3-33), task 596. Null = no index (unit rigs). */
    private $index;

    /** @var CodeCheckInterface|null 2026-10-24 17:13 +03: Task M1.6, task 596. Null = NoCodeCheck. */
    private $code_checks;

    /** @var callable|null 2026-10-24 17:13 +03: Task M1.6, task 596. fn(): string, the store default calling code. */
    private $default_cc;

    /**
     * @var callable|null 2026-10-24 17:13 +03: Task M1.6, task 596.
     *                    fn(): array{session_hash:string,ip:string,lang:string}
     */
    private $request_context;

    /**
     * @var callable|null 2026-10-24 18:45 +03: M1.6 fix round 1 (I2), task 596. fn(int $user_id): ?array{user_type,
     *                    phone,b_phone,b_country}, the saved account as the database holds it (null = no such user)
     */
    private $account_loader;

    /** @var callable|null 2026-10-24 18:45 +03: M1.6 fix round 1 (I2), task 596. fn(): bool, inside fn_update_user */
    private $nested;

    /** The three profile fields that hold a phone (2026-10-25 04:36 +03: Task M1.9, task 596). */
    private const PHONE_FIELDS = ['phone', 'b_phone', 's_phone'];

    /** @var ConfigInterface|null 2026-10-25 04:36 +03: Task M1.9 (R8), task 596. Null = no phone checks (unit rigs). */
    private $config;

    /** @var PhoneRules|null 2026-10-25 04:36 +03: Task M1.9, task 596. */
    private $rules;

    /** @var PhoneLock|null 2026-10-25 04:36 +03: Task M1.9, task 596. */
    private $lock;

    /**
     * @var callable|null 2026-10-25 04:36 +03: Task M1.9, task 596. fn(int $user_id, int $profile_id):
     *                    ?array{user_type,phone,b_phone,s_phone,b_country,s_country}, the account as the database
     *                    holds it NOW (before the save), the profile being edited (0 = the primary one); null = no
     *                    such user
     */
    private $stored_loader;

    /** @var callable|null 2026-10-25 04:36 +03: Task M1.9, task 596. fn(string $type E|W, string $text): void */
    private $notify;

    /** @var callable|null 2026-10-25 04:36 +03: Task M1.9, task 596. fn(): bool, inside fn_checkout_update_user_profile */
    private $checkout_profile;

    /**
     * @var array<string,bool> 2026-10-25 07:11 +03: M1.9 fix round 1 (M3), task 596. The canonical phones this
     *                         instance holds a PhoneLock for: freed by exactly the key that was acquired.
     */
    private $held = [];

    public function __construct(
        callable $dispatch,
        callable $storefront_id,
        callable $area,
        ?CustomerPrefs $prefs = null,
        ?callable $actor = null,
        ?PhoneIndex $index = null, // 2026-10-24 17:13 +03: Task M1.6 (R8), task 596
        ?CodeCheckInterface $code_checks = null,
        ?callable $default_cc = null,
        ?callable $request_context = null,
        ?callable $account_loader = null, // 2026-10-24 18:45 +03: M1.6 fix round 1 (I2), task 596
        ?callable $nested = null,
        ?ConfigInterface $config = null, // 2026-10-25 04:36 +03: Task M1.9 (R8), task 596
        ?PhoneRules $rules = null,
        ?PhoneLock $lock = null,
        ?callable $stored_loader = null,
        ?callable $notify = null,
        ?callable $checkout_profile = null
    ) {
        $this->config = $config;
        $this->rules = $rules;
        $this->lock = $lock;
        $this->stored_loader = $stored_loader;
        $this->notify = $notify;
        $this->checkout_profile = $checkout_profile;
        $this->account_loader = $account_loader;
        $this->nested = $nested;
        $this->index = $index;
        $this->code_checks = $code_checks ?? new NoCodeCheck();
        $this->default_cc = $default_cc;
        $this->request_context = $request_context;
        $this->prefs = $prefs;
        $this->actor = $actor;
        $this->dispatch = $dispatch;
        $this->storefront_id = $storefront_id;
        $this->area = $area;
    }

    /**
     * @param string              $action            add or update
     * @param array<string,mixed> $user_data
     * @param array<string,mixed> $current_user_data
     */
    public function onUpdateProfile(string $action, array $user_data, array $current_user_data): void
    {
        $this->saveOrderSmsChoice($action, $user_data);
        $this->writePhoneIndex($action, $user_data, $current_user_data);
        $this->releaseHeld();
        if ($action !== 'add' || (string) call_user_func($this->area) !== 'C') {
            return;
        }
        if ((string) ($user_data['user_type'] ?? '') !== 'C') {
            return;
        }
        $user_id = (int) ($user_data['user_id'] ?? 0);
        if ($user_id <= 0) {
            return;
        }
        foreach (self::PASSWORD_KEYS as $key) {
            unset($user_data[$key]);
        }

        try {
            call_user_func(
                $this->dispatch,
                self::EVENT_REGISTERED,
                ['user_data' => $user_data, 'storefront_id' => max(0, (int) call_user_func($this->storefront_id))],
                self::EVENT_REGISTERED . '.' . $user_id
            );
        } catch (\Throwable $e) {
            error_log('kwtsms: registration alert failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    /**
     * 2026-10-25 04:36 +03: Task M1.9 (SafatMall C3, C4; P3-15, P3-21, P3-30), task 596. Hook update_user_pre, before
     * core validates or saves an account. In the storefront (AREA C), for a customer account (user type C, or a new
     * account) other than user 1, in this order, the first refusal stops the rest:
     *   1. C3 (phone_country_check ON): every phone field that is new or changed against the stored account (a
     *      registration: all of them) goes through PhoneRules::check(); a refusal sets $can_update = false with an
     *      error notice, an accepted value is rewritten to its canonical form; an unchanged value is left as it is.
     *   2. C4 (PhoneIndex::enforcing(), that is phone_unique ON and the backfill done): the account phone (phone,
     *      else b_phone) that is new or changed is looked up under PhoneLock; another account holding it refuses
     *      with the "number in use" text, the same text for every owner and without naming one. The lookup excludes
     *      the saved account itself, and the stored account is re-read from the database, so a customer is never
     *      refused because of its own phone.
     *   3. The code check seam (CodeCheckInterface::check()); a failure in it refuses (fail closed).
     * An internal error in 1 or 2 lets the save through (logged). In the admin panel (AREA A, which includes the
     * vendor panel) nothing is refused: a phone C3 would refuse shows a warning. Never throws.
     *
     * @param array<string,mixed> $user_data The posted data (the phone fields are rewritten by reference)
     */
    public function onUpdateUserPre(int $user_id, array &$user_data, bool &$can_update): void
    {
        if ($user_id === 1 || $user_id < 0) {
            return;
        }
        $area = (string) call_user_func($this->area);
        if ($area !== 'C' && $area !== 'A') {
            return;
        }
        $c3 = $this->config !== null && $this->rules !== null && $this->config->phoneCountryCheck();
        if ($area === 'A') {
            if ($c3) {
                $this->warnAdmin($user_id, $user_data);
            }

            return;
        }
        $c4 = $this->index !== null && $this->config !== null && $this->rules !== null && $this->index->enforcing();
        // 2026-10-28 11:06 +03: Task M1.10a, task 596. The composite CodeChecks is always wired; it counts only
        // while a check is ON.
        $codes = $this->codesActive();
        if (!$c3 && !$c4 && !$codes) {
            return;
        }
        $lang = $this->lang();
        $stored = [];
        try {
            if ($user_id > 0) {
                $stored = $this->storedAccount($user_id, $this->intOf($user_data['profile_id'] ?? 0)) ?? [];
            }
            $type = $user_id > 0 ? (string) ($stored['user_type'] ?? $user_data['user_type'] ?? '')
                : (string) ($user_data['user_type'] ?? 'C');
            if ($type !== 'C') {
                return;
            }
            $new = $user_id === 0;
            if ($c3) {
                $verdict = $this->evaluate($user_data, $stored, $new, $lang);
                if ($verdict['refusal'] !== null) {
                    $can_update = false;
                    $this->tell('E', $verdict['refusal']);

                    return;
                }
                foreach ($verdict['rewrite'] as $field => $canonical) {
                    $user_data[$field] = $canonical;
                }
            }
            if ($c4 && $this->accountPhoneTaken($user_id, $user_data, $stored, $new)) {
                $this->tell('E', (string) __('kwtsms.code.in_use', [], $lang));
                if (!$this->insideCheckoutProfile()) {
                    $can_update = false;

                    return;
                }
                // 2026-10-25 07:11 +03: M1.9 fix round 1 (item 2, ruling P3-R11), task 596. The post-order profile
                // save: the order is placed and the customer's other changes are kept; only the taken phone goes
                // back to its stored value, and the notice above says so (never silent).
                // 2026-10-28 11:11 +03: Task M1.10a, task 596: lines wrapped to 120 columns (phpcs warnings), no
                // change of logic.
                $field = isset($user_data['phone']) && trim((string) $user_data['phone']) !== '' ? 'phone' : 'b_phone';
                $user_data[$field] = (string) ($stored[$field] ?? '');
            }
        } catch (\Throwable $e) {
            error_log('kwtsms: phone check failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        }
        if (!$codes) {
            return;
        }
        try {
            $request = $this->request_context === null ? [] : (array) call_user_func($this->request_context);
            $ctx = [
                'action' => $user_id > 0 ? 'update' : 'add',
                'user_id' => $user_id,
                'stored' => AccountPhone::field($stored),
                'session_hash' => (string) ($request['session_hash'] ?? ''),
                'ip' => (string) ($request['ip'] ?? ''),
                'storefront_id' => max(0, (int) call_user_func($this->storefront_id)),
                'lang' => $lang,
            ];
            $refusal = $this->code_checks->check($ctx, $user_data);
            if ($refusal !== null) {
                $can_update = false;
                $this->tell('E', $refusal);
                $this->releaseHeld(); // 2026-10-25 07:11 +03: M1.9 fix round 1 (M2), task 596
            }
        } catch (\Throwable $e) {
            error_log('kwtsms: code check failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
            $can_update = false;
            $this->releaseHeld();
            $this->tell('E', (string) __('kwtsms.code.check_failed', [], $lang));
        }
    }

    /**
     * 2026-10-28 11:06 +03: Task M1.10a, task 596. Whether a code check has work to do: not the NoCodeCheck, and
     * for the composite CodeChecks at least one of its checks switched ON.
     */
    private function codesActive(): bool
    {
        if ($this->code_checks instanceof NoCodeCheck) {
            return false;
        }

        return !($this->code_checks instanceof CodeChecks) || $this->code_checks->active();
    }

    /**
     * 2026-10-25 07:11 +03: M1.9 fix round 1 (I1), task 596. The checkout profile editor (POST checkout.update_profile,
     * core's fn_update_user_profile(), which has no refusal hook), called by the pre-controller
     * controllers/frontend/checkout.pre.php before core saves anything. For the signed-in customer (never user 1) it
     * runs the SAME checks as the forms: C3 through evaluate() (an accepted phone is rewritten canonical in
     * $user_data), and C4 through accountPhoneTaken() when the edited profile is the primary one and the account has
     * no contact phone (the account phone is then the billing phone, P3-17). Returns true when the save must be
     * refused (the notice is already set; the caller redirects), false otherwise, also when both switches are OFF
     * (nothing is read). An internal error lets the save through (logged).
     *
     * @param array<string,mixed> $user_data The posted user_data of the editor (rewritten by reference)
     */
    public function onCheckoutProfileEdit(int $user_id, array &$user_data): bool
    {
        if (
            $user_id <= 1 || (string) call_user_func($this->area) !== 'C'
            || $this->config === null || $this->rules === null
        ) {
            return false;
        }
        $c3 = $this->config->phoneCountryCheck();
        $c4 = $this->index !== null && $this->index->enforcing();
        if (!$c3 && !$c4) {
            return false;
        }
        try {
            $profile_id = $this->intOf($user_data['profile_id'] ?? 0);
            $stored = $this->storedAccount($user_id, $profile_id);
            if ($stored === null || (string) ($stored['user_type'] ?? '') !== 'C') {
                return false;
            }
            $lang = $this->lang();
            if ($c3) {
                $verdict = $this->evaluate($user_data, $stored, $profile_id === 0, $lang);
                if ($verdict['refusal'] !== null) {
                    $this->tell('E', $verdict['refusal']);

                    return true;
                }
                foreach ($verdict['rewrite'] as $field => $canonical) {
                    $user_data[$field] = $canonical;
                }
            }
            if (
                $c4 && $profile_id > 0 && (string) ($stored['profile_type'] ?? '') === 'P'
                && trim((string) ($stored['phone'] ?? '')) === ''
            ) {
                $posted = $user_data;
                unset($posted['phone']);
                if ($this->accountPhoneTaken($user_id, $posted, $stored, false)) {
                    $this->tell('E', (string) __('kwtsms.code.in_use', [], $lang));

                    return true;
                }
            }
        } catch (\Throwable $e) {
            error_log('kwtsms: checkout profile check failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        }

        return false;
    }

    /**
     * 2026-10-25 04:36 +03: Task M1.9 (C3, P3-21), task 596. Hook pre_place_order (storefront checkout, AREA C, C3
     * ON): the phone fields of the cart's user data that are new or changed (a guest: all; a logged-in customer:
     * compared with the account's stored value of the same field) are checked; an accepted one is rewritten to its
     * canonical form (so the order carries it), a refused one sets $allow = false with an error notice. C4 is not
     * checked here. An internal error lets the order through (logged). Never throws.
     *
     * @param array<string,mixed> $cart
     */
    public function onPrePlaceOrder(array &$cart, bool &$allow): void
    {
        if (
            $this->config === null || $this->rules === null || (string) call_user_func($this->area) !== 'C'
            || empty($cart['user_data']) || !is_array($cart['user_data']) || !$this->config->phoneCountryCheck()
        ) {
            return;
        }
        try {
            $actor = $this->actor === null ? 0 : (int) call_user_func($this->actor);
            if ($actor === 1) {
                return;
            }
            $stored = [];
            if ($actor > 0) {
                $stored = $this->storedAccount($actor, $this->intOf($cart['profile_id'] ?? 0));
                if ($stored === null || ($stored['user_type'] ?? '') !== 'C') {
                    return;
                }
            }
            $lang = $this->lang();
            $verdict = $this->evaluate($cart['user_data'], $stored, $actor <= 0, $lang);
            if ($verdict['refusal'] !== null) {
                $allow = false;
                $this->tell('E', $verdict['refusal']);

                return;
            }
            foreach ($verdict['rewrite'] as $field => $canonical) {
                $cart['user_data'][$field] = $canonical;
            }
        } catch (\Throwable $e) {
            error_log('kwtsms: checkout phone check failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    /**
     * 2026-10-25 04:36 +03: Task M1.9 (P3-15), task 596. AREA A: a customer phone that C3 would refuse on the
     * storefront is saved as typed, with a warning notice. Never throws.
     *
     * @param array<string,mixed> $user_data
     */
    private function warnAdmin(int $user_id, array $user_data): void
    {
        try {
            $stored = [];
            if ($user_id > 0) {
                $stored = $this->storedAccount($user_id, $this->intOf($user_data['profile_id'] ?? 0)) ?? [];
            }
            $type = $user_id > 0 ? (string) ($stored['user_type'] ?? $user_data['user_type'] ?? '')
                : (string) ($user_data['user_type'] ?? 'C');
            if ($type !== 'C') {
                return;
            }
            $verdict = $this->evaluate($user_data, $stored, $user_id === 0, $this->lang());
            if ($verdict['refusal'] !== null) {
                // 2026-10-25 06:35 +03: Task M1.9, task 596. The reason is PhoneRules text (language variables and the
                // country names of core); the finished text is escaped once, at the notice (tell()).
                $this->tell('W', (string) __('kwtsms.phone.admin_warning', ['[reason]' => $verdict['refusal']]));
            }
        } catch (\Throwable $e) {
            error_log('kwtsms: admin phone warning failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    /**
     * 2026-10-25 04:36 +03: Task M1.9 (C3, P3-21), task 596. The C3 verdict for the phone fields of $data: a field
     * that is empty, or not a new or changed value (compared in canonical form with the stored account), is skipped;
     * the first field the rule refuses gives the refusal text; the others give their canonical form.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $stored
     *
     * @return array{refusal:?string,rewrite:array<string,string>}
     */
    private function evaluate(array $data, array $stored, bool $new, string $lang): array
    {
        $default_cc = $this->default_cc === null ? '965' : (string) call_user_func($this->default_cc);
        $rewrite = [];
        foreach (self::PHONE_FIELDS as $field) {
            $posted = $data[$field] ?? '';
            if (!is_string($posted) || trim($posted) === '') {
                continue;
            }
            $hint = AccountPhone::hint($field, $data, $stored, $default_cc);
            $was = isset($stored[$field]) && is_string($stored[$field]) ? $stored[$field] : '';
            if (!$new && !AccountPhone::changed($posted, $was, $hint)) {
                continue;
            }
            $result = $this->rules->check($posted, $hint);
            if (!$result['ok']) {
                return [
                    'refusal' => $this->rules->message(
                        $result['reason'],
                        $this->config->customerSmsCountries(),
                        $lang === '' ? null : $lang
                    ),
                    'rewrite' => [],
                ];
            }
            $rewrite[$field] = $result['phone'];
        }

        return ['refusal' => null, 'rewrite' => $rewrite];
    }

    /**
     * 2026-10-25 04:36 +03: Task M1.9 (C4, P3-11, P3-21), task 596. True when the account phone (phone, else b_phone)
     * of the posted data is new or changed and another account has it indexed. The check runs under PhoneLock; the
     * lock is kept after a "free" answer until onUpdateProfile() has written the index row (or the request ends),
     * and freed at once on a refusal. A phone that is not a valid mobile has no canonical form and nobody owns it.
     * A lock that stays busy counts as taken.
     *
     * @param array<string,mixed> $user_data
     * @param array<string,mixed> $stored
     */
    private function accountPhoneTaken(int $user_id, array $user_data, array $stored, bool $new): bool
    {
        $posted = AccountPhone::field($user_data);
        if ($posted === '' || $this->lock === null) {
            return false;
        }
        $default_cc = $this->default_cc === null ? '965' : (string) call_user_func($this->default_cc);
        $field = isset($user_data['phone']) && trim((string) $user_data['phone']) !== '' ? 'phone' : 'b_phone';
        $hint = AccountPhone::hint($field, $user_data, $stored, $default_cc);
        if (!$new && !AccountPhone::changed($posted, AccountPhone::field($stored), $hint)) {
            return false;
        }
        $canonical = AccountPhone::canonicalOf($user_data, $stored, $default_cc);
        if ($canonical === '') {
            return false;
        }
        if (!$this->lock->acquire($canonical)) {
            return true;
        }
        $this->held[$canonical] = true;
        if ($this->index->ownerOf($canonical, $user_id) > 0) {
            $this->lock->release($canonical);
            unset($this->held[$canonical]);

            return true;
        }

        return false;
    }

    /**
     * 2026-10-25 07:11 +03: M1.9 fix round 1 (M3), task 596 (was releasePhoneLock(), which recomputed the phone
     * from the saved data). After the account was saved and its index row written, or when a later step refused,
     * every lock this instance took is freed by the key it acquired, so the next request sees the row. Never
     * throws.
     */
    private function releaseHeld(): void
    {
        if ($this->lock === null || $this->held === []) {
            return;
        }
        foreach (array_keys($this->held) as $phone) {
            try {
                $this->lock->release((string) $phone);
            } catch (\Throwable $e) {
                error_log('kwtsms: phone lock release failed: ' . get_class($e) . ': ' . $e->getMessage()
                    . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
            }
        }
        $this->held = [];
    }

    /**
     * @return array<string,mixed>|null The stored account (user_type, phone, b_phone, s_phone, b_country, s_country)
     */
    private function storedAccount(int $user_id, int $profile_id): ?array
    {
        if ($this->stored_loader === null) {
            return null;
        }
        $row = call_user_func($this->stored_loader, $user_id, $profile_id);

        return is_array($row) ? $row : null;
    }

    private function lang(): string
    {
        $request = $this->request_context === null ? [] : (array) call_user_func($this->request_context);

        return (string) ($request['lang'] ?? '');
    }

    /** @param mixed $value */
    private function intOf($value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function insideCheckoutProfile(): bool
    {
        if ($this->checkout_profile !== null) {
            return (bool) call_user_func($this->checkout_profile);
        }
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 14) as $frame) {
            if (($frame['function'] ?? '') === 'fn_checkout_update_user_profile') {
                return true;
            }
        }

        return false;
    }

    /** A customer-facing notice; the text is escaped here (the notification area prints HTML). */
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
     * 2026-10-24 18:45 +03: M1.6 fix round 1 (I2, ruling P3-R6), task 596. Hook update_user_profile_post: core's
     * fn_update_user_profile() saved a profile of the account without update_profile (the checkout profile editor,
     * checkout.update_profile). The account is re-read from the database (users.phone, the primary profile's
     * b_phone and b_country, the same source as the backfill) and goes through the same
     * writePhoneIndex(). Inside fn_update_user (which calls fn_update_user_profile first and update_profile last) it
     * does nothing: update_profile writes once. Never throws.
     *
     * @param array<string,mixed> $posted The saved profile data (unused: the database is the source)
     */
    public function onUpdateUserProfile(int $user_id, array $posted, string $action): void
    {
        if ($this->index === null || $user_id <= 0) {
            return;
        }
        try {
            if (!$this->index->active() || $this->insideUpdateUser()) {
                return;
            }
            try {
                $account = $this->account_loader === null ? null : call_user_func($this->account_loader, $user_id);
                if (is_array($account)) {
                    $written = ['user_id' => $user_id] + $account;
                    $this->writePhoneIndex($action === 'add' ? 'add' : 'update', $written, []);
                }
            } finally {
                $this->releaseHeld(); // 2026-10-25 07:20 +03: M1.9 fix round 1 (I1), task 596
            }
        } catch (\Throwable $e) {
            error_log('kwtsms: phone index write failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    private function insideUpdateUser(): bool
    {
        if ($this->nested !== null) {
            return (bool) call_user_func($this->nested);
        }
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            if (($frame['function'] ?? '') === 'fn_update_user') {
                return true;
            }
        }

        return false;
    }

    /**
     * 2026-10-24 17:13 +03: Task M1.6 (P3-31, P3-33), task 596. The ONE write of kwtsms_phone_index on a profile save:
     * only while PhoneIndex::active(), only for a customer account (user_type C), in any area (an administrator
     * who edits the phone moves the index too). The row is the canonical account phone of the saved data ('' when it
     * is not a mobile), verified only when the code step reports that same phone. Never throws.
     *
     * @param array<string,mixed> $user_data         The saved data (core merges the stored profile into it)
     * @param array<string,mixed> $current_user_data
     */
    private function writePhoneIndex(string $action, array $user_data, array $current_user_data): void
    {
        if ($this->index === null) {
            return;
        }
        try {
            if (!$this->index->active()) {
                return;
            }
            $user_id = (int) ($user_data['user_id'] ?? 0);
            $type = (string) ($user_data['user_type'] ?? $current_user_data['user_type'] ?? '');
            if ($user_id <= 0 || $type === '') {
                return;
            }
            if ($type !== 'C') {
                // 2026-10-24 18:45 +03: M1.6 fix round 1 (M3), task 596. An account saved as another type is no
                // customer any more: its row must not keep blocking a number.
                $this->index->delete($user_id);

                return;
            }
            $default_cc = $this->default_cc === null ? '965' : (string) call_user_func($this->default_cc);
            $phone = AccountPhone::canonicalOf($user_data, $current_user_data, $default_cc);
            $request = $this->request_context === null ? [] : (array) call_user_func($this->request_context);
            $ctx = [
                'action' => $action,
                'user_id' => $user_id,
                'stored' => AccountPhone::field($current_user_data),
                'session_hash' => (string) ($request['session_hash'] ?? ''),
                'ip' => (string) ($request['ip'] ?? ''),
                'storefront_id' => max(0, (int) call_user_func($this->storefront_id)),
                'lang' => (string) ($request['lang'] ?? ''),
            ];
            $verified = $this->code_checks->afterSave($ctx, $user_data);
            $this->index->upsert($user_id, $phone, $phone !== '' && $verified === $phone);
        } catch (\Throwable $e) {
            error_log('kwtsms: phone index write failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    /**
     * 2026-10-23 00:52 +03: Task A10 (SafatMall F6), task 596. Saves user_data[kwtsms_order_sms] (Y / N) for a
     * customer account. Only a storefront request counts (AREA C), for the account that is logged in (a new account
     * is not yet: action add); the backend profile form does not carry the field and a posted one is ignored there.
     * Any other value, or no field, changes nothing. Never throws.
     *
     * @param array<string,mixed> $user_data
     */
    private function saveOrderSmsChoice(string $action, array $user_data): void
    {
        if (
            $this->prefs === null
            || !isset($user_data['kwtsms_order_sms'])
            || !is_string($user_data['kwtsms_order_sms'])
        ) {
            return;
        }
        $choice = $user_data['kwtsms_order_sms'];
        $user_id = (int) ($user_data['user_id'] ?? 0);
        if (
            ($choice !== CustomerPrefs::ALLOWED && $choice !== CustomerPrefs::OPTED_OUT)
            || $user_id <= 0
            || (string) ($user_data['user_type'] ?? '') !== 'C'
            || (string) call_user_func($this->area) !== 'C'
        ) {
            return;
        }
        if ($action !== 'add' && ($this->actor === null || (int) call_user_func($this->actor) !== $user_id)) {
            return;
        }
        try {
            $this->prefs->save($user_id, $choice === CustomerPrefs::ALLOWED);
        } catch (\Throwable $e) {
            error_log('kwtsms: opt-out save failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }
}
