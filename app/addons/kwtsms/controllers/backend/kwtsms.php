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

use Tygh\Addons\Kwtsms\Admin\Navigation;
use Tygh\Addons\Kwtsms\Admin\RequestGate;
use Tygh\Addons\Kwtsms\Admin\SettingsPage;
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
                $kwtsms_page->save(
                    isset($_REQUEST['kwtsms_settings']) ? $_REQUEST['kwtsms_settings'] : [],
                    isset($_REQUEST['kwtsms_ack_test_sender']) ? $_REQUEST['kwtsms_ack_test_sender'] : ''
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

                return [CONTROLLER_STATUS_REDIRECT, $kwtsms_settings_url];

            case 'refresh_balance':
                $kwtsms_page->refreshBalance();

                return [CONTROLLER_STATUS_REDIRECT, $kwtsms_settings_url];

            case 'retry_gateway':
                $kwtsms_page->retryGateway();

                return [CONTROLLER_STATUS_REDIRECT, $kwtsms_settings_url];

            case 'resume_daily_cap':
                $kwtsms_page->resumeDailyCap();

                return [CONTROLLER_STATUS_REDIRECT, $kwtsms_settings_url];

            case 'regenerate_cron_key':
                $kwtsms_page->regenerateCronKey();

                return [CONTROLLER_STATUS_REDIRECT, $kwtsms_settings_url . '#kwtsms_cron'];
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

    if ($mode === 'dashboard') {
        // Until the dashboard page exists (Task 1.11) the menu item opens Settings for those who may see it.
        if (fn_check_current_user_access('manage_kwtsms_gateway') === true) {
            return [CONTROLLER_STATUS_REDIRECT, $kwtsms_settings_url];
        }

        return [CONTROLLER_STATUS_NO_PAGE];
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

    return $kwtsms_method === RequestGate::POST
        ? [CONTROLLER_STATUS_REDIRECT, $kwtsms_settings_url]
        : [CONTROLLER_STATUS_NO_PAGE];
}
