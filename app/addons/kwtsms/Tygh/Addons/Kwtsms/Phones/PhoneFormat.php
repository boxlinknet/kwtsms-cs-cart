<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Phones/PhoneFormat.php
 * CREATED:   2026-10-24 15:29 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The one stored form of a customer phone: '+' and the digits of PhoneNormalizer::validateInput()
 *            (+96550123401). canonical() adds the plus to digits that are already known good; fromRaw() takes any
 *            typing and answers the canonical form, or '' when the input is not a mobile number.
 * WHY:       Task M1.5 (P3-27). The saved profile value (C3), the phone index, the code subject, ownerOf(), the
 *            bypass session key and every rate-limit bucket must compare equal for "50123402", "+965 5012-3402" and
 *            the same number typed with Arabic digits, so they all go through this class.
 * USAGE:     PhoneFormat::canonical('96550123401');  PhoneFormat::fromRaw('5012 3402', '965');
 * DEPENDS:   Sms\PhoneNormalizer (the only normaliser; there is no second one).
 */

namespace Tygh\Addons\Kwtsms\Phones;

use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;

final class PhoneFormat
{
    /**
     * '+' and the digits. Anything that is not a digit is dropped first, so '+96550123401' and '96550123401' give
     * the same form; no digits give ''.
     */
    public static function canonical(string $digits): string
    {
        $only = preg_replace('/[^0-9]/', '', $digits);
        $only = is_string($only) ? $only : '';

        return $only === '' ? '' : '+' . $only;
    }

    /**
     * The canonical form of one typed phone, or '' when PhoneNormalizer::validateInput() does not accept it
     * (empty, an e-mail, too short or long for the country, not a mobile prefix).
     *
     * @param string $hint_cc Calling code used for a number typed without its country, e.g. '965'
     */
    public static function fromRaw(string $raw, string $hint_cc): string
    {
        $verdict = PhoneNormalizer::validateInput($raw, $hint_cc);

        return $verdict[0] ? self::canonical($verdict[2]) : '';
    }
}
