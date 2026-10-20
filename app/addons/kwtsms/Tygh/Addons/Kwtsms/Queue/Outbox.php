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
 *            foreach ($outbox->due(20) as $row) {   // ['outbox_id','created_at','retry_at','expires_at','attempts',
 *                                                   //  'reason','status','phones'=>[],'message'=>'','context'=>[]]
 *                $sms->send($row['phones'], $row['message'], $row['context'] + ['outbox_id' => $row['outbox_id']]);
 *            }
 *            claim($id) pending -> sending; markRetry($id, $retry_at, $reason, $phones) -> pending, attempts + 1;
 *            markDone($id); markExpired($id); markHeld($id) (TLS failure: kept, never due, resent by hand).
 *            Outbox::backoff($attempts) -> 30, 60, 120, then 300 s. enqueue() returns 0 when the row could not
 *            be written (the caller records a failure, never a silent deferral).
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
            $phones_json = json_encode(array_values(array_map('strval', $phones)), JSON_UNESCAPED_SLASHES);
            $message_json = json_encode($message, JSON_UNESCAPED_SLASHES);
            $context_json = json_encode($this->keepContext($context), JSON_UNESCAPED_SLASHES);
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

    /** pending -> sending. False when another drain took it or it is final. */
    public function claim(int $id): bool
    {
        return $this->updateRow($id, ['status' => self::STATUS_SENDING], [self::STATUS_PENDING]) === 1;
    }

    /**
     * Back to pending for another try, one more attempt counted.
     *
     * @param array<int,mixed>|null $phones The numbers still to send (null = unchanged)
     */
    public function markRetry(int $id, int $retry_at, string $reason, ?array $phones = null): bool
    {
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
            $changes['phones'] = (string) json_encode($list, JSON_UNESCAPED_SLASHES);
        }

        return $this->updateRow($id, $changes, [self::STATUS_SENDING, self::STATUS_PENDING]) === 1;
    }

    public function markDone(int $id): bool
    {
        return $this->finish($id, self::STATUS_DONE, [self::STATUS_SENDING, self::STATUS_PENDING]);
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
     *
     * @return int Rows changed
     */
    protected function updateRow(int $id, array $changes, array $from_statuses): int
    {
        return (int) db_query(
            'UPDATE ?:kwtsms_outbox SET ?u WHERE outbox_id = ?i AND status IN (?a)',
            $changes,
            $id,
            $from_statuses
        );
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
                $changes['message'] = (string) json_encode($masked, JSON_UNESCAPED_SLASHES);
                unset($context['secret_values']);
                $changes['context'] = (string) json_encode($context, JSON_UNESCAPED_SLASHES);
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
