<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Notifications/EventsSchemaExtender.php
 * CREATED:   2026-10-21 01:56 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Attaches the kwtsms transport to the merged notification events schema: one SmsMessageSchema per event
 *            and receiver for every order.status_changed.<status> event (custom statuses included) and for every
 *            event declared in schemas/kwtsms/events.php (+ post-files of other add-ons), receivers C and A only.
 * WHY:       PRD 4.1, 5.2. init.php extends the service event.events_schema with this, so the Notifications page shows
 *            an SMS column wherever SMS is possible and the dispatcher hands those events to our transport. The
 *            schema files of add-ons run in priority order, so a post-file cannot see every event; the service
 *            extension sees the merged result. Mail and every other transport stay exactly as they were. An event
 *            is never created here: a declaration for an event that does not exist is ignored. The vendor receiver
 *            (V) is Phase 2.
 *            Declaration format (schemas/kwtsms/events.post.php of a third-party add-on):
 *              $schema['acme.shipped'] = ['receivers' => ['C', 'A'],
 *                  'placeholders' => ['tracking' => 'shipment.tracking_number'],  // {tracking}: dotted data path
 *                  'phone' => 'user_data.phone'];                                  // optional customer phone path
 * USAGE:     $schema = (new EventsSchemaExtender(fn_get_schema('kwtsms', 'events')))->extend($schema);
 * DEPENDS:   Notifications\SmsMessageSchema.
 */

namespace Tygh\Addons\Kwtsms\Notifications;

class EventsSchemaExtender
{
    public const TRANSPORT_ID = 'kwtsms';

    public const STATUS_PREFIX = 'order.status_changed.';

    /** Phase 1 receivers. */
    public const RECEIVERS = ['C', 'A'];

    /** @var array<string,array<string,mixed>> */
    private $declared;

    /**
     * @param array<mixed,mixed> $declared The kwtsms events schema: event id => ['receivers' => [...], ...]
     */
    public function __construct(array $declared = [])
    {
        $this->declared = self::validDeclarations($declared);
    }

    /**
     * @param array<string,mixed> $schema The merged notification events schema
     *
     * @return array<string,mixed>
     */
    public function extend(array $schema): array
    {
        foreach ($schema as $event_id => $event) {
            if (!is_string($event_id) || !is_array($event)) {
                continue;
            }
            if (strpos($event_id, self::STATUS_PREFIX) === 0 && strlen($event_id) > strlen(self::STATUS_PREFIX)) {
                if (!isset($event['receivers']) || !is_array($event['receivers'])) {
                    continue;
                }
                foreach (self::RECEIVERS as $receiver) {
                    if (isset($event['receivers'][$receiver]) && is_array($event['receivers'][$receiver])) {
                        $sms = self::schemaFor($event_id, $receiver);
                        $schema[$event_id]['receivers'][$receiver][self::TRANSPORT_ID] = $sms;
                    }
                }
                continue;
            }
            if (!isset($this->declared[$event_id])) {
                continue;
            }
            if (!isset($event['receivers']) || !is_array($event['receivers'])) {
                $schema[$event_id]['receivers'] = [];
            }
            foreach ($this->declared[$event_id]['receivers'] as $receiver) {
                $current = $schema[$event_id]['receivers'][$receiver] ?? [];
                if (!is_array($current)) {
                    continue;
                }
                $current[self::TRANSPORT_ID] = self::schemaFor($event_id, $receiver);
                $schema[$event_id]['receivers'][$receiver] = $current;
            }
        }

        return $schema;
    }

    /**
     * The usable declarations: string event id, receivers a list holding C and/or A.
     *
     * @param array<mixed,mixed> $declared
     *
     * @return array<string,array<string,mixed>>
     */
    public static function validDeclarations(array $declared): array
    {
        $out = [];
        foreach ($declared as $event_id => $decl) {
            $usable = is_string($event_id) && $event_id !== '' && is_array($decl)
                && isset($decl['receivers']) && is_array($decl['receivers']);
            if (!$usable) {
                continue;
            }
            $receivers = array_values(array_intersect(self::RECEIVERS, $decl['receivers']));
            if ($receivers === []) {
                continue;
            }
            $decl['receivers'] = $receivers;
            if (!isset($decl['placeholders']) || !is_array($decl['placeholders'])) {
                $decl['placeholders'] = [];
            }
            $out[$event_id] = $decl;
        }

        return $out;
    }

    private static function schemaFor(string $event_id, string $receiver): SmsMessageSchema
    {
        return SmsMessageSchema::create(['event_id' => $event_id, 'receiver' => $receiver]);
    }
}
