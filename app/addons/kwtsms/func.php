<?php

/*
 * FILE:      app/addons/kwtsms/func.php
 * CREATED:   2026-10-20 19:53 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Procedural functions of the kwtSMS add-on: the install and uninstall handlers named in
 *            addon.xml and the renderer of the visible "info" setting.
 * WHY:       addon.xml cannot express a random value (cron_key), a sweep over tables found at run time, or
 *            the HTML of the settings info item. Services and hooks arrive in later tasks; this file
 *            stays free of business logic.
 * USAGE:     Loaded by CS-Cart: fn_kwtsms_install() and fn_kwtsms_uninstall() by the add-on installer,
 *            fn_kwtsms_settings_info() by Admin > Add-ons > kwtSMS (settings page).
 * DEPENDS:   CS-Cart 4.18.1 to 4.21.2 core (Tygh\Settings, db_* functions, fn_url, fn_html_escape).
 */

use Tygh\Registry;
use Tygh\Settings;

defined('BOOTSTRAP') or die('Access denied');

/**
 * Install handler (addon.xml functions, for="install"). Runs after the tables, privileges and settings
 * exist.
 *
 * Generates the cron key: 32 random hex characters, new on every install, never a static default.
 * Nothing else is seeded: notification switches are fail-closed and need no rows (PRD 4.1).
 *
 * @return void
 */
function fn_kwtsms_install(): void
{
    // 2026-10-20 19:53 +03: cron_key generated here (PRD 9), task 596.
    Settings::instance(['company_id' => 0, 'storefront_id' => 0])
        ->updateValue('cron_key', bin2hex(random_bytes(16)), 'kwtsms');
}

/**
 * Uninstall handler (addon.xml functions, for="uninstall"). Runs before the queries of addon.xml and
 * before the settings and language values are removed.
 *
 * Drops every table whose name starts with "kwtsms_" in this store, including tables a later version
 * created that addon.xml of this version does not list, so a reinstall always starts clean (PRD 13).
 * The nine Phase 1 tables are also dropped by name in addon.xml; both are idempotent.
 *
 * @return void
 */
function fn_kwtsms_uninstall(): void
{
    // 2026-10-20 19:53 +03: sweep of kwtsms_ tables created by later versions (PRD 13), task 596.
    $prefix = Registry::get('config.table_prefix');

    foreach ((array) db_get_fields('SHOW TABLES') as $table) {
        if (strpos((string) $table, $prefix . 'kwtsms_') === 0) {
            db_query('DROP TABLE IF EXISTS ?p', '`' . str_replace('`', '', (string) $table) . '`');
        }
    }
}

/**
 * Renderer of the "info" setting: a button that opens the add-on's own Settings page. Every other
 * setting of the add-on is hidden and is edited there.
 *
 * @return string HTML, every dynamic part escaped
 */
function fn_kwtsms_settings_info(): string
{
    // 2026-10-20 19:53 +03: link to kwtsms.settings (PRD 9), task 596.
    $url = fn_url('kwtsms.settings');

    return '<a class="btn btn-primary" href="' . fn_html_escape($url) . '">'
        . fn_html_escape(__('kwtsms.open_settings'))
        . '</a>';
}
