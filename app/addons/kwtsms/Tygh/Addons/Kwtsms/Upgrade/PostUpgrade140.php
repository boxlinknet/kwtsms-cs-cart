<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Upgrade/PostUpgrade140.php
 * CREATED:   2026-11-12 02:02 +03
 * TASK:      596 M3.0
 * AUTHOR:    kwtSMS
 * WHAT:      The content step of the 1.4.0 upgrade, run by upgrades/1.4.0/scripts/post.php after the Upgrade Center
 *            copied the files, and by PostUpgrade::selfHeal() for a stored version below 1.4.0 or a 1.4.0 store with a
 *            default template missing: (1) a store whose stored version is still below 1.3.0 gets
 *            PostUpgrade130::run($from) first (the 1.3.0 content, which runs the 1.2.0 content or the rc refresh
 *            before it), (2) the 1.4.0 tables must exist (the migration made them), (3) the settings addon.xml
 *            declares and the store lacks, created with their defaults while every stored value and per-storefront
 *            override is put back (P2-R6 method), (4) missing default SMS templates, (5) missing language variables
 *            (INSERT IGNORE: an edited value stays), (6) the add-on version 1.4.0 last.
 * WHY:       Milestone 3 (P3-80). The migration (Kwtsms140) is DDL only because migrations run before the file copy;
 *            settings, language files and the version need the 1.4.0 files. This is a NEW class name on purpose: the
 *            Upgrade Center request may have the OLD PostUpgrade and PostUpgrade130 loaded (the old func.php calls
 *            PostUpgrade::selfHeal() on the admin before_dispatch hook), so new behaviour cannot live there; this class
 *            is autoloaded from the new files and uses only methods the released 1.3.0 PostUpgrade130 already has
 *            (run(), missingTemplates()). It writes no switch row, no condition row, no vendor sender row and no
 *            feature value (every new setting is created OFF), so an upgrade turns nothing on (PRD 13). Idempotent.
 * USAGE:     PostUpgrade140::run($from) throws on failure (the version is then not written, a retry runs every step
 *            again); PostUpgrade140::runReported($from) (what post.php calls) reports instead and returns false, one
 *            run at a time (database lock kwtsms_post_upgrade_140). $from is the add-on version before the upgrade.
 *            Each 1.4.0 event task that adds a default text names it in TEMPLATES.
 * DEPENDS:   The 1.4.0 files on disk, SchemesManager, fn_update_addon_settings(), fn_update_addon_version(),
 *            Templates\TemplateRepository, Upgrade\{PostRefresh,PostUpgrade130}; the tables of Kwtsms140.
 */

namespace Tygh\Addons\Kwtsms\Upgrade;

use Tygh\Addons\SchemesManager;

class PostUpgrade140
{
    public const VERSION = '1.4.0';

    private const ADDON = 'kwtsms';

    /** Name of the database lock that serializes 1.4.0 post-upgrade runs (post.php and the self-heal). */
    private const LOCK = 'kwtsms_post_upgrade_140';

    /**
     * 2026-11-17 15:22 +03: exception code of "the 1.4.0 tables are missing" (review M3, task 596): the notice then
     * names the
     * Upgrade Center, because the DDL runs only from the migration and an admin page cannot create the tables.
     */
    public const CODE_TABLES_MISSING = 1401;

    /**
     * 2026-11-17 15:32 +03: language keys shipped in 1.3.0 and gone in 1.4.0 (review M4, task 596). INSERT IGNORE never
     * removes a key, so step (5) deletes them (no code reads them; a fresh 1.4.0 install does not have them).
     */
    public const REMOVED_LANGVARS = ['kwtsms.integrations.later.installed'];

    /** The two tables the 1.4.0 migration creates (checked, never created here: DDL belongs to the migration). */
    private const TABLES = ['kwtsms_event_conditions', 'kwtsms_vendor_senders'];

    /**
     * The 1.4.0 default templates ("event_id|receiver") that must exist before the version is written. Each event
     * task (M3.1 onward) lists its new default texts here; missingTemplates() also checks the 1.3.0 list.
     *
     * @var string[]
     */
    // 2026-11-12 07:40 +03: Task M3.1 (RMA status SMS), task 596: the return status family texts.
    // 2026-11-14 05:30 +03: Task M3.2 (call request SMS), task 596: the A and V texts of the two call request events.
    private const TEMPLATES = [
        'rma.status_changed|C', 'rma.status_changed|A', 'rma.status_changed|V',
        'call_requests.request_created|A', 'call_requests.request_created|V',
        'call_requests.request_about_product_created|A', 'call_requests.request_about_product_created|V',
        // 2026-11-14 09:37 +03: Task M3.3 (back-in-stock SMS), task 596: the customer text.
        'product.back_in_stock|C',
        // 2026-11-14 13:26 +03: Task M3.4 (gift certificate SMS), task 596: the purchaser texts (status family,
        // updated).
        'gift_certificates.gift_certificate.status_changed|C', 'gift_certificates.gift_certificate.updated|C',
        // 2026-11-14 17:25 +03: Task M3.5 (review, discussion and message SMS), task 596: the A, V and C texts.
        'discussion.products.new_post|A', 'discussion.products.new_post|V',
        // 2026-11-14 22:30 +03: task 596 M3.5 review M4. Core has no V receiver on testimonials and categories.
        'discussion.testimonials.new_post|A',
        'discussion.orders.new_post|A', 'discussion.orders.new_post|V',
        'discussion.categories.new_post|A',
        'discussion.pages.new_post|A', 'discussion.pages.new_post|V',
        'discussion.vendors.new_post|A', 'discussion.vendors.new_post|V',
        'product_reviews.new_post|A', 'product_reviews.new_post|V',
        'vendor_communication.message_received|C', 'vendor_communication.message_received|A',
        'vendor_communication.message_received|V',
        'vendor_communication.order_message_received|C', 'vendor_communication.order_message_received|V',
        'vendor_communication.vendor_to_admin_message_received|A',
        // 2026-11-15 01:51 +03: Task M3.6 (vendor status, update and plan SMS), task 596: the vendor family and update
        // texts, the plan change texts of A and V.
        'order.status_changed|V', 'order.updated|V', 'vendor_plans.plan_changed|A', 'vendor_plans.plan_changed|V',
    ];

    /**
     * Language variables whose text changed between 1.3.0 and 1.4.0, with the text 1.3.0 shipped, per language
     * (name => lang => text): an unedited old text is refreshed, an edited one stays. A task that rewrites an
     * existing text adds the old one here (the lesson of PostUpgrade130::SHIPPED_1_2_0). ASCII only (\u{} escapes).
     *
     * @var array<string,array<string,string>>
     */
    // 2026-11-17 07:25 +03: Task M3.13, task 596. Two 1.3.0 texts rewritten in 1.4.0: the add-on rows of the
    // Integrations page are live (no more "Coming in 1.4."), and the Logs label of antibot_required no longer says
    // "registration" (call requests and discussion posts use it too). Texts byte-exact from tag v1.3.0.
    private const SHIPPED_1_3_0 = [
        'kwtsms.integrations.later.help' => [
            'en' => 'Coming in 1.4.',
            'ar' => "\u{642}\u{631}\u{64a}\u{628}\u{627} \u{641}\u{64a} "
                . "\u{627}\u{644}\u{625}\u{635}\u{62f}\u{627}\u{631} 1.4.",
        ],
        'kwtsms.logs.reason.antibot_required' => [
            'en' => 'Skipped: CAPTCHA for registration is off.',
            'ar' => "\u{62a}\u{645} \u{627}\u{644}\u{62a}\u{62e}\u{637}\u{64a}: "
                . "\u{627}\u{644}\u{643}\u{627}\u{628}\u{62a}\u{634}\u{627} "
                . "\u{644}\u{644}\u{62a}\u{633}\u{62c}\u{64a}\u{644} "
                . "\u{63a}\u{64a}\u{631} \u{645}\u{641}\u{639}\u{644}\u{629}.",
        ],
    ];

    /**
     * The 1.1.0 text of kwtsms.integrations.later.help, per language (name => lang => text), byte-exact from tag
     * v1.1.0-rc.1 (the v1.1.0 file). A store upgraded from 1.1.0 (or one that went through 1.2.0 and 1.3.0 without
     * the refresh) still holds it and no other map lists it. An unedited text is refreshed, an edited one stays.
     * ASCII only (\u{} escapes).
     * 2026-11-18 11:26 +03: added, Task fixE2 (M3.15 dry run), task 596.
     *
     * @var array<string,array<string,string>>
     */
    private const SHIPPED_1_1_0 = [
        'kwtsms.integrations.later.help' => [
            'en' => 'Coming in 1.2.',
            'ar' => "\u{642}\u{631}\u{64a}\u{628}\u{627} "
                . "\u{641}\u{64a} "
                . "\u{627}\u{644}\u{625}\u{635}\u{62f}\u{627}\u{631} "
                . "1.2.",
        ],
    ];

    /**
     * @param string        $from_version The add-on version before the upgrade ('1.0.0' .. '1.3.0'; '1.4.0' repairs
     *                                    a store whose templates or settings are missing)
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

        // (1) The earlier content steps: a store still below 1.3.0 (post.php's PostUpgrade130 run failed, or the
        // self-heal of a 1.0.0 / 1.1.0 / 1.2.0 store) gets the 1.3.0 step, which writes 1.3.0.
        if (version_compare(self::storedVersion(), PostUpgrade130::VERSION, '<')) {
            PostUpgrade130::run($from_version);
            SchemesManager::clearInternalCache(self::ADDON);
            $scheme = SchemesManager::getScheme(self::ADDON);
        }

        // (2) The migration ran before the copy; a store whose tables are missing is not finished.
        $absent = self::missingTables();
        if ($absent !== []) {
            throw new \RuntimeException(
                'the 1.4.0 tables are missing (' . implode(', ', $absent) . '): run the migration',
                self::CODE_TABLES_MISSING
            );
        }

        // (2b) 2026-11-17 18:10 +03: FIX-D, task 596. The code KPI index; a failure is reported once, never fatal.
        self::ensureCodeIndexReported();

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
        // 2026-11-17 07:50 +03: Task M3.13 round 3, task 596. SHIPPED_1_3_0 plus the Arabic texts renamed to the
        // glossary terms.
        PostRefresh::refreshUneditedValues((array) $scheme->getLanguageValues(false), self::shipped130());
        // 2026-11-18 11:26 +03: Task fixE2, task 596. The 1.1.0 text of the Integrations help line (M3.15 dry run).
        PostRefresh::refreshUneditedValues((array) $scheme->getLanguageValues(false), self::shipped110());
        foreach (array_chunk((array) $scheme->getLanguageValues(false), 500) as $chunk) {
            db_query('INSERT IGNORE INTO ?:language_values ?m', $chunk);
        }
        foreach (array_chunk((array) $scheme->getLanguageValues(true), 500) as $chunk) {
            db_query('REPLACE INTO ?:original_values ?m', $chunk);
        }
        // 2026-11-17 15:32 +03: review M4, task 596: drop the keys 1.4.0 no longer ships (language_values and the
        // original text, whose context is 'Languages::<name>').
        db_query('DELETE FROM ?:language_values WHERE name IN (?a)', self::REMOVED_LANGVARS);
        db_query(
            'DELETE FROM ?:original_values WHERE msgctxt IN (?a)',
            array_map(static fn (string $name): string => 'Languages::' . $name, self::REMOVED_LANGVARS)
        );

        // The version is set only when the templates exist (a stale class can have left them out).
        $missing = self::missingTemplates();
        if ($missing !== []) {
            throw new \RuntimeException('the default templates are missing (' . implode(', ', $missing) . ')');
        }

        // (6) Last.
        self::writeVersion(self::VERSION);
    }

    /**
     * 2026-11-17 18:10 +03: FIX-D, task 596. Adds idx_created_status (created_at, status) to kwtsms_otp_codes and
     * drops the redundant idx_created_at in the same ALTER, once: nothing is done when the new index exists. Seams:
     * $indexes(table) returns the index names, $alter(table, dropOld) runs the ALTER.
     *
     * @return string 'added' or 'present'
     *
     * @throws \Throwable When the ALTER fails
     */
    public static function ensureCodeStatusIndex(
        string $prefix = '',
        ?callable $indexes = null,
        ?callable $alter = null
    ): string {
        $table = $prefix . 'kwtsms_otp_codes';
        $names = $indexes !== null ? $indexes($table) : array_map('strval', (array) db_get_fields(
            'SELECT DISTINCT index_name FROM information_schema.STATISTICS WHERE table_schema = DATABASE()'
            . ' AND table_name = ?s',
            $table
        ));
        if (in_array('idx_created_status', $names, true)) {
            return 'present';
        }
        $drop = in_array('idx_created_at', $names, true);
        if ($alter !== null) {
            $alter($table, $drop);
        } else {
            db_query(
                'ALTER TABLE ?p ADD KEY `idx_created_status` (`created_at`, `status`)'
                . ($drop ? ', DROP KEY `idx_created_at`' : ''),
                '`' . $table . '`'
            );
        }

        return 'added';
    }

    /** ensureCodeStatusIndex() for the store, failure reported (PHP error log, debug log), never thrown. */
    private static function ensureCodeIndexReported(): void
    {
        try {
            self::ensureCodeStatusIndex((string) \Tygh\Registry::get('config.table_prefix'));
        } catch (\Throwable $e) {
            $message = get_class($e) . ': ' . $e->getMessage();
            error_log('kwtsms: upgrade.code_index_failed: ' . $message);
            try {
                if (isset(\Tygh::$app['kwtsms.logs.debug'])) {
                    \Tygh::$app['kwtsms.logs.debug']->log('error', 'upgrade.code_index_failed', $message);
                }
            } catch (\Throwable $ignored) {
                // the PHP error log has it
            }
        }
    }

    /**
     * The 1.3.0 texts that 1.4.0 rewrote: SHIPPED_1_3_0 and Shipped130Terms.php (the Arabic rename to the kwtSMS
     * terms).
     * 2026-11-17 07:50 +03: Task M3.13 round 3, task 596.
     *
     * @return array<string,array<string,string>> name => lang => text
     */
    public static function shipped130(): array
    {
        return self::SHIPPED_1_3_0 + (array) require __DIR__ . '/Shipped130Terms.php';
    }

    /**
     * The 1.1.0 texts that 1.4.0 rewrote and no earlier step refreshes: SHIPPED_1_1_0.
     * 2026-11-18 11:26 +03: added, Task fixE2, task 596.
     *
     * @return array<string,array<string,string>> name => lang => text
     */
    public static function shipped110(): array
    {
        return self::SHIPPED_1_1_0;
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
                        $e->getCode() === self::CODE_TABLES_MISSING
                            // 2026-11-17 15:22 +03: review M3, task 596: this fault is not healed by an admin page.
                            ? 'kwtsms ' . self::VERSION . ': the add-on files are new but the database tables are not. '
                                . 'Run the 1.4.0 upgrade package again from the Upgrade Center '
                                . '(Administration, Upgrade center); '
                                . 'order SMS stay off until it has finished. Details are in the kwtSMS debug '
                                . 'log and the PHP error log.'
                            : 'kwtsms ' . self::VERSION . ': the post-upgrade step failed (' . get_class($e)
                                . '). Open any kwtSMS admin page to finish the upgrade: the step runs again by '
                                . 'itself and is safe to repeat. Details are in the kwtSMS debug log and the PHP '
                                . 'error log.'
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
     * Names of the settings the 1.4.0 addon.xml declares that the store does not have.
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
     * The 1.4.0 tables the store lacks.
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
     * The default templates ("event_id|receiver") that the store lacks: the 1.4.0 list (no template row, or a row
     * without a description) and the 1.3.0 list of PostUpgrade130. A fixed list on purpose: Defaults::events() may
     * itself be a stale in-memory class.
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

        return array_values(array_unique(array_merge(PostUpgrade130::missingTemplates(), $missing)));
    }

    /**
     * fn_update_addon_settings() deletes the add-on's settings section and rebuilds it from addon.xml, so it runs
     * only when a setting is missing and is given every stored value (and per-storefront override) to put back:
     * nothing the merchant saved changes, the new settings get their defaults (all features OFF). Same method as
     * PostUpgrade130 (whose copy is private), including the M2.10 G7 signature check.
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
        $needed = ['fn_update_addon_settings', 'fn_get_addon_settings_values', 'fn_get_addon_settings_vendor_values'];
        foreach ($needed as $core) {
            if (!function_exists($core)) {
                throw new \RuntimeException('this CS-Cart has no ' . $core . '()');
            }
        }
        // A fn_update_addon_settings() with fewer than 4 parameters ignores the stored values we pass and rebuilds
        // the section from defaults: the merchant's values would be dropped. Refuse first; the run reports and
        // retries.
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
     * fn_update_addon_settings($scheme, $execute, $values, $vendor_values) must take all four parameters, or the
     * stored values are not put back.
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
