<?php

/*
 * FILE:      upgrades/1.3.0/scripts/post.php
 * CREATED:   2026-11-04 18:18 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Post-upgrade script of the kwtSMS 1.3.0 upgrade packages (1.2.0 -> 1.3.0 and the chained 1.1.0 / 1.0.0 ->
 *            1.3.0): from 1.0.0 the 1.1.0 content step first (its migration file is carried in the package), then
 *            PostUpgrade::runReported() for 1.0.0 and 1.1.0 (the 1.2.0 content), then always
 *            PostUpgrade130::runReported() (from 1.2.0 it runs the rc wording refresh first; settings, language
 *            values, add-on version 1.3.0).
 * WHY:       The Upgrade Center runs migrations before it copies the files, so the 1.3.0 settings, language files and
 *            version can only be handled after the copy. The add-on version is still the old one when this script
 *            starts, so it tells the path. PostUpgrade130 is a NEW class (P3-60): the request may have the old
 *            PostUpgrade loaded, which cannot carry 1.3.0 behaviour. Nothing here turns a feature on, writes a
 *            kwtsms_twofa row or holds a user at login (PRD 13).
 * USAGE:     Named in content/package.json under "scripts": {"post": "post.php"}; included by the Upgrade Center.
 * DEPENDS:   Tygh\Addons\Kwtsms\Upgrade\{PostUpgrade,PostUpgrade130} (copied with the package); for 1.0.0 the migration
 *            ../migrations/20261022130000_kwtsms_1_1_0.php (the package carries it).
 */

defined('BOOTSTRAP') or die('Access denied');

$kwtsms_from = (string) fn_get_addon_version('kwtsms');
if ($kwtsms_from === '1.0.0' || $kwtsms_from === '1.1.0') {
    if ($kwtsms_from === '1.0.0' && !class_exists('Kwtsms110', false)) {
        require_once __DIR__ . '/../migrations/20261022130000_kwtsms_1_1_0.php';
    }
    // The 1.2.0 content (and, from 1.0.0, the 1.1.0 content first). It writes version 1.2.0; PostUpgrade130 below
    // sees the stored version 1.2.0 and does not run the 1.2.0 step again.
    \Tygh\Addons\Kwtsms\Upgrade\PostUpgrade::runReported($kwtsms_from);
}
// All paths, including a store already on 1.2.0 (a release candidate or the final build): PostUpgrade130 runs the
// refresh of the 1.2.0 wording first for '1.2.0'.
\Tygh\Addons\Kwtsms\Upgrade\PostUpgrade130::runReported($kwtsms_from);
