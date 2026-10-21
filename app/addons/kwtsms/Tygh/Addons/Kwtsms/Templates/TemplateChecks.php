<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Templates/TemplateChecks.php
 * CREATED:   2026-10-21 03:08 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The advice the Templates page gives about a template body (stored form): {store_name} missing from a
 *            customer text, an HTML-like span left after cleaning, two placeholders touching, an ampersand, hash or
 *            semicolon fragment next to a placeholder, and promotional wording.
 * WHY:       PRD 6.4 and the carries of Tasks 1.3 and 1.8. The gateway rejects any "<...>" span (ERR027), a customer
 *            must see who the SMS is from, two placeholders that touch can join their values into an entity or a
 *            link, "&" / "#" / ";" next to a placeholder can build an entity, and offers and discount codes are
 *            promotional content. The page warns; only the missing {store_name} blocks a save (the page decides).
 *            The JavaScript counter (js/addons/kwtsms/counter.js) carries the same rules for the live warnings; a
 *            fixture of 30 bodies (tests/fixtures/templates-counter-parity.json) proves both give the same codes.
 * USAGE:     All methods are static and pure.
 *              TemplateChecks::analyze($stored_body, 'C')
 *                  -> code => list of what was found, only for the warnings that apply, in this order:
 *                     store_name_missing [], html_span ['< b >'], adjacent ['{a}{b}'], entity_fragment ['&{a}'],
 *                     promotional ['%', 'sale']
 *              TemplateChecks::PROMO_WORDS_EN / PROMO_WORDS_AR   the wording list (also handed to the script)
 * DEPENDS:   Sms\MessageCleaner (clean, hasHtmlLikeSpan, URL_TLDS), Templates\Defaults (placeholder names).
 */

namespace Tygh\Addons\Kwtsms\Templates;

use Tygh\Addons\Kwtsms\Sms\MessageCleaner;

final class TemplateChecks
{
    public const STORE_NAME_MISSING = 'store_name_missing';
    public const HTML_SPAN = 'html_span';
    public const ADJACENT = 'adjacent';
    public const ENTITY_FRAGMENT = 'entity_fragment';
    public const PROMOTIONAL = 'promotional';

    /** English promotional words, matched as whole words, any case. */
    public const PROMO_WORDS_EN = [
        'code', 'coupon', 'voucher', 'promo', 'promotion', 'offer', 'sale', 'discount', 'deal', 'free', 'bonus',
    ];

    /**
     * Arabic promotional words (discount, offer, offers, code, coupon, voucher, reduction, reductions, markdowns,
     * free in three forms, gift, bonus, promotional), matched anywhere in the text. Written as \u escapes.
     */
    public const PROMO_WORDS_AR = [
        "\u{062E}\u{0635}\u{0645}",
        "\u{0639}\u{0631}\u{0636}",
        "\u{0639}\u{0631}\u{0648}\u{0636}",
        "\u{0643}\u{0648}\u{062F}",
        "\u{0643}\u{0648}\u{0628}\u{0648}\u{0646}",
        "\u{0642}\u{0633}\u{064A}\u{0645}\u{0629}",
        "\u{062A}\u{062E}\u{0641}\u{064A}\u{0636}",
        "\u{062A}\u{0646}\u{0632}\u{064A}\u{0644}\u{0627}\u{062A}",
        "\u{0645}\u{062C}\u{0627}\u{0646}\u{064A}",
        "\u{0645}\u{062C}\u{0627}\u{0646}\u{0627}",
        "\u{0645}\u{062C}\u{0627}\u{0646}\u{064A}\u{0629}",
        "\u{0647}\u{062F}\u{064A}\u{0629}",
        "\u{0628}\u{0648}\u{0646}\u{0635}",
        "\u{062A}\u{0631}\u{0648}\u{064A}\u{062C}\u{064A}",
    ];

    /** A placeholder written with a plain name; the shape the page knows how to name. */
    private const PLACEHOLDER = '\{[a-z_]+\}';

    /**
     * @param string $body     The stored form of the body (what the repository keeps)
     * @param string $receiver 'C' (customer) or 'A' (administrator)
     *
     * @return array<string,array<int,string>> Warning code => what was found; only the warnings that apply
     */
    public static function analyze(string $body, string $receiver): array
    {
        $found = [];
        if (trim($body) === '') {
            return $found;
        }

        if ($receiver === Defaults::RECEIVER_CUSTOMER && strpos($body, '{store_name}') === false) {
            $found[self::STORE_NAME_MISSING] = [];
        }

        $span = self::htmlSpans($body);
        if ($span !== []) {
            $found[self::HTML_SPAN] = $span;
        }

        $adjacent = self::adjacentPlaceholders($body);
        if ($adjacent !== []) {
            $found[self::ADJACENT] = $adjacent;
        }

        $fragments = self::entityFragments($body);
        if ($fragments !== []) {
            $found[self::ENTITY_FRAGMENT] = $fragments;
        }

        $promo = self::promotionalWording($body);
        if ($promo !== []) {
            $found[self::PROMOTIONAL] = $promo;
        }

        return $found;
    }

    /**
     * The "<...>" spans the gateway would reject, in the body as it is after cleaning.
     *
     * @return array<int,string>
     */
    private static function htmlSpans(string $body): array
    {
        $cleaned = MessageCleaner::clean($body);
        if (!MessageCleaner::hasHtmlLikeSpan($cleaned)) {
            return [];
        }
        preg_match_all('/<[^>]*>/', $cleaned, $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * Two known placeholders written with nothing between them: {order_id}{currency}.
     *
     * @return array<int,string>
     */
    private static function adjacentPlaceholders(string $body): array
    {
        $known = array_flip(Defaults::PLACEHOLDERS);
        $out = [];
        // Lookahead on the second placeholder, so {a}{b}{c} reports both pairs.
        preg_match_all('/(' . self::PLACEHOLDER . ')(?=(' . self::PLACEHOLDER . '))/', $body, $m, PREG_SET_ORDER);
        foreach ($m as $pair) {
            if (isset($known[trim($pair[1], '{}')], $known[trim($pair[2], '{}')])) {
                $text = $pair[1] . $pair[2];
                if (!in_array($text, $out, true)) {
                    $out[] = $text;
                }
            }
        }

        return $out;
    }

    /**
     * "&" or "#" right before a placeholder, ";" "&" or "#" right after it: the pair can read as an entity once
     * the value is in (&#{order_id}; is a character reference).
     *
     * @return array<int,string>
     */
    private static function entityFragments(string $body): array
    {
        $out = [];
        preg_match_all('/([&#]+' . self::PLACEHOLDER . '|' . self::PLACEHOLDER . '[;&#]+)/', $body, $m);
        foreach ($m[1] as $text) {
            if (!in_array($text, $out, true)) {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * Promotional wording: a percent sign, a link, a bare domain, a word of PROMO_WORDS_EN, a word of
     * PROMO_WORDS_AR. Placeholders are taken out first so a placeholder name is never read as wording.
     *
     * @return array<int,string> Each finding once, in order of appearance by kind
     */
    private static function promotionalWording(string $body): array
    {
        $text = (string) preg_replace('/\{[^{}\r\n]*\}/u', ' ', $body);
        $out = [];
        if (strpos($text, '%') !== false) {
            $out[] = '%';
        }
        $domain = '~(?<![\p{L}\p{N}-])[\p{L}\p{N}][\p{L}\p{N}-]*(?:\.[\p{L}\p{N}][\p{L}\p{N}-]*)*\.(?:'
            . implode('|', MessageCleaner::URL_TLDS) . ')(?![\p{L}\p{N}-])~iu';
        if (preg_match('~[A-Za-z][A-Za-z0-9+.-]*://\S+|\bwww\.\S+~i', $text, $m) === 1) {
            $out[] = rtrim($m[0], '.,;:)');
        } elseif (preg_match($domain, $text, $m) === 1) {
            $out[] = $m[0];
        }
        preg_match_all('/\b(' . implode('|', self::PROMO_WORDS_EN) . ')\b/i', $text, $m);
        foreach ($m[1] as $word) {
            $word = strtolower($word);
            if (!in_array($word, $out, true)) {
                $out[] = $word;
            }
        }
        foreach (self::PROMO_WORDS_AR as $word) {
            if (strpos($text, $word) !== false && !in_array($word, $out, true)) {
                $out[] = $word;
            }
        }

        return $out;
    }
}
