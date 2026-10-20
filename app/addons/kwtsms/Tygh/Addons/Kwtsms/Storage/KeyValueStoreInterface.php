<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Storage/KeyValueStoreInterface.php
 * CREATED:   2026-10-20 21:18 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Contract of the small string key/value store behind the gateway state (balance, sender list,
 *            coverage, suspension, breaker, API slot timestamp).
 * WHY:       PRD 4.4 keeps this state in CS-Cart's storage_data table. Hiding that behind an interface lets
 *            GatewayState and the services above it run in unit tests on an in-memory store (no database).
 * USAGE:     $store->set('kwtsms_last_sync', (string) time());   $store->get('kwtsms_last_sync'); // '1760...'
 *            $store->get('never_written');                        // null
 *            $store->set('k', '', true);                          // stores an empty string
 *            $store->getFresh('kwtsms_api_slot');                 // bypasses the per-request cache
 * DEPENDS:   Nothing.
 */

namespace Tygh\Addons\Kwtsms\Storage;

interface KeyValueStoreInterface
{
    /**
     * Value of a key, cached for the life of the object. Null means "never written" (or deleted);
     * an empty string means "written empty".
     */
    public function get(string $key): ?string;

    /**
     * Value of a key read from the backing store now, ignoring (and refreshing) the cache. Use it for
     * state that other requests or processes change while this one runs (suspension, breaker, the API
     * slot timestamp).
     */
    public function getFresh(string $key): ?string;

    /**
     * Stores a value. An empty value is ignored unless $allow_empty is true (an accidental '' must not
     * wipe a key); delete() is the way to remove one.
     */
    public function set(string $key, string $value, bool $allow_empty = false): void;

    /**
     * Removes a key. Removing a missing key is not an error.
     */
    public function delete(string $key): void;
}
