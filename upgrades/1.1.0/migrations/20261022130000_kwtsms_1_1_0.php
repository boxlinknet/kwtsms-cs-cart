<?php

/*
 * FILE:      upgrades/1.1.0/migrations/20261022130000_kwtsms_1_1_0.php
 * CREATED:   2026-10-22 13:15 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Phinx migration that takes a 1.0.0 install of the kwtSMS add-on to 1.1.0: adds
 *            kwtsms_templates.storefront_id and swaps its unique key (uniq_event_receiver ->
 *            uniq_event_receiver_sf), adds kwtsms_brand_names.sender_id, creates kwtsms_storefront_switches,
 *            kwtsms_vendor_phones, kwtsms_vendor_quotas and kwtsms_customer_prefs. content() then creates the
 *            setting vendor_monthly_quota (existing values stay), seeds missing default templates, imports
 *            language variables that are missing (edited values stay) and sets the add-on version to 1.1.0.
 * WHY:       Phase 2 (PRD 8 amended by Task A1). An upgrade must never change an edited template, a switch, a
 *            setting or the credentials, and must write no notification_settings row (PRD 13). The Upgrade Center
 *            runs migrations BEFORE it copies the package files, so at that moment the add-on code on disk is
 *            still 1.0.0: schema() needs no add-on code and runs then; content() needs the 1.1.0 files and is run
 *            by the migration only when they are already in place (a dev store, a re-run) and otherwise by the
 *            package's post script (scripts/post.php), which the Upgrade Center runs after the copy.
 *            The class is Kwtsms110 because Phinx derives it from the file name (Util::mapFileNameToClassName).
 * USAGE:     Upgrade Center > Upload upgrade package (tools/build-upgrade-package.sh --from 1.0.0 --to 1.1.0).
 *            Tests: (new Kwtsms110('development', 20261022130000))->up(), or ->schema($prefix) on a scratch prefix.
 * DEPENDS:   Phinx (AbstractMigration), CS-Cart db_* functions, Tygh\Addons\SchemesManager,
 *            fn_update_addon_settings(), fn_get_addon_settings_values(), fn_update_addon_version().
 */

defined('BOOTSTRAP') or die('Access denied');

use Phinx\Migration\AbstractMigration;
use Tygh\Addons\SchemesManager;
use Tygh\Registry;

class Kwtsms110 extends AbstractMigration
{
    private const ADDON = 'kwtsms';
    private const VERSION = '1.1.0';

    /**
     * DDL of the four new tables, identical to app/addons/kwtsms/addon.xml (a test compares SHOW CREATE TABLE of an
     * upgraded 1.0.0 database with a fresh 1.1.0 install). {t} is the full table name.
     */
    private const NEW_TABLES = [
        'kwtsms_storefront_switches' => 'CREATE TABLE IF NOT EXISTS {t} ('
            . '`storefront_id` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`event_id` varchar(128) NOT NULL DEFAULT \'\','
            . '`receiver` varchar(15) NOT NULL DEFAULT \'\','
            . '`enabled` char(1) NOT NULL DEFAULT \'N\','
            . '`updated_at` int(11) unsigned NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`storefront_id`, `event_id`, `receiver`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET UTF8',
        'kwtsms_vendor_phones' => 'CREATE TABLE IF NOT EXISTS {t} ('
            . '`company_id` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`phone` varchar(32) NOT NULL DEFAULT \'\','
            . '`approved_at` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`approved_by` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`updated_at` int(11) unsigned NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`company_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET UTF8',
        'kwtsms_vendor_quotas' => 'CREATE TABLE IF NOT EXISTS {t} ('
            . '`plan_id` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`monthly_limit` int(11) unsigned NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`plan_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET UTF8',
        'kwtsms_customer_prefs' => 'CREATE TABLE IF NOT EXISTS {t} ('
            . '`user_id` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`order_sms` char(1) NOT NULL DEFAULT \'Y\','
            . '`updated_at` int(11) unsigned NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`user_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET UTF8',
    ];

    /**
     * Phinx entry point (up only).
     */
    public function up(): void
    {
        $this->schema((string) Registry::get('config.table_prefix'));

        // The files of this version are already on disk (dev store, test, re-run): finish here. In an Upgrade Center
        // run they are not yet (the copy comes after the migrations) and scripts/post.php calls content() instead.
        if (self::filesAreCurrent()) {
            self::content();
        }
    }

    /**
     * Columns, key swap and tables. Idempotent: every step looks first and acts only when its result is missing.
     *
     * @param string $prefix Table prefix (the store's, or a scratch prefix in a test)
     *
     * @return void
     */
    public function schema(string $prefix): void
    {
        $templates = $prefix . 'kwtsms_templates';
        if (self::tableExists($templates)) {
            if (!self::columnExists($templates, 'storefront_id')) {
                self::run('ALTER TABLE ?p ADD COLUMN `storefront_id` int(11) unsigned NOT NULL DEFAULT 0', $templates);
            }
            // Add the wider key first (it is satisfied by every existing row), then drop the old one.
            if (!self::indexExists($templates, 'uniq_event_receiver_sf')) {
                self::run('ALTER TABLE ?p ADD UNIQUE KEY `uniq_event_receiver_sf` (`event_id`, `receiver`, `storefront_id`)', $templates);
            }
            if (self::indexExists($templates, 'uniq_event_receiver')) {
                self::run('ALTER TABLE ?p DROP KEY `uniq_event_receiver`', $templates);
            }
            self::must(
                self::columnExists($templates, 'storefront_id') && self::indexExists($templates, 'uniq_event_receiver_sf')
                && !self::indexExists($templates, 'uniq_event_receiver'),
                'kwtsms_templates was not upgraded'
            );
        }

        $brands = $prefix . 'kwtsms_brand_names';
        if (self::tableExists($brands)) {
            if (!self::columnExists($brands, 'sender_id')) {
                self::run('ALTER TABLE ?p ADD COLUMN `sender_id` varchar(11) NOT NULL DEFAULT \'\'', $brands);
            }
            self::must(self::columnExists($brands, 'sender_id'), 'kwtsms_brand_names was not upgraded');
        }

        foreach (self::NEW_TABLES as $name => $ddl) {
            $table = $prefix . $name;
            if (!self::tableExists($table)) {
                self::run(str_replace('{t}', '?p', $ddl), $table);
            }
            self::must(self::tableExists($table), $name . ' was not created');
        }
    }

    /**
     * Setting, default templates, language variables and version. Idempotent. Needs the 1.1.0 files on disk.
     *
     * @return void
     */
    public static function content(): void
    {
        SchemesManager::clearInternalCache(self::ADDON);
        $scheme = SchemesManager::getScheme(self::ADDON);
        self::must($scheme !== null && $scheme->getVersion() === self::VERSION, 'the add-on files on disk are not ' . self::VERSION);

        // fn_update_addon_settings() deletes the add-on's settings section and rebuilds it from addon.xml, so it only
        // runs when the new setting is missing and is given every stored value (and per-storefront override) to put
        // back: nothing the merchant saved changes, the new setting gets its default.
        if (!self::settingExists('vendor_monthly_quota')) {
            $result = fn_update_addon_settings(
                $scheme,
                false,
                fn_get_addon_settings_values(self::ADDON),
                fn_get_addon_settings_vendor_values(self::ADDON)
            );
            self::must($result !== false && self::settingExists('vendor_monthly_quota'), 'the setting vendor_monthly_quota was not created');
        }

        // INSERT IGNORE: the default texts of events a 1.0.0 install did not have; an existing (edited) text stays.
        if (function_exists('fn_kwtsms_seed_default_templates')) {
            fn_kwtsms_seed_default_templates();
        }

        // fn_update_addon_language_variables() does REPLACE INTO ?:language_values and so overwrites a value the
        // merchant edited (spike of Task A1, 2026-10-22): only the missing rows are inserted here.
        foreach (array_chunk((array) $scheme->getLanguageValues(false), 500) as $chunk) {
            db_query('INSERT IGNORE INTO ?:language_values ?m', $chunk);
        }
        foreach (array_chunk((array) $scheme->getLanguageValues(true), 500) as $chunk) {
            db_query('REPLACE INTO ?:original_values ?m', $chunk);
        }

        fn_update_addon_version(self::ADDON, self::VERSION);
    }

    /**
     * @return bool True when the add-on files on disk are this version's
     */
    private static function filesAreCurrent(): bool
    {
        SchemesManager::clearInternalCache(self::ADDON);
        $scheme = SchemesManager::getScheme(self::ADDON);

        return $scheme !== null && $scheme->getVersion() === self::VERSION;
    }

    private static function tableExists(string $table): bool
    {
        return (int) db_get_field(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = ?s',
            $table
        ) > 0;
    }

    private static function columnExists(string $table, string $column): bool
    {
        return (int) db_get_field(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = ?s AND column_name = ?s',
            $table,
            $column
        ) > 0;
    }

    private static function indexExists(string $table, string $index): bool
    {
        return (int) db_get_field(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = ?s AND index_name = ?s',
            $table,
            $index
        ) > 0;
    }

    private static function settingExists(string $name): bool
    {
        return (int) db_get_field(
            'SELECT COUNT(*) FROM ?:settings_objects AS o INNER JOIN ?:settings_sections AS s ON s.section_id = o.section_id'
            . ' WHERE o.name = ?s AND s.name = ?s AND s.type = ?s',
            $name,
            self::ADDON,
            'ADDON'
        ) > 0;
    }

    /**
     * One statement with the table name placeholder ?p; the name is a prefix plus a fixed word, quoted here.
     */
    private static function run(string $sql, string $table): void
    {
        db_query($sql, '`' . $table . '`');
    }

    private static function must(bool $ok, string $what): void
    {
        if (!$ok) {
            throw new \RuntimeException('kwtsms 1.1.0 migration: ' . $what);
        }
    }
}
