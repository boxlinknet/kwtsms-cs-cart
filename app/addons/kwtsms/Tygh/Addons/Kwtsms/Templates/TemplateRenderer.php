<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Templates/TemplateRenderer.php
 * CREATED:   2026-10-20 22:55 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Fills a template body with its placeholder values ({name} -> value), collapses the spacing and
 *            punctuation that an empty value leaves behind, finds unknown placeholders in a body (save time)
 *            and leftover "{...}" in a rendered text (send time), and fires the public hook
 *            kwtsms_render_template_post.
 * WHY:       PRD 6.4: an empty value renders as nothing and the text around it must still read well
 *            ("Thanks , order" and "KWD, ." are what a naive replace would send), and any "{...}" without a double
 *            quote left in the rendered text blocks the send (outcome blocked_placeholder), so a customer never gets a
 *            literal "{customer_first_name}". Customer-typed values (names) get the anti-smishing cleaner of
 *            PRD 6.2.1; values the shop or core produced (order id, total, brand, status, method names) do
 *            not, because that cleaner would delete the digits of a 6-digit order id or an 8-digit total.
 *            "without a double quote": 2026-10-21 11:51 +03, ruling R42; stamped 2026-10-21 13:03 +03, review M7.
 * USAGE:     All methods are static and pure, except the hook call in render().
 *              TemplateRenderer::render($body, ['order_id' => 10234, 'customer_first_name' => 'Ali', ...])
 *                  -> text. Keys are placeholder names without braces; values are RAW (scalars; null and
 *                     arrays count as empty). The caller (SmsTransport) never pre-cleans them.
 *              TemplateRenderer::unknownPlaceholders($body, ['store_name', 'order_id'])
 *                  -> ['{typo}', ...] unique, in order of appearance (names compared case-sensitively;
 *                     $allowed may carry braces or not). The Templates page needs "Save anyway" for these.
 *              TemplateRenderer::hasLeftover($rendered)
 *                  -> true when any "{...}" without a double quote remains; the send is blocked (blocked_placeholder).
 *            The FINAL full MessageCleaner::clean() of the rendered text is the CALLER's job (SmsService,
 *            Task 1.6, ruling R18: cleaning happens once, at send, and it also decodes the typed escapes
 *            of the stored body exactly once). render() only cleans the customer-typed values it inserts.
 *            The rendered text is therefore the PRE-send form: every backslash of every inserted value is
 *            doubled (2026-10-20 23:59 +03, fix round 1 item 1, task 596), so the one decode at send gives back
 *            the value's own backslashes and a name can never become a line break, tab or CRLF. Never show
 *            render() output to a person or count it without clean() first; the hook sees this form too.
 *            Every value also loses NUL and invalid UTF-8 and the braces `{` and `}`, and is one line. A shop value
 *            gets MessageCleaner::cleanForStorage() (entities decoded, tags and hidden characters gone, escapes NOT
 *            decoded; 2026-10-21 00:17 +03, fix round 1 item 1, task 596), so an entity cannot turn into a
 *            backslash after the doubling.
 *            2026-10-21 00:48 +03, fix round 2 F1, task 596: cleaning and brace removal repeat until nothing changes
 *            (the brace strip can build an entity: "&{#92;n}" -> "&#92;n"), and a customer-typed value (FREE_TEXT)
 *            also loses every "&", so a name can hold no entity and two names that touch cannot form one. "AT&T"
 *            is sent as "ATT". See settleValue().
 *            Hook (PRD 5.2): fn_set_hook('kwtsms_render_template_post', $rendered, $body, $vars), all three
 *            by reference; a handler may change $rendered. $body is the template as stored, $vars the values
 *            as passed in (raw).
 * DEPENDS:   Sms\MessageCleaner (cleanPlaceholderValue, recleanPlaceholderValue, cleanForStorage, dropInvalidUtf8),
 *            CS-Cart fn_set_hook() (tests stub it). 2026-10-21 00:48 +03: list completed, fix round 2, task 596.
 *            2026-10-21 11:33 +03: also MessageCleaner::decodeUnicodeEscapes() (ruling R40), task 596.
 *
 * TASK 1.16 FIX (2026-10-21 11:33 +03, ruling R40, task 596): kwtSMS decodes a literal backslash-u escape whatever
 * stands before it, and the doubled backslash does not protect it (MessageCleaner header, change 20), so clean() at
 * send decodes every one in the rendered text. A value is therefore settled to the form it is sent in: its escapes are
 * decoded in the value itself (a name typed as backslash-u000a is a line break there and becomes a space,
 * backslash-ud83d backslash-ude00 is an emoji and is removed), and a value never ends in the start of an escape (a
 * backslash followed by nothing, "u" or "u" and up to three hex digits loses that backslash), so the text after it,
 * another customer value included, cannot complete one ("Ali\" + "u000a"). For the same reason a backslash in the
 * template directly in front of a placeholder, or in front of "u" and up to three hex digits that run into one
 * ("\{customer_first_name}", "\u00{order_id}"), is removed before the values go in.
 * 2026-10-21 11:51 +03, ruling R42, task 596: a "{...}" holding a double quote ('{"ar":"..."}') is text, not a
 * placeholder, for hasLeftover() and unknownPlaceholders() (TOKEN). Moved here from USAGE 2026-10-21 13:03 +03 to keep
 * the header keys within the first 4000 bytes (review M7).
 */

namespace Tygh\Addons\Kwtsms\Templates;

use Tygh\Addons\Kwtsms\Sms\MessageCleaner;

class TemplateRenderer
{
    /**
     * Placeholders that hold text a customer or a vendor typed. Only these pass through
     * MessageCleaner::cleanPlaceholderValue() (20 characters, no URL, www., domain or run of 6+ digits).
     * Every other value is shop-made (ids, amounts, brand, status and method names): no 20-character cap, no
     * URL, domain or digit-run rule, only the body cleaning without the escape decoding (see valueText()).
     */
    public const FREE_TEXT = ['customer_first_name', 'customer_last_name', 'vendor_name'];

    /**
     * Any "{...}" on one line without a double quote. Wider than the placeholder names on purpose: "{Order_ID}",
     * "{order id}" and "{}" are mistakes too, and none of them may reach a customer (PRD 6.4: any "{...}" without a
     * double quote blocks the send).
     * 2026-10-21 11:51 +03: a double quote inside the braces makes it JSON-like text, not a placeholder: '{"ar":"..."}'
     * is sent as typed (procedure case 14, deviation D-14 closed), ruling R42, task 596. Was /\{[^{}\r\n]*\}/.
     */
    private const TOKEN = '/\{[^{}\r\n"]*\}/';

    /** What an empty value leaves in the text until the tidy pass has run. */
    private const MARK = "\x00";

    /**
     * Passes of clean and brace strip settleValue() makes at most; a value that needs more is dropped.
     * 2026-10-21 00:44 +03: added with settleValue(), Task 1.8 fix round 2 F1, task 596.
     * 2026-10-21 08:35 +03: this stamp added, cleanup item 6.
     */
    private const MAX_VALUE_PASSES = 8;

    /**
     * The start of a literal \uXXXX at the very end of a value: backslashes followed by nothing, by "u", or by "u"
     * and up to three hex digits. The text after the value could complete it, and kwtSMS decodes \uXXXX whatever
     * stands in front of it (MessageCleaner header, change 20). 2026-10-21 11:33 +03: added, ruling R40, task 596.
     */
    private const ESCAPE_START_AT_END = '/\\\\++(?=(?:u[0-9a-fA-F]{0,3})?\z)/';

    /**
     * The start of a reference at the very end of a value: a bare "&", "&#" and decimal digits, or "&#x" / "&#X" and
     * hex digits. The text after the value could complete it ("&#0" + "10;" is a line break). A named start ("AT&T") is
     * not touched: html_entity_decode() needs its ";", which a customer value never holds, and the gateway decodes no
     * named reference. 2026-10-21 12:54 +03: added, review M1, task 596.
     */
    private const REFERENCE_START_AT_END = '/&(?:#(?:[xX][0-9a-fA-F]*|[0-9]*))?\z/';

    /** Separators inside a sentence: , ; : and the Arabic comma and semicolon. */
    private const SEP = '[,;:\x{060C}\x{061B}]';

    /** Sentence end: . ! ? and the Arabic question mark. */
    private const END = '[.!?\x{061F}]';

    /**
     * Fill $body with $vars and fire the hook.
     *
     * @param array<string,mixed> $vars Placeholder name (no braces) => raw value
     */
    public static function render(string $body, array $vars): string
    {
        // A NUL cannot occur in a cleaned body; dropping it keeps the mark below unambiguous. Invalid UTF-8 would
        // make the /u patterns of the tidy pass fail ("PCRE error 4"), so it goes too (fix round 1 item 3).
        $template = MessageCleaner::dropInvalidUtf8(str_replace(self::MARK, '', $body));
        // 2026-10-21 11:33 +03: no backslash of the template may start a \uXXXX that a value completes (ruling R40),
        // task 596.
        $template = self::dropEscapeStartsBefore($template, array_keys($vars));

        $map = [];
        $has_empty = false;
        foreach ($vars as $name => $value) {
            $text = self::valueText((string) $name, $value);
            if ($text === '') {
                $has_empty = true;
                $text = self::MARK;
            }
            $map['{' . $name . '}'] = $text;
        }

        $rendered = $map === [] ? $template : strtr($template, $map);
        if ($has_empty && strpos($rendered, self::MARK) !== false) {
            $rendered = self::tidy($rendered);
        }

        $hook_body = $body;
        $hook_vars = $vars;
        fn_set_hook('kwtsms_render_template_post', $rendered, $hook_body, $hook_vars);

        return (string) $rendered;
    }

    /**
     * Placeholders in $body that are not in $allowed, once each, in order of appearance, braces included.
     * What counts is what the rendered text would hold: a known placeholder is replaced first, and any "{...}"
     * still in the text is reported, so "{{store_name}}" and "{ {store_name} }" are unknown although the name
     * inside is known (rendered they read "{ABC}", which blocks the send).
     * 2026-10-20 23:59 +03: known placeholders are marked before the scan, fix round 1 item 4, task 596.
     *
     * @param array<int,string> $allowed Allowed names, with or without braces
     *
     * @return array<int,string>
     */
    public static function unknownPlaceholders(string $body, array $allowed): array
    {
        $known = [];
        foreach ($allowed as $name) {
            $known[trim((string) $name, '{}')] = true;
        }

        // SOH and STX stand for the braces of a known placeholder while the rest is scanned.
        // 2026-10-21 11:51 +03: the same token shape as TOKEN, no double quote inside (ruling R42), task 596.
        $work = str_replace(["\x01", "\x02"], '', $body);
        $work = (string) preg_replace_callback('/\{([^{}\r\n"]*)\}/', static function (array $m) use ($known): string {
            return isset($known[$m[1]]) ? "\x01" . $m[1] . "\x02" : $m[0];
        }, $work);

        $unknown = [];
        preg_match_all(self::TOKEN, $work, $found);
        foreach ($found[0] as $token) {
            $token = strtr($token, ["\x01" => '{', "\x02" => '}']);
            if (!in_array($token, $unknown, true)) {
                $unknown[] = $token;
            }
        }

        return $unknown;
    }

    /**
     * True when the rendered text still holds a "{...}". Byte-level (no /u): braces are ASCII, so
     * malformed UTF-8 elsewhere in the text cannot hide a token.
     */
    public static function hasLeftover(string $rendered): bool
    {
        return preg_match(self::TOKEN, $rendered) === 1;
    }

    /**
     * The text one placeholder contributes: '' for null, arrays and objects; scalars cast; NUL and invalid UTF-8
     * removed; settled by settleValue() (cleaned, braces removed, one line, trimmed, until nothing changes; a
     * customer-typed value also loses every ampersand); every backslash doubled.
     *
     * The doubling is for the ONE decode at send (ruling R18): the full clean() turns a pair of backslashes into
     * one and "backslash n" into a line break. Without it a name typed as backslash backslash n, or as the
     * entities of those, leaves cleanPlaceholderValue() as backslash n and becomes a line break (or a tab, or
     * CRLF) in the SMS. With it the sent text holds exactly the backslashes the value holds.
     * 2026-10-20 23:59 +03: NUL, invalid UTF-8 and backslashes, fix round 1 items 1 and 3, task 596.
     * 2026-10-21 00:44 +03: the cleaning and the brace strip moved into settleValue(), fix round 2 F1, task 596.
     *
     * @param mixed $value
     */
    private static function valueText(string $name, $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $text = is_bool($value) ? ($value ? '1' : '') : (string) $value;
        $text = MessageCleaner::dropInvalidUtf8(str_replace(self::MARK, '', $text));

        return str_replace('\\', '\\\\', self::settleValue($name, $text, self::MAX_VALUE_PASSES));
    }

    /**
     * Clean a value, remove its braces, and do both again until a pass changes nothing; '' when it does not settle
     * within $max_passes (fail closed).
     *
     * Why a loop: the send decodes entities before anything else, and removing braces can build one out of text that
     * was not: "&{#92;n}" is clean, but "&#92;n" is a backslash n, which the one decode at send turns into a line
     * break ("&{#9;}" into a tab). So whatever the brace strip leaves is cleaned again, and the pass that changes
     * nothing is the value. Each pass either shortens the text or ends the loop.
     *
     * The cleaning is cleanPlaceholderValue() for a customer-typed value (20 characters, no URL, no domain, no run of
     * 6 digits, one line) and cleanForStorage() for every other (entities decoded, tags, hidden characters and emoji
     * gone, escapes NOT decoded); every value is also one line, because a value is a word, not a paragraph.
     *
     * Ruling (fix round 2 F1b): a customer-typed value also loses every ampersand, inside the loop, after the entities
     * are decoded. With no ampersand in a name no entity can exist in it, and none can be formed across two
     * placeholders that touch ("{customer_first_name}{customer_last_name}" holding "&" and "#92;n"). The price is
     * that "AT&T" is sent as "ATT". A shop value keeps its ampersand.
     * 2026-10-21 00:44 +03: added, fix round 2 F1, task 596.
     * 2026-10-21 12:54 +03: a customer-typed value also loses every ";", and every value loses a trailing bare "&",
     * "&#digits" or "&#x..." (REFERENCE_START_AT_END), so no reference can be completed across a shop value and the
     * customer value after it: "B&N" + "ewLine;x", "AT&T" + "ab;x", "&#0" + "10;" built a line break or a tab at send.
     * html_entity_decode() completes no reference without ";", and the gateway's semicolon-less decimal and hex form
     * needs the "&#" the trailing rule removes. "AT&T" and "H&M" stay as they are (review M1), task 596.
     */
    private static function settleValue(string $name, string $text, int $max_passes): string
    {
        $free = in_array($name, self::FREE_TEXT, true);
        // 2026-10-21 12:54 +03: ";" added for a customer-typed value (review M1), task 596.
        $drop = $free ? ['{' => '', '}' => '', '&' => '', ';' => ''] : ['{' => '', '}' => ''];

        // A customer-typed value has its literal escapes decoded once, in the first pass (as clean() does); a second
        // decode would eat one more layer of backslashes.
        $first = true;
        for ($pass = 0; $pass < $max_passes; $pass++) {
            // 2026-10-21 11:33 +03: a shop value has its \uXXXX decoded too (cleanForStorage() keeps them, and clean()
            // at send would decode them across the doubled backslash); the customer cleaners decode them themselves.
            // Ruling R40, task 596.
            if (!$free) {
                $cleaned = MessageCleaner::cleanForStorage(MessageCleaner::decodeUnicodeEscapes($text));
            } elseif ($first) {
                $cleaned = MessageCleaner::cleanPlaceholderValue($text);
            } else {
                $cleaned = MessageCleaner::recleanPlaceholderValue($text);
            }
            $first = false;
            $next = trim((string) preg_replace('/\s+/u', ' ', strtr($cleaned, $drop)));
            // 2026-10-21 11:33 +03: the value does not end in the start of a \uXXXX the next text could complete
            // (ruling R40), task 596.
            $next = (string) preg_replace(self::ESCAPE_START_AT_END, '', $next);
            // 2026-10-21 12:54 +03: nor in the start of a numeric reference or a bare "&" (review M1), task 596.
            $next = trim((string) preg_replace(self::REFERENCE_START_AT_END, '', $next));
            if ($next === $text) {
                return $text;
            }
            $text = $next;
        }

        return '';
    }

    /**
     * The template without the backslashes that start a literal \uXXXX a value would complete: a run of backslashes
     * directly in front of one of the placeholders in $names, or in front of "u" and up to three hex digits that run
     * into one ("\{customer_first_name}", "\u00{order_id}"). Removing a run can put an earlier one in that position
     * ("\u\u{x}"), so it repeats until nothing changes; every round removes at least one character.
     * 2026-10-21 11:33 +03: added, ruling R40, task 596.
     *
     * @param array<int,int|string> $names Placeholder names without braces
     */
    private static function dropEscapeStartsBefore(string $template, array $names): string
    {
        if ($names === [] || strpos($template, '\\') === false) {
            return $template;
        }
        $tokens = [];
        foreach ($names as $name) {
            $tokens[] = preg_quote('{' . $name . '}', '/');
        }
        $pattern = '/\\\\++(?=(?:u[0-9a-fA-F]{0,3})?(?:' . implode('|', $tokens) . '))/';
        do {
            $before = $template;
            $template = self::replace($pattern, '', $template);
        } while ($template !== $before);

        return $template;
    }

    /**
     * Remove the marks left by empty values together with the spaces and separators around them, only
     * there. Text without marks is never touched.
     */
    private static function tidy(string $text): string
    {
        $sep = self::SEP;
        $end = self::END;

        // Marks next to each other (only blanks between them) are one.
        $text = self::replace('/\x00(?:[ \t]*\x00)+/', self::MARK, $text);

        // The rules in order of strength. After a rule changes the text the list starts again from the first
        // rule, so "Total 5, , ." is settled from its end ("Total 5, ." then "Total 5.") before a weaker
        // rule can leave a stray comma behind.
        $rules = [
            // The last item of a sentence or line: drop it with the separator that introduced it.
            ['/(?:[ \t]*' . $sep . ')?[ \t]*\x00(?=[ \t]*(?:' . $end . '|\n|\z))/u', ''],
            // The first item of a line: drop it with the separator that follows.
            ['/(?<![^\n])[ \t]*\x00[ \t]*(?:' . $sep . '+[ \t]*)?/u', ''],
            // Between two separators: one of them goes ("A, , B" -> "A, B").
            ['/(' . $sep . ')[ \t]*\x00[ \t]*' . $sep . '/u', '$1'],
            // Before any punctuation: "Thanks , order" -> "Thanks, order".
            ['/[ \t]*\x00(?=[,;:.!?\x{060C}\x{061B}\x{061F}])/u', ''],
        ];
        // Every rule that fires removes one mark, so the number of marks bounds the passes.
        $passes = substr_count($text, self::MARK) + 1;
        for ($pass = 0; $pass < $passes && strpos($text, self::MARK) !== false; $pass++) {
            foreach ($rules as $rule) {
                $next = self::replace($rule[0], $rule[1], $text);
                if ($next !== $text) {
                    $text = $next;
                    continue 2;
                }
            }
            break;
        }

        // Whatever is left sits inside a sentence: blanks around it become one space, or none when it was
        // glued to the words on both sides.
        $text = (string) preg_replace_callback('/([ \t]*)\x00([ \t]*)/', static function (array $m): string {
            return $m[1] !== '' || $m[2] !== '' ? ' ' : '';
        }, $text);

        return trim($text);
    }

    /**
     * preg_replace() that never returns null: a PCRE failure must not let a half-tidied text through.
     */
    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        $out = preg_replace($pattern, $replacement, $subject);
        if (!is_string($out)) {
            throw new \RuntimeException('PCRE error ' . preg_last_error());
        }

        return $out;
    }
}
