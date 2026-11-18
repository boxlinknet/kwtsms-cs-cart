<?php

/*
 * FILE:      upgrades/1.4.0/scripts/post.php
 * CREATED:   2026-11-12 02:02 +03
 * TASK:      596 M3.0
 * AUTHOR:    kwtSMS
 * WHAT:      Post-upgrade script of the kwtSMS 1.4.0 upgrade packages (1.3.0 -> 1.4.0 and the chained 1.2.0 / 1.1.0 /
 *            1.0.0 -> 1.4.0): from 1.0.0 the 1.1.0 migration file first (the package carries it); from 1.0.0 and
 *            1.1.0 PostUpgrade::runReported() (the 1.2.0 content); from 1.0.0, 1.1.0 and 1.2.0
 *            PostUpgrade130::runReported() (the 1.3.0 content, and from 1.2.0 the rc wording refresh); always
 *            PostUpgrade140::runReported() (settings, templates, language values, add-on version 1.4.0 last).
 * WHY:       The Upgrade Center runs migrations before it copies the files, so the 1.4.0 settings, language files and
 *            version can only be handled after the copy. The add-on version is still the old one when this script
 *            starts, so it tells the path. PostUpgrade140 is a NEW class (P3-80): the request may have the old
 *            PostUpgrade and PostUpgrade130 loaded, which cannot carry 1.4.0 behaviour. Nothing here turns a feature
 *            on or writes a switch, condition or vendor sender row (PRD 13).
 * USAGE:     Named in content/package.json under "scripts": {"post": "post.php"}; included by the Upgrade Center.
 * DEPENDS:   Tygh\Addons\Kwtsms\Upgrade\{PostUpgrade,PostUpgrade130,PostUpgrade140} (copied with the package); for
 *            1.0.0 the migration ../migrations/20261022130000_kwtsms_1_1_0.php (the package carries it).
 */

defined('BOOTSTRAP') or die('Access denied');

// The stored version through the core function when this CS-Cart has it, else a direct read of ?:addons (as the 1.3.0
// post script does, M2.10 G8): an undefined function would stop the step before PostUpgrade140 runs.
$kwtsms_from = function_exists('fn_get_addon_version')
    ? (string) fn_get_addon_version('kwtsms')
    : (string) db_get_field('SELECT version FROM ?:addons WHERE addon = ?s', 'kwtsms');
if ($kwtsms_from === '1.0.0' || $kwtsms_from === '1.1.0') {
    if ($kwtsms_from === '1.0.0' && !class_exists('Kwtsms110', false)) {
        require_once __DIR__ . '/../migrations/20261022130000_kwtsms_1_1_0.php';
    }
    // The 1.2.0 content (and, from 1.0.0, the 1.1.0 content first). It writes version 1.2.0.
    \Tygh\Addons\Kwtsms\Upgrade\PostUpgrade::runReported($kwtsms_from);
}
if ($kwtsms_from === '1.0.0' || $kwtsms_from === '1.1.0' || $kwtsms_from === '1.2.0') {
    // The 1.3.0 content (from 1.2.0 with the rc wording refresh first). It writes version 1.3.0; it sees a stored
    // 1.2.0 after the step above and does not repeat it.
    // 2026-11-17 15:14 +03: review M2, task 596. A failed 1.3.0 step is reported once: PostUpgrade140 would run it
    // again as
    // its step (1) and fail the same way (two notices, two debug rows). The self-heal retries both later.
    $kwtsms_130_ok = \Tygh\Addons\Kwtsms\Upgrade\PostUpgrade130::runReported($kwtsms_from);
}
// All paths, including a store already on 1.3.0 or 1.4.0 (a repeat repairs missing templates and settings).
if (!isset($kwtsms_130_ok) || $kwtsms_130_ok !== false) {
    \Tygh\Addons\Kwtsms\Upgrade\PostUpgrade140::runReported($kwtsms_from);
}
