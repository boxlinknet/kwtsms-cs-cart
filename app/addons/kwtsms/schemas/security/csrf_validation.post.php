<?php

/*
 * FILE:      app/addons/kwtsms/schemas/security/csrf_validation.post.php
 * CREATED:   2026-10-21 01:13 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Exempts the controller kwtsms_cron from the core anti-CSRF form-token check of the admin area.
 * WHY:       PRD 6.11: the URL mode may carry the cron key in a POST body. An external scheduler has no session and no
 *            security_hash, so without this exemption the core redirects the request before the controller runs.
 *            The controller authenticates every web request with the cron key (hash_equals) and ignores a request
 *            without it, so the check the core would do adds nothing here; every other kwtsms controller keeps it.
 * USAGE:     Loaded by CS-Cart (fn_get_schema('security', 'csrf_validation')).
 * DEPENDS:   Controller kwtsms_cron (Task 1.7).
 */

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */
$schema['A']['controllers']['kwtsms_cron'] = [
    'validate' => false,
];

return $schema;
