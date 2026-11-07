<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Settings/CsCartSettingsBackend.php
 * CREATED:   2026-10-20 21:44 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      SettingsBackendInterface on CS-Cart's Settings class, always at the root scope.
 * WHY:       The add-on's settings are ROOT settings. Written through the default Settings instance they
 *            fail while a storefront is selected (PRD 4.4: ult Settings.php), so every read and write goes
 *            through Settings::instance(['company_id' => 0, 'storefront_id' => 0]). Values are read once
 *            per object (one query for all 33 settings) and the cache follows this object's own writes.
 * USAGE:     $backend = new CsCartSettingsBackend();   (wired in Task 1.6, under Settings\Config)
 * DEPENDS:   CS-Cart core: Tygh\Settings. The settings section is the add-on id, kwtsms.
 */

namespace Tygh\Addons\Kwtsms\Settings;

use Tygh\Settings;

class CsCartSettingsBackend implements SettingsBackendInterface
{
    private const SECTION = 'kwtsms';

    /** Settings whose value is a credential or a key: never shown by var_dump() or print_r(). */
    private const HIDDEN_IN_DUMPS = ['api_username', 'api_password', 'cron_key'];

    /**
     * 2026-11-05 11:15 +03: Task M2.2b fix 1 (review I1), task 596. The three 2FA switches: they change only through
     * the guarded save of the Settings page (Admin\SettingsPage -> Config::save -> write()), never through core's
     * add-on settings form or the REST settings entity.
     */
    private const TWOFA_KEYS = ['twofa_customers', 'twofa_vendors', 'twofa_admins'];

    /** @var int how many write() calls of a 2FA switch are running (request-scoped, 0 = none) */
    private static $allow_twofa = 0;

    /** @var array<string, mixed>|null */
    private $values;

    /**
     * 2026-11-05 11:15 +03: Task M2.2b fix 1 (review I1), task 596. Body of the settings_update_value_by_id_pre hook
     * (Tygh\Settings::updateValueById, app/Tygh/Settings.php:1179 in CS-Cart 4.21.2: fn_set_hook(
     * 'settings_update_value_by_id_pre', $this, $object_id, $value, $company_id, $execute_functions, $data, $old_data,
     * $table, $storefront_id)). A write of a 2FA switch that did not come through write() keeps the stored value:
     * $data['value'] is put back to the old one, so the table write that follows changes nothing.
     *
     * @param array<string,mixed> $data     The row about to be written (by reference)
     * @param array<string,mixed> $old_data The stored object (empty when the setting has no row yet)
     *
     * @return bool True when the value was restored
     */
    public static function holdTwoFactorSwitch(array &$data, array $old_data): bool
    {
        if (self::$allow_twofa > 0 || $old_data === []) {
            return false;
        }
        // 2026-11-05 12:06 +03: Task M2.2b fix 1 (review I1), task 596. A row without a stored value yet is core's own
        // install or upgrade code (fn_update_addon_settings() creates the row, then writes the value, a merchant's
        // "required" included): nothing to protect, so it goes through. After that every switch holds a value.
        if (!isset($old_data['value']) || $old_data['value'] === '') {
            return false;
        }
        $ours = isset($old_data['section_name'], $old_data['name'])
            && strtolower((string) $old_data['section_name']) === self::SECTION
            && in_array((string) $old_data['name'], self::TWOFA_KEYS, true)
            && array_key_exists('value', $old_data);
        if (!$ours) {
            return false;
        }
        $data['value'] = $old_data['value'];

        return true;
    }

    /**
     * 2026-10-20 22:20 +03: what var_dump() and print_r() show (task 596, Task 1.4 fix round 1). The loaded
     * values with the username, the password ciphertext and the cron key replaced by [redacted]; nothing is
     * loaded for the dump.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        if ($this->values === null) {
            return ['values' => '(not loaded)'];
        }
        $values = $this->values;
        foreach (self::HIDDEN_IN_DUMPS as $key) {
            if (isset($values[$key]) && $values[$key] !== '') {
                $values[$key] = '[redacted]';
            }
        }

        return ['values' => $values];
    }

    public function read(string $key): ?string
    {
        $values = $this->load();

        return isset($values[$key]) ? (string) $values[$key] : null;
    }

    /**
     * 2026-10-20 22:15 +03: fails loudly, task 596 (Task 1.4 fix round 1). Settings::updateValue() always
     * returns true, even for a setting that does not exist, so the id is resolved here and
     * updateValueById() (false for a missing object or an edition that cannot hold the value) is checked.
     * The message names the key only: a value may be a ciphertext or a secret.
     *
     * @throws \RuntimeException When the setting does not exist or the update was refused; nothing is cached
     */
    public function write(string $key, string $value): void
    {
        $settings = $this->settings();
        $id = $settings->getId($key, self::SECTION);
        // 2026-11-05 11:15 +03: Task M2.2b fix 1 (review I1), task 596. This backend is the add-on's own, guarded
        // writer: while it writes a 2FA switch the settings_update_value_by_id_pre hook lets the value through. Core's
        // add-on form and the REST settings entity do not come through here, so the hook restores the stored value.
        $guarded = in_array($key, self::TWOFA_KEYS, true);
        // 2026-11-07 23:57 +03: M2.10 G1, task 596. A core that cannot hold the second sign-in step never gets a switch
        // that leaves 'off' (defence in depth behind the Settings page refusal): 'off' is stored, the platform record
        // names login_user_pre (the red Settings note), and the caller sees a normal write.
        if ($guarded && $value !== 'off' && !$this->guards()->twofaEnforceable()) {
            $value = 'off';
            $this->recordPlatformMissing('login_user_pre');
        }
        if ($guarded) {
            self::$allow_twofa++;
        }
        try {
            $ok = $id && $settings->updateValueById($id, $value);
        } finally {
            if ($guarded) {
                self::$allow_twofa--;
            }
        }
        if (!$ok) {
            throw new \RuntimeException('Setting "' . $key . '" could not be saved');
        }
        $this->load();
        $this->values[$key] = $value;
    }

    /** 2026-11-07 23:57 +03: M2.10 G1, task 596. The probes of the running core (a test replaces this seam). */
    protected function guards(): CoreGuards
    {
        return new CoreGuards();
    }

    /**
     * 2026-11-07 23:57 +03: M2.10 G1, task 596. Adds $hook to the kwtsms_platform_missing record that the red Settings
     * note reads (same shape as func.php fn_kwtsms_hook_args_missing(): hook => [given, needed, time]). Never throws.
     */
    protected function recordPlatformMissing(string $hook): void
    {
        try {
            if (!function_exists('fn_get_storage_data') || !function_exists('fn_set_storage_data')) {
                return;
            }
            $raw = fn_get_storage_data('kwtsms_platform_missing');
            $all = is_string($raw) ? json_decode($raw, true) : [];
            $all = is_array($all) ? $all : [];
            if (!isset($all[$hook])) {
                $all[$hook] = [0, 0, time()];
                fn_set_storage_data('kwtsms_platform_missing', (string) json_encode($all));
            }
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    protected function settings(): Settings
    {
        return Settings::instance(['company_id' => 0, 'storefront_id' => 0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if ($this->values === null) {
            $values = $this->settings()->getValues(self::SECTION, Settings::ADDON_SECTION, false);
            $this->values = is_array($values) ? $values : [];
        }

        return $this->values;
    }
}
