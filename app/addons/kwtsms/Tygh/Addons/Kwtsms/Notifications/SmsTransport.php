<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Notifications/SmsTransport.php
 * CREATED:   2026-10-21 01:58 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The CS-Cart notification transport "kwtsms" (Tygh::$app['event.transports.kwtsms']): receives one
 *            initialized SmsMessageSchema per event and receiver that core decided to notify, and hands it to the
 *            order SMS logic (Orders\OrderNotifier), which renders the text and calls SmsService::send().
 * WHY:       PRD D1, 4.1: SMS is a native notification transport, so the switches live on Administration >
 *            Notifications and core's Notify customer / department boxes apply. The dispatcher has already checked
 *            the (fail-closed) switch and the notify rules before it calls process(); placeholders are built only
 *            now, from the event data (BaseDataProvider order_info for our event, core's OrderDataProvider for
 *            status events). An SMS failure never breaks checkout or an admin save: process() never throws.
 *            The signature fits the 4.21.2 two-argument interface and the older one-argument one.
 * USAGE:     Registered by ServiceProvider; core calls $transport->process($schema->init($data), $conditions).
 * DEPENDS:   Tygh\Notifications\Transports\{ITransport,BaseMessageSchema} (core), Notifications\SmsMessageSchema,
 *            Orders\OrderNotifier.
 */

namespace Tygh\Addons\Kwtsms\Notifications;

use Tygh\Addons\Kwtsms\Orders\OrderNotifier;
use Tygh\Notifications\Transports\BaseMessageSchema;
use Tygh\Notifications\Transports\ITransport;

class SmsTransport implements ITransport
{
    /** @var callable (): OrderNotifier, resolved on the first SMS of the request */
    private $notifier;

    /**
     * @param callable $notifier (): OrderNotifier
     */
    public function __construct(callable $notifier)
    {
        $this->notifier = $notifier;
    }

    /**
     * @return string
     */
    public static function getId()
    {
        return EventsSchemaExtender::TRANSPORT_ID;
    }

    /**
     * @param BaseMessageSchema                                   $schema
     * @param array<int,\Tygh\Notifications\Receivers\SearchCondition> $receiver_search_conditions Unused: SMS numbers
     *                                                            come from the order and the Admin SMS Alerts list
     *
     * @return bool True when an SMS was handed to the send pipeline
     */
    public function process(BaseMessageSchema $schema, array $receiver_search_conditions = [])
    {
        try {
            if (!$schema instanceof SmsMessageSchema || $schema->eventId() === '' || $schema->receiver() === '') {
                return false;
            }
            /** @var OrderNotifier $notifier */
            $notifier = call_user_func($this->notifier);

            return $notifier->notify($schema->eventId(), $schema->receiver(), $schema->payload());
        } catch (\Throwable $e) {
            error_log('kwtsms: SMS transport failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());

            return false;
        }
    }
}
