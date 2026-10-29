<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Upgrade/SmsCodeScenario.php
 * CREATED:   2026-10-29 19:46 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Ticks or unticks the core Image verification scenario kwtsms_sms_code (the CAPTCHA of the SMS code
 *            request) in the global list and in every storefront list. Body moved here unchanged from
 *            fn_kwtsms_set_sms_code_scenario() (func.php), which now only delegates.
 * WHY:       M1.13b fix round 1. The 1.2.0 post-upgrade step runs inside the Upgrade Center request, where core loaded
 *            the OLD func.php before the file copy; a call to a function that only the new func.php defines threw "does
 *            not exist" and the merchant saw a red "post-upgrade step failed" notice on a successful upgrade. A class
 *            is autoloaded from the new files on disk, so the step no longer depends on func.php.
 * USAGE:     SmsCodeScenario::tick(): bool; SmsCodeScenario::untick(): bool; SmsCodeScenario::set(bool $on): bool.
 *            True when every list reads back as asked.
 * DEPENDS:   Tygh\Settings, Tygh\Tygh storefront.repository, fn_clear_cache() (core).
 */

namespace Tygh\Addons\Kwtsms\Upgrade;

use Tygh\Settings;
use Tygh\Tygh;

class SmsCodeScenario
{
    public const SCENARIO = 'kwtsms_sms_code';

    public static function tick(): bool
    {
        return self::set(true);
    }

    public static function untick(): bool
    {
        return self::set(false);
    }

    /**
     * Puts kwtsms_sms_code in ($on) or out of the Image verification list of the global instance (company 0,
     * storefront 0) and of each storefront (the list it sees, own value or inherited; a storefront whose list is
     * already right is not written, so no override is created).
     * 2026-10-24 23:01 +03: M1.7b fix round 1 (review M1, M2, M3), task 596 (original in func.php).
     * 2026-10-29 19:46 +03: moved into a class, M1.13b fix round 1, task 596.
     */
    public static function set(bool $on): bool
    {
        $scenario = self::SCENARIO;
        $read = static function (Settings $settings, $storefront_id = null) {
            $now = (array) $settings->getValue('use_for', 'Image_verification', null, $storefront_id);

            return array_values(array_keys(array_filter($now, static function ($value): bool {
                return $value === 'Y';
            })));
        };
        $apply = static function (Settings $settings, $storefront_id = null) use ($on, $scenario, $read): bool {
            $ticked = $read($settings, $storefront_id);
            if (in_array($scenario, $ticked, true) !== $on) {
                $ticked = $on ? array_merge($ticked, [$scenario]) : array_values(array_diff($ticked, [$scenario]));
                $settings->updateValue('use_for', $ticked, 'Image_verification', false, null, true, $storefront_id);
            }

            return in_array($scenario, $read($settings, $storefront_id), true) === $on;
        };

        $ok = $apply(Settings::instance(['company_id' => 0, 'storefront_id' => 0]));
        try {
            [$storefronts] = Tygh::$app['storefront.repository']->find([]);
            if (count((array) $storefronts) > 1) {
                foreach ((array) $storefronts as $storefront) {
                    $id = (int) $storefront->storefront_id;
                    $ok = $apply(Settings::instance(['storefront_id' => $id]), $id) && $ok;
                }
            }
        } catch (\Throwable $e) {
            error_log('kwtsms: hook sms_code_scenario.storefronts failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
            $ok = false;
        }
        fn_clear_cache('registry');

        return $ok;
    }
}
