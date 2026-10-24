<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Codes/CodeInput.php
 * CREATED:   2026-10-23 21:26 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Turns what a customer typed or pasted as the SMS code into the code: Arabic-Indic and Persian digits
 *            become ASCII, spaces, hyphens and no-break spaces are removed, and the result must be exactly 6 digits.
 * WHY:       Task M1.3 (SafatMall D1, review focus 3): a customer on an Arabic keyboard types the code in Arabic
 *            digits, or pastes it with a space in the middle; it must match, and anything that is not 6 digits is
 *            refused without counting a wrong attempt.
 * USAGE:     CodeInput::normalize("\u{0661}\u{0662}\u{0663} 456");   // '123456'
 *            CodeInput::normalize('12345');                          // null (not 6 digits)
 * DEPENDS:   Sms\PhoneNormalizer::DIGITS_MAP (the one shared digit table).
 */

namespace Tygh\Addons\Kwtsms\Codes;

use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;

final class CodeInput
{
    /** The length of a code (SafatMall D1). */
    public const LENGTH = 6;

    /**
     * @return string|null The 6 ASCII digits, or null when the entry is not a code
     */
    public static function normalize(string $raw): ?string
    {
        $text = strtr($raw, PhoneNormalizer::DIGITS_MAP);
        // Only the three separators a person types or a paste adds: space, hyphen and the no-break space (U+00A0).
        $text = str_replace([' ', '-', "\u{00A0}"], '', $text);

        return preg_match('/^[0-9]{' . self::LENGTH . '}$/D', $text) === 1 ? $text : null;
    }
}
