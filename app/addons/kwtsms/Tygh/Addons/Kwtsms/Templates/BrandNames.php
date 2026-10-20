<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Templates/BrandNames.php
 * CREATED:   2026-10-20 23:10 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The "SMS brand name" a message shows as {store_name}: the brand the admin set for the storefront in
 *            the message language, else the storefront name, else the company name; at most 20 characters, on
 *            one plain line.
 * WHY:       PRD 6.4: customers must recognise the sender in the first words of the SMS, and a long shop name
 *            would eat the page budget (the defaults are sized for a 20-character brand). In Multi-Vendor the
 *            brand is the marketplace's, never a vendor's: the company name used is the marketplace setting, and
 *            the vendor company records are not read at all.
 * USAGE:     BrandNames::get($storefront_id, 'ar')   -> 'ABC Store' ('' only when nothing is named anywhere)
 *            Sources, in order: kwtsms_brand_names (enabled = Y) with kwtsms_brand_name_descriptions for
 *            (storefront, language); Tygh storefront name; company name (Ultimate: the storefront's company;
 *            Multi-Vendor and last resort: the store's Company settings name = the marketplace).
 *            A source that is empty after cleaning is skipped. Cleaning is MessageCleaner::cleanForStorage()
 *            (no URL rule: the brand is the shop's own text, not a customer value), then one space for any
 *            run of whitespace, then the 20-character cut. The three sources are protected static methods so a
 *            test can replace them.
 *            2026-10-20 23:59 +03, fix round 1 item 1, task 596: get() returns the brand as typed, typed
 *            backslashes included (no decoding, no doubling): it is also shown in the admin. The renderer doubles
 *            the backslashes of every value it inserts ({store_name} too), so the one decode at send gives the
 *            brand back and it can never become a line break or tab. Do not double them here as well.
 * DEPENDS:   CS-Cart db_get_field(), Tygh::$app['storefront.repository'], fn_allowed_for(), fn_get_company_name(),
 *            Tygh\Registry (settings.Company.company_name); Sms\MessageCleaner; tables kwtsms_brand_names and
 *            kwtsms_brand_name_descriptions.
 */

namespace Tygh\Addons\Kwtsms\Templates;

use Tygh\Addons\Kwtsms\Sms\MessageCleaner;
use Tygh\Registry;
use Tygh\Tygh;

class BrandNames
{
    /** Longest brand, in characters (PRD 6.4). */
    public const MAX_LENGTH = 20;

    /**
     * The brand for a storefront and a message language.
     */
    public static function get(int $storefront_id, string $lang): string
    {
        $brand = self::fit(static::storedBrand($storefront_id, $lang));
        if ($brand === '') {
            $brand = self::fit(static::storefrontName($storefront_id));
        }
        if ($brand === '') {
            $brand = self::fit(static::companyName($storefront_id));
        }

        return $brand;
    }

    /**
     * The brand the admin typed for this storefront and language, '' when none or switched off.
     */
    protected static function storedBrand(int $storefront_id, string $lang): string
    {
        return (string) db_get_field(
            'SELECT d.brand_name FROM ?:kwtsms_brand_names AS b'
            . ' INNER JOIN ?:kwtsms_brand_name_descriptions AS d ON d.storefront_id = b.storefront_id'
            . ' WHERE b.storefront_id = ?i AND b.enabled = ?s AND d.lang_code = ?s',
            $storefront_id,
            'Y',
            $lang
        );
    }

    /**
     * The name of the storefront in core's storefront list, '' when there is none.
     */
    protected static function storefrontName(int $storefront_id): string
    {
        try {
            $storefront = Tygh::$app['storefront.repository']->findById($storefront_id);

            return $storefront === null ? '' : (string) $storefront->name;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * The company name: in Ultimate the company that owns the storefront; otherwise (Multi-Vendor, or no company
     * found) the store's Company settings name, which in Multi-Vendor is the marketplace. A vendor is never read.
     */
    protected static function companyName(int $storefront_id): string
    {
        if (!fn_allowed_for('MULTIVENDOR')) {
            try {
                $storefront = Tygh::$app['storefront.repository']->findById($storefront_id);
                $company_ids = $storefront === null ? [] : (array) $storefront->getCompanyIds();
                $company_id = (int) reset($company_ids);
                $name = $company_id > 0 ? trim((string) fn_get_company_name($company_id)) : '';
                if ($name !== '') {
                    return $name;
                }
            } catch (\Throwable $e) {
                // Fall through to the Company settings name.
            }
        }

        return (string) Registry::get('settings.Company.company_name');
    }

    /**
     * Clean to one plain line and cut to MAX_LENGTH characters.
     */
    private static function fit(string $name): string
    {
        $clean = MessageCleaner::cleanForStorage($name);
        $line = trim((string) preg_replace('/\s+/u', ' ', $clean));

        return trim(mb_substr($line, 0, self::MAX_LENGTH, 'UTF-8'));
    }
}
