<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/LogsPage.php
 * CREATED:   2026-10-21 05:50 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The Logs page (kwtsms.logs) and the detail view (kwtsms.log_details): the three tabs (Messages, Attempts,
 *            Debug) with their filters, a page of rows with store time and kwtSMS time (Asia/Kuwait), the TEST badge,
 *            canonical outcome labels ("Accepted by kwtSMS" with its tooltip, never "Delivered"), paging, the search
 *            horizon notice, the clear preview, and the detail of one row: the raw request (redacted again) and reply
 *            with the truncated flag, the attempts of a message, the resend verdict, and the "Copy details for kwtSMS
 *            support" text.
 * WHY:       PRD 6.8, 10, 11 and Task 1.14. The controller stays a thin gate and router; the rules live here. Filters
 *            come from Admin\LogFilters (fixed key map); a filter set that no index orders by time is answered from
 *            the newest AdminLogStoreInterface::HORIZON_ROWS rows of the period only, and the page says where the
 *            search stopped (every list and count stays under a second on 1M attempts and 2M debug rows, ruling R35).
 *            Lists never read the raw request and reply (DbLogStore::page()); the detail view reads them and redacts
 *            the saved credentials once more before showing them. The support text never holds the password.
 * USAGE:     $page = LogsPage::fromContainer(Tygh::$app);
 *            $data = $page->viewData($_REQUEST, $can_gateway);              // GET kwtsms.logs
 *            $data = $page->details('messages', 15, $can_gateway);          // GET kwtsms.log_details (null: no row)
 *            LogsPage::needsHorizon(Schema::ATTEMPTS, $filters);           // pure
 *            LogsPage::truncation($raw);                                   // [bool truncated, int original bytes]
 * DEPENDS:   Admin\{LogFilters,LogClearer,LogResender}, Log\{AdminLogStoreInterface,LogStoreInterface,Schema,Redactor,
 *            DebugLog}, Settings\ConfigInterface, Sms\SmsService::OUTCOMES; CS-Cart __(), fn_get_simple_statuses(),
 *            the storefront repository (through protected seams a test can replace).
 */

namespace Tygh\Addons\Kwtsms\Admin;

use Tygh\Addons\Kwtsms\Log\AdminLogStoreInterface;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Log\LogStoreInterface;
use Tygh\Addons\Kwtsms\Log\Redactor;
use Tygh\Addons\Kwtsms\Log\Schema;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\ApiErrors;
use Tygh\Addons\Kwtsms\Sms\SmsService;

class LogsPage
{
    /** Attempts of one message the detail view lists. */
    public const DETAIL_ATTEMPTS = 200;

    /** Characters of a message or debug text a list cell shows (the detail view shows all). */
    public const PREVIEW_CHARS = 90;

    /** Numbers of a message the list shows before "+N more". */
    public const PREVIEW_NUMBERS = 3;

    /** The kwtSMS clock (PRD 6.8: every row shows store time and kwtSMS time). */
    public const KWTSMS_TIMEZONE = 'Asia/Kuwait';

    /** RowCleaner's marker at the end of a raw copy cut at 64 KB. */
    private const TRUNCATED = '/ \[truncated, (\d+) bytes\]$/D';

    /**
     * Attempt reasons with a plain-language text (kwtsms.logs.reason.<reason>, EN and AR). 2026-10-21 12:56 +03:
     * added, review M2, task 596.
     */
    // 2026-10-21 14:10 +03: also the reasons whose outcome text would mislead or says nothing (a deferred row:
    // "the reason is in the log", which is where the reader is already): the gateway could not be reached, sending
    // is paused, quiet hours, too many calls for one request, the outbox could not take the row. Cleanup 2 item 5,
    // task 596.
    public const LABELLED_REASONS = [
        SmsService::CAP_RESERVED, 'cap_unavailable', 'gateway_unreachable', 'suspended', 'quiet_hours',
        'request_overflow', 'outbox_unavailable',
        // 2026-10-22 14:53 +03: a storefront turned the SMS off for itself (P2-1), Task A2, task 596.
        'storefront_switch',
        // 2026-10-22 17:13 +03: the vendor receiver has no approved number; a declared data_class is missing (R16),
        // Task A4, task 596.
        'vendor_not_approved', 'platform_missing',
        // 2026-10-22 19:10 +03: a password-recovery SMS inside the 10-minute window of the account (R5), Task A6,
        // task 596.
        'recover_throttled',
        // 2026-10-22 21:29 +03: a product that already alerted in the last 24 h (Task A7), task 596.
        'low_stock_window',
        // 2026-10-22 20:27 +03: a registration SMS skipped because the core CAPTCHA for registration is off
        // (P2-R12), Task A6 fix round 1.
        'antibot_required',
        // 2026-10-22 22:47 +03: a vendor SMS over the vendor's monthly quota (P2-6), Task A8, task 596.
        'vendor_quota',
        // 2026-10-23 00:56 +03: a customer who turned off order status SMS in their profile (P2-4), Task A10, task 596.
        'customer_opt_out',
    ];

    /**
     * A kwtSMS or add-on code inside a reason: ERR010 of "ERR010: Zero balance", LOCAL_CONNECT of
     * "gateway_unreachable: LOCAL_CONNECT", ERR003 of "ERR003_content"; the first one wins.
     * 2026-10-21 14:10 +03: added, cleanup 2 item 5, task 596.
     */
    private const REASON_CODE = '/(?<![A-Za-z0-9_])(ERR[0-9]{3}|LOCAL_[A-Z]+(?:_[A-Z]+)*)(?![A-Za-z0-9])/';

    /**
     * Reasons of a blocked_not_configured attempt: the texts of the Dashboard connection card (kwtsms.reason.<id>).
     * 2026-10-21 14:10 +03: added, cleanup 2 item 5, task 596.
     */
    private const NOT_CONFIGURED_REASONS = [
        'no_credentials', 'relogin_required', 'not_logged_in', 'no_sender_ids', 'sender_not_in_list', 'sender_banned',
    ];

    /** @var ConfigInterface */
    private $config;

    /** @var LogStoreInterface&AdminLogStoreInterface */
    private $store;

    /** @var DebugLog */
    private $log;

    /** @var LogClearer */
    private $clearer;

    /** @var LogResender|null */
    private $resender;

    /** @var callable|null */
    private $clock;

    /** @var array<string,string>|null */
    private $statuses = null;

    /**
     * @param LogStoreInterface&AdminLogStoreInterface $store
     * @param callable|null                            $clock Returns the current Unix time (tests); null = time()
     */
    public function __construct(
        ConfigInterface $config,
        $store,
        DebugLog $log,
        LogClearer $clearer,
        ?LogResender $resender = null,
        ?callable $clock = null
    ) {
        if (!$store instanceof LogStoreInterface || !$store instanceof AdminLogStoreInterface) {
            throw new \InvalidArgumentException('The Logs page needs a store with the admin operations');
        }
        $this->config = $config;
        $this->store = $store;
        $this->log = $log;
        $this->clearer = $clearer;
        $this->resender = $resender;
        $this->clock = $clock;
    }

    /**
     * @param \ArrayAccess<string,mixed> $app Tygh::$app
     */
    public static function fromContainer($app): self
    {
        return new self(
            $app['kwtsms.config'],
            $app['kwtsms.logs.store'],
            $app['kwtsms.logs.debug'],
            LogClearer::fromContainer($app),
            LogResender::fromContainer($app)
        );
    }

    // ================================================================== GET kwtsms.logs

    /**
     * Everything the list template shows.
     *
     * @param array<string,mixed> $request $_REQUEST
     *
     * @return array<string,mixed>
     */
    public function viewData(array $request, bool $can_gateway): array
    {
        $started = microtime(true);
        $parsed = LogFilters::parse($request, $this->config->defaultCountryCode(), $this->now());
        $table = $parsed['table'];
        $plan = $this->plan($table, $parsed['filters']);
        $per_page = $parsed['items_per_page'];
        $offset = ($parsed['page'] - 1) * $per_page;

        $total = $this->store->count($table, $plan['filters']);
        $rows = $offset < $total || $offset === 0
            ? $this->store->page($table, $plan['filters'], $per_page, $offset, $parsed['sort_order'] === 'asc')
            : [];
        $pages = max(1, (int) ceil($total / $per_page));
        $last_page = min($pages, intdiv(LogFilters::MAX_OFFSET, $per_page) + 1);

        $notices = [];
        if ($plan['horizon'] !== null) {
            $notices[] = $this->text('kwtsms.logs.notice.horizon', [
                '[rows]' => number_format(AdminLogStoreInterface::HORIZON_ROWS),
                '[date]' => self::kuwaitTime($plan['horizon']),
            ]);
        }
        if ($pages > $last_page) {
            $notices[] = $this->text('kwtsms.logs.notice.deep', ['[rows]' => number_format(LogFilters::MAX_OFFSET)]);
        }

        $clear = null;
        if ($can_gateway && isset($request['clear']) && is_string($request['clear'])) {
            $clear = $this->clearer->preview($parsed, $plan, $request['clear'], $request['days'] ?? null);
            $clear['hidden'] = self::fields($clear['fields']);
            $clear['log'] = (string) __('kwtsms.logs.tab.' . $parsed['tab']);
        }
        $view = $parsed['query'] + ['items_per_page' => (string) $per_page, 'sort_order' => $parsed['sort_order']];
        $export = $parsed['query'];
        unset($export['tab']);

        $data = [
            'tab' => $parsed['tab'],
            'tabs' => $this->tabs($parsed['tab']),
            'form' => $parsed['form'],
            'link_range' => array_intersect_key($parsed['query'], ['created_from' => 1, 'created_to' => 1]),
            'active' => $parsed['active'],
            'errors' => array_map(function (string $key): string {
                return $this->text($key);
            }, $parsed['errors']),
            'notices' => $notices,
            'rows' => $this->decorateRows($parsed['tab'], $rows),
            'search' => [
                'page' => $parsed['page'],
                'items_per_page' => $per_page,
                'total_items' => $total,
                'pages' => $pages,
                'last_page' => $last_page,
                'sort_by' => $parsed['sort_by'],
                'sort_order' => $parsed['sort_order'],
            ],
            'per_page_options' => [10, 25, 50, 100, 200],
            'query' => $parsed['query'],
            'query_fields' => self::fields($parsed['query']),
            'prev_url' => $parsed['page'] > 1
                ? 'kwtsms.logs?' . http_build_query($view + ['page' => $parsed['page'] - 1])
                : '',
            'next_url' => $parsed['page'] < $last_page && $parsed['page'] < $pages
                ? 'kwtsms.logs?' . http_build_query($view + ['page' => $parsed['page'] + 1])
                : '',
            'sort_url' => 'kwtsms.logs?' . http_build_query(
                ['sort_order' => $parsed['sort_order'] === 'asc' ? 'desc' : 'asc', 'sort_by' => 'created_at'] + $view
            ),
            'export_url' => 'kwtsms.export_logs?' . http_build_query($export),
            'reset_url' => 'kwtsms.logs?tab=' . $parsed['tab'],
            'list_url' => 'kwtsms.logs?' . http_build_query($view),
            'options' => $this->options($parsed['tab']),
            'can' => ['gateway' => $can_gateway],
            'clear' => $clear,
            'retention' => [
                'messages' => $this->config->retentionMessagesDays(),
                'attempts' => $this->config->retentionAttemptsDays(),
                'debug' => $this->config->retentionDebugDays(),
            ],
            'horizon' => $plan['horizon'],
        ];
        $this->log->log('debug', 'logs.view', $parsed['tab'], [
            'filters' => array_keys($parsed['filters']), 'rows' => count($rows), 'total' => $total,
            'horizon' => $plan['horizon'] !== null,
        ], (int) round((microtime(true) - $started) * 1000));

        return $data;
    }

    /**
     * The filters a list, count, clear or export really runs with: the parsed filters, plus the search horizon when
     * no index orders the filter set by time.
     *
     * @param array<string,mixed> $filters
     *
     * @return array{filters: array<string,mixed>, horizon: ?int}
     */
    public function plan(string $table, array $filters): array
    {
        $horizon = null;
        if (self::needsHorizon($table, $filters)) {
            $from = isset($filters['created_from']) ? (int) $filters['created_from'] : null;
            $to = isset($filters['created_to']) ? (int) $filters['created_to'] : null;
            $edge = $this->store->horizon($table, $from, $to, AdminLogStoreInterface::HORIZON_ROWS);
            if ($edge !== null && ($from === null || $edge > $from)) {
                $filters['created_from'] = $edge;
                $horizon = $edge;
            }
        }

        return ['filters' => $filters, 'horizon' => $horizon];
    }

    /**
     * True when no index reads this filter set in time order, so the list and the count would have to read every row
     * of the period: anything beyond a created_at range, except a lookup that an index makes small (phone, order,
     * message id, kwtSMS msg-id) or one equality on a column whose index ends with created_at (messages status or
     * vendor, attempts outcome, debug level or action).
     *
     * @param array<string,mixed> $filters
     */
    public static function needsHorizon(string $table, array $filters): bool
    {
        $keys = array_values(array_diff(array_keys($filters), ['created_from', 'created_to']));
        if ($keys === []) {
            return false;
        }
        if (array_intersect($keys, ['phone', 'order', 'message_id', 'api_msg_id']) !== []) {
            return false;
        }
        $ordered = [
            Schema::MESSAGES => ['status', 'company_id'],
            Schema::ATTEMPTS => ['outcome'],
            Schema::DEBUG => ['level', 'action'],
        ];
        if (count($keys) === 1 && in_array($keys[0], $ordered[$table] ?? [], true)) {
            $value = $filters[$keys[0]];

            return is_array($value) && count($value) !== 1;
        }

        return true;
    }

    // ================================================================== GET kwtsms.log_details

    /**
     * One row with everything the detail view shows, or null when the tab or the row does not exist.
     *
     * @return array<string,mixed>|null
     */
    public function details(string $tab, int $id, bool $can_gateway): ?array
    {
        if (!isset(LogFilters::TABS[$tab]) || $id < 1) {
            return null;
        }
        $table = LogFilters::TABS[$tab];
        $row = $this->store->find($table, $id);
        if ($row === null) {
            return null;
        }
        $data = [
            'tab' => $tab,
            'tabs' => $this->tabs($tab),
            'id' => $id,
            'row' => $this->decorate($tab, $row, true),
            'can' => ['gateway' => $can_gateway],
            'attempts' => [],
            'message' => null,
            'raw' => [],
            'support' => '',
            'context' => '',
            'resend' => null,
            'resends' => [],
            'outbox' => null,
            'request_url' => '',
        ];
        if ($tab === 'messages') {
            $data['raw'] = $this->raw($row);
            $attempts = $this->store->select(Schema::ATTEMPTS, ['message_id' => $id], self::DETAIL_ATTEMPTS, 0);
            // 2026-10-21 08:07 +03: the verdicts of all recipients at once (a fixed number of reads, not three per
            // recipient), fix round 1 item 7, task 596.
            $verdicts = $can_gateway && $this->resender !== null && $attempts !== []
                ? $this->resender->verdicts($attempts)
                : [];
            foreach ($attempts as $attempt) {
                $one = $this->decorate('attempts', $attempt, false);
                $one['resend'] = $verdicts[(int) $attempt['attempt_id']] ?? null;
                $data['attempts'][] = $one;
            }
            $data['support'] = $this->supportText($row, null);
        } elseif ($tab === 'attempts') {
            $message_id = (int) $row['message_id'];
            $message = $message_id > 0 ? $this->store->find(Schema::MESSAGES, $message_id) : null;
            if ($message !== null) {
                $data['message'] = $this->decorate('messages', $message, true);
                $data['raw'] = $this->raw($message);
            }
            if ($this->resender !== null) {
                $data['outbox'] = $this->resender->outboxState($row);
                $data['resends'] = array_map(function (array $r): array {
                    return $this->decorate('attempts', $r, false);
                }, $this->resender->resendsOf($row));
                if ($can_gateway) {
                    $data['resend'] = $this->resender->verdict($row);
                }
            }
            $data['support'] = $this->supportText($message ?? [], $row);
        } else {
            $data['context'] = self::prettyJson((string) $row['context']);
            // The rows of the same request: a request lasts seconds, so an hour around this row keeps the search small.
            $data['request_url'] = (string) $row['request_id'] === '' ? '' : 'kwtsms.logs?' . http_build_query([
                'tab' => 'debug', 'request_id' => (string) $row['request_id'],
                'created_from' => max(0, (int) $row['created_at'] - 3600),
                'created_to' => (int) $row['created_at'] + 3600,
            ]);
        }

        return $data;
    }

    /**
     * The raw request and reply of a message row, redacted once more with the saved credentials (they were redacted
     * when written; a credential changed since then is caught here), each with its truncated flag.
     *
     * @param array<string,mixed> $message
     *
     * @return array<string,array{text:string,truncated:bool,bytes:int}>
     */
    private function raw(array $message): array
    {
        $secrets = [$this->config->apiUsername(), $this->config->password()];
        $out = [];
        foreach (['request' => 'api_request', 'response' => 'api_response'] as $name => $column) {
            $text = Redactor::redactString((string) ($message[$column] ?? ''), $secrets);
            [$truncated, $bytes] = self::truncation($text);
            $out[$name] = ['text' => $text, 'truncated' => $truncated, 'bytes' => $bytes];
        }

        return $out;
    }

    /**
     * Whether a raw copy was cut at 64 KB (RowCleaner's marker " [truncated, N bytes]" at its end) and its size before
     * the cut.
     *
     * @return array{0:bool,1:int}
     */
    public static function truncation(string $text): array
    {
        if (preg_match(self::TRUNCATED, $text, $m) === 1) {
            return [true, (int) $m[1]];
        }

        return [false, strlen($text)];
    }

    /**
     * "Copy details for kwtSMS support" (PRD 6.8): API username, msg-id, Kuwait date-time, sender, numbers, text, test
     * flag and code, in English whatever the admin language (kwtSMS support reads it). Never the password.
     *
     * @param array<string,mixed>      $message A message row ([] when the attempt never reached the API)
     * @param array<string,mixed>|null $attempt An attempt row, for the attempt detail
     */
    public function supportText(array $message, ?array $attempt): string
    {
        $time = (int) ($message['created_at'] ?? ($attempt['created_at'] ?? 0));
        $numbers = $attempt !== null ? (string) $attempt['phone'] : (string) ($message['recipients'] ?? '');
        $code = (string) ($message['api_code'] ?? '');
        $lines = [
            'title' => '',
            'username' => $this->config->apiUsername(),
            'msg_id' => (string) ($message['api_msg_id'] ?? ''),
            'time' => $time > 0 ? self::kuwaitTime($time) : '',
            'sender' => (string) ($message['sender'] ?? ''),
            'numbers' => $numbers,
            'text' => (string) ($message['message'] ?? ''),
            'test' => (string) (($message['is_test'] ?? ($attempt['is_test'] ?? 'N')) === 'Y' ? 'yes' : 'no'),
            'code' => $code !== '' ? $code : (string) ($message['status'] ?? ''),
        ];
        if ($attempt !== null) {
            $reason = (string) $attempt['reason'];
            $lines['outcome'] = (string) $attempt['outcome'] . ($reason !== '' ? ' (' . $reason . ')' : '');
        }
        $out = [];
        foreach ($lines as $key => $value) {
            $label = (string) __('kwtsms.logs.support.' . $key, [], 'en');
            $out[] = $key === 'title' ? $label : $label . ': ' . ($value === '' ? '-' : $value);
        }
        // The password is never written; the saved one is replaced if it ever appears in a value.
        $text = implode("\n", $out);

        return $this->config->password() === '' ? $text : Redactor::redactString($text, [$this->config->password()]);
    }

    /**
     * Request keys as hidden form fields: a list becomes key[] once per item.
     *
     * @param array<string,mixed> $query
     *
     * @return array<int,array{name:string,value:string}>
     */
    public static function fields(array $query): array
    {
        $out = [];
        foreach ($query as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $out[] = ['name' => $name . '[]', 'value' => (string) $item];
                }
            } else {
                $out[] = ['name' => (string) $name, 'value' => (string) $value];
            }
        }

        return $out;
    }

    /** A Unix time on the kwtSMS clock (Asia/Kuwait), Y-m-d H:i:s. */
    public static function kuwaitTime(int $timestamp): string
    {
        $date = new \DateTime('@' . $timestamp);
        $date->setTimezone(new \DateTimeZone(self::KWTSMS_TIMEZONE));

        return $date->format('Y-m-d H:i:s');
    }

    /** A JSON text indented for reading; any other text as it is. */
    public static function prettyJson(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return $text;
        }
        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($pretty) ? $pretty : $text;
    }

    // ================================================================== notices of the POST actions

    /**
     * The notifications after a Clear: what was deleted, what is left, and a warning when the audit row could not be
     * written. Values escaped (PRD 10).
     *
     * @param array<string,mixed> $result LogClearer::clear()
     *
     * @return array<int,array{0:string,1:string}> [type N|W|E, text]
     */
    public function clearNotices(array $result): array
    {
        if (empty($result['ok'])) {
            return [['E', $this->text((string) ($result['error'] ?: 'kwtsms.logs.clear.error_scope'))]];
        }
        $tab_id = isset(LogFilters::TABS[$result['tab']]) ? (string) $result['tab'] : 'messages';
        $tab = (string) __('kwtsms.logs.tab.' . $tab_id);
        $out = [[
            'N',
            // 2026-10-21 17:28 +03: plural forms, the number first (final review M5), task 596.
            $this->text('kwtsms.logs.clear.done', [
                0 => (int) $result['deleted'],
                '[count]' => number_format((int) $result['deleted']),
                '[log]' => $tab,
            ]),
        ]];
        if (!empty($result['left'])) {
            $out[] = ['W', $this->text('kwtsms.logs.clear.left')];
        }
        if (empty($result['audit'])) {
            $out[] = ['W', $this->text('kwtsms.logs.clear.audit_failed')];
        }

        return $out;
    }

    /**
     * The notification after a Resend: the outcome of the new attempt, or why it was refused.
     *
     * @param array<string,mixed> $result LogResender::resend()
     *
     * @return array{0:string,1:string}
     */
    public function resendNotice(array $result): array
    {
        if (empty($result['ok'])) {
            return ['E', $this->text((string) ($result['reason'] ?: 'kwtsms.logs.resend.failed'))];
        }
        $outcome = (string) $result['outcome'];
        $type = in_array($outcome, ['accepted', 'test_queued', 'partial_accept', 'deferred'], true) ? 'N' : 'W';

        return [$type, $this->text('kwtsms.logs.resend.done', [
            '[outcome]' => $this->outcomeLabel($outcome),
            '[reason]' => $this->outcomeTip($outcome),
        ])];
    }

    // ================================================================== rows and labels

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    private function decorateRows(string $tab, array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->decorate($tab, $row, false);
        }

        return $out;
    }

    /**
     * A row as the templates show it: plain values (auto-escaped by Smarty), labels, kwtSMS time, previews.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public function decorate(string $tab, array $row, bool $full): array
    {
        $pk = Schema::pk(LogFilters::TABS[$tab] ?? Schema::MESSAGES);
        $out = [
            'id' => (int) ($row[$pk] ?? 0),
            'created_at' => (int) $row['created_at'],
            'kwt_time' => self::kuwaitTime((int) $row['created_at']),
            'is_test' => (string) ($row['is_test'] ?? 'N') === 'Y',
        ];
        if ($tab === 'debug') {
            $message = (string) $row['message'];

            return $out + [
                'level' => (string) $row['level'],
                'action' => (string) $row['action'],
                'message' => $full ? $message : self::preview($message, self::PREVIEW_CHARS),
                'duration_ms' => (int) $row['duration_ms'],
                'request_id' => (string) $row['request_id'],
                'user_id' => (int) $row['user_id'],
                'audit' => (string) $row['action'] === LogStoreInterface::AUDIT_ACTION,
            ];
        }
        $outcome = (string) ($tab === 'messages' ? $row['status'] : $row['outcome']);
        $out += [
            'event_id' => (string) $row['event_id'],
            'event_label' => $this->eventLabel((string) $row['event_id']),
            'receiver' => (string) $row['receiver'],
            'receiver_label' => $this->receiverLabel((string) $row['receiver']),
            'source' => (string) $row['source'],
            'order_id' => (int) $row['order_id'],
            'parent_order_id' => (int) $row['parent_order_id'],
            'storefront_id' => (int) $row['storefront_id'],
            'company_id' => (int) $row['company_id'],
            'outcome' => $outcome,
            'outcome_label' => $this->outcomeLabel($outcome),
            'outcome_tip' => $this->outcomeTip($outcome),
            'outcome_kind' => OutcomeGroups::kind($outcome),
        ];
        if ($tab === 'messages') {
            $numbers = array_map('trim', explode(',', (string) $row['recipients']));
            $numbers = array_values(array_filter($numbers, 'strlen'));
            $message = (string) $row['message'];

            return $out + [
                'numbers' => $full ? $numbers : array_slice($numbers, 0, self::PREVIEW_NUMBERS),
                'numbers_more' => $full ? 0 : max(0, count($numbers) - self::PREVIEW_NUMBERS),
                'recipient_count' => (int) $row['recipient_count'],
                'numbers_accepted' => (int) $row['numbers_accepted'],
                'sender' => (string) $row['sender'],
                'message' => $full ? $message : self::preview($message, self::PREVIEW_CHARS),
                'masked' => (string) $row['masked'] === 'Y',
                'lang_code' => (string) $row['lang_code'],
                'encoding' => (string) $row['encoding'],
                'pages' => (int) $row['pages'],
                // 2026-10-21 14:10 +03: "1 page, GSM (English)", "2 pages, Unicode (Arabic)" for the detail view
                // instead of "1 gsm en"; the raw values above stay for the support text and the export. Cleanup 2
                // item 6, task 596.
                'pages_text' => $this->pagesText(
                    (int) $row['pages'],
                    (string) $row['encoding'],
                    (string) $row['lang_code']
                ),
                'batch_no' => (int) $row['batch_no'],
                'batch_total' => (int) $row['batch_total'],
                'api_code' => (string) $row['api_code'],
                'code_source' => (string) $row['code_source'],
                'api_msg_id' => (string) $row['api_msg_id'],
                'points_charged' => (float) $row['points_charged'],
                'balance_after' => (float) $row['balance_after'],
                'duration_ms' => (int) $row['duration_ms'],
                'user_id' => (int) $row['user_id'],
                'actor_user_id' => (int) $row['actor_user_id'],
            ];
        }

        return $out + [
            'phone' => (string) $row['phone'],
            'phone_raw' => (string) $row['phone_raw'],
            'reason' => (string) $row['reason'],
            // 2026-10-21 12:56 +03: plain-language text of the reasons that have one ('' = show the raw reason),
            // review M2, task 596.
            // 2026-10-21 14:10 +03: every reason gets a text (its code's text, the Dashboard's, or the outcome's)
            // and the code is kept apart to be shown after it: "Your kwtSMS balance is zero. ... (ERR010)".
            // Cleanup 2 item 5, task 596.
            'reason_label' => $this->reasonLabel((string) $row['reason'], $outcome),
            'reason_code' => $this->reasonCode((string) $row['reason']),
            'message_id' => (int) $row['message_id'],
            'outbox_id' => (int) $row['outbox_id'],
            'retry_of' => (int) $row['retry_of'],
            'user_id' => (int) $row['user_id'],
            'actor_user_id' => (int) $row['actor_user_id'],
            'ip' => (string) ($row['ip'] ?? ''),
            'resendable' => in_array($outcome, LogResender::RESENDABLE, true),
        ];
    }

    /** At most $chars characters, with "..." when cut (ASCII, PRD: no ellipsis character). */
    public static function preview(string $text, int $chars): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_strlen($text, 'UTF-8') > $chars ? rtrim(mb_substr($text, 0, $chars, 'UTF-8')) . '...' : $text;
    }

    /**
     * Short label of a canonical outcome (PRD Appendix B), "Accepted by kwtSMS" for accepted, the code itself for a
     * value that is not one.
     */
    public function outcomeLabel(string $outcome): string
    {
        return in_array($outcome, SmsService::OUTCOMES, true)
            ? (string) __('kwtsms.logs.outcome.' . $outcome)
            : $outcome;
    }

    /** Tooltip of an outcome: the PRD 6.8 note for accepted, the plain-language reason for the others. */
    public function outcomeTip(string $outcome): string
    {
        if ($outcome === 'accepted') {
            return (string) __('kwtsms.dash.group.accepted_tip');
        }

        return in_array($outcome, SmsService::OUTCOMES, true) ? (string) __('kwtsms.outcome.' . $outcome) : '';
    }

    /**
     * Plain-language text of an attempt reason, in the admin's language: never an internal id.
     * 2026-10-21 12:56 +03: added for the cap reservation and the cap check that could not run, review M2, task 596.
     * 2026-10-21 14:10 +03: every reason, cleanup 2 item 5, task 596. In this order: the own text of
     * LABELLED_REASONS; the Dashboard's text of a not-set-up reason (blocked_not_configured); the text of the code
     * the reason carries
     * (ApiErrors::describe, the text the Dashboard Problems list shows beside the code), except ERR003_content, where
     * the login was fine and the TEXT was refused; the text of the outcome (kwtsms.outcome.<outcome>, the text the
     * Dashboard Problems list and the outcome tooltip show). '' when the reason is empty, or when it has no code and no
     * known outcome is given: the caller then shows the reason itself.
     */
    public function reasonLabel(string $reason, string $outcome = ''): string
    {
        if ($reason === '') {
            return '';
        }
        if (in_array($reason, self::LABELLED_REASONS, true)) {
            return (string) __('kwtsms.logs.reason.' . $reason);
        }
        if ($outcome === 'blocked_not_configured' && in_array($reason, self::NOT_CONFIGURED_REASONS, true)) {
            return (string) __('kwtsms.reason.' . $reason);
        }
        $code = $this->reasonCode($reason);
        if ($code !== '' && strpos($reason, 'ERR003_content') !== 0) {
            return ApiErrors::describe($code);
        }

        return in_array($outcome, SmsService::OUTCOMES, true) ? (string) __('kwtsms.outcome.' . $outcome) : '';
    }

    /**
     * The kwtSMS or add-on code a reason carries (ERR010, LOCAL_CONNECT), '' when it carries none. Shown after the text
     * of the reason so the admin can quote it to kwtSMS support. Cleanup 2 item 5, task 596.
     */
    public function reasonCode(string $reason): string
    {
        return preg_match(self::REASON_CODE, $reason, $found) === 1 ? $found[1] : '';
    }

    /**
     * The pages of a message in words: "1 page, GSM (English)", "2 pages, Unicode (Arabic)" (EN), the same in Arabic
     * with the Arabic plural forms; the language in brackets only when the message has one. An encoding value this page
     * does not know is shown as it is, an empty one is left out.
     * 2026-10-21 14:10 +03: added, cleanup 2 item 6, task 596.
     */
    public function pagesText(int $pages, string $encoding, string $lang_code): string
    {
        $count = (string) __('kwtsms.logs.pages_n', [$pages]);
        $kind = strtolower(trim($encoding));
        if (in_array($kind, ['gsm', 'gsm7'], true)) {
            $label = (string) __('kwtsms.logs.enc.gsm');
        } elseif (in_array($kind, ['unicode', 'ucs2'], true)) {
            $label = (string) __('kwtsms.logs.enc.unicode');
        } else {
            $label = trim($encoding);
        }
        if ($label === '') {
            return $count;
        }
        $language = $this->languageLabel($lang_code);

        return (string) __(
            $language === '' ? 'kwtsms.logs.pages_line' : 'kwtsms.logs.pages_line_lang',
            ['[pages]' => $count, '[encoding]' => $label, '[language]' => $language]
        );
    }

    /**
     * The name of a message language: English and Arabic have a language variable of their own (so an English page says
     * "Arabic", not the native name), another installed language shows its own name, an unknown code the code.
     * Added in commit 567084c (cleanup 2 item 6, committed 2026-10-21 14:36 +03); 2026-10-21 17:29 +03: this dated note
     * added, cleanup 2 review minor 1, task 596.
     */
    private function languageLabel(string $lang_code): string
    {
        $code = strtolower(trim($lang_code));
        if ($code === '') {
            return '';
        }
        if ($code === 'en' || $code === 'ar') {
            return (string) __('kwtsms.logs.lang.' . $code);
        }

        return $this->installedLanguageName($code);
    }

    /**
     * The name of an installed language ('' is never returned: the code stands in for an unknown one).
     * Added in commit 567084c with languageLabel() (cleanup 2 item 6); 2026-10-21 17:29 +03: this dated note added,
     * cleanup 2 review minor 1, task 596.
     */
    protected function installedLanguageName(string $code): string
    {
        $all = class_exists('Tygh\Languages\Languages') ? (array) \Tygh\Languages\Languages::getAll() : [];

        $name = isset($all[$code]['name']) ? (string) $all[$code]['name'] : '';

        return $name !== '' ? $name : strtoupper($code);
    }

    public function receiverLabel(string $receiver): string
    {
        return in_array($receiver, LogFilters::RECEIVERS, true)
            ? (string) __('kwtsms.dash.receiver.' . $receiver)
            : $receiver;
    }

    /** Plain-language name of an event id, '' when it is not one of ours (the page then shows the id). */
    public function eventLabel(string $event_id): string
    {
        if ($event_id === SettingsPage::TEST_SMS_EVENT) {
            return (string) __('kwtsms.dash.event.test_sms');
        }
        if (strpos($event_id, 'kwtsms.order_placed') === 0) {
            return (string) __('kwtsms.dash.event.order_placed');
        }
        // 2026-10-23 02:09 +03: the manual SMS of the order page, Task A11, task 596.
        if ($event_id === OrderSmsSender::EVENT_ID) {
            return (string) __('kwtsms.dash.event.manual_order_sms');
        }
        if (preg_match('/^order\.status_changed\.([a-z0-9])$/D', $event_id, $m) === 1) {
            $code = strtoupper($m[1]);
            $names = $this->statusNames();

            return (string) __('kwtsms.dash.event.status_changed', ['[status]' => $names[$code] ?? $code]);
        }

        return '';
    }

    /**
     * The tabs above the list (each its own page, PRD 6.8).
     *
     * @return array<string,array{title:string,href:string,active:bool}>
     */
    private function tabs(string $active): array
    {
        $out = [];
        foreach (array_keys(LogFilters::TABS) as $tab) {
            $out[$tab] = [
                'title' => (string) __('kwtsms.logs.tab.' . $tab),
                'href' => 'kwtsms.logs?tab=' . $tab,
                'active' => $tab === $active,
            ];
        }

        return $out;
    }

    /**
     * Choices of the filter form.
     *
     * @return array<string,mixed>
     */
    private function options(string $tab): array
    {
        $outcomes = [];
        foreach (SmsService::OUTCOMES as $code) {
            $outcomes[$code] = $this->outcomeLabel($code);
        }
        $events = [
            'kwtsms.order_placed' => (string) __('kwtsms.dash.event.order_placed'),
            'kwtsms.order_placed.multi' => (string) __('kwtsms.dash.event.order_placed')
                . ' (' . (string) __('kwtsms.logs.event_multi') . ')',
            SettingsPage::TEST_SMS_EVENT => (string) __('kwtsms.dash.event.test_sms'),
            // 2026-10-23 02:09 +03: Task A11, task 596.
            OrderSmsSender::EVENT_ID => (string) __('kwtsms.dash.event.manual_order_sms'),
        ];
        foreach ($this->statusNames() as $code => $name) {
            $events['order.status_changed.' . strtolower((string) $code)]
                = (string) __('kwtsms.dash.event.status_changed', ['[status]' => $name]);
        }

        return [
            'outcomes' => $outcomes,
            'events' => $events,
            'receivers' => [
                'C' => $this->receiverLabel('C'), 'A' => $this->receiverLabel('A'), 'V' => $this->receiverLabel('V'),
            ],
            'storefronts' => $tab === 'debug' ? [] : $this->storefronts(),
            'levels' => LogFilters::LEVELS,
            'multivendor' => $this->isMultiVendor(),
        ];
    }

    /**
     * @return array<string,string> order status code => name
     */
    private function statusNames(): array
    {
        if ($this->statuses === null) {
            try {
                $this->statuses = array_map('strval', $this->fetchOrderStatuses());
            } catch (\Throwable $e) {
                $this->failed('logs.order_statuses_failed', $e);
                $this->statuses = [];
            }
        }

        return $this->statuses;
    }

    // ================================================================== seams (tests replace them)

    /**
     * @return array<string,string>
     */
    protected function fetchOrderStatuses(): array
    {
        return function_exists('fn_get_simple_statuses')
            ? (array) fn_get_simple_statuses(STATUSES_ORDER, true, true)
            : [];
    }

    /**
     * Storefronts by id => name (at most 100; the filter is a select only when there are several).
     *
     * @return array<int,string>
     */
    protected function storefronts(): array
    {
        try {
            $app = \Tygh\Tygh::$app;
            if (!isset($app['storefront.repository'])) {
                return [];
            }
            [$storefronts] = $app['storefront.repository']->find(['items_per_page' => 100]);
            $out = [];
            foreach ((array) $storefronts as $storefront) {
                $out[(int) $storefront->storefront_id] = (string) $storefront->name;
            }

            return $out;
        } catch (\Throwable $e) {
            $this->failed('logs.storefronts_failed', $e);

            return [];
        }
    }

    protected function isMultiVendor(): bool
    {
        return function_exists('fn_allowed_for') && fn_allowed_for('MULTIVENDOR');
    }

    /**
     * A language variable with its placeholder values escaped (PRD 10).
     *
     * @param array<string,string> $params
     */
    protected function text(string $key, array $params = []): string
    {
        $escaped = [];
        foreach ($params as $name => $value) {
            $escaped[$name] = function_exists('fn_html_escape')
                ? fn_html_escape((string) $value)
                : htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }

        return (string) __($key, $escaped);
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }

    /** A swallowed failure, logged at error with class, message and file:line (no trace). */
    private function failed(string $action, \Throwable $e): void
    {
        $this->log->log(
            'error',
            $action,
            get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()
        );
    }
}
