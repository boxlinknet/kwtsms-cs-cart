<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Templates/SampleData.php
 * CREATED:   2026-10-21 03:09 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The placeholder values the Templates page renders with when no real order is asked for: the reference
 *            values of the PRD 6.4 table (what the length column and the preview show) and the worst-case values (what
 *            the worst-case estimate counts).
 * WHY:       PRD 6.4: "live counter; worst-case estimate (Arabic 10-character name, 20-character brand, 8-digit
 *            totals)". One place holds the numbers, so the page, the script and the tests agree. The worst case always
 *            uses an Arabic first name: customers of a Kuwaiti store write their names in Arabic, and one Arabic letter
 *            turns an English SMS into Unicode (70 / 67 per page instead of 160 / 153).
 * USAGE:     SampleData::reference('ar', $status_name)
 *                -> placeholder => value, every Defaults::PLACEHOLDERS key
 *            SampleData::worst('ar', $status_names, $currency_names)
 *                -> same keys; the longest status and currency name given
 *            Values are raw, as OrderSmsData::placeholders() returns them; TemplateRenderer::render() cleans them.
 *            Languages other than ar use the English values (an unknown language gets the English wording).
 * DEPENDS:   Templates\Defaults (placeholder names). Pure PHP 7.4, ASCII only (Arabic as \u escapes).
 */

namespace Tygh\Addons\Kwtsms\Templates;

final class SampleData
{
    /** Longest brand, first name and last name of the worst case, in characters. */
    private const WORST_BRAND = 'ABCDEFGHIJKLMNOPQRST';

    /**
     * The values of the PRD reference order (brand "ABC Store", order 10234, total 12.500 in KWD, first name Yousef).
     *
     * @param string $lang        Language of the message (ar gets Arabic names and currency name)
     * @param string $status_name The order status name in that language (the page passes the row's status)
     *
     * @return array<string,string>
     */
    public static function reference(string $lang, string $status_name = ''): array
    {
        $ar = $lang === 'ar';
        $paid = $ar ? "\u{0645}\u{062F}\u{0641}\u{0648}\u{0639}" : 'Paid';
        $values = [
            'store_name' => 'ABC Store',
            'order_id' => '10234',
            'order_ids' => '10234, 10235',
            'vendor_name' => 'Gulf Gadgets',
            'order_total' => '12.500',
            'currency' => $ar ? "\u{062F}.\u{0643}" : 'KWD',
            'order_status' => $status_name !== '' ? $status_name : $paid,
            'customer_first_name' => $ar ? "\u{0627}\u{0644}\u{0647}\u{0646}\u{0648}\u{0641}" : 'Yousef',
            'customer_last_name' => $ar ? "\u{0627}\u{0644}\u{0639}\u{0646}\u{0632}\u{064A}" : 'Alenezi',
            'order_date' => '2026-10-20',
            'payment_method' => 'Cash on delivery',
            'shipping_method' => 'Courier',
        ];

        return self::inPlaceholderOrder($values);
    }

    /**
     * The worst case: 20-character brand, 10-character Arabic first name, 7-character Arabic last name, 6-digit order
     * ids (the list of a checkout shows three ids and "+12"), 8-digit total, the longest status and currency name.
     *
     * @param string            $lang            Language of the message (only used to pick fallback wording)
     * @param array<int,string> $status_names    Every order status name of that language
     * @param array<int,string> $currency_names  Every currency name of that language
     * @param string            $own_status_name The status name of the row being edited ('' for a row with no status).
     *                                           A custom status can have a longer name than any shipped one, and the
     *                                           estimate must count it.
     *                                           2026-10-21 05:03 +03: added, Task 1.12 fix round 1 item 4, task 596.
     *
     * @return array<string,string>
     */
    public static function worst(string $lang, array $status_names, array $currency_names, string $own_status_name = ''): array
    {
        if ($own_status_name !== '') {
            $status_names[] = $own_status_name;
        }
        $ar = $lang === 'ar';
        $dollar = $ar ? "\u{062F}\u{0648}\u{0644}\u{0627}\u{0631}" : 'KWD';
        $awaiting = $ar
            ? "\u{0628}\u{0627}\u{0646}\u{062A}\u{0638}\u{0627}\u{0631} \u{0627}\u{062A}\u{0635}\u{0627}\u{0644}"
            : 'Awaiting call';
        $values = [
            'store_name' => self::WORST_BRAND,
            'order_id' => '102345',
            'order_ids' => '102345, 102346, 102347 +12',
            'vendor_name' => 'Gulf Gadgets Trading',
            'order_total' => '12345.678',
            'currency' => self::longest($currency_names, $dollar),
            'order_status' => self::longest($status_names, $awaiting),
            'customer_first_name' => str_repeat("\u{0639}", 10),
            'customer_last_name' => str_repeat("\u{0639}", 7),
            'order_date' => '2026-10-20',
            'payment_method' => 'Cash on delivery',
            'shipping_method' => 'Courier',
        ];

        return self::inPlaceholderOrder($values);
    }

    /**
     * @param array<int,string> $names
     */
    private static function longest(array $names, string $fallback): string
    {
        $best = '';
        foreach ($names as $name) {
            $name = (string) $name;
            if (mb_strlen($name, 'UTF-8') > mb_strlen($best, 'UTF-8')) {
                $best = $name;
            }
        }

        return $best !== '' ? $best : $fallback;
    }

    /**
     * @param array<string,string> $values
     *
     * @return array<string,string>
     */
    private static function inPlaceholderOrder(array $values): array
    {
        $out = [];
        foreach (Defaults::PLACEHOLDERS as $name) {
            $out[$name] = $values[$name] ?? '';
        }

        return $out;
    }
}
