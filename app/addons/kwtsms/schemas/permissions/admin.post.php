<?php

/*
 * FILE:      app/addons/kwtsms/schemas/permissions/admin.post.php
 * CREATED:   2026-10-20 19:55 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Privilege map of the kwtsms admin controller: a single-string controller permission
 *            (view_kwtsms) and one privilege per mode, as PRD 7 lists them.
 * WHY:       Core checks the controller permission for modes without an entry and the mode permission
 *            first when one exists. A string (never a GET/POST array) is used on purpose: core skips the
 *            check for HTTP methods missing from a method array, so PUT/HEAD/odd verbs would pass.
 *              view_kwtsms            Dashboard, Help
 *              view_kwtsms_logs       Logs, log details
 *              manage_kwtsms          Templates, Admin SMS Alerts, Integrations (+ their POST modes)
 *              manage_kwtsms_gateway  Settings page and everything gateway related: login, logout,
 *                                     test SMS, sync, balance, caps, cron key, clear, export, resend
 * USAGE:     Loaded by CS-Cart (fn_get_schema('permissions', 'admin')). The controller itself arrives in
 *            Task 1.10 and re-checks the privilege explicitly in every mode.
 * DEPENDS:   Privileges created by addon.xml (view_kwtsms, view_kwtsms_logs, manage_kwtsms,
 *            manage_kwtsms_gateway).
 */

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */
$schema['kwtsms'] = [
    'permissions' => 'view_kwtsms',
    'modes'       => [
        // GET modes
        'dashboard'              => ['permissions' => 'view_kwtsms'],
        'help'                   => ['permissions' => 'view_kwtsms'],
        'logs'                   => ['permissions' => 'view_kwtsms_logs'],
        'log_details'            => ['permissions' => 'view_kwtsms_logs'],
        'templates'              => ['permissions' => 'manage_kwtsms'],
        'integrations'           => ['permissions' => 'manage_kwtsms'],
        'admin_alerts'           => ['permissions' => 'manage_kwtsms'],
        'settings'               => ['permissions' => 'manage_kwtsms_gateway'],
        'export_logs'            => ['permissions' => 'manage_kwtsms_gateway'],

        // POST modes
        'login'                  => ['permissions' => 'manage_kwtsms_gateway'],
        'logout'                 => ['permissions' => 'manage_kwtsms_gateway'],
        'save_settings'          => ['permissions' => 'manage_kwtsms_gateway'],
        'test_sms'               => ['permissions' => 'manage_kwtsms_gateway'],
        'sync'                   => ['permissions' => 'manage_kwtsms_gateway'],
        'refresh_balance'        => ['permissions' => 'manage_kwtsms_gateway'],
        'retry_gateway'          => ['permissions' => 'manage_kwtsms_gateway'],
        'resume_daily_cap'       => ['permissions' => 'manage_kwtsms_gateway'],
        'regenerate_cron_key'    => ['permissions' => 'manage_kwtsms_gateway'],
        'clear_logs'             => ['permissions' => 'manage_kwtsms_gateway'],
        'resend'                 => ['permissions' => 'manage_kwtsms_gateway'],
        'update_template'        => ['permissions' => 'manage_kwtsms'],
        'reset_template'         => ['permissions' => 'manage_kwtsms'],
        'load_template_defaults' => ['permissions' => 'manage_kwtsms'],
        'toggle_event'           => ['permissions' => 'manage_kwtsms'],
        // 2026-10-21 03:27 +03: the Templates page preview (POST, sends nothing), Task 1.12, task 596.
        'preview_template'       => ['permissions' => 'manage_kwtsms'],
        'update_admin_alerts'    => ['permissions' => 'manage_kwtsms'],
        'update_integrations'    => ['permissions' => 'manage_kwtsms'],
    ],
];

return $schema;
