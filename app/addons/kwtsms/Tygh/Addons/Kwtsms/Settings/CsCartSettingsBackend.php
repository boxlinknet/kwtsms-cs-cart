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

    /** @var array<string, mixed>|null */
    private $values;

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
        if (!$id || !$settings->updateValueById($id, $value)) {
            throw new \RuntimeException('Setting "' . $key . '" could not be saved');
        }
        $this->load();
        $this->values[$key] = $value;
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
