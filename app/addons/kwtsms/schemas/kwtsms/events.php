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
 *            Format: event id => [
 *                'receivers'    => ['C', 'A'],                       // Phase 1 receivers only
 *                'placeholders' => ['name' => 'dotted.path.in.data'], // optional, {name} for the template
 *                'phone'        => 'dotted.path.in.data',             // optional customer phone (else the order's)
 *            ]
 * USAGE:     fn_get_schema('kwtsms', 'events'), read by init.php for EventsSchemaExtender.
 * DEPENDS:   Nothing.
 */

defined('BOOTSTRAP') or die('Access denied');

return [
    'kwtsms.order_placed' => [
        'receivers' => ['C', 'A'],
    ],
];
