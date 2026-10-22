<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Privacy/GdprUserData.php
 * CREATED:   2026-10-21 05:54 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The add-on's part of the core GDPR personal data (schemas/gdpr/user_data.post.php): collect() finds the
 *            SMS
 *            rows of one customer in kwtsms_messages, kwtsms_attempts and kwtsms_outbox by user id, by the customer's
 *            order ids (and their parent and child orders) and by the customer's phone numbers (normalized), and
 *            anonymize() rewrites exactly those rows: each of the customer's numbers becomes a one-way token, the SMS
 *            text and the raw request and reply become "[anonymized]", the IP address is cleared, and an outbox row
 *            that
 *            was still waiting is closed (expired) so the anonymized text is never sent.
 * WHY:       PRD 6.8 (GDPR) and ruling R28 (outbox rows too). The token is an HMAC-SHA256 of the number under a random
 *            key that exists only for one anonymize() call, so it cannot be turned back into the number, yet one number
 *            keeps one token across the rows of that customer. Numbers that are not the customer's (the admin alert
 *            numbers on the admin SMS of the customer's orders) are kept. Core calls collect() when it shows or exports
 *            a user's data and anonymize() from the "Anonymize" action of a customer profile.
 *            2026-10-21 08:09 +03, Task 1.14 fix round 1 (items 3 and 4): every collected row also carries sms_phone
 *            (the customer's own numbers on it) and sms_text (its text when it went to one of those numbers),
 *            HTML-escaped because core's view prints them without escaping; schemas/gdpr/user_data.post.php lists
 *            them in fields_list (FIELDS), so core's personal-data view and XML export show them, while core's
 *            anonymizer, which only rewrites fields it knows (email, phone, ...), passes the rows to anonymize()
 *            unchanged. collectForCore() turns a failed read into the FAILED marker and updateFromCore() refuses it
 *            (and a failed anonymize), so the caller raises kwtsms.gdpr.failed instead of reporting nothing.
 *            2026-10-21 08:38 +03, fix round 2: the text of a waiting outbox row (pending, sending, held: not final, so
 *            its OTP or reset link is still in clear) is masked with the row's secret_values, which never enter the
 *            collected data (rule D7); the FAILED marker carries an sms_text line (failedMarker()), so core's view
 *            and export say the SMS data could not be read.
 *            2026-10-22 22:23 +03, Task A8, task 596: NOT APPLICABLE to vendors. A vendor is a company, not a user
 *            account: the approved vendor number (kwtsms_vendor_phones), the vendor V messages and attempts belong to
 *            the company (company_id) and are removed with it (delete_company hook), never through this personal data
 *            path, which finds rows by user id, the customer's orders and the customer's own numbers.
 * USAGE:     $gdpr = GdprUserData::fromContainer(Tygh::$app);
 *            $data = $gdpr->collect($user_info);   // ['phones' => [...], 'messages' => [...], 'attempts' => [...],
 *            'outbox' => [...]]
 *            $gdpr->anonymize($data);              // ['messages' => n, 'attempts' => n, 'outbox' => n]
 * DEPENDS:   Settings\ConfigInterface (default country), Sms\PhoneNormalizer, Log\{Schema,DebugLog,Redactor}; CS-Cart
 *            db_*() and __().
 */

namespace Tygh\Addons\Kwtsms\Privacy;

use Tygh\Addons\Kwtsms\Log\DebugLog;
// 2026-10-21 08:37 +03: Redactor::mask() for the waiting outbox rows (fix round 2, item 1), task 596.
use Tygh\Addons\Kwtsms\Log\Redactor;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;

class GdprUserData
{
    public const ANONYMIZED = '[anonymized]';

    /** Prefix of a number token (never a valid phone, so no phone filter or cap count matches it). */
    public const TOKEN_PREFIX = 'anon-';

    /**
     * 2026-10-21 08:09 +03: the keys core's extractors show (fields_list); not in core's anonymizer schema, so its
     * anonymizer leaves them and the raw rows as they are (fix round 1, item 3), task 596.
     */
    public const FIELDS = ['sms_phone', 'sms_text'];

    /**
     * 2026-10-21 08:09 +03: collectForCore() returns [FAILED => 'Y'] when the rows could not be read, so the update
     * reports it instead of anonymizing nothing (fix round 1, item 4), task 596.
     */
    public const FAILED = 'kwtsms_collect_failed';

    /** Phone fields of a user profile and of an order. */
    private const PHONE_FIELDS = ['phone', 'b_phone', 's_phone'];

    /** Outbox states that would still be sent: closed as expired when anonymized. */
    private const WAITING = ['pending', 'sending', 'held'];

    /** @var ConfigInterface */
    private $config;

    /** @var DebugLog */
    private $log;

    public function __construct(ConfigInterface $config, DebugLog $log)
    {
        $this->config = $config;
        $this->log = $log;
    }

    /**
     * @param \ArrayAccess<string,mixed> $app Tygh::$app
     */
    public static function fromContainer($app): self
    {
        return new self($app['kwtsms.config'], $app['kwtsms.logs.debug']);
    }

    /**
     * The SMS rows of one customer.
     *
     * @param array<string,mixed> $user fn_get_user_info() of the customer (user_id, email, phone fields)
     *
     * @return array<string,mixed> user_id, phones (string[]), order_ids (int[]), messages, attempts and outbox (rows)
     */
    public function collect(array $user): array
    {
        $user_id = (int) ($user['user_id'] ?? 0);
        $email = isset($user['email']) && is_string($user['email']) ? $user['email'] : '';
        $orders = $user_id > 0 ? $this->orders($user_id, $email) : [];
        $order_ids = array_values(array_unique(array_map(static function (array $o): int {
            return (int) $o['order_id'];
        }, $orders)));
        $phones = self::phonesOf($user, $orders, $this->config->defaultCountryCode());
        $out = [
            'user_id' => $user_id, 'phones' => $phones, 'order_ids' => $order_ids,
            'messages' => [], 'attempts' => [], 'outbox' => [], 'prefs' => [],
        ];
        if ($user_id < 1) {
            return $out;
        }
        // 2026-10-23 00:56 +03: Task A10 (SafatMall F6), task 596. The customer's SMS preference row (empty when none).
        $out['prefs'] = $this->prefs($user_id);
        $out['messages'] = $this->messages($user_id, $order_ids, $phones);
        $out['attempts'] = $this->attempts($user_id, $order_ids, $phones);
        $out['outbox'] = $this->outbox($user_id, $order_ids, $phones);
        // 2026-10-21 08:37 +03: rule D7: the text of a waiting outbox row is masked with its own secret values, which
        // then leave the collected data (fix round 2, item 1), task 596.
        foreach ($out['outbox'] as $i => $row) {
            $secrets = isset($row['secret_values']) && is_array($row['secret_values']) ? $row['secret_values'] : [];
            unset($row['secret_values']);
            if ($secrets !== []) {
                $row['message'] = Redactor::mask((string) ($row['message'] ?? ''), $secrets);
            }
            $out['outbox'][$i] = $row;
        }
        // 2026-10-21 08:09 +03: what core's view and export show of each row (fix round 1, item 3), task 596.
        $hint = $this->config->defaultCountryCode();
        $columns_of = [
            'messages' => ['recipients', 'message'], 'attempts' => ['phone', ''], 'outbox' => ['phones', 'message'],
        ];
        foreach ($columns_of as $key => $columns) {
            foreach ($out[$key] as $i => $row) {
                $out[$key][$i] = $row + self::shown($row, $columns[0], $columns[1], $phones, $hint);
            }
        }

        return $out;
    }

    /**
     * 2026-10-21 08:09 +03: sms_phone (the customer's numbers among the row's numbers) and sms_text (the row's text
     * when it went to one of them; a staff alert about their order is not shown), escaped for core's view, which
     * prints these values without escaping. Fix round 1, item 3, task 596.
     *
     * @param array<string,mixed> $row
     * @param string[]            $phones the customer's numbers
     *
     * @return array{sms_phone:string,sms_text:string}
     */
    private static function shown(
        array $row,
        string $numbers_column,
        string $text_column,
        array $phones,
        string $hint
    ): array {
        $mine = [];
        foreach (explode(',', (string) ($row[$numbers_column] ?? '')) as $number) {
            $normalized = PhoneNormalizer::normalize(trim($number), $hint);
            if (in_array($normalized, $phones, true)) {
                $mine[$normalized] = true;
            }
        }
        $mine = array_map('strval', array_keys($mine));
        $text = $mine !== [] && $text_column !== '' ? (string) ($row[$text_column] ?? '') : '';
        $escape = static function (string $value): string {
            return htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        return ['sms_phone' => $escape(implode(', ', $mine)), 'sms_text' => $escape($text)];
    }

    /**
     * 2026-10-21 08:09 +03: collect() for core's collector: a failed read is logged and returned as the FAILED
     * marker, never as "no rows" (fix round 1, item 4), task 596.
     *
     * @param array<string,mixed> $user
     *
     * @return array<string,mixed>
     */
    public function collectForCore(array $user): array
    {
        try {
            return $this->collect($user);
        } catch (\Throwable $e) {
            $this->failed('collect', $e);

            return self::failedMarker();
        }
    }

    /**
     * 2026-10-21 08:37 +03: the FAILED marker with an sms_text line, so core's view and XML export show that the SMS
     * data could not be read instead of an empty section (fix round 2, item 4), task 596.
     *
     * @return array{kwtsms_collect_failed:string,sms_text:string}
     */
    public static function failedMarker(): array
    {
        $text = function_exists('__') ? (string) __('kwtsms.gdpr.collect_failed') : '';
        if ($text === '' || $text[0] === '_') {
            $text = "SMS data couldn't be read. See the kwtSMS Debug log.";
        }

        return [self::FAILED => 'Y', 'sms_text' => htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8')];
    }

    /**
     * 2026-10-21 08:09 +03: anonymize() for core's updater. False when the data is the FAILED marker or the
     * anonymize failed: the caller tells the administrator (kwtsms.gdpr.failed). Fix round 1, item 4, task 596.
     *
     * @param array<string,mixed> $data
     */
    public function updateFromCore(array $data): bool
    {
        if (($data[self::FAILED] ?? '') === 'Y') {
            return false;
        }
        try {
            $this->anonymize($data);

            return true;
        } catch (\Throwable $e) {
            $this->failed('anonymize', $e);

            return false;
        }
    }

    /** The PHP error log always (the debug log may be the thing that failed), the debug log when it can. */
    private function failed(string $step, \Throwable $e): void
    {
        $text = 'gdpr ' . $step . ' failed: ' . get_class($e) . ': ' . $e->getMessage() . ' in '
            . basename($e->getFile()) . ':' . $e->getLine();
        error_log('kwtsms: ' . $text);
        try {
            $this->log->log('error', 'gdpr.' . $step . '_failed', $text);
        } catch (\Throwable $ignored) {
            // Already in the PHP error log.
        }
    }

    /**
     * Anonymizes the rows collect() found. Returns the rows changed per table.
     *
     * @param array<string,mixed> $data collect() (core passes it back after its own anonymizer, which leaves it as is)
     *
     * @return array{messages:int,attempts:int,outbox:int}
     */
    public function anonymize(array $data): array
    {
        $phones = array_map('strval', (array) ($data['phones'] ?? []));
        $phones = array_values(array_filter($phones, static function (string $p): bool {
            return preg_match('/^[1-9][0-9]{6,14}$/D', $p) === 1;
        }));
        $key = random_bytes(32);
        $tokens = [];
        foreach ($phones as $phone) {
            $tokens[$phone] = self::token($phone, $key);
        }
        $done = ['messages' => 0, 'attempts' => 0, 'outbox' => 0];
        $hint = $this->config->defaultCountryCode();

        foreach ((array) ($data['messages'] ?? []) as $row) {
            $id = (int) ($row['message_id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $done['messages'] += (int) db_query('UPDATE ?:kwtsms_messages SET ?u WHERE message_id = ?i', [
                'recipients' => self::replaceNumbers((string) ($row['recipients'] ?? ''), $tokens),
                'message' => self::ANONYMIZED,
                'api_request' => self::ANONYMIZED,
                'api_response' => self::ANONYMIZED,
            ], $id) > 0 ? 1 : 0;
        }
        foreach ((array) ($data['attempts'] ?? []) as $row) {
            $id = (int) ($row['attempt_id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $phone = (string) ($row['phone'] ?? '');
            $raw_normalized = PhoneNormalizer::normalize((string) ($row['phone_raw'] ?? ''), $hint);
            $set = ['ip' => null];
            if (isset($tokens[$phone])) {
                $set['phone'] = $tokens[$phone];
            }
            if (isset($tokens[$phone]) || isset($tokens[$raw_normalized])) {
                $set['phone_raw'] = $tokens[$phone] ?? $tokens[$raw_normalized];
            }
            $changed = (int) db_query('UPDATE ?:kwtsms_attempts SET ?u WHERE attempt_id = ?i', $set, $id);
            $done['attempts'] += $changed > 0 ? 1 : 0;
        }
        foreach ((array) ($data['outbox'] ?? []) as $row) {
            $id = (int) ($row['outbox_id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $stored = db_get_row('SELECT status, phones, context FROM ?:kwtsms_outbox WHERE outbox_id = ?i', $id);
            if (!is_array($stored) || $stored === []) {
                continue;
            }
            $list = json_decode((string) $stored['phones'], true);
            $list = is_array($list) ? $list : [];
            foreach ($list as $i => $number) {
                $normalized = PhoneNormalizer::normalize((string) $number, $hint);
                if (isset($tokens[$normalized])) {
                    $list[$i] = $tokens[$normalized];
                }
            }
            $context = json_decode((string) $stored['context'], true);
            $context = is_array($context) ? $context : [];
            unset($context['ip'], $context['secret_values']);
            $set = [
                'phones' => (string) json_encode(array_values($list), JSON_UNESCAPED_SLASHES),
                'message' => (string) json_encode(self::ANONYMIZED),
                'context' => (string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            ];
            if (in_array((string) $stored['status'], self::WAITING, true)) {
                $set['status'] = 'expired';
            }
            $changed = (int) db_query('UPDATE ?:kwtsms_outbox SET ?u WHERE outbox_id = ?i', $set, $id);
            $done['outbox'] += $changed > 0 ? 1 : 0;
        }
        // 2026-10-23 00:56 +03: Task A10 (SafatMall F6), task 596. The opt-out row of the account goes with the rest
        // of its SMS data.
        $this->deletePrefs((int) ($data['user_id'] ?? 0));
        $this->log->log('info', 'gdpr.anonymized', 'SMS rows of a customer anonymized', [
            'user_id' => (int) ($data['user_id'] ?? 0), 'numbers' => count($tokens),
        ] + $done);

        return $done;
    }

    /**
     * The customer's numbers, normalized: the profile phone fields and the phone fields of every order, each with the
     * calling code of its own country as the hint (else the store default). Fragments under 7 digits are dropped.
     *
     * @param array<string,mixed>            $user
     * @param array<int,array<string,mixed>> $orders
     *
     * @return string[]
     */
    public static function phonesOf(array $user, array $orders, string $default_cc): array
    {
        $out = [];
        foreach (array_merge([$user], $orders) as $record) {
            $country = '';
            foreach (['b_country', 's_country', 'country'] as $field) {
                if (isset($record[$field]) && is_string($record[$field]) && $record[$field] !== '') {
                    $country = (string) PhoneNormalizer::callingCodeForIso($record[$field]);
                    break;
                }
            }
            foreach (self::PHONE_FIELDS as $field) {
                $raw = isset($record[$field]) && is_scalar($record[$field]) ? trim((string) $record[$field]) : '';
                if ($raw === '' || strpos($raw, '@') !== false) {
                    continue;
                }
                // One hint: the record's own country, else the store default (a second guess could turn a number into
                // someone else's).
                $phone = PhoneNormalizer::normalize($raw, $country !== '' ? $country : $default_cc);
                if (preg_match('/^[1-9][0-9]{6,14}$/D', $phone) === 1) {
                    $out[$phone] = true;
                }
            }
        }

        // Digit-only keys become integers in a PHP array: give the numbers back as strings (strict comparisons follow).
        return array_map('strval', array_keys($out));
    }

    /** A one-way token of a number under a key that lives for one anonymize() call. */
    public static function token(string $phone, string $key): string
    {
        return self::TOKEN_PREFIX . substr(hash_hmac('sha256', $phone, $key), 0, 20);
    }

    /**
     * A comma-separated list of numbers with the customer's numbers replaced by their tokens (others kept).
     *
     * @param array<string,string> $tokens number => token
     */
    public static function replaceNumbers(string $list, array $tokens): string
    {
        if ($list === '') {
            return '';
        }
        $numbers = array_map('trim', explode(',', $list));
        foreach ($numbers as $i => $number) {
            if (isset($tokens[$number])) {
                $numbers[$i] = $tokens[$number];
            }
        }

        return implode(',', $numbers);
    }

    // ================================================================== database (protected: tests replace them)

    /**
     * 2026-10-23 00:56 +03: Task A10, task 596. The customer's row of kwtsms_customer_prefs (user_id, order_sms,
     * updated_at), or [].
     *
     * @return array<string,mixed>
     */
    protected function prefs(int $user_id): array
    {
        $row = db_get_row(
            'SELECT user_id, order_sms, updated_at FROM ?:kwtsms_customer_prefs WHERE user_id = ?i',
            $user_id
        );

        return is_array($row) ? $row : [];
    }

    /** 2026-10-23 00:56 +03: Task A10, task 596. Removes the account's opt-out row (exact user id). */
    protected function deletePrefs(int $user_id): void
    {
        if ($user_id > 0) {
            db_query('DELETE FROM ?:kwtsms_customer_prefs WHERE user_id = ?i', $user_id);
        }
    }

    /**
     * The customer's orders (by user id, or by e-mail as core's own orders_data does), with their child orders.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function orders(int $user_id, string $email): array
    {
        $condition = db_quote('user_id = ?i', $user_id);
        if ($email !== '') {
            $condition .= db_quote(' OR email = ?s', $email);
        }
        $orders = (array) db_get_array(
            'SELECT order_id, parent_order_id, phone, b_phone, s_phone, b_country, s_country FROM ?:orders WHERE ?p',
            $condition
        );
        $ids = array_map(static function (array $o): int {
            return (int) $o['order_id'];
        }, $orders);
        if ($ids !== []) {
            $children = (array) db_get_array(
                'SELECT order_id, parent_order_id, phone, b_phone, s_phone, b_country, s_country FROM ?:orders'
                . ' WHERE parent_order_id IN (?n) AND order_id NOT IN (?n)',
                $ids,
                $ids
            );
            $orders = array_merge($orders, $children);
        }

        return $orders;
    }

    /**
     * Message rows of the customer: by user id, order ids (also as parent), through the attempts of their numbers
     * (indexed), and any message whose number list or text holds one of their numbers (attempts are kept 365 days,
     * messages until cleared).
     *
     * @param int[]    $order_ids
     * @param string[] $phones
     *
     * @return array<int,array<string,mixed>>
     */
    protected function messages(int $user_id, array $order_ids, array $phones): array
    {
        return (array) db_get_array($this->messagesSql($user_id, $order_ids, $phones));
    }

    /**
     * The query of messages(), as SQL text (with the ?: prefix marker; also for EXPLAIN / ANALYZE).
     *
     * @param int[]    $order_ids
     * @param string[] $phones
     */
    public function messagesSql(int $user_id, array $order_ids, array $phones): string
    {
        $parts = [db_quote('user_id = ?i', $user_id)];
        if ($order_ids !== []) {
            $parts[] = db_quote('order_id IN (?n) OR parent_order_id IN (?n)', $order_ids, $order_ids);
        }
        if ($phones !== []) {
            $parts[] = db_quote(
                'message_id IN (SELECT message_id FROM ?:kwtsms_attempts WHERE phone IN (?a) AND message_id > 0)',
                $phones
            );
            foreach ($phones as $phone) {
                $like = '%' . addcslashes($phone, '%_\\') . '%';
                $parts[] = db_quote('recipients LIKE ?s OR message LIKE ?s', $like, $like);
            }
        }

        return db_quote(
            'SELECT message_id, created_at, event_id, receiver, recipients, message, status, is_test'
            . ' FROM ?:kwtsms_messages WHERE ?p ORDER BY message_id',
            '(' . implode(') OR (', $parts) . ')'
        );
    }

    /**
     * Attempt rows of the customer: by order ids and numbers (indexed), and by user id.
     *
     * @param int[]    $order_ids
     * @param string[] $phones
     *
     * @return array<int,array<string,mixed>>
     */
    protected function attempts(int $user_id, array $order_ids, array $phones): array
    {
        return (array) db_get_array($this->attemptsSql($user_id, $order_ids, $phones));
    }

    /**
     * The query of attempts(), as SQL text (with the ?: prefix marker; also for EXPLAIN / ANALYZE).
     *
     * @param int[]    $order_ids
     * @param string[] $phones
     */
    public function attemptsSql(int $user_id, array $order_ids, array $phones): string
    {
        $parts = [db_quote('user_id = ?i', $user_id)];
        if ($order_ids !== []) {
            $parts[] = db_quote('order_id IN (?n) OR parent_order_id IN (?n)', $order_ids, $order_ids);
        }
        if ($phones !== []) {
            $parts[] = db_quote('phone IN (?a)', $phones);
        }

        return db_quote(
            'SELECT attempt_id, created_at, event_id, receiver, phone, phone_raw, outcome, is_test'
            . ' FROM ?:kwtsms_attempts WHERE ?p ORDER BY attempt_id',
            '(' . implode(') OR (', $parts) . ')'
        );
    }

    /**
     * Outbox rows of the customer (by the user id or an order id of the stored context, or one of their numbers). The
     * outbox is small: final rows are pruned after 30 days (R28).
     *
     * @param int[]    $order_ids
     * @param string[] $phones
     *
     * @return array<int,array<string,mixed>>
     */
    protected function outbox(int $user_id, array $order_ids, array $phones): array
    {
        $hint = $this->config->defaultCountryCode();
        $out = [];
        $rows = (array) db_get_array(
            'SELECT outbox_id, created_at, status, phones, message, context FROM ?:kwtsms_outbox ORDER BY outbox_id'
        );
        foreach ($rows as $row) {
            $context = json_decode((string) $row['context'], true);
            $context = is_array($context) ? $context : [];
            $list = json_decode((string) $row['phones'], true);
            $match = (int) ($context['user_id'] ?? 0) === $user_id
                || in_array((int) ($context['order_id'] ?? 0), $order_ids, true)
                || in_array((int) ($context['parent_order_id'] ?? 0), $order_ids, true);
            foreach (is_array($list) ? $list : [] as $number) {
                if (in_array(PhoneNormalizer::normalize((string) $number, $hint), $phones, true)) {
                    $match = true;
                }
            }
            if ($match) {
                $message = json_decode((string) $row['message'], true);
                $out[] = [
                    'outbox_id' => (int) $row['outbox_id'],
                    'created_at' => (int) $row['created_at'],
                    'status' => (string) $row['status'],
                    'phones' => is_array($list) ? implode(',', array_map('strval', $list)) : '',
                    'message' => is_string($message) ? $message : '',
                    // 2026-10-21 08:37 +03: a waiting row still holds its OTP or reset link in clear; collect() masks
                    // the text with these and drops them (rule D7, fix round 2 item 1), task 596.
                    'secret_values' => isset($context['secret_values']) && is_array($context['secret_values'])
                        ? array_values($context['secret_values'])
                        : [],
                ];
            }
        }

        return $out;
    }
}
