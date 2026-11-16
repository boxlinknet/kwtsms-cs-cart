<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Orders/Coalescer.php
 * CREATED:   2026-11-16 18:40 +03
 * TASK:      596 (M3.8, B8, P3-69)
 * AUTHOR:    kwtSMS
 * WHAT:      Holds the customer (receiver C) SMS of one order that the same request produces (a shipment saved with a
 *            status change, two status changes, an edit plus a status) and, at the end of the request, hands back
 *            ONE winner per order. The losers are written as outcome superseded, reason coalesced, through the
 *            recorder the notifier gives it.
 * WHY:       Closes P2-9: a shipment saved together with an order status change sent two SMS to the same customer
 *            about the same order seconds apart. With the switch coalesce_customer_sms ON the customer gets the one
 *            that says the most.
 * USAGE:     OrderNotifier::deliver() calls eligible() then hold() AFTER the dispatch key was claimed (so a loser's
 *            change counts as handled for 24 h and is never sent later); OrderNotifier::flushDeferred() calls flush().
 *            Winner rule: the shipment SMS beats a status SMS and an update SMS; a status SMS beats an update SMS;
 *            between two of the same kind the last one wins. Never merged: other receivers, other orders, the code
 *            sources otp / otp_code, kwtsms.order_placed, profile.*, security.* and any event outside the three kinds.
 * DEPENDS:   a recorder callable(string $event_id, int $order_id, array $phones, array $context): void.
 */

namespace Tygh\Addons\Kwtsms\Orders;

class Coalescer
{
    /** Attempt outcome and reason of a SMS that lost to a later one of the same order. */
    public const OUTCOME = 'superseded';
    public const REASON = 'coalesced';

    private const STATUS_PREFIX = 'order.status_changed.';
    private const EVENT_UPDATED = 'order.updated';
    private const EVENT_SHIPMENT = 'order.shipment_updated';

    /** @var callable|null */
    private $record;

    /** @var int 2026-11-16 21:30 +03: Task M3.8 fix round 1 M1, task 596. Held orders that trigger an early flush. */
    private $cap;

    /** @var array<int,array<int,array{event_id:string,phones:array,text:string,context:array,actor:array}>> order id => entries */
    private $held = [];

    /**
     * @param callable|null $record callable(string $event_id, int $order_id, array $phones, array $context): void
     * @param int           $cap    held orders at which full() turns true (default 200; a test injects a small one)
     */
    public function __construct(?callable $record = null, int $cap = 200)
    {
        $this->record = $record;
        $this->cap = max(1, $cap);
    }

    /** 2026-11-16 21:30 +03: M3.8 fix round 1 M1, task 596. True when the held orders reached the cap: flush early. */
    public function full(): bool
    {
        return count($this->held) >= $this->cap;
    }

    /** 2026-11-16 21:30 +03: M3.8 fix round 1 M2, task 596. Held SMS count (all orders), for the flush log line. */
    public function count(): int
    {
        $n = 0;
        foreach ($this->held as $entries) {
            $n += count($entries);
        }

        return $n;
    }

    /** Whether this SMS may be held: a customer SMS of a status change, an order edit or a shipment, from an event. */
    public function eligible(string $event_id, string $receiver, string $source): bool
    {
        return $receiver === 'C' && $source === 'event' && self::rank($event_id) > 0;
    }

    /** True while something is held. */
    public function pending(): bool
    {
        return $this->held !== [];
    }

    /**
     * Keeps one SMS in memory for the rest of the request. The dispatch key is already claimed by the caller.
     *
     * @param string[]            $phones
     * @param array<string,mixed> $context SmsService::send() context
     */
    public function hold(
        string $event_id,
        int $order_id,
        array $phones,
        string $text,
        array $context,
        array $actor = []
    ): void {
        $this->held[$order_id][] = [
            // 2026-11-16 21:30 +03: M3.8 fix round 1 I2, task 596. The actor of THIS SMS: its winner sends in its mode.
            'actor' => $actor,
            'event_id' => $event_id,
            'phones' => $phones,
            'text' => $text,
            'context' => $context,
        ];
    }

    /**
     * The winners, one per order, in the order their orders were first held. Clears everything held first, so a
     * second call (or a fatal that runs the shutdown function twice) returns nothing. Losers are recorded here.
     *
     * @return array<int,array{phones:array,text:string,context:array,actor:array}>
     */
    public function flush(): array
    {
        $held = $this->held;
        $this->held = [];
        $winners = [];
        foreach ($held as $order_id => $entries) {
            $win = 0;
            foreach ($entries as $i => $entry) {
                // >= : among equals the later one wins
                if (self::rank($entry['event_id']) >= self::rank($entries[$win]['event_id'])) {
                    $win = $i;
                }
            }
            // 2026-11-16 21:30 +03: M3.8 fix round 1 M3 (coordinator ruling), task 596. Shipment SMS are never
            // coalesced with each other (different tracking numbers): every shipment of the order wins; a status
            // or edit text still loses to a shipment.
            $all_ship = self::rank($entries[$win]['event_id']) === 3;
            foreach ($entries as $i => $entry) {
                if ($i === $win || ($all_ship && self::rank($entry['event_id']) === 3)) {
                    $winners[] = [
                        'phones' => $entry['phones'],
                        'text' => $entry['text'],
                        'context' => $entry['context'],
                        'actor' => $entry['actor'],
                    ];
                    continue;
                }
                if ($this->record !== null) {
                    try {
                        call_user_func(
                            $this->record,
                            $entry['event_id'],
                            (int) $order_id,
                            $entry['phones'],
                            $entry['context']
                        );
                    } catch (\Throwable $e) {
                        // a failed note never blocks the winner
                        continue;
                    }
                }
            }
        }

        return $winners;
    }

    /** 3 shipment, 2 status change, 1 order edit, 0 not coalesced. */
    private static function rank(string $event_id): int
    {
        if ($event_id === self::EVENT_SHIPMENT) {
            return 3;
        }
        if (strpos($event_id, self::STATUS_PREFIX) === 0) {
            return 2;
        }

        return $event_id === self::EVENT_UPDATED ? 1 : 0;
    }
}
