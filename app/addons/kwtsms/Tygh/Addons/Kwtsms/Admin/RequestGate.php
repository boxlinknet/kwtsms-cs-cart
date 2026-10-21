<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/RequestGate.php
 * CREATED:   2026-10-21 01:53 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The security gate of the kwtsms admin controller: the explicit map of every mode (its one HTTP method and
 *            its one privilege) and deny(), which the controller runs before any other work.
 * WHY:       PRD 7 and 10. Core skips its permission check for HTTP methods missing from a permissions array and falls
 *            back to the controller permission for a mode it does not list, so the controller checks for itself:
 *            1 only GET and POST (anything else is refused before any work), 2 a mode outside the map is refused (no
 *            fallback to view_kwtsms), 3 each mode answers its own method only (a POST mode never runs on GET), 4 root
 *            administrators on All storefronts only (ruling R12: auth company 0 AND runtime company 0, which also
 *            covers a single-storefront Ultimate where core skips the vendor_ultimate schema), 5 the mode privilege,
 *            asked explicitly, 6 on POST our own hash_equals() of the session security hash, so a disabled core
 *            anti_csrf switch cannot expose our POST modes.
 * USAGE:     $why = RequestGate::deny($_SERVER['REQUEST_METHOD'], $mode, $auth, Registry::get('runtime.company_id'),
 *                'fn_check_current_user_access', Tygh::$app['session']['security_hash'] ?? null,
 *                $_POST['security_hash'] ?? null);
 *            null = allowed; else method | mode | mode_method | scope | privilege | token.
 *            Later page tasks (1.11-1.15) add no mode here without adding it to schemas/permissions/admin.post.php
 *            with the same privilege (a unit test compares the two).
 * DEPENDS:   Nothing (pure; the privilege check is passed in).
 */

namespace Tygh\Addons\Kwtsms\Admin;

final class RequestGate
{
    public const GET = 'GET';
    public const POST = 'POST';

    /**
     * Every mode of the controller: [method, privilege] (PRD 7). The privileges equal the permission schema.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const MODES = [
        // GET pages
        'dashboard'              => [self::GET, 'view_kwtsms'],
        'help'                   => [self::GET, 'view_kwtsms'],
        'logs'                   => [self::GET, 'view_kwtsms_logs'],
        'log_details'            => [self::GET, 'view_kwtsms_logs'],
        'templates'              => [self::GET, 'manage_kwtsms'],
        'integrations'           => [self::GET, 'manage_kwtsms'],
        'admin_alerts'           => [self::GET, 'manage_kwtsms'],
        'settings'               => [self::GET, 'manage_kwtsms_gateway'],
        'export_logs'            => [self::GET, 'manage_kwtsms_gateway'],
        // POST actions
        'login'                  => [self::POST, 'manage_kwtsms_gateway'],
        'logout'                 => [self::POST, 'manage_kwtsms_gateway'],
        'save_settings'          => [self::POST, 'manage_kwtsms_gateway'],
        'test_sms'               => [self::POST, 'manage_kwtsms_gateway'],
        'sync'                   => [self::POST, 'manage_kwtsms_gateway'],
        'refresh_balance'        => [self::POST, 'manage_kwtsms_gateway'],
        'retry_gateway'          => [self::POST, 'manage_kwtsms_gateway'],
        'resume_daily_cap'       => [self::POST, 'manage_kwtsms_gateway'],
        'regenerate_cron_key'    => [self::POST, 'manage_kwtsms_gateway'],
        'clear_logs'             => [self::POST, 'manage_kwtsms_gateway'],
        'resend'                 => [self::POST, 'manage_kwtsms_gateway'],
        'update_template'        => [self::POST, 'manage_kwtsms'],
        'reset_template'         => [self::POST, 'manage_kwtsms'],
        'load_template_defaults' => [self::POST, 'manage_kwtsms'],
        'toggle_event'           => [self::POST, 'manage_kwtsms'],
        'update_admin_alerts'    => [self::POST, 'manage_kwtsms'],
        'update_integrations'    => [self::POST, 'manage_kwtsms'],
    ];

    /**
     * Why the request must be refused, or null when it may run. The checks run in this order and the first failure
     * answers: method, mode, mode_method, scope, privilege, token.
     *
     * @param mixed    $method          $_SERVER['REQUEST_METHOD'] (compared exactly: GET or POST)
     * @param mixed    $mode            The dispatch mode
     * @param array    $auth            Tygh::$app['session']['auth']
     * @param mixed    $runtime_company Registry::get('runtime.company_id')
     * @param callable $has_privilege   (string $privilege): bool, normally fn_check_current_user_access
     * @param mixed    $session_hash    The session security hash
     * @param mixed    $posted_hash     The security hash of the POST body
     *
     * @return string|null
     */
    public static function deny(
        $method,
        $mode,
        array $auth,
        $runtime_company,
        callable $has_privilege,
        $session_hash,
        $posted_hash
    ): ?string {
        if ($method !== self::GET && $method !== self::POST) {
            return 'method';
        }
        if (!is_string($mode) || !isset(self::MODES[$mode])) {
            return 'mode';
        }
        list($mode_method, $privilege) = self::MODES[$mode];
        if ($method !== $mode_method) {
            return 'mode_method';
        }
        if (!self::isRootContext($auth, $runtime_company)) {
            return 'scope';
        }
        if (call_user_func($has_privilege, $privilege) !== true) {
            return 'privilege';
        }
        if ($method === self::POST && !self::tokenMatches($session_hash, $posted_hash)) {
            return 'token';
        }

        return null;
    }

    // 2026-10-21 03:19 +03: isPostMode() removed, nothing used it (Task 1.10 fix round 1 item 11), task 596.

    /**
     * Ruling R12: an administrator account (user type A) of company 0, with no storefront or vendor selected.
     *
     * @param array $auth
     * @param mixed $runtime_company
     */
    private static function isRootContext(array $auth, $runtime_company): bool
    {
        if (!isset($auth['user_type']) || $auth['user_type'] !== 'A') {
            return false;
        }
        if (!array_key_exists('company_id', $auth) || !self::isZero($auth['company_id'])) {
            return false;
        }

        return self::isZero($runtime_company);
    }

    /**
     * @param mixed $value
     */
    private static function isZero($value): bool
    {
        return $value === 0 || $value === '0';
    }

    /**
     * @param mixed $session_hash
     * @param mixed $posted_hash
     */
    private static function tokenMatches($session_hash, $posted_hash): bool
    {
        return is_string($session_hash)
            && $session_hash !== ''
            && is_string($posted_hash)
            && hash_equals($session_hash, $posted_hash);
    }
}
