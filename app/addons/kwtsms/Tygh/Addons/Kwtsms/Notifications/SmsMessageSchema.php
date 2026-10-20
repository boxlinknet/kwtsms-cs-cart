<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Notifications/SmsMessageSchema.php
 * CREATED:   2026-10-21 01:54 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The message schema of the kwtsms transport: carries the event id and the receiver as plain values,
 *            and the order data of the event (DataValue, resolved by core's init()).
 * WHY:       PRD 4.1: ITransport::process() receives only the initialized schema, never the event id or the receiver,
 *            so each (event, receiver) needs its own schema object that knows both (EventsSchemaExtender creates one
 *            per pair, like core clones its mail schemas, events.php:809-823). The DataValue property is protected so
 *            nothing outside reads the raw DataValue; BaseMessageSchema::init() can still resolve it (same class
 *            hierarchy). data_modifier is assigned in create() and only normalizes order_info to an array.
 * USAGE:     $s = SmsMessageSchema::create(['event_id' => 'order.status_changed.p', 'receiver' => 'C']);
 *            $ready = $s->init(new \Tygh\Notifications\Data(['order_info' => $order]));   // core does this
 *            $ready->eventId(); $ready->receiver(); $ready->orderInfo(); $ready->payload();
 *            Not for use in other add-ons' schemas (PRD 5.2): declare events in schemas/kwtsms/events.post.php.
 * DEPENDS:   Tygh\Notifications\Transports\BaseMessageSchema, Tygh\Notifications\DataValue (CS-Cart core).
 */

namespace Tygh\Addons\Kwtsms\Notifications;

use Tygh\Notifications\DataValue;
use Tygh\Notifications\Transports\BaseMessageSchema;

class SmsMessageSchema extends BaseMessageSchema
{
    /** @var string */
    public $event_id = '';

    /** @var string C, A */
    public $receiver = '';

    /** @var DataValue|array<string,mixed>|null The event's order_info once initialized */
    protected $order_info;

    /**
     * @param array<string,mixed> $schema event_id, receiver
     */
    public static function create(array $schema): self
    {
        $self = new self();
        $self->event_id = isset($schema['event_id']) && is_string($schema['event_id']) ? $schema['event_id'] : '';
        $self->receiver = isset($schema['receiver']) && is_string($schema['receiver']) ? $schema['receiver'] : '';
        $self->order_info = DataValue::create('order_info', []);
        $self->data_modifier = [self::class, 'normalizeData'];

        return $self;
    }

    /**
     * data_modifier: order_info is always an array (core passes fn_get_order_info() rows, a third-party event may
     * pass nothing).
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    public static function normalizeData(array $data): array
    {
        if (!isset($data['order_info']) || !is_array($data['order_info'])) {
            $data['order_info'] = [];
        }

        return $data;
    }

    public function eventId(): string
    {
        return $this->event_id;
    }

    public function receiver(): string
    {
        return $this->receiver;
    }

    /**
     * @return array<string,mixed>
     */
    public function orderInfo(): array
    {
        return is_array($this->order_info) ? $this->order_info : [];
    }

    /**
     * Every value of the event (after the data_modifier).
     *
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return is_array($this->data) ? $this->data : [];
    }
}
