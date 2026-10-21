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
     * @return array{rows:int,filters:string[],horizon:bool}
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
        while (true) {
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

        return ['rows' => $rows, 'filters' => array_keys($parsed['filters']), 'horizon' => $plan['horizon'] !== null];
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
