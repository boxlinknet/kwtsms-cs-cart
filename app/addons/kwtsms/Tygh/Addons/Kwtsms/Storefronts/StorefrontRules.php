<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Storefronts/StorefrontRules.php
 * CREATED:   2026-10-22 14:53 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The per-storefront switches: whether a storefront has turned an event and receiver OFF for itself, and
 *            the list of storefronts for the admin pages.
 * WHY:       Phase 2 ruling P2-1 (SafatMall A0): the core switch on Administration > Notifications stays the master
 *            ON/OFF for all storefronts; a storefront can only turn an event OFF for itself. No row follows the
 *            master. A row with enabled = 'N' is an override; there is never a row with 'Y'.
 * USAGE:     $rules = Tygh::$app['kwtsms.storefront_rules'];
 *            $rules->isSwitchedOff(2, 'order.status_changed.c', 'C')   // one PK SELECT per key, cached per request
 *            $rules->setSwitch(2, 'order.status_changed.c', 'C', false) // storefront 2 turns it OFF (row 'N')
 *            $rules->setSwitch(2, 'order.status_changed.c', 'C', true)  // follows the master again (row deleted)
 *            $rules->storefronts()                                      // [storefront_id => name], any status
 *            Storefront 0 (all storefronts / no storefront) is never switched off and never gets a row.
 *            The four database touches are protected methods, so the unit test replaces them.
 * DEPENDS:   Table kwtsms_storefront_switches (schema 1.1.0); CS-Cart db_* functions, table ?:storefronts.
 */

namespace Tygh\Addons\Kwtsms\Storefronts;

class StorefrontRules
{
    /** @var array<string,bool> "storefront|event|receiver" => switched off (cache of this request) */
    private $cache = [];

    /**
     * True when the storefront turned this event and receiver OFF for itself.
     */
    public function isSwitchedOff(int $storefront_id, string $event_id, string $receiver): bool
    {
        if ($storefront_id <= 0 || $event_id === '' || $receiver === '') {
            return false;
        }
        $key = $storefront_id . '|' . $event_id . '|' . $receiver;
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $this->readEnabled($storefront_id, $event_id, $receiver) === 'N';
        }

        return $this->cache[$key];
    }

    /**
     * $follow_master true deletes the row (the storefront follows the master); false writes enabled = 'N'.
     */
    public function setSwitch(int $storefront_id, string $event_id, string $receiver, bool $follow_master): void
    {
        if ($storefront_id <= 0 || $event_id === '' || $receiver === '') {
            return;
        }
        if ($follow_master) {
            $this->deleteRow($storefront_id, $event_id, $receiver);
        } else {
            $this->writeOff($storefront_id, $event_id, $receiver);
        }
        unset($this->cache[$storefront_id . '|' . $event_id . '|' . $receiver]);
    }

    /**
     * Storefront id => name, every storefront whatever its status.
     *
     * @return array<int,string>
     */
    public function storefronts(): array
    {
        $out = [];
        foreach ($this->loadStorefronts() as $id => $name) {
            $out[(int) $id] = (string) $name;
        }

        return $out;
    }

    // ------------------------------------------------------------------ the store (replaced by tests)

    protected function readEnabled(int $storefront_id, string $event_id, string $receiver): string
    {
        return (string) db_get_field(
            'SELECT enabled FROM ?:kwtsms_storefront_switches WHERE storefront_id = ?i AND event_id = ?s'
            . ' AND receiver = ?s',
            $storefront_id,
            $event_id,
            $receiver
        );
    }

    protected function deleteRow(int $storefront_id, string $event_id, string $receiver): void
    {
        db_query(
            'DELETE FROM ?:kwtsms_storefront_switches WHERE storefront_id = ?i AND event_id = ?s AND receiver = ?s',
            $storefront_id,
            $event_id,
            $receiver
        );
    }

    protected function writeOff(int $storefront_id, string $event_id, string $receiver): void
    {
        db_query('REPLACE INTO ?:kwtsms_storefront_switches ?e', [
            'storefront_id' => $storefront_id,
            'event_id' => $event_id,
            'receiver' => $receiver,
            'enabled' => 'N',
            'updated_at' => time(),
        ]);
    }

    /**
     * @return array<int,string>
     */
    protected function loadStorefronts(): array
    {
        return (array) db_get_hash_single_array(
            'SELECT storefront_id, name FROM ?:storefronts ORDER BY storefront_id',
            ['storefront_id', 'name']
        );
    }
}
