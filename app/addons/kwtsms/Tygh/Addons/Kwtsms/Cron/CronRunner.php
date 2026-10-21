<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Cron/CronRunner.php
 * CREATED:   2026-10-21 01:13 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The jobs of kwtsms_cron.run, behind one lock: drain_outbox (every run), sync (daily, at the slot of
 *            this install, or when a failed sync's retry time has come, or when ERR026 asked for a coverage
 *            resync), prune_logs (daily, retention per log table) and health (daily admin notices).
 * WHY:       PRD 6.11 and 6.13. One class, no CS-Cart globals, so every decision (the lock, the schedule of each job,
 *            what a failure does to the result) is unit-testable; fn_kwtsms_cron_run() in func.php wires the real
 *            services and the controller turns the result into an exit code or a JSON reply.
 * USAGE:     $result = Tygh::$app['kwtsms.cron']->run();            // all jobs
 *            $result = $runner->run(['sync']);                      // only the named ones
 *            // ['ok' => bool, 'locked' => bool, 'jobs' => [name => ['ok' => bool, ...]], 'errors' => string[],
 *            //  'ms' => int]
 *            Lock: createLock('kwtsms.cron', 600), taken without waiting. A run that finds it held returns
 *            locked = true, ok = true and does nothing (cron fires every minute; an earlier run is still going).
 *            A lock older than 600 s is the core store's to take over (a crashed run). The heartbeat
 *            (kwtsms_last_cron) is written once the lock is taken, before any job, and only here.
 *            Time budget: 50 s for the whole run; drain_outbox gets what is left after the probe of a suspended
 *            gateway. The drain is SmsService::drainDue(): this class never reads an outbox row and never hands row
 *            data to send() (a stale snapshot once resent numbers that were already accepted).
 *            A job that throws fails only itself (logged at error as cron.job_failed, class and message and
 *            file:line, no trace); the rest still run. ok = false is what makes the CLI exit non-zero.
 *            Daily jobs keep their last run in storage key kwtsms_cron_jobs; a failed prune is retried in an hour.
 *            2026-10-21 01:57 +03, Task 1.7 fix round 1, task 596: the drain runs with the cron flag of
 *            SmsService::drainDue() (cli timeouts, no web request budget) over the command line AND over the URL
 *            mode; prune_logs also deletes outbox rows in a final state (done, expired, unknown) older than 30 days
 *            (ruling R28), works in chunks of 5,000 and stops after 120 s, to go on at the next run (the job is
 *            stamped done only when every table is finished), so the first prune of a very large table cannot
 *            outlive the 600 s lock.
 *            Log rows: cron.sync (info, every attempt), cron.drain_outbox (info when rows were claimed or reaped),
 *            cron.prune_logs and cron.health (info), cron.run (info when a job did work or failed, else debug).
 * DEPENDS:   Gateway\GatewayState, Gateway\SyncService, Log\{LogStoreInterface,DebugLog,Schema},
 *            Settings\ConfigInterface, Storage\KeyValueStoreInterface, Alerts\AdminAlerts. A drain callable
 *            (int $limit, float $time_budget_s, bool $cron): array (SmsService::drainDue; the third argument was
 *            added on 2026-10-21 01:54 +03 (commit 09bfa1e; this said 02:30 until 2026-10-21 08:35 +03,
 *            cleanup item 8), Task 1.7 fix round 1 item 7, task 596, and the runner always passes
 *            true), a lock factory
 *            (string $name, int $ttl): object with acquire(bool): bool and release(), a held-credits callable
 *            (): array{messages: int, credits: float}, an outbox prune callable (int $before, int $limit): int
 *            (Queue\Outbox::pruneFinal, one chunk per call).
 *            2026-10-21 04:22 +03, ruling R32, Task 1.9 fix round 1 item 6, task 596: prune_logs also deletes
 *            status:* dispatch keys older than 2 days (the 24 h status window plus a day of margin) through a key
 *            prune callable (int $before, int $limit): int (Orders\DispatchKeys::pruneStatus, one chunk per call),
 *            after the outbox, in the same 5,000-row chunks and 120 s budget; order_placed:* keys are never pruned.
 */

namespace Tygh\Addons\Kwtsms\Cron;

use Tygh\Addons\Kwtsms\Alerts\AdminAlerts;
use Tygh\Addons\Kwtsms\Gateway\GatewayState;
use Tygh\Addons\Kwtsms\Gateway\SyncService;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Log\LogStoreInterface;
use Tygh\Addons\Kwtsms\Log\PrunableLogStoreInterface;
use Tygh\Addons\Kwtsms\Log\Schema;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Storage\KeyValueStoreInterface;

class CronRunner
{
    public const JOBS = ['drain_outbox', 'sync', 'prune_logs', 'health'];

    public const LOCK_NAME = 'kwtsms.cron';

    public const LOCK_TTL = 600;

    /** Seconds one run may use (PRD 6.11 gives the cron a minute; the drain stops early enough to finish). */
    public const BUDGET_SECONDS = 50.0;

    /** Most outbox rows one run handles. */
    public const DRAIN_ROWS = 200;

    /** Storage key of the last run of each daily job. */
    public const KEY_JOBS = 'kwtsms_cron_jobs';

    private const DAY = 86400;

    /**
     * Seconds prune_logs may work in one run; what is left waits for the next run.
     * 2026-10-21 02:30 +03: added, Task 1.7 fix round 1 item 5, task 596.
     * 2026-10-21 08:35 +03: this stamp added, cleanup item 6.
     */
    public const PRUNE_BUDGET_SECONDS = 120.0;

    /**
     * Outbox rows in a final state are kept this long (ruling R28).
     * 2026-10-21 02:30 +03: added, Task 1.7 fix round 1 item 5, task 596.
     * 2026-10-21 08:35 +03: this stamp added, cleanup item 6.
     */
    public const OUTBOX_FINAL_DAYS = 30;

    /**
     * Status dispatch keys are kept this long: the 24 h status window plus a day of margin (ruling R32).
     * 2026-10-21 04:22 +03: added, Task 1.9 fix round 1 item 6, task 596.
     */
    public const STATUS_KEY_DAYS = 2;

    /** A prune that failed is tried again after this many seconds. */
    private const RETRY_AFTER_FAILURE = 3600;

    /** Health notices repeat after 23 h, so a daily health run is never throttled by the notice it raised itself. */
    private const NOTICE_SECONDS = 82800;

    /** @var ConfigInterface */
    private $config;

    /** @var GatewayState */
    private $state;

    /** @var SyncService */
    private $sync;

    /**
     * @var callable (int $limit, float $time_budget_s, bool $cron): array
     *               2026-10-21 01:54 +03: $cron added, fix round 1 item 7, task 596 (this said 02:30 until
     *               2026-10-21 08:35 +03, cleanup item 8)
     */
    private $drain;

    /** @var LogStoreInterface */
    private $logs;

    /** @var DebugLog */
    private $log;

    /** @var KeyValueStoreInterface */
    private $store;

    /** @var callable (string $name, int $ttl): object */
    private $lock_factory;

    /** @var AdminAlerts|null */
    private $alerts;

    /** @var callable|null (): array{messages: int, credits: float} */
    private $held;

    /** @var callable|null */
    private $clock;

    /** @var callable|null */
    private $microclock;

    /**
     * @var callable|null (int $before, int $limit): int
     *                    2026-10-21 02:30 +03: added, Task 1.7 fix round 1 item 5, task 596
     *                    (2026-10-21 08:35 +03: this stamp added, cleanup item 6)
     */
    private $prune_outbox;

    /** @var callable|null (int $before, int $limit): int, expired status dispatch keys (2026-10-21 04:22 +03, R32, task 596) */
    private $prune_keys;

    /**
     * @param callable      $drain        (int $limit, float $time_budget_s, bool $cron): array,
     *                                    SmsService::drainDue(); the runner always passes $cron = true
     *                                    (2026-10-21 01:54 +03, fix round 1 item 7, task 596; said 02:30 until
     *                                    2026-10-21 08:35 +03, cleanup item 8)
     * @param callable      $lock_factory (string $name, int $ttl): object with acquire(bool): bool and release()
     * @param callable|null $held         (): array{messages: int, credits: float}, the test messages that hold credits
     * @param callable|null $clock        Returns the current Unix time (tests); null = time()
     * @param callable|null $microclock   Returns seconds as a float (tests); null = microtime(true)
     * @param callable|null $prune_outbox (int $before, int $limit): int, deletes one chunk of final outbox rows
     * @param callable|null $prune_keys   (int $before, int $limit): int, deletes one chunk of status dispatch keys
     *                                    created before $before (2026-10-21 04:22 +03, ruling R32, task 596)
     */
    public function __construct(
        ConfigInterface $config,
        GatewayState $state,
        SyncService $sync,
        callable $drain,
        LogStoreInterface $logs,
        DebugLog $log,
        KeyValueStoreInterface $store,
        callable $lock_factory,
        ?AdminAlerts $alerts = null,
        ?callable $held = null,
        ?callable $clock = null,
        ?callable $microclock = null,
        ?callable $prune_outbox = null,
        ?callable $prune_keys = null
    ) {
        $this->config = $config;
        $this->state = $state;
        $this->sync = $sync;
        $this->drain = $drain;
        $this->logs = $logs;
        $this->log = $log;
        $this->store = $store;
        $this->lock_factory = $lock_factory;
        $this->alerts = $alerts;
        $this->held = $held;
        $this->clock = $clock;
        $this->microclock = $microclock;
        $this->prune_outbox = $prune_outbox;
        $this->prune_keys = $prune_keys;
    }

    /**
     * Runs the jobs under the lock.
     *
     * @param string[] $jobs Names from self::JOBS; empty = all (unknown names are ignored)
     *
     * @return array{ok: bool, locked: bool, jobs: array<string,array<string,mixed>>, errors: string[], ms: int}
     */
    public function run(array $jobs = []): array
    {
        $started = $this->micro();
        $selected = $jobs === [] ? self::JOBS : array_values(array_intersect(self::JOBS, $jobs));
        $result = ['ok' => true, 'locked' => false, 'jobs' => [], 'errors' => [], 'ms' => 0];
        $this->note('debug', 'cron.enter', 'run()', ['jobs' => $selected]);

        try {
            $lock = call_user_func($this->lock_factory, self::LOCK_NAME, self::LOCK_TTL);
            $got = $lock->acquire(false) === true;
        } catch (\Throwable $e) {
            $this->noteThrowable('cron.lock_failed', $e);
            $result['ok'] = false;
            $result['errors'][] = 'lock: ' . $this->describe($e);

            return $this->finish($result, $started);
        }
        if (!$got) {
            $result['locked'] = true;
            $this->note('debug', 'cron.locked', 'another run holds the lock', []);

            return $this->finish($result, $started);
        }

        try {
            $this->state->touchCron();
            foreach ($selected as $job) {
                $out = $this->runJob($job, $started);
                $result['jobs'][$job] = $out;
                if ($out['ok'] !== true) {
                    $result['ok'] = false;
                    $result['errors'][] = $job . ': ' . (isset($out['error']) ? (string) $out['error'] : 'failed');
                }
            }
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $e) {
                $this->noteThrowable('cron.release_failed', $e);
            }
        }

        return $this->finish($result, $started);
    }

    // ================================================================== jobs

    /**
     * @return array<string,mixed>
     */
    private function runJob(string $job, float $run_started): array
    {
        try {
            switch ($job) {
                case 'drain_outbox':
                    return $this->drainOutbox($run_started);
                case 'sync':
                    return $this->syncJob();
                case 'prune_logs':
                    return $this->pruneLogs();
                default:
                    return $this->health();
            }
        } catch (\Throwable $e) {
            $this->noteThrowable('cron.job_failed', $e, ['job' => $job]);

            return ['ok' => false, 'error' => $this->describe($e)];
        }
    }

    /**
     * A suspended gateway is probed first (throttled inside SyncService: 15 min, ERR005 hourly), so the rows wait
     * no longer than they must; then SmsService::drainDue() sends the due rows, oldest first, within what is left of
     * the time budget. This job never reads outbox rows itself: SmsService re-reads each row when it claims it.
     *
     * @return array<string,mixed>
     */
    private function drainOutbox(float $run_started): array
    {
        $out = ['ok' => true, 'suspension' => null, 'drain' => []];
        if ($this->state->suspendedCode() !== null) {
            $out['suspension'] = $this->sync->retrySuspension(false, 'cli');
        }
        $left = max(0.0, self::BUDGET_SECONDS - ($this->micro() - $run_started));
        // 2026-10-21 01:54 +03: true = the cron's drain: no web request budget and cli timeouts, also when the run
        // came in over the URL mode, Task 1.7 fix round 1 item 7, task 596 (said 02:30 until 2026-10-21 08:35 +03,
        // cleanup item 8).
        $out['drain'] = $this->summarize(call_user_func($this->drain, self::DRAIN_ROWS, $left, true));
        $handled = $out['drain']['claimed'] + $out['drain']['reaped'];
        $this->note($handled > 0 ? 'info' : 'debug', 'cron.drain_outbox', $handled > 0 ? 'drained' : 'nothing due', [
            'drain' => $out['drain'],
            'suspension' => $out['suspension'],
        ]);

        return $out;
    }

    /**
     * What SmsService::drainDue() returns, without the SendResult objects: the counts, why it stopped, and how many
     * sends ended in each status.
     *
     * @param mixed $drain
     *
     * @return array{reaped: int, due: int, claimed: int, busy: int, stopped: string|null, statuses: array<string,int>}
     */
    private function summarize($drain): array
    {
        $drain = is_array($drain) ? $drain : [];
        $statuses = [];
        foreach ((array) ($drain['results'] ?? []) as $result) {
            if (is_object($result) && method_exists($result, 'status')) {
                $status = (string) $result->status();
                $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            }
        }

        return [
            'reaped' => max(0, (int) ($drain['reaped'] ?? 0)),
            'due' => max(0, (int) ($drain['due'] ?? 0)),
            'claimed' => max(0, (int) ($drain['claimed'] ?? 0)),
            'busy' => max(0, (int) ($drain['busy'] ?? 0)),
            'stopped' => isset($drain['stopped']) && is_string($drain['stopped']) ? $drain['stopped'] : null,
            'statuses' => $statuses,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function syncJob(): array
    {
        if ($this->sync->isSyncDue()) {
            $r = $this->sync->sync('cli');
            $this->note('info', 'cron.sync', $r['ok'] ? 'synced' : 'sync failed', [
                'ok' => $r['ok'],
                'code' => $r['code'],
                'retry_at' => $r['retry_at'],
            ]);

            return $r['ok']
                ? ['ok' => true, 'ran' => 'full']
                : ['ok' => false, 'ran' => 'full', 'error' => 'sync failed' . $this->codeSuffix($r['code'])];
        }
        if ($this->state->coverageResyncPending()) {
            $r = $this->sync->resyncCoverage('cli');
            $this->note('info', 'cron.sync', $r['ok'] ? 'coverage resynced' : 'coverage resync failed', [
                'ok' => $r['ok'],
                'code' => $r['code'],
                'coverage_only' => true,
            ]);

            return $r['ok']
                ? ['ok' => true, 'ran' => 'coverage']
                : [
                    'ok' => false,
                    'ran' => 'coverage',
                    'error' => 'coverage resync failed' . $this->codeSuffix($r['code']),
                ];
        }

        return ['ok' => true, 'ran' => false];
    }

    /**
     * Daily: deletes the rows older than the retention of each log table (0 days keeps the table) and the outbox rows
     * in a final state older than 30 days, in chunks of 5,000. It works for at most 120 s per run: what is left waits
     * for the next run (the job is not stamped done until every table is finished), so the first prune of a very
     * large table cannot outlive the 600 s lock. A table that fails is reported and the others still run; the whole
     * job is then retried in an hour.
     * 2026-10-21 02:30 +03: rewritten for chunks, the 120 s budget and the outbox (ruling R28), Task 1.7 fix
     * round 1 item 5, task 596.
     *
     * @return array<string,mixed>
     */
    private function pruneLogs(): array
    {
        if (!$this->dailyDue('prune_logs')) {
            return ['ok' => true, 'ran' => false];
        }
        $deadline = $this->micro() + self::PRUNE_BUDGET_SECONDS;
        $now = $this->now();
        $retention = [
            Schema::MESSAGES => $this->config->retentionMessagesDays(),
            Schema::ATTEMPTS => $this->config->retentionAttemptsDays(),
            Schema::DEBUG => $this->config->retentionDebugDays(),
        ];
        $deleted = [];
        $errors = [];
        $partial = false;
        foreach ($retention as $table => $days) {
            if ($days <= 0) {
                continue;
            }
            if ($this->micro() >= $deadline) {
                $partial = true;
                break;
            }
            try {
                $deleted[$table] = $this->pruneChunks(function (int $chunk) use ($table, $now, $days): int {
                    return $this->logs instanceof PrunableLogStoreInterface
                        ? $this->logs->pruneChunk($table, $now - $days * self::DAY, $chunk)
                        : $this->logs->deleteOlderThan($table, $now - $days * self::DAY, $chunk);
                }, $deadline, $this->logs instanceof PrunableLogStoreInterface, $partial);
            } catch (\Throwable $e) {
                $errors[] = $table . ': ' . $this->describe($e);
                $this->noteThrowable('cron.prune_failed', $e, ['table' => $table]);
            }
            if ($partial) {
                break;
            }
        }
        if (!$partial && $this->prune_outbox !== null) {
            if ($this->micro() >= $deadline) {
                $partial = true;
            } else {
                try {
                    $before = $now - self::OUTBOX_FINAL_DAYS * self::DAY;
                    $deleted['outbox'] = $this->pruneChunks(function (int $chunk) use ($before): int {
                        return (int) call_user_func($this->prune_outbox, $before, $chunk);
                    }, $deadline, true, $partial);
                } catch (\Throwable $e) {
                    $errors[] = 'outbox: ' . $this->describe($e);
                    $this->noteThrowable('cron.prune_failed', $e, ['table' => 'outbox']);
                }
            }
        }
        // 2026-10-21 04:22 +03: expired status dispatch keys, ruling R32 (Task 1.9 fix round 1 item 6), task 596.
        if (!$partial && $this->prune_keys !== null) {
            if ($this->micro() >= $deadline) {
                $partial = true;
            } else {
                try {
                    $before = $now - self::STATUS_KEY_DAYS * self::DAY;
                    $deleted['dispatch_keys'] = $this->pruneChunks(function (int $chunk) use ($before): int {
                        return (int) call_user_func($this->prune_keys, $before, $chunk);
                    }, $deadline, true, $partial);
                } catch (\Throwable $e) {
                    $errors[] = 'dispatch_keys: ' . $this->describe($e);
                    $this->noteThrowable('cron.prune_failed', $e, ['table' => 'dispatch_keys']);
                }
            }
        }
        if (!$partial) {
            $this->stampDaily('prune_logs', $errors === []);
        }
        $summary = $errors === [] ? 'pruned' : 'prune failed in part';
        $this->note(
            'info',
            'cron.prune_logs',
            $partial ? 'time budget used, the rest waits for the next run' : $summary,
            ['deleted' => $deleted, 'errors' => $errors, 'partial' => $partial]
        );

        return array_filter([
            'ok' => $errors === [],
            'ran' => true,
            'deleted' => $deleted,
            'partial' => $partial,
            'error' => $errors === [] ? null : implode('; ', $errors),
        ], static function ($value): bool {
            return $value !== null;
        });
    }

    /**
     * Calls $delete (one chunk of rows per call, returns how many it deleted) until a chunk comes back short, or
     * the deadline passes. A delete that cannot be done in chunks (a store without pruneChunk()) is called once.
     * 2026-10-21 02:30 +03: added, Task 1.7 fix round 1 item 5, task 596.
     *
     * @param callable(int):int $delete
     * @param bool              $chunked True when $delete deletes at most the given number of rows
     * @param bool              $partial Set to true when the deadline stopped the work before it was done
     */
    private function pruneChunks(callable $delete, float $deadline, bool $chunked, bool &$partial): int
    {
        $chunk = LogStoreInterface::DELETE_CHUNK;
        $total = 0;
        do {
            $n = (int) $delete($chunk);
            $total += $n;
            $more = $chunked && $n >= $chunk;
        } while ($more && $this->micro() < $deadline);
        if ($more) {
            $partial = true;
        }

        return $total;
    }

    /**
     * Daily: re-raises the notices of PRD 6.13 that stay true until somebody fixes them. AdminAlerts throttles each
     * condition and never throws.
     *
     * @return array<string,mixed>
     */
    private function health(): array
    {
        if (!$this->dailyDue('health')) {
            return ['ok' => true, 'ran' => false, 'raised' => []];
        }
        $raised = [];
        $enabled = $this->config->enabled();

        if ($enabled && $this->config->testMode()) {
            $held = $this->heldCredits();
            $params = ['[count]' => $held['messages'], '[credits]' => $this->number($held['credits'])];
            if ($this->raise('test_mode', $params, false)) {
                $raised[] = 'test_mode';
            }
        }
        $balance = $this->state->balance();
        if ($enabled && $balance !== null && $balance['available'] <= 0.0) {
            if ($this->raise('balance_zero', [], true)) {
                $raised[] = 'balance_zero';
            }
        }
        $suspended = $this->state->suspendedCode();
        if ($suspended !== null && $this->raise('suspended', ['[code]' => $suspended], true, $suspended)) {
            $raised[] = 'suspended';
        }
        $selected = $this->config->senderId();
        if ($selected !== '' && $this->config->credentialsState() === ConfigInterface::CREDENTIALS_OK) {
            $senders = $this->state->senderIds();
            if ($this->state->bannedSender() === $selected) {
                if ($this->raise('sender_banned', ['[sender]' => $selected], true, $selected)) {
                    $raised[] = 'sender_banned';
                }
            } elseif ($senders !== [] && !in_array($selected, $senders, true)) {
                if ($this->raise('sender_removed', ['[sender]' => $selected], true, $selected)) {
                    $raised[] = 'sender_removed';
                }
            }
        }
        $this->stampDaily('health', true);
        $this->note('info', 'cron.health', 'checked', ['raised' => $raised]);

        return ['ok' => true, 'ran' => true, 'raised' => $raised];
    }

    // ================================================================== internals

    /**
     * @param array<string,scalar> $params
     */
    private function raise(string $condition, array $params, bool $email, string $instance = ''): bool
    {
        return $this->alerts !== null
            && $this->alerts->raise($condition, $params, $email, self::NOTICE_SECONDS, $instance);
    }

    /**
     * @return array{messages: int, credits: float}
     */
    private function heldCredits(): array
    {
        if ($this->held === null) {
            return ['messages' => 0, 'credits' => 0.0];
        }
        try {
            $held = call_user_func($this->held);

            return [
                'messages' => max(0, (int) ($held['messages'] ?? 0)),
                'credits' => max(0.0, (float) ($held['credits'] ?? 0)),
            ];
        } catch (\Throwable $e) {
            $this->noteThrowable('cron.held_failed', $e);

            return ['messages' => 0, 'credits' => 0.0];
        }
    }

    private function dailyDue(string $job): bool
    {
        $last = $this->stamps()[$job] ?? 0;

        return $this->now() - $last >= self::DAY;
    }

    /**
     * Records that a daily job ran. A failed job is stamped so that it is due again in an hour, not at once.
     */
    private function stampDaily(string $job, bool $ok): void
    {
        $stamps = $this->stamps();
        $stamps[$job] = $ok ? $this->now() : $this->now() - self::DAY + self::RETRY_AFTER_FAILURE;
        $this->store->set(self::KEY_JOBS, (string) json_encode($stamps));
    }

    /**
     * @return array<string,int>
     */
    private function stamps(): array
    {
        $data = json_decode((string) $this->store->getFresh(self::KEY_JOBS), true);
        $out = [];
        if (is_array($data)) {
            foreach ($data as $job => $at) {
                if (is_string($job) && is_int($at)) {
                    $out[$job] = $at;
                }
            }
        }

        return $out;
    }

    /**
     * @param array{ok: bool, locked: bool, jobs: array<string,array<string,mixed>>, errors: string[], ms: int} $result
     *
     * @return array{ok: bool, locked: bool, jobs: array<string,array<string,mixed>>, errors: string[], ms: int}
     */
    private function finish(array $result, float $started): array
    {
        $result['ms'] = (int) round(($this->micro() - $started) * 1000);
        $worked = false;
        foreach ($result['jobs'] as $out) {
            if (
                ($out['ran'] ?? false) !== false
                || (int) ($out['drain']['claimed'] ?? 0) + (int) ($out['drain']['reaped'] ?? 0) > 0
            ) {
                $worked = true;
            }
        }
        $level = (!$result['ok'] || $worked) ? 'info' : 'debug';
        $this->note($level, 'cron.run', $result['locked'] ? 'locked' : ($result['ok'] ? 'done' : 'failed'), [
            'jobs' => array_map(static function (array $out): bool {
                return $out['ok'] === true;
            }, $result['jobs']),
            'errors' => $result['errors'],
        ], $result['ms']);

        return $result;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }

    private function micro(): float
    {
        return $this->microclock === null ? microtime(true) : (float) call_user_func($this->microclock);
    }

    private function codeSuffix(?string $code): string
    {
        return $code === null || $code === '' ? '' : ' (' . $code . ')';
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function describe(\Throwable $e): string
    {
        return get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    /**
     * @param array<mixed> $context
     */
    private function note(string $level, string $action, string $message, array $context = [], ?int $ms = null): void
    {
        $this->log->log($level, $action, $message, $context, $ms);
    }

    /**
     * @param array<mixed> $context
     */
    private function noteThrowable(string $action, \Throwable $e, array $context = []): void
    {
        $this->note('error', $action, $this->describe($e), $context);
    }
}
