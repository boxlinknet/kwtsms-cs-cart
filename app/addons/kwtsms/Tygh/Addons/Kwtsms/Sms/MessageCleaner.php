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
 *            the page count shown in the admin matches the kwtSMS rules in PRD 6.2 step 9, and so
 *            the common causes of those rejections (invisible-only text, tags, emoji) are removed before the
 *            call. Cleaning does not guarantee acceptance. PRD 6.2.1 keeps a lone < or > (a price such as
 *            "<10 KD"), while the gateway rejects ANY <...> span: those spans are detected by
 *            hasHtmlLikeSpan() and the CALLER blocks the send (SmsService, outcome rejected, code
 *            LOCAL_HTML, no HTTP call). The page cap is also the caller's check, and the gateway has the
 *            last word on everything else.
 *            2026-10-20 21:41 +03, fix round 1, task 596: this paragraph replaced one that claimed a send
 *            "either goes out in a form the gateway accepts or is blocked".
 *            2026-10-20 22:04 +03, fix round 2, task 596: reworded again so it no longer opens by claiming
 *            that what is left is always accepted.
 * USAGE:     All methods are static and pure (no state, no CS-Cart calls, no I/O).
 *              MessageCleaner::clean($body)                  -> cleaned body ('' means blocked_empty)
 *              MessageCleaner::cleanForStorage($body)        -> clean() without the escape decoding, for
 *                                                               template bodies (2026-10-20 22:53 +03, ruling
 *                                                               R18, Task 1.8, task 596); clean(cleanForStorage(x))
 *                                                               === clean(x), the escapes decode once, at send
 *              MessageCleaner::dropInvalidUtf8($text)        -> only the invalid UTF-8 bytes removed (2026-10-20
 *                                                               23:59 +03, Task 1.8 fix round 1, task 596)
 *              MessageCleaner::recleanPlaceholderValue($v)   -> cleanPlaceholderValue() without decoding the escapes
 *                                                               again (2026-10-21 00:46 +03, Task 1.8 fix round 2)
 *              MessageCleaner::hasHtmlLikeSpan($cleaned)     -> true when a "<" is followed later by a ">"
 *                                                               (the gateway's ERR027 rule); the caller blocks
 *              MessageCleaner::cleanPlaceholderValue($name)  -> at most 20 characters on one line, no < or >,
 *                                                               URL, www., domain or run of 6+ digits
 *              MessageCleaner::decodeEscapes($text)          -> literal \r\n \n \t decoded, \\ -> \
 *              MessageCleaner::isUnicode($body)              -> true when any character is above U+007F
 *              MessageCleaner::units($body)                  -> UTF-16 code units (astral = 2)
 *              MessageCleaner::pages($body)                  -> GSM 160 / 153, Unicode 70 / 67
 *              MessageCleaner::MAX_PAGES                     -> 7; pages() may return more, the CALLER blocks
 *            Count the CLEANED body: clean() first, then pages(). The JS counter on the Templates page
 *            (Task 1.12) must give the same numbers; tests/fixtures/send-path-cases.json holds both.
 * DEPENDS:   ext-mbstring (mb_convert_encoding, mb_substr, mb_chr), PCRE with UTF-8 support, PHP 7.4 to 8.3, and
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
 *   8. Literal escapes: \r\n, \n and \t decode; a doubled backslash collapses to one, so "\\n" is a literal
 *      backslash and n (procedure case 16c). A lone \r is NOT decoded (PRD 6.2.1 lists only the three).
 *      2026-10-21 11:33 +03, ruling R40, task 596: corrected. This item used to end "\uXXXX is NOT decoded; the
 *      gateway decodes \uXXXX itself", which made the local count read 6 ASCII characters where kwtSMS stores and
 *      bills one letter (Task 1.16 carry 14b: 80 A + 10 literal backslash-u0633 counted 1 page, charged 2).
 *      \uXXXX is now decoded here, see item 20.
 *   9. Placeholder values (names): cleaned first (so hidden characters, entities and Arabic digits cannot
 *      hide a link), then URLs (scheme://, www., host/path), runs of 6 or more digits removed, then cut to
 *      20 code points, then trimmed. (Extended in change 12.)
 *  10. PHP 7.4 safe: no match, str_contains, named arguments; intdiv() and possessive PCRE quantifiers only.
 *
 * FIX ROUND 1 (2026-10-20 21:41 +03, task 596; review findings and controller rulings R13 and R14):
 *  11. Bare domains in placeholder values. A dotted token with a label from URL_TLDS after its first label
 *      (case-insensitive, also written evil[.]com or evil(.)com, with the path or query that follows) is
 *      removed: "evil.com", "bit.ly", "visit evil.com now", "x.com?a=1", "evil.com.Smith". A dotted token
 *      with a /path is removed whatever its labels. "Mr.Smith", "St.John" and "A.B" stay. A label starts with
 *      a letter or digit, so "evil.com.-" ends at com; a query after a KEPT token is still scanned
 *      ("a.b?q=1x.com").
 *  12. cleanPlaceholderValue also removes every < and >, and collapses each run of whitespace (newlines and
 *      tabs too) into one space, so a customer-typed value can never create an HTML-like span or inject a
 *      line break. It repeats until stable, because removing a bracket can rebuild an entity ("&<>lt;"),
 *      and the cut to 20 code points is inside that loop, because a cut can build a domain
 *      ("abcdefghijklmnopq.coolbeans" is cut to "...q.co", which the next pass removes).
 *  13. hasHtmlLikeSpan(): preg_match('/<[^>]*>/') === 1, the gateway's own ERR027 test (R13). Added so the
 *      caller can block the lone-bracket shapes clean() keeps on purpose, for example "a < b > c".
 *  14. HIDDEN gained U+034F, U+2061-U+2064, U+206A-U+206F, U+3164 and U+FFA0, so a body of invisible
 *      characters cleans to '' and is never billed.
 *  15. TYPOGRAPHY gained (R14): U+2012, U+2013, U+2014, U+2015 and U+2212 to "-", U+2032 to "'" and U+2033 to
 *      '"'. One em dash would turn the whole SMS into UCS-2 (70 / 67 per page instead of 160 / 153). U+00AB,
 *      U+00BB and U+2122 are left alone on purpose.
 *
 * FIX ROUND 2 (2026-10-20 22:04 +03, task 596; controller rulings after the round 1 review):
 *  16. Domain matching checks each hyphen-separated segment of every label after the first against URL_TLDS,
 *      so "evil.com-x", "evil.com--x", "evil.com-pay" and "evil.pay-com" are removed (an em dash maps to "-"
 *      first). "Jo.Smith-Co" is removed too; that cost is accepted. "Anne.Marie-Claire" stays.
 *  17. TYPOGRAPHY also maps U+2010, U+2011 and U+FF0D to "-" (more dash forms that force UCS-2).
 *  18. The WHY paragraph no longer claims that what is left is always accepted, and clean() and cleanBody()
 *      carry dated comments for the round 1 edits.
 *
 * CLEANUP BATCH (2026-10-21 08:35 +03, task 596):
 *  19. The WHY paragraph no longer says the page count matches the gateway's count "exactly": it matches the kwtSMS
 *      rules in PRD 6.2 step 9, and the gateway has the last word on the billed count. Comment stamps that were
 *      later than the commit holding them (c908a6f, 21:56) are set to 21:56.
 *
 * TASK 1.16 FIX (2026-10-21 11:33 +03, ruling R40, task 596):
 *      New public method: MessageCleaner::decodeUnicodeEscapes($text) -> literal \uXXXX decoded the way kwtSMS decodes
 *      them, until none is left ('' when it does not settle); for callers that clean a value without clean().
 *  20. Literal \uXXXX escapes are decoded exactly as the kwtSMS /API/send/ handler decodes them (sendv2_decode_text(),
 *      API/Send.php:55-66, run at Send.php:441 on the JSON-decoded message before \n \r \t, Send.php:98-103, and
 *      stripslashes(), Send.php:442): a lowercase u and exactly four hex digits of either case; a high surrogate
 *      (D800-DBFF) followed by an escaped low surrogate (DC00-DFFF) is one character above U+FFFF; any other surrogate
 *      is "?" (what PHP 8.3 mbstring gives the gateway for it); \u0000 is NUL (removed later with the controls); fewer
 *      than four hex digits, an uppercase U or a space after the u is not an escape; any number of backslashes in
 *      front does not stop it (the gateway has no lookbehind for \u, only for \n \r \t). The gateway decodes once;
 *      here the decode repeats until no escape is left (at most MAX_PASSES rounds, else the body is blocked), because
 *      a decoded backslash (backslash-u005c, then u0041) or a later removal (a hidden character, a tag, an Arabic
 *      digit folded to ASCII) can form a new one, and the body sent must hold none: ApiClient::gatewayEscape()
 *      doubles every backslash, which protects the text from \n \r \t and stripslashes() but not from the \u decoder.
 *      The decode runs in every pass, after the entities and before the literal escapes, so the hidden-character,
 *      emoji and digit passes see the decoded character: a typed backslash-ud83d backslash-ude00 is an emoji and is
 *      removed, backslash-u200b is removed, backslash-u0661 is 1. cleanForStorage() does not decode them (they stay
 *      as typed, like \n), clean() at send does.
 *  21. The same message then goes through sendsms_msg_text() (cportal/inc/sendsms_lib.php:40-61, called at
 *      API/Send.php:105), which decodes decimal entities when the text holds at least one complete "&#N;": every
 *      "&#" with 1 to 10 digits up to U+10FFFF, the ";" optional (PHP 8.3 mb_decode_numericentity()).
 *      html_entity_decode() leaves "&#1;", "&#128;", "&#55357;" or "&#65534;" alone, so settleMarkup() now also runs
 *      that step (gatewayEntities()); a surrogate is removed. Hex and named entities are not decoded by the gateway.
 *      The gateway's steps after JSON decoding, in order: trim (Send.php:180), \uXXXX (55-66), \n \r \t (98-103), the
 *      entities (105), stripslashes() and trim (442-443). What is sent holds no \uXXXX and no decimal entity the
 *      gateway would decode, and every backslash travels doubled, so those steps give back exactly the cleaned body.
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

    /**
     * Labels that make a dotted token in a placeholder value a domain a phone would turn into a link (lower
     * case; matching is case-insensitive): the last label, or any label after the first, and each
     * hyphen-separated segment of such a label counts, so "evil.com-pay" and "evil.pay-com" match too.
     * Anything else, such as "Mr.Smith" or "A.B", is a name.
     * 2026-10-20 21:41 +03: added with the bare-domain rule, fix round 1, task 596.
     * 2026-10-20 22:04 +03: the hyphen-segment rule, fix round 2, task 596.
     * 2026-10-21 08:35 +03: described here, cleanup item 5, task 596.
     */
    public const URL_TLDS = [
        'com', 'net', 'org', 'info', 'biz', 'xyz', 'top', 'io', 'ly', 'me', 'co', 'app', 'link', 'site',
        'online', 'shop', 'store', 'click', 'live', 'kw', 'sa', 'ae', 'qa', 'bh', 'om', 'eg', 'uk', 'us',
        'ru', 'cn', 'in',
    ];

    /** Cleaning passes before a body that keeps changing is treated as hostile. */
    private const MAX_PASSES = 8;

    /**
     * The kwtSMS decoder's own pattern, verbatim (API/Send.php:57): an escaped surrogate pair (groups 1 and 2), or
     * any four hex digits (group 3). 2026-10-21 11:33 +03: added, ruling R40, task 596.
     */
    public const UNICODE_ESCAPE = '/\\\\u([dD][89abAB][0-9a-fA-F]{2})\\\\u([dD][c-fC-F][0-9a-fA-F]{2})'
        . '|\\\\u([0-9a-fA-F]{4})/';

    /** Literal escape pairs, longest first (strtr takes the longest key at each position, no rescanning). */
    private const ESCAPES = [
        '\\\\' => '\\',
        '\\r\\n' => "\r\n",
        '\\n' => "\n",
        '\\t' => "\t",
    ];

    /**
     * Smart quotes, dashes, primes and the ellipsis to ASCII.
     * 2026-10-20 21:41 +03: dashes U+2012-U+2015 and U+2212 to "-", primes U+2032 and U+2033 to ' and ",
     * ruling R14 (an em dash forces the whole SMS to UCS-2), task 596.
     * 2026-10-20 22:04 +03: also the hyphen U+2010, the non-breaking hyphen U+2011 and the full-width
     * hyphen-minus U+FF0D to "-" (same cost, same reason), round 2, task 596.
     */
    private const TYPOGRAPHY = [
        "\u{2010}" => '-',
        "\u{2011}" => '-',
        "\u{FF0D}" => '-',
        "\u{2012}" => '-',
        "\u{2013}" => '-',
        "\u{2014}" => '-',
        "\u{2015}" => '-',
        "\u{2212}" => '-',
        "\u{2032}" => "'",
        "\u{2033}" => '"',
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

    /**
     * A dotted run of labels that does not start in the middle of a word. A label starts with a letter or
     * digit and goes on with letters, digits and hyphens; the dot may be written [.] or (.). Leading hyphens
     * in front of the run are swallowed ("-evil.com"). Group 1 is the run, group 2 the first character of a
     * path, query or fragment that follows it (looked at, not consumed, so a kept run does not hide a domain
     * in its query: "a.b?q=1x.com"). Possessive, so a long run of "a.a.a." or "a-a-a-" is scanned once.
     * 2026-10-20 21:41 +03: bare-domain rule, fix round 1, task 596. 21:55: a label cannot be a lone
     * hyphen (the em dash maps to "-", so "evil.com.-" must still end the run at com). 21:56: the path is a
     * lookahead. Both found by the fuzz test.
     * 2026-10-21 08:35 +03: the stamps 22:02 here, 21:58 (removeDomains) and 22:10 (settlePlaceholder)
     * were later than the commit that holds them (c908a6f, 21:56); set to 21:56, cleanup item 5, task 596.
     */
    private const HOST_RUN = '~(?<![\p{L}\p{N}-])-*+([\p{L}\p{N}][\p{L}\p{N}-]*+'
        . '(?:(?:\.|\[\.\]|\(\.\))[\p{L}\p{N}][\p{L}\p{N}-]*+)++)(?=([/?#])?)~u';

    /** What removeDomains() leaves where it removed a run, so the path after it can be removed too. */
    private const REMOVED_MARK = "\x00";

    /** The marker and the path, query or fragment that follows it (to the next whitespace). */
    private const REMOVED_WITH_PATH = '~\x00(?:[/?#]\S*+)?~u';

    /**
     * Hidden and bidi characters, C0 controls except TAB and LF, DEL and the C1 controls.
     * 2026-10-20 21:41 +03: added U+034F, U+2061-U+2064, U+206A-U+206F, U+3164 and U+FFA0 (invisible
     * math operators, deprecated format characters, grapheme joiner and Hangul fillers), so an
     * invisible-only body is empty and never billed, ruling R13 item 4, task 596.
     */
    private const HIDDEN = '/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{00AD}\x{034F}\x{061C}\x{180E}'
        . '\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{206A}-\x{206F}'
        . '\x{3164}\x{FEFF}\x{FFA0}\x{FFFC}]/u';

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
        // 2026-10-20 22:04 +03: the pass loop moved into cleanBody() so cleanPlaceholderValue() can run the
        // same passes without decoding the literal escapes twice; behaviour of clean() is unchanged
        // (fix round 1, task 596).
        try {
            return self::cleanBody($text, true);
        } catch (\UnexpectedValueException $e) {
            return '';
        }
    }

    /**
     * Clean a template body for storage: clean() without the literal-escape decoding step.
     * 2026-10-20 22:53 +03: added, ruling R18 (cleaning happens once, at send), Task 1.8, task 596.
     * A body is stored cleaned (tags, hidden characters, emoji, Arabic digits, smart quotes, line endings,
     * trim) but the escapes an admin typed (backslash-n, backslash-r-backslash-n, backslash-t, doubled
     * backslash) stay as typed, so the full clean() at send time decodes them exactly once.
     * clean(cleanForStorage($x)) === clean($x) for every $x (tests/Unit/MessageCleanerStorageTest.php).
     * Fails closed like clean(): text that does not settle gives ''.
     *
     * One corner needs a guard: clean() decodes the escapes BEFORE it removes hidden characters, emoji and
     * controls, so a typed backslash, a hidden character and "n" stay a literal backslash-n there, while
     * the stored text (hidden character already gone) would decode to a newline at send. When the two
     * routes disagree, the stored text is clean($x) with its backslash, LF and TAB written back as escapes,
     * which decodes at send to exactly clean($x).
     */
    public static function cleanForStorage(string $text): string
    {
        try {
            // 2026-10-21 11:33 +03: the stored form decodes neither the literal escapes nor \uXXXX (both stay as typed
            // and clean() decodes them at send), ruling R40, task 596.
            $stored = self::cleanBody($text, false, false);
            $expected = self::cleanBody($text, true);
            if (self::cleanBody($stored, true) === $expected) {
                return $stored;
            }

            $encoded = strtr($expected, ['\\' => '\\\\', "\n" => '\\n', "\t" => '\\t']);

            return self::cleanBody($encoded, true) === $expected ? $encoded : '';
        } catch (\UnexpectedValueException $e) {
            return '';
        }
    }

    /**
     * Remove every byte that is not part of a valid UTF-8 sequence, and nothing else (step 1 of PRD 6.2.1).
     * For callers that must run a /u pattern on text they have not cleaned yet (the template renderer, whose
     * tidy pass throws "PCRE error 4" on malformed input). Fails closed: '' when PCRE fails.
     * 2026-10-20 23:59 +03: added, Task 1.8 fix round 1 item 3, task 596.
     */
    public static function dropInvalidUtf8(string $text): string
    {
        try {
            return self::stripInvalidUtf8($text);
        } catch (\UnexpectedValueException $e) {
            return '';
        }
    }

    /**
     * True when the text holds a "<" followed later by a ">": the gateway's own ERR027 rule, preg_match of
     * /<[^>]*>/. clean() keeps a lone < or > on purpose (PRD 6.2.1, "<10 KD"), but "a < b > c" or
     * "x < y and 5 > 3" still contains such a span and the gateway rejects it. Call this AFTER clean();
     * when it returns true the caller blocks the send locally (SmsService: outcome rejected, code
     * LOCAL_HTML, no HTTP call) and the Templates page warns on save.
     * 2026-10-20 21:41 +03: added, controller ruling R13 item 2, task 596.
     */
    public static function hasHtmlLikeSpan(string $text): bool
    {
        return preg_match('/<[^>]*>/', $text) === 1;
    }

    /**
     * Clean a customer-typed value that is inserted into a template (names): clean it like a body, then
     * remove every < and >, scheme:// URLs, "www." hosts, bare domains (a dotted token with one of URL_TLDS as
     * a label after its first, also in a hyphen-separated segment of a label: "evil.com-pay"; also written
     * [.] or (.); or any dotted token followed by a /path) and runs of 6 or more
     * digits, collapse each run of whitespace (newlines and tabs too) into one space, cut to 20 code points
     * and trim. Anti-smishing: a phone turns a bare domain into a link, a name cannot inject a line break
     * or an HTML-like span. Returns '' when the value cannot be settled (fail closed).
     * 2026-10-20 21:41 +03: bare domains, brackets and whitespace added, fix round 1, task 596.
     */
    public static function cleanPlaceholderValue(string $value): string
    {
        // 2026-10-21 00:46 +03: delegates to placeholderValue() (the escapes decode here, once), Task 1.8 fix round 2
        // F1, task 596. Comment added 2026-10-21 08:35 +03, cleanup item 6.
        return self::placeholderValue($value, true);
    }

    /**
     * cleanPlaceholderValue() for a value that was already cleaned once: the same cleaning, the literal escapes are NOT
     * decoded. TemplateRenderer cleans a value more than once (it removes braces between the passes) and uses this from
     * the second pass on, because a second decode would eat one more layer of backslashes. The public signature of
     * cleanPlaceholderValue() is pinned by MessageCleanerTest, so this is a method of its own.
     * 2026-10-21 00:46 +03: added, Task 1.8 fix round 2 F1, task 596.
     */
    public static function recleanPlaceholderValue(string $value): string
    {
        return self::placeholderValue($value, false);
    }

    /**
     * The one body of cleanPlaceholderValue() ($decode_escapes true) and recleanPlaceholderValue() (false).
     * 2026-10-21 00:46 +03: added, Task 1.8 fix round 2 F1, task 596.
     * 2026-10-21 08:35 +03: this docblock added, cleanup item 6, task 596.
     */
    private static function placeholderValue(string $value, bool $decode_escapes): string
    {
        try {
            return self::settlePlaceholder(self::cleanBody($value, $decode_escapes), self::MAX_PASSES);
        } catch (\UnexpectedValueException $e) {
            return '';
        }
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
     * Decode literal \uXXXX escapes the way kwtSMS decodes them (header, change 20), again and again until none is
     * left; '' when the text still holds one after MAX_PASSES rounds (fail closed, like clean()). Only \uXXXX: the
     * other escapes are decodeEscapes(). For callers that clean a value without clean(), such as the shop values of
     * TemplateRenderer.
     * 2026-10-21 11:33 +03: added, ruling R40, task 596.
     */
    public static function decodeUnicodeEscapes(string $text): string
    {
        try {
            return self::decodeUnicode($text);
        } catch (\UnexpectedValueException $e) {
            return '';
        }
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
     * The cleaning passes of PRD 6.2.1 (see the header, changes 2 and 6). The literal escapes (step 4) are
     * decoded once, on the first pass, and only when $decodeEscapes is true: a second decode would turn the
     * result of "\\n" into a newline.
     *
     * 2026-10-21 11:33 +03: $decodeUnicode (default true) decodes \uXXXX in EVERY pass, after the entities and before
     * the literal escapes and the hidden-character, emoji and digit passes, so the settled text holds no \uXXXX; only
     * cleanForStorage() passes false. Ruling R40, task 596.
     *
     * @throws \UnexpectedValueException When the text keeps changing after MAX_PASSES or PCRE fails
     */
    private static function cleanBody(string $text, bool $decodeEscapes, bool $decodeUnicode = true): string
    {
        // 2026-10-20 22:04 +03: extracted from clean() with the $decodeEscapes switch and a thrown
        // UnexpectedValueException instead of a silent '' (clean() and cleanPlaceholderValue() catch it and
        // fail closed). Why: the placeholder cleaner re-runs these passes after removing brackets and must
        // not decode "\\n" a second time. Fix round 1, task 596.
        $text = self::stripInvalidUtf8($text);

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $next = self::settleMarkup($text);
            // 2026-10-21 11:33 +03: \uXXXX as kwtSMS decodes it, every pass (ruling R40), task 596.
            if ($decodeUnicode) {
                $next = self::decodeUnicode($next);
            }
            if ($decodeEscapes) {
                $next = self::decodeEscapes($next);
                $decodeEscapes = false;
            }
            $next = self::normalize($next);

            if ($next === $text) {
                return $text;
            }
            $text = $next;
        }

        throw new \UnexpectedValueException('text does not settle');
    }

    /**
     * The placeholder passes of cleanPlaceholderValue(), repeated until a whole pass changes nothing.
     * 2026-10-20 21:41 +03: added, fix round 1, task 596. 21:56: split out so the "does not settle" branch
     * can be tested with a small $maxPasses.
     *
     * @throws \UnexpectedValueException When the value still changes after $maxPasses or PCRE fails
     */
    private static function settlePlaceholder(string $value, int $maxPasses): string
    {
        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $before = $value;
            $value = strtr($value, ['<' => '', '>' => '']);
            // Removing a bracket can rebuild an entity ("&<>lt;"), so the body cleaner runs again. The
            // literal escapes were decoded once already and are not decoded a second time.
            $value = self::cleanBody($value, false);
            $value = self::replace('~[A-Za-z][A-Za-z0-9+.-]*+://\S*+~u', '', $value);
            $value = self::replace('~www\.\S*+~iu', '', $value);
            $value = self::removeDomains($value);
            $value = self::replace('~[0-9]{6,}~', '', $value);
            $value = trim(self::replace('/\s+/u', ' ', $value));
            // The cut is part of the loop: cutting "abcdefghijklmnopq.coolbeans" at 20 leaves
            // "abcdefghijklmnopq.co", a domain, which the next pass removes.
            $value = trim(mb_substr($value, 0, self::PLACEHOLDER_MAX_CHARS, 'UTF-8'));
            if ($value === $before) {
                return $value;
            }
        }

        throw new \UnexpectedValueException('placeholder value does not settle');
    }

    /**
     * Remove bare domains from a placeholder value: a dotted run that has a label from URL_TLDS after its
     * first label (the last label, or an earlier one: a phone links "evil.com" inside "evil.com.Smith"), or
     * any dotted run followed by a /path. Each hyphen-separated segment of a label is checked, not the label
     * as a whole: "evil.com-x", "evil.com--x", "evil.com-pay" and "evil.pay-com" are links too (a phone
     * stops the host at the hyphen), and "Jo.Smith-Co" is removed (that cost is accepted). "Mr.Smith",
     * "St.John", "A.B" and "Anne.Marie-Claire" are names and stay.
     * 2026-10-20 21:41 +03: added, fix round 1, task 596. 21:56: any label after the first, not only the
     * last, found by the fuzz test ("Smith" + "evil.COM" + ".Mr.Smith" built a linkable domain).
     * 2026-10-20 22:04 +03: the hyphen-segment rule, fix round 2, task 596.
     * 2026-10-21 08:35 +03: described here, cleanup item 5, task 596.
     *
     * @throws \UnexpectedValueException When PCRE fails
     */
    private static function removeDomains(string $text): string
    {
        $out = preg_replace_callback(self::HOST_RUN, static function (array $m): string {
            $labels = preg_split('/\.|\[\.\]|\(\.\)/', $m[1]);

            if (($m[2] ?? '') === '/') {
                return self::REMOVED_MARK;
            }
            foreach (array_slice((array) $labels, 1) as $label) {
                // 2026-10-20 22:04 +03: every hyphen-separated segment of the label is checked, not the label
                // as a whole: "evil.com-x", "evil.com--x", "evil.com-pay" and "evil.pay-com" are still links
                // (a phone stops the host at the hyphen). Round 2, task 596.
                foreach (explode('-', $label) as $segment) {
                    $tld = strtr($segment, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
                    if (in_array($tld, self::URL_TLDS, true)) {
                        return self::REMOVED_MARK;
                    }
                }
            }

            return $m[0];
        }, $text);
        if (!is_string($out)) {
            throw new \UnexpectedValueException('PCRE error ' . preg_last_error());
        }

        // NUL cannot occur in a cleaned value, so the marker is unambiguous.
        return self::replace(self::REMOVED_WITH_PATH, '', $out);
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
     * 2026-10-21 11:42 +03: html_entity_decode() is followed by gatewayEntities(), the numeric entities kwtSMS
     * decodes itself (header, change 21), ruling R40, task 596.
     *
     * @throws \UnexpectedValueException When the text is still changing after MAX_PASSES or PCRE fails
     */
    private static function settleMarkup(string $text): string
    {
        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $before = $text;
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = self::gatewayEntities($text);
            $text = self::replace(self::COMMENT, '', $text);
            $text = self::replace(self::TAG, '', $text);
            if ($text === $before) {
                return $text;
            }
        }

        throw new \UnexpectedValueException('entity nesting is too deep');
    }

    /**
     * The decimal entities kwtSMS decodes in the message (sendsms_msg_text(), cportal/inc/sendsms_lib.php:56-59, called
     * from API/Send.php:105): only when the text holds a complete "&#123;" or "&amp;#123;"; then "&amp;#123;" becomes
     * "&#123;" and mb_decode_numericentity() (PHP 8.3) decodes every "&#" with 1 to 10 digits whose value is at most
     * U+10FFFF, the ";" after it optional. html_entity_decode() before this leaves the control characters, the C1
     * range, the surrogates and the noncharacters alone, and the gateway would decode them: "&#128;" is one character
     * above U+007F there (the body turns Unicode) and six ASCII characters here. A surrogate is removed here (the
     * gateway would store a broken byte sequence). See the header, change 21.
     * 2026-10-21 11:42 +03: added, ruling R40, task 596.
     *
     * @throws \UnexpectedValueException When PCRE fails
     */
    private static function gatewayEntities(string $text): string
    {
        if (strpos($text, '&#') === false || preg_match('/&(?:amp;)?#\d+;/', $text) !== 1) {
            return $text;
        }
        $text = self::replace('/&amp;#(\d+);/', '&#$1;', $text);
        $out = preg_replace_callback('/&#([0-9]{1,10})(?![0-9]);?/', static function (array $m): string {
            $digits = ltrim($m[1], '0');
            if (strlen($digits) > 7 || (int) $digits > 0x10FFFF) {
                return $m[0];
            }
            $cp = (int) $digits;

            return $cp >= 0xD800 && $cp <= 0xDFFF ? '' : (string) mb_chr($cp, 'UTF-8');
        }, $text);
        if (!is_string($out)) {
            throw new \UnexpectedValueException('PCRE error ' . preg_last_error());
        }

        return $out;
    }

    /**
     * Literal \uXXXX escapes decoded as kwtSMS decodes them (header, change 20), repeated until none is left.
     * One round is the gateway's own preg_replace_callback (API/Send.php:57-65): left to right, no rescan of what it
     * produced, so backslash-u005c followed by u0041 needs a second round. Each round that changes the text makes
     * it shorter.
     * 2026-10-21 11:33 +03: added, ruling R40, task 596.
     *
     * @throws \UnexpectedValueException When an escape is still left after MAX_PASSES rounds or PCRE fails
     */
    private static function decodeUnicode(string $text): string
    {
        for ($round = 0; $round < self::MAX_PASSES; $round++) {
            if (strpos($text, '\\u') === false) {
                return $text;
            }
            $next = preg_replace_callback(self::UNICODE_ESCAPE, static function (array $m): string {
                if ($m[1] !== '' && isset($m[2]) && $m[2] !== '') {
                    $high = (int) hexdec($m[1]) - 0xD800;
                    $low = (int) hexdec($m[2]) - 0xDC00;

                    return (string) mb_chr(0x10000 + ($high << 10) + $low, 'UTF-8');
                }
                $cp = (int) hexdec($m[3]);

                // A surrogate on its own: the gateway's mb_convert_encoding() answers "?" for it (PHP 8.3).
                return $cp >= 0xD800 && $cp <= 0xDFFF ? '?' : (string) mb_chr($cp, 'UTF-8');
            }, $text);
            if (!is_string($next)) {
                throw new \UnexpectedValueException('PCRE error ' . preg_last_error());
            }
            if ($next === $text) {
                return $text;
            }
            $text = $next;
        }

        if (preg_match(self::UNICODE_ESCAPE, $text) === 1) {
            throw new \UnexpectedValueException('unicode escapes nested too deep');
        }

        return $text;
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
