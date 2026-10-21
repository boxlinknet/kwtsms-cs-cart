<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/CronLine.php
 * CREATED:   2026-10-21 07:01 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The one place that builds the shell command of the kwtsms cron job: php <root>/<admin script>
 *            --dispatch=kwtsms_cron.run, with a path that holds an unusual character quoted for the shell, and the
 *            quoting of a URL for the URL-mode line.
 * WHY:       Tasks 1.13 and 1.15 fix round 1. The Settings page and the Help page both show the crontab line of this
 *            store and built it separately, so a path with a space was quoted on Help and not on Settings. Both pages
 *            now call this helper, so the line is the same on both.
 * USAGE:     CronLine::command('/var/www/shop', 'admin.php')
 *              // php /var/www/shop/admin.php --dispatch=kwtsms_cron.run
 *            CronLine::command('/var/www/my shop/', '')         // php '/var/www/my shop/admin.php' --dispatch=...
 *            CronLine::quote("it's")                            // 'it'\''s'
 * DEPENDS:   Nothing (pure functions, no CS-Cart calls).
 */

namespace Tygh\Addons\Kwtsms\Admin;

final class CronLine
{
    /** What the cron controller is called by. */
    public const DISPATCH = 'kwtsms_cron.run';

    /** The admin script when config.admin_index is empty. */
    public const DEFAULT_SCRIPT = 'admin.php';

    /**
     * The cron command (without the five time fields) for a store root and its admin script.
     *
     * @param string $root         config.dir.root (a trailing slash is dropped)
     * @param string $admin_script config.admin_index (admin.php unless renamed; empty falls back to admin.php)
     */
    public static function command(string $root, string $admin_script): string
    {
        $script = trim($admin_script);
        $script = $script === '' ? self::DEFAULT_SCRIPT : $script;

        return 'php ' . self::shellPath(rtrim($root, '/') . '/' . $script) . ' --dispatch=' . self::DISPATCH;
    }

    /** A path as one shell word: left alone when it holds only safe characters, else single-quoted. */
    public static function shellPath(string $path): string
    {
        return preg_match('~^[A-Za-z0-9_./:@%+=,-]+$~', $path) === 1 ? $path : self::quote($path);
    }

    /** Text as one single-quoted shell word. */
    public static function quote(string $text): string
    {
        return "'" . str_replace("'", "'\\''", $text) . "'";
    }
}
