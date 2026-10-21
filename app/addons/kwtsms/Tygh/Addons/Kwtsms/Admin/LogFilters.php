<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/LogFilters.php
 * CREATED:   2026-10-21 05:47 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The fixed key map of the Logs page: turns the request ($_REQUEST) into the tab, the Log\Schema filters
 *            with
 *            typed values, the values the filter form shows, the language keys of the problems found, the page, the
 *            page size and the sort order.
 * WHY:       PRD 6.8 and 10. Filters come from a fixed key map only: a request key outside the map of the tab is
 *            ignored, every value is checked against its own rule (canonical outcome codes, digits, Y/N, a pattern), so
 *            nothing the request holds can reach SQL other than as a typed placeholder value in DbLogStore. The page
 *            size is clamped to 10-200, the sort is created_at only (db_sort with a one-entry whitelist), the offset is
 *            capped (MAX_OFFSET), the phone is normalized (98765432 finds 96598765432) and the text filter needs at
 *            least 3 characters and a date range of at most 31 days. The Dashboard's problem links (Task 1.11: tab,
 *            outcome and outcome[], api_code, created_from) are part of the map.
 * USAGE:     $f = LogFilters::parse($_REQUEST, $config->defaultCountryCode(), time());
 *            $f['tab'] (messages|attempts|debug), $f['table'] (Log\Schema name), $f['filters'] (Schema keys),
 *            $f['form'] (strings for the form), $f['errors'] (kwtsms.logs.error.* keys), $f['page'],
 *            $f['items_per_page'], $f['sort_by'] ('created_at'), $f['sort_order'] (asc|desc), $f['active'] (any
 *            filter), $f['query'] (the request keys that rebuild this view, for paging, export and clear links).
 * DEPENDS:   Log\Schema, Sms\SmsService::OUTCOMES, Sms\PhoneNormalizer; CS-Cart db_sort() when it exists.
 */

namespace Tygh\Addons\Kwtsms\Admin;

use Tygh\Addons\Kwtsms\Log\Schema;
use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;
use Tygh\Addons\Kwtsms\Sms\SmsService;

final class LogFilters
{
    /** Request tab => Log\Schema table. */
    public const TABS = ['messages' => Schema::MESSAGES, 'attempts' => Schema::ATTEMPTS, 'debug' => Schema::DEBUG];

    public const PAGE_MIN = 10;
    public const PAGE_MAX = 200;
    public const PAGE_DEFAULT = 50;

    /** Rows a page may skip (deeper pages read too much of the index; the page asks for a narrower filter). */
    public const MAX_OFFSET = 10000;

    /** PRD 6.8: the text filter needs this many characters and a date range of at most TEXT_MAX_DAYS days. */
    public const TEXT_MIN = 3;
    public const TEXT_MAX_DAYS = 31;
    public const TEXT_MAX_LENGTH = 100;

    public const LEVELS = ['debug', 'info', 'warning', 'error'];
    public const RECEIVERS = ['C', 'A', 'V'];

    private const MAX_ID = 4294967295;

    /**
     * Request key => [Schema key, rule], per tab. Rules: outcome (one or more canonical outcome codes), id (digits,
     * 0..4294967295), receiver, flag (Y/N), code, event, msgid, level, action, request, phone.
     *
     * @var array<string,array<string,array{0:string,1:string}>>
     */
    private const MAP = [
        'messages' => [
            'status' => ['status', 'outcome'], 'api_code' => ['api_code', 'code'], 'event_id' => ['event_id', 'event'],
            'receiver' => ['receiver', 'receiver'], 'order' => ['order', 'id'],
            'storefront_id' => ['storefront_id', 'id'],
            'company_id' => ['company_id', 'id'], 'phone' => ['phone', 'phone'], 'is_test' => ['is_test', 'flag'],
            'api_msg_id' => ['api_msg_id', 'msgid'],
        ],
        'attempts' => [
            'outcome' => ['outcome', 'outcome'], 'event_id' => ['event_id', 'event'],
            'receiver' => ['receiver', 'receiver'], 'order' => ['order', 'id'],
            'storefront_id' => ['storefront_id', 'id'], 'company_id' => ['company_id', 'id'],
            'phone' => ['phone', 'phone'], 'is_test' => ['is_test', 'flag'], 'message_id' => ['message_id', 'id'],
        ],
        'debug' => [
            'level' => ['level', 'level'], 'action' => ['action', 'action'], 'request_id' => ['request_id', 'request'],
            'user_id' => ['user_id', 'id'],
        ],
    ];

    private function __construct()
    {
    }

    /**
     * @param array<string,mixed> $request    $_REQUEST
     * @param string              $default_cc Calling code of the store's default country (phone hint)
     * @param int                 $now        Current Unix time (the open end of a text filter's range)
     *
     * @return array<string,mixed> See the file header
     */
    public static function parse(array $request, string $default_cc, int $now): array
    {
        $tab = isset($request['tab']) && is_string($request['tab']) && isset(self::TABS[$request['tab']])
            ? $request['tab']
            : 'messages';
        $filters = [];
        $form = [];
        $errors = [];
        $query = ['tab' => $tab];

        // ---- dates: the date inputs (whole days) win over the unix values of a link
        $range = self::range($request, $form, $query);
        if ($range['from'] !== null) {
            $filters['created_from'] = $range['from'];
        }
        if ($range['to'] !== null) {
            $filters['created_to'] = $range['to'];
        }

        // ---- the keys of the tab
        foreach (self::MAP[$tab] as $key => $rule) {
            if (!array_key_exists($key, $request)) {
                continue;
            }
            $raw = $request[$key];
            if ($rule[1] === 'outcome') {
                $list = array_values(array_filter((array) $raw, static function ($v): bool {
                    return is_string($v) && in_array($v, SmsService::OUTCOMES, true);
                }));
                if ($list !== []) {
                    $filters[$rule[0]] = $list;
                    $form[$key] = count($list) === 1 ? $list[0] : '';
                    $query[$key] = count($list) === 1 ? $list[0] : $list;
                }
                continue;
            }
            if (!is_string($raw) && !is_int($raw)) {
                continue;
            }
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            if ($rule[1] === 'phone') {
                $form[$key] = mb_substr($raw, 0, 64, 'UTF-8');
                $query[$key] = $form[$key];
                // The match is exact on the stored normalized number, so a fragment (under 7 digits) finds nothing.
                $phone = PhoneNormalizer::normalize($form[$key], $default_cc);
                if (strlen($phone) < 7) {
                    $errors[] = 'kwtsms.logs.error.phone';
                } else {
                    $filters[$rule[0]] = $phone;
                }
                continue;
            }
            $value = self::value($rule[1], $raw);
            if ($value === null) {
                continue;
            }
            $filters[$rule[0]] = $value;
            $form[$key] = (string) $value;
            $query[$key] = (string) $value;
        }

        // ---- text: at least 3 characters and a range of at most 31 days (PRD 6.8)
        if (isset($request['q']) && is_string($request['q'])) {
            $text = self::cleanText($request['q']);
            if ($text !== '') {
                $form['q'] = $text;
                $query['q'] = $text;
                $span_ok = $range['from'] !== null
                    && (($range['to'] ?? $now) - $range['from']) < self::TEXT_MAX_DAYS * 86400;
                if (mb_strlen($text, 'UTF-8') >= self::TEXT_MIN && $span_ok) {
                    $filters['text'] = $text;
                } else {
                    $errors[] = 'kwtsms.logs.error.text_rule';
                }
            }
        }

        $per_page = self::PAGE_DEFAULT;
        $raw_size = $request['items_per_page'] ?? null;
        if ((is_string($raw_size) || is_int($raw_size)) && preg_match('/^-?[0-9]{1,9}$/D', (string) $raw_size) === 1) {
            $per_page = max(self::PAGE_MIN, min(self::PAGE_MAX, (int) $raw_size));
        }
        $page = 1;
        if (
            isset($request['page']) && (is_string($request['page']) || is_int($request['page']))
            && preg_match('/^[0-9]{1,9}$/D', (string) $request['page']) === 1
        ) {
            $page = max(1, (int) $request['page']);
        }
        $page = min($page, intdiv(self::MAX_OFFSET, $per_page) + 1);

        $sort_order = self::sortOrder($request);

        return [
            'tab' => $tab,
            'table' => self::TABS[$tab],
            'filters' => $filters,
            'form' => $form,
            'errors' => array_values(array_unique($errors)),
            'page' => $page,
            'items_per_page' => $per_page,
            'sort_by' => 'created_at',
            'sort_order' => $sort_order,
            'active' => $filters !== [] || $errors !== [],
            'query' => $query,
        ];
    }

    /**
     * created_at is the only sort; the order is asc or desc. Through core db_sort() with a one-entry whitelist when it
     * exists (PRD 10), the same rule without it (unit tests).
     *
     * @param array<string,mixed> $request
     */
    public static function sortOrder(array $request): string
    {
        $params = [
            'sort_by' => isset($request['sort_by']) && is_string($request['sort_by']) ? $request['sort_by'] : '',
            'sort_order' => isset($request['sort_order']) && is_string($request['sort_order'])
                ? $request['sort_order']
                : '',
        ];
        if (function_exists('db_sort')) {
            db_sort($params, ['created_at' => 'created_at'], 'created_at', 'desc');
        } elseif (!in_array($params['sort_order'], ['asc', 'desc'], true)) {
            $params['sort_order'] = 'desc';
        }

        return $params['sort_order'] === 'asc' ? 'asc' : 'desc';
    }

    /**
     * The created_at range: the date inputs period_from / period_to (Y-m-d, whole days in the store's time zone) when
     * given, else created_from / created_to (unix, the Dashboard links). A reversed range is swapped.
     *
     * @param array<string,mixed> $request
     * @param array<string,string> $form
     * @param array<string,mixed> $query
     *
     * @return array{from:?int,to:?int}
     */
    private static function range(array $request, array &$form, array &$query): array
    {
        $out = ['from' => null, 'to' => null];
        $ends = [
            'from' => ['period_from', 'created_from', '00:00:00'],
            'to' => ['period_to', 'created_to', '23:59:59'],
        ];
        foreach ($ends as $end => $keys) {
            $date = isset($request[$keys[0]]) && is_string($request[$keys[0]]) ? trim($request[$keys[0]]) : '';
            if (
                $date !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) === 1
                && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            ) {
                $time = strtotime($date . ' ' . $keys[2]);
                if ($time !== false) {
                    $out[$end] = $time;
                    $query[$keys[0]] = $date;
                    continue;
                }
            }
            $unix = isset($request[$keys[1]]) && (is_string($request[$keys[1]]) || is_int($request[$keys[1]]))
                ? (string) $request[$keys[1]]
                : '';
            if (preg_match('/^[0-9]{1,10}$/D', $unix) === 1 && (int) $unix <= self::MAX_ID) {
                $out[$end] = (int) $unix;
                $query[$keys[1]] = $unix;
            }
        }
        if ($out['from'] !== null && $out['to'] !== null && $out['from'] > $out['to']) {
            // A reversed range is swapped, each end keeping its whole day.
            $from = strtotime(date('Y-m-d', $out['to']) . ' 00:00:00');
            $to = strtotime(date('Y-m-d', $out['from']) . ' 23:59:59');
            $out = ['from' => $from === false ? $out['to'] : $from, 'to' => $to === false ? $out['from'] : $to];
        }
        if ($out['from'] !== null) {
            $form['period_from'] = date('Y-m-d', $out['from']);
        }
        if ($out['to'] !== null) {
            $form['period_to'] = date('Y-m-d', $out['to']);
        }

        return $out;
    }

    /**
     * One value checked against its rule, or null when it does not pass.
     *
     * @return int|string|null
     */
    private static function value(string $rule, string $raw)
    {
        switch ($rule) {
            case 'id':
                return preg_match('/^[0-9]{1,10}$/D', $raw) === 1 && (int) $raw <= self::MAX_ID ? (int) $raw : null;
            case 'receiver':
                return in_array($raw, self::RECEIVERS, true) ? $raw : null;
            case 'flag':
                return $raw === 'Y' || $raw === 'N' ? $raw : null;
            case 'code':
                return preg_match('/^[A-Z][A-Z0-9_]{0,31}$/D', $raw) === 1 ? $raw : null;
            case 'event':
                return preg_match('/^[a-z0-9_][a-z0-9_.]{0,127}$/D', $raw) === 1 ? $raw : null;
            case 'msgid':
                return preg_match('/^[A-Za-z0-9-]{1,64}$/D', $raw) === 1 ? $raw : null;
            case 'level':
                return in_array($raw, self::LEVELS, true) ? $raw : null;
            case 'action':
                return preg_match('/^[a-z0-9_][a-z0-9_.]{0,63}$/D', $raw) === 1 ? $raw : null;
            case 'request':
                return preg_match('/^[a-f0-9]{8,32}$/D', $raw) === 1 ? $raw : null;
        }

        return null;
    }

    /** The search text: control characters removed, trimmed, at most TEXT_MAX_LENGTH characters. */
    private static function cleanText(string $raw): string
    {
        if (!mb_check_encoding($raw, 'UTF-8')) {
            return '';
        }
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $raw);

        return mb_substr(trim((string) $text), 0, self::TEXT_MAX_LENGTH, 'UTF-8');
    }
}
