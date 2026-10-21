<?php

/*
 * FILE:      app/addons/kwtsms/schemas/gdpr/user_data.post.php
 * CREATED:   2026-10-21 05:54 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The kwtSMS entry of the core GDPR personal-data schema: the SMS rows of a customer (messages, attempts and
 *            outbox rows found by user id, order ids and normalized phone numbers) for core's data view and export,
 *            and their anonymization when an administrator anonymizes the customer.
 * WHY:       PRD 6.8 (GDPR) and ruling R28. Core's anonymizer only changes the fields named in fields_list, with its
 *            own fixed replacements; the add-on needs its own rules (one-way token for each number, "[anonymized]"
 *            text, IP cleared, waiting outbox rows closed), so update_data_callback does the work from the rows
 *            collect_data_callback found (Privacy\GdprUserData).
 *            2026-10-21 08:10 +03, Task 1.14 fix round 1: fields_list names sms_phone and sms_text (items 3): core's
 *            view and XML export show only the fields in fields_list, and its anonymizer has no rule for these two, so
 *            it passes the rows on unchanged. A failed collect returns GdprUserData::FAILED and a failed or refused
 *            update raises kwtsms.gdpr.failed (item 4): the administrator never sees "anonymized" for rows that were
 *            not.
 * USAGE:     Loaded by core (Tygh\Gdpr\SchemaManager: fn_get_schema('gdpr', 'user_data')) while the add-on is active:
 *            collect_data_callback($user_info), update_data_callback($collected).
 * DEPENDS:   Privacy\GdprUserData, Tygh::$app['kwtsms.config'] and ['kwtsms.logs.debug'] (ServiceProvider).
 */

use Tygh\Addons\Kwtsms\Privacy\GdprUserData;

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */
$schema['kwtsms'] = [
    'params' => [
        'fields_list' => GdprUserData::FIELDS,
    ],
    'collect_data_callback' => static function ($params) {
        try {
            return GdprUserData::fromContainer(Tygh::$app)->collectForCore(is_array($params) ? $params : []);
        } catch (\Throwable $e) {
            error_log('kwtsms: gdpr collect failed: ' . get_class($e) . ': ' . $e->getMessage() . ' in '
                . basename($e->getFile()) . ':' . $e->getLine());

            // 2026-10-21 08:38 +03: the marker with its visible line (fix round 2, item 4), task 596.
            return GdprUserData::failedMarker();
        }
    },
    'update_data_callback' => static function ($data) {
        $done = false;
        try {
            $done = GdprUserData::fromContainer(Tygh::$app)->updateFromCore(is_array($data) ? $data : []);
        } catch (\Throwable $e) {
            error_log('kwtsms: gdpr anonymize failed: ' . get_class($e) . ': ' . $e->getMessage() . ' in '
                . basename($e->getFile()) . ':' . $e->getLine());
        }
        if (!$done) {
            // Core reports the customer as anonymized; the administrator must know the SMS rows are not.
            fn_set_notification('E', __('error'), fn_html_escape((string) __('kwtsms.gdpr.failed')));
        }
    },
];

return $schema;
