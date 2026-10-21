<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/LogClearer.php
 * CREATED:   2026-10-21 05:51 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      "Clear" of the Logs page, one tab at a time: all rows (TRUNCATE), rows older than N days, or the rows of
 *            the
 *            current filter (5,000-row DELETEs), with the row count shown first (preview) and the logs.cleared audit
 *            row written after the delete.
 * WHY:       PRD 6.8 and 11. The preview counts the rows and freezes what the delete will match (the cut-off of "older
 *            than", the effective period of a filter: its end at the moment of the preview and the search horizon),
 *            so the POST deletes exactly the counted rows, never rows that arrived after the count. The audit row
 *            (action logs.cleared: admin, table, scope, counts; never a phone number or a search text) is immune to
 *            every clear (AdminLogStoreInterface) and to retention under 365 days (DbLogStore::pruneChunk). A partial
 *            clear stops after TIME_BUDGET seconds and says how much is left.
 * USAGE:     $preview = $clearer->preview(LogFilters::parse($_REQUEST, ...), $page->plan(...), 'older', '30');
 *            // ['scope' => 'older', 'count' => 1234, 'fields' => [hidden POST fields], 'error' => null, ...]
 *            $result = $clearer->clear($_POST, $admin_id, $default_cc);
 *            // ['ok' => true, 'deleted' => 1234, 'counted' => 1234, 'left' => false, 'audit' => true, 'tab' => ...]
 * DEPENDS:   Admin\LogFilters, Log\{AdminLogStoreInterface,LogStoreInterface,Schema,DebugLog}.
 */

namespace Tygh\Addons\Kwtsms\Admin;

use Tygh\Addons\Kwtsms\Log\AdminLogStoreInterface;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Log\LogStoreInterface;

class LogClearer
{
    public const SCOPES = ['all', 'older', 'filter'];

    public const DAYS_DEFAULT = 30;
    public const DAYS_MAX = 3650;

    /** Seconds a partial clear may delete for in one request; the rest is cleared by the next click. */
    public const TIME_BUDGET = 20.0;

    /** Request keys of a filter that hold personal data: the audit row says they were set, never their value. */
    private const PRIVATE_KEYS = ['phone', 'q'];

    /** @var LogStoreInterface&AdminLogStoreInterface */
    private $store;

    /** @var DebugLog */
    private $log;

    /** @var callable|null */
    private $clock;

    /** @var callable|null returns microtime(true) (tests) */
    private $timer;

    /**
     * @param LogStoreInterface&AdminLogStoreInterface $store
     */
    public function __construct($store, DebugLog $log, ?callable $clock = null, ?callable $timer = null)
    {
        if (!$store instanceof LogStoreInterface || !$store instanceof AdminLogStoreInterface) {
            throw new \InvalidArgumentException('Clear needs a store with the admin operations');
        }
        $this->store = $store;
        $this->log = $log;
        $this->clock = $clock;
        $this->timer = $timer;
    }

    /**
     * @param \ArrayAccess<string,mixed> $app Tygh::$app
     */
    public static function fromContainer($app): self
    {
        return new self($app['kwtsms.logs.store'], $app['kwtsms.logs.debug']);
    }

    /**
     * The count shown before anything is deleted, and the hidden fields of the confirmation form.
     *
     * @param array<string,mixed>                                $parsed LogFilters::parse()
     * @param array{filters: array<string,mixed>, horizon: ?int} $plan   LogsPage::plan() of those filters
     * @param mixed                                              $days   The "older than" days as typed
     *
     * @return array<string,mixed>
     */
    public function preview(array $parsed, array $plan, string $scope, $days = null): array
    {
        $tab = (string) $parsed['tab'];
        $table = (string) $parsed['table'];
        $out = [
            'scope' => $scope, 'tab' => $tab, 'count' => 0, 'days' => 0, 'cutoff' => 0, 'fields' => [], 'error' => null,
        ];
        if (!in_array($scope, self::SCOPES, true)) {
            $out['scope'] = '';
            $out['error'] = 'kwtsms.logs.clear.error_scope';

            return $out;
        }
        $fields = ['tab' => $tab, 'scope' => $scope];
        if ($scope === 'all') {
            $out['count'] = $this->store->countClearable($table, []);
        } elseif ($scope === 'older') {
            $n = self::days($days);
            if ($n === null) {
                $out['error'] = 'kwtsms.logs.clear.error_days';

                return $out;
            }
            $cutoff = $this->now() - $n * 86400;
            $out['days'] = $n;
            $out['cutoff'] = $cutoff;
            $out['count'] = $this->store->countClearable($table, ['created_to' => $cutoff - 1]);
            $fields += ['days' => (string) $n, 'cutoff' => (string) $cutoff];
        } else {
            $filters = $plan['filters'];
            if (
                $parsed['errors'] !== [] || array_diff(array_keys($filters), ['created_from', 'created_to']) === []
                && !isset($filters['created_from']) && !isset($filters['created_to'])
            ) {
                $out['error'] = 'kwtsms.logs.clear.error_filter';

                return $out;
            }
            // Freeze the period: its end is now (rows that arrive later are not counted, so they are not deleted) and
            // its start is the search horizon when one applied.
            $filters['created_to'] = isset($filters['created_to'])
                ? min((int) $filters['created_to'], $this->now())
                : $this->now();
            $out['count'] = $this->store->countClearable($table, $filters);
            $query = $parsed['query'];
            unset($query['period_from'], $query['period_to'], $query['tab']);
            $query['created_to'] = (string) $filters['created_to'];
            if (isset($filters['created_from'])) {
                $query['created_from'] = (string) $filters['created_from'];
            }
            $fields += $query;
        }
        $out['fields'] = $fields;

        return $out;
    }

    /**
     * Deletes what a confirmed preview counted and writes the audit row after the delete.
     *
     * @param array<string,mixed> $posted The confirmation form (the hidden fields of preview())
     *
     * @return array<string,mixed>
     */
    public function clear(array $posted, int $admin_id, string $default_cc): array
    {
        $started = $this->micro();
        $scope = isset($posted['scope']) && is_string($posted['scope']) ? $posted['scope'] : '';
        $parsed = LogFilters::parse($posted, $default_cc, $this->now());
        $tab = (string) $parsed['tab'];
        $table = (string) $parsed['table'];
        $out = [
            'ok' => false, 'tab' => $tab, 'scope' => $scope, 'counted' => 0, 'deleted' => 0, 'left' => false,
            'audit' => false, 'error' => null,
        ];
        $audit = ['table' => $tab, 'scope' => $scope, 'admin_id' => $admin_id];

        if ($scope === 'all') {
            $out['deleted'] = $out['counted'] = $this->store->truncate($table);
        } elseif ($scope === 'older') {
            $days = self::days($posted['days'] ?? null);
            $cutoff = isset($posted['cutoff']) && is_string($posted['cutoff']) && ctype_digit($posted['cutoff'])
                ? (int) $posted['cutoff'] : 0;
            if ($days === null || $cutoff < 1 || $cutoff > $this->now()) {
                $out['error'] = 'kwtsms.logs.clear.error_days';

                return $out;
            }
            $filters = ['created_to' => $cutoff - 1];
            $out['counted'] = $this->store->countClearable($table, $filters);
            [$out['deleted'], $out['left']] = $this->deleteAll($table, $filters, $started);
            $audit += ['days' => $days, 'cutoff' => $cutoff];
        } elseif ($scope === 'filter') {
            $filters = $parsed['filters'];
            if (
                $parsed['errors'] !== [] || !isset($filters['created_to'])
                || (array_diff(array_keys($filters), ['created_from', 'created_to']) === []
                    && !isset($filters['created_from']))
            ) {
                $out['error'] = 'kwtsms.logs.clear.error_filter';

                return $out;
            }
            $out['counted'] = $this->store->countClearable($table, $filters);
            [$out['deleted'], $out['left']] = $this->deleteAll($table, $filters, $started);
            $audit['filters'] = self::auditFilters($parsed['query']);
        } else {
            $out['error'] = 'kwtsms.logs.clear.error_scope';

            return $out;
        }
        $out['ok'] = true;

        // PRD 6.8: the audit row is written after the delete; it is immune to every clear.
        $audit += ['counted' => $out['counted'], 'deleted' => $out['deleted'], 'left' => $out['left']];
        $id = $this->log->log(
            'info',
            LogStoreInterface::AUDIT_ACTION,
            'Logs cleared: ' . $tab . ', ' . $scope . ', ' . $out['deleted'] . ' rows',
            $audit,
            (int) round(($this->micro() - $started) * 1000)
        );
        $out['audit'] = $id !== null && $id > 0;
        if (!$out['audit']) {
            error_log('kwtsms: the audit row of a log clear could not be written: ' . (string) json_encode($audit));
        }

        return $out;
    }

    /**
     * 5,000-row DELETEs until a short chunk (nothing left) or the time budget.
     *
     * @param array<string,mixed> $filters
     *
     * @return array{0:int,1:bool} [rows deleted, rows left]
     */
    private function deleteAll(string $table, array $filters, float $started): array
    {
        $deleted = 0;
        while (true) {
            $n = $this->store->deleteChunk($table, $filters, LogStoreInterface::DELETE_CHUNK);
            $deleted += $n;
            if ($n < LogStoreInterface::DELETE_CHUNK) {
                return [$deleted, false];
            }
            if ($this->micro() - $started >= self::TIME_BUDGET) {
                return [$deleted, true];
            }
        }
    }

    /**
     * The days of "older than": 1..3650, or null.
     *
     * @param mixed $raw
     */
    public static function days($raw): ?int
    {
        if ($raw === null || $raw === '') {
            return self::DAYS_DEFAULT;
        }
        if ((!is_string($raw) && !is_int($raw)) || preg_match('/^[0-9]{1,4}$/D', (string) $raw) !== 1) {
            return null;
        }
        $n = (int) $raw;

        return $n >= 1 && $n <= self::DAYS_MAX ? $n : null;
    }

    /**
     * The filter of a clear as the audit row keeps it: every key, but a phone number or a search text only as "set".
     *
     * @param array<string,mixed> $query LogFilters query
     *
     * @return array<string,mixed>
     */
    public static function auditFilters(array $query): array
    {
        unset($query['tab']);
        foreach (self::PRIVATE_KEYS as $key) {
            if (isset($query[$key])) {
                $query[$key] = '(set)';
            }
        }

        return $query;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }

    private function micro(): float
    {
        return $this->timer === null ? microtime(true) : (float) call_user_func($this->timer);
    }
}
