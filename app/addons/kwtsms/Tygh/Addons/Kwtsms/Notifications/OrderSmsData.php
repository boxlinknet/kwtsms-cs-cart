<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Notifications/OrderSmsData.php
 * CREATED:   2026-10-21 01:54 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Turns an order (fn_get_order_info() row) into what an order SMS needs: the value of every PRD 6.4
 *            placeholder in one language, the customer phone, the country hint, the customer and admin languages.
 * WHY:       PRD 3, 6.3, 6.4 and the Task 1.9 carries. Built in the transport AFTER the switch check, from the order
 *            data the event already carries (core's OrderDataProvider is too heavy to build at checkout for a switch
 *            that is off). Rules:
 *              phone       order phone > b_phone > s_phone > profile phone (CS-Cart nulls are '')
 *              hint        s_country, else b_country (ISO2; SmsService turns it into the calling code, else the
 *                          default country)
 *              language    customer: the order's lang_code, else the storefront default language;
 *                          admin: admin_alert_lang, else the backend default language
 *              store_name  the SMS brand of the storefront (BrandNames: the marketplace in Multi-Vendor, never a
 *                          vendor); vendor_name the order's vendor in Multi-Vendor, '' otherwise
 *              order_ids   child ids, first three then +N ("101, 102, 103 +2"); a checkout SMS also puts the list
 *                          in {order_id}, so no text ever shows the parent id
 *              order_total in the order's display (secondary) currency and its decimals, ASCII digits, '.' and no
 *                          thousands separator (core mail common/price.tpl converts the same way)
 *              currency    the ISO code in English, kwtsms.currency.<ISO> in other languages when it exists
 *              order_status kwtsms.status.<CODE> when shipped (A B C D F I O P Y), else core's status name
 *              names       firstname / lastname, else the billing names; shipping names unique, joined by ", "
 *            Values are RAW; TemplateRenderer::render() cleans the customer-typed ones and SmsService cleans once.
 *            Core lookups are protected methods so a unit test can replace them (BrandNames pattern).
 * USAGE:     $data = new OrderSmsData();
 *            $vars = $data->placeholders($order_info, 'ar');                          // every placeholder
 *            $vars = $data->placeholders($parent_info, 'en', ['order_ids' => [101, 102, 103]]);  // a checkout
 *            OrderSmsData::customerPhone($order_info, $profile_phone); OrderSmsData::countryIso($order_info);
 *            $data->customerLang($order_info); $data->adminLang($config->adminAlertLang());
 * DEPENDS:   Templates\{BrandNames,Defaults}; CS-Cart __(), fn_get_simple_statuses(), fn_get_company_name(),
 *            fn_date_format(), Tygh\Registry, Tygh\Settings, fn_allowed_for() (in the protected lookups only).
 */

namespace Tygh\Addons\Kwtsms\Notifications;

use Tygh\Addons\Kwtsms\Templates\BrandNames;
use Tygh\Addons\Kwtsms\Templates\Defaults;
use Tygh\Registry;
use Tygh\Settings;

class OrderSmsData
{
    /** How many ids {order_ids} lists before "+N". */
    public const IDS_SHOWN = 3;

    /** Decimals beyond this are never shown. */
    private const MAX_DECIMALS = 4;

    /** @var array<string,array<string,string>> lang => code => core status name */
    private $status_names = [];

    /**
     * Every placeholder of PRD 6.4 for one order in one language.
     *
     * @param array<string,mixed> $order_info fn_get_order_info() row (a parent order for a checkout SMS)
     * @param array<string,mixed> $extra      order_ids (int[] child ids of a checkout), vendor_name, status (code)
     *
     * @return array<string,string> placeholder name (no braces) => raw value, in Defaults::PLACEHOLDERS order
     */
    public function placeholders(array $order_info, string $lang, array $extra = []): array
    {
        $status = strtoupper((string) ($extra['status'] ?? ($order_info['status'] ?? '')));
        $ids = isset($extra['order_ids']) && is_array($extra['order_ids']) ? self::orderIds($extra['order_ids']) : '';
        $currency = $this->currency($order_info);
        $coefficient = $currency['coefficient'] > 0 ? $currency['coefficient'] : 1.0;
        $total = ((float) ($order_info['total'] ?? 0)) / $coefficient;
        $order_id = (int) ($order_info['order_id'] ?? 0);
        $timestamp = (int) ($order_info['timestamp'] ?? 0);

        $vendor = '';
        if (array_key_exists('vendor_name', $extra)) {
            $vendor = (string) $extra['vendor_name'];
        } elseif ($this->isMultiVendor() && (int) ($order_info['company_id'] ?? 0) > 0) {
            $vendor = $this->companyName((int) $order_info['company_id']);
        }

        $values = [
            'store_name' => $this->brand((int) ($order_info['storefront_id'] ?? 0), $lang),
            'order_id' => $ids !== '' ? $ids : ($order_id > 0 ? (string) $order_id : ''),
            'order_ids' => $ids,
            'vendor_name' => $vendor,
            'order_total' => self::formatAmount($total, $currency['decimals']),
            'currency' => $this->currencyName($currency['code'], $lang),
            'order_status' => $status === '' ? '' : $this->statusName($status, $lang),
            'customer_first_name' => self::firstFilled($order_info, ['firstname', 'b_firstname', 's_firstname']),
            'customer_last_name' => self::firstFilled($order_info, ['lastname', 'b_lastname', 's_lastname']),
            'order_date' => $timestamp > 0 ? $this->formatDate($timestamp) : '',
            'payment_method' => isset($order_info['payment_method']['payment'])
                ? trim((string) $order_info['payment_method']['payment']) : '',
            'shipping_method' => self::shippingNames($order_info),
        ];

        $out = [];
        foreach (Defaults::PLACEHOLDERS as $name) {
            $out[$name] = $values[$name] ?? '';
        }

        return $out;
    }

    /**
     * The phone an order SMS goes to: order phone, billing phone, shipping phone, else the profile phone.
     *
     * @param array<string,mixed> $order_info
     */
    public static function customerPhone(array $order_info, string $profile_phone = ''): string
    {
        $phone = self::firstFilled($order_info, ['phone', 'b_phone', 's_phone']);

        return $phone !== '' ? $phone : trim($profile_phone);
    }

    /**
     * ISO2 country hint of an order: shipping country, else billing country ('' when neither).
     *
     * @param array<string,mixed> $order_info
     */
    public static function countryIso(array $order_info): string
    {
        return strtoupper(self::firstFilled($order_info, ['s_country', 'b_country']));
    }

    /**
     * Child ids for {order_ids}: positive ints, unique, ascending, the first three, then " +N" for the rest.
     *
     * @param array<int,mixed> $ids
     */
    public static function orderIds(array $ids): string
    {
        $clean = [];
        foreach ($ids as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $id = (int) $id;
                if ($id > 0) {
                    $clean[$id] = $id;
                }
            }
        }
        if ($clean === []) {
            return '';
        }
        sort($clean);
        $shown = array_slice($clean, 0, self::IDS_SHOWN);
        $rest = count($clean) - count($shown);

        return implode(', ', $shown) . ($rest > 0 ? ' +' . $rest : '');
    }

    /**
     * ASCII digits, '.' as decimal point, no thousands separator.
     *
     * @param float|int $amount
     */
    public static function formatAmount($amount, int $decimals): string
    {
        $decimals = max(0, min(self::MAX_DECIMALS, $decimals));

        return number_format(round((float) $amount, $decimals), $decimals, '.', '');
    }

    /**
     * The customer's language: the order's lang_code, else the storefront default language.
     *
     * @param array<string,mixed> $order_info
     */
    public function customerLang(array $order_info): string
    {
        $lang = isset($order_info['lang_code']) && is_string($order_info['lang_code']) ? $order_info['lang_code'] : '';
        if (preg_match('/^[a-z]{2}$/D', $lang) === 1) {
            return $lang;
        }

        return $this->storefrontLanguage((int) ($order_info['storefront_id'] ?? 0));
    }

    /**
     * The admin's language: admin_alert_lang, else the backend default language.
     */
    public function adminLang(string $configured): string
    {
        return preg_match('/^[a-z]{2}$/D', $configured) === 1 ? $configured : $this->backendLanguage();
    }

    // ------------------------------------------------------------------------------------------ lookups

    protected function brand(int $storefront_id, string $lang): string
    {
        return BrandNames::get($storefront_id, $lang);
    }

    /**
     * A language value, or null when the variable does not exist in that language.
     */
    protected function langvar(string $name, string $lang): ?string
    {
        $value = (string) __($name, [], $lang);

        return $value === '' || $value === '_' . strtolower($name) || $value === '_' . $name ? null : $value;
    }

    protected function coreStatusName(string $status, string $lang): string
    {
        if (!isset($this->status_names[$lang])) {
            $names = (array) fn_get_simple_statuses(STATUSES_ORDER, true, false, $lang);
            $this->status_names[$lang] = array_map('strval', $names);
        }

        return $this->status_names[$lang][$status] ?? $status;
    }

    /**
     * The order's display currency.
     *
     * @param array<string,mixed> $order_info
     *
     * @return array{code:string,decimals:int,coefficient:float}
     */
    protected function currency(array $order_info): array
    {
        $currencies = (array) Registry::get('currencies');
        if ($currencies === []) {
            $currencies = (array) db_get_hash_array('SELECT * FROM ?:currencies', 'currency_code');
        }
        $code = isset($order_info['secondary_currency']) ? (string) $order_info['secondary_currency'] : '';
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

        return [
            'code' => $code,
            'decimals' => isset($row['decimals']) ? (int) $row['decimals'] : 2,
            'coefficient' => (float) ($row['coefficient'] ?? 0) > 0 ? (float) $row['coefficient'] : 1.0,
        ];
    }

    protected function companyName(int $company_id): string
    {
        return trim((string) fn_get_company_name($company_id));
    }

    protected function formatDate(int $timestamp): string
    {
        $format = (string) Registry::get('settings.Appearance.date_format');

        return (string) fn_date_format($timestamp, $format !== '' ? $format : '%Y-%m-%d');
    }

    protected function storefrontLanguage(int $storefront_id): string
    {
        $lang = '';
        try {
            $lang = (string) Settings::instance(['storefront_id' => $storefront_id])
                ->getValue('frontend_default_language', 'Appearance');
        } catch (\Throwable $e) {
            $lang = '';
        }
        if ($lang === '') {
            $lang = (string) Registry::get('settings.Appearance.frontend_default_language');
        }

        return $lang !== '' ? $lang : 'en';
    }

    protected function backendLanguage(): string
    {
        $lang = (string) Registry::get('settings.Appearance.backend_default_language');

        return $lang !== '' ? $lang : 'en';
    }

    protected function isMultiVendor(): bool
    {
        return function_exists('fn_allowed_for') && fn_allowed_for('MULTIVENDOR');
    }

    // ------------------------------------------------------------------------------------------ helpers

    private function statusName(string $status, string $lang): string
    {
        $shipped = null;
        if (in_array($status, Defaults::STATUS_CODES, true)) {
            $shipped = $this->langvar(Defaults::statusLangvar($status), $lang);
        }

        return $shipped ?? $this->coreStatusName($status, $lang);
    }

    private function currencyName(string $code, string $lang): string
    {
        if ($code === '' || $lang === 'en') {
            return $code;
        }

        return $this->langvar(Defaults::currencyLangvar($code), $lang) ?? $code;
    }

    /**
     * @param array<string,mixed> $order_info
     * @param string[]            $fields
     */
    private static function firstFilled(array $order_info, array $fields): string
    {
        foreach ($fields as $field) {
            $value = isset($order_info[$field]) && is_scalar($order_info[$field]) ? (string) $order_info[$field] : '';
            if (trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $order_info
     */
    private static function shippingNames(array $order_info): string
    {
        $names = [];
        foreach ((array) ($order_info['shipping'] ?? []) as $shipping) {
            $name = is_array($shipping) && isset($shipping['shipping']) ? trim((string) $shipping['shipping']) : '';
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return implode(', ', $names);
    }
}
