<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Events/GiftCertificateSmsData.php
 * CREATED:   2026-11-14 13:26 +03
 * TASK:      596 M3.4
 * AUTHOR:    kwtSMS
 * WHAT:      The data_class of the gift_certificates.gift_certificate.status_changed.<status> and
 *            gift_certificates.gift_certificate.updated declarations (schemas/kwtsms/events.php), receiver C only.
 *            enrich() reads the FIRST order id of certificate_data.order_ids (the purchaser's order) and adds
 *            kwtsms_ctx.{phone, lang, user_id, storefront_id, company_id, currency} from that order; a certificate
 *            without an order gets only company_id 0 (the SMS then ends as no_phone) and a payload without a
 *            certificate id fails closed. values() computes {amount} (in the currency of the purchaser's order),
 *            {currency} and {gc_status} (the name of the certificate status in the language of the SMS), and returns
 *            secret_values = [the generated code], so the code is masked in every stored copy even when a merchant
 *            types it into a text by hand.
 * WHY:       Task M3.4 (B4, P3-63). The core payload has the certificate row only; the purchaser is the customer of
 *            the order that bought it, not the e-mail recipient. The certificate code is a bearer secret: it is never a
 *            placeholder, a dedupe key or a path (EventDeclaration refuses gift_cert_code), and the sender, recipient
 *            and message of the certificate (typed by a customer) never become text.
 * USAGE:     'data_class' => \Tygh\Addons\Kwtsms\Events\GiftCertificateSmsData::class; OrderNotifier builds it with no
 *            arguments, calls enrich() before the declaration is read, then values($data, 'C'). A unit test replaces
 *            the three core reads with useOrderLookup(), useStatusLookup() and useCurrencyLookup().
 * DEPENDS:   Notifications\{DeclaredSmsData,PayloadEnricher,OrderSmsData}. Core (read only, guarded):
 *            fn_get_order_info(), STATUSES_GIFT_CERTIFICATE and fn_get_simple_statuses(), the currencies registry and
 *            the add-on's own currency language variables.
 */

namespace Tygh\Addons\Kwtsms\Events;

use Tygh\Addons\Kwtsms\Notifications\DeclaredSmsData;
use Tygh\Addons\Kwtsms\Notifications\OrderSmsData;
use Tygh\Addons\Kwtsms\Notifications\PayloadEnricher;
use Tygh\Addons\Kwtsms\Templates\Defaults;

final class GiftCertificateSmsData implements DeclaredSmsData, PayloadEnricher
{
    /** Longest status name put into an SMS, in characters. */
    public const MAX_STATUS = 40;

    /** @var (callable(int): array<string,mixed>)|null replaces fn_get_order_info() (tests) */
    private static $order_lookup = null;

    /** @var (callable(string): array<string,string>)|null replaces the status read (tests) */
    private static $status_lookup = null;

    /** @var (callable(string,string): array{name:string,decimals:int,coefficient:float})|null replaces the currency read */
    private static $currency_lookup = null;

    /** @var array<string,array<string,string>> lang => status code => name, read once per request and language */
    private static $names = [];

    /**
     * @param (callable(int): array<string,mixed>)|null $lookup order id => order array; null = core again
     */
    public static function useOrderLookup(?callable $lookup): void
    {
        self::$order_lookup = $lookup;
    }

    /**
     * @param (callable(string): array<string,string>)|null $lookup lang => [status code => name]; null = core again
     */
    public static function useStatusLookup(?callable $lookup): void
    {
        self::$status_lookup = $lookup;
        self::$names = [];
    }

    /**
     * @param (callable(string,string): array{name:string,decimals:int,coefficient:float})|null $lookup
     *        (currency code of the order or '', lang) => name in that language, decimals, coefficient; null = core
     */
    public static function useCurrencyLookup(?callable $lookup): void
    {
        self::$currency_lookup = $lookup;
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    private static function certificate(array $payload): array
    {
        return isset($payload['certificate_data']) && is_array($payload['certificate_data'])
            ? $payload['certificate_data'] : [];
    }

    /**
     * Fails closed for EVERY receiver: a payload without a certificate id (a shape 4.20.1 may have) throws, so
     * OrderNotifier writes attempt invalid / platform_missing and sends nothing, with no PHP warning. The purchaser is
     * read from the first id of order_ids; a certificate without a readable order gets kwtsms_ctx.company_id 0 only.
     *
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    public function enrich(array $payload, string $receiver): array
    {
        $cert = self::certificate($payload);
        $id = $cert['gift_cert_id'] ?? null;
        if (!is_scalar($id) || is_bool($id) || (int) $id <= 0) {
            throw new \RuntimeException('gift certificate payload without a certificate id');
        }
        // 2026-11-14 15:41 +03: ruling B, a payload without the certificate code cannot be masked, so no SMS goes
        // (the code is a secret and a merchant may have typed it into the text), task 596 M3.4 review.
        $code = $cert['gift_cert_code'] ?? null;
        if (!is_scalar($code) || is_bool($code) || trim((string) $code) === '') {
            throw new \RuntimeException('gift certificate payload without a certificate code');
        }
        $payload['kwtsms_ctx'] = ['company_id' => 0];
        $raw = $cert['order_ids'] ?? '';
        $first = is_scalar($raw) && !is_bool($raw) ? (int) trim(explode(',', (string) $raw)[0]) : 0;
        if ($first <= 0) {
            return $payload;
        }
        try {
            $order = self::$order_lookup !== null
                ? (self::$order_lookup)($first)
                : (function_exists('fn_get_order_info') ? fn_get_order_info($first) : []);
        } catch (\Throwable $e) {
            return $payload;
        }
        if (!is_array($order) || $order === []) {
            return $payload;
        }
        $ctx = ['company_id' => 0];
        $phone = OrderSmsData::customerPhone($order);
        if ($phone !== '') {
            $ctx['phone'] = $phone;
        }
        $lang = isset($order['lang_code']) && is_string($order['lang_code']) ? strtolower($order['lang_code']) : '';
        if (preg_match('/^[a-z]{2}$/D', $lang) === 1) {
            $ctx['lang'] = $lang;
        }
        foreach (['user_id', 'storefront_id', 'company_id'] as $key) {
            $value = $order[$key] ?? 0;
            if (is_scalar($value) && !is_bool($value) && ctype_digit((string) $value) && (int) $value > 0) {
                $ctx[$key] = (int) $value;
            }
        }
        $currency = $order['secondary_currency'] ?? '';
        $ctx['currency'] = is_string($currency) && preg_match('/^[A-Za-z]{3}$/D', $currency) === 1
            ? strtoupper($currency) : '';
        $payload['kwtsms_ctx'] = $ctx;

        return $payload;
    }

    /**
     * @param array<string,mixed> $data     The event data: certificate_data.{amount,status,gift_cert_code}, kwtsms_ctx,
     *                                      kwtsms_lang
     * @param string              $receiver C
     *
     * @return array{placeholders:array<string,string>,secret_values:array<int,string>}
     */
    public function values(array $data, string $receiver): array
    {
        $cert = self::certificate($data);
        $lang = strtolower(trim(is_scalar($data['kwtsms_lang'] ?? null) ? (string) $data['kwtsms_lang'] : ''));
        if (preg_match('/^[a-z]{2}$/D', $lang) !== 1) {
            $lang = 'en';
        }
        $ctx = isset($data['kwtsms_ctx']) && is_array($data['kwtsms_ctx']) ? $data['kwtsms_ctx'] : [];
        $code = isset($ctx['currency']) && is_string($ctx['currency']) ? $ctx['currency'] : '';
        $currency = self::currency($code, $lang);

        $amount = $cert['amount'] ?? 0;
        $amount = is_scalar($amount) && !is_bool($amount) && is_numeric((string) $amount) ? (float) $amount : 0.0;
        $status = $cert['status'] ?? '';
        $status = is_scalar($status) && !is_bool($status) ? strtoupper(trim((string) $status)) : '';
        $name = '';
        if ($status !== '') {
            $names = self::namesIn($lang);
            $name = isset($names[$status]) ? self::clean($names[$status]) : '';
        }

        $secrets = [];
        $secret = $cert['gift_cert_code'] ?? '';
        if (is_scalar($secret) && !is_bool($secret) && (string) $secret !== '') {
            $secrets[] = (string) $secret;
        }

        return [
            'placeholders' => [
                'amount' => OrderSmsData::formatAmount($amount / $currency['coefficient'], $currency['decimals']),
                'currency' => $currency['name'],
                'gc_status' => $name !== '' ? $name : $status,
            ],
            'secret_values' => $secrets,
        ];
    }

    /**
     * @return array{name:string,decimals:int,coefficient:float}
     */
    private static function currency(string $code, string $lang): array
    {
        $none = ['name' => '', 'decimals' => 2, 'coefficient' => 1.0];
        try {
            if (self::$currency_lookup !== null) {
                $found = (self::$currency_lookup)($code, $lang);
            } else {
                $found = self::coreCurrency($code, $lang);
            }
        } catch (\Throwable $e) {
            return $none;
        }
        $coefficient = (float) ($found['coefficient'] ?? 0);

        return [
            'name' => isset($found['name']) && is_scalar($found['name']) ? trim((string) $found['name']) : '',
            'decimals' => max(0, min(4, (int) ($found['decimals'] ?? 2))),
            'coefficient' => $coefficient > 0 ? $coefficient : 1.0,
        ];
    }

    /**
     * The currency of the purchaser's order (the primary one when the order has none): its row of the currencies
     * registry and its name in the language of the SMS (the add-on's own variable, else the ISO code).
     *
     * @return array{name:string,decimals:int,coefficient:float}
     */
    private static function coreCurrency(string $code, string $lang): array
    {
        $currencies = class_exists('\\Tygh\\Registry') ? (array) \Tygh\Registry::get('currencies') : [];
        if (!isset($currencies[$code])) {
            $code = '';
            foreach ($currencies as $candidate => $row) {
                if (isset($row['is_primary']) && $row['is_primary'] === 'Y') {
                    $code = (string) $candidate;
                    break;
                }
            }
        }
        $row = $currencies[$code] ?? [];
        $name = $code;
        if ($code !== '' && $lang !== 'en' && function_exists('__')) {
            $var = Defaults::currencyLangvar($code);
            $text = (string) __($var, [], $lang);
            if ($text !== '' && $text !== '_' . $var && $text !== $var) {
                $name = $text;
            }
        }

        return [
            'name' => $name,
            'decimals' => isset($row['decimals']) ? (int) $row['decimals'] : 2,
            'coefficient' => (float) ($row['coefficient'] ?? 1),
        ];
    }

    /**
     * Status code (upper case) => name in $lang; [] when the read fails (the caller then falls back to the code).
     *
     * @return array<string,string>
     */
    private static function namesIn(string $lang): array
    {
        if (isset(self::$names[$lang])) {
            return self::$names[$lang];
        }
        $names = [];
        try {
            $raw = self::$status_lookup !== null
                ? (self::$status_lookup)($lang)
                : (defined('STATUSES_GIFT_CERTIFICATE') && function_exists('fn_get_simple_statuses')
                    ? fn_get_simple_statuses(STATUSES_GIFT_CERTIFICATE, false, false, $lang) : []);
            foreach ((array) $raw as $code => $name) {
                if (is_scalar($name)) {
                    $names[strtoupper((string) $code)] = (string) $name;
                }
            }
        } catch (\Throwable $e) {
            $names = [];
        }
        self::$names[$lang] = $names;

        return $names;
    }

    private static function clean(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $name));

        return mb_strlen($name, 'UTF-8') > self::MAX_STATUS
            ? rtrim(mb_substr($name, 0, self::MAX_STATUS, 'UTF-8'))
            : $name;
    }
}
