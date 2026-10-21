<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/LogExport.php
 * CREATED:   2026-10-21 05:52 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      CSV export of the Messages log with the current filters of the Logs page, streamed in chunks of 1,000
 *            rows, UTF-8 with a byte order mark (Arabic opens correctly in spreadsheet programs).
 * WHY:       PRD 6.8: "CSV export of Messages with the current filters (gateway privilege); cells starting with
 *            = + - @ TAB CR are prefixed with '; masked rows stay masked". The rows are exactly what the list shows
 *            (same LogFilters map, same search horizon), read newest first by created_at and message id without an
 *            offset (keyset), and never the raw request or reply. The stored text is exported as stored, so a masked
 *            row (OTP code, reset link) stays ******.
 *            2026-10-21 08:08 +03, Task 1.14 fix round 1 (item 8): when the search horizon cut the period, the file
 *            ends with a NOTE line that says so and from when; before each further chunk the time left under
 *            max_execution_time is checked, and an export that runs out stops after a whole chunk with a NOTE line
 *            "export truncated at N rows" instead of a file cut mid-row by a fatal error.
 * USAGE:     $rows = $export->stream($_REQUEST, static function (string $line): void { echo $line; });
 *            LogExport::cell('=1+1') -> "'=1+1";  LogExport::line(['a', 'b"c']) -> "a,\"b\"\"c\"\r\n"
 *            LogExport::filename(time()) -> 'kwtsms-messages-20261021-063500.csv'
 * DEPENDS:   Admin\{LogFilters,LogsPage}, Log\{AdminLogStoreInterface,LogStoreInterface,Schema},
 *            Settings\ConfigInterface.
 */

namespace Tygh\Addons\Kwtsms\Admin;

use Tygh\Addons\Kwtsms\Log\AdminLogStoreInterface;
use Tygh\Addons\Kwtsms\Log\LogStoreInterface;
use Tygh\Addons\Kwtsms\Log\Schema;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

class LogExport
{
    public const CHUNK = 1000;

    /** The UTF-8 byte order mark that starts the file. */
    public const BOM = "\xEF\xBB\xBF";

    /** Columns of the file: language-neutral names, never api_request or api_response. */
    public const COLUMNS = [
        'message_id', 'store_time', 'kwtsms_time', 'event_id', 'receiver', 'source', 'order_id', 'parent_order_id',
        'storefront_id', 'company_id', 'sender', 'recipients', 'recipient_count', 'numbers_accepted', 'message',
        'masked', 'lang_code', 'encoding', 'pages', 'is_test', 'status', 'api_code', 'code_source', 'api_msg_id',
        'points_charged', 'balance_after', 'duration_ms',
    ];

    /** First characters a spreadsheet reads as a formula or a control (PRD 6.8). */
    private const FORMULA_START = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * 2026-10-21 08:08 +03: the last lines of a cut file (English, like the column names), fix round 1 item 8,
     * task 596.
     */
    public const NOTE_HORIZON = 'NOTE: search horizon applied: only the newest %s rows of the period were searched,'
        . ' from %s (Kuwait time). Narrow the period to export older rows.';
    public const NOTE_TRUNCATED = 'NOTE: export truncated at %d rows: the time limit of this server was reached.'
        . ' Narrow the filters and export again.';

    /** Seconds kept free under max_execution_time for the last lines and the response. */
    private const TIME_MARGIN = 5;

    /** @var float when this export started (when the request start time is unknown) */
    private $started;

    /** @var ConfigInterface */
    private $config;

    /** @var LogStoreInterface&AdminLogStoreInterface */
    private $store;

    /** @var LogsPage */
    private $page;

    /** @var callable|null */
    private $clock;

    /**
     * @param LogStoreInterface&AdminLogStoreInterface $store
     */
    public function __construct(ConfigInterface $config, $store, LogsPage $page, ?callable $clock = null)
    {
        if (!$store instanceof LogStoreInterface || !$store instanceof AdminLogStoreInterface) {
            throw new \InvalidArgumentException('The export needs a store with the admin operations');
        }
        $this->config = $config;
        $this->store = $store;
        $this->page = $page;
        $this->clock = $clock;
        $this->started = microtime(true);
    }

    /**
     * @param \ArrayAccess<string,mixed> $app Tygh::$app
     */
    public static function fromContainer($app): self
    {
        return new self($app['kwtsms.config'], $app['kwtsms.logs.store'], LogsPage::fromContainer($app));
    }

    /**
     * Writes the BOM, the header line and every matching message row through $write.
     *
     * @param array<string,mixed>    $request $_REQUEST (the filters of the Messages tab; the tab key is ignored)
     * @param callable(string): void $write
     *
     * @return array{rows:int,filters:string[],horizon:bool,truncated:bool}
     */
    public function stream(array $request, callable $write): array
    {
        $request['tab'] = 'messages';
        $parsed = LogFilters::parse($request, $this->config->defaultCountryCode(), $this->now());
        $plan = $this->page->plan(Schema::MESSAGES, $parsed['filters']);
        $filters = $plan['filters'];
        $write(self::BOM . self::line(self::COLUMNS));

        $rows = 0;
        $last_time = null;
        $last_id = 0;
        $truncated = false;
        while (true) {
            // 2026-10-21 08:08 +03: the first chunk always goes; before each further one, the time left is checked
            // (fix round 1, item 8), task 596.
            if ($last_time !== null && $this->outOfTime()) {
                $truncated = true;
                break;
            }
            $chunk_filters = $filters;
            if ($last_time !== null) {
                $chunk_filters['created_to'] = $last_time;
            }
            $chunk = $this->store->page(Schema::MESSAGES, $chunk_filters, self::CHUNK, 0, false);
            $fresh = 0;
            foreach ($chunk as $row) {
                $time = (int) $row['created_at'];
                $id = (int) $row['message_id'];
                // Keyset: rows of the second already started were written by the previous chunk (id descending).
                if ($last_time !== null && $time === $last_time && $id >= $last_id) {
                    continue;
                }
                $write(self::line($this->cells($row)));
                $rows++;
                $fresh++;
                $last_time = $time;
                $last_id = $id;
            }
            if (count($chunk) < self::CHUNK) {
                break;
            }
            if ($fresh === 0) {
                // More than CHUNK rows share one second (never seen: sends are paced at 5 per second); step past it.
                $last_time = (int) $last_time - 1;
                $last_id = PHP_INT_MAX;
                if ($last_time < 0) {
                    break;
                }
            }
        }

        // 2026-10-21 08:08 +03: a cut file says so in its last lines (fix round 1, item 8), task 596.
        if ($plan['horizon'] !== null) {
            $write(self::line([sprintf(
                self::NOTE_HORIZON,
                number_format(AdminLogStoreInterface::HORIZON_ROWS),
                LogsPage::kuwaitTime((int) $plan['horizon'])
            )]));
        }
        if ($truncated) {
            $write(self::line([sprintf(self::NOTE_TRUNCATED, $rows)]));
        }

        return [
            'rows' => $rows, 'filters' => array_keys($parsed['filters']), 'horizon' => $plan['horizon'] !== null,
            'truncated' => $truncated,
        ];
    }

    /**
     * 2026-10-21 08:08 +03: true when the request has used its time budget (wall time since the request started, which
     * is never less than the CPU time max_execution_time counts on Linux). Fix round 1, item 8, task 596.
     */
    protected function outOfTime(): bool
    {
        $budget = self::budget((int) ini_get('max_execution_time'));
        if ($budget <= 0.0) {
            return false;
        }
        $start = isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : $this->started;

        return microtime(true) - $start >= $budget;
    }

    /**
     * 2026-10-21 08:08 +03: seconds an export may run under a max_execution_time (0 = no limit): the limit minus a
     * margin for the last lines, never under one second. Fix round 1, item 8, task 596.
     */
    public static function budget(int $max_execution_time): float
    {
        if ($max_execution_time <= 0) {
            return 0.0;
        }

        return (float) max(1, $max_execution_time - self::TIME_MARGIN);
    }

    /**
     * @param array<string,mixed> $row A message row of the list (no raw columns)
     *
     * @return string[]
     */
    private function cells(array $row): array
    {
        $out = [];
        foreach (self::COLUMNS as $column) {
            if ($column === 'store_time') {
                $out[] = date('Y-m-d H:i:s', (int) $row['created_at']);
            } elseif ($column === 'kwtsms_time') {
                $out[] = LogsPage::kuwaitTime((int) $row['created_at']);
            } else {
                $out[] = (string) ($row[$column] ?? '');
            }
        }

        return $out;
    }

    /** A cell that a spreadsheet would read as a formula gets a leading apostrophe (PRD 6.8). */
    public static function cell(string $value): string
    {
        return $value !== '' && in_array($value[0], self::FORMULA_START, true) ? "'" . $value : $value;
    }

    /**
     * One CSV line (RFC 4180: comma, double quotes doubled, CRLF), every cell through cell().
     *
     * @param array<int,string> $cells
     */
    public static function line(array $cells): string
    {
        $out = [];
        foreach ($cells as $value) {
            $value = self::cell((string) $value);
            $out[] = preg_match('/[",\r\n]/', $value) === 1 || $value !== trim($value)
                ? '"' . str_replace('"', '""', $value) . '"'
                : $value;
        }

        return implode(',', $out) . "\r\n";
    }

    public static function filename(int $now): string
    {
        return 'kwtsms-messages-' . date('Ymd-His', $now) . '.csv';
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }
}
