<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Orders/LowStockSmsData.php
 * CREATED:   2026-10-22 21:27 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The data_class of kwtsms.low_stock: turns the collector's data (products, company_id, more_count) into
 *            the placeholders product_name, product_code, stock_amount (all of the FIRST product) and more_count
 *            ("+N" when other products crossed in the same request, else an empty string).
 * WHY:       Phase 2 Task A7 (SafatMall F5 part, rulings R4, P2-10): one SMS names the first product and counts the
 *            rest, so a 40-product order is one text. The name and code are read when the SMS is built (the
 *            collector only keeps ids and amounts, the checkout path stays cheap).
 * USAGE:     'data_class' => LowStockSmsData::class in schemas/kwtsms/events.php; OrderNotifier builds it with no
 *            arguments and calls values($data, $receiver). describe() is the protected seam of the unit test.
 * DEPENDS:   Notifications\DeclaredSmsData; fn_get_product_name(), db_get_field() and fn_get_company_language() of
 *            CS-Cart when they exist.
 */

namespace Tygh\Addons\Kwtsms\Orders;

use Tygh\Addons\Kwtsms\Notifications\DeclaredSmsData;

class LowStockSmsData implements DeclaredSmsData
{
    /**
     * @param array<string,mixed> $data     products, company_id, more_count (LowStockCollector::flush)
     * @param string              $receiver A or V
     *
     * @return array{placeholders:array<string,string>}
     */
    public function values(array $data, string $receiver): array
    {
        $products = isset($data['products']) && is_array($data['products']) ? array_values($data['products']) : [];
        if ($products === [] || !is_array($products[0])) {
            return ['placeholders' => []];
        }
        $first = $products[0];
        $info = $this->describe((int) ($first['product_id'] ?? 0), (int) ($data['company_id'] ?? 0));
        $more = max(0, (int) ($data['more_count'] ?? 0));

        return ['placeholders' => [
            'product_name' => (string) $info['name'],
            'product_code' => (string) $info['code'],
            'stock_amount' => (string) (int) ($first['new_amount'] ?? 0),
            'more_count' => $more > 0 ? '+' . $more : '',
        ]];
    }

    /**
     * The name (in the vendor's language, else the backend default) and the code of a product.
     *
     * @return array{name:string,code:string}
     */
    protected function describe(int $product_id, int $company_id): array
    {
        $lang = '';
        if ($company_id > 0 && function_exists('fn_get_company_language')) {
            $lang = (string) fn_get_company_language($company_id);
        }
        if ($lang === '' && class_exists('\Tygh\Registry')) {
            $lang = (string) \Tygh\Registry::get('settings.Appearance.backend_default_language');
        }
        $lang = $lang !== '' ? $lang : 'en';
        $name = function_exists('fn_get_product_name') ? fn_get_product_name($product_id, $lang) : '';
        $code = function_exists('db_get_field')
            ? db_get_field('SELECT product_code FROM ?:products WHERE product_id = ?i', $product_id) : '';

        return [
            'name' => is_string($name) ? $name : '',
            'code' => is_scalar($code) ? (string) $code : '',
        ];
    }
}
