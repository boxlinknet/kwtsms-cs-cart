<?php

/*
 * FILE:      app/addons/kwtsms/schemas/menu/menu.post.php
 * CREATED:   2026-10-20 19:54 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Adds Add-ons > kwtSMS to the admin menu, pointing to the dashboard (kwtsms.dashboard).
 * WHY:       PRD 7: the add-on lives under the Add-ons menu. Core hides a menu item whose dispatch the
 *            administrator may not open, so users without view_kwtsms, vendors and storefront-bound
 *            administrators never see it (schemas/permissions/*.post.php decide).
 * USAGE:     Loaded by CS-Cart when it builds the backend menu (fn_get_schema('menu', 'menu')).
 *            The array key is the language variable used as the item title.
 * DEPENDS:   Language variable kwtsms.menu_title (var/langs/{en,ar}/addons/kwtsms.po).
 */

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */
$schema['top']['addons']['items']['kwtsms.menu_title'] = [
    'href'     => 'kwtsms.dashboard',
    'attrs'    => [
        'class' => 'is-addon',
    ],
    'position' => 100,
];

return $schema;
