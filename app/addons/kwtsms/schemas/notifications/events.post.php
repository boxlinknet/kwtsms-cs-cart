<?php

/*
 * FILE:      app/addons/kwtsms/schemas/notifications/events.post.php
 * CREATED:   2026-10-21 01:52 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Declares the add-on's own notification event kwtsms.order_placed ("New order placed") in group orders for
 *            the customer (C) and the admin (A), without any transport.
 * WHY:       PRD 4.1: core has no event that fires once when an order is placed (see Orders\NewOrderDetector). The
 *            event carries no transport here: the SMS transport is attached by EventsSchemaExtender (init.php,
 *            event.events_schema extension) together with every status event, so this file never references an
 *            add-on class and a disabled add-on leaves nothing behind in the schema. With no data_provider the
 *            dispatcher uses BaseDataProvider: the event data is the order_info the hooks pass, nothing heavier.
 * USAGE:     Loaded by fn_get_schema('notifications', 'events') while the add-on is active.
 * DEPENDS:   Language variable kwtsms.event.order_placed (var/langs/{en,ar}/addons/kwtsms.po).
 */

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */
$schema['kwtsms.order_placed'] = [
    'group' => 'orders',
    'name' => [
        'template' => 'kwtsms.event.order_placed',
        'params' => [],
    ],
    'receivers' => [
        'C' => [],
        'A' => [],
    ],
];
// 2026-10-22 22:23 +03: Task A8 (SafatMall F4, R9), task 596. The vendor of a child order (Multi-Vendor only) is a
// receiver too.
if (fn_allowed_for('MULTIVENDOR')) {
    $schema['kwtsms.order_placed']['receivers']['V'] = [];
}

// 2026-10-22 19:08 +03: Task A6 (SafatMall F5 part, P2-7), task 596. The administrator's registration alert: a
// customer registered on
// the storefront or at checkout. Group profile, receiver A, no transport here (EventsSchemaExtender attaches the SMS
// one
// from schemas/kwtsms/events.php). Dispatched by Profiles\ProfileHooks.
$schema['kwtsms.customer_registered'] = [
    'group' => 'profile',
    'name' => [
        'template' => 'kwtsms.event.customer_registered',
        'params' => [],
    ],
    'receivers' => [
        'A' => [],
    ],
];

// 2026-10-22 21:28 +03: Task A7 (SafatMall F5 part, P2-10), task 596. The low-stock SMS: a product crossed the
// low-stock threshold. Group products, receiver A, and V in Multi-Vendor; no transport here (EventsSchemaExtender
// attaches the SMS one from schemas/kwtsms/events.php). Dispatched by Orders\LowStockCollector.
$schema['kwtsms.low_stock'] = [
    'group' => 'products',
    'name' => [
        'template' => 'kwtsms.event.low_stock',
        'params' => [],
    ],
    'receivers' => [
        'A' => [],
    ],
];
if (fn_allowed_for('MULTIVENDOR')) {
    $schema['kwtsms.low_stock']['receivers']['V'] = [];
}

// 2026-10-23 00:14 +03: Task A9 (SafatMall F5 part), task 596. The low kwtSMS balance SMS: the balance crossed the
// low-balance threshold. Group kwtsms.events_group (heading "kwtSMS"), receiver A, no transport here
// (EventsSchemaExtender attaches the SMS one from schemas/kwtsms/events.php). Dispatched by Alerts\LowBalanceSms.
$schema['kwtsms.low_balance'] = [
    'group' => 'kwtsms.events_group',
    'name' => [
        'template' => 'kwtsms.event.low_balance',
        'params' => [],
    ],
    'receivers' => [
        'A' => [],
    ],
];

return $schema;
