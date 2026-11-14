<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Events/CallRequestSmsData.php
 * CREATED:   2026-11-14 05:30 +03
 * TASK:      596 M3.2
 * AUTHOR:    kwtSMS
 * WHAT:      The data_class of the call_requests.request_created and call_requests.request_about_product_created
 *            declarations (schemas/kwtsms/events.php). enrich() adds kwtsms_ctx.company_id (the int company of the
 *            request, 0 when empty) and fails closed without a request id; values() computes {customer_phone} (the
 *            digits of the normalized requester number, '' when it is not a valid number) and {product_name} (the
 *            catalog name in the language of the SMS, cleaned and cut). Nothing else is returned.
 * WHY:       Task M3.2 (B2, P3-63, P3-64). The core payload has the company but not under a path the dedupe key may
 *            rely on as an int, and the product name needs the catalog. The requester name and notes are typed by a
 *            visitor of a public form and never become text, a key or a placeholder; the phone is text only (the staff
 *            member needs it to call back), never part of a dispatch key.
 * USAGE:     'data_class' => \Tygh\Addons\Kwtsms\Events\CallRequestSmsData::class; OrderNotifier builds it with no
 *            arguments, calls enrich() before the declaration is read, then values($data, $receiver). A unit test
 *            replaces the catalog read with useProductLookup().
 * DEPENDS:   Notifications\{DeclaredSmsData,PayloadEnricher}, Sms\PhoneNormalizer. Core: fn_get_product_name() (read
 *            only when a product id is present), Registry for the default country code (guarded).
 */

namespace Tygh\Addons\Kwtsms\Events;

use Tygh\Addons\Kwtsms\Notifications\DeclaredSmsData;
use Tygh\Addons\Kwtsms\Notifications\PayloadEnricher;
use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;

final class CallRequestSmsData implements DeclaredSmsData, PayloadEnricher
{
    /** Longest product name put into an SMS, in characters. */
    public const MAX_PRODUCT = 40;

    /** @var (callable(int,string): string)|null replaces the catalog read (tests) */
    private static $lookup = null;

    /**
     * @param (callable(int,string): string)|null $lookup (product id, lang) => name; null = core again
     */
    public static function useProductLookup(?callable $lookup): void
    {
        self::$lookup = $lookup;
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    private static function request(array $payload): array
    {
        return isset($payload['call_request_data']) && is_array($payload['call_request_data'])
            ? $payload['call_request_data'] : [];
    }

    /**
     * Fails closed for EVERY receiver: a payload without a request id (a shape 4.20.1 may have) throws, so
     * OrderNotifier writes attempt invalid / platform_missing and sends nothing. kwtsms_ctx.company_id is the int
     * company of the request, 0 for an empty or non-numeric one (Ultimate without a company). Reads with isset and
     * is_scalar only (no PHP warning).
     *
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    public function enrich(array $payload, string $receiver): array
    {
        $request = self::request($payload);
        $id = $request['request_id'] ?? null;
        if (!is_scalar($id) || is_bool($id) || (int) $id <= 0) {
            throw new \RuntimeException('call request payload without a request id');
        }
        $company = $request['company_id'] ?? 0;
        $payload['kwtsms_ctx'] = [
            'company_id' => is_scalar($company) && !is_bool($company) && ctype_digit((string) $company)
                ? (int) $company : 0,
        ];

        return $payload;
    }

    /**
     * @param array<string,mixed> $data     The event data: call_request_data.phone and .product_id, kwtsms_lang
     * @param string              $receiver A or V
     *
     * @return array{placeholders:array<string,string>}
     */
    public function values(array $data, string $receiver): array
    {
        $request = self::request($data);
        $lang = strtolower(trim(is_scalar($data['kwtsms_lang'] ?? null) ? (string) $data['kwtsms_lang'] : ''));
        if (preg_match('/^[a-z]{2}$/D', $lang) !== 1) {
            $lang = 'en';
        }

        return ['placeholders' => [
            'customer_phone' => self::phone($request['phone'] ?? null),
            'product_name' => self::product($request['product_id'] ?? null, $lang),
        ]];
    }

    /**
     * The digits of the normalized number; '' when the input is not a string or does not verify.
     *
     * @param mixed $raw
     */
    private static function phone($raw): string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return '';
        }
        $hint = '';
        if (class_exists('\\Tygh\\Registry')) {
            $hint = (string) \Tygh\Registry::get('addons.kwtsms.default_country_code');
        }
        $digits = PhoneNormalizer::normalize($raw, $hint !== '' ? $hint : '965');

        return $digits !== '' && PhoneNormalizer::verify($digits)[0] ? $digits : '';
    }

    /**
     * @param mixed $product_id
     */
    private static function product($product_id, string $lang): string
    {
        if (!is_scalar($product_id) || is_bool($product_id) || (int) $product_id <= 0) {
            return '';
        }
        try {
            $name = self::$lookup !== null
                ? (self::$lookup)((int) $product_id, $lang)
                : (function_exists('fn_get_product_name') ? fn_get_product_name((int) $product_id, $lang) : '');
        } catch (\Throwable $e) {
            return '';
        }
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', is_string($name) ? $name : ''));

        return mb_strlen($name, 'UTF-8') > self::MAX_PRODUCT
            ? rtrim(mb_substr($name, 0, self::MAX_PRODUCT, 'UTF-8'))
            : $name;
    }
}
