<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Sms/SmsService.php
 * CREATED:   2026-10-21 00:11 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The single send pipeline of the add-on, send(), PRD 6.2 steps 1-16 in their exact order, with the
 *            private bulkSend() (the brief's _bulksend(), D10) that splits into API calls of at most 200 numbers
 *            and 2500 characters. Every recipient ends with one canonical outcome (Appendix B) and one attempt
 *            row; every API call that was attempted gets one message row.
 * WHY:       Order events, the test SMS form, resend, the outbox drain and fn_kwtsms_send() must all pass the
 *            same gates (switch, configured, suspension, abuse caps, numbers, countries, coverage, cleaning,
 *            length, balance, pilot, quiet hours, request budget) and map every kwtSMS and local error the same
 *            way (Appendix A). An SMS failure never breaks the caller: send() never throws.
 * USAGE:     $sms = Tygh::$app['kwtsms.sms'];
 *            $result = $sms->send(['+965 5012 3401'], $text, [
 *                'source' => 'event', 'event_id' => 'order.status_changed.p', 'receiver' => 'C',
 *                'order_id' => 12, 'country_iso' => 'KW', 'lang_code' => 'en', 'audience' => 'storefront',
 *                'interactive' => false, 'secret_values' => [],
 *            ]);   // SendResult
 *            Context keys (anything else is dropped): source (event, manual, api, cron, otp; default manual),
 *            event_id, receiver (C customer, A admin, V vendor), order_id, parent_order_id, company_id,
 *            storefront_id, user_id, actor_user_id, actor_company_id, lang_code, audience (admin | storefront,
 *            default storefront), interactive (bool), ip, secret_values (masked in every log copy, D7),
 *            country_iso (ISO2 of the order country: the phone hint, else default_country_code), caller (the
 *            add-on behind fn_kwtsms_send), retry_of. (outbox_id is ignored when a caller passes it: only drainDue()
 *            sets it, see the 2026-10-21 01:04 +03 note below.)
 *            Pipeline: 1 hook kwtsms_send_pre (may edit or cancel -> cancelled); 2 enabled -> blocked_disabled;
 *            3 configured -> blocked_not_configured, suspended -> deferred/suspended; 4 abuse caps (per number:
 *            customer receiver and manual/api sources 5 per hour and 20 per day, api counted in its own bucket;
 *            admin receiver 300 per day; store-wide daily cap from local midnight or "Resume now") ->
 *            rate_limited / blocked_daily_cap, and a failed count -> deferred/cap_unavailable; 5 normalize and
 *            verify -> invalid / no_phone; 6 dedupe -> duplicate; 7 allowed customer countries -> blocked_country,
 *            coverage -> no_coverage (coverage unknown: sent, attempt reason coverage_unknown); 8 ONE full
 *            MessageCleaner::clean() of the final body -> blocked_empty, then TemplateRenderer::hasLeftover() on
 *            the cleaned body -> blocked_placeholder (a value such as "&#123;x&#125;" becomes "{x}" only here),
 *            then MessageCleaner::hasHtmlLikeSpan() -> rejected / LOCAL_HTML (R13); 9 pages of that body over
 *            max_pages_notification (notifications) or 7 -> blocked_too_long; 10 pages x recipients against the
 *            cached balance, one live /balance/ at most every 10 min -> blocked_balance; 11 pilot mode ->
 *            blocked_pilot, quiet hours -> deferred/quiet_hours; 12 more than 20 API calls in one web request ->
 *            deferred/request_overflow; 13 bulkSend(); 14 message and attempt rows; 15 balance from
 *            balance-after, low-balance crossing alert; 16 hook kwtsms_send_post, SendResult.
 *            Outcome of an API call (Appendix A, task 596 rulings): OK -> test_queued (test mode) or accepted,
 *            partial_accept when fewer numbers were accepted than sent; ERR001/004/005/024/034 -> suspend + alert,
 *            deferred/suspended; ERR003 -> one /balance/ probe per request: OK -> rejected (content), ERR003 ->
 *            suspended, else failed; ERR006/025 -> invalid; ERR010 -> balance cached 0, blocked_balance;
 *            ERR013 -> deferred/queue_full; ERR026 -> no_coverage; ERR009/012/027/036/031/032 -> rejected
 *            (cleaner gaps logged as body hex); ERR002/007/008/011/033/035 and unknown codes -> failed (server
 *            text kept); LOCAL_TIMEOUT, and LOCAL_HTTP / LOCAL_BAD_JSON of a request that may have arrived ->
 *            unknown, never retried; LOCAL_CONNECT/DNS/BREAKER/DEFERRED/BUSY -> deferred/gateway_unreachable;
 *            LOCAL_TLS -> deferred/gateway_unreachable, outbox row held (no automatic retry) and a notice;
 *            LOCAL_CANCELLED -> cancelled; other LOCAL_* -> failed; LOCAL_SKIPPED (never sent) follows the code
 *            that stopped the send and is never unknown (deferred/gateway_unreachable instead).
 *            Outbox back-off: 30, 60, 120, then 300 s; suspended 300 s; request_overflow at once; quiet hours at
 *            the window end; rows expire 30 min after they were queued (quiet hours: after the window end).
 *            Test seams (protected): hookSendPre(), hookSendPost(), isWebRequest().
 *            2026-10-21 01:04 +03, Task 1.6 fix round 1 (review: a stale drain snapshot resent 201 numbers), task 596:
 *            the outbox is drained ONLY by drainDue($limit, $time_budget_s) (Task 1.7's cron and the dashboard
 *            fallback): it reaps stuck rows, claims each due row on (status, retry_at, attempts) and sends what the
 *            CLAIMED row holds; send() ignores any outbox_id a caller passes. A drained row skips kwtsms_send_pre
 *            (it ran on the first attempt, on the text the row stores) and uses the test flag stored at enqueue:
 *            a test row is never sent live, a live row drained in test mode goes out as test (ruling R26).
 *            refreshBalance() and probeCredentials() are public, the single implementation SyncService delegates to
 *            (ruling R25): account-level codes on /balance/ suspend, and the ERR003 probe runs at most once per
 *            15 minutes across requests (GatewayState::lastProbe()). The caps read every number of a send in one
 *            grouped query and the attempt rows are written in one INSERT (AttemptLog::countsByPhone(),
 *            recordMany()); the admin cap (300/day) applies only to numbers in admin_phones; the auto
 *            low-balance threshold is computed once a day.
 * DEPENDS:   Settings\ConfigInterface, Gateway\GatewayState, Sms\{ApiClient,ApiErrors,PhoneNormalizer,
 *            MessageCleaner,SendResult}, Templates\TemplateRenderer, Log\{MessageLog,AttemptLog,DebugLog,Redactor},
 *            Queue\Outbox, Alerts\AdminAlerts; a client factory (string $timeout_context): ApiClient whose clients
 *            share ONE ApiGuard per request (ServiceProvider).
 */

namespace Tygh\Addons\Kwtsms\Sms;

use Tygh\Addons\Kwtsms\Alerts\AdminAlerts;
use Tygh\Addons\Kwtsms\Gateway\GatewayState;
use Tygh\Addons\Kwtsms\Log\AttemptLog;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Log\MessageLog;
use Tygh\Addons\Kwtsms\Log\Redactor;
use Tygh\Addons\Kwtsms\Queue\Outbox;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Templates\TemplateRenderer;

class SmsService
{
    /** Appendix B: every outcome an attempt row can carry. */
    public const OUTCOMES = [
        'accepted', 'test_queued', 'partial_accept', 'failed', 'unknown', 'rejected', 'invalid', 'duplicate',
        'no_phone', 'no_coverage', 'blocked_country', 'blocked_disabled', 'blocked_not_configured', 'blocked_empty',
        'blocked_too_long', 'blocked_placeholder', 'blocked_balance', 'blocked_pilot', 'blocked_daily_cap',
        'rate_limited', 'cancelled', 'deferred', 'expired', 'switched_off', 'not_notified', 'superseded',
        'duplicate_event', 'no_template',
    ];

    /**
     * Reasons of a deferred outcome: Appendix B's five, plus cap_unavailable (the cap count could not be read;
     * Task 1.6 carry: never skip the cap, never abort silently).
     */
    public const DEFER_REASONS = [
        'queue_full', 'gateway_unreachable', 'suspended', 'quiet_hours', 'request_overflow', 'cap_unavailable',
    ];

    /** PRD 6.2 step 12: API calls one web request may make. */
    public const REQUEST_BUDGET = 20;

    /** PRD 6.1: at most one live balance refresh in this many seconds. */
    public const BALANCE_REFRESH_SECONDS = 600;

    /** Outcomes the abuse caps count (the gateway took or may have taken the number). */
    public const SENT_OUTCOMES = ['accepted', 'test_queued', 'partial_accept', 'unknown'];

    /** fn_kwtsms_send(): most recipients per call (PRD 6.14). */
    public const MAX_API_RECIPIENTS = 1000;

    private const SOURCES = ['event', 'manual', 'api', 'cron', 'otp'];

    /** The per-number bucket of every source that is not api (PRD 5.2: api has its own). */
    private const NON_API_SOURCES = ['event', 'manual', 'cron', 'otp'];

    private const SUSPEND_CODES = ['ERR001', 'ERR004', 'ERR005', 'ERR024', 'ERR034'];

    /** 2026-10-21 01:04 +03: the ERR003 probe runs at most once in this many seconds (ruling R25), task 596. */
    public const PROBE_SECONDS = 900;

    /** kwtSMS codes with a fixed outcome (the others are handled in code or are failed). */
    private const CODE_OUTCOMES = [
        'ERR006' => 'invalid', 'ERR025' => 'invalid', 'ERR010' => 'blocked_balance', 'ERR026' => 'no_coverage',
        'ERR009' => 'rejected', 'ERR012' => 'rejected', 'ERR027' => 'rejected', 'ERR036' => 'rejected',
        'ERR031' => 'rejected', 'ERR032' => 'rejected',
    ];

    /** Content codes the cleaner should have prevented: the body is logged as hex (Appendix A). */
    private const CLEANER_GAPS = ['ERR009', 'ERR012', 'ERR027', 'ERR036'];

    /** Codes that mean the request never reached kwtSMS. */
    private const UNREACHABLE = [
        'LOCAL_CONNECT', 'LOCAL_DNS', 'LOCAL_TLS', 'LOCAL_BREAKER', 'LOCAL_DEFERRED', 'LOCAL_BUSY',
    ];

    private const CONTEXT_DEFAULTS = [
        'source' => 'manual', 'event_id' => '', 'receiver' => '', 'order_id' => 0, 'parent_order_id' => 0,
        'company_id' => 0, 'storefront_id' => 0, 'user_id' => 0, 'actor_user_id' => 0, 'actor_company_id' => 0,
        'lang_code' => '', 'audience' => 'storefront', 'interactive' => false, 'ip' => '', 'secret_values' => [],
        'country_iso' => '', 'caller' => '', 'retry_of' => 0, 'outbox_id' => 0,
    ];

    private const INT_KEYS = [
        'order_id', 'parent_order_id', 'company_id', 'storefront_id', 'user_id', 'actor_user_id', 'actor_company_id',
        'retry_of', 'outbox_id',
    ];

    /** @var ConfigInterface */
    private $config;

    /** @var GatewayState */
    private $state;

    /** @var callable (string $timeout_context): ApiClient */
    private $client_factory;

    /** @var MessageLog */
    private $messages;

    /** @var AttemptLog */
    private $attempts;

    /** @var DebugLog */
    private $log;

    /** @var Outbox */
    private $outbox;

    /** @var AdminAlerts|null */
    private $alerts;

    /** @var callable|null */
    private $clock;

    /** @var int API calls made by this service in this request (PRD 6.2 step 12) */
    private $api_calls = 0;

    /** @var string|null Verdict of this request's ERR003 /balance/ probe: ok, ERR003 or failed */
    private $probe = null;

    /** @var ApiClient|null The client of the current send() */
    private $client = null;

    /** @var bool The test flag of the current send (2026-10-21 01:04 +03, ruling R26, task 596) */
    private $test = true;

    /** @var array<string,mixed>|null The claimed outbox row the current send drains (2026-10-21 01:04 +03, task 596) */
    private $row = null;

    /** @var array<string,array{ms:int,decided:int}> Per-stage timings of the current send() */
    private $stages = [];

    /** @var float */
    private $stage_started = 0.0;

    /**
     * @param callable $client_factory (string $timeout_context web|admin|cli): ApiClient; every client of one
     *                                 request must share one ApiGuard
     * @param callable|null $clock     Returns the current Unix time (tests); null = time()
     */
    public function __construct(
        ConfigInterface $config,
        GatewayState $state,
        callable $client_factory,
        MessageLog $messages,
        AttemptLog $attempts,
        DebugLog $log,
        Outbox $outbox,
        ?AdminAlerts $alerts = null,
        ?callable $clock = null
    ) {
        $this->config = $config;
        $this->state = $state;
        $this->client_factory = $client_factory;
        $this->messages = $messages;
        $this->attempts = $attempts;
        $this->log = $log;
        $this->outbox = $outbox;
        $this->alerts = $alerts;
        $this->clock = $clock;
    }

    /**
     * Sends one text to one or more numbers. Never throws.
     *
     * @param array<int,mixed>|string $phones  Numbers as typed (a string may list several, separated by , ; or
     *                                         line breaks); CS-Cart nulls are read as ''
     * @param array<string,mixed>     $context See the file header
     */
    public function send($phones, string $message, array $context = []): SendResult
    {
        // 2026-10-21 01:04 +03: a caller can never point a send at an outbox row; rows are drained only by drainDue(),
        // from the claimed row's own contents (fix round 1, critical 1), task 596.
        unset($context['outbox_id']);

        return $this->run($this->phoneList($phones), $message, $this->normalizeContext($context), null);
    }

    /**
     * Drains the outbox: reaps rows that can never be drained (sending for more than 10 minutes -> unknown, held
     * past their expiry -> expired), then claims due rows oldest first and sends what each CLAIMED row holds. A
     * row another drain claimed first, or whose attempts changed since this drain read it, is skipped. Called by
     * the cron (Task 1.7) and the dashboard fallback; callers never pass row data. Never throws.
     * 2026-10-21 01:04 +03: added (fix round 1, critical 1), task 596.
     *
     * @return array{reaped:int,due:int,claimed:int,busy:int,stopped:?string,results:array<int,SendResult>}
     */
    public function drainDue(int $limit, float $time_budget_s): array
    {
        $started = microtime(true);
        $summary = ['reaped' => 0, 'due' => 0, 'claimed' => 0, 'busy' => 0, 'stopped' => null, 'results' => []];
        try {
            foreach ($this->outbox->reap($this->now()) as $reaped) {
                $summary['reaped']++;
                $this->recordReaped($reaped);
            }
            $rows = $this->outbox->due($limit);
        } catch (\Throwable $e) {
            $this->noteThrowable('sms.drain_failed', $e);

            return $summary;
        }
        $summary['due'] = count($rows);
        foreach ($rows as $snapshot) {
            if (microtime(true) - $started >= $time_budget_s) {
                $summary['stopped'] = 'time_budget';
                break;
            }
            try {
                if (!$this->outbox->claim($snapshot['outbox_id'], $snapshot['attempts'])) {
                    $summary['busy']++;
                    continue;
                }
                $row = $this->outbox->find($snapshot['outbox_id']);
            } catch (\Throwable $e) {
                $this->noteThrowable('sms.drain_claim_failed', $e);
                continue;
            }
            if ($row === null || $row['status'] !== Outbox::STATUS_SENDING) {
                $summary['busy']++;
                continue;
            }
            $summary['claimed']++;
            $summary['results'][$row['outbox_id']] = $this->sendRow($row);
        }
        $this->note('info', 'sms.drain', $summary['stopped'] ?? 'done', [
            'reaped' => $summary['reaped'],
            'due' => $summary['due'],
            'claimed' => $summary['claimed'],
            'busy' => $summary['busy'],
        ], (int) round((microtime(true) - $started) * 1000));

        return $summary;
    }

    /**
     * One claimed outbox row through the pipeline: phones, text and context come from the row; only its id is
     * added. 2026-10-21 01:04 +03: added, task 596.
     *
     * @param array<string,mixed> $row A decoded row that this request claimed (status sending)
     */
    private function sendRow(array $row): SendResult
    {
        $context = $this->normalizeContext(is_array($row['context']) ? $row['context'] : []);
        $context['outbox_id'] = (int) $row['outbox_id'];

        return $this->run($this->phoneList($row['phones']), (string) $row['message'], $context, $row);
    }

    /**
     * Attempt rows for the recipients of a reaped row (outcome unknown or expired).
     * 2026-10-21 01:04 +03: added, task 596.
     *
     * @param array<string,mixed> $row
     */
    private function recordReaped(array $row): void
    {
        $context = $this->normalizeContext(is_array($row['context']) ? $row['context'] : []);
        $outcome = $row['status'] === Outbox::STATUS_UNKNOWN ? 'unknown' : 'expired';
        $reason = $outcome === 'unknown' ? 'outbox_claim_stale' : 'expired_in_outbox';
        $rcpt = $this->recipients($this->phoneList($row['phones']));
        foreach ($rcpt as $i => $r) {
            $rcpt[$i] = $this->decided($r, $outcome, $reason);
            $rcpt[$i]['phone'] = ltrim($r['phone_raw'], '+');
            $rcpt[$i]['outbox_id'] = (int) $row['outbox_id'];
        }
        $this->recordAttempts($rcpt, $context);
        $this->note('warning', 'sms.outbox_reaped', $outcome, [
            'outbox_id' => $row['outbox_id'],
            'recipients' => count($rcpt),
        ]);
    }

    /**
     * The body of send() and of a drained row. Never throws.
     *
     * @param string[]                 $list
     * @param array<string,mixed>      $context Normalized
     * @param array<string,mixed>|null $row     The claimed outbox row, or null
     */
    private function run(array $list, string $message, array $context, ?array $row): SendResult
    {
        $started = microtime(true);
        $this->stages = [];
        $this->stage_started = $started;
        $this->client = null;
        $this->row = $row;
        // Ruling R26: a drained row keeps the test flag of its first attempt; a test row never goes out live, and
        // test mode switched on makes every row a test (the safe direction). A row without a flag is a test.
        $stored = $row !== null && isset($row['context']['test']) ? (string) $row['context']['test'] : '1';
        $this->test = $row === null ? $this->config->testMode() : ($stored !== '0' || $this->config->testMode());
        $calls_before = $this->api_calls;
        $this->note('debug', 'sms.send.enter', 'send()', [
            'recipients' => count($list),
            'source' => $context['source'],
            'event_id' => $context['event_id'],
            'receiver' => $context['receiver'],
            'outbox_id' => $context['outbox_id'],
        ]);

        $rcpt = [];
        try {
            $result = $this->pipeline($list, $message, $context, $rcpt, $row);
        } catch (\Throwable $e) {
            $this->noteThrowable('sms.send_failed', $e);
            $result = $this->afterThrow($rcpt, $context, $row, $this->api_calls > $calls_before);
        }
        $this->row = null;

        try {
            $this->hookSendPost($result, $context);
        } catch (\Throwable $e) {
            $this->noteThrowable('sms.send_post_hook_failed', $e);
        }

        $counts = array_count_values(array_column($result->recipients(), 'outcome'));
        $ms = (int) round((microtime(true) - $started) * 1000);
        $this->note('debug', 'sms.send.stages', 'send() stages', ['stages' => $this->stages], $ms);
        $this->note('info', 'sms.send', $result->status(), [
            'source' => $context['source'],
            'event_id' => $context['event_id'],
            'receiver' => $context['receiver'],
            'order_id' => $context['order_id'],
            'caller' => $context['caller'],
            'outcomes' => $counts,
            'message_ids' => $result->messageIds(),
            'api_calls' => $this->api_calls,
        ], $ms);

        return $result;
    }

    /**
     * What a throw inside the pipeline leaves behind: the claimed row goes back to pending when no API call was
     * made (with one more attempt counted, so a throw that repeats ends at the expiry) and is final "unknown"
     * otherwise (it may have been sent); a recipient is never "deferred" without an outbox row; attempt rows already
     * written are not written again. 2026-10-21 01:04 +03: added (fix round 1, items 4 and 9), task 596.
     *
     * @param array<int,array<string,mixed>> $rcpt
     * @param array<string,mixed>            $context
     * @param array<string,mixed>|null       $row
     */
    private function afterThrow(array $rcpt, array $context, ?array $row, bool $api_called): SendResult
    {
        $repended = false;
        try {
            if ($row !== null) {
                $repended = !$api_called && $this->outbox->markRetry(
                    (int) $row['outbox_id'],
                    $this->now() + Outbox::backoff((int) $row['attempts'] + 1),
                    'error'
                );
                if (!$repended) {
                    $this->outbox->markUnknown((int) $row['outbox_id']);
                }
            }
        } catch (\Throwable $e) {
            $this->noteThrowable('sms.send_failed_outbox', $e);
        }
        $pending = [];
        foreach ($rcpt as $i => $r) {
            if ($r['attempt_id'] > 0) {
                continue;
            }
            if ($row !== null) {
                $rcpt[$i]['outbox_id'] = (int) $row['outbox_id'];
                if ($r['outcome'] === null || $r['outcome'] === 'deferred') {
                    $rcpt[$i] = $repended
                        ? $this->decided($rcpt[$i], 'deferred', 'error_retry', 'LOCAL_ERROR')
                        : $this->decided($rcpt[$i], 'unknown', 'LOCAL_ERROR', 'LOCAL_ERROR');
                }
            } elseif ($r['outcome'] === null || ($r['outcome'] === 'deferred' && $r['outbox_id'] < 1)) {
                $rcpt[$i] = $this->decided($rcpt[$i], 'failed', 'LOCAL_ERROR', 'LOCAL_ERROR');
            }
            $pending[$i] = $rcpt[$i];
        }
        try {
            $this->recordAttempts($pending, $context);
            foreach ($pending as $i => $r) {
                $rcpt[$i]['attempt_id'] = $r['attempt_id'];
            }
        } catch (\Throwable $e) {
            $this->noteThrowable('sms.send_failed_attempts', $e);
        }

        return new SendResult($rcpt, ['test' => $this->test, 'error_code' => 'LOCAL_ERROR']);
    }

    // ------------------------------------------------------------------ test seams

    /**
     * PRD 5.2: kwtsms_send_pre($phones, $message, $context, $cancel); fn_set_hook() takes references.
     *
     * @param array<int,mixed>    $phones
     * @param array<string,mixed> $context
     */
    protected function hookSendPre(array &$phones, string &$message, array &$context, bool &$cancel): void
    {
        if (function_exists('fn_set_hook')) {
            fn_set_hook('kwtsms_send_pre', $phones, $message, $context, $cancel);
        }
    }

    /**
     * PRD 5.2: kwtsms_send_post($result, $context).
     *
     * @param array<string,mixed> $context
     */
    protected function hookSendPost(SendResult $result, array $context): void
    {
        if (function_exists('fn_set_hook')) {
            fn_set_hook('kwtsms_send_post', $result, $context);
        }
    }

    /** The request budget applies to web requests only (the cron and CLI have their own time budget). */
    protected function isWebRequest(): bool
    {
        return PHP_SAPI !== 'cli';
    }

    // ------------------------------------------------------------------ the pipeline

    /**
     * @param string[]                        $phones
     * @param array<string,mixed>             $context
     * @param array<int,array<string,mixed>>  $rcpt    Filled here, so the caller can finish them after a throw
     * @param array<string,mixed>|null        $row     The claimed outbox row being drained (2026-10-21 01:04 +03)
     */
    private function pipeline(array $phones, string $message, array &$context, array &$rcpt, ?array $row): SendResult
    {
        $meta = ['test' => $this->test];

        // A drained row past its expiry is not sent (it was claimed by drainDue()).
        if ($row !== null && $row['expires_at'] > 0 && $this->now() >= $row['expires_at']) {
            $rcpt = $this->recipients($phones);
            $this->decideAll($rcpt, 'expired', 'expired_in_outbox');
            $this->outbox->markExpired((int) $row['outbox_id']);

            return $this->finish($rcpt, $context, $message, $meta, null);
        }

        // Step 1: kwtsms_send_pre may edit the phones, the text and the context, or cancel.
        // 2026-10-21 01:04 +03: NOT for a drained row: the hook ran on its first attempt and the row holds the text it
        // returned; running it again would edit an edited text and could cancel a send already promised (fix
        // round 1, important 3), task 596.
        $cancel = false;
        $outbox_id = $context['outbox_id'];
        if ($row === null) {
            try {
                $this->hookSendPre($phones, $message, $context, $cancel);
            } catch (\Throwable $e) {
                $this->noteThrowable('sms.send_pre_hook_failed', $e);
                $cancel = true;
            }
            // A handler may have put anything into the three references.
            $phones = $this->phoneList($phones);
            $message = is_scalar($message) ? (string) $message : '';
            $context = $this->normalizeContext(is_array($context) ? $context : []);
        }
        $context['outbox_id'] = $outbox_id;
        $rcpt = $this->recipients($phones);
        $this->stage('1_send_pre', $rcpt);
        if ($cancel) {
            $this->decideAll($rcpt, 'cancelled', 'kwtsms_send_pre');

            return $this->finish($rcpt, $context, $message, $meta, $row);
        }

        // Step 2: the global switch.
        if (!$this->config->enabled()) {
            $this->decideAll($rcpt, 'blocked_disabled', 'enabled_off');

            return $this->finish($rcpt, $context, $message, $meta, $row);
        }

        // Step 3: configured, then suspended.
        [$configured, $why] = $this->state->isConfigured($this->config);
        if (!$configured) {
            $this->decideAll($rcpt, 'blocked_not_configured', (string) $why);

            return $this->finish($rcpt, $context, $message, $meta, $row);
        }
        if ($this->state->suspendedCode() !== null) {
            foreach ($this->open($rcpt) as $i) {
                $rcpt[$i] = $this->deferred($rcpt[$i], 'suspended', $this->suspendedRetry());
            }

            return $this->finish($rcpt, $context, $message, $meta, $row);
        }
        $this->stage('3_configured', $rcpt);

        // Step 4: abuse caps (the numbers are normalized here to be counted; step 5 decides on them).
        $hint = $this->countryHint($context);
        foreach ($rcpt as $i => $r) {
            [$ok, $reason, $normalized] = PhoneNormalizer::validateInput($r['phone_raw'], $hint);
            $rcpt[$i]['phone'] = $normalized;
            $rcpt[$i]['valid'] = $ok;
            $rcpt[$i]['invalid_reason'] = (string) $reason;
        }
        try {
            $this->applyCaps($rcpt, $context);
        } catch (\Throwable $e) {
            $this->noteThrowable('sms.cap_unavailable', $e);
            foreach ($this->open($rcpt) as $i) {
                $rcpt[$i] = $this->deferred($rcpt[$i], 'cap_unavailable', $this->now() + 60);
            }

            return $this->finish($rcpt, $context, $message, $meta, $row);
        }
        $this->stage('4_caps', $rcpt);

        // Step 5: local verification.
        foreach ($this->open($rcpt) as $i) {
            if (!$rcpt[$i]['valid']) {
                $raw_empty = trim($rcpt[$i]['phone_raw']) === '';
                $reason = $rcpt[$i]['invalid_reason'] === 'empty' ? 'no_digits' : $rcpt[$i]['invalid_reason'];
                $rcpt[$i] = $raw_empty
                    ? $this->decided($rcpt[$i], 'no_phone', 'empty')
                    : $this->decided($rcpt[$i], 'invalid', $reason);
            }
        }
        $this->stage('5_verify', $rcpt);

        // Step 6: one API number per normalized number.
        $seen = [];
        foreach ($this->open($rcpt) as $i) {
            if (isset($seen[$rcpt[$i]['phone']])) {
                $first_raw = $rcpt[$seen[$rcpt[$i]['phone']]]['phone_raw'];
                $rcpt[$i] = $this->decided($rcpt[$i], 'duplicate', 'same_as_' . $first_raw);
                continue;
            }
            $seen[$rcpt[$i]['phone']] = $i;
        }
        $this->stage('6_dedupe', $rcpt);

        // Step 7: allowed customer countries, then coverage.
        $allowed = $this->config->customerSmsCountries();
        $coverage = $this->state->coverage();
        foreach ($this->open($rcpt) as $i) {
            $cc = (string) PhoneNormalizer::countryCode($rcpt[$i]['phone']);
            if ($context['receiver'] === 'C' && !in_array($cc, $allowed, true)) {
                $rcpt[$i] = $this->decided($rcpt[$i], 'blocked_country', 'country_' . ($cc === '' ? 'unknown' : $cc));
                continue;
            }
            if ($coverage === null) {
                $rcpt[$i]['note'] = 'coverage_unknown';
                continue;
            }
            if (!$this->covered($rcpt[$i]['phone'], $coverage)) {
                $rcpt[$i] = $this->decided($rcpt[$i], 'no_coverage', 'country_' . ($cc === '' ? 'unknown' : $cc));
                $rcpt[$i]['country'] = $cc === '' ? substr($rcpt[$i]['phone'], 0, 3) : $cc;
            }
        }
        $this->stage('7_countries', $rcpt);
        if ($this->open($rcpt) === []) {
            return $this->finish($rcpt, $context, $message, $meta, $row);
        }

        // Step 8: the one full clean of the final body, then the checks on what was cleaned.
        $body = MessageCleaner::clean($message);
        if ($body === '') {
            $this->decideAll($rcpt, 'blocked_empty', 'empty_after_cleaning');

            return $this->finish($rcpt, $context, $message, $meta, $row);
        }
        if (TemplateRenderer::hasLeftover($body)) {
            $this->decideAll($rcpt, 'blocked_placeholder', 'leftover_placeholder');

            return $this->finish($rcpt, $context, $message, $meta, $row);
        }
        if (MessageCleaner::hasHtmlLikeSpan($body)) {
            $this->decideAll($rcpt, 'rejected', 'LOCAL_HTML', 'LOCAL_HTML');

            return $this->finish($rcpt, $context, $message, $meta, $row);
        }
        $this->stage('8_clean', $rcpt);

        // Step 9: length.
        $pages = MessageCleaner::pages($body);
        $limit = in_array($context['source'], ['manual', 'api'], true)
            ? MessageCleaner::MAX_PAGES
            : min(MessageCleaner::MAX_PAGES, $this->config->maxPagesNotification());
        if ($pages > $limit) {
            $this->decideAll($rcpt, 'blocked_too_long', 'pages_' . $pages . '_over_' . $limit);

            return $this->finish($rcpt, $context, $message, $meta, $row);
        }
        $this->stage('9_length', $rcpt);

        // Step 10: balance (pages x recipients), one live refresh at most every 10 minutes.
        $required = $pages * count($this->open($rcpt));
        $available = $this->availableBalance();
        if ($available < $required && $this->balanceRefreshAllowed()) {
            $this->refreshBalance();
            $available = $this->availableBalance();
        }
        if ($this->state->suspendedCode() !== null) {
            // 2026-10-21 01:04 +03: the refresh answered an account-level code (ruling R25): the send waits, task 596.
            foreach ($this->open($rcpt) as $i) {
                $rcpt[$i] = $this->deferred($rcpt[$i], 'suspended', $this->suspendedRetry());
            }

            return $this->finish($rcpt, $context, $message, $meta, $row);
        }
        if ($available < $required) {
            $why = 'required_' . $required . '_available_' . $this->number($available);
            $this->decideAll($rcpt, 'blocked_balance', $why);
            $this->note('warning', 'sms.blocked_balance', 'insufficient balance', [
                'required' => $required,
                'available' => $available,
            ]);

            return $this->finish($rcpt, $context, $message, $meta, $row);
        }
        $this->stage('10_balance', $rcpt);

        // Step 11: pilot mode, then quiet hours.
        if ($this->config->pilotMode()) {
            $admins = $this->config->adminPhones();
            foreach ($this->open($rcpt) as $i) {
                if (!in_array($rcpt[$i]['phone'], $admins, true)) {
                    $rcpt[$i] = $this->decided($rcpt[$i], 'blocked_pilot', 'pilot_mode');
                }
            }
        }
        $quiet_end = $this->quietHoursEnd($context);
        if ($quiet_end !== null) {
            foreach ($this->open($rcpt) as $i) {
                $rcpt[$i] = $this->deferred($rcpt[$i], 'quiet_hours', $quiet_end);
            }
        }
        $this->stage('11_pilot_quiet', $rcpt);
        $open = $this->open($rcpt);
        if ($open === []) {
            return $this->finish($rcpt, $context, $message, $meta, $row);
        }

        // Steps 12-14: request budget, the API calls, one message row per call.
        $numbers = [];
        foreach ($open as $i) {
            $numbers[] = $rcpt[$i]['phone'];
        }
        $balance_before = $available;
        $bulk = $this->bulkSend($numbers, $body, $context + ['_body' => $body, '_pages' => $pages]);
        foreach ($open as $i) {
            $chunk = $bulk['by_number'][$rcpt[$i]['phone']] ?? null;
            if ($chunk === null) {
                $rcpt[$i] = $this->decided($rcpt[$i], 'failed', 'LOCAL_ERROR', 'LOCAL_ERROR');
                continue;
            }
            $rcpt[$i]['message_id'] = $chunk['message_id'];
            $rcpt[$i] = $chunk['defer'] !== null
                ? $this->deferred($rcpt[$i], $chunk['defer'], $chunk['retry_at'], $chunk['code'], $chunk['held'])
                : $this->decided($rcpt[$i], $chunk['outcome'], $chunk['reason'], $chunk['code']);
        }
        $meta['message_ids'] = $bulk['message_ids'];
        $meta['points_charged'] = $bulk['points'];
        $meta['balance_after'] = $bulk['balance_after'];
        $this->stage('13_send', $rcpt);

        // Step 15: balance from balance-after, low-balance crossing.
        if ($bulk['balance_after'] !== null) {
            $this->state->setBalance((float) $bulk['balance_after'], null);
            $this->checkLowBalance($balance_before, (float) $bulk['balance_after']);
        }

        return $this->finish($rcpt, $context, $message, $meta, $row);
    }

    /**
     * PRD 6.2 step 13, the brief's _bulksend(): calls of at most 200 numbers and 2500 characters of "mobile"
     * (ApiClient::chunk(), 200 ms between calls, every call through the request hook, the rate slot and the
     * breaker of the shared ApiGuard), cut at the request budget. Writes one message row per attempted call.
     *
     * @param string[]            $numbers Normalized, unique
     * @param array<string,mixed> $context Plus _pages
     *
     * @return array{by_number:array<string,array<string,mixed>>,message_ids:string[],points:float,balance_after:?float}
     */
    private function bulkSend(array $numbers, string $message, array $context): array
    {
        $out = ['by_number' => [], 'message_ids' => [], 'points' => 0.0, 'balance_after' => null];
        $chunks = ApiClient::chunk($numbers);
        $allowed = count($chunks);
        if ($this->isWebRequest()) {
            $allowed = max(0, min($allowed, self::REQUEST_BUDGET - $this->api_calls));
        }
        // 2026-10-21 01:04 +03: the flag of this send (a drained row keeps its own, ruling R26), task 596.
        $test = $this->test;

        $send = [];
        foreach (array_slice($chunks, 0, $allowed) as $chunk) {
            $send = array_merge($send, $chunk);
        }
        $stopped = null;
        if ($send !== []) {
            $result = $this->client($context)->send($this->config->senderId(), $send, $message, $test);
            if (($result['calls'] ?? []) === []) {
                // 2026-10-21 01:04 +03: refused before any call (LOCAL_BAD_INPUT): every number keeps that code,
                // task 596.
                $code = (string) ($result['code'] ?? 'LOCAL_ERROR');
                foreach ($send as $number) {
                    $out['by_number'][(string) $number] = $this->verdict('failed', $code, $code) + ['message_id' => 0];
                }
            }
            foreach ($result['calls'] ?? [] as $i => $call) {
                if (!empty($call['skipped'])) {
                    $verdict = $this->skippedVerdict($stopped, (string) ($call['skipped_because'] ?? ''));
                    $message_id = 0;
                } else {
                    $this->api_calls++;
                    $verdict = $this->callVerdict($call, $test, $context);
                    if ($call['result'] !== 'OK') {
                        // The last failed call before a skipped one is the one that stopped the send.
                        $stopped = $verdict;
                    }
                    $batch = [(int) $i + 1, count($chunks)];
                    $message_id = $this->recordMessage($call, $verdict, $context, $message, $batch);
                    if ($call['result'] === 'OK') {
                        if ($call['msg-id'] !== null) {
                            $out['message_ids'][] = (string) $call['msg-id'];
                        }
                        $out['points'] += (float) $call['points-charged'];
                        if ($call['balance-after'] !== null) {
                            $out['balance_after'] = (float) $call['balance-after'];
                        }
                    }
                }
                foreach ((array) $call['recipients'] as $number) {
                    $out['by_number'][(string) $number] = $verdict + ['message_id' => $message_id];
                }
            }
        }

        // Past the budget: deferred to the outbox, due at once (the next drain sends them).
        foreach (array_slice($chunks, $allowed) as $chunk) {
            foreach ($chunk as $number) {
                $overflow = $this->verdict('deferred', 'request_overflow', null, 'request_overflow', $this->now());
                $out['by_number'][(string) $number] = $overflow + ['message_id' => 0];
            }
        }
        if (count($chunks) > $allowed) {
            $this->note('warning', 'sms.request_overflow', 'request budget used', [
                'calls' => $this->api_calls,
                'deferred_numbers' => count($numbers) - count($send),
            ]);
        }

        return $out;
    }

    // ------------------------------------------------------------------ outcome of one API call

    /**
     * The outcome of an attempted call, with its side effects (suspension, cached balance, alerts, logs).
     *
     * @param array<string,mixed> $call    One entry of ApiClient::send()['calls']
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed> outcome, reason, code, defer, retry_at, held
     */
    private function callVerdict(array $call, bool $test, array $context): array
    {
        if ($call['result'] === 'OK') {
            $partial = (int) $call['numbers'] < (int) $call['recipient_count'];

            return $this->verdict($partial ? 'partial_accept' : ($test ? 'test_queued' : 'accepted'), '', null);
        }
        $code = (string) $call['code'];
        $text = $code . ($call['description'] !== '' ? ': ' . substr((string) $call['description'], 0, 200) : '');
        $attempts = $this->retryAttempts($context);

        if (in_array($code, self::SUSPEND_CODES, true)) {
            $this->suspend($code);

            return $this->verdict('deferred', 'suspended: ' . $code, $code, 'suspended', $this->suspendedRetry());
        }
        if ($code === 'ERR003') {
            $probe = $this->probeCredentials();
            if ($probe === 'ok') {
                return $this->verdict('rejected', 'ERR003_content', $code);
            }
            if ($this->state->suspendedCode() !== null) {
                // 2026-10-21 01:04 +03: the probe suspended (ERR003 or another account-level code), task 596.
                return $this->verdict('deferred', 'suspended: ' . $probe, $code, 'suspended', $this->suspendedRetry());
            }

            return $this->verdict('failed', $text, $code);
        }
        if ($code === 'ERR013') {
            $retry_at = $this->now() + Outbox::backoff($attempts);

            return $this->verdict('deferred', 'queue_full: ERR013', $code, 'queue_full', $retry_at);
        }
        if ($code === 'ERR010') {
            $this->state->setBalance(0.0, null);
        }
        if (in_array($code, self::CLEANER_GAPS, true)) {
            $masked = Redactor::mask((string) ($context['_body'] ?? ''), $context['secret_values']);
            $this->note('error', 'sms.cleaner_gap', $code, ['body_hex' => bin2hex(substr($masked, 0, 300))]);
        }
        if ($code === 'ERR002' || $code === 'ERR007') {
            $this->note('error', 'sms.addon_bug', $code, [
                'request' => Redactor::mask((string) $call['raw_request'], $context['secret_values']),
            ]);
        }
        if ($code === 'ERR035') {
            $this->alert('intl_limit', [], false, 86400);
        }
        if (isset(self::CODE_OUTCOMES[$code])) {
            return $this->verdict(self::CODE_OUTCOMES[$code], $text, $code);
        }
        $may_have_arrived = in_array($code, ['LOCAL_HTTP', 'LOCAL_BAD_JSON'], true) && !empty($call['sent']);
        if ($code === 'LOCAL_TIMEOUT' || $may_have_arrived) {
            // The request may have arrived: never resent automatically (Appendix A).
            return $this->verdict('unknown', $code, $code);
        }
        if (in_array($code, self::UNREACHABLE, true)) {
            $held = $code === 'LOCAL_TLS';
            if ($held) {
                $this->alert('tls_failure', [], false, 86400);
            }

            $retry_at = $this->now() + Outbox::backoff($attempts);

            $reason = 'gateway_unreachable: ' . $code;

            return $this->verdict('deferred', $reason, $code, 'gateway_unreachable', $retry_at, $held);
        }
        if ($code === 'LOCAL_CANCELLED') {
            return $this->verdict('cancelled', $code, $code);
        }

        return $this->verdict('failed', $text, $code);
    }

    /**
     * A chunk the client did not send because an earlier one failed: it never reached kwtSMS, so it is never
     * unknown. It shares the stopping chunk's verdict, except that "unknown" becomes a deferral.
     *
     * @param array<string,mixed>|null $stopped The verdict of the chunk that stopped the send
     *
     * @return array<string,mixed>
     */
    private function skippedVerdict(?array $stopped, string $because): array
    {
        if ($stopped === null || $stopped['outcome'] === 'unknown') {
            return $this->verdict(
                'deferred',
                'gateway_unreachable: LOCAL_SKIPPED after ' . $because,
                'LOCAL_SKIPPED',
                'gateway_unreachable',
                $this->now() + Outbox::backoff(0)
            );
        }
        $stopped['reason'] = 'LOCAL_SKIPPED after ' . $stopped['reason'];

        return $stopped;
    }

    /**
     * @return array<string,mixed>
     */
    private function verdict(
        string $outcome,
        string $reason,
        ?string $code,
        ?string $defer = null,
        int $retry_at = 0,
        bool $held = false
    ): array {
        return [
            'outcome' => $outcome,
            'reason' => $reason,
            'code' => $code,
            'defer' => $defer,
            'retry_at' => $retry_at,
            'held' => $held,
        ];
    }

    /**
     * The credentials probe (PRD 6.1): one live /balance/ that tells a rejected text (OK) from changed or blocked
     * credentials. At most one per 15 minutes across requests (GatewayState::lastProbe(); within that time the
     * stored verdict is returned without a call) and one per request. OK caches the balance and lifts a
     * suspension; ERR003 or an account-level code (ERR001/004/005/024/034) suspends. Credentials are never
     * cleared here. SyncService (Task 1.7) delegates its probe here (ruling R25).
     * 2026-10-21 01:04 +03: public, throttled across requests, suspends on account-level codes (fix round 1, item 11),
     * task 596.
     *
     * 2026-10-21 01:21 +03: $force skips both throttles ("Retry now", PRD 6.1), and a stored verdict older than the
     * current suspension is never reused (it says nothing about the account since), task 596.
     *
     * @param string $timeout_context web, admin or cli; '' = the context of the current send or request
     * @param bool   $force           True for "Retry now": probe even inside the 15 minutes
     *
     * @return string "ok", the error code (ERR003, ERR005, ...), or "failed" (no answer, budget used)
     */
    public function probeCredentials(string $timeout_context = '', bool $force = false): string
    {
        if ($this->probe !== null && !$force) {
            return $this->probe;
        }
        $last = $this->state->lastProbe();
        $suspended_at = $this->state->suspendedAt();
        $fresh = $last !== null && $this->now() - (int) $last['at'] < self::PROBE_SECONDS
            && ($suspended_at === null || (int) $last['at'] >= $suspended_at);
        if ($fresh && !$force) {
            $verdict = (string) $last['verdict'];

            return $verdict === 'ok' ? 'ok' : (string) ($last['code'] ?? 'failed');
        }
        if ($this->isWebRequest() && $this->api_calls >= self::REQUEST_BUDGET) {
            return 'failed';
        }
        $this->api_calls++;
        $r = $this->clientFor($timeout_context)->balance();
        if ($r['result'] === 'OK') {
            $this->cacheBalance($r);
            $this->probe = 'ok';
            $this->state->recordProbe('ok', null);
            if ($this->state->suspendedCode() !== null) {
                $this->state->resume();
                $this->note('info', 'sms.resumed', 'probe ok', []);
            }
        } else {
            $code = (string) $r['code'];
            $this->probe = $code === 'ERR003' || in_array($code, self::SUSPEND_CODES, true) ? $code : 'failed';
            if ($r['code_source'] === 'api') {
                $this->state->recordProbe('error', $code);
            }
            if ($this->probe !== 'failed') {
                $this->suspend($code);
            }
        }
        $this->note('info', 'sms.probe', $this->probe, ['code' => $r['code']]);

        return $this->probe;
    }

    private function suspend(string $code): void
    {
        $this->state->suspend($code);
        $this->note('error', 'sms.suspended', $code, []);
        $this->alert('suspended', ['[code]' => $code], true, 86400, $code);
    }

    // ------------------------------------------------------------------ step 4: caps

    /**
     * @param array<int,array<string,mixed>> $rcpt
     * @param array<string,mixed>            $context
     *
     * @throws \RuntimeException When a count cannot be read (the caller defers, never skips the cap)
     */
    private function applyCaps(array &$rcpt, array $context): void
    {
        // 2026-10-21 01:04 +03: one grouped count for every number of the send (was two counts per number), and the
        // admin bucket only for numbers in admin_phones; any other number of an admin send gets the customer caps
        // (fix round 1, items 6 and 12), task 596.
        $admin_receiver = $context['receiver'] === 'A';
        $capped = $admin_receiver || $context['receiver'] === 'C'
            || in_array($context['source'], ['manual', 'api'], true);
        $sources = $context['source'] === 'api' ? ['api'] : self::NON_API_SOURCES;
        $admins = $admin_receiver ? $this->config->adminPhones() : [];

        $phones = [];
        foreach ($this->open($rcpt) as $i) {
            if ($rcpt[$i]['valid']) {
                $phones[] = $rcpt[$i]['phone'];
            }
        }
        $counts = $capped && $phones !== []
            ? $this->attempts->countsByPhone($phones, self::SENT_OUTCOMES, $sources)
            : [];
        foreach ($this->open($rcpt) as $i) {
            if (!$capped || !$rcpt[$i]['valid']) {
                continue;
            }
            $c = $counts[$rcpt[$i]['phone']] ?? ['hour' => 0, 'day' => 0, 'day_all' => 0];
            if (in_array($rcpt[$i]['phone'], $admins, true)) {
                if ($c['day_all'] >= $this->config->capAdminDay()) {
                    $rcpt[$i] = $this->decided($rcpt[$i], 'rate_limited', 'cap_admin_day');
                }
                continue;
            }
            if ($c['hour'] >= $this->config->capRecipientHour()) {
                $rcpt[$i] = $this->decided($rcpt[$i], 'rate_limited', 'cap_recipient_hour');
            } elseif ($c['day'] >= $this->config->capRecipientDay()) {
                $rcpt[$i] = $this->decided($rcpt[$i], 'rate_limited', 'cap_recipient_day');
            }
        }

        // The store-wide daily cap: counted from local midnight, or from "Resume now" when that is later.
        $now = $this->now();
        $start = max((int) strtotime('today', $now), $this->state->dailyCapResumedAt());
        $used = $this->attempts->countRecent('', '', $now - $start, self::SENT_OUTCOMES);
        $left = $this->config->capStoreDay() - $used;
        $passed = [];
        $blocked = 0;
        foreach ($this->open($rcpt) as $i) {
            if (!$rcpt[$i]['valid'] || isset($passed[$rcpt[$i]['phone']])) {
                continue;
            }
            if (count($passed) >= $left) {
                $rcpt[$i] = $this->decided($rcpt[$i], 'blocked_daily_cap', 'cap_store_day');
                $blocked++;
                continue;
            }
            $passed[$rcpt[$i]['phone']] = true;
        }
        if ($blocked > 0) {
            $this->note('warning', 'sms.daily_cap', 'store daily cap reached', [
                'cap' => $this->config->capStoreDay(),
                'used' => $used,
            ]);
            $this->alert('daily_cap', ['[cap]' => $this->config->capStoreDay()], true, 172800, date('Y-m-d', $now));
        }
    }

    // ------------------------------------------------------------------ steps 10, 11, 15

    private function availableBalance(): float
    {
        $balance = $this->state->balance();

        return $balance === null ? 0.0 : (float) $balance['available'];
    }

    /**
     * PRD 6.1: a live balance refresh at most every 10 minutes, and never past the request budget.
     * 2026-10-21 01:04 +03: public (SyncService::refreshBalanceIfAllowed() asks it, ruling R25), task 596.
     */
    public function balanceRefreshAllowed(): bool
    {
        $last = $this->state->lastBalanceRefresh();
        if ($last !== null && $this->now() - $last < self::BALANCE_REFRESH_SECONDS) {
            return false;
        }

        return !($this->isWebRequest() && $this->api_calls >= self::REQUEST_BUDGET);
    }

    /**
     * One live /balance/ (the stamp of the 10-minute rule is written first, so a failed refresh waits too). OK
     * caches the balance; ERR003 or an account-level code (ERR001/004/005/024/034) suspends (ruling R25). The
     * caller checks balanceRefreshAllowed() first; SyncService (Task 1.7) delegates here.
     * 2026-10-21 01:04 +03: public, suspends on account-level codes (fix round 1, item 11), task 596.
     *
     * @param string $timeout_context web, admin or cli; '' = the context of the current send or request
     *
     * @return float|null The available balance, or null when kwtSMS did not answer OK
     */
    public function refreshBalance(string $timeout_context = ''): ?float
    {
        $this->state->touchBalanceRefresh();
        $this->api_calls++;
        $r = $this->clientFor($timeout_context)->balance();
        $this->note('info', 'sms.balance_refresh', (string) $r['result'], ['code' => $r['code']]);
        if ($r['result'] === 'OK') {
            $this->cacheBalance($r);

            return (float) $r['available'];
        }
        $code = (string) $r['code'];
        if ($code === 'ERR003' || in_array($code, self::SUSPEND_CODES, true)) {
            $this->suspend($code);
        }

        return null;
    }

    /**
     * PRD 6.13: a notice and an e-mail once per crossing of the low-balance threshold (setting, or "auto":
     * max(100, 3 days of the 30-day average of numbers sent)).
     */
    private function checkLowBalance(float $before, float $after): void
    {
        if ($after >= $before) {
            return;
        }
        $threshold = $this->config->lowBalanceThreshold();
        if ($threshold === null) {
            // 2026-10-21 01:04 +03: counted once a day and kept in storage; a failed count is logged at error and the
            // floor of 100 is used for this send only (fix round 1, items 5 and 13), task 596.
            $day = date('Y-m-d', $this->now());
            $threshold = $this->state->lowBalanceAuto($day);
            if ($threshold === null) {
                try {
                    $sent = $this->attempts->countRecent('', '', 30 * 86400, self::SENT_OUTCOMES);
                    $threshold = max(100, (int) ceil(3 * $sent / 30));
                    $this->state->setLowBalanceAuto($day, $threshold);
                } catch (\Throwable $e) {
                    $this->noteThrowable('sms.low_balance_count_failed', $e);
                    $threshold = 100;
                }
            }
        }
        if ($before >= $threshold && $after < $threshold) {
            $this->alert('low_balance', ['[balance]' => $this->number($after), '[threshold]' => $threshold], true, 0);
        }
    }

    /**
     * D9: customer status SMS triggered by an admin, the API or the cron wait for the end of the quiet window;
     * checkout confirmations (kwtsms.order_placed*), OTP and admin alerts are exempt.
     *
     * @param array<string,mixed> $context
     *
     * @return int|null The end of the window (Unix time), or null when nothing waits
     */
    private function quietHoursEnd(array $context): ?int
    {
        if (!$this->config->quietHoursEnabled() || $context['receiver'] !== 'C' || $context['source'] === 'otp') {
            return null;
        }
        if (strncmp($context['event_id'], 'kwtsms.order_placed', 19) === 0) {
            return null;
        }
        if (!in_array($context['source'], ['manual', 'api', 'cron'], true) && $context['audience'] !== 'admin') {
            return null;
        }
        $from = $this->config->quietHoursFrom();
        $to = $this->config->quietHoursTo();
        $now = $this->now();
        $time = date('H:i', $now);
        $inside = $from < $to ? ($time >= $from && $time < $to) : ($from !== $to && ($time >= $from || $time < $to));
        if (!$inside) {
            return null;
        }
        $end = (int) strtotime(date('Y-m-d', $now) . ' ' . $to . ':00', $now);

        return $end > $now ? $end : (int) strtotime('+1 day', $end);
    }

    // ------------------------------------------------------------------ finishing: outbox, attempts, result

    /**
     * Writes the deferred recipients to the outbox (or back to the row being drained), the attempt rows, and
     * builds the result.
     *
     * @param array<int,array<string,mixed>> $rcpt
     * @param array<string,mixed>            $context
     * @param array<string,mixed>            $meta
     * @param array<string,mixed>|null       $row     The outbox row being drained
     */
    private function finish(array &$rcpt, array $context, string $message, array $meta, ?array $row): SendResult
    {
        $groups = [];
        foreach ($rcpt as $i => $r) {
            if ($r['outcome'] === 'deferred' && $r['defer'] !== null) {
                $key = $r['defer']['reason'] . '|' . $r['defer']['retry_at'] . '|' . ($r['defer']['held'] ? 1 : 0);
                $groups[$key][] = $i;
            }
        }
        $reused = false;
        foreach ($groups as $indexes) {
            $first = $rcpt[$indexes[0]]['defer'];
            $phones = [];
            foreach ($indexes as $i) {
                // A normalized number is queued with + so the retry never applies a country hint to it again.
                $normalized = $rcpt[$i]['phone'] !== '' && $rcpt[$i]['valid'];
                $phones[] = $normalized ? '+' . $rcpt[$i]['phone'] : $rcpt[$i]['phone_raw'];
            }
            if ($row !== null && !$reused) {
                $reused = true;
                // 2026-10-21 01:04 +03: quiet hours move the expiry past the window end (fix round 1, item 10),
                // task 596.
                $expires = $first['reason'] === 'quiet_hours' ? $first['retry_at'] + Outbox::TTL_SECONDS : null;
                $retried = $this->outbox->markRetry(
                    (int) $row['outbox_id'],
                    $first['retry_at'],
                    $first['reason'],
                    $phones,
                    $expires
                );
                $id = $retried ? (int) $row['outbox_id'] : 0;
            } else {
                $expires = $first['reason'] === 'quiet_hours'
                    ? $first['retry_at'] + Outbox::TTL_SECONDS
                    : ($row !== null ? (int) $row['expires_at'] : $this->now() + Outbox::TTL_SECONDS);
                // 2026-10-21 01:04 +03: the row keeps the test flag of this send (ruling R26), task 596.
                $id = $this->outbox->enqueue(
                    $phones,
                    $message,
                    ['test' => $this->test ? '1' : '0'] + $context,
                    $first['reason'],
                    $first['retry_at'],
                    $expires
                );
            }
            if ($id > 0 && $first['held']) {
                $this->outbox->markHeld($id);
            }
            foreach ($indexes as $i) {
                if ($id > 0) {
                    $rcpt[$i]['outbox_id'] = $id;
                } else {
                    $rcpt[$i] = $this->decided($rcpt[$i], 'failed', 'outbox_unavailable', 'outbox_unavailable');
                }
            }
            if ($id === 0) {
                $this->note('error', 'sms.outbox_unavailable', 'could not queue a deferred send', [
                    'reason' => $first['reason'],
                ]);
            }
        }
        if ($row !== null) {
            if (!$reused) {
                $this->outbox->markDone((int) $row['outbox_id']);
            }
            foreach ($rcpt as $i => $r) {
                $rcpt[$i]['outbox_id'] = $r['outbox_id'] > 0 ? $r['outbox_id'] : $row['outbox_id'];
            }
        } elseif ($context['outbox_id'] > 0) {
            foreach ($rcpt as $i => $r) {
                $rcpt[$i]['outbox_id'] = $context['outbox_id'];
            }
        }

        $this->recordAttempts($rcpt, $context);
        $meta['error_message'] = $this->errorMessage($rcpt, $context);
        $this->stage('14_persist', $rcpt);

        return new SendResult($rcpt, $meta);
    }

    /**
     * @param array<int,array<string,mixed>> $rcpt
     * @param array<string,mixed>            $context
     */
    private function recordAttempts(array &$rcpt, array $context): void
    {
        // 2026-10-21 01:04 +03: one INSERT for the whole send (AttemptLog::recordMany(), fix round 1, item 12),
        // task 596.
        $rows = [];
        foreach ($rcpt as $i => $r) {
            $reason = $r['reason'];
            if ($reason === '' && isset($r['note'])) {
                $reason = (string) $r['note'];
            }
            $rcpt[$i]['reason'] = $reason;
            $rows[$i] = [
                'message_id' => (int) $r['message_id'],
                'outbox_id' => (int) $r['outbox_id'],
                'retry_of' => $context['retry_of'],
                'source' => $context['source'],
                'event_id' => $context['event_id'],
                'receiver' => $context['receiver'],
                'company_id' => $context['company_id'],
                'parent_order_id' => $context['parent_order_id'],
                'storefront_id' => $context['storefront_id'],
                'order_id' => $context['order_id'],
                'user_id' => $context['user_id'],
                'actor_user_id' => $context['actor_user_id'],
                'phone_raw' => $r['phone_raw'],
                'phone' => $r['phone'],
                'outcome' => (string) $r['outcome'],
                'reason' => $reason,
                'ip' => $context['ip'] === '' ? null : $context['ip'],
                'secret_values' => $context['secret_values'],
            ];
        }
        $ids = $this->attempts->recordMany(array_values($rows));
        foreach (array_keys($rows) as $k => $i) {
            $rcpt[$i]['attempt_id'] = (int) ($ids[$k] ?? 0);
        }
    }

    /**
     * One message row per attempted API call (PRD 6.2 step 14). Secrets masked and credentials removed by
     * MessageLog.
     *
     * @param array<string,mixed> $call
     * @param array<string,mixed> $verdict
     * @param array<string,mixed> $context
     */
    private function recordMessage(array $call, array $verdict, array $context, string $body, array $batch): int
    {
        $ok = $call['result'] === 'OK';
        [$batch_no, $total] = $batch;

        return $this->messages->record([
            'source' => $context['source'],
            'event_id' => $context['event_id'],
            'receiver' => $context['receiver'],
            'company_id' => $context['company_id'],
            'parent_order_id' => $context['parent_order_id'],
            'storefront_id' => $context['storefront_id'],
            'order_id' => $context['order_id'],
            'user_id' => $context['user_id'],
            'actor_user_id' => $context['actor_user_id'],
            'actor_company_id' => $context['actor_company_id'],
            'sender' => $this->config->senderId(),
            'recipients' => implode(',', (array) $call['recipients']),
            'recipient_count' => (int) $call['recipient_count'],
            'numbers_accepted' => $ok ? (int) $call['numbers'] : 0,
            'partial_accept' => $verdict['outcome'] === 'partial_accept' ? 'Y' : 'N',
            'message' => $body,
            'lang_code' => $context['lang_code'],
            'encoding' => MessageCleaner::isUnicode($body) ? 'ucs2' : 'gsm7',
            'pages' => (int) $context['_pages'],
            'is_test' => ($call['test'] ?? '1') === '1' ? 'Y' : 'N',
            'batch_no' => $batch_no,
            'batch_total' => $total,
            'status' => $verdict['outcome'],
            'api_code' => $ok ? '' : (string) $call['code'],
            'code_source' => $ok ? 'api' : (string) $call['code_source'],
            'api_msg_id' => $ok ? (string) $call['msg-id'] : '',
            'points_charged' => $ok ? (float) $call['points-charged'] : 0.0,
            'balance_after' => $ok ? (float) $call['balance-after'] : 0.0,
            'api_unix_ts' => $ok ? (int) $call['unix-timestamp'] : 0,
            'api_request' => (string) $call['raw_request'],
            'api_response' => (string) $call['raw_response'],
            'duration_ms' => (int) $call['ms'],
            'secret_values' => $context['secret_values'],
        ]);
    }

    /**
     * D4: interactive admin sends get the specific reason, interactive storefront sends a generic text,
     * background sends nothing.
     *
     * @param array<int,array<string,mixed>> $rcpt
     * @param array<string,mixed>            $context
     */
    private function errorMessage(array $rcpt, array $context): string
    {
        if (!$context['interactive']) {
            return '';
        }
        foreach ($rcpt as $r) {
            if ($r['outcome'] === 'duplicate' || in_array($r['outcome'], SendResult::ACCEPTED_OUTCOMES, true)) {
                continue;
            }
            if ($context['audience'] !== 'admin') {
                return (string) __('kwtsms.send.failed_generic');
            }
            if ($r['outcome'] === 'no_coverage') {
                $cc = (string) ($r['country'] ?? PhoneNormalizer::countryCode($r['phone']));

                return (string) __('kwtsms.send.no_coverage', ['[code]' => htmlspecialchars($cc, ENT_QUOTES, 'UTF-8')]);
            }
            if ($r['code'] !== null && $r['code'] !== '' && $r['code'] !== 'outbox_unavailable') {
                return ApiErrors::describe((string) $r['code']);
            }

            return (string) __('kwtsms.outcome.' . $r['outcome']);
        }

        return '';
    }

    // ------------------------------------------------------------------ small helpers

    /**
     * @param mixed $phones
     *
     * @return string[]
     */
    private function phoneList($phones): array
    {
        if (is_string($phones)) {
            $parts = preg_split('/[,;\r\n]+/', $phones);
            $list = [];
            foreach ($parts === false ? [] : $parts as $part) {
                if (trim($part) !== '') {
                    $list[] = trim($part);
                }
            }

            return $list;
        }
        $list = [];
        foreach ((array) $phones as $phone) {
            $list[] = is_scalar($phone) ? trim((string) $phone) : '';
        }

        return $list;
    }

    /**
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed>
     */
    private function normalizeContext(array $context): array
    {
        $out = self::CONTEXT_DEFAULTS;
        foreach (self::CONTEXT_DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $context) || $context[$key] === null) {
                continue;
            }
            $value = $context[$key];
            if (in_array($key, self::INT_KEYS, true)) {
                $out[$key] = is_numeric($value) ? max(0, (int) $value) : 0;
            } elseif ($key === 'interactive') {
                $out[$key] = $value === true || $value === 1 || $value === '1' || $value === 'Y';
            } elseif ($key === 'secret_values') {
                $values = array_map('strval', array_filter((array) $value, 'is_scalar'));
                $out[$key] = array_values(array_filter($values, 'strlen'));
            } elseif (is_scalar($value)) {
                $out[$key] = substr(trim((string) $value), 0, 128);
            }
        }
        $out['source'] = in_array($out['source'], self::SOURCES, true) ? $out['source'] : 'manual';
        $out['receiver'] = strtoupper(substr($out['receiver'], 0, 15));
        $out['audience'] = $out['audience'] === 'admin' ? 'admin' : 'storefront';

        return $out;
    }

    /**
     * @param string[] $phones
     *
     * @return array<int,array<string,mixed>>
     */
    private function recipients(array $phones): array
    {
        if ($phones === []) {
            $phones = [''];
        }
        $rcpt = [];
        foreach ($phones as $phone) {
            $rcpt[] = [
                'phone_raw' => $phone,
                'phone' => '',
                'valid' => false,
                'invalid_reason' => '',
                'outcome' => null,
                'reason' => '',
                'code' => null,
                'defer' => null,
                'message_id' => 0,
                'outbox_id' => 0,
                'attempt_id' => 0,
            ];
        }

        return $rcpt;
    }

    /**
     * Indexes of recipients without an outcome yet.
     *
     * @param array<int,array<string,mixed>> $rcpt
     *
     * @return int[]
     */
    private function open(array $rcpt): array
    {
        $open = [];
        foreach ($rcpt as $i => $r) {
            if ($r['outcome'] === null) {
                $open[] = $i;
            }
        }

        return $open;
    }

    /**
     * @param array<int,array<string,mixed>> $rcpt
     */
    private function decideAll(array &$rcpt, string $outcome, string $reason, ?string $code = null): void
    {
        foreach ($this->open($rcpt) as $i) {
            $rcpt[$i] = $this->decided($rcpt[$i], $outcome, $reason, $code);
        }
    }

    /**
     * @param array<string,mixed> $r
     *
     * @return array<string,mixed>
     */
    private function decided(array $r, string $outcome, string $reason, ?string $code = null): array
    {
        $r['outcome'] = $outcome;
        $r['reason'] = $reason;
        $r['code'] = $code;

        return $r;
    }

    /**
     * @param array<string,mixed> $r
     *
     * @return array<string,mixed>
     */
    private function deferred(array $r, string $reason, int $retry_at, ?string $code = null, bool $held = false): array
    {
        $suffix = $code !== null && strpos($reason, ':') === false ? ': ' . $code : '';
        $r = $this->decided($r, 'deferred', $reason . $suffix, $code);
        $r['defer'] = ['reason' => $reason, 'retry_at' => $retry_at, 'held' => $held];

        return $r;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function client(array $context): ApiClient
    {
        if ($this->client === null) {
            $timeout = !$this->isWebRequest()
                ? 'cli'
                : ($context['interactive'] && $context['audience'] === 'admin' ? 'admin' : 'web');
            $this->client = call_user_func($this->client_factory, $timeout);
        }

        return $this->client;
    }

    /**
     * The client for a public call: the given timeout context, else the client of the current send, else the
     * default of this request (cli or web). 2026-10-21 01:04 +03: added for refreshBalance() / probeCredentials(),
     * task 596.
     */
    private function clientFor(string $timeout_context): ApiClient
    {
        if ($timeout_context !== '') {
            return call_user_func($this->client_factory, $timeout_context);
        }

        return $this->client ?? $this->client(['interactive' => false, 'audience' => 'storefront']);
    }

    /**
     * Tries already made for the outbox row being drained (0 for a new send).
     *
     * @param array<string,mixed> $context
     */
    private function retryAttempts(array $context): int
    {
        // 2026-10-21 01:04 +03: the claimed row of this send (no second read), task 596.
        unset($context);

        return $this->row === null ? 0 : (int) $this->row['attempts'] + 1;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function countryHint(array $context): string
    {
        $hint = $context['country_iso'] !== '' ? PhoneNormalizer::callingCodeForIso($context['country_iso']) : null;

        return $hint !== null && $hint !== '' ? $hint : $this->config->defaultCountryCode();
    }

    /**
     * @param string[] $coverage
     */
    private function covered(string $phone, array $coverage): bool
    {
        foreach ($coverage as $prefix) {
            if (strncmp($phone, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }

        return false;
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

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * @param array<int,array<string,mixed>> $rcpt
     */
    private function stage(string $name, array $rcpt): void
    {
        $now = microtime(true);
        $this->stages[$name] = [
            'ms' => (int) round(($now - $this->stage_started) * 1000),
            'decided' => count($rcpt) - count($this->open($rcpt)),
        ];
        $this->stage_started = $now;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function note(string $level, string $action, string $message, array $context = [], ?int $ms = null): void
    {
        $this->log->log($level, $action, $message, $context, $ms);
    }

    private function noteThrowable(string $action, \Throwable $e): void
    {
        $where = basename($e->getFile()) . ':' . $e->getLine();
        $this->note('error', $action, get_class($e) . ': ' . $e->getMessage() . ' at ' . $where);
    }

    /** Suspended sends wait the longest back-off; the probe that ends a suspension runs every 15 min (1.7). */
    private function suspendedRetry(): int
    {
        return $this->now() + Outbox::backoff(count(Outbox::BACKOFF));
    }

    /**
     * @param array<string,mixed> $balance A balance() result with result OK
     */
    private function cacheBalance(array $balance): void
    {
        $purchased = $balance['purchased'] === null ? null : (float) $balance['purchased'];
        $this->state->setBalance((float) $balance['available'], $purchased);
    }
}
