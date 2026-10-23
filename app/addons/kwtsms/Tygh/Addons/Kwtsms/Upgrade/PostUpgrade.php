<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Upgrade/PostUpgrade.php
 * CREATED:   2026-10-23 17:33 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The content step of the 1.2.0 upgrade, run by upgrades/1.2.0/scripts/post.php after the Upgrade Center
 *            copied the files: (1) from 1.0.0 the 1.1.0 content step first (Kwtsms110::runContent(), which writes
 *            version 1.1.0), (2) the settings the add-on's addon.xml declares and the store lacks, created with their
 *            defaults while every stored value and per-storefront override is put back, (3) missing default SMS
 *            templates, (4) missing language variables (a value that still equals the text the previous release
 *            shipped is refreshed, an edited one stays), (5) the add-on version 1.2.0 last.
 * WHY:       Phase 3 Milestone 1 (PRD 8 and 13). The migration (Kwtsms120) is DDL only because migrations run before
 *            the file copy (P2-R6); settings, language files and the version need the 1.2.0 files. An upgrade changes
 *            no edited template, switch, setting or credential, writes no notification_settings row, turns no
 *            feature on, writes no phone-index row and no kwtsms_phone_index_state key (P3-31): the index backfill
 *            starts only when an admin turns a phone feature on. Every step is idempotent, so uploading the same
 *            package again is the retry.
 * USAGE:     PostUpgrade::run($from) throws on failure; PostUpgrade::runReported($from) (what post.php calls)
 *            reports instead and returns false. $from is the add-on version before the upgrade
 *            (fn_get_addon_version('kwtsms'), still the old one when post.php starts).
 * DEPENDS:   The 1.2.0 files on disk, SchemesManager, fn_update_addon_settings(), fn_update_addon_version(),
 *            fn_kwtsms_seed_default_templates(); for $from 1.0.0 the class Kwtsms110 (post.php loads its file).
 */

namespace Tygh\Addons\Kwtsms\Upgrade;

use Tygh\Addons\SchemesManager;
use Tygh\Registry;

class PostUpgrade
{
    private const ADDON = 'kwtsms';
    private const VERSION = '1.2.0';

    /**
     * Language variables whose text changed between 1.1.0 and 1.2.0, with the value 1.1.0 shipped, per language
     * ('name' => ['en' => ..., 'ar' => ...]; another language was filled from the English text). A stored value that
     * still equals it is the shipped one and is updated; an edited one stays (ruling P2-R17). None changed so far:
     * a later task that rewrites an existing text adds the old one here. ASCII only (\u{} escapes).
     */
    private const SHIPPED_1_1_0 = [];

    /**
     * @param string $from_version The add-on version before the upgrade ('1.0.0' or '1.1.0')
     *
     * @return void
     *
     * @throws \Throwable When a step fails (the version is then not written, so a retry runs every step again)
     */
    public static function run(string $from_version): void
    {
        SchemesManager::clearInternalCache(self::ADDON);
        $scheme = SchemesManager::getScheme(self::ADDON);
        if ($scheme === null || version_compare((string) $scheme->getVersion(), self::VERSION, '<')) {
            throw new \RuntimeException('the add-on files on disk are older than ' . self::VERSION);
        }

        // The 1.1.0 steps of a 1.0.0 store run first: Kwtsms110::content() writes version 1.1.0, so it must come
        // before the 1.2.0 version write below (otherwise the store would end on 1.1.0).
        if ($from_version === '1.0.0') {
            if (!class_exists('Kwtsms110', false)) {
                throw new \RuntimeException('the 1.1.0 migration file is not loaded (needed to upgrade from 1.0.0)');
            }
            if (!\Kwtsms110::runContent()) {
                throw new \RuntimeException('the 1.1.0 content step failed (details in the kwtSMS debug log)');
            }
            SchemesManager::clearInternalCache(self::ADDON);
        }

        self::addMissingSettings($scheme);

        if (function_exists('fn_kwtsms_seed_default_templates')) {
            \fn_kwtsms_seed_default_templates();
        } else {
            throw new \RuntimeException('fn_kwtsms_seed_default_templates() does not exist');
        }

        self::languageValues($scheme);

        fn_update_addon_version(self::ADDON, self::VERSION);
    }

    /**
     * run() as the post script calls it: a failure is reported (PHP error log, the add-on's debug log, an admin
     * notice that names the retry), never thrown, because the Upgrade Center would show a raw error.
     *
     * @param string        $from_version See run()
     * @param callable|null $step         Replaces run() (a test injects a failing step)
     *
     * @return bool True when every step finished
     */
    public static function runReported(string $from_version, ?callable $step = null): bool
    {
        // 2026-10-23 17:37 +03: Task M1.1b, task 596. $step lets a test prove the failure path (like
        // Kwtsms110::runContent).
        try {
            $step === null ? self::run($from_version) : $step();

            return true;
        } catch (\Throwable $e) {
            $message = get_class($e) . ': ' . $e->getMessage();
            error_log('kwtsms: upgrade.content_failed: ' . $message);
            try {
                if (isset(\Tygh::$app['kwtsms.logs.debug'])) {
                    \Tygh::$app['kwtsms.logs.debug']->log('error', 'upgrade.content_failed', $message);
                }
            } catch (\Throwable $inner) {
                error_log('kwtsms: the debug log write failed: ' . get_class($inner));
            }
            // 2026-10-23 17:41 +03: Task M1.1b, task 596. The notice is escaped like every notice (package gate).
            fn_set_notification(
                'E',
                __('error'),
                fn_html_escape(
                    'kwtsms ' . self::VERSION . ': the post-upgrade step failed (' . get_class($e) . '). It is safe to '
                    . 'repeat: upload the same upgrade package again. Details are in the kwtSMS debug log and the PHP '
                    . 'error log.'
                )
            );

            return false;
        }
    }

    /**
     * Names of the settings the 1.2.0 addon.xml declares that the store does not have.
     *
     * @param object $scheme The add-on scheme
     *
     * @return string[]
     */
    public static function missingSettings($scheme): array
    {
        $declared = [];
        foreach ((array) $scheme->getSections() as $section) {
            foreach ((array) $scheme->getSettings($section['id']) as $setting) {
                if (!empty($setting['id'])) {
                    $declared[] = (string) $setting['id'];
                }
            }
        }
        $stored = db_get_fields(
            'SELECT o.name FROM ?:settings_objects AS o INNER JOIN ?:settings_sections AS s'
            . ' ON s.section_id = o.section_id WHERE s.name = ?s AND s.type = ?s',
            self::ADDON,
            'ADDON'
        );

        return array_values(array_diff($declared, array_map('strval', $stored)));
    }

    /**
     * fn_update_addon_settings() deletes the add-on's settings section and rebuilds it from addon.xml, so it runs
     * only when a setting is missing and is given every stored value (and per-storefront override) to put back:
     * nothing the merchant saved changes, the new settings get their defaults (all switches OFF).
     *
     * @param object $scheme The add-on scheme
     *
     * @return void
     */
    private static function addMissingSettings($scheme): void
    {
        if (self::missingSettings($scheme) === []) {
            return;
        }
        $result = fn_update_addon_settings(
            $scheme,
            false,
            fn_get_addon_settings_values(self::ADDON),
            fn_get_addon_settings_vendor_values(self::ADDON)
        );
        $left = self::missingSettings($scheme);
        if ($result === false || $left !== []) {
            throw new \RuntimeException('the settings were not created: ' . implode(', ', $left));
        }
    }

    /**
     * Inserts the language variables the store lacks (INSERT IGNORE: an edited value stays; REPLACE INTO would
     * overwrite it, spike of Task A1) and refreshes the originals.
     *
     * @param object $scheme The add-on scheme
     *
     * @return void
     */
    private static function languageValues($scheme): void
    {
        self::refreshUneditedValues((array) $scheme->getLanguageValues(false));
        foreach (array_chunk((array) $scheme->getLanguageValues(false), 500) as $chunk) {
            db_query('INSERT IGNORE INTO ?:language_values ?m', $chunk);
        }
        foreach (array_chunk((array) $scheme->getLanguageValues(true), 500) as $chunk) {
            db_query('REPLACE INTO ?:original_values ?m', $chunk);
        }
    }

    /**
     * Updates a language variable whose text changed in 1.2.0 only where the stored value still equals the one 1.1.0
     * shipped (merchant edits stay). Idempotent.
     *
     * @param array<int,array{lang_code:string,name:string,value:string}> $rows    The 1.2.0 values of the add-on
     * @param array<string,array<string,string>>|null                      $shipped Defaults to SHIPPED_1_1_0
     *
     * @return int Number of values updated
     */
    public static function refreshUneditedValues(array $rows, ?array $shipped = null): int
    {
        $shipped = $shipped ?? self::SHIPPED_1_1_0;
        $updated = 0;
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if (!isset($shipped[$name])) {
                continue;
            }
            $lang = (string) $row['lang_code'];
            $old = $shipped[$name][$lang] ?? $shipped[$name]['en'];
            if ($old === (string) $row['value']) {
                continue;
            }
            $stored = db_get_field(
                'SELECT value FROM ?:language_values WHERE lang_code = ?s AND name = ?s',
                $lang,
                $name
            );
            if ($stored !== false && (string) $stored === $old) {
                db_query(
                    'UPDATE ?:language_values SET value = ?s WHERE lang_code = ?s AND name = ?s',
                    $row['value'],
                    $lang,
                    $name
                );
                $updated++;
            }
        }

        return $updated;
    }
}
