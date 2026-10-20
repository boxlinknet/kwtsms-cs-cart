<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Sms/PhoneNormalizer.php
 * CREATED:   2026-10-20 21:01 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Phone number normalization and local verification: Arabic and Persian digits to ASCII,
 *            the default-country local-number rule (D18), trunk-zero removal, country detection,
 *            per-country length and mobile-prefix checks, the ISO2 to calling-code map and the
 *            country dropdown options for the Settings page.
 * WHY:       Every SMS recipient (customer, vendor, admin) passes through here before the gateway is
 *            called, so a number the gateway would reject, or a landline that can never receive an
 *            SMS, is refused locally with a stable reason code. D18 (Boss, 2026-10-20 18:10): a number
 *            typed without + or 00 whose digit count equals the local length of the default country
 *            gets that country code, so 98765432 is Kuwait, not Iran.
 * USAGE:     All methods are static and pure (no state, no CS-Cart calls, no I/O).
 *              PhoneNormalizer::normalize('9876 5432', '965')        -> '96598765432'
 *              PhoneNormalizer::verify('96598765432')                -> [true, null]
 *              PhoneNormalizer::validateInput('a@b.c', '965')        -> [false, 'email', '']
 *              PhoneNormalizer::countryCode('96598765432')           -> '965'
 *              PhoneNormalizer::callingCodeForIso('KW')              -> '965'
 *              PhoneNormalizer::countryOptions()                     -> [965 => 'Kuwait (+965)', ...]
 *            Reason codes of verify() and validateInput(): empty, email, too_short, too_long,
 *            bad_length, not_mobile. Arguments must be strings: cast CS-Cart values (null, int)
 *            with (string) before calling.
 * DEPENDS:   Nothing (PHP 7.4 to 8.3, no extensions beyond PCRE).
 *
 * UPSTREAM:  Port of kwtsms-php v1.7.1, src/PhoneUtils.php (MIT, Copyright (c) 2026 kwtSMS / boxlink net).
 *            Commit  132285b9f39bcfe33925dfbf9ea614026b87240a
 *            sha256  c85bb928a665dd78f0d2922d6be5c8a59bee0d6d57988a0f4f14f38654c572b6
 *            kwtsms-php is not a dependency (D18); this file is a copy that evolves on its own.
 *            Copied unchanged in value: PHONE_RULES (81 entries), COUNTRY_NAMES (82 entries),
 *            DIGITS_MAP (20 entries), the longest-prefix country detection (3, 2, then 1 digit),
 *            the trunk-zero removal after the country code, and the 7 to 15 digit bounds.
 *
 * DELIBERATE CHANGES FROM UPSTREAM (2026-10-20 21:01 +03, task 596):
 *   1. D18 rule first: normalize() takes a country hint. If the raw input has no + or 00 and its digit
 *      count (leading zeros stripped) equals a local length of the hint country, the hint code is
 *      prefixed and country detection is skipped. Otherwise detection runs as upstream. Upstream has
 *      no hint, so 98765432 became "Iran, 6 local digits" there.
 *   2. normalize() remembers whether the raw input starts with + or 00 (international marker): then
 *      the hint is ignored, as the country code is already in the number.
 *   3. The 22334455 case: with hint 965 it becomes 96522334455 and verify() rejects it as not_mobile
 *      (a Kuwait landline), instead of being detected as an unknown country and passing 7 to 15 digits.
 *   4. API: upstream normalize_phone, find_country_code, validate_phone_format and
 *      validate_phone_input (snake_case, English sentences, null on failure) became normalize,
 *      countryCode, verify and validateInput with stable reason codes. The UI translates the codes.
 *      validateInput() returns the normalized string as third element on failure too (upstream: null),
 *      '' only for empty and email.
 *   5. Trunk-zero removal moved from validate_phone_input into normalize(), so the number that is
 *      stored and sent is already corrected.
 *   6. verify() requires digits only with no leading zero (a normalize() result); anything else
 *      is reported as bad_length. Upstream never receives such input.
 *   7. PHP 7.4 safe: substr() returns false (not '') when the offset equals the string length on
 *      PHP 7; localPart() guards that. Upstream relies on PHP 8 behaviour.
 *   8. DIGITS_MAP keys are \u{...} escapes so this file stays ASCII; the mapped characters are the
 *      same 20 (Arabic-Indic U+0660..U+0669, Extended Arabic-Indic U+06F0..U+06F9).
 *   9. Table layout: alignment padding collapsed (PSR-12), values untouched.
 *  10. Added: ISO_CALLING_CODES, callingCodeForIso(), countryOptions(). Namespace and class name changed.
 */

namespace Tygh\Addons\Kwtsms\Sms;

class PhoneNormalizer
{
    /**
     * Calling code that countryOptions() lists first (the store default).
     */
    public const FIRST_COUNTRY = '965';

    /**
     * Map Arabic-Indic and Extended Arabic-Indic (Persian) numerals to Latin digits.
     * Keys are escapes (upstream has the literal characters).
     *
     * @var array<string, string>
     */
    public const DIGITS_MAP = [
        "\u{0660}" => '0',
        "\u{0661}" => '1',
        "\u{0662}" => '2',
        "\u{0663}" => '3',
        "\u{0664}" => '4',
        "\u{0665}" => '5',
        "\u{0666}" => '6',
        "\u{0667}" => '7',
        "\u{0668}" => '8',
        "\u{0669}" => '9',
        "\u{06F0}" => '0',
        "\u{06F1}" => '1',
        "\u{06F2}" => '2',
        "\u{06F3}" => '3',
        "\u{06F4}" => '4',
        "\u{06F5}" => '5',
        "\u{06F6}" => '6',
        "\u{06F7}" => '7',
        "\u{06F8}" => '8',
        "\u{06F9}" => '9',
    ];

    /**
     * Phone number validation rules by country code (kwtsms-php v1.7.1, values unchanged).
     * Each entry has:
     *   localLengths: valid digit counts AFTER the country code
     *   mobileStartDigits: (optional) valid first digit(s) of the local part
     *
     * Countries not listed pass through with generic E.164 validation (7 to 15 digits).
     */
    public const PHONE_RULES = [
        // === GCC ===
        '965' => ['localLengths' => [8], 'mobileStartDigits' => ['4', '5', '6', '9']], // Kuwait
        '966' => ['localLengths' => [9], 'mobileStartDigits' => ['5']], // Saudi Arabia
        '971' => ['localLengths' => [9], 'mobileStartDigits' => ['5']], // UAE
        '973' => ['localLengths' => [8], 'mobileStartDigits' => ['3', '6']], // Bahrain
        '974' => ['localLengths' => [8], 'mobileStartDigits' => ['3', '5', '6', '7']], // Qatar
        '968' => ['localLengths' => [8], 'mobileStartDigits' => ['7', '9']], // Oman
        // === Levant ===
        '962' => ['localLengths' => [9], 'mobileStartDigits' => ['7']], // Jordan
        '961' => ['localLengths' => [7, 8], 'mobileStartDigits' => ['3', '7', '8']], // Lebanon
        '970' => ['localLengths' => [9], 'mobileStartDigits' => ['5']], // Palestine
        '964' => ['localLengths' => [10], 'mobileStartDigits' => ['7']], // Iraq
        '963' => ['localLengths' => [9], 'mobileStartDigits' => ['9']], // Syria
        // === Other Arab ===
        '967' => ['localLengths' => [9], 'mobileStartDigits' => ['7']], // Yemen
        '20' => ['localLengths' => [10], 'mobileStartDigits' => ['1']], // Egypt
        '218' => ['localLengths' => [9], 'mobileStartDigits' => ['9']], // Libya
        '216' => ['localLengths' => [8], 'mobileStartDigits' => ['2', '4', '5', '9']], // Tunisia
        '212' => ['localLengths' => [9], 'mobileStartDigits' => ['6', '7']], // Morocco
        '213' => ['localLengths' => [9], 'mobileStartDigits' => ['5', '6', '7']], // Algeria
        '249' => ['localLengths' => [9], 'mobileStartDigits' => ['9']], // Sudan
        // === Non-Arab Middle East ===
        '98' => ['localLengths' => [10], 'mobileStartDigits' => ['9']], // Iran
        '90' => ['localLengths' => [10], 'mobileStartDigits' => ['5']], // Turkey
        '972' => ['localLengths' => [9], 'mobileStartDigits' => ['5']], // Israel
        // === South Asia ===
        '91' => ['localLengths' => [10], 'mobileStartDigits' => ['6', '7', '8', '9']], // India
        '92' => ['localLengths' => [10], 'mobileStartDigits' => ['3']], // Pakistan
        '880' => ['localLengths' => [10], 'mobileStartDigits' => ['1']], // Bangladesh
        '94' => ['localLengths' => [9], 'mobileStartDigits' => ['7']], // Sri Lanka
        '960' => ['localLengths' => [7], 'mobileStartDigits' => ['7', '9']], // Maldives
        // === East Asia ===
        '86' => ['localLengths' => [11], 'mobileStartDigits' => ['1']], // China
        '81' => ['localLengths' => [10], 'mobileStartDigits' => ['7', '8', '9']], // Japan
        '82' => ['localLengths' => [10], 'mobileStartDigits' => ['1']], // South Korea
        '886' => ['localLengths' => [9], 'mobileStartDigits' => ['9']], // Taiwan
        // === Southeast Asia ===
        '65' => ['localLengths' => [8], 'mobileStartDigits' => ['8', '9']], // Singapore
        '60' => ['localLengths' => [9, 10], 'mobileStartDigits' => ['1']], // Malaysia
        '62' => ['localLengths' => [9, 10, 11, 12], 'mobileStartDigits' => ['8']], // Indonesia
        '63' => ['localLengths' => [10], 'mobileStartDigits' => ['9']], // Philippines
        '66' => ['localLengths' => [9], 'mobileStartDigits' => ['6', '8', '9']], // Thailand
        '84' => ['localLengths' => [9], 'mobileStartDigits' => ['3', '5', '7', '8', '9']], // Vietnam
        '95' => ['localLengths' => [9], 'mobileStartDigits' => ['9']], // Myanmar
        '855' => ['localLengths' => [8, 9], 'mobileStartDigits' => ['1', '6', '7', '8', '9']], // Cambodia
        '976' => ['localLengths' => [8], 'mobileStartDigits' => ['6', '8', '9']], // Mongolia
        // === Europe ===
        '44' => ['localLengths' => [10], 'mobileStartDigits' => ['7']], // UK
        '33' => ['localLengths' => [9], 'mobileStartDigits' => ['6', '7']], // France
        '49' => ['localLengths' => [10, 11], 'mobileStartDigits' => ['1']], // Germany
        '39' => ['localLengths' => [10], 'mobileStartDigits' => ['3']], // Italy
        '34' => ['localLengths' => [9], 'mobileStartDigits' => ['6', '7']], // Spain
        '31' => ['localLengths' => [9], 'mobileStartDigits' => ['6']], // Netherlands
        '32' => ['localLengths' => [9]], // Belgium: mobile/fixed share number space; length-only intentional
        '41' => ['localLengths' => [9], 'mobileStartDigits' => ['7']], // Switzerland
        '43' => ['localLengths' => [10], 'mobileStartDigits' => ['6']], // Austria
        '47' => ['localLengths' => [8], 'mobileStartDigits' => ['4', '9']], // Norway
        '48' => ['localLengths' => [9]], // Poland: complex prefix allocation; length-only intentional
        '30' => ['localLengths' => [10], 'mobileStartDigits' => ['6']], // Greece
        '420' => ['localLengths' => [9], 'mobileStartDigits' => ['6', '7']], // Czech Republic
        '46' => ['localLengths' => [9], 'mobileStartDigits' => ['7']], // Sweden
        '45' => ['localLengths' => [8]], // Denmark: complex prefix allocation; length-only intentional
        '40' => ['localLengths' => [9], 'mobileStartDigits' => ['7']], // Romania
        '36' => ['localLengths' => [9]], // Hungary: complex prefix allocation; length-only intentional
        '380' => ['localLengths' => [9]], // Ukraine: complex prefix allocation; length-only intentional
        // === Americas ===
        '1' => ['localLengths' => [10]], // USA/Canada: no mobile-specific prefix; length-only intentional
        '52' => ['localLengths' => [10]], // Mexico: mobile/fixed unified since 2019; length-only intentional
        '55' => ['localLengths' => [11]], // Brazil: area code + 9 + subscriber; length-only intentional
        '57' => ['localLengths' => [10], 'mobileStartDigits' => ['3']], // Colombia
        '54' => ['localLengths' => [10], 'mobileStartDigits' => ['9']], // Argentina
        '56' => ['localLengths' => [9], 'mobileStartDigits' => ['9']], // Chile
        '58' => ['localLengths' => [10], 'mobileStartDigits' => ['4']], // Venezuela
        '51' => ['localLengths' => [9], 'mobileStartDigits' => ['9']], // Peru
        '593' => ['localLengths' => [9], 'mobileStartDigits' => ['9']], // Ecuador
        '53' => ['localLengths' => [8], 'mobileStartDigits' => ['5', '6']], // Cuba
        // === Africa ===
        '27' => ['localLengths' => [9], 'mobileStartDigits' => ['6', '7', '8']], // South Africa
        '234' => ['localLengths' => [10], 'mobileStartDigits' => ['7', '8', '9']], // Nigeria
        '254' => ['localLengths' => [9], 'mobileStartDigits' => ['1', '7']], // Kenya
        '233' => ['localLengths' => [9], 'mobileStartDigits' => ['2', '5']], // Ghana
        '251' => ['localLengths' => [9], 'mobileStartDigits' => ['7', '9']], // Ethiopia
        '255' => ['localLengths' => [9], 'mobileStartDigits' => ['6', '7']], // Tanzania
        '256' => ['localLengths' => [9], 'mobileStartDigits' => ['7']], // Uganda
        '237' => ['localLengths' => [9], 'mobileStartDigits' => ['6']], // Cameroon
        '225' => ['localLengths' => [10]], // Ivory Coast: 01/05/07 prefix system; length-only intentional
        '221' => ['localLengths' => [9], 'mobileStartDigits' => ['7']], // Senegal
        '252' => ['localLengths' => [9], 'mobileStartDigits' => ['6', '7']], // Somalia
        '250' => ['localLengths' => [9], 'mobileStartDigits' => ['7']], // Rwanda
        // === Oceania ===
        '61' => ['localLengths' => [9], 'mobileStartDigits' => ['4']], // Australia
        '64' => ['localLengths' => [8, 9, 10], 'mobileStartDigits' => ['2']], // New Zealand
    ];

    /**
     * Human-readable English country names keyed by calling code (kwtsms-php v1.7.1, unchanged).
     * Includes 211 (South Sudan), which has no entry in PHONE_RULES and is not offered by countryOptions().
     */
    public const COUNTRY_NAMES = [
        // Middle East & North Africa
        '965' => 'Kuwait',
        '966' => 'Saudi Arabia',
        '971' => 'UAE',
        '973' => 'Bahrain',
        '974' => 'Qatar',
        '968' => 'Oman',
        '962' => 'Jordan',
        '961' => 'Lebanon',
        '970' => 'Palestine',
        '964' => 'Iraq',
        '963' => 'Syria',
        '967' => 'Yemen',
        '98' => 'Iran',
        '90' => 'Turkey',
        '972' => 'Israel',
        '20' => 'Egypt',
        '218' => 'Libya',
        '216' => 'Tunisia',
        '212' => 'Morocco',
        '213' => 'Algeria',
        '249' => 'Sudan',
        '211' => 'South Sudan',
        // Africa
        '27' => 'South Africa',
        '234' => 'Nigeria',
        '254' => 'Kenya',
        '233' => 'Ghana',
        '251' => 'Ethiopia',
        '255' => 'Tanzania',
        '256' => 'Uganda',
        '237' => 'Cameroon',
        '225' => 'Ivory Coast',
        '221' => 'Senegal',
        '252' => 'Somalia',
        '250' => 'Rwanda',
        // Europe
        '44' => 'UK',
        '33' => 'France',
        '49' => 'Germany',
        '39' => 'Italy',
        '34' => 'Spain',
        '31' => 'Netherlands',
        '32' => 'Belgium',
        '41' => 'Switzerland',
        '43' => 'Austria',
        '46' => 'Sweden',
        '47' => 'Norway',
        '45' => 'Denmark',
        '48' => 'Poland',
        '420' => 'Czech Republic',
        '36' => 'Hungary',
        '40' => 'Romania',
        '30' => 'Greece',
        '380' => 'Ukraine',
        // Americas
        '1' => 'USA/Canada',
        '52' => 'Mexico',
        '55' => 'Brazil',
        '54' => 'Argentina',
        '57' => 'Colombia',
        '56' => 'Chile',
        '58' => 'Venezuela',
        '51' => 'Peru',
        '593' => 'Ecuador',
        '53' => 'Cuba',
        // Asia
        '91' => 'India',
        '92' => 'Pakistan',
        '86' => 'China',
        '81' => 'Japan',
        '82' => 'South Korea',
        '886' => 'Taiwan',
        '65' => 'Singapore',
        '60' => 'Malaysia',
        '62' => 'Indonesia',
        '63' => 'Philippines',
        '66' => 'Thailand',
        '84' => 'Vietnam',
        '855' => 'Cambodia',
        '95' => 'Myanmar',
        '880' => 'Bangladesh',
        '94' => 'Sri Lanka',
        '960' => 'Maldives',
        '976' => 'Mongolia',
        // Oceania
        '61' => 'Australia',
        '64' => 'New Zealand',
    ];

    /**
     * ISO 3166-1 alpha-2 country code to calling code, for every country in PHONE_RULES (CS-Cart order
     * addresses carry ISO2: KW, SA). US and CA share +1. Added 2026-10-20 21:01 +03 (not upstream), task 596.
     *
     * @var array<string, string>
     */
    public const ISO_CALLING_CODES = [
        // GCC
        'KW' => '965',
        'SA' => '966',
        'AE' => '971',
        'BH' => '973',
        'QA' => '974',
        'OM' => '968',
        // Levant
        'JO' => '962',
        'LB' => '961',
        'PS' => '970',
        'IQ' => '964',
        'SY' => '963',
        // Other Arab
        'YE' => '967',
        'EG' => '20',
        'LY' => '218',
        'TN' => '216',
        'MA' => '212',
        'DZ' => '213',
        'SD' => '249',
        // Non-Arab Middle East
        'IR' => '98',
        'TR' => '90',
        'IL' => '972',
        // South Asia
        'IN' => '91',
        'PK' => '92',
        'BD' => '880',
        'LK' => '94',
        'MV' => '960',
        // East Asia
        'CN' => '86',
        'JP' => '81',
        'KR' => '82',
        'TW' => '886',
        // Southeast Asia
        'SG' => '65',
        'MY' => '60',
        'ID' => '62',
        'PH' => '63',
        'TH' => '66',
        'VN' => '84',
        'MM' => '95',
        'KH' => '855',
        'MN' => '976',
        // Europe
        'GB' => '44',
        'FR' => '33',
        'DE' => '49',
        'IT' => '39',
        'ES' => '34',
        'NL' => '31',
        'BE' => '32',
        'CH' => '41',
        'AT' => '43',
        'NO' => '47',
        'PL' => '48',
        'GR' => '30',
        'CZ' => '420',
        'SE' => '46',
        'DK' => '45',
        'RO' => '40',
        'HU' => '36',
        'UA' => '380',
        // Americas
        'US' => '1',
        'CA' => '1',
        'MX' => '52',
        'BR' => '55',
        'CO' => '57',
        'AR' => '54',
        'CL' => '56',
        'VE' => '58',
        'PE' => '51',
        'EC' => '593',
        'CU' => '53',
        // Africa
        'ZA' => '27',
        'NG' => '234',
        'KE' => '254',
        'GH' => '233',
        'ET' => '251',
        'TZ' => '255',
        'UG' => '256',
        'CM' => '237',
        'CI' => '225',
        'SN' => '221',
        'SO' => '252',
        'RW' => '250',
        // Oceania
        'AU' => '61',
        'NZ' => '64',
    ];

    /**
     * Normalize a raw phone string to digits with the country code in front, or '' when there is no digit.
     * 2026-10-20 21:01 +03: the hint, the international marker and the D18 step are not upstream, task 596.
     *
     * Order (PRD 6.3, D18):
     *   1. Arabic and Persian digits to ASCII; keep digits only; strip leading zeros. The raw input is
     *      "international" when its first digit-or-plus character is + or it starts with 00.
     *   2. Not international, and the digit count equals a local length of the hint country: prefix the
     *      hint code and stop. Verification then rejects non-mobiles (22334455 -> 96522334455).
     *   3. Otherwise the number is taken as it is, minus the trunk zero after the detected country code
     *      (9660551234567 -> 966551234567).
     * The hint is ignored for international input, for an empty hint and for a hint that is not in
     * PHONE_RULES (country detection alone then decides).
     *
     * @param string $raw              Phone as typed
     * @param string $country_hint_cc  Calling code of the order country or the default country, e.g. '965'
     *                                 (a leading + or 00 is tolerated)
     * @return string Digits only, no leading zero; '' when the input holds no digit
     */
    public static function normalize(string $raw, string $country_hint_cc = ''): string
    {
        $mapped = strtr(trim($raw), self::DIGITS_MAP);
        $international = preg_match('/^[^0-9+]*(?:\+|00)/', $mapped) === 1;
        $digits = ltrim((string)preg_replace('/[^0-9]/', '', $mapped), '0');
        if ($digits === '') {
            return '';
        }

        if (!$international) {
            $hint = ltrim((string)preg_replace('/[^0-9]/', '', $country_hint_cc), '0');
            if (
                $hint !== ''
                && isset(self::PHONE_RULES[$hint])
                && in_array(strlen($digits), self::PHONE_RULES[$hint]['localLengths'], true)
            ) {
                return $hint . $digits;
            }
        }

        $cc = self::countryCode($digits);
        if ($cc !== null) {
            $local = self::localPart($digits, $cc);
            if ($local !== '' && $local[0] === '0') {
                return $cc . substr($local, 1);
            }
        }

        return $digits;
    }

    /**
     * Check a normalized number against the country rules.
     *
     * Reasons: empty, email, too_short (under 7 digits), too_long (over 15), bad_length (the local part
     * has a length the country does not use), not_mobile (the local part starts with a digit that is not
     * a mobile prefix). A country that is not in PHONE_RULES passes at 7 to 15 digits.
     * Input that is not a normalize() result (a non-digit, or a leading zero) is reported as bad_length.
     *
     * @param string $normalized Result of normalize()
     * @return array{0: bool, 1: ?string} [ok, reason]
     */
    public static function verify(string $normalized): array
    {
        if ($normalized === '') {
            return [false, 'empty'];
        }
        if (strpos($normalized, '@') !== false) {
            return [false, 'email'];
        }
        if (preg_match('/^[1-9][0-9]*$/', $normalized) !== 1) {
            return [false, 'bad_length'];
        }

        $length = strlen($normalized);
        if ($length < 7) {
            return [false, 'too_short'];
        }
        if ($length > 15) {
            return [false, 'too_long'];
        }

        $cc = self::countryCode($normalized);
        if ($cc === null) {
            return [true, null];
        }

        $rule = self::PHONE_RULES[$cc];
        $local = self::localPart($normalized, $cc);
        if (!in_array(strlen($local), $rule['localLengths'], true)) {
            return [false, 'bad_length'];
        }
        if (!empty($rule['mobileStartDigits']) && !in_array($local[0], $rule['mobileStartDigits'], true)) {
            return [false, 'not_mobile'];
        }

        return [true, null];
    }

    /**
     * Normalize and verify one raw input in a single call (admin phone fields, order phones).
     *
     * An input with an @ is an email and is refused before any digit is read.
     *
     * @param string $raw              Phone as typed
     * @param string $country_hint_cc  See normalize()
     * @return array{0: bool, 1: ?string, 2: string} [ok, reason, normalized]; normalized is '' for
     *                                 empty and email, otherwise the normalize() result even on failure
     */
    public static function validateInput(string $raw, string $country_hint_cc = ''): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [false, 'empty', ''];
        }
        if (strpos($trimmed, '@') !== false) {
            return [false, 'email', ''];
        }

        $normalized = self::normalize($trimmed, $country_hint_cc);
        $verdict = self::verify($normalized);

        return [$verdict[0], $verdict[1], $normalized];
    }

    /**
     * Country code prefix of a normalized number: 3 digits, then 2, then 1 (longest match wins).
     *
     * @param string $normalized Digits only, no leading zero
     * @return string|null A key of PHONE_RULES, or null when no known country matches
     */
    public static function countryCode(string $normalized): ?string
    {
        foreach ([3, 2, 1] as $length) {
            if (strlen($normalized) >= $length) {
                $cc = substr($normalized, 0, $length);
                if (isset(self::PHONE_RULES[$cc])) {
                    return $cc;
                }
            }
        }

        return null;
    }

    /**
     * Calling code for an ISO 3166-1 alpha-2 country code (case and surrounding blanks ignored).
     * Added 2026-10-20 21:01 +03 (not upstream): order addresses in CS-Cart carry ISO2, task 596.
     *
     * @param string $iso2 e.g. 'KW', 'sa'
     * @return string|null e.g. '965'; null for a country that is not in PHONE_RULES
     */
    public static function callingCodeForIso(string $iso2): ?string
    {
        // strtr, not strtoupper: strtoupper follows the process locale on PHP 7 (Turkish i).
        $iso = strtr(trim($iso2), 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');

        return self::ISO_CALLING_CODES[$iso] ?? null;
    }

    /**
     * Options for the "Default country" dropdown on the Settings page: Kuwait first, then every other
     * country in PHONE_RULES sorted by English name. The Settings page translates the names later.
     * Added 2026-10-20 21:01 +03 (not upstream): the D18 dropdown, task 596.
     *
     * PHP turns the numeric string key '965' into the int 965; $options['965'] and $options[965] are
     * the same slot, so cast with (string) when comparing against a stored setting.
     *
     * @return array<int, string> calling code => 'Name (+code)', e.g. 965 => 'Kuwait (+965)'
     */
    public static function countryOptions(): array
    {
        $names = [];
        foreach (array_keys(self::PHONE_RULES) as $cc) {
            $names[$cc] = self::COUNTRY_NAMES[$cc] ?? ('+' . $cc);
        }
        $first = self::FIRST_COUNTRY;
        $firstName = $names[$first];
        unset($names[$first]);
        uasort($names, 'strcasecmp');

        $options = [$first => $firstName . ' (+' . $first . ')'];
        foreach ($names as $cc => $name) {
            $options[$cc] = $name . ' (+' . $cc . ')';
        }

        return $options;
    }

    /**
     * Digits after the country code. substr() returns false on PHP 7 when nothing is left, hence the guard.
     *
     * @param string $digits Normalized number that starts with $cc
     * @param string $cc     Country code
     * @return string
     */
    private static function localPart(string $digits, string $cc): string
    {
        return strlen($digits) > strlen($cc) ? substr($digits, strlen($cc)) : '';
    }
}
