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
 *            starts only when an admin turns a phone feature on. Every step is idempotent.
 *            2026-10-24 23:03 +03: M1.7b fix round 1 (review M4), task 596. The one setting it does change: the Image
 *            verification scenario kwtsms_sms_code is ticked (P3-36), in the global list and in every storefront
 *            list; a retry after a failed step ticks it again.
 *            2026-10-23 18:31 +03: M1.1b fix round 1 (I1), task 596. Uploading the package again is NOT a retry: the
 *            Upgrade Center refuses a package whose from_version differs from the addon.xml on disk, and by the time
 *            post.php runs the 1.2.0 files are on disk. So selfHeal() (called on the admin-area before_dispatch hook)
 *            runs this step again whenever the stored add-on version is behind the files.
 * USAGE:     PostUpgrade::run($from) throws on failure; PostUpgrade::runReported($from) (what post.php calls)
 *            reports instead and returns false. $from is the add-on version before the upgrade
 *            (fn_get_addon_version('kwtsms'), still the old one when post.php starts).
 *            PostUpgrade::selfHeal() does nothing when the stored version is 1.2.0 or newer, else runReported() with
 *            the stored version, at most once per request, serialized by a database lock.
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
     * 2026-10-23 18:31 +03: M1.1b fix round 1 (I1), task 596. Copy of Kwtsms110::SHIPPED_1_0_0 (the four texts 1.0.0
     * shipped and 1.1.0 changed). Used only when a store at 1.0.0 is healed without the 1.1.0 migration file (the file
     * lives in the upgrade package, not in the add-on tree); a unit test compares it with the original.
     */
    private const SHIPPED_1_0_0 = [
        'kwtsms.integrations.mve.vendor_sms' => [
            'en' => "Vendor SMS: not included in 1.0, planned for 1.1",
            'ar' => "\u{631}\u{633}\u{627}\u{626}\u{644} \u{627}\u{644}\u{628}\u{627}\u{626}\u{639}\u{64a}\u{646}: "
                . "\u{63a}\u{64a}\u{631} \u{645}\u{636}\u{645}\u{646}\u{629} \u{641}\u{64a} \u{627}\u{644}\u{625}"
                . "\u{635}\u{62f}\u{627}\u{631} 1.0\u{60c} "
                . "\u{648}\u{645}\u{62e}\u{637}\u{637} \u{644}\u{647}\u{627} \u{641}\u{64a} 1.1",
        ],
        'kwtsms.help.overview.p2' => [
            'en' => "kwtSMS sends one-way messages, so customers can't reply. "
                . "Vendor SMS is not included in version 1.0 and is planned for 1.1.",
            'ar' => "\u{64a}\u{631}\u{633}\u{644} kwtSMS \u{631}\u{633}\u{627}\u{626}\u{644} \u{628}\u{627}\u{62a}"
                . "\u{62c}\u{627}\u{647} \u{648}\u{627}\u{62d}\u{62f}\u{60c} \u{641}\u{644}\u{627} \u{64a}\u{633}"
                . "\u{62a}\u{637}\u{64a}\u{639} \u{627}\u{644}\u{639}\u{645}\u{644}\u{627}\u{621} \u{627}\u{644}"
                . "\u{631}\u{62f}. "
                . "\u{631}\u{633}\u{627}\u{626}\u{644} \u{627}\u{644}\u{628}\u{627}\u{626}\u{639}\u{64a}\u{646} "
                    . "\u{63a}\u{64a}\u{631} \u{645}\u{636}\u{645}\u{646}\u{629} \u{641}\u{64a} \u{627}\u{644}"
                    . "\u{625}\u{635}\u{62f}\u{627}\u{631} 1.0 "
                . "\u{648}\u{645}\u{62e}\u{637}\u{637} \u{644}\u{647}\u{627} \u{641}\u{64a} 1.1.",
        ],
        'kwtsms.integrations.later.help' => [
            'en' => "Available in a later version.",
            'ar' => "\u{645}\u{62a}\u{627}\u{62d}\u{629} \u{641}\u{64a} \u{625}\u{635}\u{62f}\u{627}\u{631} "
                . "\u{644}\u{627}\u{62d}\u{642}.",
        ],
        'kwtsms.integrations.later.discussion' => [
            'en' => "Reviews and comments",
            'ar' => "\u{627}\u{644}\u{62a}\u{642}\u{64a}\u{64a}\u{645}\u{627}\u{62a} "
                . "\u{648}\u{627}\u{644}\u{62a}\u{639}\u{644}\u{64a}\u{642}\u{627}\u{62a}",
        ],
    ];

    /** Name of the database lock that serializes post-upgrade runs (post.php and the self-heal). */
    private const LOCK = 'kwtsms_post_upgrade';

    /** Seconds after a heal run (success or failure) during which the same admin session does not run it again. */
    public const HEAL_BACKOFF = 300;

    /** Session key of the last heal run (2026-10-23 19:35 +03, M1.1b fix round 2, task 596). */
    private const HEAL_SESSION_KEY = 'kwtsms_heal_at';

    /** @var bool True once selfHeal() has looked at the version in this request */
    private static $healChecked = false;

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
            if (class_exists('Kwtsms110', false)) {
                // The package path: post.php loaded the 1.1.0 migration file. No notice of its own (one for the chain).
                if (!\Kwtsms110::runContent(null, false)) {
                    throw new \RuntimeException('the 1.1.0 content step failed (details in the kwtSMS debug log)');
                }
                SchemesManager::clearInternalCache(self::ADDON);
            } else {
                // 2026-10-23 18:31 +03: M1.1b fix round 1 (I1), task 596. The self-heal has no migration file.
                // Everything
                // else Kwtsms110::content() does (the vendor_monthly_quota setting, missing templates, missing
                // language values) is covered by the generic steps below; only the refresh of the four 1.0.0 texts
                // is repeated here.
                self::refreshUneditedValues((array) $scheme->getLanguageValues(false), self::SHIPPED_1_0_0);
            }
        }

        self::addMissingSettings($scheme);

        if (function_exists('fn_kwtsms_seed_default_templates')) {
            \fn_kwtsms_seed_default_templates();
        } else {
            throw new \RuntimeException('fn_kwtsms_seed_default_templates() does not exist');
        }

        self::languageValues($scheme);

        // 2026-10-24 21:13 +03: Task M1.7b (P3-36), task 596. The CAPTCHA scenario of the SMS code request is
        // ticked, so the mode is "always" by default. It adds one entry to the ticked list and changes nothing else;
        // no customer data is processed and no form of the 1.1.0 store changes (the scenario has no form yet).
        if (function_exists('fn_kwtsms_tick_sms_code_scenario')) {
            if (!\fn_kwtsms_tick_sms_code_scenario()) {
                throw new \RuntimeException('the image verification scenario kwtsms_sms_code was not ticked');
            }
        } else {
            throw new \RuntimeException('fn_kwtsms_tick_sms_code_scenario() does not exist');
        }

        fn_update_addon_version(self::ADDON, self::VERSION);
    }

    /**
     * run() as the post script calls it: a failure is reported (PHP error log, the add-on's debug log, an admin
     * notice that names the retry), never thrown, because the Upgrade Center would show a raw error.
     *
     * @param string        $from_version See run()
     * @param callable|null $step         Replaces run() (a test injects a failing step)
     *
     * @return bool True when every step finished (or another request holds the lock and is running them)
     */
    public static function runReported(string $from_version, ?callable $step = null): bool
    {
        // 2026-10-23 17:37 +03: Task M1.1b, task 596. $step lets a test prove the failure path (like
        // Kwtsms110::runContent).
        // 2026-10-23 18:31 +03: M1.1b fix round 1 (I1), task 596. One run at a time: post.php and a self-heal from an
        // admin request can overlap (the Upgrade Center takes several requests); the second one leaves the work to the
        // first (non-blocking lock).
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
            // 2026-10-23 17:41 +03: Task M1.1b, task 596. The notice is escaped like every notice (package gate).
            fn_set_notification(
                'E',
                __('error'),
                fn_html_escape(
                    'kwtsms ' . self::VERSION . ': the post-upgrade step failed (' . get_class($e) . '). Open any '
                    . 'kwtSMS admin page to finish the upgrade: the step runs again by itself and is safe to repeat. '
                    . 'Details are in the kwtSMS debug log and the PHP error log.'
                )
            );

            return false;
        } finally {
            if ($locked) {
                db_get_field('SELECT RELEASE_LOCK(?s)', self::LOCK);
            }
        }
    }

    /**
     * The retry. The add-on files are 1.2.0 (this class is one of them); when the stored add-on version is behind,
     * a post-upgrade step failed or has not run, so it runs again with the stored version as $from. Idempotent;
     * once per request; nothing when the version is current (one indexed query).
     * 2026-10-23 19:35 +03: M1.1b fix round 2 (N1), task 596. Only a store administrator (session auth user_type 'A'
     * with a user id) can trigger it: the hook fires before core's login check, so an anonymous visitor of the admin
     * login page and a vendor user (user_type 'V') must not. After a run, success or failure, the session is stamped
     * and the heal is skipped for HEAL_BACKOFF seconds, so a failure that persists runs and notifies once per admin
     * session every five minutes. No storage key: the stamp lives in the session.
     *
     * @param bool                    $once  False lets a test call it repeatedly in one process
     * @param array<string,mixed>|null $auth Session auth (null: the current session's)
     * @param int|null                $now   Unix time (null: time())
     *
     * @return bool True when it ran the upgrade step
     */
    public static function selfHeal(bool $once = true, ?array $auth = null, ?int $now = null): bool
    {
        // 2026-10-23 18:31 +03: M1.1b fix round 1 (I1), task 596.
        if ($once && self::$healChecked) {
            return false;
        }
        $auth = $auth ?? (array) (\Tygh::$app['session']['auth'] ?? []);
        if (($auth['user_type'] ?? '') !== 'A' || empty($auth['user_id'])) {
            return false;
        }
        $now = $now ?? time();
        $stamp = (int) (\Tygh::$app['session'][self::HEAL_SESSION_KEY] ?? 0);
        if ($stamp > 0 && $now - $stamp < self::HEAL_BACKOFF) {
            return false;
        }
        self::$healChecked = true;
        $from = (string) fn_get_addon_version(self::ADDON);
        if ($from === '' || version_compare($from, self::VERSION, '>=')) {
            return false;
        }
        self::runReported($from);
        \Tygh::$app['session'][self::HEAL_SESSION_KEY] = $now;

        return true;
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
