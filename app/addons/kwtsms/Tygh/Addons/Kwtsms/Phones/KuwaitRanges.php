<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Phones/KuwaitRanges.php
 * CREATED:   2026-11-16 23:34 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The mobile start digits of Kuwait (country code 965, 8 local digits, mobiles start with 4, 5, 6 or 9)
 *            and the test "this canonical number is a Kuwait number that is not a mobile".
 * WHY:       Task M3.20 (G5, P3-78). With phone_kw_mobile_only ON a Kuwaiti landline or service number is refused at
 *            registration or profile and skipped at send. SOURCE of the list: our own client library
 *            /mnt/d/projects/kwtsms_integrations/kwtsms_php/src/PhoneUtils.php, PHONE_RULES['965'] (file last changed
 *            in commit 16ec6b2, 2026-03-17; the Kuwait line unchanged since its first commit dfe6a84, 2026-03-13),
 *            the same table in every kwtSMS client library (PRD docs/prd-kwtsms-clients-all-languages.md). Ruling
 *            2026-11-14 20:56 +03 (Boss): not a list from the engineer. Details: docs/reference/kw-mobile-prefixes.md.
 * USAGE:     KuwaitRanges::isNonMobile('96522000123');   // true: a 965 number whose 8 local digits start with 2
 *            KuwaitRanges::isNonMobile('96550000123');   // false
 * DEPENDS:   nothing (pure functions on a canonical digit string; normalise first with Sms\PhoneNormalizer).
 */

namespace Tygh\Addons\Kwtsms\Phones;

final class KuwaitRanges
{
    public const CALLING_CODE = '965';

    /** Digits of the local part after 965. */
    public const LOCAL_LENGTH = 8;

    /** First digit of the 8 local digits of a Kuwaiti mobile number. */
    public const MOBILE_PREFIXES = ['4', '5', '6', '9'];

    /**
     * True only for a well-formed Kuwait number (965 + 8 digits) whose first local digit is not a mobile prefix.
     * A wrong length, another country, an empty or non-digit input is false: those are other rules' business.
     *
     * @param string $normalized Canonical digits as PhoneNormalizer::normalize() returns them, e.g. 96550000123
     */
    public static function isNonMobile(string $normalized): bool
    {
        if (preg_match('/^965[0-9]{8}$/', $normalized) !== 1) {
            return false;
        }

        return !in_array($normalized[3], self::MOBILE_PREFIXES, true);
    }
}
