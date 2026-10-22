<?php

/*
 * FILE:      app/addons/kwtsms/schemas/kwtsms/events.php
 * CREATED:   2026-10-21 01:52 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The kwtsms events schema: notification events (other than the order status events, which are always
 *            covered) that get the SMS transport, with their receivers and the placeholder map. Base file; other
 *            add-ons extend it with schemas/kwtsms/events.post.php.
 * WHY:       PRD 5.2 extension contract: a third-party event gets SMS by declaring it here, never by referencing our
 *            classes in its own notification schema (a disabled kwtsms would then fatal the store). Every declared
 *            event starts with its SMS switch OFF (fail-closed); nothing is sent until the merchant switches it on
 *            and writes a text for it on the Templates page.
 *            Format (v2, 2026-10-22 17:13 +03, Task A4, task 596; every 1.0.0 key keeps its meaning, every v2 key is
 * optional,
 *            a wrong one is ignored; every path is a dotted path into the event data):
 *            event id => [
 *                'receivers'    => ['C', 'A', 'V'],                  // required; V (vendor) is kept only in
 * Multi-Vendor
 *                'placeholders' => ['name' => 'dotted.path.in.data'], // {name} for the template
 *                'phone'        => 'path',                           // customer phone (else the order's)
 *                'country'      => 'path',                           // ISO2 country of the customer
 *                'lang'         => 'path',                           // 2-letter language of the text
 *                'storefront'   => 'path',                           // storefront id (else order, payload)
 *                'user_id'      => 'path',                           // customer account
 *                'company_id'   => 'path',                           // the vendor of a V SMS
 *                'order'        => 'path',                           // an order array, default order_info
 *                'dedupe'       => ['key' => 'shipment:{shipment.shipment_id}:{receiver}',
 *                                   'window' => 600,                 // seconds, 0 = permanent
 *                                   'reason' => 'recover_throttled'],// optional attempt reason of a duplicate
 *                'secret'       => ['name'],                         // placeholder names masked in every log copy
 *                'opt_out'      => true,                             // customer opt-out applies (F6)
 *                'group'        => 'langvar',                        // Templates page heading
 *                'data_class'   => \Acme\Sms\ShippedData::class,   // implements Notifications\DeclaredSmsData
 *            ]
 *            A core event gets the SMS transport only for receivers its merged notification schema already has; the
 *            add-on's own kwtsms.* events get every declared receiver. Own events are declared twice: here
 *            (receivers, paths, dedupe) and in schemas/notifications/events.post.php (the core event, no transport).
 * USAGE:     fn_get_schema('kwtsms', 'events'), read by init.php for EventsSchemaExtender.
 * DEPENDS:   Nothing.
 */

defined('BOOTSTRAP') or die('Access denied');

return [
    'kwtsms.order_placed' => [
        'receivers' => ['C', 'A'],
    ],
];
