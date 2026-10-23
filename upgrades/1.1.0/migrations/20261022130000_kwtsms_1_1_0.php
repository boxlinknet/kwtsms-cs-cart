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
     * 2026-10-23 13:14 +03: final review A, Important 1 (ruling P2-R17), task 596. The four language variables whose text changed
     * between 1.0.0 and 1.1.0 (they said "Vendor SMS ... planned for 1.1"), with the value 1.0.0 shipped, per language. A stored
     * value that still equals this is the shipped one, so it is updated to the 1.1.0 text; an edited value stays. A language
     * other than en and ar was filled from the English text. ASCII only (\u{} escapes), copied from the tag v1.0.0 .po files.
     */
    private const SHIPPED_1_0_0 = [
        'kwtsms.integrations.mve.vendor_sms' => [
            'en' => "Vendor SMS: not included in 1.0, planned for 1.1",
            'ar' => "\u{631}\u{633}\u{627}\u{626}\u{644} \u{627}\u{644}\u{628}\u{627}\u{626}\u{639}\u{64a}\u{646}: \u{63a}\u{64a}\u{631} \u{645}\u{636}\u{645}\u{646}\u{629} \u{641}\u{64a} \u{627}\u{644}\u{625}\u{635}\u{62f}\u{627}\u{631} 1.0\u{60c} \u{648}\u{645}\u{62e}\u{637}\u{637} \u{644}\u{647}\u{627} \u{641}\u{64a} 1.1",
        ],
        'kwtsms.help.overview.p2' => [
            'en' => "kwtSMS sends one-way messages, so customers can't reply. Vendor SMS is not included in version 1.0 and is planned for 1.1.",
            'ar' => "\u{64a}\u{631}\u{633}\u{644} kwtSMS \u{631}\u{633}\u{627}\u{626}\u{644} \u{628}\u{627}\u{62a}\u{62c}\u{627}\u{647} \u{648}\u{627}\u{62d}\u{62f}\u{60c} \u{641}\u{644}\u{627} \u{64a}\u{633}\u{62a}\u{637}\u{64a}\u{639} \u{627}\u{644}\u{639}\u{645}\u{644}\u{627}\u{621} \u{627}\u{644}\u{631}\u{62f}. \u{631}\u{633}\u{627}\u{626}\u{644} \u{627}\u{644}\u{628}\u{627}\u{626}\u{639}\u{64a}\u{646} \u{63a}\u{64a}\u{631} \u{645}\u{636}\u{645}\u{646}\u{629} \u{641}\u{64a} \u{627}\u{644}\u{625}\u{635}\u{62f}\u{627}\u{631} 1.0 \u{648}\u{645}\u{62e}\u{637}\u{637} \u{644}\u{647}\u{627} \u{641}\u{64a} 1.1.",
        ],
        'kwtsms.integrations.later.help' => [
            'en' => "Available in a later version.",
            'ar' => "\u{645}\u{62a}\u{627}\u{62d}\u{629} \u{641}\u{64a} \u{625}\u{635}\u{62f}\u{627}\u{631} \u{644}\u{627}\u{62d}\u{642}.",
        ],
        'kwtsms.integrations.later.discussion' => [
            'en' => "Reviews and comments",
            'ar' => "\u{627}\u{644}\u{62a}\u{642}\u{64a}\u{64a}\u{645}\u{627}\u{62a} \u{648}\u{627}\u{644}\u{62a}\u{639}\u{644}\u{64a}\u{642}\u{627}\u{62a}",
        ],
    ];

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
        self::seedTemplates();

        // fn_update_addon_language_variables() does REPLACE INTO ?:language_values and so overwrites a value the
        // merchant edited (spike of Task A1, 2026-10-22): only the missing rows are inserted here.
        // 2026-10-23 13:14 +03: final review A, Important 1 (P2-R17), task 596. First the texts that still hold the 1.0.0 wording.
        self::refreshUneditedValues((array) $scheme->getLanguageValues(false));
        foreach (array_chunk((array) $scheme->getLanguageValues(false), 500) as $chunk) {
            db_query('INSERT IGNORE INTO ?:language_values ?m', $chunk);
        }
        foreach (array_chunk((array) $scheme->getLanguageValues(true), 500) as $chunk) {
            db_query('REPLACE INTO ?:original_values ?m', $chunk);
        }

        fn_update_addon_version(self::ADDON, self::VERSION);
    }

    /**
     * Updates a language variable whose text changed in 1.1.0 only where the stored value still equals the one 1.0.0 shipped
     * (merchant edits stay). Idempotent: after the update the stored value is the new one and no longer matches.
     *
     * @param array<int,array{lang_code:string,name:string,value:string}> $rows The 1.1.0 values of the add-on
     *
     * @return int Number of values updated
     */
    public static function refreshUneditedValues(array $rows): int
    {
        $updated = 0;
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if (!isset(self::SHIPPED_1_0_0[$name])) {
                continue;
            }
            $lang = (string) $row['lang_code'];
            $old = self::SHIPPED_1_0_0[$name][$lang] ?? self::SHIPPED_1_0_0[$name]['en'];
            if ($old === (string) $row['value']) {
                continue;
            }
            $stored = db_get_field('SELECT value FROM ?:language_values WHERE lang_code = ?s AND name = ?s', $lang, $name);
            if ($stored !== false && (string) $stored === $old) {
                db_query('UPDATE ?:language_values SET value = ?s WHERE lang_code = ?s AND name = ?s', $row['value'], $lang, $name);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * @param string $function The seeding function (a parameter so a test can name a missing one)
     */
    public static function seedTemplates(string $function = 'fn_kwtsms_seed_default_templates'): void
    {
        if (function_exists($function)) {
            $function();

            return;
        }
        // 2026-10-23 08:26 +0300: cleanup CA2-1 (A1 M5), task 596. It was skipped silently; a tree without the function is broken, so say so.
        self::report('upgrade.seed_skipped', 'the default templates were not seeded: ' . $function . '() does not exist');
    }

    /**
     * The content step as the post script runs it: a failure is reported, never thrown (the Upgrade Center would then
     * show a raw error), and the notice says how to repeat the step.
     *
     * @param callable|null $step Defaults to self::content()
     *
     * @return bool True when the step finished
     */
    public static function runContent(?callable $step = null): bool
    {
        // 2026-10-23 08:26 +0300: cleanup CA2-1 (A1 M1), task 596. The step is idempotent, so re-uploading the same package is the retry.
        try {
            $step === null ? self::content() : $step();

            return true;
        } catch (\Throwable $e) {
            self::report('upgrade.content_failed', get_class($e) . ': ' . $e->getMessage());
            fn_set_notification(
                'E',
                __('error'),
                'kwtsms 1.1.0: the post-upgrade step failed (' . get_class($e) . '). It is safe to repeat: upload the same upgrade package again.'
                . ' Details are in the kwtSMS debug log and the PHP error log.'
            );

            return false;
        }
    }

    /**
     * Writes a line to the PHP error log and, when the add-on's container is up, to its debug log (level error).
     */
    private static function report(string $action, string $message): void
    {
        error_log('kwtsms: ' . $action . ': ' . $message);
        try {
            if (isset(\Tygh::$app['kwtsms.logs.debug'])) {
                \Tygh::$app['kwtsms.logs.debug']->log('error', $action, $message);
            }
        } catch (\Throwable $e) {
            error_log('kwtsms: the debug log write failed: ' . get_class($e));
        }
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
