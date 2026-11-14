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
 *            2026-10-23 06:02 +03: Cleanup CA1-8 (A4 M5), task 596: a declaration whose dedupe key names the path of a
 *            'secret' placeholder is refused (the whole event is ignored): the key is stored and shown as the reason.
 *            A core event gets the SMS transport only for receivers its merged notification schema already has; the
 *            add-on's own kwtsms.* events get every declared receiver. Own events are declared twice: here
 *            (receivers, paths, dedupe) and in schemas/notifications/events.post.php (the core event, no transport).
 * USAGE:     fn_get_schema('kwtsms', 'events'), read by init.php for EventsSchemaExtender.
 * DEPENDS:   Nothing.
 */

defined('BOOTSTRAP') or die('Access denied');

$schema = [
    // 2026-10-22 22:23 +03: Task A8 (SafatMall F4, R9), task 596. V is the vendor of a child order of a Multi-Vendor
    // checkout (kept only
    // in Multi-Vendor): its approved number, its monthly quota, one SMS per child order (key order_placed:<child>:V),
    // default OFF.
    'kwtsms.order_placed' => [
        'receivers' => ['C', 'A', 'V'],
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
        // 2026-10-22 20:27 +03: Task A6 fix round 1 (P2-R12), task 596. A storefront registration sends only while
        // the core antibot
        // scenario "register" is active (public form, PRD 6.5 / R25); admin-created customers are not gated.
        'public_form' => 'register',
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
        // 2026-10-22 20:27 +03: Task A6 fix round 1 (P2-R12), task 596. Same antibot rule as the welcome SMS.
        'public_form' => 'register',
        'group' => 'kwtsms.templates.group_profile',
        'data_class' => \Tygh\Addons\Kwtsms\Profiles\ProfileSmsData::class,
    ],
    // 2026-10-22 21:28 +03: Task A7 (SafatMall F5 part, P2-10), task 596. The add-on's own event (also in
    // schemas/notifications/events.post.php): a product's stock crossed the low-stock threshold. A is the
    // administrator (marketplace products and every Ultimate product), V the vendor (Multi-Vendor only, its approved
    // number). One SMS per receiver and request: the first product plus {more_count}. No dedupe here: the 24 h key
    // per product is claimed by Orders\LowStockCollector before the dispatch (R20). Default OFF.
    'kwtsms.low_stock' => [
        'receivers' => ['A', 'V'],
        'storefront' => 'storefront_id',
        'company_id' => 'company_id',
        'group' => 'kwtsms.templates.group_products',
        'data_class' => \Tygh\Addons\Kwtsms\Orders\LowStockSmsData::class,
    ],
    // 2026-10-23 00:14 +03: Task A9 (SafatMall F5 part, R6, R7), task 596. The add-on's own event (also in
    // schemas/notifications/events.post.php): the balance crossed the low-balance threshold. A only (the staff phone
    // list, cap_admin_day). One SMS per crossing: the key carries the crossing time (Alerts\LowBalanceSms).
    // Default OFF.
    'kwtsms.low_balance' => [
        'receivers' => ['A'],
        'placeholders' => ['balance' => 'balance', 'threshold' => 'threshold'],
        'storefront' => 'storefront_id',
        'dedupe' => ['key' => 'low_balance:{crossing_at}:{receiver}', 'window' => 0],
        'group' => 'kwtsms.events_group',
    ],
    // 2026-11-06 22:07 +03: Task M2.11 (P3-41, P3-57), task 596. The add-on's own event (also in
    // schemas/notifications/events.post.php): more sends failed within 15 minutes than fail_spike_threshold. A only
    // (the staff phone list, cap_admin_day). One SMS per clock hour: the key carries the hour start (Alerts\FailSpike).
    // Default OFF; the attempts of this event are not counted by the spike check, so it cannot raise itself.
    'kwtsms.send_fail_spike' => [
        'receivers' => ['A'],
        // 2026-11-07 02:46 +03: Task M2.7 (M2.11 review minor), task 596. The placeholder is window_minutes
        // (data key minutes).
        'placeholders' => ['count' => 'count', 'window_minutes' => 'minutes'],
        'storefront' => 'storefront_id',
        'dedupe' => ['key' => 'send_fail_spike:{crossing_at}:{receiver}', 'window' => 0],
        'group' => 'kwtsms.events_group',
    ],
];

// 2026-11-14 05:30 +03: Task M3.2 (B2, P3-62, P3-63, P3-64), task 596. Call request SMS: the staff alert of a call
// request (call_requests.request_created) and of a "buy with one click" request about a product
// (call_requests.request_about_product_created). A and V: core's merged notification schema decides which of them an
// event really has (Ultimate: A; Multi-Vendor: request_created A and V, the product request V only), V only in
// Multi-Vendor. Only while the call_requests add-on is active (owner gate). The form is public: a storefront request
// sends only while the core antibot scenario call_request is on (public_form), and the throttle is one SMS per event,
// receiver and COMPANY in 15 minutes: the key has no requester phone, name or note, so rotating numbers cannot reset
// it, and it is required (a key that cannot be resolved sends nothing). The requester name and notes are never
// mapped, nor is any other value of the form (no storefront path: the visitor could post it); the phone is text only
// ({customer_phone}, from CallRequestSmsData). Default OFF (no switch row). The data class is named as a string, no
// add-on function is called here (SchemaFuncPhpGuardTest).
foreach (['call_requests.request_created', 'call_requests.request_about_product_created'] as $call_event) {
    $schema[$call_event] = [
        'addon' => 'call_requests',
        'receivers' => ['A', 'V'],
        'placeholders' => ['request_id' => 'call_request_data.request_id'],
        'company_id' => 'kwtsms_ctx.company_id',
        'dedupe' => [
            'key' => 'call:{event}:{receiver}:{kwtsms_ctx.company_id}',
            'window' => 900,
            'required' => true,
        ],
        'public_form' => 'call_request',
        'group' => 'kwtsms.templates.group_call_requests',
        'data_class' => \Tygh\Addons\Kwtsms\Events\CallRequestSmsData::class,
    ];
}

// 2026-11-14 09:41 +03: Task M3.3 (B3, P3-63, P3-64, P3-65), task 596. Back in stock: core dispatches
// product.back_in_stock once per company group with the subscribers' E-MAIL addresses only. OrderNotifier hands it
// to Orders\BackInStockNotifier (after the storefront switch), which looks up the registered, active customer accounts
// with a phone (one SELECT per 500 e-mails), caps the fan-out at bis_max_recipients (1..500, below the kwtSMS queue
// limit) and, per recipient, asks THIS declaration for the key bis:<product id>:<user id> (window 86400, required: a
// key that cannot be resolved sends nothing) and claims it: one SMS per account and product per day, however often
// the product is restocked or the e-mail is subscribed. The key holds ids only, no e-mail or phone. The user id is
// not in the core payload: the fan-out adds kwtsms_ctx.user_id per recipient (the add-on's own key). The customer
// order-SMS opt-out does not apply (the customer asked for this SMS). Only the product name and its storefront link
// are placeholders (catalog data, no subscriber data). Default OFF (no switch row). No add-on function is called here
// (SchemaFuncPhpGuardTest).
$schema['product.back_in_stock'] = [
    'receivers' => ['C'],
    'placeholders' => ['product_name' => 'product.name', 'product_url' => 'url'],
    'storefront' => 'storefront_id',
    'dedupe' => [
        'key' => 'bis:{product_id}:{kwtsms_ctx.user_id}',
        'window' => 86400,
        'required' => true,
    ],
    'group' => 'kwtsms.templates.group_products',
];

// 2026-11-12 07:40 +03: Task M3.1 (B1, P3-62, P3-63, P3-64), task 596. RMA return status SMS: one declaration per
// return status, event id rma.status_changed.<status lower case> as core's rma schema names them
// (rma/schemas/notifications/events.post.php:94 reads the same statuses with the same call at the same time). Only
// while
// the rma add-on is active and the core constants and function exist, inside try: a throw gives no rma declaration
// (never a fatal in a schema read). Receivers C, A, V (V only in Multi-Vendor); the data {order_info, return_info} of
// fn_rma_send_notification(). Nothing the customer typed is mapped (the customer return comment is refused by
// EventDeclaration). The customer opt-out applies; a repeat of the same status for the same return is a
// duplicate_event for 24 h and an unresolved key is not sent (required). Default OFF (no switch row). The data class is
// named as a string, no add-on function is called here (SchemaFuncPhpGuardTest).
try {
    if (
        class_exists('\\Tygh\\Registry') && \Tygh\Registry::get('addons.rma.status') === 'A'
        && defined('STATUSES_RETURN')
        && function_exists('fn_get_simple_statuses')
    ) {
        foreach (array_keys((array) fn_get_simple_statuses(STATUSES_RETURN)) as $rma_status) {
            $schema['rma.status_changed.' . strtolower((string) $rma_status)] = [
                'addon' => 'rma',
                'receivers' => ['C', 'A', 'V'],
                'order' => 'order_info',
                'placeholders' => [
                    'return_id' => 'return_info.return_id',
                    'return_status' => 'return_info.status',
                ],
                'dedupe' => [
                    'key' => 'rma:{return_info.return_id}:{return_info.status}:{receiver}',
                    'window' => 86400,
                    'required' => true,
                ],
                'opt_out' => true,
                'group' => 'kwtsms.templates.group_returns',
                'data_class' => \Tygh\Addons\Kwtsms\Events\RmaSmsData::class,
            ];
        }
    }
} catch (\Throwable $e) {
    // One debug row (the add-on's own log service); if even that is not available, the PHP error log.
    try {
        \Tygh\Tygh::$app['kwtsms.logs.debug']->log(
            'debug',
            'rma.statuses_failed',
            'the rma return statuses could not be read, no rma SMS events',
            ['error' => get_class($e)]
        );
    } catch (\Throwable $inner) {
        error_log('kwtsms: the rma return statuses could not be read, no rma SMS events: ' . get_class($e));
    }
}

// 2026-11-14 13:26 +03: Task M3.4 (B4, P3-62, P3-63, P3-64), task 596. Gift certificate SMS to the PURCHASER: one
// declaration per certificate status, event id gift_certificates.gift_certificate.status_changed.<status lower case> as
// core's gift_certificates schema names them (gift_certificates/schemas/notifications/events.post.php reads the same
// statuses with the same call), and gift_certificates.gift_certificate.updated. Only while the gift_certificates
// add-on is active and the core constants and function exist, inside try (a throw gives no declaration, never a fatal).
// Receiver C only. Core's mail goes to the RECIPIENT address typed at checkout; the SMS goes to the purchaser, the
// customer of the first order in certificate_data.order_ids (Events\GiftCertificateSmsData adds phone, language, user,
// storefront and company as kwtsms_ctx.*; a certificate without an order has no phone and ends as no_phone). A postal
// certificate (send_via P) makes core dispatch no event at all, so no row. The certificate code is a bearer secret: it
// is never mapped (EventDeclaration refuses gift_cert_code, sender, recipient and message), it is masked in every
// stored copy through the data class' secret_values, and the dedupe key holds the certificate id only. The throttle is
// one SMS per certificate, event and receiver in 24 h and it is required. Default OFF (no switch row). No add-on
// function is called here (SchemaFuncPhpGuardTest).
try {
    if (
        class_exists('\\Tygh\\Registry') && \Tygh\Registry::get('addons.gift_certificates.status') === 'A'
        && defined('STATUSES_GIFT_CERTIFICATE')
        && function_exists('fn_get_simple_statuses')
    ) {
        $gift_events = [];
        foreach (array_keys((array) fn_get_simple_statuses(STATUSES_GIFT_CERTIFICATE)) as $gift_status) {
            $gift_events[] = 'gift_certificates.gift_certificate.status_changed.' . strtolower((string) $gift_status);
        }
        $gift_events[] = 'gift_certificates.gift_certificate.updated';
        foreach ($gift_events as $gift_event) {
            $schema[$gift_event] = [
                'addon' => 'gift_certificates',
                'receivers' => ['C'],
                'placeholders' => [
                    'gift_cert_id' => 'certificate_data.gift_cert_id',
                    'amount' => 'certificate_data.amount',
                    'gc_status' => 'certificate_data.status',
                ],
                'phone' => 'kwtsms_ctx.phone',
                'lang' => 'kwtsms_ctx.lang',
                'user_id' => 'kwtsms_ctx.user_id',
                'storefront' => 'kwtsms_ctx.storefront_id',
                'company_id' => 'kwtsms_ctx.company_id',
                'dedupe' => [
                    // 2026-11-14 15:41 +03: no {event}: one admin save fires status_changed then updated with the
                    // same new status, the second is a duplicate, so the purchaser gets one SMS; task 596 M3.4
                    // review I1.
                    'key' => 'gc:{certificate_data.gift_cert_id}:{certificate_data.status}:{receiver}',
                    'window' => 86400,
                    'required' => true,
                ],
                'opt_out' => true,
                'group' => 'kwtsms.templates.group_gift_certificates',
                'data_class' => \Tygh\Addons\Kwtsms\Events\GiftCertificateSmsData::class,
            ];
        }
    }
} catch (\Throwable $e) {
    // One debug row (the add-on's own log service); if even that is not available, the PHP error log.
    try {
        \Tygh\Tygh::$app['kwtsms.logs.debug']->log(
            'debug',
            'gift_certificates.statuses_failed',
            'the gift certificate statuses could not be read, no gift certificate SMS events',
            ['error' => get_class($e)]
        );
    } catch (\Throwable $inner) {
        error_log(
            'kwtsms: the gift certificate statuses could not be read, no gift certificate SMS events: ' . get_class($e)
        );
    }
}

return $schema;
