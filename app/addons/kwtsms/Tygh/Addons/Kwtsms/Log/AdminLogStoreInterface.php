<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Log/AdminLogStoreInterface.php
 * CREATED:   2026-10-21 05:45 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The operations of the Logs page on a log store: one sorted page, the search horizon, the counts and
 *            deletes
 *            of a clear (chunked or TRUNCATE), the attempts of an order and its parent (the order SMS tab), and the SQL
 *            text of those statements for EXPLAIN / ANALYZE.
 * WHY:       PRD 6.8 and 10, Task 1.14. A separate interface keeps LogStoreInterface (and every test double of it)
 *            unchanged, the way BulkLogStoreInterface and PrunableLogStoreInterface do. Filters are those of
 *            LogStoreInterface plus "text" (a LIKE on Schema::textColumns(), the pattern escaped by the store) and,
 *            on the
 *            messages table, "phone" (through the indexed kwtsms_attempts.phone).
 *            The audit rows of a clear (LogStoreInterface::AUDIT_ACTION on the debug table) are immune to every clear:
 *            countClearable() and deleteChunk() never count or delete one, truncate() puts them back.
 *            The search horizon (PRD 6.8 performance, ruling R35): a filter that no index orders by time (a vendor, a
 *            storefront, Test/Live, text, a list of outcomes, ...) is answered from the newest HORIZON_ROWS rows of the
 *            period only, so no list or count reads more than that many rows; the caller tells the administrator where
 *            the search stopped.
 * USAGE:     $rows = $store->page(Schema::ATTEMPTS, ['outcome' => ['failed']], 50, 0, false);
 *            $from = $store->horizon(Schema::ATTEMPTS, $created_from, $created_to,
 *            AdminLogStoreInterface::HORIZON_ROWS);
 *            $n = $store->countClearable(Schema::DEBUG, ['created_to' => $cutoff]);   // audit rows not counted
 *            $store->deleteChunk(Schema::DEBUG, ['created_to' => $cutoff], 5000);    // one DELETE, oldest first
 *            $store->truncate(Schema::MESSAGES);                                      // TRUNCATE TABLE
 *            $store->attemptsForOrder(72, 71, 200);
 * DEPENDS:   Log\Schema, Log\LogStoreInterface.
 */

namespace Tygh\Addons\Kwtsms\Log;

interface AdminLogStoreInterface
{
    /** Rows a list or count without an ordering index may read at most (the search horizon). */
    public const HORIZON_ROWS = 50000;

    /**
     * One page of rows in created_at order, then the primary key in the same direction. Never the hidden columns.
     *
     * @param array<string,mixed> $filters
     *
     * @return array<int,array<string,mixed>>
     */
    public function page(string $table, array $filters, int $limit, int $offset, bool $ascending = false): array;

    /**
     * The created_at of the $rows-th newest row whose created_at is within [$from, $to] (null = open end), read from
     * the
     * created_at index only; null when the period holds no more than $rows rows (nothing to cut).
     */
    public function horizon(string $table, ?int $from, ?int $to, int $rows): ?int;

    /**
     * Rows a clear with these filters deletes (on the debug table never an audit row).
     *
     * @param array<string,mixed> $filters
     */
    public function countClearable(string $table, array $filters): int;

    /**
     * One DELETE of at most $chunk rows that match the filters, oldest first (on the debug table never an audit row).
     *
     * @param array<string,mixed> $filters
     *
     * @return int Rows deleted; fewer than $chunk means none is left
     */
    public function deleteChunk(string $table, array $filters, int $chunk = LogStoreInterface::DELETE_CHUNK): int;

    /**
     * Empties the table with TRUNCATE TABLE. On the debug table the audit rows are read first and written back with
     * their ids afterwards.
     *
     * @return int Rows the table held before (audit rows of the debug table not included)
     *
     * @throws \RuntimeException When the audit rows could not be written back (they are then in the PHP error log)
     */
    public function truncate(string $table): int;

    /**
     * Attempts of an order and of its parent, newest first: order_id is the order or its parent, or parent_order_id is
     * the order (the children of a checkout).
     *
     * @return array<int,array<string,mixed>>
     */
    public function attemptsForOrder(int $order_id, int $parent_id, int $limit): array;

    /**
     * The SQL text of page(), for EXPLAIN / ANALYZE only.
     *
     * @param array<string,mixed> $filters
     */
    public function pageSql(string $table, array $filters, int $limit, int $offset, bool $ascending = false): string;
}
