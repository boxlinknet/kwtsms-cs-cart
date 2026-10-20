<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Storage/StorageDataStore.php
 * CREATED:   2026-10-20 21:18 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      KeyValueStoreInterface on CS-Cart's storage_data table (fn_get_storage_data and
 *            fn_set_storage_data), with a per-object cache that tells null (never written) from ''.
 * WHY:       PRD 4.4 and 8: gateway state lives in storage_data keys named kwtsms_*. The core getter
 *            returns '' for a missing key and its Registry cache is shared by name; this class keeps its
 *            own cache so a missing key is null and so getFresh() can reach the row another process wrote.
 *            The uninstall query of addon.xml removes every key that starts with "kwtsms_".
 * USAGE:     $store = new StorageDataStore();   (see KeyValueStoreInterface; wired in Task 1.6)
 * DEPENDS:   CS-Cart core: fn_get_storage_data(), fn_set_storage_data(), db_get_row(), db_query().
 */

namespace Tygh\Addons\Kwtsms\Storage;

class StorageDataStore implements KeyValueStoreInterface
{
    /** @var array<string, string|null> key => value (null = known to be missing) */
    private $cache = [];

    public function get(string $key): ?string
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        // Default null: a missing key comes back as null instead of ''.
        $value = fn_get_storage_data($key, null);
        $this->cache[$key] = $value === null ? null : (string) $value;

        return $this->cache[$key];
    }

    public function getFresh(string $key): ?string
    {
        $row = db_get_row('SELECT data FROM ?:storage_data WHERE data_key = ?s', $key);
        $this->cache[$key] = (is_array($row) && array_key_exists('data', $row) && $row['data'] !== null)
            ? (string) $row['data']
            : null;

        return $this->cache[$key];
    }

    public function set(string $key, string $value, bool $allow_empty = false): void
    {
        // fn_set_storage_data() deletes the row when the value is empty and allow_empty is false;
        // this contract says an empty value is ignored instead.
        if ($value === '' && !$allow_empty) {
            return;
        }

        fn_set_storage_data($key, $value, true);
        $this->cache[$key] = $value;
    }

    public function delete(string $key): void
    {
        // Empty data with allow_empty false makes the core delete the row and clear its own cache.
        fn_set_storage_data($key, '', false);
        $this->cache[$key] = null;
    }
}
