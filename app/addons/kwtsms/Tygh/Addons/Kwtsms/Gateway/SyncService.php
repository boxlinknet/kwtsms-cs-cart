<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Gateway/SyncService.php
 * CREATED:   2026-10-21 01:08 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Everything between the add-on and the kwtSMS account that is not a send: login (PRD 6.1), logout, the
 *            sync of balance, Sender IDs and coverage, when a sync is due, the retry of a suspended gateway, and
 *            the coverage resync that ERR026 asks for.
 * WHY:       Task 1.7. The Settings page (1.10) and the dashboard (1.11) call login(), logout(), sync(),
 *            syncIfStale(), refreshBalanceIfAllowed() and retrySuspension(); the cron (kwtsms_cron.run) calls
 *            isSyncDue(), sync(), resyncCoverage() and retrySuspension(). One class holds the rules so they are
 *            written once: a login saves nothing unless /balance/ is OK, a failed sync keeps the cache and retries
 *            after 15 min / 1 h / 6 h, and synced data is validated before it is stored (PRD 10).
 * USAGE:     $sync = Tygh::$app['kwtsms.sync'];
 *            $r = $sync->login($user, $pass);     // ['ok'=>bool, 'code'=>?string, 'message'=>string,
 *                                                 //  'warnings'=>string[], 'sync'=>array (only after a good login)]
 *            $sync->logout();                     // clears credentials, sender, the cached state; turns SMS off
 *            $r = $sync->sync();                  // ['ok','skipped','code','message','balance','senderids',
 *                                                 //  'coverage','retry_at']; keeps the cache on failure
 *            $sync->syncIfStale(86400);           // true when a sync ran and succeeded (the dashboard fallback)
 *            $sync->isSyncDue();                  // the cron's question: once a day at a slot derived from cron_key,
 *                                                 // or when a failed sync's retry time has come
 *            $sync->resyncCoverage();             // only /coverage/ (ERR026 asked for it, at most once an hour)
 *            $sync->retrySuspension($now);        // ['state'=>not_suspended|throttled|resumed|still_suspended|
 *                                                 //  unreachable, 'code'=>?string]
 *            $sync->refreshBalanceIfAllowed();    // ?float, at most once in 10 minutes (true forces it)
 *            $sync->probe();                      // ['verdict' => 'ok' | 'ERR003' | ... | 'failed', 'ok' => bool]
 *            Ruling R25: the /balance/ refresh, the ERR003 probe and the suspension on account-level codes live in
 *            Sms\SmsService (refreshBalance(), probeCredentials(), with the 15-minute probe throttle); this class
 *            delegates to them and has no second implementation. Its own /balance/ call is the login's, made with
 *            the candidate credentials before anything is saved.
 *            Timeout context of the calls: cli in a CLI process, otherwise admin (an administrator is waiting).
 *            Secrets: login() registers the candidate username and password, raw and trimmed, with the debug log
 *            before any line is written; nothing here logs a credential.
 * DEPENDS:   Settings\ConfigInterface, Gateway\GatewayState, Sms\{ApiClient,ApiErrors,SmsService}, Log\DebugLog,
 *            Alerts\AdminAlerts. A client factory (string $timeout_context): ApiClient built on the saved
 *            credentials, and a candidate factory (string $user, string $pass, string $timeout_context): ApiClient;
 *            every client of one request must share one ApiGuard (ServiceProvider).
 */

namespace Tygh\Addons\Kwtsms\Gateway;

use Tygh\Addons\Kwtsms\Alerts\AdminAlerts;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\ApiClient;
use Tygh\Addons\Kwtsms\Sms\ApiErrors;
use Tygh\Addons\Kwtsms\Sms\SmsService;

class SyncService
{
    /** A suspended gateway is probed at most this often (PRD 6.1) ... */
    public const RETRY_SECONDS = 900;

    /** ... and ERR005 (account blocked) hourly (Appendix A). */
    public const RETRY_SECONDS_BLOCKED = 3600;

    /** One sync a day (PRD 6.11). */
    public const DAY_SECONDS = 86400;

    /** @var ConfigInterface */
    private $config;

    /** @var GatewayState */
    private $state;

    /** @var callable (string $timeout_context): ApiClient */
    private $client_factory;

    /** @var callable (string $user, string $pass, string $timeout_context): ApiClient */
    private $candidate_factory;

    /** @var DebugLog */
    private $log;

    /** @var SmsService */
    private $sms;

    /** @var AdminAlerts|null */
    private $alerts;

    /** @var callable|null */
    private $clock;

    /**
     * @param callable        $client_factory    (string $timeout_context): ApiClient on the saved credentials
     * @param callable        $candidate_factory (string $user, string $pass, string $timeout_context): ApiClient
     * @param callable|null   $clock             Returns the current Unix time (tests); null = time()
     */
    public function __construct(
        ConfigInterface $config,
        GatewayState $state,
        callable $client_factory,
        callable $candidate_factory,
        DebugLog $log,
        SmsService $sms,
        ?AdminAlerts $alerts = null,
        ?callable $clock = null
    ) {
        $this->config = $config;
        $this->state = $state;
        $this->client_factory = $client_factory;
        $this->candidate_factory = $candidate_factory;
        $this->log = $log;
        $this->sms = $sms;
        $this->alerts = $alerts;
        $this->clock = $clock;
    }

    // ================================================================== login and logout

    /**
     * PRD 6.1 login: one /balance/ with the candidate pair. Only when it is OK are the username and the encrypted
     * password saved, the balance cached, and the Sender IDs and coverage fetched. Anything else saves nothing and
     * returns the text of that error code (Appendix A).
     *
     * @return array{ok: bool, code: string|null, message: string, warnings: string[], sync?: array<string,mixed>}
     */
    public function login(
        string $username,
        #[\SensitiveParameter]
        string $password
    ): array {
        // The candidate pair is registered, raw and trimmed, before the first log line (Task 1.5 carry).
        foreach ([$username, trim($username), $password, trim($password)] as $secret) {
            $this->log->addSecret($secret);
        }
        $username = trim($username);
        $password = trim($password);
        $started = microtime(true);
        $this->note('debug', 'sync.login.enter', 'login()', ['username_length' => strlen($username)]);

        $warnings = $this->loginWarnings($username, $password);
        if ($username === '' || $password === '') {
            return $this->loginResult(false, 'LOCAL_BAD_INPUT', (string) __('kwtsms.login.empty'), $warnings, $started);
        }

        $client = call_user_func($this->candidate_factory, $username, $password, $this->context());
        $balance = $client->balance();
        if ($balance['result'] !== 'OK') {
            $code = (string) $balance['code'];
            $this->note('warning', 'sync.login_failed', $code, ['code' => $code]);

            return $this->loginResult(false, $code, $this->loginMessage($code), $warnings, $started);
        }

        $previous = $this->config->apiUsername();
        $rejected = $this->config->save('login', ['api_username' => $username, 'api_password' => $password]);
        if ($rejected !== []) {
            $this->note('error', 'sync.login_not_saved', 'credentials refused by the settings', [
                'keys' => $rejected,
            ]);

            return $this->loginResult(
                false,
                'LOCAL_SAVE',
                (string) __('kwtsms.login.save_failed'),
                $warnings,
                $started
            );
        }
        // Change account (Task 1.5 carry): the old account's balance, senders, coverage and flags must not
        // survive next to the new login.
        $changed = $previous !== $username;
        if ($changed) {
            $this->state->clear();
        }
        $this->cacheBalance($balance);
        $this->state->recordProbe('ok', null);
        $this->state->resume();
        $summary = $this->syncLists($client, $started);

        $this->note('info', 'sync.login', 'logged in', [
            'account_changed' => $changed,
            'senders' => count($this->state->senderIds()),
            'coverage' => count((array) $this->state->coverage()),
            'sync_ok' => $summary['ok'],
        ]);
        $result = $this->loginResult(true, null, (string) __('kwtsms.login.ok'), $warnings, $started);
        $result['sync'] = $summary;

        return $result;
    }

    /**
     * Disconnect (PRD 6.1): credentials, the selected sender and the cached account state are cleared and the
     * global switch is turned off. The cron heartbeat stays.
     *
     * @throws \RuntimeException When the settings could not be cleared (the cached state is cleared anyway)
     */
    public function logout(): void
    {
        $this->note('debug', 'sync.logout.enter', 'logout()');
        $rejected = $this->config->save('logout', [
            'api_username' => '',
            'api_password' => '',
            'sender_id' => '',
            'enabled' => 'N',
        ]);
        $this->state->clear();
        $this->note('info', 'sync.logout', 'disconnected', ['rejected' => $rejected]);
        if ($rejected !== []) {
            throw new \RuntimeException('Disconnect could not clear these settings: ' . implode(', ', $rejected));
        }
    }

    // ================================================================== sync

    /**
     * Fetches balance, Sender IDs and coverage with the saved credentials. A failure keeps what is cached, logs a
     * warning and schedules the next try 15 min, 1 h, then 6 h later (PRD 6.1).
     *
     * @param string $timeout_context web, admin or cli; '' = cli in a CLI process, else admin
     *
     * @return array{ok: bool, skipped: string|null, code: string|null, message: string, balance: bool,
     *               senderids: bool, coverage: bool, retry_at: int|null}
     */
    public function sync(string $timeout_context = ''): array
    {
        $this->note('debug', 'sync.enter', 'sync()');
        if ($this->config->credentialsState() !== ConfigInterface::CREDENTIALS_OK) {
            return $this->summary(['skipped' => 'no_credentials']);
        }
        $context = $this->context($timeout_context);
        $started = microtime(true);

        // Ruling R25: the /balance/ call, and the suspension on an account-level code, are SmsService's.
        if ($this->sms->refreshBalance($context) === null) {
            $code = $this->state->suspendedCode();
            $this->note('warning', 'sync.balance_failed', (string) $code, ['code' => $code]);

            return $this->finishFailedSync(
                ['balance' => false, 'senderids' => false, 'coverage' => false],
                $code,
                $started
            );
        }

        // /balance/ answered OK: the credentials work and the account can call the API, so a suspension on an
        // account-level code (written by a /send/ or an earlier probe) no longer holds.
        $was = $this->state->suspendedCode();
        if ($was !== null) {
            $this->state->resume();
            $this->note('info', 'sync.resumed', 'a sync reached the account', ['was' => $was]);
        }

        return $this->syncLists(call_user_func($this->client_factory, $context), $started);
    }

    /**
     * The dashboard fallback (PRD 6.11): syncs when the last complete sync is older than $max_age, or when a failed
     * sync's retry time has come. Does nothing while a retry is scheduled in the future.
     *
     * @return bool True when a sync ran and succeeded
     */
    public function syncIfStale(int $max_age = self::DAY_SECONDS): bool
    {
        if ($this->config->credentialsState() !== ConfigInterface::CREDENTIALS_OK) {
            return false;
        }
        $retry = $this->state->syncRetryAt();
        if ($retry !== null) {
            if ($retry > $this->now()) {
                return false;
            }
        } else {
            $last = $this->state->lastSync();
            if ($last !== null && $this->now() - $last < max(1, $max_age)) {
                return false;
            }
        }

        return $this->sync()['ok'];
    }

    /**
     * Whether the cron should run a full sync now: credentials saved and either a failed sync's retry time has come,
     * or no sync has happened since the latest daily slot of this install.
     */
    public function isSyncDue(): bool
    {
        if ($this->config->credentialsState() !== ConfigInterface::CREDENTIALS_OK) {
            return false;
        }
        $retry = $this->state->syncRetryAt();
        if ($retry !== null) {
            return $retry <= $this->now();
        }
        $last = $this->state->lastSync();
        if ($last === null) {
            return true;
        }
        $now = $this->now();
        $slot = $now - (($now - $this->dailySlotOffset()) % self::DAY_SECONDS + self::DAY_SECONDS) % self::DAY_SECONDS;

        return $last < $slot;
    }

    /**
     * Seconds after UTC midnight at which this install syncs, 0 to 86399, derived from its cron key so that stores
     * do not all call kwtSMS in the same minute (PRD 6.11 "jitter derived from the install").
     */
    public function dailySlotOffset(): int
    {
        return hexdec(substr(md5($this->config->cronKey() . '|kwtsms-sync'), 0, 8)) % self::DAY_SECONDS;
    }

    /**
     * Only /coverage/ (ERR026, PRD Appendix A). The request is cleared and the hour starts whatever the outcome,
     * so a gateway that is down is not asked every minute.
     *
     * @return array{ok: bool, code: string|null}
     */
    public function resyncCoverage(string $timeout_context = ''): array
    {
        $this->note('debug', 'sync.coverage.enter', 'resyncCoverage()');
        if ($this->config->credentialsState() !== ConfigInterface::CREDENTIALS_OK) {
            $this->state->touchCoverageResync();

            return ['ok' => false, 'code' => null];
        }
        $client = call_user_func($this->client_factory, $this->context($timeout_context));
        $out = $this->coverageStep($client);
        $this->state->touchCoverageResync();
        $this->note(
            $out['ok'] ? 'info' : 'warning',
            'sync.coverage_resync',
            $out['ok'] ? 'ok' : (string) $out['code'],
            []
        );

        return ['ok' => $out['ok'], 'code' => $out['code']];
    }

    // ================================================================== suspension

    /**
     * PRD 6.1: a suspended gateway is probed with /balance/ at most every 15 minutes (ERR005: hourly) or at once on
     * "Retry now". An OK answer lifts the suspension; an account-level code keeps it, and SmsService replaces the
     * stored code when it differs; any other answer, or none, keeps it as it is.
     *
     * @param bool $now True for "Retry now": no waiting
     *
     * @return array{state: string, code: string|null, next_at?: int}
     */
    public function retrySuspension(bool $now = false, string $timeout_context = ''): array
    {
        $this->note('debug', 'sync.retry.enter', 'retrySuspension()', ['now' => $now]);
        $code = $this->state->suspendedCode();
        if ($code === null) {
            return ['state' => 'not_suspended', 'code' => null];
        }
        $interval = $code === 'ERR005' ? self::RETRY_SECONDS_BLOCKED : self::RETRY_SECONDS;
        $probe = $this->state->lastProbe();
        $since = max(
            (int) $this->state->suspendedAt(),
            $probe === null ? 0 : $probe['at'],
            (int) $this->state->lastBalanceRefresh()
        );
        if (!$now && $this->now() - $since < $interval) {
            return ['state' => 'throttled', 'code' => $code, 'next_at' => $since + $interval];
        }
        if ($this->config->credentialsState() !== ConfigInterface::CREDENTIALS_OK) {
            return ['state' => 'still_suspended', 'code' => $code];
        }

        // Ruling R25: the probe is SmsService's. Forced, it skips its own 15-minute throttle (this method keeps the
        // cadence of a suspended gateway above); OK caches the balance, lifts the suspension and records the
        // verdict; an account-level code suspends again, with the new code.
        $verdict = $this->sms->probeCredentials($this->context($timeout_context), true);
        if ($verdict === 'ok') {
            $this->note('info', 'sync.resumed', 'the gateway answers again', ['was' => $code]);

            return ['state' => 'resumed', 'code' => null];
        }
        $code = $this->state->suspendedCode() ?? $code;
        $this->note('info', 'sync.still_suspended', $code, ['probe' => $verdict]);

        return ['state' => 'still_suspended', 'code' => $code];
    }

    // ================================================================== balance and probe (SmsService's)

    /**
     * The dashboard's "Refresh balance" (PRD 6.1): a live /balance/ at most once in 10 minutes, shared with the
     * send pipeline's own refresh. Ruling R25: SmsService does the call.
     *
     * @param bool $force True for the button: no waiting
     *
     * @return float|null The available balance, or null when it was not refreshed
     */
    public function refreshBalanceIfAllowed(bool $force = false, string $timeout_context = ''): ?float
    {
        $this->note('debug', 'sync.refresh.enter', 'refreshBalanceIfAllowed()', ['force' => $force]);
        if ($this->config->credentialsState() !== ConfigInterface::CREDENTIALS_OK) {
            return null;
        }
        if (!$force && !$this->sms->balanceRefreshAllowed()) {
            return null;
        }

        return $this->sms->refreshBalance($this->context($timeout_context));
    }

    /**
     * The credentials probe (PRD 6.1), at most one every 15 minutes across requests. Ruling R25: SmsService decides
     * and keeps the throttle; a suspension it finds is its own to write.
     *
     * @return array{verdict: string, ok: bool} verdict is "ok", the error code (ERR003, ERR005, ...) or "failed"
     */
    public function probe(string $timeout_context = ''): array
    {
        $this->note('debug', 'sync.probe.enter', 'probe()');
        if ($this->config->credentialsState() !== ConfigInterface::CREDENTIALS_OK) {
            return ['verdict' => 'failed', 'ok' => false];
        }
        $verdict = $this->sms->probeCredentials($this->context($timeout_context));

        return ['verdict' => $verdict, 'ok' => $verdict === 'ok'];
    }

    // ================================================================== internals

    /**
     * The Sender ID and coverage calls, and the bookkeeping that follows. The balance is already cached (login, or
     * sync() through SmsService).
     *
     * @return array{ok: bool, skipped: string|null, code: string|null, message: string, balance: bool,
     *               senderids: bool, coverage: bool, retry_at: int|null}
     */
    private function syncLists(ApiClient $client, float $started): array
    {
        $out = ['balance' => true, 'senderids' => false, 'coverage' => false];

        $senders = $this->sendersStep($client);
        $out['senderids'] = $senders['ok'];
        $code = $senders['code'];

        $coverage = $this->coverageStep($client);
        $out['coverage'] = $coverage['ok'];
        $none = $coverage['none'];
        $code = ($coverage['ok'] || $none) ? $code : ($code ?? $coverage['code']);

        if ($out['senderids'] && ($out['coverage'] || $none)) {
            $this->state->touchSync();
            $this->state->recordSyncSuccess();
            $this->state->touchCoverageResync();
            $this->note('info', 'sync.done', 'synced', [
                'senders' => count($this->state->senderIds()),
                'coverage' => count((array) $this->state->coverage()),
                'coverage_none' => $none,
            ], (int) round((microtime(true) - $started) * 1000));

            return $this->summary($out + ['ok' => true]);
        }

        return $this->finishFailedSync($out, $code, $started);
    }

    /**
     * @param array<string,bool> $out
     *
     * @return array<string,mixed>
     */
    private function finishFailedSync(array $out, ?string $code, float $started): array
    {
        $retry_at = $this->state->recordSyncFailure();
        $this->note('warning', 'sync.failed', (string) $code, [
            'code' => $code,
            'balance' => $out['balance'],
            'senderids' => $out['senderids'],
            'coverage' => $out['coverage'],
            'retry_at' => $retry_at,
            'failures' => $this->state->syncFailures(),
        ], (int) round((microtime(true) - $started) * 1000));

        return $this->summary($out + ['ok' => false, 'code' => $code, 'retry_at' => $retry_at]);
    }

    /**
     * /senderid/: the list is validated before it is stored; a reply with entries of which none is valid keeps the
     * old cache and fails. A selected Sender ID that is no longer in the list raises sender_removed.
     *
     * @return array{ok: bool, code: string|null}
     */
    private function sendersStep(ApiClient $client): array
    {
        $r = $client->senderIds();
        if ($r['result'] !== 'OK') {
            $code = (string) $r['code'];
            $this->note('warning', 'sync.senderid_failed', $code, ['code' => $code]);

            return ['ok' => false, 'code' => $code];
        }
        $listed = (array) $r['senderids'];
        $valid = GatewayState::validSenderIds($listed);
        if ($listed !== [] && $valid === []) {
            $this->note('warning', 'sync.senderid_invalid', 'no valid Sender ID in the reply', [
                'entries' => count($listed),
            ]);

            return ['ok' => false, 'code' => 'LOCAL_BAD_DATA'];
        }
        if (count($valid) < count($listed)) {
            $this->note('warning', 'sync.senderid_dropped', 'invalid or duplicate entries dropped', [
                'dropped' => count($listed) - count($valid),
            ]);
        }
        $this->state->setSenderIds($valid);

        $selected = $this->config->senderId();
        if ($selected !== '' && !in_array($selected, $valid, true)) {
            $this->note('warning', 'sync.sender_removed', 'the selected Sender ID is no longer on the account', [
                'sender' => $selected,
            ]);
            $this->alert('sender_removed', ['[sender]' => $selected], true, 86400, $selected);
        }

        return ['ok' => true, 'code' => null];
    }

    /**
     * /coverage/: validated; ERR033 (no active coverage) keeps the old cache and is not a failure.
     *
     * @return array{ok: bool, none: bool, code: string|null}
     */
    private function coverageStep(ApiClient $client): array
    {
        $r = $client->coverage();
        if ($r['result'] !== 'OK') {
            $code = (string) $r['code'];
            if ($code === 'ERR033') {
                $this->note('warning', 'sync.coverage_none', 'kwtSMS reports no active coverage; cache kept', []);

                return ['ok' => false, 'none' => true, 'code' => $code];
            }
            $this->note('warning', 'sync.coverage_failed', $code, ['code' => $code]);

            return ['ok' => false, 'none' => false, 'code' => $code];
        }
        $listed = (array) $r['prefixes'];
        $valid = GatewayState::validPrefixes($listed);
        if ($listed !== [] && $valid === []) {
            $this->note('warning', 'sync.coverage_invalid', 'no valid prefix in the reply', [
                'entries' => count($listed),
            ]);

            return ['ok' => false, 'none' => false, 'code' => 'LOCAL_BAD_DATA'];
        }
        $this->state->setCoverage($valid);

        return ['ok' => true, 'none' => false, 'code' => null];
    }

    /**
     * @param array<string,mixed> $reply An OK /balance/ reply
     */
    private function cacheBalance(array $reply): void
    {
        $this->state->setBalance(
            (float) $reply['available'],
            $reply['purchased'] === null ? null : (float) $reply['purchased']
        );
    }

    /**
     * @param array<string,mixed> $parts
     *
     * @return array{ok: bool, skipped: string|null, code: string|null, message: string, balance: bool,
     *               senderids: bool, coverage: bool, retry_at: int|null}
     */
    private function summary(array $parts): array
    {
        $parts += [
            'ok' => false,
            'skipped' => null,
            'code' => null,
            'balance' => false,
            'senderids' => false,
            'coverage' => false,
            'retry_at' => null,
        ];
        $parts['message'] = $parts['ok']
            ? ''
            : ($parts['code'] !== null ? ApiErrors::describe((string) $parts['code']) : '');

        return [
            'ok' => (bool) $parts['ok'],
            'skipped' => $parts['skipped'],
            'code' => $parts['code'],
            'message' => $parts['message'],
            'balance' => (bool) $parts['balance'],
            'senderids' => (bool) $parts['senderids'],
            'coverage' => (bool) $parts['coverage'],
            'retry_at' => $parts['retry_at'],
        ];
    }

    /**
     * @param string[] $warnings
     *
     * @return array{ok: bool, code: string|null, message: string, warnings: string[]}
     */
    private function loginResult(bool $ok, ?string $code, string $message, array $warnings, float $started): array
    {
        $ms = (int) round((microtime(true) - $started) * 1000);
        $this->note('debug', 'sync.login.exit', $ok ? 'ok' : (string) $code, [], $ms);

        return ['ok' => $ok, 'code' => $code, 'message' => $message, 'warnings' => $warnings];
    }

    /**
     * PRD 6.1: a gateway code gets its own text; a failure that never reached kwtSMS says so in one sentence.
     */
    private function loginMessage(string $code): string
    {
        return ApiErrors::source($code) === 'local' && $code !== 'LOCAL_CANCELLED'
            ? (string) __('kwtsms.login.unreachable')
            : ApiErrors::describe($code);
    }

    /**
     * @return string[]
     */
    private function loginWarnings(string $username, string $password): array
    {
        $warnings = [];
        if (strlen($username) > 15) {
            $warnings[] = (string) __('kwtsms.login.warn_username_long');
        }
        if (strlen($password) > 30) {
            $warnings[] = (string) __('kwtsms.login.warn_password_long');
        }
        if ($username !== '' && ctype_digit($username)) {
            $warnings[] = (string) __('kwtsms.login.warn_username_digits');
        }

        return $warnings;
    }

    private function context(string $requested = ''): string
    {
        if (in_array($requested, ['web', 'admin', 'cli'], true)) {
            return $requested;
        }

        return PHP_SAPI === 'cli' ? 'cli' : 'admin';
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }

    /**
     * @param array<string,scalar> $params
     */
    private function alert(string $condition, array $params, bool $email, int $every, string $instance = ''): void
    {
        if ($this->alerts !== null) {
            $this->alerts->raise($condition, $params, $email, $every, $instance);
        }
    }

    /**
     * @param array<mixed> $context
     */
    private function note(string $level, string $action, string $message, array $context = [], ?int $ms = null): void
    {
        $this->log->log($level, $action, $message, $context, $ms);
    }
}
