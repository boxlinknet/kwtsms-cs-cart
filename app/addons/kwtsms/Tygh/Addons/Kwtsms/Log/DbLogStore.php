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
 *            2026-10-21 01:00 +03: also BulkLogStoreInterface: insertMany() (one INSERT per 500 rows; it returns
 *            every id: the first id of each INSERT plus k times @@auto_increment_increment, 2026-10-21 01:43 +03;
 *            "ids consecutive" was wrong, fixed 2026-10-21 08:35 +03, cleanup item 8) and
 *            countAttemptsByPhone() (the abuse-cap counts of a whole send in one grouped query on the
 *            (phone, created_at) index; countAttemptsByPhoneSql() is its text for EXPLAIN), Task 1.6 fix round 1,
 *            task 596.
 *            2026-10-21 12:02 +03: updateMany() writes rows in place (the cap reservations, ruling R41), task 596.
 *            $store->select(Schema::MESSAGES, ['status' => 'failed', 'created_from' => $t], 50, 0);
 *            $store->selectSql(...) and countSql(...) return the final SQL text, for EXPLAIN/ANALYZE only.
 *            2026-10-21 05:46 +03: also AdminLogStoreInterface (the Logs page and the order SMS tab: page(), horizon(),
 *            countClearable(), deleteChunk(), truncate(), attemptsForOrder() and their SQL text), the "text" and
 *            messages "phone" filters, and the audit rows of a clear kept by pruneChunk() / deleteOlderThan() on the
 *            debug table until they are 365 days old; new DbLogStore($clock) takes a clock for that age (tests),
 *            Task 1.14, task 596.
 * DEPENDS:   CS-Cart core: db_query(), db_get_array(), db_get_row(), db_get_field(), db_quote(),
 *            Tygh\Registry (config.table_prefix); Log\Schema, Log\LogStoreInterface.
 */

namespace Tygh\Addons\Kwtsms\Log;

use Tygh\Registry;

// 2026-10-21 05:46 +03: AdminLogStoreInterface added (Logs page, order SMS tab), Task 1.14, task 596.
class DbLogStore implements LogStoreInterface, BulkLogStoreInterface, PrunableLogStoreInterface, AdminLogStoreInterface
{
    /** deleteOlderThan() stops after this many statements; the caller can call again. */
    private const MAX_CHUNKS = 1000;

    /** Rows per multi-row INSERT and phones per grouped count (2026-10-21 01:00 +03, task 596). */
    private const BULK_CHUNK = 500;

    /** @var int|null @@auto_increment_increment of this connection (2026-10-21 01:43 +03, task 596) */
    private $auto_increment_step = null;

    /** @var callable|null the current Unix time (2026-10-21 05:46 +03, Task 1.14: the audit row age), task 596 */
    private $clock;

    /**
     * 2026-10-21 05:46 +03: optional clock, used only for the age of an audit row in pruneChunk() (tests pass one),
     * Task 1.14, task 596.
     *
     * @param callable|null $clock Returns the current Unix time; null = time()
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock;
    }

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
     * 2026-10-21 01:43 +03: returns every id: the first id of each statement plus k times @@auto_increment_increment
     * (read once per request; a Galera or multi-source cluster sets it above 1), fix round 2, item C, task 596.
     *
     * @return int[]
     *
     * @throws \RuntimeException
     */
    public function insertMany(string $table, array $rows): array
    {
        $name = Schema::table($table);
        $columns = Schema::columns($table);
        $ids = [];
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
            // 2026-10-21 08:25 +03: the increment is read BEFORE the INSERT (it was after): a throw in this read used
            // to leave the rows written and AttemptLog::recordMany() then recorded them again one by one (duplicate
            // attempt rows); now a throw here means nothing was written, cleanup item 1, task 596.
            $step = $this->autoIncrementStep();
            $first = $this->runInsertMany($name, $clean);
            if ($first < 1) {
                throw new \RuntimeException('Could not insert into ' . $name);
            }
            foreach (array_keys($clean) as $k) {
                $ids[] = $first + $k * $step;
            }
        }

        return $ids;
    }

    /**
     * One multi-row INSERT; the first id it generated. 2026-10-21 01:43 +03: seam, task 596.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    protected function runInsertMany(string $name, array $rows): int
    {
        return (int) db_query('INSERT INTO ?:' . $name . ' ?m', $rows);
    }

    /**
     * The server's auto_increment_increment, read once per request (1 when it cannot be read).
     * 2026-10-21 01:43 +03: added, task 596.
     */
    protected function autoIncrementStep(): int
    {
        if ($this->auto_increment_step === null) {
            $step = (int) $this->fetchField(['SELECT @@auto_increment_increment']);
            $this->auto_increment_step = $step > 0 ? $step : 1;
        }

        return $this->auto_increment_step;
    }

    /**
     * 2026-10-21 12:02 +03: rows written in place, one INSERT ... ON DUPLICATE KEY UPDATE per 500 rows keyed by the
     * primary key (the only unique key of the log tables), so the cap reservations of a whole send are finished in one
     * statement (ruling R41), task 596. Column names come from Schema, never from the caller.
     *
     * @throws \RuntimeException
     */
    public function updateMany(string $table, array $rows): int
    {
        $name = Schema::table($table);
        $pk = Schema::pk($table);
        $columns = Schema::columns($table);
        $written = 0;
        foreach (array_chunk($rows, self::BULK_CHUNK, true) as $chunk) {
            $keys = null;
            $clean = [];
            foreach ($chunk as $id => $row) {
                $values = [$pk => (int) $id];
                foreach (array_intersect_key($row, $columns) as $column => $value) {
                    if (is_bool($value)) {
                        $value = (int) $value;
                    } elseif ((is_float($value) && !is_finite($value)) || is_array($value) || is_object($value)) {
                        $value = 0;
                    }
                    $values[$column] = $value;
                }
                if ($keys === null) {
                    $keys = array_keys($values);
                } elseif (array_keys($values) !== $keys) {
                    throw new \RuntimeException('updateMany: every row must list the same columns');
                }
                $clean[] = $values;
            }
            if ($clean === [] || $keys === null || count($keys) < 2) {
                continue;
            }
            $set = [];
            foreach ($keys as $column) {
                if ($column !== $pk) {
                    $set[] = '`' . $column . '` = VALUES(`' . $column . '`)';
                }
            }
            $result = db_query('INSERT INTO ?:' . $name . ' ?m ON DUPLICATE KEY UPDATE ' . implode(', ', $set), $clean);
            if ($result === false) {
                throw new \RuntimeException('Could not write the rows of ' . $name);
            }
            $written += count($clean);
        }

        return $written;
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
        $chunk = max(1, $chunk);
        $total = 0;
        for ($i = 0; $i < self::MAX_CHUNKS; $i++) {
            $deleted = $this->pruneChunk($table, $timestamp, $chunk);
            $total += $deleted;
            if ($deleted < $chunk) {
                break;
            }
        }

        return $total;
    }

    /**
     * One statement of deleteOlderThan(), for a caller that keeps its own clock (Cron\CronRunner, prune_logs).
     * 2026-10-21 01:56 +03: split out of deleteOlderThan(), Task 1.7 fix round 1 item 5, task 596.
     */
    public function pruneChunk(string $table, int $timestamp, int $chunk = self::DELETE_CHUNK): int
    {
        // 2026-10-21 05:46 +03: on the debug table an audit row of a clear (AUDIT_ACTION) is kept until it is 365 days
        // old, whatever retention asks for (PRD 6.8: immune to retention under 365 days), Task 1.14 carry, task 596.
        if ($table === Schema::DEBUG) {
            return (int) db_query(
                'DELETE FROM ?:' . Schema::table($table)
                . ' WHERE `created_at` < ?i AND (`action` <> ?s OR `created_at` < ?i)'
                . ' ORDER BY `created_at` ASC LIMIT ?i',
                $timestamp,
                self::AUDIT_ACTION,
                min($timestamp, $this->now() - self::AUDIT_KEEP_SECONDS),
                max(1, $chunk)
            );
        }

        return (int) db_query(
            'DELETE FROM ?:' . Schema::table($table) . ' WHERE `created_at` < ?i ORDER BY `created_at` ASC LIMIT ?i',
            $timestamp,
            max(1, $chunk)
        );
    }

    // ------------------------------------------------------------------ AdminLogStoreInterface (Task 1.14)

    /**
     * 2026-10-21 05:46 +03: added, Task 1.14, task 596.
     *
     * @throws \RuntimeException
     */
    public function page(string $table, array $filters, int $limit, int $offset, bool $ascending = false): array
    {
        $rows = $this->fetchRows($this->selectArguments($table, $filters, $limit, $offset, $ascending));
        if (!is_array($rows)) {
            throw new \RuntimeException('Could not read the rows of ' . Schema::table($table));
        }

        return $rows;
    }

    /** 2026-10-21 05:46 +03: added, Task 1.14, task 596. */
    public function pageSql(string $table, array $filters, int $limit, int $offset, bool $ascending = false): string
    {
        return $this->prefixed(db_quote(...$this->selectArguments($table, $filters, $limit, $offset, $ascending)));
    }

    /**
     * 2026-10-21 05:46 +03: one index-only read of the created_at index (the $rows-th newest entry of the period),
     * Task 1.14, task 596.
     */
    public function horizon(string $table, ?int $from, ?int $to, int $rows): ?int
    {
        $value = $this->fetchField($this->horizonArguments($table, $from, $to, $rows));
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /** 2026-10-21 05:46 +03: the SQL text of horizon(), Task 1.14, task 596. */
    public function horizonSql(string $table, ?int $from, ?int $to, int $rows): string
    {
        return $this->prefixed(db_quote(...$this->horizonArguments($table, $from, $to, $rows)));
    }

    /**
     * @return array<int,mixed>
     */
    private function horizonArguments(string $table, ?int $from, ?int $to, int $rows): array
    {
        $range = [];
        if ($from !== null) {
            $range['created_from'] = $from;
        }
        if ($to !== null) {
            $range['created_to'] = $to;
        }

        return [
            'SELECT `created_at` FROM ?:' . Schema::table($table) . ' FORCE INDEX (`idx_created_at`) WHERE ?p'
                . ' ORDER BY `created_at` DESC LIMIT ?i, 1',
            $this->where($table, $range),
            max(0, $rows - 1),
        ];
    }

    /**
     * 2026-10-21 05:46 +03: added, Task 1.14, task 596.
     *
     * @throws \RuntimeException
     */
    public function countClearable(string $table, array $filters): int
    {
        $count = $this->fetchField($this->countClearableArguments($table, $filters));
        if (!is_int($count) && !(is_string($count) && ctype_digit($count))) {
            throw new \RuntimeException('Could not count the rows of ' . Schema::table($table));
        }

        return (int) $count;
    }

    /**
     * 2026-10-21 05:46 +03: the SQL text of countClearable(), Task 1.14, task 596.
     *
     * @param array<string,mixed> $filters
     */
    public function countClearableSql(string $table, array $filters): string
    {
        return $this->prefixed(db_quote(...$this->countClearableArguments($table, $filters)));
    }

    /**
     * @param array<string,mixed> $filters
     *
     * @return array<int,mixed>
     */
    private function countClearableArguments(string $table, array $filters): array
    {
        // 2026-10-21 06:31 +03: on the debug table "every row but the audit rows" is counted as two counts (all rows of
        // the filter, minus its audit rows found through idx_action): a single COUNT with action <> ... reads every row
        // of the period (2M rows: seconds), the two counts read indexes only (ruling R35 evidence), task 596.
        if ($table === Schema::DEBUG) {
            $where = $this->where($table, $filters);

            return [
                'SELECT (SELECT COUNT(*) FROM ?:' . Schema::table($table) . ' WHERE ?p)'
                    . ' - (SELECT COUNT(*) FROM ?:' . Schema::table($table) . ' WHERE (?p) AND `action` = ?s)',
                $where,
                $where,
                self::AUDIT_ACTION,
            ];
        }

        return [
            'SELECT COUNT(*) FROM ?:' . Schema::table($table) . ' WHERE ?p',
            $this->clearableWhere($table, $filters),
        ];
    }

    /** 2026-10-21 05:46 +03: added, Task 1.14, task 596. */
    public function deleteChunk(string $table, array $filters, int $chunk = self::DELETE_CHUNK): int
    {
        return (int) db_query(
            'DELETE FROM ?:' . Schema::table($table) . ' WHERE ?p ORDER BY `created_at` ASC LIMIT ?i',
            $this->clearableWhere($table, $filters),
            max(1, $chunk)
        );
    }

    /**
     * The filters' WHERE, on the debug table without the audit rows (they are immune to every clear).
     *
     * @param array<string,mixed> $filters
     */
    private function clearableWhere(string $table, array $filters): string
    {
        $where = $this->where($table, $filters);
        if ($table === Schema::DEBUG) {
            $where = '(' . $where . ') AND ' . db_quote('`action` <> ?s', self::AUDIT_ACTION);
        }

        return $where;
    }

    /**
     * 2026-10-21 05:46 +03: PRD 6.8 "full clear uses TRUNCATE". On the debug table the audit rows are read first and
     * written back with their own ids (they are few: one per clear); a write-back that fails puts them in the PHP
     * error log and throws, so the caller reports it. Task 1.14, task 596.
     * 2026-10-21 08:11 +03, fix round 1: TRUNCATE resets AUTO_INCREMENT, and attempts keep pointing at message ids
     * (retry_of at attempt ids, outbox contexts at both): a new row with an old id would show or resend another
     * customer's text. The next id is read before the TRUNCATE and set again after it (item 1). The audit rows go to
     * the PHP error log before the TRUNCATE, so a fatal error before their write-back cannot lose them silently
     * (item 6).
     *
     * @throws \RuntimeException
     */
    public function truncate(string $table): int
    {
        $name = Schema::table($table);
        $before = $this->countClearable($table, []);
        $next = $this->nextId($table);
        $audit = [];
        if ($table === Schema::DEBUG) {
            $audit = db_get_array('SELECT * FROM ?:' . $name . ' WHERE `action` = ?s', self::AUDIT_ACTION);
            if (!is_array($audit)) {
                throw new \RuntimeException('Could not read the audit rows of ' . $name);
            }
            if ($audit !== []) {
                error_log('kwtsms: audit rows kept across TRUNCATE of ' . $name . ' (written back right after): '
                    . (string) json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            }
        }
        db_query('TRUNCATE TABLE ?:' . $name);
        // 2026-10-21 08:39 +03: the id counter as it was, right after the TRUNCATE and before the audit write-back,
        // so the instant in which another request could take a low id is one statement long (fix round 2, item 3;
        // the explicit audit ids below the counter are fine), task 596.
        db_query('ALTER TABLE ?:' . $name . ' AUTO_INCREMENT = ?i', $next);
        if ($audit !== []) {
            $written = 0;
            foreach (array_chunk($audit, self::BULK_CHUNK) as $chunk) {
                $written += (int) db_query('INSERT INTO ?:' . $name . ' ?m', $chunk) > 0 ? count($chunk) : 0;
            }
            if ($written !== count($audit)) {
                error_log('kwtsms: audit rows not written back after TRUNCATE of ' . $name . ': '
                    . (string) json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                throw new \RuntimeException('Could not write back the audit rows of ' . $name);
            }
        }
        // 2026-10-21 08:11 +03: the id counter as it was (fix round 1, item 1), task 596. 2026-10-21 08:39 +03: set
        // above (fix round 2, item 3); checked here, after the write-back.
        if ($this->nextId($table) < $next) {
            error_log('kwtsms: AUTO_INCREMENT of ' . $name . ' not restored to ' . $next . ' after TRUNCATE');
            throw new \RuntimeException('Could not restore the id counter of ' . $name);
        }

        return $before;
    }

    /**
     * 2026-10-21 08:11 +03: the id the next insert gets: the AUTO_INCREMENT this connection sees (SHOW CREATE TABLE,
     * which also answers for a TEMPORARY table of the same name, where information_schema does not), never below
     * MAX(id) + 1. Fix round 1, item 1, task 596.
     */
    private function nextId(string $table): int
    {
        $name = Schema::table($table);
        $max = (int) db_get_field('SELECT MAX(`' . Schema::pk($table) . '`) FROM ?:' . $name);
        $create = db_get_row('SHOW CREATE TABLE ?:' . $name);
        $sql = is_array($create) ? (string) (array_values($create)[1] ?? '') : '';
        $auto = 0;
        // The table option comes after the column list; a column's own AUTO_INCREMENT attribute has no "=".
        if (preg_match_all('/\bAUTO_INCREMENT=(\d+)/', $sql, $m) > 0) {
            $auto = (int) end($m[1]);
        }

        return max($auto, $max + 1, 1);
    }

    /**
     * 2026-10-21 05:46 +03: the order SMS tab (PRD 6.8): attempts of the order, of its parent, and of the children of
     * the order when it is a checkout, through idx_order_id and idx_parent_order_id. Task 1.14, task 596.
     *
     * @throws \RuntimeException
     */
    public function attemptsForOrder(int $order_id, int $parent_id, int $limit): array
    {
        if ($order_id < 1) {
            return [];
        }
        $rows = $this->fetchRows($this->orderArguments($order_id, $parent_id, $limit));
        if (!is_array($rows)) {
            throw new \RuntimeException('Could not read the attempts of order ' . $order_id);
        }

        return $rows;
    }

    /** 2026-10-21 05:46 +03: the SQL text of attemptsForOrder(), Task 1.14, task 596. */
    public function attemptsForOrderSql(int $order_id, int $parent_id, int $limit): string
    {
        return $this->prefixed(db_quote(...$this->orderArguments($order_id, $parent_id, $limit)));
    }

    /**
     * @return array<int,mixed>
     */
    private function orderArguments(int $order_id, int $parent_id, int $limit): array
    {
        $ids = $parent_id > 0 && $parent_id !== $order_id ? [$order_id, $parent_id] : [$order_id];

        return [
            'SELECT * FROM ?:' . Schema::table(Schema::ATTEMPTS) . ' WHERE `order_id` IN (?n) OR `parent_order_id` = ?i'
                . ' ORDER BY `created_at` DESC, `attempt_id` DESC LIMIT ?i',
            $ids,
            $order_id,
            max(1, min(1000, $limit)),
        ];
    }

    /**
     * The LIKE pattern of a text filter: the text anywhere, its %, _ and backslash taken literally (PRD 10:
     * addcslashes($q, '%_\\')); bound with ?s, never ?l (which doubles the backslash once more).
     * 2026-10-21 05:46 +03: added, Task 1.14, task 596.
     */
    public static function likePattern(string $text): string
    {
        return '%' . addcslashes($text, '%_\\') . '%';
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }

    /**
     * Test messages of the last window that hold credits in the kwtSMS queue (PRD 6.13 test-mode notice): how many,
     * and the points they were charged. A range of the created_at index. 2026-10-21 01:56 +03: moved here from
     * ServiceProvider, Task 1.7 fix round 1 item 10, task 596.
     *
     * @param int $since Unix time the window starts at
     *
     * @return array{messages: int, credits: float}
     */
    public function heldTestCredits(int $since): array
    {
        $row = db_get_row(
            'SELECT COUNT(*) AS messages, COALESCE(SUM(points_charged), 0) AS credits FROM ?:kwtsms_messages'
            . ' WHERE created_at >= ?i AND is_test = ?s AND points_charged > 0',
            $since,
            'Y'
        );

        return [
            'messages' => is_array($row) ? (int) ($row['messages'] ?? 0) : 0,
            'credits' => is_array($row) ? (float) ($row['credits'] ?? 0) : 0.0,
        ];
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
    private function selectArguments(
        string $table,
        array $filters,
        int $limit,
        int $offset,
        bool $ascending = false
    ): array {
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

        // 2026-10-21 05:46 +03: oldest first on request (the Logs page sort, Task 1.14), task 596.
        $direction = $ascending ? 'ASC' : 'DESC';

        return [
            'SELECT ?p FROM ?:' . Schema::table($table) . $hint . ' WHERE ?p ORDER BY `created_at` ' . $direction
                . ', `' . $pk . '` ' . $direction . ' LIMIT ?i, ?i',
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
            } elseif ($key === 'text') {
                // 2026-10-21 05:46 +03: a LIKE on the text columns of the table, the pattern escaped (Task 1.14),
                // task 596.
                $like = [];
                foreach (Schema::textColumns($table) as $column) {
                    $like[] = db_quote('`' . $column . '` LIKE ?s', self::likePattern((string) $value));
                }
                $parts[] = '(' . implode(' OR ', $like) . ')';
            } elseif ($key === 'phone' && Schema::hasPhoneLookup($table)) {
                // 2026-10-21 05:46 +03: messages to a number, through the indexed kwtsms_attempts.phone (Task 1.14),
                // task 596.
                $parts[] = db_quote(
                    '`message_id` IN (SELECT `message_id` FROM ?:' . Schema::table(Schema::ATTEMPTS)
                    . ' WHERE `phone` = ?s AND `message_id` > 0)',
                    (string) $value
                );
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
