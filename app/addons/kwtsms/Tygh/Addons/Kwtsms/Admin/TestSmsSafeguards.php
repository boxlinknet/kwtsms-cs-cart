<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/TestSmsSafeguards.php
 * CREATED:   2026-11-16 23:47 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The two checks a test SMS needs before it is sent: the number typed twice (equal after normalization) and
 *            the tick "I have permission to send an SMS to this number".
 * WHY:       Task M3.19 (P3-77, SafatMall G4). A mistyped number sends an SMS to a stranger. A safeguard on an admin
 *            action, not a feature: no switch. Pure (no database, no CS-Cart call), so it is unit tested; the one
 *            send path SettingsPage::sendTestText() calls it, so the Settings test SMS and the Templates "send a
 *            test" both pass it and a new caller cannot skip it.
 * USAGE:     $reason = TestSmsSafeguards::refusal($phone, $phone_confirm, $intl, $default_cc, $consent);
 *            // null = go on; 'confirm_mismatch' or 'consent_required' = refuse (language key kwtsms.test_sms.<reason>)
 * DEPENDS:   Sms\PhoneNormalizer.
 */

namespace Tygh\Addons\Kwtsms\Admin;

use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;

final class TestSmsSafeguards
{
    public const MISMATCH = 'confirm_mismatch';
    public const NO_CONSENT = 'consent_required';

    /**
     * @param string $phone         The number as typed
     * @param string $phone_confirm The number typed again
     * @param bool   $intl          "The number includes its country code" ticked
     * @param string $default_cc    Calling code added to a local number when $intl is false
     * @param bool   $consent       The permission tick
     *
     * @return string|null The reason to refuse (a part of a language key), null when both checks pass
     */
    public static function refusal(
        string $phone,
        string $phone_confirm,
        bool $intl,
        string $default_cc,
        bool $consent
    ): ?string {
        $first = self::normalized($phone, $intl, $default_cc);
        if ($first === '' || $first !== self::normalized($phone_confirm, $intl, $default_cc)) {
            return self::MISMATCH;
        }

        return $consent ? null : self::NO_CONSENT;
    }

    private static function normalized(string $phone, bool $intl, string $default_cc): string
    {
        $phone = trim($phone);
        $to = $intl ? '+' . (string) preg_replace('/^\s*(\+|00)/', '', $phone) : $phone;

        return PhoneNormalizer::normalize($to, $intl ? '' : $default_cc);
    }
}
