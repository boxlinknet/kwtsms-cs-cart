<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Log/AttemptLog.php
 * CREATED:   2026-10-20 21:35 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Writes one row of kwtsms_attempts per recipient per send (including everything that never
 *            reached the API) and counts recent attempts for the abuse caps.
 * WHY:       PRD 6.8 and 6.14: the Attempts tab explains why a customer got no SMS, and the caps (5 per hour
 *            and 20 per day per recipient, 300 per day per admin number, the store daily cap) are counted
 *            from these rows through the (phone, created_at) and (ip, created_at) indexes. D7: secret
 *            values are masked in the reason. An audit write must not break the send: a failed insert
 *            returns 0. A failed count is NOT hidden (it would read as "no recent attempts" and skip the cap).
 * USAGE:     $log = new AttemptLog($store);   (wired in Task 1.6)
 *            $id = $log->record(['phone' => '96598765432', 'outcome' => 'blocked_country', 'reason' => '...',
 *                                'secret_values' => ['482913']]);          // row id, or 0 when the write failed
 *            $log->countRecent('phone', '96598765432', 3600, ['accepted', 'test_queued']);   // last hour
 *            $log->countRecent('', '', 86400, ['accepted', 'test_queued']);                  // whole store
 *            Fields for countRecent: phone, ip, order_id, parent_order_id, or '' for no field.
 * DEPENDS:   Log\LogStoreInterface, Log\Redactor, Log\RowCleaner, Log\Schema.
 */

namespace Tygh\Addons\Kwtsms\Log;

class AttemptLog
{
    use ReportsFailures;

    /** Columns with an index that starts with them (phone and ip also carry created_at). */
    private const COUNT_FIELDS = ['phone', 'ip', 'order_id', 'parent_order_id'];

    /** @var LogStoreInterface */
    private $store;

    /** @var callable|null returns the current Unix time */
    private $clock;

    /**
     * @param callable|null $clock Returns the current Unix time (tests); null = time()
     */
    public function __construct(LogStoreInterface $store, ?callable $clock = null)
    {
        $this->store = $store;
        $this->clock = $clock;
    }

    /**
     * @param array<string, mixed> $row Columns of kwtsms_attempts plus the optional secret_values list
     *
     * @return int The new attempt_id, or 0 when the row could not be written
     */
    public function record(array $row): int
    {
        try {
            $secrets = isset($row['secret_values']) && is_array($row['secret_values']) ? $row['secret_values'] : [];
            unset($row['secret_values']);
            $row += ['created_at' => $this->now()];
            if ($secrets !== [] && isset($row['reason']) && is_string($row['reason'])) {
                $row['reason'] = Redactor::mask($row['reason'], $secrets);
            }

            return $this->store->insert(Schema::ATTEMPTS, RowCleaner::clean(Schema::ATTEMPTS, $row));
        } catch (\Throwable $e) {
            $this->reportFailure($e);

            return 0;
        }
    }

    /**
     * Attempts in the last $seconds (the window start is inclusive).
     *
     * @param string   $field    phone, ip, order_id, parent_order_id, or '' to count every attempt
     * @param string   $value    Ignored when $field is ''
     * @param string[] $outcomes Count only these outcomes; empty = any
     *
     * @throws \InvalidArgumentException For any other field (it would have no index)
     */
    public function countRecent(string $field, string $value, int $seconds, array $outcomes = []): int
    {
        if ($field !== '' && !in_array($field, self::COUNT_FIELDS, true)) {
            throw new \InvalidArgumentException('countRecent() cannot count by "' . $field . '"');
        }
        if ($seconds < 1) {
            return 0;
        }
        $filters = ['created_from' => $this->now() - $seconds];
        if ($field !== '') {
            $filters[$field] = $value;
        }
        $outcomes = array_values(array_filter(array_map('strval', $outcomes), static function (string $outcome): bool {
            return $outcome !== '';
        }));
        if ($outcomes !== []) {
            $filters['outcome'] = $outcomes;
        }

        return $this->store->count(Schema::ATTEMPTS, $filters);
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }
}
