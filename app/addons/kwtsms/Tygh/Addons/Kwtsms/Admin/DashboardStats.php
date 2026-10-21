<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/DashboardStats.php
 * CREATED:   2026-10-21 03:12 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Every database read of the Dashboard (PRD 6.9): the counters for today, 7 and 30 days (live and test
 *            separately), the Problems panel (outcomes, API codes, countries), credits by event and receiver for this
 *            month and last month, the last messages, the outbox size and backlog, the held test credits, and the
 *            three facts the checklists derive (a test SMS went out, a live message was accepted, which template
 *            languages were edited).
 * WHY:       The page must stay fast on tables that grow without bound (1M attempts, PRD 14), so each aggregate is ONE
 *            statement over a range of an index: attempts and messages by FORCE INDEX (idx_created_at) with an
 *            upper bound (so a test can use a simulated "now"), the Problems outcomes by idx_outcome_created_at, the
 *            outbox by idx_status_retry_at. queries() returns the exact text of each statement for EXPLAIN in tests.
 *            Phones, texts and names are never selected here: the Dashboard needs view_kwtsms only, the logs need
 *            view_kwtsms_logs.
 * USAGE:     $stats = new DashboardStats();
 *            $stats->counters($now, $today_start)['live']['week']['accepted'];
 *            $stats->problems($since, $now);   $stats->outbox($now);   $stats->queries($now) -> name => SQL text
 *            Test seam: fetchRows(), fetchRow(), fetchField() are the only places that call the database.
 * DEPENDS:   CS-Cart db_get_array(), db_get_row(), db_get_field(), db_quote(); Admin\OutcomeGroups;
 *            Sms\PhoneNormalizer;
 *            tables kwtsms_attempts, kwtsms_messages, kwtsms_outbox, kwtsms_template_descriptions.
 */

namespace Tygh\Addons\Kwtsms\Admin;

use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;
use Tygh\Registry;

class DashboardStats
{
    /** Outbox statuses that wait to be sent. */
    private const WAITING = ['pending', 'sending'];

    /** The reason of a row that waits for the end of quiet hours on purpose (never a backlog). */
    public const QUIET_REASON = 'quiet_hours';

    /** The event id the Settings test SMS form writes. */
    public const TEST_SMS_EVENT = 'kwtsms.test_sms';

    // ------------------------------------------------------------------ counters

    /**
     * Attempts by outcome group and credits, for today, the last 7 days and the last 30 days, live and test.
     * Windows: today = since $today_start, 7 days = $now - 7 d, 30 days = $now - 30 d, all up to $now.
     *
     * @return array<string,array<string,array<string,int|float>>> [live|test][today|week|month][accepted|failed|
     *         skipped|deferred|credits]
     */
    public function counters(int $now, int $today_start): array
    {
        $empty = ['accepted' => 0, 'failed' => 0, 'skipped' => 0, 'deferred' => 0, 'credits' => 0.0];
        $out = [];
        foreach (['live', 'test'] as $mode) {
            $out[$mode] = ['today' => $empty, 'week' => $empty, 'month' => $empty];
        }
        foreach ($this->rows($this->attemptCounterQuery($now, $today_start)) as $row) {
            $kind = OutcomeGroups::kind((string) $row['outcome']);
            if ($kind === '') {
                continue;
            }
            $mode = (string) $row['is_test'] === 'Y' ? 'test' : 'live';
            $out[$mode]['today'][$kind] += (int) $row['n_today'];
            $out[$mode]['week'][$kind] += (int) $row['n_week'];
            $out[$mode]['month'][$kind] += (int) $row['n_month'];
        }
        foreach ($this->rows($this->creditCounterQuery($now, $today_start)) as $row) {
            $mode = (string) $row['is_test'] === 'Y' ? 'test' : 'live';
            $out[$mode]['today']['credits'] += (float) $row['c_today'];
            $out[$mode]['week']['credits'] += (float) $row['c_week'];
            $out[$mode]['month']['credits'] += (float) $row['c_month'];
        }

        return $out;
    }

    /**
     * 2026-10-21 06:05 +03: reads idx_created_outcome_test (created_at, outcome, is_test) only, no table row: 47 ms
     * instead of 756 ms for 30 days of 1M attempts (Task 1.14 performance step, ruling R35), task 596.
     *
     * @return array<int,mixed>
     */
    private function attemptCounterQuery(int $now, int $today_start): array
    {
        return [
            'SELECT is_test, outcome, SUM(created_at >= ?i) AS n_today, SUM(created_at >= ?i) AS n_week,'
            . ' COUNT(*) AS n_month FROM ?:kwtsms_attempts FORCE INDEX (idx_created_outcome_test)'
            . ' WHERE created_at >= ?i AND created_at <= ?i GROUP BY is_test, outcome',
            $today_start,
            $now - 7 * 86400,
            $now - 30 * 86400,
            $now,
        ];
    }

    /**
     * @return array<int,mixed>
     */
    private function creditCounterQuery(int $now, int $today_start): array
    {
        return [
            'SELECT is_test, SUM(IF(created_at >= ?i, points_charged, 0)) AS c_today,'
            . ' SUM(IF(created_at >= ?i, points_charged, 0)) AS c_week, SUM(points_charged) AS c_month'
            . ' FROM ?:kwtsms_messages FORCE INDEX (idx_created_at)'
            . ' WHERE created_at >= ?i AND created_at <= ?i AND points_charged > 0 GROUP BY is_test',
            $today_start,
            $now - 7 * 86400,
            $now - 30 * 86400,
            $now,
        ];
    }

    // ------------------------------------------------------------------ problems

    /**
     * The Problems panel: attempts per problem outcome, messages per API code, and the numbers kwtSMS could not
     * reach by country (calling code of the number).
     *
     * @return array{outcomes:array<string,int>,codes:array<int,array{code:string,source:string,count:int}>,countries:array<string,int>}
     */
    public function problems(int $since, int $until): array
    {
        $outcomes = [];
        foreach ($this->rows($this->problemOutcomeQuery($since, $until)) as $row) {
            $outcomes[(string) $row['outcome']] = (int) $row['n'];
        }
        $codes = [];
        foreach ($this->rows($this->problemCodeQuery($since, $until)) as $row) {
            $codes[] = [
                'code' => (string) $row['api_code'],
                'source' => (string) $row['code_source'],
                'count' => (int) $row['n'],
            ];
        }
        $countries = [];
        foreach ($this->rows($this->problemCountryQuery($since, $until)) as $row) {
            $cc = PhoneNormalizer::countryCode((string) $row['prefix']);
            $key = $cc === null ? '' : $cc;
            $countries[$key] = ($countries[$key] ?? 0) + (int) $row['n'];
        }
        arsort($countries);

        return ['outcomes' => $outcomes, 'codes' => $codes, 'countries' => $countries];
    }

    /**
     * @return array<int,mixed>
     */
    private function problemOutcomeQuery(int $since, int $until): array
    {
        return [
            'SELECT outcome, COUNT(*) AS n FROM ?:kwtsms_attempts FORCE INDEX (idx_outcome_created_at)'
            . ' WHERE outcome IN (?a)'
            . ' AND created_at >= ?i AND created_at <= ?i GROUP BY outcome ORDER BY n DESC',
            OutcomeGroups::problemOutcomes(),
            $since,
            $until,
        ];
    }

    /**
     * @return array<int,mixed>
     */
    private function problemCodeQuery(int $since, int $until): array
    {
        return [
            'SELECT api_code, code_source, COUNT(*) AS n FROM ?:kwtsms_messages FORCE INDEX (idx_created_at)'
            . ' WHERE created_at >= ?i AND created_at <= ?i AND api_code <> ?s'
            . ' GROUP BY api_code, code_source ORDER BY n DESC LIMIT 20',
            $since,
            $until,
            '',
        ];
    }

    /**
     * @return array<int,mixed>
     */
    private function problemCountryQuery(int $since, int $until): array
    {
        return [
            'SELECT LEFT(phone, 3) AS prefix, COUNT(*) AS n FROM ?:kwtsms_attempts FORCE INDEX (idx_outcome_created_at)'
            . ' WHERE outcome IN (?a)'
            . ' AND created_at >= ?i AND created_at <= ?i GROUP BY prefix ORDER BY n DESC LIMIT 200',
            OutcomeGroups::BY_COUNTRY,
            $since,
            $until,
        ];
    }

    // ------------------------------------------------------------------ credits by event and receiver

    /**
     * Credits charged per event and receiver, live and test, for the month that starts at $this_start and the month
     * before it (from $last_start).
     *
     * @return array<int,array{event_id:string,receiver:string,this_live:float,this_test:float,last_live:float,last_test:float}>
     */
    public function creditsByEvent(int $last_start, int $this_start, int $now): array
    {
        $out = [];
        foreach ($this->rows($this->creditsByEventQuery($last_start, $this_start, $now)) as $row) {
            $out[] = [
                'event_id' => (string) $row['event_id'],
                'receiver' => (string) $row['receiver'],
                'this_live' => (float) $row['this_live'],
                'this_test' => (float) $row['this_test'],
                'last_live' => (float) $row['last_live'],
                'last_test' => (float) $row['last_test'],
            ];
        }

        return $out;
    }

    /**
     * @return array<int,mixed>
     */
    private function creditsByEventQuery(int $last_start, int $this_start, int $now): array
    {
        return [
            'SELECT event_id, receiver,'
            . ' SUM(IF(created_at >= ?i AND is_test = ?s, points_charged, 0)) AS this_live,'
            . ' SUM(IF(created_at >= ?i AND is_test = ?s, points_charged, 0)) AS this_test,'
            . ' SUM(IF(created_at < ?i AND is_test = ?s, points_charged, 0)) AS last_live,'
            . ' SUM(IF(created_at < ?i AND is_test = ?s, points_charged, 0)) AS last_test'
            . ' FROM ?:kwtsms_messages FORCE INDEX (idx_created_at)'
            . ' WHERE created_at >= ?i AND created_at <= ?i AND points_charged > 0'
            . ' GROUP BY event_id, receiver ORDER BY SUM(points_charged) DESC LIMIT 50',
            $this_start, 'N',
            $this_start, 'Y',
            $this_start, 'N',
            $this_start, 'Y',
            $last_start,
            $now,
        ];
    }

    // ------------------------------------------------------------------ last messages

    /**
     * The newest API calls, without phone numbers or texts.
     *
     * @return array<int,array<string,mixed>>
     */
    public function lastMessages(int $limit): array
    {
        $out = [];
        foreach ($this->rows($this->lastMessagesQuery($limit)) as $row) {
            $out[] = [
                'message_id' => (int) $row['message_id'],
                'created_at' => (int) $row['created_at'],
                'event_id' => (string) $row['event_id'],
                'receiver' => (string) $row['receiver'],
                'status' => (string) $row['status'],
                'is_test' => (string) $row['is_test'] === 'Y',
                'recipients' => (int) $row['recipient_count'],
                'pages' => (int) $row['pages'],
                'credits' => (float) $row['points_charged'],
                'api_code' => (string) $row['api_code'],
            ];
        }

        return $out;
    }

    /**
     * @return array<int,mixed>
     */
    private function lastMessagesQuery(int $limit): array
    {
        return [
            'SELECT message_id, created_at, event_id, receiver, status, is_test, recipient_count, pages,'
            . ' points_charged, api_code FROM ?:kwtsms_messages FORCE INDEX (idx_created_at)'
            . ' ORDER BY created_at DESC, message_id DESC LIMIT ?i',
            max(1, min(100, $limit)),
        ];
    }

    // ------------------------------------------------------------------ outbox

    /**
     * Rows waiting in the outbox. Backlog = pending or sending rows queued $older_than seconds ago or longer,
     * except rows that wait for the end of quiet hours on purpose: a quiet-hours row whose retry time has passed is
     * a backlog again, because nothing sent it when its window ended (2026-10-21 05:16 +03, Task 1.11 fix round 1
     * item 9, task 596).
     *
     * @return array{waiting:int,held:int,oldest:?int,backlog:int}
     */
    public function outbox(int $now, int $older_than = 600): array
    {
        $out = ['waiting' => 0, 'held' => 0, 'oldest' => null, 'backlog' => 0];
        foreach ($this->rows($this->outboxQuery()) as $row) {
            if ((string) $row['status'] === 'held') {
                $out['held'] += (int) $row['n'];
                continue;
            }
            $out['waiting'] += (int) $row['n'];
            $first = (int) $row['oldest'];
            if ($first > 0 && ($out['oldest'] === null || $first < $out['oldest'])) {
                $out['oldest'] = $first;
            }
        }
        $out['backlog'] = (int) $this->field($this->outboxBacklogQuery($now, $older_than));

        return $out;
    }

    /**
     * @return array<int,mixed>
     */
    private function outboxQuery(): array
    {
        return [
            'SELECT status, COUNT(*) AS n, MIN(created_at) AS oldest FROM ?:kwtsms_outbox'
            . ' FORCE INDEX (idx_status_retry_at)'
            . ' WHERE status IN (?a)'
            . ' GROUP BY status',
            array_merge(self::WAITING, ['held']),
        ];
    }

    /**
     * @return array<int,mixed>
     */
    private function outboxBacklogQuery(int $now, int $older_than): array
    {
        return [
            'SELECT COUNT(*) FROM ?:kwtsms_outbox FORCE INDEX (idx_status_retry_at)'
            . ' WHERE status IN (?a) AND created_at <= ?i AND (reason <> ?s OR retry_at <= ?i)',
            self::WAITING,
            $now - $older_than,
            self::QUIET_REASON,
            $now,
        ];
    }

    // ------------------------------------------------------------------ held test credits

    /**
     * Test messages that hold credits in the kwtSMS queue since $since (same predicate as
     * DbLogStore::heldTestCredits(), plus the time of the oldest one).
     *
     * @return array{messages:int,credits:float,first_at:?int}
     */
    public function heldTest(int $since, int $until): array
    {
        $row = $this->row($this->heldTestQuery($since, $until));

        return [
            'messages' => (int) ($row['messages'] ?? 0),
            'credits' => (float) ($row['credits'] ?? 0),
            'first_at' => isset($row['first_at']) && (int) $row['first_at'] > 0 ? (int) $row['first_at'] : null,
        ];
    }

    /**
     * @return array<int,mixed>
     */
    private function heldTestQuery(int $since, int $until): array
    {
        return [
            'SELECT COUNT(*) AS messages, COALESCE(SUM(points_charged), 0) AS credits, MIN(created_at) AS first_at'
            . ' FROM ?:kwtsms_messages FORCE INDEX (idx_created_at)'
            . ' WHERE created_at >= ?i AND created_at <= ?i AND is_test = ?s AND points_charged > 0',
            $since,
            $until,
            'Y',
        ];
    }

    // ------------------------------------------------------------------ facts for the checklists

    /** Attempts with this outcome since $since (the daily-cap warning). */
    public function outcomeSince(string $outcome, int $since): int
    {
        return (int) $this->field([
            'SELECT COUNT(*) FROM ?:kwtsms_attempts FORCE INDEX (idx_outcome_created_at) WHERE outcome = ?s'
            . ' AND created_at >= ?i',
            $outcome,
            $since,
        ]);
    }

    /** True when a test SMS from the Settings form was accepted (live or test). */
    public function testSmsAccepted(): bool
    {
        return (int) $this->field([
            'SELECT 1 FROM ?:kwtsms_messages WHERE event_id = ?s AND status IN (?a) LIMIT 1',
            self::TEST_SMS_EVENT,
            OutcomeGroups::ACCEPTED,
        ]) === 1;
    }

    /** True when kwtSMS accepted at least one live (not test) message. */
    public function liveAccepted(): bool
    {
        return (int) $this->field([
            'SELECT 1 FROM ?:kwtsms_messages FORCE INDEX (idx_status_created_at) WHERE status IN (?a) AND is_test = ?s'
            . ' LIMIT 1',
            ['accepted', 'partial_accept'],
            'N',
        ]) === 1;
    }

    /** True when the administrator saved an enabled SMS brand name for a storefront (PRD 6.4). */
    public function brandSaved(): bool
    {
        return (int) $this->field([
            'SELECT 1 FROM ?:kwtsms_brand_names b INNER JOIN ?:kwtsms_brand_name_descriptions d'
            . ' ON d.storefront_id = b.storefront_id WHERE b.enabled = ?s AND d.brand_name <> ?s LIMIT 1',
            'Y',
            '',
        ]) === 1;
    }

    /**
     * Languages with at least one template body the administrator saved.
     *
     * @return string[]
     */
    public function editedLanguages(): array
    {
        $langs = $this->column([
            'SELECT DISTINCT lang_code FROM ?:kwtsms_template_descriptions WHERE is_edited = ?s',
            'Y',
        ]);

        return array_values(array_map('strval', $langs));
    }

    // ------------------------------------------------------------------ EXPLAIN support

    /**
     * The exact text of every aggregate this class runs, table prefix applied, for EXPLAIN in tests.
     *
     * @return array<string,string>
     */
    public function queries(int $now, int $today_start): array
    {
        $month = (int) strtotime('first day of this month 00:00:00', $now);
        $last = (int) strtotime('first day of last month 00:00:00', $now);
        $all = [
            'attempt_counters' => $this->attemptCounterQuery($now, $today_start),
            'credit_counters' => $this->creditCounterQuery($now, $today_start),
            'problem_outcomes' => $this->problemOutcomeQuery($now - 7 * 86400, $now),
            'problem_codes' => $this->problemCodeQuery($now - 7 * 86400, $now),
            'problem_countries' => $this->problemCountryQuery($now - 7 * 86400, $now),
            'credits_by_event' => $this->creditsByEventQuery($last, $month, $now),
            'last_messages' => $this->lastMessagesQuery(10),
            'outbox' => $this->outboxQuery(),
            'outbox_backlog' => $this->outboxBacklogQuery($now, 600),
            'held_test' => $this->heldTestQuery($now - 30 * 86400, $now),
        ];
        $out = [];
        foreach ($all as $name => $args) {
            $out[$name] = $this->prefixed(db_quote(...$args));
        }

        return $out;
    }

    private function prefixed(string $sql): string
    {
        return str_replace('?:', (string) Registry::get('config.table_prefix'), $sql);
    }

    // ------------------------------------------------------------------ database seams

    /**
     * @param array<int,mixed> $query Pattern first, then the values for its placeholders
     *
     * @return array<int,array<string,mixed>>
     */
    private function rows(array $query): array
    {
        $rows = $this->fetchRows($query);
        if (!is_array($rows)) {
            throw new \RuntimeException('Could not read the Dashboard figures');
        }

        return $rows;
    }

    /**
     * @param array<int,mixed> $query
     *
     * @return array<string,mixed>
     */
    private function row(array $query): array
    {
        $row = $this->fetchRow($query);

        return is_array($row) ? $row : [];
    }

    /**
     * @param array<int,mixed> $query
     *
     * @return mixed
     */
    private function field(array $query)
    {
        return $this->fetchField($query);
    }

    /**
     * @param array<int,mixed> $query
     *
     * @return array<int,mixed>
     */
    private function column(array $query): array
    {
        $values = $this->fetchColumn($query);

        return is_array($values) ? $values : [];
    }

    /**
     * @param array<int,mixed> $query
     *
     * @return mixed
     */
    protected function fetchRows(array $query)
    {
        return db_get_array(array_shift($query), ...$query);
    }

    /**
     * @param array<int,mixed> $query
     *
     * @return mixed
     */
    protected function fetchRow(array $query)
    {
        return db_get_row(array_shift($query), ...$query);
    }

    /**
     * @param array<int,mixed> $query
     *
     * @return mixed
     */
    protected function fetchField(array $query)
    {
        return db_get_field(array_shift($query), ...$query);
    }

    /**
     * @param array<int,mixed> $query
     *
     * @return mixed
     */
    protected function fetchColumn(array $query)
    {
        return db_get_fields(array_shift($query), ...$query);
    }
}
