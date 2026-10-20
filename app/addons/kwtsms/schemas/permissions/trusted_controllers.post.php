<?php

/*
 * FILE:      app/addons/kwtsms/schemas/permissions/trusted_controllers.post.php
 * CREATED:   2026-10-20 19:56 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Declares kwtsms_cron.run as reachable without an administrator login.
 * WHY:       PRD 6.11: the optional cron URL mode calls admin.php?dispatch=kwtsms_cron.run from outside;
 *            the controller (Task 1.7) accepts it only with the cron key (header or POST body, compared
 *            with hash_equals) and while the URL mode setting is on. Only the run mode is trusted.
 * USAGE:     Loaded by CS-Cart (fn_get_schema('permissions', 'trusted_controllers')); the area defaults to
 *            the admin panel.
 * DEPENDS:   Controller kwtsms_cron (Task 1.7).
 */

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */
$schema['kwtsms_cron'] = [
    'allow' => [
        'run' => true,
    ],
];

return $schema;
