<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Phones/AccountPhone.php
 * CREATED:   2026-10-24 15:29 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Helpers about "the account phone" of a customer: which user_data value it is (phone, else b_phone),
 *            whether a posted value changes the stored one (compared in canonical form), which country hint a
 *            phone field gets, and whether a profile form shows a phone field at all.
 * WHY:       Task M1.5 (P3-17, P3-21, PRD 6.3). Only a new or changed phone is checked; a legacy phone that is left
 *            alone must never block a profile save or a checkout, whatever shape it has.
 * USAGE:     AccountPhone::field($user_data);  AccountPhone::changed($posted, $stored, $hint_cc);
 *            AccountPhone::hint('b_phone', $_REQUEST['user_data'], $stored_user, $default_cc);
 *            AccountPhone::formHasPhone('C');
 * DEPENDS:   Profiles\ProfileSmsData::phone(), Phones\PhoneFormat, Sms\PhoneNormalizer::callingCodeForIso();
 *            fn_get_profile_fields() of CS-Cart for formHasPhone().
 */

namespace Tygh\Addons\Kwtsms\Phones;

use Tygh\Addons\Kwtsms\Profiles\ProfileSmsData;
use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;

final class AccountPhone
{
    /** Phone field => the country field that decides its default calling code (PRD 6.3). */
    private const COUNTRY_FIELD = ['phone' => 'b_country', 'b_phone' => 'b_country', 's_phone' => 's_country'];

    /** @var array<string,mixed>|null 2026-11-06 07:34 +03: Task M2.5, task 596. Test seam, see useSelection(). */
    private static $seam_request = null;

    /** @var string[]|null 2026-11-06 07:34 +03: Task M2.5, task 596. Test seam, see useSelection(). */
    private static $seam_allowed = null;

    /**
     * The account phone as stored or posted (raw, trimmed): user_data.phone, else user_data.b_phone, '' when neither
     * is filled. Same rule as ProfileSmsData::phone().
     *
     * @param array<string,mixed> $user_data
     */
    public static function field(array $user_data): string
    {
        return ProfileSmsData::phone($user_data);
    }

    /**
     * 2026-10-24 17:12 +03: Task M1.6 (P3-27, P3-31), task 596. The canonical form of the account phone of $user_data,
     * or '' (no phone, or not a mobile): the field() value read with the calling code of its country field
     * (hint()), $stored being the user data the country may also come from. The profile save (the one index writer)
     * and the backfill both use this, so the same account gets the same index value either way.
     *
     * @param array<string,mixed> $user_data
     * @param array<string,mixed> $stored
     */
    public static function canonicalOf(array $user_data, array $stored, string $default_cc): string
    {
        $raw = self::field($user_data);
        if ($raw === '') {
            return '';
        }
        $field = isset($user_data['phone']) && trim((string) $user_data['phone']) !== '' ? 'phone' : 'b_phone';

        return PhoneFormat::fromRaw($raw, self::hint($field, $user_data, $stored, $default_cc));
    }

    /**
     * True when $posted is a different phone from $stored. Both are compared in canonical form
     * (50123401 and +96550123401 are the same phone); a value that is not a valid mobile compares as its raw
     * trimmed string, so a legacy value that is posted back unchanged is not a change.
     * 2026-10-24 16:27 +03: example corrected (it named two different numbers), Task M1.5 fix round 1, task 596.
     */
    public static function changed(
        string $posted,
        string $stored,
        string $hint_cc,
        ?string $stored_hint_cc = null
    ): bool {
        // 2026-11-16 23:53 +03: M6 (branch review 1.2.0, task M3.22, task 596): the stored value is read with the
        // calling code of the STORED country ($stored_hint_cc), the posted one with the posted country ($hint_cc).
        // Before, both used the posted hint, so a stored 50123401 (KW) posted back with the country changed to SA
        // compared equal and skipped B5 and C4. null keeps the old single-hint behaviour.
        $stored_hint = $stored_hint_cc ?? $hint_cc;

        return self::comparable($posted, $hint_cc) !== self::comparable($stored, $stored_hint);
    }

    /**
     * 2026-11-16 23:53 +03: M6 (task M3.22, task 596). The calling code a STORED phone field is read with: the stored
     * country of its field, else the store default. Ignores the posted country and the country selector.
     *
     * @param array<string,mixed> $stored the stored user_data (may be [])
     */
    public static function storedHint(string $field, array $stored, string $default_cc): string
    {
        $country_field = self::COUNTRY_FIELD[$field] ?? '';
        $iso = $country_field !== '' && isset($stored[$country_field]) && is_string($stored[$country_field])
            ? $stored[$country_field] : '';
        $cc = $iso === '' ? null : PhoneNormalizer::callingCodeForIso($iso);

        return $cc ?? $default_cc;
    }

    /**
     * The calling code a phone field is read with: the calling code of the posted country of its field, else of the
     * stored one, else the store default. `phone` follows b_country (the contact phone has no country of its own).
     *
     * @param string              $field        phone, b_phone or s_phone
     * @param array<string,mixed> $posted       The posted user_data
     * @param array<string,mixed> $stored       The stored user_data (may be [])
     * @param string              $default_cc   defaultCountryCode()
     */
    public static function hint(string $field, array $posted, array $stored, string $default_cc): string
    {
        // 2026-11-06 07:34 +03: Task M2.5 (C2, P3-55), task 596. A posted kwtsms_cc[<field>] (the storefront country
        // selector) that is one of the allowed countries is the first hint; anything else is ignored.
        $selected = self::requestSelection($field);
        if ($selected !== null) {
            return $selected;
        }
        $country_field = self::COUNTRY_FIELD[$field] ?? '';
        if ($country_field === '') {
            return $default_cc;
        }
        foreach ([$posted, $stored] as $source) {
            $iso = isset($source[$country_field]) && is_string($source[$country_field]) ? $source[$country_field] : '';
            $cc = $iso === '' ? null : PhoneNormalizer::callingCodeForIso($iso);
            if ($cc !== null) {
                return $cc;
            }
        }

        return $default_cc;
    }

    /**
     * 2026-11-06 07:34 +03: Task M2.5 (C2, P3-55), task 596. The calling code the country selector posted for $field,
     * or null: $posted is the kwtsms_cc request array (field => calling code); a value counts only when it is a string
     * of 1 to 4 digits without a leading zero AND one of $allowed (the customer_sms_countries, given only while the
     * selector is active). The browser never decides: the C3 check and the canonical form still run on the result.
     *
     * @param mixed    $posted  the kwtsms_cc request value
     * @param string[] $allowed allowed calling codes
     */
    public static function selectedCc(string $field, $posted, array $allowed): ?string
    {
        if (!is_array($posted) || !isset($posted[$field]) || !is_string($posted[$field])) {
            return null;
        }
        $cc = trim($posted[$field]);
        if (preg_match('/^[1-9][0-9]{0,3}$/D', $cc) !== 1 || !in_array($cc, array_map('strval', $allowed), true)) {
            return null;
        }

        return $cc;
    }

    /**
     * 2026-11-06 07:34 +03: Task M2.5 (C2, P3-55), task 596. Test seam of the request-level selection: $request is
     * the kwtsms_cc array and $allowed the allowed calling codes; (null, null) is the live behaviour (the request's
     * kwtsms_cc and the allowed list of the add-on settings while the selector is active).
     *
     * @param array<string,mixed>|null $request
     * @param string[]|null            $allowed
     */
    public static function useSelection(?array $request, ?array $allowed): void
    {
        self::$seam_request = $request;
        self::$seam_allowed = $allowed;
    }

    /** The selected calling code of this request for $field (selectedCc() on the request and the live allowed list). */
    private static function requestSelection(string $field): ?string
    {
        try {
            $request = self::$seam_request;
            if ($request === null) {
                $request = isset($_REQUEST['kwtsms_cc']) ? $_REQUEST['kwtsms_cc'] : null;
            }
            if (!is_array($request) || $request === []) {
                return null;
            }
            $allowed = self::$seam_allowed;
            if ($allowed === null) {
                $allowed = [];
                if (class_exists('Tygh\Tygh') && isset(\Tygh\Tygh::$app['kwtsms.config'])) {
                    $allowed = (new CountrySelector(\Tygh\Tygh::$app['kwtsms.config']))->hintAllowed();
                }
            }

            return self::selectedCc($field, $request, $allowed);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * True when the profile form of $location (C registration and profile, O checkout) shows a phone or b_phone
     * field. An error while reading the fields reads as "no phone field" (the caller then does not require a code).
     *
     * @param callable|null $fields (string $location): array, the fn_get_profile_fields() result (test seam)
     */
    public static function formHasPhone(string $location, ?callable $fields = null): bool
    {
        return self::formPhoneField($location, $fields) !== '';
    }

    /**
     * 2026-10-28 11:07 +03: Task M1.10a (B1), task 596. The name of the phone field the form of $location shows:
     * 'phone' (preferred, it is the account phone), else 'b_phone', '' when neither. The registration code field
     * posts the phone of this input.
     *
     * @param callable|null $fields (string $location): array, the fn_get_profile_fields() result (test seam)
     */
    public static function formPhoneField(string $location, ?callable $fields = null): string
    {
        try {
            if ($fields === null) {
                if (!function_exists('fn_get_profile_fields')) {
                    return '';
                }
                $fields = static function (string $where): array {
                    $rows = fn_get_profile_fields($where);

                    return is_array($rows) ? $rows : [];
                };
            }
            $found = '';
            foreach ((array) $fields($location) as $section) {
                foreach ((array) $section as $row) {
                    $name = is_array($row) && isset($row['field_name']) ? (string) $row['field_name'] : '';
                    if ($name === 'phone') {
                        return 'phone';
                    }
                    if ($name === 'b_phone') {
                        $found = 'b_phone';
                    }
                }
            }

            return $found;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 2026-10-28 18:52 +03: Task M1.10a fix round 1 (review I5, M1, ruling P3-R12), task 596. The phone of the
     * registration form:
     * 'account' is the store's account phone field (P3-17: phone, or b_phone when no section of the form location
     * has an active phone field), 'shown' is the phone field the form really shows ('' = none). The quick
     * registration form (settings.General.quick_registration) shows section C only. When 'shown' is '' the add-on
     * adds an input of its own that posts to 'account'.
     *
     * @param callable|null $fields (string $location): array, the fn_get_profile_fields() result (test seam)
     *
     * @return array{account:string,shown:string}
     */
    public static function registrationPhone(bool $quick, ?callable $fields = null): array
    {
        $none = ['account' => 'phone', 'shown' => ''];
        try {
            if ($fields === null) {
                if (!function_exists('fn_get_profile_fields')) {
                    return $none;
                }
                $fields = static function (string $where): array {
                    $rows = fn_get_profile_fields($where);

                    return is_array($rows) ? $rows : [];
                };
            }
            $account = 'b_phone';
            $shown = '';
            foreach ((array) $fields('C') as $section => $rows) {
                $visible = !$quick || (string) $section === 'C';
                foreach ((array) $rows as $row) {
                    $name = is_array($row) && isset($row['field_name']) ? (string) $row['field_name'] : '';
                    if ($name === 'phone') {
                        $account = 'phone';
                        $shown = $visible ? 'phone' : $shown;
                    } elseif ($name === 'b_phone' && $visible && $shown !== 'phone') {
                        $shown = 'b_phone';
                    }
                }
            }

            return ['account' => $account, 'shown' => $shown];
        } catch (\Throwable $e) {
            return $none;
        }
    }

    /**
     * 2026-10-28 22:31 +03: Task M1.10b fix round 1 (review M3, P3-17), task 596. The phone input the code field of a
     * phone change watches: $shown is the phone field of the profile form (formPhoneField()), $stored_users_phone the
     * stored users.phone of the customer. A shown b_phone is the account phone only while users.phone is empty; ''
     * = none.
     */
    public static function watchedField(string $shown, string $stored_users_phone): string
    {
        if ($shown === 'phone') {
            return 'phone';
        }

        return $shown === 'b_phone' && trim($stored_users_phone) === '' ? 'b_phone' : '';
    }

    private static function comparable(string $value, string $hint_cc): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        $canonical = PhoneFormat::fromRaw($trimmed, $hint_cc);

        return $canonical !== '' ? $canonical : $trimmed;
    }
}
