<?php

/*
 * FILE:      app/addons/kwtsms/controllers/backend/kwtsms_cron.php
 * CREATED:   2026-10-21 01:13 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The controller kwtsms_cron, mode run: drain the SMS outbox, sync balance/senders/coverage, prune logs and
 *            raise the daily health notices (fn_kwtsms_cron_run()).
 * WHY:       PRD 6.11. The cron runs it every minute from the command line, or from outside over the optional URL mode.
 *            kwtsms_cron.run is a trusted controller (schemas/permissions/trusted_controllers.post.php: no login),
 *            and any logged-in administrator can reach it through the permission schema too, so this controller
 *            authenticates EVERY web request itself with the cron key (CronKey, hash_equals): URL mode on, key in
 *            the header X-Kwtsms-Cron-Key or the POST field kwtsms_cron_key, never the query string. A refused
 *            request gets 403 and {"ok":false}, nothing is run and nothing says why. The command line (CONSOLE)
 *            needs no key. 2026-10-21 01:58 +03: web requests must be POST (PRD 10: no state change on GET); a
 *            GET, PUT or DELETE that carries the right key gets 405, "Allow: POST" and {"ok":false}, Task 1.7 fix
 *            round 1 item 6, task 596.
 * USAGE:     * * * * * www-data php /var/www/html/admin.php --dispatch=kwtsms_cron.run
 *            curl -s -X POST -H 'X-Kwtsms-Cron-Key: <key>' \
 *              'https://shop.example.com/admin.php?dispatch=kwtsms_cron.run'
 *            -> {"ok":true} (or 500 {"ok":false} when a job failed). In the CLI the exit code is 1 when a job failed.
 * DEPENDS:   func.php (fn_kwtsms_cron_run, fn_kwtsms_cron_denial, fn_kwtsms_cron_log_denial, fn_kwtsms_cron_reply),
 *            Cron\CronRunner and Cron\CronKey through Tygh::$app['kwtsms.cron'].
 */

defined('BOOTSTRAP') or die('Access denied');

/** @var string $mode */

if ($mode !== 'run') {
    return [CONTROLLER_STATUS_NO_PAGE];
}

if (defined('CONSOLE')) {
    fn_kwtsms_cron_run();

    return [CONTROLLER_STATUS_OK];
}

$denied = fn_kwtsms_cron_denial();
if ($denied !== null) {
    fn_kwtsms_cron_log_denial($denied);
    fn_kwtsms_cron_reply($denied === 'method' ? 405 : 403, false);
}

// A caller that hangs up must not stop a run half way (the outbox rows are claimed one by one).
ignore_user_abort(true);
@set_time_limit(120);

$result = fn_kwtsms_cron_run();
fn_kwtsms_cron_reply($result['ok'] ? 200 : 500, $result['ok']);
