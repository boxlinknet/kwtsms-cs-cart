<?php

/*
 * FILE:      app/addons/kwtsms/controllers/frontend/checkout.pre.php
 * CREATED:   2026-10-25 07:12 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Pre-controller of the storefront checkout: for POST checkout.update_profile (the checkout profile editor)
 *            it runs the phone checks C3 (country) and C4 (one account per phone) before core saves anything. A refused
 *            phone shows the same notice as the profile form and the request is redirected back to the checkout; an
 *            accepted phone is rewritten to its canonical form in the request.
 * WHY:       Task M1.9 fix round 1 (I1). Core saves that editor with fn_update_user_profile(), which has no refusal
 *            hook (update_user_pre is not fired), so a Saudi phone or a number another account holds went in
 *            unchecked and then reached the order and the phone index. A returned CONTROLLER_STATUS_REDIRECT stops
 *            core's checkout
 *            controller (fn.control.php), so nothing is saved.
 * USAGE:     Loaded by core before controllers/frontend/checkout.php. All logic is in
 *            Profiles\ProfileHooks::onCheckoutProfileEdit() (the same evaluate() and accountPhoneTaken() as the forms);
 *            with both switches OFF it returns at once and reads nothing.
 * DEPENDS:   Tygh::$app['kwtsms.profile_hooks'], func.php fn_kwtsms_log_hook_error().
 */

use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

/** @var string $mode */
/** @var array<string,mixed> $auth */

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $mode === 'update_profile'
    && !empty($auth['user_id']) && !empty($_REQUEST['user_data']) && is_array($_REQUEST['user_data'])
) {
    try {
        $kwtsms_user_data = $_REQUEST['user_data'];
        if (Tygh::$app['kwtsms.profile_hooks']->onCheckoutProfileEdit((int) $auth['user_id'], $kwtsms_user_data)) {
            return [CONTROLLER_STATUS_REDIRECT, 'checkout.checkout'];
        }
        $_REQUEST['user_data'] = $kwtsms_user_data;
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('checkout.update_profile', $e);
    }
}
