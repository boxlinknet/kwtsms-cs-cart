<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Upgrade/PostUpgrade130.php
 * CREATED:   2026-11-04 18:19 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The content step of the 1.3.0 upgrade, run by upgrades/1.3.0/scripts/post.php after the Upgrade Center
 *            copied the files, and by PostUpgrade::selfHeal() for a stored version below 1.3.0: (1) from a stored
 *            1.2.0 (final or any release candidate) PostRefresh::run() first (the idempotent rc wording refresh);
 *            from 1.1.0 or 1.0.0 whose stored version is still below 1.2.0, PostUpgrade::run($from) first (the 1.2.0
 *            content, and
 *            from 1.0.0 the 1.1.0 content before it), (2) the 1.3.0 tables must exist (the migration made them),
 *            (3) the settings addon.xml declares and the store lacks, created with their defaults while every stored
 *            value and per-storefront override is put back (P2-R6 method), (4) missing default SMS templates, (5)
 *            missing language variables (INSERT IGNORE: an edited value stays), (6) the add-on version 1.3.0 last.
 * WHY:       Milestone 2 (PRD 8 and 13, ruling P3-60). The migration (Kwtsms130) is DDL only because migrations run
 *            before the file copy; settings, language files and the version need the 1.3.0 files. This is a NEW class
 *            name on purpose: the Upgrade Center request has the OLD PostUpgrade loaded (the old func.php calls
 *            PostUpgrade::selfHeal() on the admin before_dispatch hook), so new behaviour cannot live there; this class
 *            is autoloaded from the new files and depends on no method that only the new PostUpgrade has. It writes no
 *            switch row, no feature value (every new setting is created OFF), no kwtsms_twofa row, no phone index row
 *            and no storage key, so an upgrade turns nothing on and holds nobody at login (PRD 13). Idempotent.
 * USAGE:     PostUpgrade130::run($from) throws on failure (the version is then not written, a retry runs every step
 *            again); PostUpgrade130::runReported($from) (what post.php calls) reports instead and returns false, one
 *            run at a time (database lock). $from is the add-on version before the upgrade
 *            (fn_get_addon_version('kwtsms'), still the old one when post.php starts).
 * DEPENDS:   The 1.3.0 files on disk, SchemesManager, fn_update_addon_settings(), fn_update_addon_version(),
 *            Templates\TemplateRepository, Upgrade\{PostRefresh,PostUpgrade}; the tables of Kwtsms130.
 */

namespace Tygh\Addons\Kwtsms\Upgrade;

use Tygh\Addons\SchemesManager;

class PostUpgrade130
{
    public const VERSION = '1.3.0';

    private const ADDON = 'kwtsms';

    /** Name of the database lock that serializes 1.3.0 post-upgrade runs (post.php and the self-heal). */
    private const LOCK = 'kwtsms_post_upgrade_130';

    /** The two tables the 1.3.0 migration creates (checked, never created here: DDL belongs to the migration). */
    private const TABLES = ['kwtsms_twofa', 'kwtsms_backup_codes'];

    /**
     * Default templates ("event_id|receiver") that must exist before the version is written: the two 1.2.0 ones
     * (a stale class in the Upgrade Center request can leave them out, ruling P3-R16) and the 1.3.0 ones. The
     * kwtsms.send_fail_spike row (receiver A) is added with its default text by Task M2.11; seedDefaults() below
     * seeds whatever Templates\Defaults holds, so no change here is needed then except naming it in this list.
     */
    // 2026-11-06 22:09 +03: Task M2.11 (ruling [M2.11] 2026-11-04 19:02), task 596. kwtsms.send_fail_spike (receiver A)
    // joins the list: its default text is seeded by seedDefaults() and checked here before the version is written.
    private const TEMPLATES = ['otp.code|C', 'security.phone_changed|C', 'kwtsms.send_fail_spike|A'];

    /**
     * Language variables whose text changed between 1.2.0 and 1.3.0, with the text 1.2.0 shipped, per language
     * (name => lang => text). None changed so far: a later task that rewrites an existing text adds the old one here.
     * ASCII only (\u{} escapes).
     */
    private const SHIPPED_1_2_0 = [];

    /**
     * @param string        $from_version The add-on version before the upgrade ('1.0.0', '1.1.0', '1.2.0'; '1.3.0'
     *                                    repairs a store whose templates are missing)
     * @param callable|null $seeder       Replaces the template seed (a test injects a no-op or a failing one)
     *
     * @return void
     *
     * @throws \Throwable When a step fails (the version is then not written, so a retry runs every step again)
     */
    public static function run(string $from_version, ?callable $seeder = null): void
    {
        SchemesManager::clearInternalCache(self::ADDON);
        $scheme = SchemesManager::getScheme(self::ADDON);
        if ($scheme === null || version_compare((string) $scheme->getVersion(), self::VERSION, '<')) {
            throw new \RuntimeException('the add-on files on disk are older than ' . self::VERSION);
        }

        // (1) The earlier content steps. A 1.0.0 / 1.1.0 store whose stored version is still below 1.2.0 gets the
        // 1.2.0 step (PostUpgrade::run() writes 1.2.0); post.php may already have run it, then the stored version is
        // 1.2.0 and it is not repeated. A 1.2.0 store gets the rc wording refresh.
        $stored = self::storedVersion();
        if (($from_version === '1.0.0' || $from_version === '1.1.0') && version_compare($stored, '1.2.0', '<')) {
            PostUpgrade::run($from_version);
            SchemesManager::clearInternalCache(self::ADDON);
            $scheme = SchemesManager::getScheme(self::ADDON);
        } elseif ($from_version === '1.2.0') {
            PostRefresh::run();
        }

        // (2) The migration ran before the copy; a store whose tables are missing is not finished.
        $absent = self::missingTables();
        if ($absent !== []) {
            throw new \RuntimeException(
                'the 1.3.0 tables are missing (' . implode(', ', $absent) . '): run the migration'
            );
        }

        // (3) Settings: only the missing ones, defaults, every stored value kept.
        self::addMissingSettings($scheme);

        // (4) Missing default templates, through the class (autoloaded from the new files), not a func.php function.
        try {
            if ($seeder !== null) {
                $seeder();
            } else {
                (new \Tygh\Addons\Kwtsms\Templates\TemplateRepository())->seedDefaults(
                    array_map('strval', array_keys(\Tygh\Languages\Languages::getAll()))
                );
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'seeding the default templates failed: ' . get_class($e) . ': ' . $e->getMessage(),
                0,
                $e
            );
        }

        // (5) Language variables: INSERT IGNORE keeps an edited value; an unedited old text is refreshed.
        PostRefresh::refreshUneditedValues((array) $scheme->getLanguageValues(false), self::SHIPPED_1_2_0);
        foreach (array_chunk((array) $scheme->getLanguageValues(false), 500) as $chunk) {
            db_query('INSERT IGNORE INTO ?:language_values ?m', $chunk);
        }
        foreach (array_chunk((array) $scheme->getLanguageValues(true), 500) as $chunk) {
            db_query('REPLACE INTO ?:original_values ?m', $chunk);
        }

        // The version is set only when the templates exist (a stale class can have left them out).
        $missing = self::missingTemplates();
        if ($missing !== []) {
            throw new \RuntimeException('the default templates are missing (' . implode(', ', $missing) . ')');
        }

        // (6) Last.
        self::writeVersion(self::VERSION);
    }

    /**
     * run() as the post script calls it: a failure is reported (PHP error log, the add-on's debug log, an admin
     * notice that names the retry), never thrown, because the Upgrade Center would show a raw error.
     *
     * @param string        $from_version See run()
     * @param callable|null $step         Replaces run() (a test injects a failing step); it takes no lock
     *
     * @return bool True when every step finished (or another request holds the lock and is running them)
     */
    public static function runReported(string $from_version, ?callable $step = null): bool
    {
        $locked = $step === null;
        if ($locked && (int) db_get_field('SELECT GET_LOCK(?s, 0)', self::LOCK) !== 1) {
            return true;
        }
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
            if (function_exists('fn_set_notification') && function_exists('fn_html_escape')) {
                fn_set_notification(
                    'E',
                    __('error'),
                    fn_html_escape(
                        'kwtsms ' . self::VERSION . ': the post-upgrade step failed (' . get_class($e) . '). Open any '
                        . 'kwtSMS admin page to finish the upgrade: the step runs again by itself and is safe to '
                        . 'repeat. Details are in the kwtSMS debug log and the PHP error log.'
                    )
                );
            }

            return false;
        } finally {
            if ($locked) {
                db_get_field('SELECT RELEASE_LOCK(?s)', self::LOCK);
            }
        }
    }

    /**
     * Names of the settings the 1.3.0 addon.xml declares that the store does not have.
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
     * The 1.3.0 tables the store lacks.
     *
     * @return string[]
     */
    public static function missingTables(): array
    {
        $prefix = (string) \Tygh\Registry::get('config.table_prefix');
        $missing = [];
        foreach (self::TABLES as $name) {
            $found = (int) db_get_field(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = ?s',
                $prefix . $name
            );
            if ($found === 0) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * The default templates ("event_id|receiver") that the store lacks: no template row, or a row without a
     * description. A fixed list on purpose: Defaults::events() may itself be a stale in-memory class.
     *
     * @return string[]
     */
    public static function missingTemplates(): array
    {
        $missing = [];
        foreach (self::TEMPLATES as $key) {
            [$event, $receiver] = explode('|', $key);
            $id = (int) db_get_field(
                'SELECT template_id FROM ?:kwtsms_templates WHERE event_id = ?s AND receiver = ?s',
                $event,
                $receiver
            );
            $described = $id > 0 && (int) db_get_field(
                'SELECT COUNT(*) FROM ?:kwtsms_template_descriptions WHERE template_id = ?i',
                $id
            ) > 0;
            if (!$described) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * fn_update_addon_settings() deletes the add-on's settings section and rebuilds it from addon.xml, so it runs
     * only when a setting is missing and is given every stored value (and per-storefront override) to put back:
     * nothing the merchant saved changes, the new settings get their defaults (all features OFF).
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
        // These three core functions have no equivalent direct write: a core without them fails the step with a
        // message (reported, retried), never a fatal.
        $needed = ['fn_update_addon_settings', 'fn_get_addon_settings_values', 'fn_get_addon_settings_vendor_values'];
        foreach ($needed as $core) {
            if (!function_exists($core)) {
                throw new \RuntimeException('this CS-Cart has no ' . $core . '()');
            }
        }
        // 2026-11-08 00:07 +03: M2.10 G7, task 596. A fn_update_addon_settings() with fewer than 4 parameters ignores the
        // stored values we pass and rebuilds the section from defaults: the merchant's values would be dropped. Refuse
        // first; the run reports the step and retries.
        self::requireValueRestoringSettingsFunction(
            (new \ReflectionFunction('fn_update_addon_settings'))->getNumberOfParameters()
        );
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
     * 2026-11-08 00:07 +03: M2.10 G7, task 596. fn_update_addon_settings($scheme, $execute, $values, $vendor_values) must
     * take all four parameters, or the stored values are not put back.
     *
     * @throws \RuntimeException When the function takes fewer than 4 parameters
     */
    private static function requireValueRestoringSettingsFunction(int $parameter_count): void
    {
        if ($parameter_count < 4) {
            throw new \RuntimeException('this CS-Cart cannot restore stored values');
        }
    }

    /** The stored add-on version: the core function when this CS-Cart has it, else a direct read of ?:addons. */
    private static function storedVersion(): string
    {
        if (function_exists('fn_get_addon_version')) {
            return (string) fn_get_addon_version(self::ADDON);
        }

        return (string) db_get_field('SELECT version FROM ?:addons WHERE addon = ?s', self::ADDON);
    }

    /** The reverse of storedVersion(): fn_update_addon_version() or a direct UPDATE of the same row. */
    private static function writeVersion(string $version): void
    {
        if (function_exists('fn_update_addon_version')) {
            fn_update_addon_version(self::ADDON, $version);

            return;
        }
        db_query('UPDATE ?:addons SET version = ?s WHERE addon = ?s', $version, self::ADDON);
    }
}
