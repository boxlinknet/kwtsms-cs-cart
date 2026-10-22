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
    // 2026-10-22 17:59 +03: Task A5 (SafatMall F2), task 596. Core's order.updated: the admin edited or saved an order
    // (Orders > details, or Order management). C and A (V comes with B6). Sent once per order and receiver in 10
    // minutes (saving again is a duplicate_event). An order flagged new or admin-created in this request is
    // superseded by the order-placed SMS (OrderNotifier). Default OFF.
    'order.updated' => [
        'receivers' => ['C', 'A'],
        'order' => 'order_info',
        'group' => 'kwtsms.templates.group_order_updates',
        'dedupe' => ['key' => 'updated:{order_info.order_id}:{receiver}', 'window' => 600],
        'opt_out' => true,
    ],
    // 2026-10-22 17:59 +03: Task A5, task 596. Core's order.shipment_updated (a shipment was created, with or
    // without a tracking number): C only. One SMS per shipment, ever. tracking_number is cleaned by ShipmentSmsData.
    // Default OFF.
    'order.shipment_updated' => [
        'receivers' => ['C'],
        'placeholders' => [
            'tracking_number' => 'shipment.tracking_number',
            'carrier' => 'shipment.carrier_info.name',
            'tracking_url' => 'shipment.carrier_info.tracking_url',
        ],
        'dedupe' => ['key' => 'shipment:{shipment.shipment_id}:{receiver}', 'window' => 0],
        'opt_out' => true,
        'group' => 'kwtsms.templates.group_order_updates',
        'data_class' => \Tygh\Addons\Kwtsms\Orders\ShipmentSmsData::class,
    ],
    // 2026-10-22 19:08 +03: Task A6 (SafatMall F5 part), task 596. Core's profile.created.c (a customer account was
    // created and
    // the Notify box was on): the welcome SMS, C only, once per account. The password of the event data is never
    // mapped. Default OFF.
    'profile.created.c' => [
        'receivers' => ['C'],
        'phone' => 'user_data.phone',
        'country' => 'user_data.b_country',
        'lang' => 'user_data.lang_code',
        'storefront' => 'storefront_id',
        'user_id' => 'user_data.user_id',
        'dedupe' => ['key' => 'created:{user_data.user_id}:{receiver}', 'window' => 0],
        'group' => 'kwtsms.templates.group_profile',
        'data_class' => \Tygh\Addons\Kwtsms\Profiles\ProfileSmsData::class,
    ],
    // 2026-10-22 19:08 +03: Task A6, task 596. Core's profile.password_recover.c (a customer asked for a password
    // reset): the SMS
    // carries the reset link (placeholder reset_url, built by ProfileSmsData from the ekey). The link and the ekey
    // are secrets: masked in every log copy (secret_values), the outbox row expires with the ekey (ttl 900). One SMS
    // per
    // account in 10 minutes (a repeat is skipped with the reason recover_throttled). The F6 opt-out never covers it
    // (P2-4). profile.password_recover.a and .v are never declared (PRD 10). Default OFF.
    'profile.password_recover.c' => [
        'receivers' => ['C'],
        'phone' => 'user_data.phone',
        'lang' => 'user_data.lang_code',
        'storefront' => 'storefront_id',
        'user_id' => 'user_data.user_id',
        // reset_url is computed by ProfileSmsData (the placeholder path is only the declaration of the name; the data
        // class value wins) and is secret.
        'placeholders' => ['reset_url' => 'ekey'],
        'secret' => ['reset_url'],
        'dedupe' => [
            'key' => 'recover:{user_data.user_id}:{receiver}', 'window' => 600, 'reason' => 'recover_throttled',
        ],
        'group' => 'kwtsms.templates.group_profile',
        'data_class' => \Tygh\Addons\Kwtsms\Profiles\ProfileSmsData::class,
    ],
    // 2026-10-22 19:08 +03: Task A6, task 596. The add-on's own event (schemas/notifications/events.post.php): the
    // administrator is told
    // when a customer registers on the storefront or at checkout (P2-7). A only. Default OFF.
    'kwtsms.customer_registered' => [
        'receivers' => ['A'],
        'phone' => 'user_data.phone',
        'country' => 'user_data.b_country',
        'lang' => 'user_data.lang_code',
        'storefront' => 'storefront_id',
        'user_id' => 'user_data.user_id',
        'dedupe' => ['key' => 'registered:{user_data.user_id}:A', 'window' => 0],
        'group' => 'kwtsms.templates.group_profile',
        'data_class' => \Tygh\Addons\Kwtsms\Profiles\ProfileSmsData::class,
    ],
];
