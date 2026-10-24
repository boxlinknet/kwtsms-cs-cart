<?php

/*
 * FILE:      app/addons/kwtsms/schemas/security/antibot_rules.post.php
 * CREATED:   2026-10-24 23:05 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      While Turnstile is the active antibot driver, the core rule of auth.login always ends a request that
 *            failed the CAPTCHA with a redirect: to the posted redirect_url (a local address) or to auth.login_form.
 * WHY:       Ruling P3-R9. CS-Cart 4.21.2 gives that rule a redirect without an address; when the post has no
 *            redirect_url (the real login form sends one, a script need not) fn_validate_controller_with_antibot()
 *            answers "no content" and the dispatcher still runs the login controller, so the user is logged in with
 *            no CAPTCHA. A location that is always present makes the failed check break the cascade before auth.login
 *            runs. With Turnstile not the driver the schema is left exactly as core has it.
 * USAGE:     Loaded by CS-Cart (fn_get_schema('security', 'antibot_rules')), called once per storefront request.
 * DEPENDS:   Tygh::$app['antibot'], Antibot\TurnstileDriver.
 */

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */
try {
    $kwtsms_driver = isset(\Tygh\Tygh::$app['antibot']) ? \Tygh\Tygh::$app['antibot']->getDriver() : null;
    if (
        $kwtsms_driver instanceof \Tygh\Addons\Kwtsms\Antibot\TurnstileDriver
        && isset($schema['auth']['login']['rewrite_controller_status'])
    ) {
        $schema['auth']['login']['rewrite_controller_status'] = [
            CONTROLLER_STATUS_REDIRECT,
            static function () {
                $url = isset($_REQUEST['redirect_url']) && is_string($_REQUEST['redirect_url'])
                    ? trim($_REQUEST['redirect_url']) : '';

                // A local address only (no scheme, no host, no protocol-relative form).
                return $url !== '' && !preg_match('#^([a-z][a-z0-9+.-]*:|//|\\\\)#i', $url) ? $url : 'auth.login_form';
            },
        ];
    }
} catch (\Throwable $e) {
    // A failure here leaves core's rule as it is.
    error_log('kwtsms: antibot_rules.post failed: ' . get_class($e) . ': ' . $e->getMessage());
}

return $schema;
