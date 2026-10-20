<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Log/PrunableLogStoreInterface.php
 * CREATED:   2026-10-21 01:56 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Optional capability of a log store: delete ONE chunk of rows older than a time, so a caller can watch
 *            the clock between chunks.
 * WHY:       Task 1.7 fix round 1 item 5. The daily prune_logs job must stop after 120 s and go on at the next cron
 *            run, so the first prune of a very large table cannot outlive the 600 s cron lock;
 *            LogStoreInterface::deleteOlderThan() loops until the table is done. A separate interface keeps
 *            LogStoreInterface (and every double of it) unchanged, the way BulkLogStoreInterface does.
 * USAGE:     if ($store instanceof PrunableLogStoreInterface) {
 *                do {
 *                    $n = $store->pruneChunk(Schema::DEBUG, $cutoff, 5000);   // rows deleted by one statement
 *                } while ($n >= 5000 && microtime(true) < $deadline);
 *            }
 * DEPENDS:   Log\Schema.
 */

namespace Tygh\Addons\Kwtsms\Log;

interface PrunableLogStoreInterface
{
    /**
     * One DELETE of at most $chunk rows with created_at below $timestamp, oldest first.
     *
     * @return int Rows deleted; fewer than $chunk means nothing older is left
     */
    public function pruneChunk(string $table, int $timestamp, int $chunk = LogStoreInterface::DELETE_CHUNK): int;
}
