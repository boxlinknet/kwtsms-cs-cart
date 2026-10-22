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
     * 2026-10-21 08:27 +03: $brand replaces "ABC Store" when it is not empty (the Templates page passes the store's
     * real brand, so the preview, the editor sample and the test SMS show the text the store would send), cleanup
     * item 3, task 596.
     *
     * @param string $lang        Language of the message (ar gets Arabic names and currency name)
     * @param string $status_name The order status name in that language (the page passes the row's status)
     * @param string $brand       The store's SMS brand name in that language ('' = the PRD sample brand)
     *
     * @return array<string,string>
     */
    public static function reference(string $lang, string $status_name = '', string $brand = ''): array
    {
        $ar = $lang === 'ar';
        $paid = $ar ? "\u{0645}\u{062F}\u{0641}\u{0648}\u{0639}" : 'Paid';
        $values = [
            // 2026-10-21 08:27 +03: the real brand when given, cleanup item 3, task 596.
            'store_name' => $brand !== '' ? $brand : 'ABC Store',
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
     *                                           2026-10-21 08:37 +03: the signature is wrapped to 120
     *                                           columns (phpcs), cleanup batch, task 596.
     *
     * @return array<string,string>
     */
    public static function worst(
        string $lang,
        array $status_names,
        array $currency_names,
        string $own_status_name = ''
    ): array {
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
     * 2026-10-22 18:00 +03: Task A5 (order.shipment_updated), task 596. The values of the shipment placeholders, kept
     * apart from
     * reference() and worst() (their keys are exactly Defaults::PLACEHOLDERS): the reference ones, or the worst case of
     * the brief (15-character carrier name, 22-character tracking number).
     *
     * @return array<string,string>
     */
    public static function shipment(bool $worst = false, string $lang = 'en'): array
    {
        if ($worst) {
            return [
                'tracking_number' => '1234567890123456789012',
                'carrier' => 'ABCDEFGHIJKLMNO',
                'tracking_url' => 'https://example.test/track/1234567890123456789012',
                // 2026-10-22 18:38 +03: Task A5 fix round 1, task 596.
                'shipment_info' => $lang === 'ar'
                    ? "\u{062A}\u{062A}\u{0628}\u{0639} 1234567890123456789012"
                    : 'Carrier: ABCDEFGHIJKLMNO. Tracking: 1234567890123456789012.',
            ];
        }

        return [
            'tracking_number' => 'TRK123456789',
            'carrier' => 'USPS',
            'tracking_url' => 'https://example.test/t/TRK123456789',
            // 2026-10-22 18:38 +03: Task A5 fix round 1, task 596.
            'shipment_info' => $lang === 'ar'
                ? "\u{062A}\u{062A}\u{0628}\u{0639} TRK123456789" : 'Carrier: USPS. Tracking: TRK123456789.',
        ];
    }

    /**
     * 2026-10-22 19:08 +03: Task A6 (registration SMS, recovery SMS), task 596. The values of the profile
     * placeholders, kept apart from
     * reference() and worst() like shipment(): the reference ones, or the worst case (6-digit user id, 15-character
     * phone, the 80-character reset URL of P2-8).
     *
     * @return array<string,string>
     */
    public static function profile(bool $worst = false): array
    {
        if ($worst) {
            return [
                'user_id' => '102345',
                'customer_phone' => '+9655012340299',
                'reset_url' => 'https://shop.example.com/index.php?dispatch=auth.recover_password&ekey=012345678',
                'valid_minutes' => '15', // 2026-10-22 20:28 +03: Task A6 fix round 1
            ];
        }

        return [
            'user_id' => '10234',
            'customer_phone' => '+965 5012 3402',
            'reset_url' => 'https://example.com/index.php?dispatch=auth.recover_password&ekey=0f3a9c',
            'valid_minutes' => '15',
        ];
    }

    /**
     * 2026-10-22 21:29 +03: Task A7 (low-stock SMS), task 596. The values of the low-stock placeholders: a reference
     * product, or the worst case (32-character product code, 30-character name, 5-digit amount, "+99").
     *
     * @return array<string,string>
     */
    public static function lowStock(bool $worst = false): array
    {
        if ($worst) {
            return [
                'product_name' => 'Wireless Noise Cancelling Headphones',
                'product_code' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345',
                'stock_amount' => '12345',
                'more_count' => '+99',
            ];
        }

        return [
            'product_name' => 'Ceramic Mug',
            'product_code' => 'MUG-0042',
            'stock_amount' => '3',
            'more_count' => '+2',
        ];
    }

    /**
     * 2026-10-23 00:14 +03: Task A9 (low-balance SMS), task 596. The values of the low-balance placeholders: a reference
     * balance, or the worst case (7-digit credits left and alert level).
     *
     * @return array<string,string>
     */
    public static function lowBalance(bool $worst = false): array
    {
        return $worst ? ['balance' => '9999999', 'threshold' => '9999999'] : ['balance' => '95', 'threshold' => '100'];
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
