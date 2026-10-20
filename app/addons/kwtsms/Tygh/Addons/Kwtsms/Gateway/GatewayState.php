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

    /**
     * @param KeyValueStoreInterface $store
     * @param callable|null          $clock Returns the current Unix time (tests); null = time()
     */
    public function __construct(KeyValueStoreInterface $store, ?callable $clock = null)
    {
        $this->store = $store;
        $this->clock = $clock;
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
        $state = $this->readState();
        $state['suspended_code'] = $clean === '' ? 'UNKNOWN' : $clean;
        $state['suspended_at'] = $this->now();
        $this->writeState($state);
    }

    /** Lifts the suspension. The breaker is left alone. */
    public function resume(): void
    {
        $state = $this->readState();
        $state['suspended_code'] = null;
        $state['suspended_at'] = 0;
        $this->writeState($state);
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
     */
    public function clear(): void
    {
        $keys = [
            self::KEY_BALANCE,
            self::KEY_SENDERIDS,
            self::KEY_COVERAGE,
            self::KEY_LAST_SYNC,
            self::KEY_GATEWAY_STATE,
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
    private function cleanList(?array $items, string $pattern): array
    {
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
