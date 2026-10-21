<?php

/*
 * FILE:      app/addons/kwtsms/controllers/backend/kwtsms.php
 * CREATED:   2026-10-21 02:00 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The kwtsms admin controller: the security gate of every mode, the add-on navigation and the Settings page
 *            with its POST actions. Later page tasks (1.11-1.15) add their pages here.
 * WHY:       PRD 7 and 10. Before any work: 1 only GET and POST (anything else: 405 and the access-denied page),
 *            2 RequestGate::deny(): a mode outside the explicit map is denied, each mode answers its own method only,
 *            root administrators on All storefronts only (ruling R12), fn_check_current_user_access() with the mode's
 *            privilege, and on every POST our own hash_equals() of the session security hash (on top of the core
 *            check, so switching core anti_csrf off cannot expose these POST modes). No state changes on GET.
 * USAGE:     admin.php?dispatch=kwtsms.settings (GET); forms post dispatch[kwtsms.<mode>] (POST). Mode map, methods and
 *            privileges: Admin\RequestGate::MODES. Conventions for later pages:
 *              - add the mode's handler below; never add a mode that is not in RequestGate::MODES and
 *                schemas/permissions/admin.post.php;
 *              - a POST handler ends with a redirect (PRG) to its page; a GET handler never writes;
 *              - add the page to Admin\Navigation::PAGES and a kwtsms.nav.<mode> language variable;
 *              - wrap the work in the try block: a Throwable is logged (class, message, file:line) and the admin sees
 *                a generic error, never a trace.
 * DEPENDS:   Admin\{RequestGate,SettingsPage,Navigation}, Tygh::$app['kwtsms.*'] (ServiceProvider),
 *            design/backend/templates/addons/kwtsms/views/kwtsms/settings.tpl.
 */

use Tygh\Addons\Kwtsms\Admin\DashboardPage;
use Tygh\Addons\Kwtsms\Admin\Navigation;
use Tygh\Addons\Kwtsms\Admin\RequestGate;
use Tygh\Addons\Kwtsms\Admin\SettingsPage;
use Tygh\Addons\Kwtsms\Admin\TemplatesPage;
use Tygh\Registry;
use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

/** @var string $mode */
/** @var array $auth */

// 1. The request method, before anything else.
$kwtsms_method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '';
if ($kwtsms_method !== RequestGate::GET && $kwtsms_method !== RequestGate::POST) {
    if (!headers_sent()) {
        http_response_code(405);
        header('Allow: GET, POST');
    }

    return [CONTROLLER_STATUS_DENIED];
}

// 2. Mode map, mode method, root context, privilege, security hash.
$kwtsms_session = Tygh::$app['session'];
$kwtsms_denied = RequestGate::deny(
    $kwtsms_method,
    $mode,
    is_array($auth) ? $auth : [],
    Registry::get('runtime.company_id'),
    static function (string $privilege): bool {
        return fn_check_current_user_access($privilege) === true;
    },
    isset($kwtsms_session['security_hash']) ? $kwtsms_session['security_hash'] : null,
    isset($_POST['security_hash']) ? $_POST['security_hash'] : null
);
if ($kwtsms_denied !== null) {
    if ($kwtsms_denied === 'token') {
        // Only reachable with core anti_csrf off (core refuses a missing hash first): worth a warning row.
        Tygh::$app['kwtsms.logs.debug']->log('warning', 'admin.denied', 'token', ['mode' => (string) $mode]);
    }

    return [CONTROLLER_STATUS_DENIED];
}

$kwtsms_settings_url = 'kwtsms.settings';

// 2026-10-21 03:23 +03: the Dashboard buttons (Refresh balance, Sync now, Retry now, Resume now) post
// kwtsms_return=dashboard and come back to the Dashboard; anything else, or an administrator who may not open the
// Dashboard, goes back to Settings (fixed targets, no open redirect). Task 1.11, task 596.
$kwtsms_back_url = isset($_REQUEST['kwtsms_return']) && $_REQUEST['kwtsms_return'] === 'dashboard'
    && fn_check_current_user_access('view_kwtsms') === true ? 'kwtsms.dashboard' : $kwtsms_settings_url;

try {
    if ($kwtsms_method === RequestGate::POST) {
        $kwtsms_page = SettingsPage::fromContainer(Tygh::$app);

        switch ($mode) {
            case 'login':
                // PRD 4.4 / 10: the raw values (bootstrap strip_tags would cut a password that holds "<").
                fn_trusted_vars('kwtsms_gw_u', 'kwtsms_gw_p');
                // Only from the POST body (fn_trusted_vars would also take a query string value, which servers log).
                $kwtsms_user = isset($_POST['kwtsms_gw_u'], $_REQUEST['kwtsms_gw_u'])
                    && is_string($_REQUEST['kwtsms_gw_u']) ? $_REQUEST['kwtsms_gw_u'] : '';
                $kwtsms_pass = isset($_POST['kwtsms_gw_p'], $_REQUEST['kwtsms_gw_p'])
                    && is_string($_REQUEST['kwtsms_gw_p']) ? $_REQUEST['kwtsms_gw_p'] : '';
                unset($_POST['kwtsms_gw_p'], $_REQUEST['kwtsms_gw_p']);
                $kwtsms_ok = $kwtsms_page->login($kwtsms_user, $kwtsms_pass);
                unset($kwtsms_user, $kwtsms_pass);

                // A failed "Change account" keeps the login form open (the old account stays connected).
                return [
                    CONTROLLER_STATUS_REDIRECT,
                    $kwtsms_ok ? $kwtsms_settings_url : $kwtsms_settings_url . '?change_account=1',
                ];

            case 'logout':
                $kwtsms_page->logout();

                return [CONTROLLER_STATUS_REDIRECT, $kwtsms_settings_url];

            case 'save_settings':
                // 2026-10-21 03:23 +03: turning SMS on (or test mode on while SMS are on) raises the "test mode is on"
                // bell notice at once (PRD 6.13); the switches before and after the save decide. Task 1.11, task 596.
                $kwtsms_was_on = Tygh::$app['kwtsms.config']->enabled();
                $kwtsms_was_test = Tygh::$app['kwtsms.config']->testMode();
                $kwtsms_saved = $kwtsms_page->save(
                    isset($_REQUEST['kwtsms_settings']) ? $_REQUEST['kwtsms_settings'] : [],
                    isset($_REQUEST['kwtsms_ack_test_sender']) ? $_REQUEST['kwtsms_ack_test_sender'] : ''
                );
                // 2026-10-21 05:16 +03: the keys the form saved go along: a saved default country ticks that step
                // of the Dashboard checklist (ruling R36), SMS switched on starts the cron watch. Task 1.11 fix
                // round 1 item 3, task 596.
                DashboardPage::fromContainer(Tygh::$app)->afterSettingsSaved(
                    $kwtsms_was_on,
                    $kwtsms_was_test,
                    isset($kwtsms_saved['saved']) && is_array($kwtsms_saved['saved']) ? $kwtsms_saved['saved'] : []
                );

                return [CONTROLLER_STATUS_REDIRECT, $kwtsms_settings_url];

            case 'test_sms':
                $kwtsms_ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
                $kwtsms_result = $kwtsms_page->testSms(
                    isset($_REQUEST['kwtsms_test']) ? $_REQUEST['kwtsms_test'] : [],
                    isset($auth['user_id']) ? (int) $auth['user_id'] : 0,
                    filter_var($kwtsms_ip, FILTER_VALIDATE_IP) !== false ? $kwtsms_ip : '',
                    (string) CART_LANGUAGE
                );
                if ($kwtsms_result !== null) {
                    $kwtsms_session['kwtsms_test_sms_result'] = $kwtsms_result;
                }

                return [CONTROLLER_STATUS_REDIRECT, $kwtsms_settings_url . '#kwtsms_test_sms'];

            case 'sync':
                $kwtsms_page->syncNow();

                return [CONTROLLER_STATUS_REDIRECT, $kwtsms_back_url];

            case 'refresh_balance':
                $kwtsms_page->refreshBalance();

                return [CONTROLLER_STATUS_REDIRECT, $kwtsms_back_url];

            case 'retry_gateway':
                $kwtsms_page->retryGateway();

                return [CONTROLLER_STATUS_REDIRECT, $kwtsms_back_url];

            case 'resume_daily_cap':
                $kwtsms_page->resumeDailyCap();

                return [CONTROLLER_STATUS_REDIRECT, $kwtsms_back_url];

            // 2026-10-21 05:16 +03: ruling R36, "Mark as done" of an optional step of the Dashboard setup checklist
            // (default country, brand name, SMS switches). The gate has checked POST, manage_kwtsms and the security
            // hash; DashboardState accepts only those three step ids. Task 1.11 fix round 1 item 3, task 596.
            case 'mark_step':
                $kwtsms_step = isset($_REQUEST['kwtsms_step']) && is_string($_REQUEST['kwtsms_step'])
                    ? $_REQUEST['kwtsms_step']
                    : '';
                if (DashboardPage::fromContainer(Tygh::$app)->markStep($kwtsms_step)) {
                    fn_set_notification('N', __('notice'), (string) __('kwtsms.dash.mark.done'));
                } else {
                    fn_set_notification('E', __('error'), (string) __('kwtsms.dash.mark.invalid'));
                }

                return [CONTROLLER_STATUS_REDIRECT, $kwtsms_back_url];

            case 'regenerate_cron_key':
                $kwtsms_page->regenerateCronKey();

                return [CONTROLLER_STATUS_REDIRECT, $kwtsms_settings_url . '#kwtsms_cron'];

            // 2026-10-21 03:28 +03: the Templates page actions (Task 1.12, task 596). Admin\TemplatesPage holds the
            // rules; this only reads the posted form (the body raw, from the POST body only), calls it and redirects
            // (PRG).
            case 'update_template':
            case 'reset_template':
            case 'load_template_defaults':
            case 'toggle_event':
            case 'preview_template':
                // PRD 10: a template body is raw text ("<", "&" must reach the cleaner as typed); the other fields are
                // plain strings. Only the POST body counts: a query string is logged by servers and proxies.
                fn_trusted_vars('kwtsms_tpl.body');
                $kwtsms_tpl = [];
                $kwtsms_posted = isset($_POST['kwtsms_tpl']) && is_array($_POST['kwtsms_tpl']);
                if ($kwtsms_posted && isset($_REQUEST['kwtsms_tpl']) && is_array($_REQUEST['kwtsms_tpl'])) {
                    $kwtsms_tpl = array_intersect_key($_REQUEST['kwtsms_tpl'], $_POST['kwtsms_tpl']);
                }
                $kwtsms_templates_page = TemplatesPage::fromContainer(Tygh::$app);
                $kwtsms_templates_url = static function ($lang, $edit = '', $anchor = '') {
                    return 'kwtsms.templates' . (is_string($lang) && $lang !== '' ? '?lang=' . rawurlencode($lang) : '')
                        . ($edit !== '' ? '&edit=' . rawurlencode($edit) : '') . $anchor;
                };

                if ($mode === 'update_template') {
                    $kwtsms_result = $kwtsms_templates_page->save($kwtsms_tpl);
                    if ($kwtsms_result['draft'] !== null) {
                        $kwtsms_session['kwtsms_template_draft'] = $kwtsms_result['draft'];
                    }

                    return [
                        CONTROLLER_STATUS_REDIRECT,
                        $kwtsms_templates_url($kwtsms_result['lang'], $kwtsms_result['edit'], '#kwtsms_editor'),
                    ];
                }
                if ($mode === 'reset_template') {
                    $kwtsms_result = $kwtsms_templates_page->reset($kwtsms_tpl);

                    return [CONTROLLER_STATUS_REDIRECT, $kwtsms_templates_url($kwtsms_result['lang'])];
                }
                if ($mode === 'load_template_defaults') {
                    $kwtsms_result = $kwtsms_templates_page->loadDefaults(
                        isset($kwtsms_tpl['lang']) && is_string($kwtsms_tpl['lang']) ? $kwtsms_tpl['lang'] : ''
                    );

                    return [CONTROLLER_STATUS_REDIRECT, $kwtsms_templates_url($kwtsms_result['lang'])];
                }
                if ($mode === 'toggle_event') {
                    // PRD 7: the switch is core's own, so changing it also needs core's manage_notification_settings.
                    $kwtsms_result = $kwtsms_templates_page->toggle(
                        $kwtsms_tpl,
                        fn_check_current_user_access('manage_notification_settings') === true
                    );
                    $kwtsms_anchor = '';
                    $kwtsms_event = isset($kwtsms_tpl['event_id']) ? $kwtsms_tpl['event_id'] : null;
                    $kwtsms_receiver = isset($kwtsms_tpl['receiver']) ? $kwtsms_tpl['receiver'] : null;
                    if (is_string($kwtsms_event) && is_string($kwtsms_receiver)) {
                        $kwtsms_key = TemplatesPage::rowKey($kwtsms_event, $kwtsms_receiver);
                        $kwtsms_anchor = '#' . TemplatesPage::domId($kwtsms_key);
                    }
                    $kwtsms_to = $kwtsms_templates_url($kwtsms_result['lang'], '', $kwtsms_anchor);

                    return [CONTROLLER_STATUS_REDIRECT, $kwtsms_to];
                }

                // preview_template: JSON for the script, which shows it with textContent. Sends nothing, writes
                // nothing. Showing an order needs core's view_orders.
                $kwtsms_may_see_orders = fn_check_current_user_access('view_orders') === true;
                $kwtsms_answer = $kwtsms_templates_page->preview($kwtsms_tpl, $kwtsms_may_see_orders);
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=utf-8');
                    header('Cache-Control: no-store');
                    header('X-Content-Type-Options: nosniff');
                }
                echo json_encode(
                    $kwtsms_answer,
                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
                    | JSON_INVALID_UTF8_SUBSTITUTE
                ) ?: '{"ok":false}';
                exit;
        }

        // POST modes of later tasks (templates, alerts, integrations, logs) do nothing until they are built.
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    if ($mode === 'settings') {
        $kwtsms_page = SettingsPage::fromContainer(Tygh::$app);
        $kwtsms_change = isset($_REQUEST['change_account']) && $_REQUEST['change_account'] === '1';
        $kwtsms_data = $kwtsms_page->viewData($kwtsms_change);

        // The test SMS result is shown once, after the redirect of its POST.
        $kwtsms_data['test_result'] = null;
        if (isset($kwtsms_session['kwtsms_test_sms_result']) && is_array($kwtsms_session['kwtsms_test_sms_result'])) {
            $kwtsms_data['test_result'] = $kwtsms_session['kwtsms_test_sms_result'];
            unset($kwtsms_session['kwtsms_test_sms_result']);
        }
        // Paid statuses always count as placed (PRD 4.2), so only the others are offered as extras.
        $kwtsms_data['order_statuses'] = array_diff_key(
            (array) fn_get_simple_statuses(STATUSES_ORDER, false, true),
            array_flip(array_merge(array_map('strval', (array) fn_get_order_paid_statuses()), ['N', 'T']))
        );
        $kwtsms_data['cron_command'] = 'php ' . rtrim((string) Registry::get('config.dir.root'), '/') . '/'
            . (string) Registry::get('config.admin_index') . ' --dispatch=kwtsms_cron.run';
        $kwtsms_data['cron_url'] = fn_url('kwtsms_cron.run', 'A', 'current');

        $kwtsms_nav = Navigation::sections('settings', static function (string $privilege): bool {
            return fn_check_current_user_access($privilege) === true;
        });
        Registry::set('navigation.dynamic.sections', $kwtsms_nav);
        Registry::set('navigation.dynamic.active_section', 'settings');

        Tygh::$app['view']->assign('kwtsms', $kwtsms_data);
        Tygh::$app['view']->assign('kwtsms_nav', $kwtsms_nav);

        return [CONTROLLER_STATUS_OK];
    }

    // 2026-10-21 03:28 +03: the Templates page (Task 1.12, task 596). A GET handler never writes; a refused save
    // leaves its typed text in the session for this one redirect.
    if ($mode === 'templates') {
        $kwtsms_draft = null;
        if (isset($kwtsms_session['kwtsms_template_draft']) && is_array($kwtsms_session['kwtsms_template_draft'])) {
            $kwtsms_draft = $kwtsms_session['kwtsms_template_draft'];
            unset($kwtsms_session['kwtsms_template_draft']);
        }
        $kwtsms_data = TemplatesPage::fromContainer(Tygh::$app)->viewData(
            isset($_REQUEST['lang']) && is_string($_REQUEST['lang']) ? $_REQUEST['lang'] : '',
            isset($_REQUEST['edit']) && is_string($_REQUEST['edit']) ? $_REQUEST['edit'] : '',
            $kwtsms_draft,
            fn_check_current_user_access('manage_notification_settings') === true
        );
        $kwtsms_data['can_open_settings'] = fn_check_current_user_access('manage_kwtsms_gateway') === true;

        $kwtsms_nav = Navigation::sections('templates', static function (string $privilege): bool {
            return fn_check_current_user_access($privilege) === true;
        });
        Registry::set('navigation.dynamic.sections', $kwtsms_nav);
        Registry::set('navigation.dynamic.active_section', 'templates');

        Tygh::$app['view']->assign('kwtsms_templates', $kwtsms_data);
        Tygh::$app['view']->assign('kwtsms_nav', $kwtsms_nav);

        return [CONTROLLER_STATUS_OK];
    }

    if ($mode === 'dashboard') {
        // 2026-10-21 03:23 +03: the Dashboard (Task 1.11, task 596) replaces the temporary redirect to Settings.
        // A GET handler never writes, with ONE documented exception: PRD 6.11 says opening the Dashboard drains up to
        // 20 due outbox rows and syncs when the last sync is stale (what the cron does every minute and every day), and
        // PRD 6.13's "cron missing" and "outbox backlog" bell notices can only be raised by a page view.
        $kwtsms_has = static function (string $privilege): bool {
            return fn_check_current_user_access($privilege) === true;
        };
        $kwtsms_dashboard = DashboardPage::fromContainer(Tygh::$app);
        $kwtsms_dashboard->refresh();

        $kwtsms_nav = Navigation::sections('dashboard', $kwtsms_has);
        Registry::set('navigation.dynamic.sections', $kwtsms_nav);
        Registry::set('navigation.dynamic.active_section', 'dashboard');

        Tygh::$app['view']->assign('kwtsms', $kwtsms_dashboard->viewData($kwtsms_has));
        Tygh::$app['view']->assign('kwtsms_nav', $kwtsms_nav);

        return [CONTROLLER_STATUS_OK];
    }

    // GET pages of later tasks.
    return [CONTROLLER_STATUS_NO_PAGE];
} catch (\Throwable $e) {
    error_log('kwtsms: admin ' . (string) $mode . ' failed: ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    try {
        Tygh::$app['kwtsms.logs.debug']->log('error', 'admin.failed', get_class($e) . ': ' . $e->getMessage()
            . ' in ' . basename($e->getFile()) . ':' . $e->getLine(), ['mode' => (string) $mode]);
    } catch (\Throwable $ignored) {
        // The log is best effort here; error_log above already has the line.
    }
    fn_set_notification('E', __('error'), fn_html_escape((string) __('kwtsms.settings.failed')));

    // 2026-10-21 04:38 +03: a failed Templates action goes back to the Templates page (Task 1.12, task 596).
    $kwtsms_templates_modes = [
        'update_template', 'reset_template', 'load_template_defaults', 'toggle_event', 'preview_template',
    ];
    $kwtsms_failed_url = $kwtsms_settings_url;
    if (in_array((string) $mode, $kwtsms_templates_modes, true)) {
        $kwtsms_failed_url = 'kwtsms.templates';
    }

    return $kwtsms_method === RequestGate::POST
        ? [CONTROLLER_STATUS_REDIRECT, $kwtsms_failed_url]
        : [CONTROLLER_STATUS_NO_PAGE];
}
