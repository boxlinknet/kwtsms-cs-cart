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
 *            ("Thanks , order" and "KWD, ." are what a naive replace would send), and any "{...}" left in the
 *            rendered text blocks the send (outcome blocked_placeholder), so a customer never receives a
 *            literal "{customer_first_name}". Customer-typed values (names) get the anti-smishing cleaner of
 *            PRD 6.2.1; values the shop or core produced (order id, total, brand, status, method names) do
 *            not, because that cleaner would delete the digits of a 6-digit order id or an 8-digit total.
 * USAGE:     All methods are static and pure, except the hook call in render().
 *              TemplateRenderer::render($body, ['order_id' => 10234, 'customer_first_name' => 'Ali', ...])
 *                  -> text. Keys are placeholder names without braces; values are RAW (scalars; null and
 *                     arrays count as empty). The caller (SmsTransport) never pre-cleans them.
 *              TemplateRenderer::unknownPlaceholders($body, ['store_name', 'order_id'])
 *                  -> ['{typo}', ...] unique, in order of appearance (names compared case-sensitively;
 *                     $allowed may carry braces or not). The Templates page needs "Save anyway" for these.
 *              TemplateRenderer::hasLeftover($rendered)
 *                  -> true when any "{...}" remains; the send is blocked (blocked_placeholder).
 *            The FINAL full MessageCleaner::clean() of the rendered text is the CALLER's job (SmsService,
 *            Task 1.6, ruling R18: cleaning happens once, at send, and it also decodes the typed escapes
 *            of the stored body exactly once). render() only cleans the customer-typed values it inserts.
 *            The rendered text is therefore the PRE-send form: every backslash of every inserted value is
 *            doubled (2026-10-20 23:59 +03, fix round 1 item 1, task 596), so the one decode at send gives back
 *            the value's own backslashes and a name can never become a line break, tab or CRLF. Never show
 *            render() output to a person or count it without clean() first; the hook sees this form too.
 *            Every value also loses NUL and invalid UTF-8 and the braces `{` and `}`. A shop value gets
 *            MessageCleaner::cleanForStorage() (entities decoded, tags and hidden characters gone, escapes NOT
 *            decoded; 2026-10-21 00:17 +03, fix round 1 item 1, task 596), so an entity cannot turn into a
 *            backslash after the doubling.
 *            Hook (PRD 5.2): fn_set_hook('kwtsms_render_template_post', $rendered, $body, $vars), all three
 *            by reference; a handler may change $rendered. $body is the template as stored, $vars the values
 *            as passed in (raw).
 * DEPENDS:   Sms\MessageCleaner (cleanPlaceholderValue), CS-Cart fn_set_hook() (tests stub it).
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
     * Any "{...}" on one line. Wider than the placeholder names on purpose: "{Order_ID}", "{order id}" and
     * "{}" are mistakes too, and none of them may reach a customer (PRD 6.4: any "{...}" blocks the send).
     */
    private const TOKEN = '/\{[^{}\r\n]*\}/';

    /** What an empty value leaves in the text until the tidy pass has run. */
    private const MARK = "\x00";

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
        $work = str_replace(["\x01", "\x02"], '', $body);
        $work = (string) preg_replace_callback('/\{([^{}\r\n]*)\}/', static function (array $m) use ($known): string {
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
     * removed; customer-typed names cleaned; braces removed from every value (a value can never read as a
     * placeholder); trimmed; every backslash doubled.
     *
     * The doubling is for the ONE decode at send (ruling R18): the full clean() turns a pair of backslashes into
     * one and "backslash n" into a line break. Without it a name typed as backslash backslash n, or as the
     * entities of those, leaves cleanPlaceholderValue() as backslash n and becomes a line break (or a tab, or
     * CRLF) in the SMS. With it the sent text holds exactly the backslashes the value holds.
     * 2026-10-20 23:59 +03: NUL, invalid UTF-8 and backslashes, fix round 1 items 1 and 3, task 596.
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
        // Customer-typed names get the anti-smishing cleaner; every other value gets the body cleaning without the
        // escape decoding. Either way entities are decoded HERE, before the backslashes are doubled below: the send
        // decodes entities first, so "&#92;n" left in a value (a vendor names its own shipping methods) would
        // become a backslash after the doubling and then a line break.
        $text = in_array($name, self::FREE_TEXT, true)
            ? MessageCleaner::cleanPlaceholderValue($text)
            : MessageCleaner::cleanForStorage($text);
        $text = trim(strtr($text, ['{' => '', '}' => '']));

        return str_replace('\\', '\\\\', $text);
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
