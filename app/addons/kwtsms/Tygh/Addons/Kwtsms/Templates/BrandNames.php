<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Templates/BrandNames.php
 * CREATED:   2026-10-20 23:10 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The "SMS brand name" a message shows as {store_name}: the brand the admin set for the storefront in
 *            the message language, else the storefront name, else the company name; at most 20 characters, on
 *            one plain line. Also the writer of the brand tables for the Templates page's brand-name editor
 *            (2026-10-21 05:10 +03: typed(), fallback(), normalize() and store() added, Task 1.12 fix round 1 item 3,
 *            ruling R33).
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
 *            The editor: typed($id, $lang) is what the admin typed ('' = none), fallback($id) what {store_name} is when
 *            nothing is typed, normalize($text) the stored form of a typed name (cleaned for storage, characters above
 *            U+FFFF removed because the table is utf8mb3, white space runs one space, cut to 20, trimmed), store($id,
 *            $lang, $text) writes it (enabled = Y) and returns it.
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
     * What the admin typed for a storefront and language ('' when none or switched off), as the editor shows it.
     */
    public static function typed(int $storefront_id, string $lang): string
    {
        return static::storedBrand($storefront_id, $lang);
    }

    /**
     * What {store_name} is when nothing is typed: the storefront name, else the company name ('' when neither is
     * named). The editor shows it as the placeholder of the input.
     */
    public static function fallback(int $storefront_id): string
    {
        $brand = self::fit(static::storefrontName($storefront_id));
        if ($brand === '') {
            $brand = self::fit(static::companyName($storefront_id));
        }

        return $brand;
    }

    /**
     * The stored form of a typed brand name: cleaned for storage (ruling R18), the characters above U+FFFF removed
     * BEFORE the cut (the column is utf8mb3), every run of white space one space, cut to MAX_LENGTH characters.
     */
    public static function normalize(string $name): string
    {
        return self::fit($name);
    }

    /**
     * Writes a typed brand name for a storefront and language (the storefront entry is switched on) and returns what
     * was stored. An empty result clears the language (the fallback applies); a language with no row and nothing to
     * store writes nothing. The caller has checked the storefront id and the language.
     */
    public static function store(int $storefront_id, string $lang, string $name): string
    {
        $brand = self::normalize($name);
        $has_row = (int) db_get_field(
            'SELECT COUNT(*) FROM ?:kwtsms_brand_name_descriptions WHERE storefront_id = ?i AND lang_code = ?s',
            $storefront_id,
            $lang
        ) > 0;
        if ($brand === '' && !$has_row) {
            return '';
        }
        db_query(
            // 2026-10-21 08:37 +03: the statement is split over two lines for 120 columns (phpcs), same SQL,
            // cleanup batch, task 596.
            'INSERT INTO ?:kwtsms_brand_names (storefront_id, enabled) VALUES (?i, ?s)'
            . ' ON DUPLICATE KEY UPDATE enabled = ?s',
            $storefront_id,
            'Y',
            'Y'
        );
        db_query('REPLACE INTO ?:kwtsms_brand_name_descriptions ?e', [
            'storefront_id' => $storefront_id,
            'lang_code' => $lang,
            'brand_name' => $brand,
        ]);

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
        return trim(mb_substr(self::oneLine($name), 0, self::MAX_LENGTH, 'UTF-8'));
    }

    /**
     * Cleaned for storage, the characters above U+FFFF out (the column is utf8mb3), one plain line, not yet cut.
     * 2026-10-21 05:15 +03: split out of fit(), Task 1.12 fix round 1 item 3, task 596.
     */
    private static function oneLine(string $name): string
    {
        $clean = MessageCleaner::cleanForStorage($name);
        $clean = (string) preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $clean);

        return trim((string) preg_replace('/\s+/u', ' ', $clean));
    }

    /**
     * Whether the cut to MAX_LENGTH takes characters off a typed name (the editor says so).
     */
    public static function wasCut(string $name): bool
    {
        return mb_strlen(self::oneLine($name), 'UTF-8') > self::MAX_LENGTH;
    }
}
