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
 * DEPENDS:   Container wiring in ServiceProvider ('kwtsms.profile_hooks'), func.php fn_kwtsms_update_profile().
 */

namespace Tygh\Addons\Kwtsms\Profiles;

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

    public function __construct(callable $dispatch, callable $storefront_id, callable $area)
    {
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
}
