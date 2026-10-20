<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Log/BulkLogStoreInterface.php
 * CREATED:   2026-10-21 01:00 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Optional bulk operations of a log store: many rows in one INSERT, and the abuse-cap counts of many
 *            phone numbers in one grouped query.
 * WHY:       Task 1.6 review (N+1): a send to 1000 numbers made 2000 cap counts and 1000 attempt inserts. A
 *            separate interface keeps LogStoreInterface (and every test double of it) unchanged; AttemptLog uses
 *            these methods when the store has them and falls back to one statement per row otherwise.
 * USAGE:     if ($store instanceof BulkLogStoreInterface) {
 *                $first_id = $store->insertMany(Schema::ATTEMPTS, $rows);   // ids are $first_id .. + count - 1
 *                $counts = $store->countAttemptsByPhone($phones, $day_from, $hour_from, $outcomes, $sources);
 *                // ['96550000001' => ['hour' => 1, 'day' => 3, 'day_all' => 4], ...] (phones without rows are absent)
 *            }
 * DEPENDS:   Log\Schema.
 */

namespace Tygh\Addons\Kwtsms\Log;

interface BulkLogStoreInterface
{
    /**
     * Inserts rows that all have the same keys in one statement (the caller passes rows of RowCleaner::clean()).
     *
     * @param array<int,array<string,mixed>> $rows
     *
     * @return int The primary key of the first row; the others follow it by one each
     *
     * @throws \RuntimeException When the rows could not be written
     */
    public function insertMany(string $table, array $rows): int;

    /**
     * Attempts per phone since $day_from with one of $outcomes: "hour" counts those since $hour_from whose source
     * is in $sources, "day" those whose source is in $sources, "day_all" every source.
     *
     * @param string[] $phones
     * @param string[] $outcomes
     * @param string[] $sources
     *
     * @return array<string,array{hour:int,day:int,day_all:int}>
     *
     * @throws \RuntimeException When the counts cannot be read (never returned as zero)
     */
    public function countAttemptsByPhone(
        array $phones,
        int $day_from,
        int $hour_from,
        array $outcomes,
        array $sources
    ): array;
}
