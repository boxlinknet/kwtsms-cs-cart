<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Upgrade/PostRefresh.php
 * CREATED:   2026-11-04 14:44 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The content step of the REFRESH package (a store on 1.2.0 release candidate N takes the later 1.2.0 build;
 *            tools/build-upgrade-package.sh --from 1.2.0 --to 1.2.0 --from-ref v1.2.0-rc.1), run by
 *            upgrades/1.2.0/scripts/post.php when the stored add-on version is already 1.2.0. It does only this: (1) a
 *            language variable whose stored value still equals the text the release candidate shipped gets the new
 *            wording (an edited one stays), (2) missing language variables are added (INSERT IGNORE), the originals
 *            are refreshed. It writes no setting, switch, template, credential, scenario tick or version, and it runs
 *            no migration.
 * WHY:       Task RC2-refresh. The Upgrade Center request has the OLD kwtsms classes loaded (func.php calls
 *            PostUpgrade::selfHeal() on the admin before_dispatch hook, which autoloads the old PostUpgrade), so a new
 *            version of PostUpgrade cannot take effect in that request, and the old one ticks the SMS code CAPTCHA
 *            scenario again. This is a NEW class name, autoloaded from the new files the package copied, so it is the
 *            code of the new build. Idempotent; a second run changes nothing.
 * USAGE:     PostRefresh::runReported() (what post.php calls) reports a failure and returns false;
 *            PostRefresh::run() throws.
 * DEPENDS:   SchemesManager and the add-on files of the new build on disk.
 */

namespace Tygh\Addons\Kwtsms\Upgrade;

use Tygh\Addons\SchemesManager;

class PostRefresh
{
    private const ADDON = 'kwtsms';
    private const VERSION = '1.2.0';

    /** Name of the database lock that serializes refresh runs. */
    private const LOCK = 'kwtsms_post_refresh';

    /**
     * The two language variables whose text 1.2.0-rc.2 reworded after 1.2.0-rc.1 (the code cap now counts only this
     * add-on's code SMS), with the text rc.1 shipped, per language. ASCII only (\u{} escapes).
     */
    private const SHIPPED_RC1 = [
        'kwtsms.settings.cap_code_day_help' => [
            'en' => "Most code SMS (verification codes, phone-change codes and notices) the store sends per"
                . " day, counted from midnight. Above it, new codes are refused until tomorrow and you get"
                . " an alert. Order SMS keep their own limit, so a flood of code requests cannot use it up."
                . " Default 300.",
            'ar' => "\u{623}\u{642}\u{635}\u{649} \u{639}\u{62f}\u{62f} \u{645}\u{646}"
                . " \u{631}\u{633}\u{627}\u{626}\u{644} \u{627}\u{644}\u{631}\u{645}\u{648}\u{632}"
                . " (\u{631}\u{645}\u{648}\u{632} \u{627}\u{644}\u{62a}\u{62d}\u{642}\u{642}"
                . " \u{648}\u{631}\u{645}\u{648}\u{632}"
                . " \u{648}\u{625}\u{634}\u{639}\u{627}\u{631}\u{627}\u{62a}"
                . " \u{62a}\u{63a}\u{64a}\u{64a}\u{631} \u{627}\u{644}\u{631}\u{642}\u{645})"
                . " \u{64a}\u{631}\u{633}\u{644}\u{647} \u{627}\u{644}\u{645}\u{62a}\u{62c}\u{631}"
                . " \u{641}\u{64a} \u{627}\u{644}\u{64a}\u{648}\u{645}\u{60c}"
                . " \u{64a}\u{64f}\u{62d}\u{62a}\u{633}\u{628} \u{645}\u{646}"
                . " \u{645}\u{646}\u{62a}\u{635}\u{641} \u{627}\u{644}\u{644}\u{64a}\u{644}."
                . " \u{628}\u{639}\u{62f} \u{647}\u{630}\u{627} \u{627}\u{644}\u{62d}\u{62f}"
                . " \u{62a}\u{64f}\u{631}\u{641}\u{636} \u{627}\u{644}\u{631}\u{645}\u{648}\u{632}"
                . " \u{627}\u{644}\u{62c}\u{62f}\u{64a}\u{62f}\u{629} \u{62d}\u{62a}\u{649}"
                . " \u{627}\u{644}\u{63a}\u{62f} \u{648}\u{64a}\u{635}\u{644}\u{643}"
                . " \u{62a}\u{646}\u{628}\u{64a}\u{647}. \u{62a}\u{62d}\u{62a}\u{641}\u{638}"
                . " \u{631}\u{633}\u{627}\u{626}\u{644} \u{627}\u{644}\u{637}\u{644}\u{628}\u{627}\u{62a}"
                . " \u{628}\u{62d}\u{62f}\u{647}\u{627} \u{627}\u{644}\u{62e}\u{627}\u{635}\u{60c}"
                . " \u{641}\u{644}\u{627} \u{64a}\u{633}\u{62a}\u{637}\u{64a}\u{639} \u{633}\u{64a}\u{644}"
                . " \u{645}\u{646} \u{637}\u{644}\u{628}\u{627}\u{62a}"
                . " \u{627}\u{644}\u{631}\u{645}\u{648}\u{632}"
                . " \u{627}\u{633}\u{62a}\u{647}\u{644}\u{627}\u{643}\u{647}."
                . " \u{627}\u{644}\u{627}\u{641}\u{62a}\u{631}\u{627}\u{636}\u{64a} 300.",
        ],
        'kwtsms.help.limits.p4' => [
            'en' => "Code SMS also have their own daily cap for the whole store (default 300, in Settings)."
                . " When it is reached, new codes are refused until the next day and you get an alert, and"
                . " order SMS keep their own cap. A code request refused by a limit never goes through"
                . " unverified: the setting \"When SMS codes can't be sent\" applies only when sending"
                . " itself is off-line, never to a limit.",
            'ar' => "\u{644}\u{631}\u{633}\u{627}\u{626}\u{644} \u{627}\u{644}\u{631}\u{645}\u{648}\u{632}"
                . " \u{623}\u{64a}\u{636}\u{627} \u{62d}\u{62f} \u{64a}\u{648}\u{645}\u{64a}"
                . " \u{62e}\u{627}\u{635} \u{644}\u{644}\u{645}\u{62a}\u{62c}\u{631} \u{643}\u{644}\u{647}"
                . " (\u{627}\u{644}\u{627}\u{641}\u{62a}\u{631}\u{627}\u{636}\u{64a} 300\u{60c}"
                . " \u{641}\u{64a} \u{627}\u{644}\u{625}\u{639}\u{62f}\u{627}\u{62f}\u{627}\u{62a})."
                . " \u{639}\u{646}\u{62f} \u{628}\u{644}\u{648}\u{63a}\u{647}"
                . " \u{62a}\u{64f}\u{631}\u{641}\u{636} \u{627}\u{644}\u{631}\u{645}\u{648}\u{632}"
                . " \u{627}\u{644}\u{62c}\u{62f}\u{64a}\u{62f}\u{629} \u{62d}\u{62a}\u{649}"
                . " \u{627}\u{644}\u{64a}\u{648}\u{645} \u{627}\u{644}\u{62a}\u{627}\u{644}\u{64a}"
                . " \u{648}\u{64a}\u{635}\u{644}\u{643} \u{62a}\u{646}\u{628}\u{64a}\u{647}\u{60c}"
                . " \u{648}\u{62a}\u{62d}\u{62a}\u{641}\u{638} \u{631}\u{633}\u{627}\u{626}\u{644}"
                . " \u{627}\u{644}\u{637}\u{644}\u{628}\u{627}\u{62a} \u{628}\u{62d}\u{62f}\u{647}\u{627}"
                . " \u{627}\u{644}\u{62e}\u{627}\u{635}. \u{637}\u{644}\u{628}"
                . " \u{627}\u{644}\u{631}\u{645}\u{632} \u{627}\u{644}\u{645}\u{631}\u{641}\u{648}\u{636}"
                . " \u{628}\u{633}\u{628}\u{628} \u{62d}\u{62f} \u{644}\u{627} \u{64a}\u{645}\u{631}"
                . " \u{628}\u{62f}\u{648}\u{646} \u{62a}\u{62d}\u{642}\u{642}: \u{62e}\u{64a}\u{627}\u{631}"
                . " \"\u{639}\u{646}\u{62f} \u{62a}\u{639}\u{630}\u{631}"
                . " \u{625}\u{631}\u{633}\u{627}\u{644} \u{631}\u{645}\u{648}\u{632}"
                . " \u{627}\u{644}\u{62a}\u{62d}\u{642}\u{642}\" \u{64a}\u{646}\u{637}\u{628}\u{642}"
                . " \u{641}\u{642}\u{637} \u{639}\u{646}\u{62f}\u{645}\u{627}"
                . " \u{64a}\u{62a}\u{639}\u{630}\u{631} \u{627}\u{644}\u{625}\u{631}\u{633}\u{627}\u{644}"
                . " \u{646}\u{641}\u{633}\u{647}\u{60c} \u{648}\u{644}\u{64a}\u{633}"
                . " \u{628}\u{633}\u{628}\u{628} \u{62d}\u{62f}.",
        ],
    ];

    /**
     * @return void
     *
     * @throws \Throwable When a step fails
     */
    public static function run(): void
    {
        SchemesManager::clearInternalCache(self::ADDON);
        $scheme = SchemesManager::getScheme(self::ADDON);
        if ($scheme === null || version_compare((string) $scheme->getVersion(), self::VERSION, '<')) {
            throw new \RuntimeException('the add-on files on disk are older than ' . self::VERSION);
        }
        self::refreshUneditedValues((array) $scheme->getLanguageValues(false), self::SHIPPED_RC1);
        foreach (array_chunk((array) $scheme->getLanguageValues(false), 500) as $chunk) {
            db_query('INSERT IGNORE INTO ?:language_values ?m', $chunk);
        }
        foreach (array_chunk((array) $scheme->getLanguageValues(true), 500) as $chunk) {
            db_query('REPLACE INTO ?:original_values ?m', $chunk);
        }
    }

    /**
     * Same reporting as PostUpgrade::runReported(): never throws into the Upgrade Center, one red notice.
     *
     * @return bool True when the step finished (or another request holds the lock and is running it)
     */
    public static function runReported(): bool
    {
        if ((int) db_get_field('SELECT GET_LOCK(?s, 0)', self::LOCK) !== 1) {
            return true;
        }
        try {
            self::run();

            return true;
        } catch (\Throwable $e) {
            $message = get_class($e) . ': ' . $e->getMessage();
            error_log('kwtsms: upgrade.content_failed: ' . $message);
            if (function_exists('fn_set_notification') && function_exists('fn_html_escape')) {
                fn_set_notification(
                    'E',
                    __('error'),
                    fn_html_escape(
                        'kwtsms ' . self::VERSION . ': the post-upgrade step failed (' . get_class($e) . '). Upload '
                        . 'the refresh package again; the step is safe to repeat. Details are in the PHP error log.'
                    )
                );
            }

            return false;
        } finally {
            db_get_field('SELECT RELEASE_LOCK(?s)', self::LOCK);
        }
    }

    /**
     * Updates a language variable only where the stored value still equals the text the release candidate shipped
     * (an edited value stays). Idempotent.
     *
     * @param array<int,array{lang_code:string,name:string,value:string}> $rows    The values of the new build
     * @param array<string,array<string,string>>                           $shipped name => lang => rc text
     *
     * @return int Number of values updated
     */
    public static function refreshUneditedValues(array $rows, array $shipped): int
    {
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
