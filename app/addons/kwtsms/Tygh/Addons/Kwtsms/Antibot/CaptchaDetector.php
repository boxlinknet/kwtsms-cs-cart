<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Antibot/CaptchaDetector.php
 * CREATED:   2026-11-04 12:41 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      One detection of the CAPTCHA provider that protects the SMS code form, from the ACTIVE core antibot
 *            driver (Tygh::$app['antibot']->getDriver()): our TurnstileDriver => 'turnstile'; the core reCAPTCHA
 *            add-on's driver => 'recaptcha'; core's NullDriver or no driver => 'none'; core's own native image
 *            captcha => 'native'; ANY other driver => 'third_party', named by the add-on that owns its class (the id
 *            in .../app/addons/<id>/...) or else by the class name. Also lists the CAPTCHA-looking add-ons that are
 *            active but did not register as the driver.
 * WHY:       Task RC2 item 3 (1.2.0-rc.2, SafatMall): Settings, Integrations and the Dashboard each had their own idea
 *            of "the provider" (only core recaptcha and Turnstile), so a store using another CAPTCHA add-on that
 *            registers as an antibot driver got a red "no CAPTCHA" warning although the code form was protected. The
 *            code request already validates through whatever driver is active (SuspicionGate::checkScenario(), fail
 *            closed); this class is the one place that names it.
 * USAGE:     CaptchaDetector::classify($driver)            // ['provider' => 'third_party', 'name' => 'acme_captcha',
 *                                                           //  'class' => 'Tygh\Addons\AcmeCaptcha\Driver']
 *            CaptchaDetector::current()                    // the same for Tygh::$app['antibot'] (never throws)
 *            CaptchaDetector::inactiveAddons(Registry::get('addons'), $owner_id)   // ['google_recaptcha']
 *            The name is raw text: escape it (htmlspecialchars) before it reaches HTML.
 * DEPENDS:   Nothing at load time; current() reads Tygh\Tygh::$app and the antibot service when they exist.
 */

namespace Tygh\Addons\Kwtsms\Antibot;

final class CaptchaDetector
{
    public const NONE = 'none';
    public const TURNSTILE = 'turnstile';
    public const RECAPTCHA = 'recaptcha';
    public const NATIVE = 'native';
    public const THIRD_PARTY = 'third_party';

    /** The driver class of the core reCAPTCHA add-on (lower case). */
    private const CORE_RECAPTCHA = 'tygh\\addons\\recaptcha\\recaptchadriver';

    /** The namespace of core's own drivers (lower case). */
    private const CORE_NAMESPACE = 'tygh\\web\\antibot\\';

    /**
     * @param object|null $driver The active antibot driver (null = none)
     *
     * @return array{provider:string,name:string,class:string,unconfigured:bool}
     */
    public static function classify($driver): array
    {
        if (!is_object($driver)) {
            return ['provider' => self::NONE, 'name' => '', 'class' => '', 'unconfigured' => false];
        }
        // 2026-11-04 14:41 +03: RC2 fix1 item 3, task 596. An anonymous class is named "<parent>@anonymous\0<file>":
        // cut at the NUL so the server file path never reaches a page, a log or a report.
        $class = get_class($driver);
        $nul = strpos($class, "\0");
        if ($nul !== false) {
            $class = substr($class, 0, $nul);
        }
        $lower = strtolower($class);
        if ($driver instanceof TurnstileDriver) {
            return ['provider' => self::TURNSTILE, 'name' => 'kwtsms', 'class' => $class, 'unconfigured' => false];
        }
        if ($lower === self::CORE_RECAPTCHA) {
            return ['provider' => self::RECAPTCHA, 'name' => 'recaptcha', 'class' => $class, 'unconfigured' => false];
        }
        // Exact class names only: an anonymous class is named after its interface and must not read as core.
        if ($lower === self::CORE_NAMESPACE . 'nulldriver') {
            return ['provider' => self::NONE, 'name' => '', 'class' => $class, 'unconfigured' => false];
        }
        if ($lower === self::CORE_NAMESPACE . 'nativecaptchadriver') {
            return ['provider' => self::NATIVE, 'name' => '', 'class' => $class, 'unconfigured' => false];
        }

        $owner = self::addonOf($driver);
        $name = $owner !== '' ? $owner : $class;
        // 2026-11-04 14:37 +03: RC2 fix1 item 1, task 596 (review I1, fail-open). Core's
        // Antibot::isValidationRequiredForScenario() needs the driver's isSetUp(): a third-party driver that is not set
        // up (or throws) validates nothing, so the code form has NO CAPTCHA. It is not a provider ('none'), and the
        // flag `unconfigured` lets Settings and Integrations name the add-on in the warning.
        try {
            $set_up = method_exists($driver, 'isSetUp') && (bool) $driver->isSetUp();
        } catch (\Throwable $e) {
            $set_up = false;
        }
        if (!$set_up) {
            return ['provider' => self::NONE, 'name' => $name, 'class' => $class, 'unconfigured' => true];
        }

        return ['provider' => self::THIRD_PARTY, 'name' => $name, 'class' => $class, 'unconfigured' => false];
    }

    /**
     * The provider of the running store. Never throws: any failure reads as "none" (the safe, loud side: Settings then
     * shows the red warning).
     *
     * @return array{provider:string,name:string,class:string}
     */
    public static function current(): array
    {
        try {
            $app = \Tygh\Tygh::$app;
            $antibot = isset($app['antibot']) ? $app['antibot'] : null;
            if (!is_object($antibot) || !method_exists($antibot, 'getDriver')) {
                return self::classify(null);
            }

            return self::classify($antibot->getDriver());
        } catch (\Throwable $e) {
            return self::classify(null);
        }
    }

    /**
     * True when $driver is a third-party driver that is set up (it protects forms now). Turnstile does not replace it.
     * A driver that throws from isSetUp() counts as not set up (classify() already says so, 2026-11-04 14:37 +03).
     *
     * @param object|null $driver
     */
    public static function isActiveThirdParty($driver): bool
    {
        if (!is_object($driver) || self::classify($driver)['provider'] !== self::THIRD_PARTY) {
            return false;
        }
        try {
            return method_exists($driver, 'isSetUp') && (bool) $driver->isSetUp();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The id of the add-on whose folder holds the class of $driver ('' when it is not under app/addons/<id>/).
     *
     * @param object $driver
     */
    public static function addonOf($driver): string
    {
        try {
            $file = (string) (new \ReflectionClass($driver))->getFileName();
        } catch (\Throwable $e) {
            return '';
        }
        if (preg_match_all('#[/\\\\]addons[/\\\\]([A-Za-z0-9_]+)[/\\\\]#', $file, $m) < 1) {
            return '';
        }

        return (string) end($m[1]);
    }

    /**
     * Active add-ons whose id contains "captcha" that are not the active driver's owner: they are installed but do
     * not protect the SMS code form. The core reCAPTCHA add-on is left out (it is active on every stock store and is
     * handled by its own rules, ruling P3-R7); so is this add-on.
     *
     * @param array<string,mixed> $addons  Registry 'addons' (id => settings with 'status')
     * @param string              $owner_id The add-on id that owns the active driver ('' = none)
     *
     * @return string[] Add-on ids, sorted
     */
    public static function inactiveAddons(array $addons, string $owner_id): array
    {
        $found = [];
        foreach ($addons as $id => $info) {
            $id = (string) $id;
            if (stripos($id, 'captcha') === false || $id === 'recaptcha' || $id === 'kwtsms' || $id === $owner_id) {
                continue;
            }
            if (is_array($info) && (string) ($info['status'] ?? '') === 'A') {
                $found[] = $id;
            }
        }
        sort($found);

        return $found;
    }

    /**
     * The same for the running store.
     *
     * @return string[]
     */
    public static function inactiveForStore(): array
    {
        try {
            $current = self::current();

            return self::inactiveAddons(
                (array) \Tygh\Registry::get('addons'),
                // 2026-11-04 14:37 +03: RC2 fix1 item 1, task 596. An unconfigured driver's owner is named by its own
                // warning, so it is not listed again as "inactive".
                $current['provider'] === self::THIRD_PARTY || $current['unconfigured'] ? $current['name'] : ''
            );
        } catch (\Throwable $e) {
            return [];
        }
    }
}
