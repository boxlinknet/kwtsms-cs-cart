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
        // 2026-10-29 20:35 +03: Task 596 (4.20.1 hardening item 3, branch review M3). The location is a string computed
        // here, not a Closure: core 4.21.2 calls a callable location, other versions may not.
        $schema['auth']['login']['rewrite_controller_status'] = [
            CONTROLLER_STATUS_REDIRECT,
            \Tygh\Addons\Kwtsms\Antibot\TurnstileDriver::loginRedirectLocation($_REQUEST),
        ];
    }
} catch (\Throwable $e) {
    // A failure here leaves core's rule as it is.
    error_log('kwtsms: antibot_rules.post failed: ' . get_class($e) . ': ' . $e->getMessage());
}

return $schema;
