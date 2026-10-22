<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Profiles/ProfileSmsData.php
 * CREATED:   2026-10-22 19:08 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The data_class of profile.created.c, profile.password_recover.c and kwtsms.customer_registered: turns the
 *            profile event data into the placeholders user_id, customer_phone, customer_first_name,
 *            customer_last_name and (recovery only) reset_url, the phone with its b_phone fallback, the secret values
 *            of the recovery SMS and its TTL.
 * WHY:       Phase 2 Task A6 (SafatMall F5 part, rulings R4, R19). The reset URL is built from the one-time ekey and is
 *            a secret: it goes into the send context secret_values (URL and ekey) so every log copy shows it masked,
 *            and into the outbox with the TTL of the ekey (RECOVERY_PASSWORD_TTL, 900 s) so a link that expired is
 *            never delivered. The event data also carries the password of the new customer; no value of this class
 *            is ever read from it.
 * USAGE:     'data_class' => ProfileSmsData::class in schemas/kwtsms/events.php; OrderNotifier builds it with no
 *            arguments and calls values($data, $receiver). ProfileSmsData::phone() and ::resetUrl() are the pure
 *            helpers.
 * DEPENDS:   Notifications\DeclaredSmsData; fn_url() and RECOVERY_PASSWORD_TTL of CS-Cart when they exist (a unit
 *            test falls back to a plain URL and 900).
 */

namespace Tygh\Addons\Kwtsms\Profiles;

use Tygh\Addons\Kwtsms\Notifications\DeclaredSmsData;

final class ProfileSmsData implements DeclaredSmsData
{
    /** TTL of a password-recovery ekey in core (config.php RECOVERY_PASSWORD_TTL), used when the constant is absent. */
    public const DEFAULT_RECOVERY_TTL = 900;

    /**
     * @param array<string,mixed> $data     The event data: user_data (and, for the recovery, ekey)
     * @param string              $receiver C or A
     *
     * @return array{placeholders:array<string,string>,phone:string,secret_values?:array<int,string>,ttl?:int}
     */
    public function values(array $data, string $receiver): array
    {
        $user = isset($data['user_data']) && is_array($data['user_data']) ? $data['user_data'] : [];
        $placeholders = [
            'user_id' => self::scalar($user['user_id'] ?? ''),
            'customer_phone' => self::phone($user),
            'customer_first_name' => self::firstFilled($user, ['firstname', 'b_firstname', 's_firstname']),
            'customer_last_name' => self::firstFilled($user, ['lastname', 'b_lastname', 's_lastname']),
        ];
        $out = ['placeholders' => $placeholders, 'phone' => self::phone($user)];

        $ekey = self::scalar($data['ekey'] ?? '');
        if ($ekey !== '') {
            $url = self::resetUrl($ekey, (int) self::scalar($data['storefront_id'] ?? 0));
            $out['placeholders']['reset_url'] = $url;
            $out['secret_values'] = [$url, $ekey];
            $out['ttl'] = defined('RECOVERY_PASSWORD_TTL') ? (int) RECOVERY_PASSWORD_TTL : self::DEFAULT_RECOVERY_TTL;
        }

        return $out;
    }

    /**
     * The customer's number: user_data.phone, else user_data.b_phone ('' when neither is a filled string).
     *
     * @param array<string,mixed> $user_data
     */
    public static function phone(array $user_data): string
    {
        return self::firstFilled($user_data, ['phone', 'b_phone']);
    }

    /**
     * The storefront link of the password recovery page for an ekey, built like core builds it for the e-mail
     * (events.php, profile.password_recover.c). It resolves against the storefront of the current request, which is
     * the one that dispatched the event.
     */
    // 2026-10-22 20:17 +03: the ekey is a one-time secret (package gate: SensitiveParameter), task 596.
    public static function resetUrl(
        #[\SensitiveParameter]
        string $ekey,
        int $storefront_id
    ): string
    {
        $path = 'auth.recover_password?ekey=' . $ekey;
        if (function_exists('fn_url')) {
            return (string) fn_url($path, 'C');
        }

        return 'https://shop.example.test/index.php?dispatch=' . str_replace('?', '&', $path);
    }

    /**
     * @param array<string,mixed> $row
     * @param string[]            $keys
     */
    private static function firstFilled(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = self::scalar($row[$key] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param mixed $value
     */
    private static function scalar($value): string
    {
        return is_scalar($value) && !is_bool($value) ? trim((string) $value) : '';
    }
}
