<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Log/DbLogStore.php
 * CREATED:   2026-10-20 21:29 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      LogStoreInterface on the real tables kwtsms_messages, kwtsms_attempts and kwtsms_debug_log
 *            through CS-Cart's database layer.
 * WHY:       The three logs grow without bound (messages are kept until cleared), so every list is
 *            newest-first on the created_at index with LIMIT, every filter comes from a fixed key map
 *            (Log\Schema) with typed placeholders, the list never selects the raw request and response,
 *            and deletes are either by id or by age in chunks of 5000 (PRD 6.8, 10).
 * USAGE:     $store = new DbLogStore();  (wired in Task 1.6; used by MessageLog, AttemptLog, DebugLog)
 *            2026-10-21 01:00 +03: also BulkLogStoreInterface: insertMany() (one INSERT, ids consecutive) and
 *            countAttemptsByPhone() (the abuse-cap counts of a whole send in one grouped query on the
 *            (phone, created_at) index; countAttemptsByPhoneSql() is its text for EXPLAIN), Task 1.6 fix round 1,
 *            task 596.
 *            $store->select(Schema::MESSAGES, ['status' => 'failed', 'created_from' => $t], 50, 0);
 *            $store->selectSql(...) and countSql(...) return the final SQL text, for EXPLAIN/ANALYZE only.
 * DEPENDS:   CS-Cart core: db_query(), db_get_array(), db_get_row(), db_get_field(), db_quote(),
 *            Tygh\Registry (config.table_prefix); Log\Schema, Log\LogStoreInterface.
 */

namespace Tygh\Addons\Kwtsms\Log;

use Tygh\Registry;

class DbLogStore implements LogStoreInterface, BulkLogStoreInterface
{
    /** deleteOlderThan() stops after this many statements; the caller can call again. */
    private const MAX_CHUNKS = 1000;

    /** Rows per multi-row INSERT and phones per grouped count (2026-10-21 01:00 +03, task 596). */
    private const BULK_CHUNK = 500;

    public function insert(string $table, array $row): int
    {
        $name = Schema::table($table);
        $clean = [];
        foreach (array_intersect_key($row, Schema::columns($table)) as $column => $value) {
            if (is_bool($value)) {
                $value = (int) $value;
            } elseif (is_float($value) && !is_finite($value)) {
                $value = 0;
            } elseif (is_array($value) || is_object($value)) {
                continue;
            }
            $clean[$column] = $value;
        }

        $id = $clean === []
            ? db_query('INSERT INTO ?:' . $name . ' () VALUES ()')
            : db_query('INSERT INTO ?:' . $name . ' ?e', $clean);
        if (!$id) {
            throw new \RuntimeException('Could not insert into ' . $name);
        }

        return (int) $id;
    }

    /**
     * 2026-10-21 01:00 +03: one statement for many rows (Task 1.6 review, N+1), task 596. Rows longer than
     * BULK_CHUNK go in several statements; the ids are consecutive within one statement (innodb_autoinc_lock_mode
     * 0 or 1, and simple inserts in mode 2), so a caller that needs every id passes at most BULK_CHUNK rows.
     *
     * @throws \RuntimeException
     */
    public function insertMany(string $table, array $rows): int
    {
        $name = Schema::table($table);
        $columns = Schema::columns($table);
        $first = 0;
        foreach (array_chunk(array_values($rows), self::BULK_CHUNK) as $chunk) {
            $clean = [];
            foreach ($chunk as $row) {
                $values = [];
                foreach ($columns as $column => $definition) {
                    $value = $row[$column] ?? null;
                    if (is_bool($value)) {
                        $value = (int) $value;
                    } elseif ((is_float($value) && !is_finite($value)) || is_array($value) || is_object($value)) {
                        $value = 0;
                    }
                    $values[$column] = $value;
                }
                $clean[] = $values;
            }
            $id = (int) db_query('INSERT INTO ?:' . $name . ' ?m', $clean);
            if ($id < 1) {
                throw new \RuntimeException('Could not insert into ' . $name);
            }
            $first = $first === 0 ? $id : $first;
        }

        return $first;
    }

    /**
     * 2026-10-21 01:00 +03: the cap counts of every number of a send in one query per 500 numbers (Task 1.6 review,
     * N+1), task 596.
     *
     * @throws \RuntimeException
     */
    public function countAttemptsByPhone(
        array $phones,
        int $day_from,
        int $hour_from,
        array $outcomes,
        array $sources
    ): array {
        $out = [];
        $phones = array_values(array_unique(array_map('strval', $phones)));
        foreach (array_chunk($phones, self::BULK_CHUNK) as $chunk) {
            $rows = $this->fetchRows($this->countByPhoneArguments($chunk, $day_from, $hour_from, $outcomes, $sources));
            if (!is_array($rows)) {
                throw new \RuntimeException('Could not count the rows of ' . Schema::table(Schema::ATTEMPTS));
            }
            foreach ($rows as $row) {
                $out[(string) $row['phone']] = [
                    'hour' => (int) $row['hour_n'],
                    'day' => (int) $row['day_n'],
                    'day_all' => (int) $row['day_all'],
                ];
            }
        }

        return $out;
    }

    /**
     * The exact query of countAttemptsByPhone() for one chunk, as SQL text (EXPLAIN / ANALYZE only).
     *
     * @param string[] $phones
     * @param string[] $outcomes
     * @param string[] $sources
     */
    public function countAttemptsByPhoneSql(
        array $phones,
        int $day_from,
        int $hour_from,
        array $outcomes,
        array $sources
    ): string {
        $args = $this->countByPhoneArguments($phones, $day_from, $hour_from, $outcomes, $sources);

        return $this->prefixed(db_quote(...$args));
    }

    /**
     * @param string[] $phones
     * @param string[] $outcomes
     * @param string[] $sources
     *
     * @return array<int,mixed>
     */
    private function countByPhoneArguments(
        array $phones,
        int $day_from,
        int $hour_from,
        array $outcomes,
        array $sources
    ): array {
        $sources = $sources === [] ? [''] : array_values(array_map('strval', $sources));

        return [
            'SELECT `phone`,'
                . ' SUM(CASE WHEN `created_at` >= ?i AND `source` IN (?a) THEN 1 ELSE 0 END) AS hour_n,'
                . ' SUM(CASE WHEN `source` IN (?a) THEN 1 ELSE 0 END) AS day_n,'
                . ' COUNT(*) AS day_all'
                . ' FROM ?:' . Schema::table(Schema::ATTEMPTS)
                . ' WHERE `phone` IN (?a) AND `created_at` >= ?i AND `outcome` IN (?a) GROUP BY `phone`',
            $hour_from,
            $sources,
            $sources,
            $phones === [] ? [''] : $phones,
            $day_from,
            $outcomes === [] ? [''] : array_values(array_map('strval', $outcomes)),
        ];
    }

    public function find(string $table, int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = db_get_row(
            'SELECT * FROM ?:' . Schema::table($table) . ' WHERE `' . Schema::pk($table) . '` = ?i',
            $id
        );

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * 2026-10-20 22:17 +03: a result that is not an array of rows throws instead of reading as "no rows"
     * (Task 1.4 fix round 1, task 596).
     *
     * @throws \RuntimeException
     */
    public function select(string $table, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $rows = $this->fetchRows($this->selectArguments($table, $filters, $limit, $offset));
        if (!is_array($rows)) {
            throw new \RuntimeException('Could not read the rows of ' . Schema::table($table));
        }

        return $rows;
    }

    /**
     * 2026-10-20 22:17 +03: a result that is not a number throws instead of reading as 0. A failed count read
     * as 0 would make the abuse caps (AttemptLog::countRecent) skip themselves (Task 1.4 fix round 1, task 596).
     *
     * @throws \RuntimeException
     */
    public function count(string $table, array $filters = []): int
    {
        $count = $this->fetchField($this->countArguments($table, $filters));
        if (!is_int($count) && !(is_string($count) && ctype_digit($count))) {
            throw new \RuntimeException('Could not count the rows of ' . Schema::table($table));
        }

        return (int) $count;
    }

    /**
     * The one place a list query is run (a test replaces it).
     *
     * @param array<int, mixed> $query Pattern first, then the values for its placeholders
     *
     * @return mixed
     */
    protected function fetchRows(array $query)
    {
        return db_get_array(array_shift($query), ...$query);
    }

    /**
     * The one place a single-value query is run (a test replaces it).
     *
     * @param array<int, mixed> $query Pattern first, then the values for its placeholders
     *
     * @return mixed
     */
    protected function fetchField(array $query)
    {
        return db_get_field(array_shift($query), ...$query);
    }

    public function deleteByIds(string $table, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === []) {
            return 0;
        }

        return (int) db_query(
            'DELETE FROM ?:' . Schema::table($table) . ' WHERE `' . Schema::pk($table) . '` IN (?n)',
            $ids
        );
    }

    public function deleteOlderThan(string $table, int $timestamp, int $chunk = self::DELETE_CHUNK): int
    {
        $name = Schema::table($table);
        $chunk = max(1, $chunk);
        $total = 0;
        for ($i = 0; $i < self::MAX_CHUNKS; $i++) {
            $deleted = (int) db_query(
                'DELETE FROM ?:' . $name . ' WHERE `created_at` < ?i ORDER BY `created_at` ASC LIMIT ?i',
                $timestamp,
                $chunk
            );
            $total += $deleted;
            if ($deleted < $chunk) {
                break;
            }
        }

        return $total;
    }

    /**
     * The exact list query select() runs, as SQL text with the table prefix applied. For EXPLAIN and
     * ANALYZE in tests and diagnostics; never executed from here.
     *
     * @param array<string, mixed> $filters
     */
    public function selectSql(string $table, array $filters = [], int $limit = 50, int $offset = 0): string
    {
        $args = $this->selectArguments($table, $filters, $limit, $offset);

        return $this->prefixed(db_quote(...$args));
    }

    /**
     * The exact query count() runs, as SQL text. See selectSql().
     *
     * @param array<string, mixed> $filters
     */
    public function countSql(string $table, array $filters = []): string
    {
        return $this->prefixed(db_quote(...$this->countArguments($table, $filters)));
    }

    /**
     * Pattern first, then the values for its placeholders.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<int, mixed>
     */
    private function selectArguments(string $table, array $filters, int $limit, int $offset): array
    {
        $pk = Schema::pk($table);
        $columns = array_diff(
            array_merge([$pk], array_keys(Schema::columns($table))),
            Schema::hidden($table)
        );
        $list = '`' . implode('`, `', $columns) . '`';

        // 2026-10-20 21:30 +03: with no filter, or only a created_at range, the page is read straight off
        // idx_created_at (backwards, no filesort). Left to itself the optimizer scans the table and sorts it
        // up to a few thousand rows (ANALYZE FORMAT=JSON, task 596); any other filter keeps the optimizer's
        // choice, because (status, created_at), (company_id, created_at) and friends serve those.
        $hint = array_diff(array_keys($filters), ['created_from', 'created_to']) === []
            ? ' FORCE INDEX (`idx_created_at`)'
            : '';

        return [
            'SELECT ?p FROM ?:' . Schema::table($table) . $hint . ' WHERE ?p ORDER BY `created_at` DESC, `' . $pk
                . '` DESC LIMIT ?i, ?i',
            $list,
            $this->where($table, $filters),
            max(0, $offset),
            max(1, min(1000, $limit)),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, mixed>
     */
    private function countArguments(string $table, array $filters): array
    {
        return [
            'SELECT COUNT(*) FROM ?:' . Schema::table($table) . ' WHERE ?p',
            $this->where($table, $filters),
        ];
    }

    private function prefixed(string $sql): string
    {
        return str_replace('?:', (string) Registry::get('config.table_prefix'), $sql);
    }

    /**
     * WHERE condition from the fixed filter map. Column names come from Schema, values go through typed
     * placeholders (?i, ?s, ?n, ?a); an unknown key throws.
     *
     * @param array<string, mixed> $filters
     *
     * @throws \InvalidArgumentException
     */
    private function where(string $table, array $filters): string
    {
        Schema::assertFilters($table, $filters);
        $columns = Schema::columns($table);
        $parts = [];
        foreach ($filters as $key => $value) {
            $key = (string) $key;
            if ($key === 'created_from') {
                $parts[] = db_quote('`created_at` >= ?i', $value);
            } elseif ($key === 'created_to') {
                $parts[] = db_quote('`created_at` <= ?i', $value);
            } elseif ($key === 'order') {
                $parts[] = db_quote('(`order_id` = ?i OR `parent_order_id` = ?i)', $value, $value);
            } elseif (is_array($value)) {
                $parts[] = $value === []
                    ? '0'
                    : ($columns[$key][0] === 'i'
                        ? db_quote('`' . $key . '` IN (?n)', array_values($value))
                        : db_quote('`' . $key . '` IN (?a)', array_map('strval', array_values($value))));
            } else {
                $parts[] = $columns[$key][0] === 'i'
                    ? db_quote('`' . $key . '` = ?i', $value)
                    : db_quote('`' . $key . '` = ?s', (string) $value);
            }
        }

        return $parts === [] ? '1' : implode(' AND ', $parts);
    }
}
