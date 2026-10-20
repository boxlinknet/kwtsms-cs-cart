<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Log/Schema.php
 * CREATED:   2026-10-20 21:25 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The shape of the three log tables in one place: database table, primary key, every column with
 *            its type and limit, defaults, the columns a list may filter on, and the columns a list never
 *            selects.
 * WHY:       DbLogStore (SQL), MemoryLogStore (tests) and MessageLog/AttemptLog/DebugLog (row cleaning) must
 *            agree on the columns. The definitions mirror the CREATE TABLE statements of addon.xml (PRD 8);
 *            LogStoreTest compares them with the real tables, so they cannot drift.
 * USAGE:     Schema::table(Schema::MESSAGES)   -> 'kwtsms_messages' (use as ?:kwtsms_messages)
 *            Schema::pk(Schema::MESSAGES)      -> 'message_id'
 *            Schema::columns(Schema::ATTEMPTS) -> ['created_at' => ['i', 4294967295], 'source' => ['s', 16], ...]
 *            Column types: i = unsigned integer (limit = maximum), s = string (limit = characters),
 *            sn = nullable string, t = text (limit = bytes), d = decimal(14,2) (limit = unused 0).
 * DEPENDS:   Nothing.
 */

namespace Tygh\Addons\Kwtsms\Log;

final class Schema
{
    public const MESSAGES = 'messages';
    public const ATTEMPTS = 'attempts';
    public const DEBUG = 'debug';

    private const INT = 4294967295;
    private const SMALL = 65535;
    private const TEXT = 65535;
    private const MEDIUM = 16777215;

    /**
     * @var array<string, array{table: string, pk: string, columns: array<string, array{0: string, 1: int}>,
     *      defaults: array<string, mixed>, filters: string[], hidden: string[]}>
     */
    private const TABLES = [
        self::MESSAGES => [
            'table' => 'kwtsms_messages',
            'pk' => 'message_id',
            'columns' => [
                'created_at' => ['i', self::INT],
                'source' => ['s', 16],
                'event_id' => ['s', 128],
                'receiver' => ['s', 15],
                'company_id' => ['i', self::INT],
                'parent_order_id' => ['i', self::INT],
                'storefront_id' => ['i', self::INT],
                'order_id' => ['i', self::INT],
                'user_id' => ['i', self::INT],
                'actor_user_id' => ['i', self::INT],
                'actor_company_id' => ['i', self::INT],
                'sender' => ['s', 32],
                'recipients' => ['t', self::TEXT],
                'recipient_count' => ['i', self::INT],
                'numbers_accepted' => ['i', self::INT],
                'partial_accept' => ['s', 1],
                'message' => ['t', self::TEXT],
                'masked' => ['s', 1],
                'lang_code' => ['s', 2],
                'encoding' => ['s', 8],
                'pages' => ['i', self::SMALL],
                'is_test' => ['s', 1],
                'batch_no' => ['i', self::SMALL],
                'batch_total' => ['i', self::SMALL],
                'status' => ['s', 32],
                'api_code' => ['s', 32],
                'code_source' => ['s', 8],
                'api_msg_id' => ['s', 64],
                'points_charged' => ['d', 0],
                'balance_after' => ['d', 0],
                'api_unix_ts' => ['i', self::INT],
                'api_request' => ['t', self::MEDIUM],
                'api_response' => ['t', self::MEDIUM],
                'duration_ms' => ['i', self::INT],
            ],
            'defaults' => [
                'partial_accept' => 'N',
                'masked' => 'N',
                'is_test' => 'N',
                'batch_no' => 1,
                'batch_total' => 1,
                'code_source' => 'api',
            ],
            'filters' => [
                'source', 'event_id', 'receiver', 'company_id', 'parent_order_id', 'storefront_id', 'order_id',
                'user_id', 'actor_user_id', 'status', 'api_code', 'api_msg_id', 'is_test',
            ],
            'hidden' => ['api_request', 'api_response'],
        ],
        self::ATTEMPTS => [
            'table' => 'kwtsms_attempts',
            'pk' => 'attempt_id',
            'columns' => [
                'created_at' => ['i', self::INT],
                'message_id' => ['i', self::INT],
                'outbox_id' => ['i', self::INT],
                'retry_of' => ['i', self::INT],
                'source' => ['s', 16],
                'event_id' => ['s', 128],
                'receiver' => ['s', 15],
                'company_id' => ['i', self::INT],
                'parent_order_id' => ['i', self::INT],
                'storefront_id' => ['i', self::INT],
                'order_id' => ['i', self::INT],
                'user_id' => ['i', self::INT],
                'actor_user_id' => ['i', self::INT],
                'phone_raw' => ['s', 64],
                'phone' => ['s', 64],
                'outcome' => ['s', 32],
                'reason' => ['s', 255],
                'ip' => ['sn', 45],
            ],
            'defaults' => [],
            'filters' => [
                'message_id', 'outbox_id', 'retry_of', 'source', 'event_id', 'receiver', 'company_id',
                'parent_order_id', 'storefront_id', 'order_id', 'user_id', 'actor_user_id', 'phone', 'outcome', 'ip',
            ],
            'hidden' => [],
        ],
        self::DEBUG => [
            'table' => 'kwtsms_debug_log',
            'pk' => 'log_id',
            'columns' => [
                'created_at' => ['i', self::INT],
                'level' => ['s', 10],
                'action' => ['s', 64],
                'message' => ['t', self::TEXT],
                'context' => ['t', self::TEXT],
                'duration_ms' => ['i', self::INT],
                'request_id' => ['s', 32],
                'user_id' => ['i', self::INT],
            ],
            'defaults' => ['level' => 'info'],
            'filters' => ['level', 'action', 'request_id', 'user_id'],
            'hidden' => [],
        ],
    ];

    private function __construct()
    {
    }

    /**
     * @return string[] Logical table names
     */
    public static function tables(): array
    {
        return array_keys(self::TABLES);
    }

    /**
     * @throws \InvalidArgumentException For a table that is not one of the three logs
     */
    private static function info(string $table): array
    {
        if (!isset(self::TABLES[$table])) {
            throw new \InvalidArgumentException('Unknown log table: ' . $table);
        }

        return self::TABLES[$table];
    }

    /** Database table name without the prefix, e.g. kwtsms_messages. */
    public static function table(string $table): string
    {
        return self::info($table)['table'];
    }

    public static function pk(string $table): string
    {
        return self::info($table)['pk'];
    }

    /**
     * Every column except the primary key, in table order.
     *
     * @return array<string, array{0: string, 1: int}> name => [type, limit]
     */
    public static function columns(string $table): array
    {
        return self::info($table)['columns'];
    }

    /**
     * Values other than 0, '' and null that the table defaults to.
     *
     * @return array<string, mixed>
     */
    public static function defaults(string $table): array
    {
        return self::info($table)['defaults'];
    }

    /**
     * Columns a list or count may filter on with equality or IN.
     *
     * @return string[]
     */
    public static function filters(string $table): array
    {
        return self::info($table)['filters'];
    }

    /**
     * Columns a list never selects (raw request and response: PRD 10); find() returns them.
     *
     * @return string[]
     */
    public static function hidden(string $table): array
    {
        return self::info($table)['hidden'];
    }

    /**
     * Accepts created_from, created_to, "order" (tables that have both order_id and parent_order_id) and
     * the equality columns of filters(); anything else is a programming error, not request data.
     *
     * @param array<string, mixed> $filters
     *
     * @throws \InvalidArgumentException On the first unknown filter key
     */
    public static function assertFilters(string $table, array $filters): void
    {
        $allowed = array_merge(['created_from', 'created_to'], self::filters($table));
        if (self::hasOrderPair($table)) {
            $allowed[] = 'order';
        }
        foreach (array_keys($filters) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                throw new \InvalidArgumentException('Unknown log filter "' . $key . '" for ' . $table);
            }
        }
    }

    /** True when the table has both order_id and parent_order_id (the "order" filter matches either). */
    public static function hasOrderPair(string $table): bool
    {
        $columns = self::info($table)['columns'];

        return isset($columns['order_id'], $columns['parent_order_id']);
    }
}
