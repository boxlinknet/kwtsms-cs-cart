<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/Navigation.php
 * CREATED:   2026-10-21 01:59 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The navigation between the kwtSMS admin pages: one entry per page that exists, in PRD 7 order, each shown
 *            only to an administrator holding that page's privilege.
 * WHY:       PRD 7. The controller puts the sections into navigation.dynamic.sections (the core sidebar) and the page
 *            template shows the same list as tabs above the content (views/kwtsms/components/navigation.tpl), because
 *            the core sidebar is collapsed by default on the 4.21 backend.
 * USAGE:     Navigation::sections('settings', 'fn_check_current_user_access')
 *              -> ['settings' => ['title' => 'Settings', 'href' => 'kwtsms.settings', 'active' => true], ...]
 *            A page task adds its mode to PAGES when its page is built (1.11 dashboard, 1.12 templates, 1.13
 *            integrations and admin_alerts, 1.14 logs, 1.15 help), with a kwtsms.nav.<mode> language variable.
 * DEPENDS:   Admin\RequestGate::MODES (the privilege of each page); CS-Cart __().
 */

namespace Tygh\Addons\Kwtsms\Admin;

final class Navigation
{
    /**
     * Pages that exist, in PRD 7 order. Task 1.10 builds Settings; later tasks add theirs.
     *
     * @var string[]
     */
    // 2026-10-21 04:38 +03: Templates added (PRD 7 order: Settings, then Templates), Task 1.12, task 596.
    // 2026-10-21 03:24 +03: dashboard first (PRD 7 order), Task 1.11, task 596.
    // 2026-10-21 04:20 +03: Integrations and Admin SMS Alerts added after Templates (PRD 7 order), Task 1.13, task 596.
    // 2026-10-21 05:55 +03: Logs added after Admin SMS Alerts (PRD 7 order), Task 1.14, task 596.
    // 2026-10-21 06:07 +03: Help added last (PRD 7 order), Task 1.15, task 596.
    // 2026-10-22 22:22 +03: vendors added after Admin SMS Alerts (Multi-Vendor only, see sections()), Task A8, task
    // 596.
    public const PAGES = [
        'dashboard', 'settings', 'templates', 'integrations', 'admin_alerts', 'vendors', 'logs', 'help',
    ];

    /**
     * Pages that exist in Multi-Vendor only (2026-10-22 22:22 +03: Task A8, task 596).
     *
     * @var string[]
     */
    public const MULTIVENDOR_ONLY = ['vendors'];

    /**
     * @param callable $has_privilege (string $privilege): bool
     *
     * @return array<string, array{title: string, href: string, active: bool}>
     */
    public static function sections(string $active, callable $has_privilege, ?bool $multivendor = null): array
    {
        // 2026-10-22 22:22 +03: Task A8, task 596. The Vendor SMS tab exists in Multi-Vendor only (null = ask the
        // store).
        $multivendor = $multivendor ?? (function_exists('fn_allowed_for') && fn_allowed_for('MULTIVENDOR'));
        $sections = [];
        foreach (self::PAGES as $mode) {
            if (!isset(RequestGate::MODES[$mode])) {
                continue;
            }
            if (!$multivendor && in_array($mode, self::MULTIVENDOR_ONLY, true)) {
                continue;
            }
            if (call_user_func($has_privilege, RequestGate::MODES[$mode][1]) !== true) {
                continue;
            }
            $sections[$mode] = [
                'title' => (string) __('kwtsms.nav.' . $mode),
                'href' => 'kwtsms.' . $mode,
                'active' => $mode === $active,
            ];
        }

        return $sections;
    }
}
