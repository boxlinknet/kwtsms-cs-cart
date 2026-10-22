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
 *            2026-10-22 17:11 +03, Task A4, task 596: format v2 (see EventDeclaration and schemas/kwtsms/events.php):
 *            receivers C, A and, in Multi-Vendor only, V; a core event gets the transport only for receivers the merged
 *            schema already has; our own kwtsms.* events get every declared receiver.
 * USAGE:     $schema = (new EventsSchemaExtender(fn_get_schema('kwtsms', 'events'), $is_mve))->extend($schema);
 * DEPENDS:   Notifications\SmsMessageSchema.
 */

namespace Tygh\Addons\Kwtsms\Notifications;

class EventsSchemaExtender
{
    public const TRANSPORT_ID = 'kwtsms';

    public const STATUS_PREFIX = 'order.status_changed.';

    /** Receivers of the order status events (the vendor receiver of a status event is not in Phase 2). */
    public const STATUS_RECEIVERS = ['C', 'A'];

    /** Prefix of the add-on's own events: their declared receivers are attached even where the schema lacks them. */
    public const OWN_PREFIX = 'kwtsms.';

    /** @var array<string,EventDeclaration> */
    private $declared;

    /**
     * @param array<mixed,mixed> $declared The kwtsms events schema: event id => ['receivers' => [...], ...]
     * @param bool               $is_mve   2026-10-22 17:11 +03: Task A4, task 596. Multi-Vendor keeps receiver V
     */
    public function __construct(array $declared = [], bool $is_mve = false)
    {
        $this->declared = self::declarations($declared, $is_mve);
    }

    /**
     * 2026-10-22 17:11 +03: Task A4, task 596. Replaces the 1.0.0 constant RECEIVERS: the receivers an SMS can have;
     * V only in
     * Multi-Vendor.
     *
     * @return string[]
     */
    public static function receivers(bool $is_mve): array
    {
        return $is_mve ? ['C', 'A', 'V'] : ['C', 'A'];
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
                foreach (self::STATUS_RECEIVERS as $receiver) {
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
            $own = strpos($event_id, self::OWN_PREFIX) === 0;
            foreach ($this->declared[$event_id]->receivers() as $receiver) {
                // 2026-10-22 17:11 +03: Task A4, task 596. A core event gets the SMS transport only for the receivers
                // the merged
                // schema already has (it never adds A to order.shipment_updated); our own kwtsms.* events get every
                // declared receiver.
                if (!$own && !isset($schema[$event_id]['receivers'][$receiver])) {
                    continue;
                }
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
     * The usable declarations as validated objects (2026-10-22 17:11 +03: Task A4, task 596).
     *
     * @param array<mixed,mixed> $declared
     *
     * @return array<string,EventDeclaration>
     */
    public static function declarations(array $declared, bool $is_mve = false): array
    {
        $out = [];
        foreach ($declared as $event_id => $decl) {
            if (!is_string($event_id) || !is_array($decl)) {
                continue;
            }
            $parsed = EventDeclaration::fromArray($event_id, $decl, $is_mve);
            if ($parsed !== null) {
                $out[$event_id] = $parsed;
            }
        }

        return $out;
    }

    /**
     * The usable declarations as arrays: string event id, receivers a list holding C, A and (Multi-Vendor) V,
     * placeholders an array; every other key as declared. 2026-10-22 17:11 +03: V and the format v2 (Task A4, task
     * 596).
     *
     * @param array<mixed,mixed> $declared
     *
     * @return array<string,array<string,mixed>>
     */
    public static function validDeclarations(array $declared, bool $is_mve = false): array
    {
        $out = [];
        foreach (self::declarations($declared, $is_mve) as $event_id => $parsed) {
            $decl = $declared[$event_id];
            $decl['receivers'] = $parsed->receivers();
            $decl['placeholders'] = $parsed->placeholders();
            $out[$event_id] = $decl;
        }

        return $out;
    }

    private static function schemaFor(string $event_id, string $receiver): SmsMessageSchema
    {
        return SmsMessageSchema::create(['event_id' => $event_id, 'receiver' => $receiver]);
    }
}
