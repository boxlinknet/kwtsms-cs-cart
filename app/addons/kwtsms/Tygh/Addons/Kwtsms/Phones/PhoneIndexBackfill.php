<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Phones/PhoneIndexBackfill.php
 * CREATED:   2026-10-24 17:12 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Fills kwtsms_phone_index from the customers that already exist, in batches in user_id order from a
 *            cursor, and removes index rows whose user no longer exists. The state lives in the storage key
 *            kwtsms_phone_index_state as {"cursor":<user_id>,"done":0|1,"total":<customers>} (plus "indexed", the
 *            customers handled so far, written by run()).
 * WHY:       Task M1.6 (P3-31). Nothing is indexed during an install or upgrade; the index is built only when an admin
 *            turns on C4, B1 or B5, by the cron job index_phones. Until it is done C4 would miss unindexed phones, so
 *            it does not refuse yet (PhoneIndex::enforcing()).
 * USAGE:     $b = Tygh::$app['kwtsms.phone_index_backfill'];
 *            $b->start();                 // state {"cursor":0,"done":0,"total":<count of type C users>}
 *            $r = $b->run();              // ['done' => bool, 'indexed' => rows handled in this run]; 2000/batch, 20 s
 *            $b->state();                 // ['cursor' => int, 'done' => 0|1, 'total' => int, 'indexed' => int]
 *            Each user's phone is read like the account phone everywhere else: users.phone, else the default profile's
 *            b_phone (AccountPhone::field), with the calling code of its b_country (AccountPhone::hint), else the
 *            store default country; PhoneFormat::fromRaw() gives the canonical form or '' (a legacy value is indexed
 *            as ''). Rows written by a live profile save are never made worse: a same-phone row keeps its
 *            verification. The sweep deletes, per batch, the index rows in the id range the batch just passed whose
 *            user is gone, and the whole tail above the cursor at the end. It sends nothing.
 *            Test seams (protected): countCustomers(), readBatch(), writeBatch(), sweep().
 * DEPENDS:   Phones\{AccountPhone,PhoneFormat}, Settings\ConfigInterface (defaultCountryCode),
 *            Storage\KeyValueStoreInterface, Log\DebugLog (optional); tables users, user_profiles, kwtsms_phone_index.
 */

namespace Tygh\Addons\Kwtsms\Phones;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Storage\KeyValueStoreInterface;

class PhoneIndexBackfill
{
    /** Storage key of the state (equal to Settings\Config::KEY_PHONE_INDEX_STATE). */
    public const KEY = 'kwtsms_phone_index_state';

    /** @var ConfigInterface */
    private $config;

    /** @var KeyValueStoreInterface */
    private $store;

    /** @var DebugLog|null */
    private $log;

    /** @var callable|null */
    private $clock;

    /** @var callable|null */
    private $microclock;

    public function __construct(
        ConfigInterface $config,
        KeyValueStoreInterface $store,
        ?DebugLog $log = null,
        ?callable $clock = null,
        ?callable $microclock = null
    ) {
        $this->config = $config;
        $this->store = $store;
        $this->log = $log;
        $this->clock = $clock;
        $this->microclock = $microclock;
    }

    /** Resets the state: cursor 0, not done, total = the customers there are now. */
    public function start(): void
    {
        $state = ['cursor' => 0, 'done' => 0, 'total' => $this->countCustomers()];
        $this->store->set(self::KEY, (string) json_encode($state));
    }

    /**
     * @return array{cursor:int,done:int,total:int,indexed:int}
     */
    public function state(): array
    {
        return self::parseState($this->store->getFresh(self::KEY));
    }

    /**
     * The state of a stored value; a missing or damaged value reads as "not started" (cursor 0, not done).
     *
     * @return array{cursor:int,done:int,total:int,indexed:int}
     */
    public static function parseState(?string $raw): array
    {
        $data = $raw === null || $raw === '' ? null : json_decode($raw, true);
        $data = is_array($data) ? $data : [];

        return [
            'cursor' => max(0, (int) ($data['cursor'] ?? 0)),
            'done' => (int) ($data['done'] ?? 0) === 1 ? 1 : 0,
            'total' => max(0, (int) ($data['total'] ?? 0)),
            'indexed' => max(0, (int) ($data['indexed'] ?? 0)),
        ];
    }

    /**
     * Handles batches until the customers are all passed or the time budget is used. At least one batch runs. A
     * store that has no state yet (a feature was switched on by another way than the Settings page) is started here.
     *
     * @return array{done:bool,indexed:int}
     */
    public function run(int $batch = 2000, float $budget_s = 20.0): array
    {
        $batch = max(1, $batch);
        if ($this->store->getFresh(self::KEY) === null) {
            $this->start();
        }
        $state = $this->state();
        if ($state['done'] === 1) {
            return ['done' => true, 'indexed' => 0];
        }
        $started = $this->micro();
        $default_cc = $this->config->defaultCountryCode();
        $handled = 0;
        $batches = 0;
        do {
            $previous = $state['cursor'];
            $users = $this->readBatch($previous, $batch);
            $rows = [];
            foreach ($users as $user) {
                $rows[] = ['user_id' => (int) $user['user_id'], 'phone' => self::canonicalOf($user, $default_cc)];
            }
            $last = $rows === [] ? $previous : $rows[count($rows) - 1]['user_id'];
            $final = count($users) < $batch;
            $this->writeBatch($rows, $this->now());
            // The id range this batch passed; at the end the whole tail above the cursor.
            $this->sweep($previous, $final ? 0 : $last);
            $handled += count($rows);
            $batches++;
            $state['cursor'] = $last;
            $state['indexed'] += count($rows);
            $state['done'] = $final ? 1 : 0;
            $this->store->set(self::KEY, (string) json_encode($state));
            if ($final) {
                break;
            }
        } while ($this->micro() - $started < $budget_s);

        $this->info($state, $handled, $batches, (int) round(($this->micro() - $started) * 1000));

        return ['done' => $state['done'] === 1, 'indexed' => $handled];
    }

    /**
     * The canonical phone of one user row (user_id, phone, b_phone, b_country), or '' when it is not a mobile.
     *
     * @param array<string,mixed> $user
     */
    private static function canonicalOf(array $user, string $default_cc): string
    {
        $data = [
            'phone' => (string) ($user['phone'] ?? ''),
            'b_phone' => (string) ($user['b_phone'] ?? ''),
            'b_country' => (string) ($user['b_country'] ?? ''),
        ];

        return AccountPhone::canonicalOf($data, [], $default_cc);
    }

    // ------------------------------------------------------------------ the database (replaced by tests)

    protected function countCustomers(): int
    {
        return (int) db_get_field('SELECT COUNT(*) FROM ?:users WHERE user_type = ?s', 'C');
    }

    /**
     * The next customers after the cursor, in user_id order (a primary-key range, the type is only a filter).
     *
     * @return array<int,array<string,mixed>>
     */
    protected function readBatch(int $after_user_id, int $limit): array
    {
        return (array) db_get_array(
            'SELECT u.user_id, u.phone, p.b_phone, p.b_country FROM ?:users u'
            . ' LEFT JOIN ?:user_profiles p ON p.user_id = u.user_id AND p.profile_type = ?s'
            . ' WHERE u.user_type = ?s AND u.user_id > ?i ORDER BY u.user_id LIMIT ?i',
            'P',
            'C',
            $after_user_id,
            $limit
        );
    }

    /**
     * Inserts the rows; an existing row keeps its verification when the phone is the same (see PhoneIndex).
     *
     * @param array<int,array{user_id:int,phone:string}> $rows
     */
    protected function writeBatch(array $rows, int $now): void
    {
        if ($rows === []) {
            return;
        }
        $values = [];
        foreach ($rows as $row) {
            $values[] = [
                'user_id' => $row['user_id'], 'phone' => $row['phone'], 'verified_phone' => null,
                'verified_at' => 0, 'updated_at' => $now,
            ];
        }
        db_query(
            'INSERT INTO ?:kwtsms_phone_index ?m ON DUPLICATE KEY UPDATE'
            . ' verified_phone = IF(phone = VALUES(phone), verified_phone, NULL),'
            . ' verified_at = IF(phone = VALUES(phone), verified_at, 0),'
            . ' phone = VALUES(phone), updated_at = VALUES(updated_at)',
            $values
        );
    }

    /**
     * Deletes the index rows with user_id in ($from, $to] whose user does not exist; $to = 0 means "no upper bound".
     * Only our own table is written, selected by id range.
     */
    protected function sweep(int $from, int $to): void
    {
        $sql = 'DELETE i FROM ?:kwtsms_phone_index i LEFT JOIN ?:users u ON u.user_id = i.user_id'
            . ' WHERE i.user_id > ?i AND u.user_id IS NULL';
        $args = [$from];
        if ($to > 0) {
            $sql .= ' AND i.user_id <= ?i';
            $args[] = $to;
        }
        db_query($sql, ...$args);
    }

    // ------------------------------------------------------------------ helpers

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }

    private function micro(): float
    {
        return $this->microclock === null ? microtime(true) : (float) call_user_func($this->microclock);
    }

    /**
     * @param array{cursor:int,done:int,total:int,indexed:int} $state
     */
    private function info(array $state, int $handled, int $batches, int $ms): void
    {
        if ($this->log !== null) {
            $this->log->log('info', 'phone_index.backfill', 'phone index backfill run', [
                'handled' => $handled, 'batches' => $batches, 'cursor' => $state['cursor'], 'done' => $state['done'],
                'total' => $state['total'],
            ], $ms);
        }
    }
}
