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

return $schema;
