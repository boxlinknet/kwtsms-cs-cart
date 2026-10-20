<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Sms/MessageCleaner.php
 * CREATED:   2026-10-20 21:20 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Message cleaning for every SMS body (PRD 6.2.1), the Unicode test, UTF-16 unit counting and
 *            page counting (PRD 6.2 step 9), literal escape decoding, and the stricter cleaner for
 *            customer-typed placeholder values such as names (anti-smishing).
 * WHY:       The gateway rejects or mis-bills what it cannot carry: an empty body (ERR009), HTML (ERR027),
 *            emoji (ERR036, gw7 and gw1), more than 7 pages (ERR012). The body is cleaned locally first so
 *            a send either goes out in a form the gateway accepts or is blocked before it costs credits,
 *            and the page count shown in the admin and charged for matches the gateway's count exactly.
 * USAGE:     All methods are static and pure (no state, no CS-Cart calls, no I/O).
 *              MessageCleaner::clean($body)                  -> cleaned body ('' means blocked_empty)
 *              MessageCleaner::cleanPlaceholderValue($name)  -> at most 20 characters, no URL, www. or
 *                                                               run of 6 or more digits
 *              MessageCleaner::decodeEscapes($text)          -> literal \r\n \n \t decoded, \\ -> \
 *              MessageCleaner::isUnicode($body)              -> true when any character is above U+007F
 *              MessageCleaner::units($body)                  -> UTF-16 code units (astral = 2)
 *              MessageCleaner::pages($body)                  -> GSM 160 / 153, Unicode 70 / 67
 *              MessageCleaner::MAX_PAGES                     -> 7; pages() may return more, the CALLER blocks
 *            Count the CLEANED body: clean() first, then pages(). The JS counter on the Templates page
 *            (Task 1.12) must give the same numbers; tests/fixtures/send-path-cases.json holds both.
 * DEPENDS:   ext-mbstring (mb_convert_encoding, mb_substr), PCRE with UTF-8 support, PHP 7.4 to 8.3, and
 *            PhoneNormalizer::DIGITS_MAP (the 20 Arabic-Indic and Persian digits, one shared table).
 *
 * UPSTREAM:  Port of kwtsms-php v1.7.1, src/MessageUtils.php (MIT, Copyright (c) 2026 kwtSMS / boxlink net).
 *            Commit  132285b9f39bcfe33925dfbf9ea614026b87240a
 *            sha256  c57448242600414b069ac911979e32e5d8e8030777a4f48a0ad6cf75857ddb4a
 *            kwtsms-php is not a dependency (D18); this file is a copy that evolves on its own.
 *            Kept from upstream: the invalid UTF-8 strip, the hidden-character list, the emoji ranges
 *            (U+1F000-U+1FAFF, U+20E3, U+E0000-U+E007F, U+2600-U+27BF, U+FE00-U+FE0F), the control
 *            character removal and the digit map.
 *
 * DELIBERATE CHANGES FROM UPSTREAM (2026-10-20 21:20 +03, task 596):
 *   1. API: clean_message() is clean(); added decodeEscapes(), isUnicode(), units(), pages(),
 *      cleanPlaceholderValue() and MAX_PAGES. Namespace and class name changed.
 *   2. PRD 6.2.1 order: invalid UTF-8, html_entity_decode, real tags and HTML comments, literal escapes,
 *      CRLF and lone CR to LF, U+2028 and U+2029 to LF, digits, hidden and bidi characters, C0 controls
 *      except TAB and LF, DEL, C1 controls, special spaces to a space, emoji and pictographs, smart quotes
 *      and U+2026, trim. Upstream used strip_tags() (which also deletes "<10 KD ... >" spans) and did
 *      not decode entities, so "&#60;script&#62;" got through; both are fixed here.
 *   3. Emoji additions to the upstream ranges (PRD): U+2190-U+21FF, U+2300-U+23FF, U+24C2,
 *      U+25A0-U+25FF, U+2900-U+297F, U+2B00-U+2BFF, U+3030, U+303D, U+3297, U+3299, and U+00A9 or U+00AE
 *      followed by U+FE0F (a bare U+00A9 or U+00AE is kept, and makes the body Unicode).
 *   4. Hidden characters: added U+200E-U+200F, U+202A-U+202E, U+2066-U+2069, U+061C, U+180E (bidi
 *      marks) to the upstream list; U+200C and U+200D (ZWNJ, ZWJ) stay removed.
 *   5. Upstream's invalid UTF-8 pattern accepted overlong forms, encoded surrogates and bytes above
 *      U+10FFFF; the later /u patterns then failed and upstream silently skipped them (`?? $text`),
 *      leaving the body half cleaned. This port validates strictly (RFC 3629) and fails closed.
 *   6. Fixed point: entity decoding and tag stripping repeat until the text stops changing, and the
 *      whole pass repeats if removing a hidden character rebuilt a tag or an entity (for example
 *      "&amp;lt;b&amp;gt;", "<scr<b>ipt>" or "<" + ZWSP + "b>"). The gateway decodes entities itself
 *      (procedure case 26), so the body it receives must not decode into a tag. A body that is still
 *      changing after 8 passes, or any PCRE failure, returns '' (blocked_empty): fail closed.
 *   7. Smart quotes: U+2018 U+2019 U+201A U+201B to ', U+201C U+201D U+201E U+201F to "; U+2026 to "...".
 *   8. Literal escapes follow the gateway's decoder: \r\n, \n and \t decode; a doubled backslash
 *      collapses to one, so "\\n" is a literal backslash and n (procedure case 16c). A lone \r and \uXXXX
 *      are NOT decoded (PRD 6.2.1 lists only the three); the gateway decodes \uXXXX itself.
 *   9. Placeholder values (names): cleaned first (so hidden characters, entities and Arabic digits cannot
 *      hide a link), then URLs (scheme://, www., host/path), runs of 6 or more digits removed, then cut to
 *      20 code points, then trimmed.
 *  10. PHP 7.4 safe: no match, str_contains, named arguments; intdiv() and possessive PCRE quantifiers only.
 */

namespace Tygh\Addons\Kwtsms\Sms;

class MessageCleaner
{
    /**
     * Longest message the gateway accepts, in pages. pages() can return more; the caller blocks the send
     * (PRD 6.2 step 9, blocked_too_long).
     */
    public const MAX_PAGES = 7;

    /** Units in one page of an unsplit message and in each page of a split one (ASCII body). */
    public const GSM_SINGLE = 160;
    public const GSM_MULTI = 153;

    /** Same for a body with any character above U+007F (UCS-2 / UTF-16). */
    public const UNICODE_SINGLE = 70;
    public const UNICODE_MULTI = 67;

    /** Longest customer-typed placeholder value, in code points (PRD 6.2.1). */
    public const PLACEHOLDER_MAX_CHARS = 20;

    /** Cleaning passes before a body that keeps changing is treated as hostile. */
    private const MAX_PASSES = 8;

    /** Literal escape pairs, longest first (strtr takes the longest key at each position, no rescanning). */
    private const ESCAPES = [
        '\\\\' => '\\',
        '\\r\\n' => "\r\n",
        '\\n' => "\n",
        '\\t' => "\t",
    ];

    /** Smart quotes and the ellipsis to ASCII. */
    private const TYPOGRAPHY = [
        "\u{2018}" => "'",
        "\u{2019}" => "'",
        "\u{201A}" => "'",
        "\u{201B}" => "'",
        "\u{201C}" => '"',
        "\u{201D}" => '"',
        "\u{201E}" => '"',
        "\u{201F}" => '"',
        "\u{2026}" => '...',
    ];

    /**
     * Valid UTF-8 (RFC 3629) is skipped; every other byte at or above 0x80 is removed. Runs without /u so it
     * can read malformed input.
     */
    private const INVALID_UTF8 = '/[\x00-\x7F](*SKIP)(*FAIL)'
        . '|[\xC2-\xDF][\x80-\xBF](*SKIP)(*FAIL)'
        . '|\xE0[\xA0-\xBF][\x80-\xBF](*SKIP)(*FAIL)'
        . '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}(*SKIP)(*FAIL)'
        . '|\xED[\x80-\x9F][\x80-\xBF](*SKIP)(*FAIL)'
        . '|\xF0[\x90-\xBF][\x80-\xBF]{2}(*SKIP)(*FAIL)'
        . '|[\xF1-\xF3][\x80-\xBF]{3}(*SKIP)(*FAIL)'
        . '|\xF4[\x80-\x8F][\x80-\xBF]{2}(*SKIP)(*FAIL)'
        . '|[\x80-\xFF]/s';

    /** A real tag: < or </ then a letter, letters, digits or hyphens, optional attributes, optional /, >. */
    private const TAG = '~</?[A-Za-z][A-Za-z0-9-]*+(?:\s[^<>]*+)?/?>~u';

    private const COMMENT = '~<!--.*?-->~su';

    /** Hidden and bidi characters, C0 controls except TAB and LF, DEL and the C1 controls. */
    private const HIDDEN = '/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{00AD}\x{061C}\x{180E}'
        . '\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}\x{FFFC}]/u';

    /** Spaces that are not U+0020: U+2000-U+200A, U+202F, U+205F, U+3000 and NBSP. */
    private const SPECIAL_SPACES = '/[\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}\x{00A0}]/u';

    /** The copyright and registered signs are pictographs only when followed by the emoji selector. */
    private const SIGN_WITH_SELECTOR = '/[\x{00A9}\x{00AE}]\x{FE0F}/u';

    /** Emoji and pictographs: the upstream ranges plus the PRD additions (header, change 3). */
    private const EMOJI = '/[\x{1F000}-\x{1FAFF}\x{20E3}\x{E0000}-\x{E007F}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}'
        . '\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{24C2}\x{25A0}-\x{25FF}\x{2900}-\x{297F}\x{2B00}-\x{2BFF}'
        . '\x{3030}\x{303D}\x{3297}\x{3299}]/u';

    /**
     * Clean a message body (PRD 6.2.1). The result may be empty; the caller treats that as blocked_empty.
     * Text that cannot be cleaned safely (still changing after 8 passes, PCRE failure) is returned as ''.
     */
    public static function clean(string $text): string
    {
        try {
            $text = self::stripInvalidUtf8($text);
            $escapesDecoded = false;

            for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
                $next = self::settleMarkup($text);
                if (!$escapesDecoded) {
                    // Step 4 runs once; a second decode would turn the result of "\\n" into a newline.
                    $next = self::decodeEscapes($next);
                    $escapesDecoded = true;
                }
                $next = self::normalize($next);

                if ($next === $text) {
                    return $text;
                }
                $text = $next;
            }
        } catch (\UnexpectedValueException $e) {
            return '';
        }

        return '';
    }

    /**
     * Clean a customer-typed value that is inserted into a template (names): clean it like a body, remove
     * URLs, "www." hosts and runs of 6 or more digits, cut to 20 code points, trim. Anti-smishing.
     */
    public static function cleanPlaceholderValue(string $value): string
    {
        $value = self::clean($value);
        if ($value === '') {
            return '';
        }

        try {
            for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
                $before = $value;
                // scheme://anything, www.anything, host.tld/path
                $value = self::replace('~[A-Za-z][A-Za-z0-9+.-]*+://\S*+~u', '', $value);
                $value = self::replace('~www\.\S*+~iu', '', $value);
                $value = self::replace('~(?<![A-Za-z0-9.-])[A-Za-z0-9-]++(?:\.[A-Za-z0-9-]++)++/\S*+~u', '', $value);
                $value = self::replace('~[0-9]{6,}~', '', $value);
                if ($value === $before) {
                    break;
                }
            }
        } catch (\UnexpectedValueException $e) {
            return '';
        }

        return trim(mb_substr(trim($value), 0, self::PLACEHOLDER_MAX_CHARS, 'UTF-8'));
    }

    /**
     * Decode the literal escapes a client sends as plain characters: backslash r backslash n, backslash n
     * and backslash t become CRLF, LF and TAB; two backslashes become one (so "\\n" stays a backslash and n,
     * never a newline). Anything else after a backslash is left as typed.
     */
    public static function decodeEscapes(string $text): string
    {
        if (strpos($text, '\\') === false) {
            return $text;
        }

        return strtr($text, self::ESCAPES);
    }

    /**
     * True when any character is above U+007F: the whole message is then Unicode (70 / 67 per page).
     * Byte-level on purpose: every non-ASCII character, valid or not, contains a byte above 0x7F.
     */
    public static function isUnicode(string $text): bool
    {
        return preg_match('/[^\x00-\x7F]/', $text) === 1;
    }

    /**
     * Length in UTF-16 code units: a character above U+FFFF counts 2. Pass a cleaned (valid UTF-8) body.
     */
    public static function units(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * Pages the gateway bills: 0 for an empty body; ASCII 160 / 153, Unicode 70 / 67 UTF-16 units.
     * May exceed MAX_PAGES; the caller blocks.
     */
    public static function pages(string $text): int
    {
        $units = self::units($text);
        if ($units === 0) {
            return 0;
        }

        $unicode = self::isUnicode($text);
        $single = $unicode ? self::UNICODE_SINGLE : self::GSM_SINGLE;
        $multi = $unicode ? self::UNICODE_MULTI : self::GSM_MULTI;

        return $units <= $single ? 1 : intdiv($units + $multi - 1, $multi);
    }

    /**
     * Step 1 of PRD 6.2.1: remove every byte that is not part of a valid UTF-8 sequence.
     *
     * @throws \UnexpectedValueException When PCRE fails
     */
    private static function stripInvalidUtf8(string $text): string
    {
        if (preg_match('//u', $text) === 1) {
            return $text;
        }

        $text = self::replace(self::INVALID_UTF8, '', $text);
        if (preg_match('//u', $text) !== 1) {
            throw new \UnexpectedValueException('invalid UTF-8 survived the strip');
        }

        return $text;
    }

    /**
     * Steps 2 and 3: decode entities, then strip HTML comments and real tags, until the text stops changing.
     *
     * @throws \UnexpectedValueException When the text is still changing after MAX_PASSES or PCRE fails
     */
    private static function settleMarkup(string $text): string
    {
        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $before = $text;
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = self::replace(self::COMMENT, '', $text);
            $text = self::replace(self::TAG, '', $text);
            if ($text === $before) {
                return $text;
            }
        }

        throw new \UnexpectedValueException('entity nesting is too deep');
    }

    /**
     * Steps 5 to 11: line endings, digits, hidden characters and controls, special spaces, emoji, smart
     * quotes, trim.
     *
     * @throws \UnexpectedValueException When PCRE fails
     */
    private static function normalize(string $text): string
    {
        $text = self::replace('/\r\n?/', "\n", $text);
        $text = self::replace('/[\x{2028}\x{2029}]/u', "\n", $text);
        $text = strtr($text, PhoneNormalizer::DIGITS_MAP);
        $text = self::replace(self::HIDDEN, '', $text);
        $text = self::replace(self::SPECIAL_SPACES, ' ', $text);
        $text = self::replace(self::SIGN_WITH_SELECTOR, '', $text);
        $text = self::replace(self::EMOJI, '', $text);
        $text = strtr($text, self::TYPOGRAPHY);

        return trim($text);
    }

    /**
     * preg_replace() that never returns null: a PCRE error (backtrack or JIT stack limit) must not let a
     * half-cleaned body through.
     *
     * @throws \UnexpectedValueException
     */
    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        $out = preg_replace($pattern, $replacement, $subject);
        if (!is_string($out)) {
            throw new \UnexpectedValueException('PCRE error ' . preg_last_error());
        }

        return $out;
    }
}
