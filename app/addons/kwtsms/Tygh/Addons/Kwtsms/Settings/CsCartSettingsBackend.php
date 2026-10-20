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

    /** @var array<string, mixed>|null */
    private $values;

    public function read(string $key): ?string
    {
        $values = $this->load();

        return isset($values[$key]) ? (string) $values[$key] : null;
    }

    public function write(string $key, string $value): void
    {
        $this->settings()->updateValue($key, $value, self::SECTION);
        $this->load();
        $this->values[$key] = $value;
    }

    private function settings(): Settings
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
