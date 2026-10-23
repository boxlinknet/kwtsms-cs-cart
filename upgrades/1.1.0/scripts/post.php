<?php

/*
 * FILE:      upgrades/1.1.0/scripts/post.php
 * CREATED:   2026-10-22 13:15 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Post-upgrade script of the kwtSMS 1.1.0 upgrade package: runs Kwtsms110::content() (the setting
 *            vendor_monthly_quota, missing default templates, missing language variables, add-on version 1.1.0).
 * WHY:       The Upgrade Center runs migrations before it copies the package files, so the migration cannot read the
 *            1.1.0 addon.xml or language files; this script runs after the copy and the language install. It is
 *            idempotent and changes no edited template, switch, setting or credential and writes no
 *            notification_settings row.
 * USAGE:     Named in content/package.json under "scripts": {"post": "post.php"}; included by the Upgrade Center.
 * DEPENDS:   The migration file next to it (../migrations/20261022130000_kwtsms_1_1_0.php).
 */

defined('BOOTSTRAP') or die('Access denied');

// 2026-10-23 08:26 +0300: cleanup CA2-1 (A1 M1), task 596. Failure handling moved into
// Kwtsms110::runContent() (debug log, retry hint).
if (!class_exists('Kwtsms110', false)) {
    require_once __DIR__ . '/../migrations/20261022130000_kwtsms_1_1_0.php';
}
Kwtsms110::runContent();
