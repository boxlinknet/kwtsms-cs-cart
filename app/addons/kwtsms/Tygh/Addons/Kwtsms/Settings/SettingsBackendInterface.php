<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Settings/SettingsBackendInterface.php
 * CREATED:   2026-10-20 21:44 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Contract of the raw storage under Config: read and write one setting as a string.
 * WHY:       Config holds the rules (types, ranges, allow-lists, encryption). Behind this small interface
 *            the same Config runs on CS-Cart's Settings class in production and on an array in unit tests.
 * USAGE:     $backend->read('cap_recipient_hour');            // '5', or null when the setting is unknown
 *            $backend->write('cap_recipient_hour', '7');
 * DEPENDS:   Nothing.
 */

namespace Tygh\Addons\Kwtsms\Settings;

interface SettingsBackendInterface
{
    /**
     * The stored value of a setting of the add-on, or null when there is none (add-on not installed or
     * an unknown id). An empty string is a stored empty value.
     */
    public function read(string $key): ?string;

    /**
     * Stores a value. Callers have validated the key and the value; a key that is not a setting of the
     * add-on is a programming error.
     */
    public function write(string $key, string $value): void;
}
