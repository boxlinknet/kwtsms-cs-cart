<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Log/LogStoreInterface.php
 * CREATED:   2026-10-20 21:25 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Contract of the storage behind the three logs (messages, attempts, debug): insert, find, a
 *            filtered newest-first list, count, and the two deletions the product needs.
 * WHY:       MessageLog, AttemptLog and DebugLog hold the rules (redaction, caps, 4-byte stripping); the
 *            store only moves rows. Behind an interface their unit tests run on an in-memory store, and
 *            the Logs page (a later task) and the retention cron use the same list/count/delete calls.
 * USAGE:     $id = $store->insert(Schema::ATTEMPTS, ['created_at' => time(), 'phone' => '96598765432', ...]);
 *            $rows = $store->select(Schema::MESSAGES, ['status' => 'failed', 'created_from' => $t], 50, 0);
 *            $n = $store->count(Schema::ATTEMPTS, ['phone' => $phone, 'created_from' => time() - 3600]);
 *            $store->deleteOlderThan(Schema::DEBUG, time() - 30 * 86400);   // chunks of 5000, returns the total
 *            Filters (anything else throws InvalidArgumentException): created_from / created_to (inclusive,
 *            on created_at), "order" (order_id OR parent_order_id), and equality or IN (a list value) on the
 *            columns Schema::filters() names for the table. An empty list matches nothing.
 * DEPENDS:   Log\Schema.
 */

namespace Tygh\Addons\Kwtsms\Log;

interface LogStoreInterface
{
    /** Rows deleted per statement by deleteOlderThan() (PRD 6.8: partial clears delete in chunks of 5,000). */
    public const DELETE_CHUNK = 5000;

    /**
     * 2026-10-21 05:45 +03: the audit row of a Logs page clear (PRD 6.8): a debug row with this action, written after
     * the delete. Clear never deletes it, and retention under 365 days does not either: deleteOlderThan() and
     * PrunableLogStoreInterface::pruneChunk() on the debug table keep an audit row until it is AUDIT_KEEP_SECONDS old,
     * whatever the cut-off asked for (Task 1.14 carry), task 596.
     */
    public const AUDIT_ACTION = 'logs.cleared';

    /** 2026-10-21 05:45 +03: an audit row is kept at least this long (365 days), task 596. */
    public const AUDIT_KEEP_SECONDS = 31536000;

    /**
     * Inserts one row. Keys that are not columns of the table (and the primary key) are ignored.
     *
     * @param string               $table Schema::MESSAGES, ATTEMPTS or DEBUG
     * @param array<string, mixed> $row
     *
     * @return int The new primary key
     */
    public function insert(string $table, array $row): int;

    /**
     * One row with every column, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $table, int $id): ?array;

    /**
     * Rows newest first (created_at DESC, then primary key DESC). Never includes the columns of
     * Schema::hidden() (raw request and response).
     *
     * @param array<string, mixed> $filters
     * @param int                  $limit   Clamped to 1..1000
     * @param int                  $offset  Clamped to 0 or more
     *
     * @return array<int, array<string, mixed>>
     */
    public function select(string $table, array $filters = [], int $limit = 50, int $offset = 0): array;

    /**
     * @param array<string, mixed> $filters Same keys as select()
     *
     * @throws \RuntimeException When the count cannot be read (2026-10-20 22:17 +03, task 596): never 0
     */
    public function count(string $table, array $filters = []): int;

    /**
     * Deletes the given rows only.
     *
     * @param int[] $ids
     *
     * @return int Rows deleted
     */
    public function deleteByIds(string $table, array $ids): int;

    /**
     * Deletes every row with created_at below $timestamp, oldest first, $chunk rows per statement.
     * 2026-10-21 05:45 +03: on the debug table an AUDIT_ACTION row younger than AUDIT_KEEP_SECONDS is kept, Task 1.14,
     * task 596.
     *
     * @return int Rows deleted in total
     */
    public function deleteOlderThan(string $table, int $timestamp, int $chunk = self::DELETE_CHUNK): int;
}
