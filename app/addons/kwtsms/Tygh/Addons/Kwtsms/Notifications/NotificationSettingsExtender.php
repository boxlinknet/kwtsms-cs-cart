<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Notifications/NotificationSettingsExtender.php
 * CREATED:   2026-10-21 01:57 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Fail-closed SMS switches: in the resolved notification settings, the kwtsms transport is OFF wherever no
 *            ?:notification_settings row is stored, except kwtsms.order_placed for the customer and the admin (ON). A
 *            stored row always decides. Every other transport keeps what core computed.
 * WHY:       PRD 4.1, 13: core treats a missing row as allowed (EventDispatcherProvider.php:146-151). Without this an
 *            upgrade, a status the merchant creates later or another add-on's event would start sending SMS by
 *            itself. init.php extends the service event.notification_settings with this; the dispatcher and the
 *            Notifications page both read that service, so what the page shows is what the dispatcher does. No rows
 *            are written at install; saving the Notifications page writes one row per visible checkbox.
 * USAGE:     $rows = NotificationSettingsExtender::storedRows();       // one SELECT per request
 *            $settings = (new NotificationSettingsExtender())->extend($settings, $rows);
 * DEPENDS:   CS-Cart db_get_array() (storedRows() only).
 */

namespace Tygh\Addons\Kwtsms\Notifications;

class NotificationSettingsExtender
{
    /** The only switches that are ON without a stored row (PRD 6.5). */
    public const DEFAULT_ON = [
        'kwtsms.order_placed' => ['C' => true, 'A' => true],
    ];

    /**
     * @param array<string,mixed>              $settings           event => ['receivers' => [r => [transport => bool]]]
     * @param array<string,array<string,bool>> $stored_kwtsms_rows event => receiver => is_allowed (kwtsms rows)
     *
     * @return array<string,mixed>
     */
    public function extend(array $settings, array $stored_kwtsms_rows): array
    {
        foreach ($settings as $event_id => $event) {
            if (!is_array($event) || !isset($event['receivers']) || !is_array($event['receivers'])) {
                continue;
            }
            foreach ($event['receivers'] as $receiver => $transports) {
                if (!is_array($transports) || !array_key_exists(EventsSchemaExtender::TRANSPORT_ID, $transports)) {
                    continue;
                }
                if (isset($stored_kwtsms_rows[$event_id][$receiver])) {
                    $value = (bool) $stored_kwtsms_rows[$event_id][$receiver];
                } else {
                    $value = self::DEFAULT_ON[$event_id][$receiver] ?? false;
                }
                $settings[$event_id]['receivers'][$receiver][EventsSchemaExtender::TRANSPORT_ID] = $value;
            }
        }

        return $settings;
    }

    /**
     * Every kwtsms switch off: the answer when the stored rows cannot be read (fail-closed).
     *
     * @param array<string,mixed> $settings
     *
     * @return array<string,mixed>
     */
    public static function allOff(array $settings): array
    {
        foreach ($settings as $event_id => $event) {
            if (!is_array($event) || !isset($event['receivers']) || !is_array($event['receivers'])) {
                continue;
            }
            foreach ($event['receivers'] as $receiver => $transports) {
                if (is_array($transports) && array_key_exists(EventsSchemaExtender::TRANSPORT_ID, $transports)) {
                    $settings[$event_id]['receivers'][$receiver][EventsSchemaExtender::TRANSPORT_ID] = false;
                }
            }
        }

        return $settings;
    }

    /**
     * The stored kwtsms rows, read once (the caller is the per-request settings service).
     *
     * @return array<string,array<string,bool>>
     */
    public static function storedRows(): array
    {
        $rows = db_get_array(
            'SELECT event_id, receiver, is_allowed FROM ?:notification_settings WHERE transport_id = ?s',
            EventsSchemaExtender::TRANSPORT_ID
        );

        return self::rowsToMap(is_array($rows) ? $rows : []);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<string,array<string,bool>>
     */
    public static function rowsToMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['event_id'], $row['receiver']) || $row['event_id'] === '') {
                continue;
            }
            // Core's own reader: anything but '0' is allowed (fn_get_notification_settings()).
            $map[(string) $row['event_id']][(string) $row['receiver']] = (string) ($row['is_allowed'] ?? '0') !== '0';
        }

        return $map;
    }
}
