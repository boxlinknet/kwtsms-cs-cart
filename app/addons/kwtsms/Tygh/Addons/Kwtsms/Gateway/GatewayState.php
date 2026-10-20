<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Gateway/GatewayState.php
 * CREATED:   2026-10-20 21:20 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The cached state of the kwtSMS account: balance, Sender ID list, coverage prefixes, last
 *            sync and cron times, the "configured" verdict (PRD 6.1), the account-level suspension and
 *            the transport-failure circuit breaker.
 * WHY:       The send pipeline, the sync service, the dashboard and the cron all need the same answers
 *            ("can we send?", "why not?", "is the gateway reachable?") without calling the API. Keeping
 *            them behind one class on a KeyValueStoreInterface makes every rule unit-testable. Values
 *            come from API replies and from hand-edited rows, so every read validates and degrades to
 *            "unknown" instead of failing.
 * USAGE:     $state = new GatewayState($store);                      // $store: StorageDataStore in production
 *            $state->setBalance(150.0, 1200.0);  $state->balance();
 *                // ['available' => 150.0, 'purchased' => 1200.0, 'synced_at' => 1760000000]
 *            [$ok, $reason] = $state->isConfigured($config);         // [false, 'sender_not_in_list'] ...
 *            $state->suspend('ERR005');  $state->suspendedCode();  $state->resume();
 *            $state->recordTransportFailure();  $state->breaker();   // ['failures'=>3,'open_until'=>...,'open'=>true]
 *            Storage keys (PRD 8): kwtsms_balance, kwtsms_senderids, kwtsms_coverage, kwtsms_last_sync,
 *            kwtsms_last_cron, kwtsms_gateway_state (suspension and breaker). kwtsms_api_slot belongs to ApiGuard.
 *            isConfigured() reasons, in the order they are checked: no_credentials, relogin_required,
 *            not_logged_in (no balance cached: a login stores one), no_sender_ids, sender_not_in_list
 *            (covers "nothing selected").
 *            2026-10-21 00:05 +03, Task 1.6, task 596: new GatewayState($store, $clock, $locker), where $locker
 *            (callable $write): bool runs $write under the per-database API lock (ApiGuard::runLocked()); suspend()
 *            and resume() write through it so they cannot overwrite a breaker count written under that lock by a
 *            parallel request (Task 1.5 review carry). Also: kwtsms_daily_cap (the "Resume now" time of the
 *            store daily cap, PRD 6.14) and kwtsms_balance_refresh (the last live /balance/ refresh asked for by
 *            the send pipeline, at most one every 10 minutes, PRD 6.1).
 *            2026-10-21 00:52 +03, Task 1.7, task 596: the sync bookkeeping. kwtsms_sync_state holds the retry
 *            schedule after a failed sync (syncRetryAt(): +15 min, +1 h, then +6 h), the throttled coverage resync
 *            request of ERR026 (requestCoverageResync(), at most one an hour) and the last /balance/ probe
 *            (lastProbe(): time, verdict, code) that throttles probes across requests. kwtsms_sender_ban holds the
 *            Sender ID that ERR008 banned (banSender(), bannedSender()); isConfigured() then reports
 *            sender_banned for that sender only, so selecting another one lifts it. clear() forgets all of it.
 * DEPENDS:   Storage\KeyValueStoreInterface, Settings\ConfigInterface. No CS-Cart globals, no logging.
 */

namespace Tygh\Addons\Kwtsms\Gateway;

use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Storage\KeyValueStoreInterface;

class GatewayState
{
    public const KEY_BALANCE = 'kwtsms_balance';
    public const KEY_SENDERIDS = 'kwtsms_senderids';
    public const KEY_COVERAGE = 'kwtsms_coverage';
    public const KEY_LAST_SYNC = 'kwtsms_last_sync';
    public const KEY_LAST_CRON = 'kwtsms_last_cron';
    public const KEY_GATEWAY_STATE = 'kwtsms_gateway_state';

    /** 2026-10-21 00:05 +03: "Resume now" time of the store daily cap (PRD 6.14), Task 1.6, task 596. */
    public const KEY_DAILY_CAP = 'kwtsms_daily_cap';

    /** 2026-10-21 00:05 +03: last live balance refresh of the send pipeline (PRD 6.1), Task 1.6, task 596. */
    public const KEY_BALANCE_REFRESH = 'kwtsms_balance_refresh';

    /** 2026-10-21 01:02 +03: the auto low-balance threshold of the day (PRD 6.13), Task 1.6 fix round 1, task 596. */
    public const KEY_LOW_BALANCE_AUTO = 'kwtsms_low_balance_auto';

    /** 2026-10-21 00:52 +03: sync retry schedule, coverage resync request, last probe (Task 1.7, task 596). */
    public const KEY_SYNC_STATE = 'kwtsms_sync_state';

    /** 2026-10-21 00:52 +03: the Sender ID that ERR008 banned (Task 1.7, task 596). */
    public const KEY_SENDER_BAN = 'kwtsms_sender_ban';

    /** Seconds to wait before the next sync after the 1st, 2nd and 3rd (or later) failure (PRD 6.1). */
    public const SYNC_RETRY_SECONDS = [900, 3600, 21600];

    /** At most one coverage resync (ERR026) in this many seconds. */
    public const COVERAGE_RESYNC_SECONDS = 3600;

    /**
     * Consecutive transport failures (connect, TLS, DNS, read timeout) that open the breaker.
     * 2026-10-20 23:16 +03: read timeout added to the wording (ruling R20: it keeps counting), task 596.
     */
    public const BREAKER_FAILURES = 3;

    /** Seconds the breaker stays open (reason gateway_unreachable). */
    public const BREAKER_SECONDS = 300;

    /** Sender IDs: PRD 10, validated on sync. */
    private const SENDER_PATTERN = '/^[A-Za-z0-9 .\-]{1,11}$/D';

    /** Coverage prefixes: PRD 10, validated on sync. */
    private const PREFIX_PATTERN = '/^[0-9]{1,4}$/D';

    /** @var KeyValueStoreInterface */
    private $store;

    /** @var callable|null returns the current Unix time */
    private $clock;

    /** @var callable|null (callable $write): bool, runs $write under the per-database API lock */
    private $locker;

    /**
     * @param KeyValueStoreInterface $store
     * @param callable|null          $clock  Returns the current Unix time (tests); null = time()
     * @param callable|null          $locker (callable $write): bool, runs $write under the per-database API lock
     *                                       and returns false when the lock was not available; null = no lock
     *                                       (2026-10-21 00:05 +03: added, Task 1.6, task 596)
     */
    public function __construct(KeyValueStoreInterface $store, ?callable $clock = null, ?callable $locker = null)
    {
        $this->store = $store;
        $this->clock = $clock;
        $this->locker = $locker;
    }

    // ------------------------------------------------------------------ balance

    /**
     * Cached balance, or null when none is cached or the stored value is unusable.
     *
     * @return array{available: float, purchased: float|null, synced_at: int}|null
     */
    public function balance(): ?array
    {
        $data = $this->decode($this->store->get(self::KEY_BALANCE));
        if ($data === null || !isset($data['available']) || !$this->isFiniteNumber($data['available'])) {
            return null;
        }

        return [
            'available' => (float) $data['available'],
            'purchased' => isset($data['purchased']) && $this->isFiniteNumber($data['purchased'])
                ? (float) $data['purchased']
                : null,
            'synced_at' => isset($data['synced_at']) && $this->isFiniteNumber($data['synced_at'])
                ? max(0, (int) $data['synced_at'])
                : 0,
        ];
    }

    /**
     * Stores the balance and stamps the time. A null $purchased keeps the previous purchased value (the
     * balance-after of a send does not carry it). A non-finite argument makes the call a no-op.
     */
    public function setBalance(float $available, ?float $purchased): void
    {
        if (!is_finite($available) || ($purchased !== null && !is_finite($purchased))) {
            return;
        }
        if ($purchased === null) {
            $previous = $this->balance();
            $purchased = $previous === null ? null : $previous['purchased'];
        }

        $this->store->set(
            self::KEY_BALANCE,
            (string) json_encode(
                ['available' => $available, 'purchased' => $purchased, 'synced_at' => $this->now()],
                JSON_PRESERVE_ZERO_FRACTION
            )
        );
    }

    // ------------------------------------------------------------------ sender ids

    /**
     * Cached Sender IDs of the account, case kept, in the order the gateway returned them.
     *
     * @return string[]
     */
    public function senderIds(): array
    {
        return $this->cleanList($this->decodeList($this->store->get(self::KEY_SENDERIDS)), self::SENDER_PATTERN);
    }

    /**
     * The entries of a list from the gateway that are valid Sender IDs (^[A-Za-z0-9 .-]{1,11}$), in order, without
     * duplicates. The sync uses it to tell "the account has none" from "the reply held only garbage".
     * 2026-10-21 01:05 +03: added, Task 1.7, task 596.
     *
     * @param array<int, mixed> $ids
     *
     * @return string[]
     */
    public static function validSenderIds(array $ids): array
    {
        return self::cleanList($ids, self::SENDER_PATTERN);
    }

    /**
     * The entries of a coverage list that are valid calling-code prefixes (^[0-9]{1,4}$). 2026-10-21 01:05 +03:
     * added, Task 1.7, task 596.
     *
     * @param array<int, mixed> $prefixes
     *
     * @return string[]
     */
    public static function validPrefixes(array $prefixes): array
    {
        return self::cleanList($prefixes, self::PREFIX_PATTERN);
    }

    /**
     * Replaces the cached list. Entries that do not match ^[A-Za-z0-9 .-]{1,11}$ and duplicates are
     * dropped. An empty list is stored as empty (a successful sync that returned none).
     *
     * @param array<int, string|int> $ids
     */
    public function setSenderIds(array $ids): void
    {
        $this->store->set(
            self::KEY_SENDERIDS,
            (string) json_encode($this->cleanList($ids, self::SENDER_PATTERN)),
            true
        );
    }

    // ------------------------------------------------------------------ coverage

    /**
     * Calling-code prefixes the account can send to, or null when unknown (never synced, or an empty
     * list): then nothing is filtered and the attempt notes coverage_unknown.
     *
     * @return string[]|null
     */
    public function coverage(): ?array
    {
        $list = $this->cleanList($this->decodeList($this->store->get(self::KEY_COVERAGE)), self::PREFIX_PATTERN);

        return $list === [] ? null : $list;
    }

    /**
     * Replaces the cached coverage. Prefixes must match ^[0-9]{1,4}$. Nothing valid left means
     * "unknown": the key is removed.
     *
     * @param array<int, string|int> $prefixes
     */
    public function setCoverage(array $prefixes): void
    {
        $list = $this->cleanList($prefixes, self::PREFIX_PATTERN);
        if ($list === []) {
            $this->store->delete(self::KEY_COVERAGE);

            return;
        }
        $this->store->set(self::KEY_COVERAGE, (string) json_encode($list));
    }

    // ------------------------------------------------------------------ sync and cron times

    public function lastSync(): ?int
    {
        return $this->readTime(self::KEY_LAST_SYNC);
    }

    public function touchSync(): void
    {
        $this->store->set(self::KEY_LAST_SYNC, (string) $this->now());
    }

    public function lastCron(): ?int
    {
        return $this->readTime(self::KEY_LAST_CRON);
    }

    public function touchCron(): void
    {
        $this->store->set(self::KEY_LAST_CRON, (string) $this->now());
    }

    // ------------------------------------------------------------------ configured

    /**
     * PRD 6.1: configured = credentials saved AND last login successful AND the cached sender list is
     * not empty AND the selected sender is in it. A successful login caches a balance, so "last login
     * successful" is "a balance is cached".
     *
     * @return array{0: bool, 1: string|null} [true, null] or [false, reason]
     */
    public function isConfigured(ConfigInterface $config): array
    {
        $credentials = $config->credentialsState();
        if ($credentials === ConfigInterface::CREDENTIALS_RELOGIN) {
            return [false, 'relogin_required'];
        }
        if ($credentials !== ConfigInterface::CREDENTIALS_OK) {
            return [false, 'no_credentials'];
        }
        if ($this->balance() === null) {
            return [false, 'not_logged_in'];
        }
        $senders = $this->senderIds();
        if ($senders === []) {
            return [false, 'no_sender_ids'];
        }
        if (!in_array($config->senderId(), $senders, true)) {
            return [false, 'sender_not_in_list'];
        }
        // 2026-10-21 00:52 +03: ERR008 banned this Sender ID; selecting another one lifts it (PRD 6.1), Task 1.7,
        // task 596.
        if ($this->bannedSender() === $config->senderId()) {
            return [false, 'sender_banned'];
        }

        return [true, null];
    }

    // ------------------------------------------------------------------ suspension

    /**
     * Account-level error code that suspended sending (ERR001, ERR004, ERR005, ERR024, ERR034 or a
     * confirmed ERR003), or null.
     */
    public function suspendedCode(): ?string
    {
        return $this->readState()['suspended_code'];
    }

    /** When the current suspension was recorded (Unix time), or null. */
    public function suspendedAt(): ?int
    {
        $state = $this->readState();

        return $state['suspended_code'] === null ? null : $state['suspended_at'];
    }

    /**
     * Records a suspension. The code is upper-cased and reduced to A-Z, 0-9 and "_" (at most 32
     * characters); an empty result is stored as UNKNOWN so a suspension is never lost.
     */
    public function suspend(string $code): void
    {
        $clean = substr(strtoupper((string) preg_replace('/[^A-Za-z0-9_]/', '', $code)), 0, 32);
        // 2026-10-21 00:05 +03: read-modify-write under the API lock, Task 1.6 carry, task 596.
        $this->writeLocked(function () use ($clean): void {
            $state = $this->readState();
            $state['suspended_code'] = $clean === '' ? 'UNKNOWN' : $clean;
            $state['suspended_at'] = $this->now();
            $this->writeState($state);
        });
    }

    /** Lifts the suspension. The breaker is left alone. */
    public function resume(): void
    {
        // 2026-10-21 00:05 +03: read-modify-write under the API lock, Task 1.6 carry, task 596.
        $this->writeLocked(function (): void {
            $state = $this->readState();
            $state['suspended_code'] = null;
            $state['suspended_at'] = 0;
            $this->writeState($state);
        });
    }

    // ------------------------------------------------------------------ sender ban (ERR008)

    /**
     * The Sender ID that kwtSMS banned (ERR008), or null. Only that sender is affected: isConfigured() reports
     * sender_banned while it is the selected one. 2026-10-21 00:52 +03: added, Task 1.7, task 596.
     */
    public function bannedSender(): ?string
    {
        $data = $this->decode($this->store->getFresh(self::KEY_SENDER_BAN)) ?? [];
        $sender = $data['sender'] ?? null;

        return (is_string($sender) && preg_match(self::SENDER_PATTERN, $sender) === 1) ? $sender : null;
    }

    /**
     * Records the ban. An id that is not a valid Sender ID is ignored (nothing could match it anyway).
     * 2026-10-21 00:52 +03: added, Task 1.7, task 596.
     */
    public function banSender(string $sender): void
    {
        if (preg_match(self::SENDER_PATTERN, $sender) !== 1) {
            return;
        }
        $this->store->set(self::KEY_SENDER_BAN, (string) json_encode(['sender' => $sender, 'at' => $this->now()]));
    }

    // ------------------------------------------------------------------ sync schedule, resync request, probe

    /** Consecutive failed syncs since the last good one. 2026-10-21 00:52 +03: added, Task 1.7, task 596. */
    public function syncFailures(): int
    {
        return $this->readSyncState()['fail'];
    }

    /** When the next sync is due after a failure (Unix time), or null when none failed. */
    public function syncRetryAt(): ?int
    {
        $state = $this->readSyncState();

        return $state['fail'] > 0 ? $state['retry_at'] : null;
    }

    /**
     * Counts a failed sync and schedules the next try: 15 min after the first failure, 1 h after the second, 6 h
     * after the third and every later one (PRD 6.1).
     *
     * @return int The time of the next try
     */
    public function recordSyncFailure(): int
    {
        $state = $this->readSyncState();
        $state['fail']++;
        $delays = self::SYNC_RETRY_SECONDS;
        $state['retry_at'] = $this->now() + $delays[min($state['fail'], count($delays)) - 1];
        $this->writeSyncState($state);

        return $state['retry_at'];
    }

    /** A sync finished: the schedule and any pending coverage resync request are cleared. */
    public function recordSyncSuccess(): void
    {
        $state = $this->readSyncState();
        $state['fail'] = 0;
        $state['retry_at'] = 0;
        $state['cov_req'] = 0;
        $this->writeSyncState($state);
    }

    /**
     * ERR026 asks for a coverage resync (the cron performs it). Ignored while one is pending and for an hour after
     * the last one ran.
     *
     * @return bool True when a new request was recorded
     */
    public function requestCoverageResync(): bool
    {
        $state = $this->readSyncState();
        if ($state['cov_req'] > 0 || $this->now() - $state['cov_last'] < self::COVERAGE_RESYNC_SECONDS) {
            return false;
        }
        $state['cov_req'] = $this->now();
        $this->writeSyncState($state);

        return true;
    }

    public function coverageResyncPending(): bool
    {
        return $this->readSyncState()['cov_req'] > 0;
    }

    /** The coverage resync ran: the request is cleared and the hour starts. */
    public function touchCoverageResync(): void
    {
        $state = $this->readSyncState();
        $state['cov_req'] = 0;
        $state['cov_last'] = $this->now();
        $this->writeSyncState($state);
    }

    /**
     * The last /balance/ probe that the gateway answered, or null. Probes (suspension, ERR003 on /send/) are
     * throttled by this time across requests.
     *
     * @return array{at: int, verdict: string, code: string|null}|null
     */
    public function lastProbe(): ?array
    {
        $state = $this->readSyncState();
        if ($state['probe_at'] <= 0) {
            return null;
        }

        return ['at' => $state['probe_at'], 'verdict' => $state['probe_verdict'], 'code' => $state['probe_code']];
    }

    /**
     * Records the verdict of a probe the gateway answered: "ok", an error code such as ERR003, or any other
     * short token. Reduced to A-Z, a-z, 0-9 and "_" (at most 32 characters); an empty verdict is "unknown".
     */
    public function recordProbe(string $verdict, ?string $code): void
    {
        $clean = substr((string) preg_replace('/[^A-Za-z0-9_]/', '', $verdict), 0, 32);
        $clean_code = $code === null
            ? ''
            : substr(strtoupper((string) preg_replace('/[^A-Za-z0-9_]/', '', $code)), 0, 32);
        $state = $this->readSyncState();
        $state['probe_at'] = $this->now();
        $state['probe_verdict'] = $clean === '' ? 'unknown' : $clean;
        $state['probe_code'] = $clean_code === '' ? null : $clean_code;
        $this->writeSyncState($state);
    }

    // ------------------------------------------------------------------ daily cap and balance refresh

    /**
     * When "Resume now" was last pressed for the store daily cap (0 = never): the day's count starts at the
     * later of local midnight and this time. 2026-10-21 00:05 +03: added, Task 1.6, task 596.
     */
    public function dailyCapResumedAt(): int
    {
        $data = $this->decode($this->store->getFresh(self::KEY_DAILY_CAP)) ?? [];

        return $this->intField($data, 'resumed_at');
    }

    /** "Resume now" (PRD 6.14). 2026-10-21 00:05 +03: added, Task 1.6, task 596. */
    public function resumeDailyCap(): void
    {
        $this->store->set(self::KEY_DAILY_CAP, (string) json_encode(['resumed_at' => $this->now()]));
    }

    /**
     * When the send pipeline last asked kwtSMS for a live balance (null = never). PRD 6.1: at most one such
     * refresh every 10 minutes. 2026-10-21 00:05 +03: added, Task 1.6, task 596.
     */
    public function lastBalanceRefresh(): ?int
    {
        $raw = $this->store->getFresh(self::KEY_BALANCE_REFRESH);

        return ($raw !== null && ctype_digit($raw)) ? (int) $raw : null;
    }

    /** 2026-10-21 00:05 +03: added, Task 1.6, task 596. */
    public function touchBalanceRefresh(): void
    {
        $this->store->set(self::KEY_BALANCE_REFRESH, (string) $this->now());
    }

    /**
     * The "auto" low-balance threshold computed for $day (Y-m-d), or null when none is cached for that day.
     * Key kwtsms_low_balance_auto: {"day": "...", "threshold": n}. 2026-10-21 01:02 +03: added (the 30-day average
     * is counted once a day, not on every send), Task 1.6 fix round 1, task 596.
     */
    public function lowBalanceAuto(string $day): ?int
    {
        $data = $this->decode($this->store->getFresh(self::KEY_LOW_BALANCE_AUTO)) ?? [];
        if (($data['day'] ?? null) !== $day || !isset($data['threshold']) || !is_int($data['threshold'])) {
            return null;
        }

        return max(0, $data['threshold']);
    }

    /** 2026-10-21 01:02 +03: added, Task 1.6 fix round 1, task 596. */
    public function setLowBalanceAuto(string $day, int $threshold): void
    {
        $value = ['day' => $day, 'threshold' => max(0, $threshold)];
        $this->store->set(self::KEY_LOW_BALANCE_AUTO, (string) json_encode($value));
    }

    // ------------------------------------------------------------------ breaker

    /**
     * @return array{failures: int, open_until: int, open: bool}
     */
    public function breaker(): array
    {
        $state = $this->readState();

        return [
            'failures' => $state['failures'],
            'open_until' => $state['open_until'],
            'open' => $this->now() < $state['open_until'],
        ];
    }

    /**
     * Counts one transport failure (connect, TLS, DNS, read timeout). The third consecutive one opens the
     * breaker for five minutes; a failure after it closed opens it again.
     * 2026-10-20 23:16 +03: read timeout added to the wording (ruling R20), task 596.
     *
     * @return array{failures: int, open_until: int, open: bool} The breaker after this failure
     */
    public function recordTransportFailure(): array
    {
        $state = $this->readState();
        $state['failures']++;
        if ($state['failures'] >= self::BREAKER_FAILURES) {
            $state['open_until'] = $this->now() + self::BREAKER_SECONDS;
        }
        $this->writeState($state);

        return $this->breaker();
    }

    /** A request reached the gateway: the consecutive count restarts and the breaker closes. */
    public function recordTransportSuccess(): void
    {
        $state = $this->readState();
        $state['failures'] = 0;
        $state['open_until'] = 0;
        $this->writeState($state);
    }

    // ------------------------------------------------------------------ clear

    /**
     * Disconnect: forgets balance, senders, coverage, last sync, suspension and breaker. The cron
     * heartbeat (not account data) and the API slot (ApiGuard) stay.
     * 2026-10-21 00:05 +03: also the balance refresh stamp (account data); the daily cap resume time is store
     * data and stays, Task 1.6, task 596.
     */
    public function clear(): void
    {
        $keys = [
            self::KEY_BALANCE,
            self::KEY_SENDERIDS,
            self::KEY_COVERAGE,
            self::KEY_LAST_SYNC,
            self::KEY_GATEWAY_STATE,
            self::KEY_BALANCE_REFRESH,
            // 2026-10-21 00:52 +03: the sync schedule, the probe time and the ban belong to the account, Task 1.7,
            // task 596.
            self::KEY_SYNC_STATE,
            self::KEY_SENDER_BAN,
        ];
        foreach ($keys as $key) {
            $this->store->delete($key);
        }
    }

    // ------------------------------------------------------------------ internals

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }

    /**
     * Runs a read-modify-write of kwtsms_gateway_state under the API lock. When the lock is not available the
     * write still happens without it: a lost suspension would let every send hit the same account error
     * again, which costs more than a breaker count that a parallel write may overwrite.
     * 2026-10-21 00:05 +03: added, Task 1.6 carry, task 596.
     */
    private function writeLocked(callable $write): void
    {
        if ($this->locker !== null && call_user_func($this->locker, $write) === true) {
            return;
        }
        $write();
    }

    /**
     * @return array<mixed>|null
     */
    private function decode(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Decodes a JSON list; an object (string keys) or anything else is no list.
     *
     * @return array<int, mixed>|null
     */
    private function decodeList(?string $raw): ?array
    {
        $data = $this->decode($raw);

        if ($data === null || ($data !== [] && array_keys($data) !== range(0, count($data) - 1))) {
            return null;
        }

        return $data;
    }

    /**
     * @param mixed $value
     */
    private function isFiniteNumber($value): bool
    {
        return (is_int($value) || is_float($value) || is_string($value))
            && is_numeric($value)
            && is_finite((float) $value);
    }

    /**
     * Keeps the string/int entries that match $pattern, in order, without duplicates.
     *
     * @param array<mixed>|null $items
     *
     * @return string[]
     */
    private static function cleanList(?array $items, string $pattern): array
    {
        // 2026-10-21 01:05 +03: static, so validSenderIds() and validPrefixes() can use it (Task 1.7, task 596).
        $clean = [];
        foreach ((array) $items as $item) {
            if (!is_string($item) && !is_int($item)) {
                continue;
            }
            $item = (string) $item;
            if (preg_match($pattern, $item) === 1 && !in_array($item, $clean, true)) {
                $clean[] = $item;
            }
        }

        return $clean;
    }

    private function readTime(string $key): ?int
    {
        $raw = $this->store->get($key);

        return ($raw !== null && ctype_digit($raw)) ? (int) $raw : null;
    }

    /**
     * Suspension and breaker are read from the backing store every time: another request or the cron
     * may have changed them since this object was created.
     *
     * @return array{suspended_code: string|null, suspended_at: int, failures: int, open_until: int}
     */
    private function readState(): array
    {
        $data = $this->decode($this->store->getFresh(self::KEY_GATEWAY_STATE)) ?? [];
        $code = isset($data['suspended_code']) && is_string($data['suspended_code']) && $data['suspended_code'] !== ''
            ? $data['suspended_code']
            : null;

        return [
            'suspended_code' => $code,
            'suspended_at' => $this->intField($data, 'suspended_at'),
            'failures' => $this->intField($data, 'failures'),
            'open_until' => $this->intField($data, 'open_until'),
        ];
    }

    /**
     * @param array<mixed> $data
     */
    private function intField(array $data, string $field): int
    {
        $value = $data[$field] ?? null;

        return (is_int($value) || (is_string($value) && ctype_digit($value))) ? max(0, (int) $value) : 0;
    }

    /**
     * kwtsms_sync_state, read from the backing store every time (the cron and web requests both change it).
     * 2026-10-21 00:52 +03: added, Task 1.7, task 596.
     *
     * @return array{fail: int, retry_at: int, cov_req: int, cov_last: int, probe_at: int, probe_verdict: string,
     *               probe_code: string|null}
     */
    private function readSyncState(): array
    {
        $data = $this->decode($this->store->getFresh(self::KEY_SYNC_STATE)) ?? [];
        $verdict = isset($data['probe_verdict']) && is_string($data['probe_verdict'])
            && preg_match('/^[A-Za-z0-9_]{1,32}$/D', $data['probe_verdict']) === 1
            ? $data['probe_verdict']
            : 'unknown';
        $code = isset($data['probe_code']) && is_string($data['probe_code'])
            && preg_match('/^[A-Z0-9_]{1,32}$/D', $data['probe_code']) === 1
            ? $data['probe_code']
            : null;

        return [
            'fail' => $this->intField($data, 'fail'),
            'retry_at' => $this->intField($data, 'retry_at'),
            'cov_req' => $this->intField($data, 'cov_req'),
            'cov_last' => $this->intField($data, 'cov_last'),
            'probe_at' => $this->intField($data, 'probe_at'),
            'probe_verdict' => $verdict,
            'probe_code' => $code,
        ];
    }

    /**
     * @param array<string, int|string|null> $state
     */
    private function writeSyncState(array $state): void
    {
        $this->store->set(self::KEY_SYNC_STATE, (string) json_encode($state));
    }

    /**
     * @param array{suspended_code: string|null, suspended_at: int, failures: int, open_until: int} $state
     */
    private function writeState(array $state): void
    {
        if ($state['suspended_code'] === null && $state['failures'] === 0 && $state['open_until'] === 0) {
            $this->store->delete(self::KEY_GATEWAY_STATE);

            return;
        }
        $this->store->set(self::KEY_GATEWAY_STATE, (string) json_encode($state));
    }
}
