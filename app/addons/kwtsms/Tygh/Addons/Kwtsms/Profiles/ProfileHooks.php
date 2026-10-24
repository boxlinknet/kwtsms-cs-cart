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
 * DEPENDS:   Container wiring in ServiceProvider ('kwtsms.profile_hooks'), func.php fn_kwtsms_update_profile(),
 *            Profiles\CustomerPrefs, Phones\{PhoneIndex,AccountPhone}, Profiles\CodeCheckInterface.
 */

namespace Tygh\Addons\Kwtsms\Profiles;

use Tygh\Addons\Kwtsms\Phones\AccountPhone;
use Tygh\Addons\Kwtsms\Phones\PhoneIndex;

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

    public function __construct(
        callable $dispatch,
        callable $storefront_id,
        callable $area,
        ?CustomerPrefs $prefs = null,
        ?callable $actor = null,
        ?PhoneIndex $index = null, // 2026-10-24 17:13 +03: Task M1.6 (R8), task 596
        ?CodeCheckInterface $code_checks = null,
        ?callable $default_cc = null,
        ?callable $request_context = null
    ) {
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
            if ($user_id <= 0 || (string) ($user_data['user_type'] ?? $current_user_data['user_type'] ?? '') !== 'C') {
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
