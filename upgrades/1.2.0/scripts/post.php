<?php

/*
 * FILE:      upgrades/1.2.0/scripts/post.php
 * CREATED:   2026-10-23 17:34 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Post-upgrade script of the kwtSMS 1.2.0 upgrade packages (1.1.0 -> 1.2.0 and the chained 1.0.0 -> 1.2.0):
 *            runs PostUpgrade::run() with the version the store held before (the settings of 1.2.0, missing
 *            templates and language variables, add-on version 1.2.0; from 1.0.0 the 1.1.0 content step first).
 * WHY:       The Upgrade Center runs migrations before it copies the files, so the 1.2.0 settings, language files and
 *            version can only be handled after the copy. A 1.0.0 -> 1.2.0 package cannot load the 1.1.0 upgrade
 *            files from the add-on tree, so it carries the 1.1.0 migration in content/migrations/ and this script
 *            loads that file. The add-on version is still the old one when this script starts (the 1.1.0 content
 *            step sets it itself, P2-R6), so it tells the path.
 * USAGE:     Named in content/package.json under "scripts": {"post": "post.php"}; included by the Upgrade Center.
 * DEPENDS:   Tygh\Addons\Kwtsms\Upgrade\PostUpgrade (copied with the package); for 1.0.0 the migration file
 *            ../migrations/20261022130000_kwtsms_1_1_0.php.
 */

defined('BOOTSTRAP') or die('Access denied');

$kwtsms_from = (string) fn_get_addon_version('kwtsms');
if ($kwtsms_from === '1.0.0' && !class_exists('Kwtsms110', false)) {
    require_once __DIR__ . '/../migrations/20261022130000_kwtsms_1_1_0.php';
}
\Tygh\Addons\Kwtsms\Upgrade\PostUpgrade::runReported($kwtsms_from);
