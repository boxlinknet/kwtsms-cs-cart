<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Queue/Outbox.php
 * CREATED:   2026-10-21 00:07 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The table kwtsms_outbox: sends that could not go out now (gateway unreachable, queue full,
 *            account suspended, quiet hours, request budget used up) and are retried later by the cron drain
 *            (Task 1.7) through SmsService::send() with context outbox_id.
 * WHY:       PRD 6.11 and Appendix A: deferred sends are retried oldest first with back-off 30, 60, 120, 300 s
 *            and transactional rows expire after 30 minutes; a row is claimed before it is sent, so two drains
 *            (cron and dashboard) can never send it twice. D7: once a row is final, its secret values (OTP
 *            codes, reset links) are masked in the stored message and dropped from the stored context.
 *            Phones and message are stored as JSON with every non-ASCII character escaped: the table is 3-byte
 *            utf8 (PRD 8) and a 4-byte character must survive until the single clean() of the retry (R18:
 *            clean() is not idempotent, so the ORIGINAL text is stored, never the cleaned one).
 * USAGE:     $outbox = new Outbox();
 *            $id = $outbox->enqueue(['+96550000001'], $text, $context, 'gateway_unreachable',
 *                                   time() + 30, time() + 1800);
 *            2026-10-21 01:43 +03: the drain is SmsService::drainDue($limit, $time_budget_s) ONLY (fix round 2,
 *            item F, task 596); it reaps, reads due(), claims each row with the attempts it read, and sends what
 *            the claimed row holds. The old "send($row['phones'], ..., ['outbox_id' => ...])" drain and the one-
 *            argument claim($id) are gone. The row API it uses:
 *            $outbox->due(20);             // [['outbox_id','created_at','retry_at','expires_at','attempts',
 *                                          //   'reason','status','phones'=>[],'message'=>'','context'=>[]], ...]
 *            $outbox->claim($id, $attempts_read);   // pending, due and unchanged -> sending (retry_at = claim time)
 *            markRetry($id, $retry_at, $reason, $phones, $expires_at) -> pending, attempts + 1;
 *            markDone($id); markExpired($id); markUnknown($id); markHeld($id) (TLS failure: kept, never due);
 *            reap($now) (sending over 10 min -> unknown, held past expiry -> expired).
 *            Outbox::backoff($attempts) -> 30, 60, 120, then 300 s. enqueue() returns 0 when the row could not
 *            be written (the caller records a failure, never a silent deferral).
 *            2026-10-21 01:03 +03, Task 1.6 fix round 1 (review: a stale snapshot resent a row), task 596:
 *            claim($id, $attempts) succeeds only for a row that is still pending, due (retry_at <= now) and has the
 *            attempts count the caller read; while a row is "sending" its retry_at is the claim time. reap($now)
 *            finalizes rows that can never be drained: "sending" for more than 10 minutes -> "unknown" (it may
 *            have been sent, so it is never resent) and "held" past expires_at -> "expired". markUnknown() is the
 *            final state of a claimed row whose send failed after an API call. The context keeps "test" (the test
 *            flag of the original send, ruling R26). Every final state (done, expired, unknown) masks the secret
 *            values (D7). JSON is written with JSON_INVALID_UTF8_SUBSTITUTE.
 *            Test seams (protected): insertRow(), updateRow(), selectRow(), selectDue(). dueSql($now, $limit) is the
 *            SQL text of due() for EXPLAIN / ANALYZE (2026-10-21 00:34 +03, Task 1.6, task 596).
 * DEPENDS:   CS-Cart db_query(), db_get_row(), db_get_array(); Log\Redactor, Log\ReportsFailures.
 */

namespace Tygh\Addons\Kwtsms\Queue;

use Tygh\Addons\Kwtsms\Log\Redactor;
use Tygh\Addons\Kwtsms\Log\ReportsFailures;

class Outbox
{
    use ReportsFailures;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENDING = 'sending';
    public const STATUS_DONE = 'done';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_HELD = 'held';

    /** 2026-10-21 01:03 +03: a claimed row whose send ended without a verdict (Task 1.6 fix round 1), task 596. */
    public const STATUS_UNKNOWN = 'unknown';

    /** Seconds after which a row still "sending" is finalized as unknown by reap(). */
    public const CLAIM_TIMEOUT = 600;

    /** json_encode() flags of every stored JSON (2026-10-21 01:03 +03: invalid UTF-8 substituted, task 596). */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    /** Seconds until the next try, by the number of tries already made (PRD 6.2 step 13). */
    public const BACKOFF = [30, 60, 120, 300];

    /** Transactional rows expire this long after they were queued. */
    public const TTL_SECONDS = 1800;

    /**
     * The due rows: one range of the (status, retry_at) index, already in retry_at order.
     * 2026-10-21 00:34 +03: one pattern for selectDue() and dueSql(), Task 1.6, task 596.
     */
    private const DUE_SQL = 'SELECT * FROM ?:kwtsms_outbox WHERE status = ?s AND retry_at <= ?i'
        . ' ORDER BY retry_at ASC, outbox_id ASC LIMIT ?i';

    /** The message and phones columns are text (64 KB); a JSON longer than this is refused. */
    private const MAX_JSON_BYTES = 60000;

    /** Context keys kept for the retry, with their type (s string, i int, a list of strings). */
    private const CONTEXT_KEYS = [
        'source' => 's', 'event_id' => 's', 'receiver' => 's', 'order_id' => 'i', 'parent_order_id' => 'i',
        'company_id' => 'i', 'storefront_id' => 'i', 'user_id' => 'i', 'actor_user_id' => 'i',
        'actor_company_id' => 'i', 'lang_code' => 's', 'audience' => 's', 'ip' => 's', 'country_iso' => 's',
        'caller' => 's', 'retry_of' => 'i', 'secret_values' => 'a',
        // 2026-10-21 01:03 +03: the test flag of the original send, "1" or "0" (ruling R26), task 596.
        'test' => 's',
        // 2026-10-21 05:08 +03: a row queued for after the response: "0" until kwtsms_send_pre ran on it, then "1"
        // (Task 1.9 fix round 2 item 1), task 596.
        'hook_ran' => 's',
    ];

    /** @var callable|null returns the current Unix time */
    private $clock;

    /**
     * @param callable|null $clock Returns the current Unix time (tests); null = time()
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock;
    }

    /**
     * Seconds to wait after $attempts earlier tries: 30, 60, 120, then 300.
     */
    public static function backoff(int $attempts): int
    {
        return self::BACKOFF[max(0, min($attempts, count(self::BACKOFF) - 1))];
    }

    /**
     * @param array<int,mixed>    $phones   Numbers as they should be retried (normalized ones carry a leading +)
     * @param array<string,mixed> $context  The send context; only the keys a retry needs are kept
     * @param string              $reason   queue_full, gateway_unreachable, suspended, quiet_hours,
     *                                      request_overflow, cap_unavailable
     *
     * @return int The new outbox_id, or 0 when the row could not be written
     */
    public function enqueue(
        array $phones,
        string $message,
        array $context,
        string $reason,
        int $retry_at,
        int $expires_at
    ): int {
        try {
            $phones_json = json_encode(array_values(array_map('strval', $phones)), self::JSON_FLAGS);
            $message_json = json_encode($message, self::JSON_FLAGS);
            $context_json = json_encode($this->keepContext($context), self::JSON_FLAGS);
            foreach ([$phones_json, $message_json, $context_json] as $json) {
                if (!is_string($json) || strlen($json) > self::MAX_JSON_BYTES) {
                    return 0;
                }
            }

            return $this->insertRow([
                'created_at' => $this->now(),
                'retry_at' => max(0, $retry_at),
                'expires_at' => max(0, $expires_at),
                'attempts' => 0,
                'reason' => substr($reason, 0, 32),
                'status' => self::STATUS_PENDING,
                'phones' => $phones_json,
                'message' => $message_json,
                'context' => $context_json,
            ]);
        } catch (\Throwable $e) {
            $this->reportFailure($e);

            return 0;
        }
    }

    /**
     * Pending rows whose retry time has come, oldest retry time first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function due(int $limit): array
    {
        $rows = [];
        foreach ($this->selectDue($this->now(), max(1, min($limit, 1000))) as $row) {
            $rows[] = $this->decodeRow($row);
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>|null The decoded row
     */
    public function find(int $id): ?array
    {
        $row = $id > 0 ? $this->selectRow($id) : null;

        return $row === null ? null : $this->decodeRow($row);
    }

    /**
     * pending -> sending, only while the row is still due and still has the $attempts the caller read (a drain
     * working from an older snapshot gets false). The claim time is kept in retry_at while the row is sending.
     * 2026-10-21 01:03 +03: conditional on retry_at and attempts (Task 1.6 fix round 1, critical 1), task 596.
     */
    public function claim(int $id, int $attempts): bool
    {
        $now = $this->now();
        $changes = ['status' => self::STATUS_SENDING, 'retry_at' => $now];
        $where = ['retry_at_max' => $now, 'attempts' => $attempts];

        return $this->updateRow($id, $changes, [self::STATUS_PENDING], $where) === 1;
    }

    /**
     * Rows that can never be drained, finalized: sending for longer than CLAIM_TIMEOUT -> unknown (never resent),
     * held past expires_at -> expired. Secrets masked (D7).
     * 2026-10-21 01:03 +03: added (Task 1.6 fix round 1, important 4), task 596.
     *
     * @return array<int,array<string,mixed>> The finalized rows, decoded, with their new status
     */
    public function reap(int $now): array
    {
        $done = [];
        foreach ($this->selectStale($now - self::CLAIM_TIMEOUT, $now) as $raw) {
            $row = $this->decodeRow($raw);
            $final = $row['status'] === self::STATUS_SENDING ? self::STATUS_UNKNOWN : self::STATUS_EXPIRED;
            if ($this->finish($row['outbox_id'], $final, [$row['status']])) {
                $row['status'] = $final;
                $done[] = $row;
            }
        }

        return $done;
    }

    /**
     * Deletes ONE chunk of the rows in a final state (done, expired, unknown) whose last retry time is before
     * $before: the retention of the outbox (ruling R28, 30 days), run by the daily prune_logs job. A range of the
     * (status, retry_at) index. Pending, sending and held rows are never touched.
     * 2026-10-21 01:57 +03: added, Task 1.7 fix round 1 item 5, task 596.
     *
     * @return int Rows deleted; fewer than $limit means none are left
     */
    public function pruneFinal(int $before, int $limit): int
    {
        return $this->deleteFinal(
            [self::STATUS_DONE, self::STATUS_EXPIRED, self::STATUS_UNKNOWN],
            max(0, $before),
            max(1, $limit)
        );
    }

    /**
     * Back to pending for another try, one more attempt counted.
     * 2026-10-21 01:03 +03: $expires_at moves the expiry (a drained row deferred for quiet hours), task 596.
     * 2026-10-21 05:08 +03: the $message argument of fix round 1 is gone again: markHookRan() stores the hook's text
     * (Task 1.9 fix round 2 item 1), task 596.
     *
     * @param array<int,mixed>|null $phones The numbers still to send (null = unchanged)
     */
    public function markRetry(
        int $id,
        int $retry_at,
        string $reason,
        ?array $phones = null,
        ?int $expires_at = null
    ): bool {
        $row = $this->selectRow($id);
        if ($row === null) {
            return false;
        }
        $changes = [
            'status' => self::STATUS_PENDING,
            'retry_at' => max(0, $retry_at),
            'attempts' => (int) $row['attempts'] + 1,
            'reason' => substr($reason, 0, 32),
        ];
        if ($phones !== null) {
            $list = array_values(array_map('strval', $phones));
            $changes['phones'] = (string) json_encode($list, self::JSON_FLAGS);
        }
        if ($expires_at !== null) {
            $changes['expires_at'] = max((int) $row['expires_at'], $expires_at);
        }

        return $this->updateRow($id, $changes, [self::STATUS_SENDING, self::STATUS_PENDING]) === 1;
    }

    /**
     * The row being sent (status sending) takes the phones, text and context kwtsms_send_pre returned, with
     * context hook_ran "1", so no retry runs the hook again and every retry sends what it returned.
     * 2026-10-21 05:08 +03: added, Task 1.9 fix round 2 item 1, task 596.
     *
     * @param array<int,mixed>    $phones
     * @param array<string,mixed> $context The stored context merged with the one the hook returned
     */
    public function markHookRan(int $id, array $phones, string $message, array $context): bool
    {
        try {
            $phones_json = json_encode(array_values(array_map('strval', $phones)), self::JSON_FLAGS);
            $message_json = json_encode($message, self::JSON_FLAGS);
            $context_json = json_encode($this->keepContext(['hook_ran' => '1'] + $context), self::JSON_FLAGS);
            foreach ([$phones_json, $message_json, $context_json] as $json) {
                if (!is_string($json) || strlen($json) > self::MAX_JSON_BYTES) {
                    return false;
                }
            }
            $changes = ['phones' => $phones_json, 'message' => $message_json, 'context' => $context_json];

            return $this->updateRow($id, $changes, [self::STATUS_SENDING]) === 1;
        } catch (\Throwable $e) {
            $this->reportFailure($e);

            return false;
        }
    }

    public function markDone(int $id): bool
    {
        return $this->finish($id, self::STATUS_DONE, [self::STATUS_SENDING, self::STATUS_PENDING]);
    }

    /**
     * A claimed row whose send failed after an API call may have reached kwtSMS: final, never resent.
     * 2026-10-21 01:03 +03: added (Task 1.6 fix round 1), task 596.
     */
    public function markUnknown(int $id): bool
    {
        return $this->finish($id, self::STATUS_UNKNOWN, [self::STATUS_SENDING]);
    }

    public function markExpired(int $id): bool
    {
        $from = [self::STATUS_SENDING, self::STATUS_PENDING, self::STATUS_HELD];

        return $this->finish($id, self::STATUS_EXPIRED, $from);
    }

    /** Kept but never due: a TLS failure is not retried automatically (Appendix A). */
    public function markHeld(int $id): bool
    {
        $from = [self::STATUS_SENDING, self::STATUS_PENDING];

        return $this->updateRow($id, ['status' => self::STATUS_HELD], $from) === 1;
    }

    // ------------------------------------------------------------------ seams (the database)

    /**
     * @param array<string,mixed> $row
     */
    protected function insertRow(array $row): int
    {
        return (int) db_query('INSERT INTO ?:kwtsms_outbox ?e', $row);
    }

    /**
     * @param array<string,mixed> $changes
     * @param string[]            $from_statuses
     * @param array<string,int>   $where         retry_at_max (retry_at <= value), attempts (attempts = value)
     *                                           (2026-10-21 01:03 +03: added for the conditional claim, task 596)
     *
     * @return int Rows changed
     */
    protected function updateRow(int $id, array $changes, array $from_statuses, array $where = []): int
    {
        $extra = '';
        if (isset($where['retry_at_max'])) {
            $extra .= db_quote(' AND retry_at <= ?i', $where['retry_at_max']);
        }
        if (isset($where['attempts'])) {
            $extra .= db_quote(' AND attempts = ?i', $where['attempts']);
        }

        return (int) db_query(
            'UPDATE ?:kwtsms_outbox SET ?u WHERE outbox_id = ?i AND status IN (?a)' . $extra,
            $changes,
            $id,
            $from_statuses
        );
    }

    /**
     * @param string[] $statuses
     *
     * @return int Rows deleted. 2026-10-21 01:57 +03: added, task 596.
     */
    protected function deleteFinal(array $statuses, int $before, int $limit): int
    {
        return (int) db_query(
            'DELETE FROM ?:kwtsms_outbox WHERE status IN (?a) AND retry_at < ?i LIMIT ?i',
            $statuses,
            $before,
            $limit
        );
    }

    /**
     * Rows sending since before $sending_before, and held rows whose expires_at has passed. Both read a range of
     * the (status, retry_at) index. 2026-10-21 01:03 +03: added, task 596.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function selectStale(int $sending_before, int $now): array
    {
        $rows = db_get_array(
            'SELECT * FROM ?:kwtsms_outbox WHERE (status = ?s AND retry_at < ?i) OR (status = ?s AND expires_at <= ?i)'
            . ' LIMIT 1000',
            self::STATUS_SENDING,
            $sending_before,
            self::STATUS_HELD,
            $now
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function selectRow(int $id): ?array
    {
        $row = db_get_row('SELECT * FROM ?:kwtsms_outbox WHERE outbox_id = ?i', $id);

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * Uses the (status, retry_at) index: one range, already in retry_at order.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function selectDue(int $now, int $limit): array
    {
        $rows = db_get_array(self::DUE_SQL, self::STATUS_PENDING, $now, $limit);

        return is_array($rows) ? $rows : [];
    }

    /**
     * The exact query of due(), as SQL text with the table prefix, for EXPLAIN / ANALYZE only.
     * 2026-10-21 00:34 +03: added (index evidence), Task 1.6, task 596.
     */
    public function dueSql(int $now, int $limit): string
    {
        $sql = (string) db_quote(self::DUE_SQL, self::STATUS_PENDING, $now, $limit);

        return str_replace('?:', (string) \Tygh\Registry::get('config.table_prefix'), $sql);
    }

    // ------------------------------------------------------------------ internals

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }

    /**
     * Final state; the secret values leave the stored message and context (D7).
     *
     * @param string[] $from
     */
    private function finish(int $id, string $status, array $from): bool
    {
        $changes = ['status' => $status];
        $row = $this->selectRow($id);
        if ($row !== null) {
            $context = json_decode((string) $row['context'], true);
            $secrets = is_array($context) && isset($context['secret_values']) && is_array($context['secret_values'])
                ? $context['secret_values']
                : [];
            if ($secrets !== []) {
                $message = json_decode((string) $row['message'], true);
                $masked = Redactor::mask(is_string($message) ? $message : '', $secrets);
                $changes['message'] = (string) json_encode($masked, self::JSON_FLAGS);
                unset($context['secret_values']);
                $changes['context'] = (string) json_encode($context, self::JSON_FLAGS);
            }
        }

        return $this->updateRow($id, $changes, $from) === 1;
    }

    /**
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed>
     */
    private function keepContext(array $context): array
    {
        $kept = [];
        foreach ($context as $key => $value) {
            $type = self::CONTEXT_KEYS[$key] ?? null;
            if ($type === null || $value === null) {
                continue;
            }
            if ($type === 'i') {
                $kept[$key] = is_numeric($value) ? (int) $value : 0;
            } elseif ($type === 'a') {
                $kept[$key] = array_values(array_map('strval', array_filter((array) $value, 'is_scalar')));
            } elseif (is_scalar($value)) {
                $kept[$key] = (string) $value;
            }
        }

        return $kept;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function decodeRow(array $row): array
    {
        $phones = json_decode((string) ($row['phones'] ?? ''), true);
        $message = json_decode((string) ($row['message'] ?? ''), true);
        $context = json_decode((string) ($row['context'] ?? ''), true);

        return [
            'outbox_id' => (int) $row['outbox_id'],
            'created_at' => (int) $row['created_at'],
            'retry_at' => (int) $row['retry_at'],
            'expires_at' => (int) $row['expires_at'],
            'attempts' => (int) $row['attempts'],
            'reason' => (string) $row['reason'],
            'status' => (string) $row['status'],
            'phones' => is_array($phones) ? array_values(array_map('strval', $phones)) : [],
            'message' => is_string($message) ? $message : '',
            'context' => is_array($context) ? $context : [],
        ];
    }
}
