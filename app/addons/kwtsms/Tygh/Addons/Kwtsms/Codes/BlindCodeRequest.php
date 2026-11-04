<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Codes/BlindCodeRequest.php
 * CREATED:   2026-11-04 19:10 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The blind SMS code request for the purposes login and recover (P3-42, P3-43, P3-44, P3-45): handle()
 *            answers before any account lookup, and the answer is byte-identical for every valid phone; afterResponse()
 *            then decides whether the phone can receive a code (eligibleOwner()) and only then issues and sends it,
 *            silently. finishInline() is the host without a finish function: the same work in the request, the answer
 *            padded to 3000 ms.
 * WHY:       Task M2.1 (SafatMall B2, review focus: account enumeration by body, status, headers, cookies and timing).
 *            A normal code request says "sent" only when it sent; a sign-in request must not tell whether a number has
 *            an account, so nothing before the response may depend on the phone's account.
 * USAGE:     $blind = Tygh::$app['kwtsms.blind_code_request'];
 *            $body = $blind->handle('login', $raw_phone, $_REQUEST, $session_hash, $ip, $storefront_id, 'en', false);
 *            // before the response, the same for every valid phone: per-IP buckets ip_h and ip_d, phone format and
 *            // country (C3), the CAPTCHA gate, store availability. Bodies:
 *            //   neutral  {ok:true, retry_after:<w>, expires_in:<s>, message}   w = the requester's own session
 *            //            sequence (session key kwtsms_blind_step[<purpose>]: 60, 120, 300 ...), never the phone's
 *            //   wait     {ok:false, error:"wait", retry_after, message}        (IP cap)
 *            //   invalid | country | captcha | unavailable  {ok:false, error, message}   (never bypass, P3-45)
 *            $blind->mode();                       // fpm | litespeed | inline (never "cron": code SMS do not wait)
 *            $blind->afterResponse();              // fpm / litespeed: in a shutdown function, after finish()
 *            $blind->finishInline($timing);        // inline: afterResponse() then CodeTiming::pad(FLOOR_MS)
 *            $blind->eligibleOwner($phone, 'login', $company_scope);   // P3-44; 0 = nobody (never says why)
 *            Every refusal after the response is silent: a debug row without phone or code, no SMS, nothing visible.
 *            Test seams (constructor): $owner_reader (int $user_id): array{user_type,status,company_id,twofa} (default
 *            the users row and kwtsms_twofa); $session (?array $write): array, the kwtsms_blind_step value read, or
 *            written when an array is given (default the Tygh session); $clock.
 * DEPENDS:   Settings\ConfigInterface, Security\{RateLimiter,ClientIp}, Antibot\SuspicionGate, Phones\{PhoneRules,
 *            PhoneIndex}, Codes\{CodeAvailability,OtpService,OtpResult,CodeSender,CodeRequest}, Orders\AfterResponse,
 *            Log\DebugLog (optional), __().
 */

namespace Tygh\Addons\Kwtsms\Codes;

use Tygh\Addons\Kwtsms\Antibot\SuspicionGate;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Orders\AfterResponse;
use Tygh\Addons\Kwtsms\Phones\PhoneIndex;
use Tygh\Addons\Kwtsms\Phones\PhoneRules;
use Tygh\Addons\Kwtsms\Security\ClientIp;
use Tygh\Addons\Kwtsms\Security\RateLimiter;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

class BlindCodeRequest
{
    /** The session key of the requester's own resend sequence: [purpose] => {count, last}. */
    public const STEP_KEY = 'kwtsms_blind_step';

    /** The purposes this class serves. */
    public const PURPOSES = [CodeRequest::PURPOSE_LOGIN, CodeRequest::PURPOSE_RECOVER];

    /** @var ConfigInterface */
    private $config;

    /** @var RateLimiter */
    private $limiter;

    /** @var SuspicionGate */
    private $gate;

    /** @var PhoneRules */
    private $rules;

    /** @var PhoneIndex */
    private $index;

    /** @var CodeAvailability */
    private $availability;

    /** @var OtpService */
    private $otp;

    /** @var CodeSender */
    private $sender;

    /** @var AfterResponse */
    private $after;

    /** @var DebugLog|null */
    private $log;

    /** @var callable|null */
    private $owner_reader;

    /** @var callable|null */
    private $session;

    /** @var callable|null */
    private $clock;

    /** @var array<string,mixed>|null The work after the response; set only by an answer that is the neutral body */
    private $pending;

    /**
     * @param callable|null $owner_reader (int $user_id): array{user_type,status,company_id,twofa}; null = database
     * @param callable|null $session      (?array $write): array; null = the Tygh session
     * @param callable|null $clock        Returns the Unix time; null = the limiter's clock
     */
    public function __construct(
        ConfigInterface $config,
        RateLimiter $limiter,
        SuspicionGate $gate,
        PhoneRules $rules,
        PhoneIndex $index,
        CodeAvailability $availability,
        OtpService $otp,
        CodeSender $sender,
        AfterResponse $after,
        ?DebugLog $log = null,
        ?callable $owner_reader = null,
        ?callable $session = null,
        ?callable $clock = null
    ) {
        $this->config = $config;
        $this->limiter = $limiter;
        $this->gate = $gate;
        $this->rules = $rules;
        $this->index = $index;
        $this->availability = $availability;
        $this->otp = $otp;
        $this->sender = $sender;
        $this->after = $after;
        $this->log = $log;
        $this->owner_reader = $owner_reader;
        $this->session = $session;
        $this->clock = $clock;
    }

    /**
     * The answer, decided before any account lookup (P3-42). Order: per-IP buckets, phone format and country, the
     * CAPTCHA gate, store availability, then the neutral body. Remembers the work for afterResponse().
     *
     * @param array<string,mixed> $request The posted fields (the CAPTCHA token is read from it)
     *
     * @return array<string,mixed> The JSON body
     */
    public function handle(
        string $purpose,
        string $raw_phone,
        array $request,
        string $session_hash,
        string $ip,
        int $storefront_id,
        string $lang,
        bool $captcha_checked
    ): array {
        $this->pending = null;
        if (!in_array($purpose, self::PURPOSES, true)) {
            return $this->failure('unavailable', (string) __('kwtsms.code.unavailable', [], $lang));
        }

        // 1. Per-IP buckets (P3-6, P3-35), the same as CodeRequest: every request counts, a counter that fails closed
        // is over the limit.
        $ip_part = ClientIp::bucketKey($ip);
        $buckets = [
            ['ip_h', CodeRequest::HOUR, $this->config->capIpHour()],
            ['ip_d', CodeRequest::DAY, $this->config->capIpDay()],
        ];
        foreach ($buckets as $b) {
            $bucket = $b[0] . ':' . $ip_part;
            if ($this->limiter->hit($bucket, $b[1]) > $b[2]) {
                $this->debug('otp.ip_limited', ['bucket' => $b[0], 'ip' => $ip_part, 'purpose' => $purpose]);

                return $this->wait($this->windowLeft($bucket, $b[1]), $lang);
            }
        }

        // 2. The phone, judged after the gate (a bot learns nothing about a number it cannot pass).
        $hint = CodeRequest::countryHint($request, '', $this->config->defaultCountryCode());
        $check = $this->rules->check($raw_phone, $hint);
        $phone = $check['ok'] ? $check['phone'] : '';

        // 3. Suspicion: a CAPTCHA token from the 4th request of an IP, the 3rd code of a phone, the 31st of the store.
        if (!$captcha_checked && $this->gate->requiresToken($ip, $phone) && !$this->gate->verify($request, $ip)) {
            return $this->failure('captcha', (string) __('kwtsms.code.captcha', [], $lang));
        }

        // 4. A valid, allowed mobile (the C3 texts say nothing about accounts).
        if (!$check['ok']) {
            return $this->failure(
                $check['reason'] === PhoneRules::REASON_COUNTRY ? 'country' : 'invalid',
                $this->rules->message($check['reason'], $this->config->customerSmsCountries(), $lang)
            );
        }
        if ($phone === '') {
            return $this->failure('invalid', $this->rules->message(PhoneRules::REASON_INVALID, [], $lang));
        }

        // 5. Can a code SMS leave at all? P3-45: for these purposes never a bypass (the alert still fires in gate()).
        $gate = $this->availability->gate($storefront_id);
        if ($gate['action'] !== 'send') {
            return $this->failure('unavailable', (string) __('kwtsms.code.unavailable', [], $lang));
        }

        // 6. The neutral answer. Nothing above or below reads the account of the phone.
        $this->pending = [
            'purpose' => $purpose,
            'phone' => $phone,
            'session_hash' => $session_hash,
            'ip' => $ip,
            'storefront_id' => $storefront_id,
            'lang' => $lang,
        ];

        return [
            'ok' => true,
            'retry_after' => $this->nextWait($purpose),
            'expires_in' => $this->config->otpExpirySeconds(),
            'message' => (string) __('kwtsms.code.blind_' . $purpose, [], $lang),
        ];
    }

    /**
     * fpm, litespeed or inline. The cron mode of AfterResponse is inline here: a code SMS never waits for the cron.
     */
    public function mode(): string
    {
        $mode = $this->after->mode(['area' => 'C']);

        return AfterResponse::afterResponse($mode) ? $mode : AfterResponse::INLINE;
    }

    /**
     * The work after the response (P3-42): eligibility, per-phone cap, issue, send. Never throws, never says anything:
     * every refusal is a debug row (no phone, no code). Runs once per handle().
     */
    public function afterResponse(): void
    {
        $p = $this->pending;
        $this->pending = null;
        if ($p === null) {
            return;
        }
        $issued = null;
        try {
            $owner = $this->eligibleOwner((string) $p['phone'], (string) $p['purpose'], $this->companyScope());
            if ($owner <= 0) {
                $this->debug('otp.blind_request', ['purpose' => $p['purpose'], 'result' => 'no_owner']);

                return;
            }
            // The per-phone hourly cap (P3-34), read only: a code that goes out is counted below.
            $stat = $this->limiter->peek('phone_h:' . $p['phone']);
            $window_left = (int) $stat['window_start'] + CodeRequest::HOUR - $this->now();
            if ((int) $stat['hits'] >= $this->config->capPhoneHour() && $window_left > 0) {
                $this->debug('otp.blind_request', ['purpose' => $p['purpose'], 'result' => 'phone_cap']);

                return;
            }
            $issued = $this->otp->issue(
                (string) $p['purpose'],
                (string) $p['phone'],
                $owner,
                (string) $p['session_hash'],
                (string) $p['ip'],
                (int) $p['storefront_id']
            );
            if ($issued->status !== OtpResult::ISSUED) {
                $this->debug('otp.blind_request', ['purpose' => $p['purpose'], 'result' => 'issue_' . $issued->status]);

                return;
            }
            $sent = $this->sender->sendCode(
                (string) $p['phone'],
                $issued->code(),
                (string) $p['purpose'],
                (string) $p['lang'],
                (int) $p['storefront_id'],
                $owner,
                (string) $p['ip']
            );
            $outcome = (string) ($sent['outcome'] ?? 'failed');
            $reason = (string) ($sent['reason'] ?? '');
            $class = $this->availability->classify($outcome, $reason);
            if ($class === CodeAvailability::SENT || $class === CodeAvailability::NUMBER) {
                $this->limiter->hit('phone_h:' . $p['phone'], CodeRequest::HOUR);
                $this->debug('otp.blind_request', ['purpose' => $p['purpose'], 'result' => 'sent']);

                return;
            }
            $this->otp->revoke($issued);
            if ($class !== CodeAvailability::BUSY) {
                $this->availability->alert($this->availability->reasonFor($outcome, $reason));
                if (isset($sent['outbox_id'])) {
                    $this->sender->cancelDeferred((int) $sent['outbox_id']);
                }
            }
            $this->debug('otp.blind_request', ['purpose' => $p['purpose'], 'result' => 'unsendable']);
        } catch (\Throwable $e) {
            try {
                if ($issued !== null) {
                    $this->otp->revoke($issued);
                }
            } catch (\Throwable $inner) {
                // The code expires by itself; nothing more can be done here.
                unset($inner);
            }
            // Class name only: the message may carry a number.
            if ($this->log !== null) {
                $this->log->log('error', 'otp.blind_failed', 'the code work after the response failed', [
                    'purpose' => $p['purpose'],
                    'error' => get_class($e),
                ]);
            }
        }
    }

    /**
     * Host without a finish function: the work in the request, then the answer padded to the floor (P3-43). A branch
     * that overran the floor is logged (no phone).
     */
    public function finishInline(CodeTiming $timing): void
    {
        $purpose = $this->pending['purpose'] ?? '';
        $this->afterResponse();
        $timing->pad(CodeTiming::FLOOR_MS);
        if ($timing->overran()) {
            $this->debug('otp.timing_overrun', ['purpose' => $purpose, 'elapsed_ms' => $timing->elapsedMs()]);
        }
    }

    /**
     * Who can receive a code for this phone (P3-44): the one account whose VERIFIED number it is, that no other
     * account indexes, an active customer, of the storefront's company when the store scopes users, and for sign-in
     * without a 2FA requirement. 0 = nobody; the reason is never told (and a failed read is nobody).
     *
     * @param int $storefront_company_id -1 = no company check (Multi-Vendor, shared users)
     */
    public function eligibleOwner(string $phone, string $purpose, int $storefront_company_id): int
    {
        if ($phone === '') {
            return 0;
        }
        try {
            $owner = $this->index->verifiedOwner($phone);
            if ($owner <= 0 || $this->index->ownerOf($phone, $owner) > 0) {
                return 0;
            }
            $user = $this->readOwner($owner);
            if (($user['user_type'] ?? '') !== 'C' || ($user['status'] ?? '') !== 'A') {
                return 0;
            }
            if ($storefront_company_id >= 0 && (int) ($user['company_id'] ?? -1) !== $storefront_company_id) {
                return 0;
            }
            if ($purpose === CodeRequest::PURPOSE_LOGIN && !empty($user['twofa'])) {
                return 0;
            }

            return $owner;
        } catch (\Throwable $e) {
            $this->debug('otp.blind_request', ['purpose' => $purpose, 'result' => 'eligibility_error']);

            return 0;
        }
    }

    /**
     * 2026-11-04 19:12 +03: Task M2.1, task 596. The company scope of this request (a test seam over
     * storefrontCompanyId()).
     */
    public function companyScope(): int
    {
        return self::storefrontCompanyId();
    }

    /**
     * The company condition of fn_login_user() (fn.users.php:3559-3563): Ultimate with share_users = N and a
     * storefront request needs the user of this storefront's company; every other setup has no company condition (-1).
     */
    public static function storefrontCompanyId(): int
    {
        if (!function_exists('fn_allowed_for') || !class_exists('Tygh\\Registry')) {
            return -1;
        }
        if (fn_allowed_for('ULTIMATE') && \Tygh\Registry::get('settings.Stores.share_users') == 'N') {
            return (int) \Tygh\Registry::get('runtime.company_id');
        }

        return -1;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The wait of the requester's own sequence (P3-42): 60, 120, 300 ... by the number of valid requests of this
     * session and purpose; the sequence starts again after an idle hour. Never a function of the phone.
     */
    private function nextWait(string $purpose): int
    {
        $waits = array_values(array_filter(
            array_map('intval', $this->config->otpResendWaits()),
            static function (int $wait): bool {
                return $wait > 0;
            }
        ));
        $waits = $waits === [] ? [60] : $waits;
        $all = $this->readSteps();
        $step = isset($all[$purpose]) && is_array($all[$purpose]) ? $all[$purpose] : [];
        $count = (int) ($step['count'] ?? 0);
        $now = $this->now();
        if ($count > 0 && $now - (int) ($step['last'] ?? 0) > OtpService::SEND_IDLE_SECONDS) {
            $count = 0;
        }
        $count++;
        $all[$purpose] = ['count' => $count, 'last' => $now];
        $this->writeSteps($all);

        return $waits[min($count, count($waits)) - 1];
    }

    /**
     * @return array<string,mixed>
     */
    private function readSteps(): array
    {
        if ($this->session !== null) {
            return (array) call_user_func($this->session, null);
        }
        $session = \Tygh\Tygh::$app['session'];

        return isset($session[self::STEP_KEY]) && is_array($session[self::STEP_KEY]) ? $session[self::STEP_KEY] : [];
    }

    /**
     * @param array<string,mixed> $all
     */
    private function writeSteps(array $all): void
    {
        if ($this->session !== null) {
            call_user_func($this->session, $all);

            return;
        }
        $session = \Tygh\Tygh::$app['session'];
        $session[self::STEP_KEY] = $all;
    }

    /**
     * @return array<string,mixed>
     */
    private function readOwner(int $user_id): array
    {
        if ($this->owner_reader !== null) {
            return (array) call_user_func($this->owner_reader, $user_id);
        }
        $row = db_get_row('SELECT user_type, status, company_id FROM ?:users WHERE user_id = ?i', $user_id);
        if (!is_array($row) || $row === []) {
            return [];
        }
        $enabled = db_get_field('SELECT enabled FROM ?:kwtsms_twofa WHERE user_id = ?i', $user_id);
        $row['twofa'] = (string) $enabled === 'Y';

        return $row;
    }

    /**
     * @return array<string,mixed>
     */
    private function wait(int $seconds, string $lang): array
    {
        $seconds = max(1, $seconds);

        return [
            'ok' => false,
            'error' => 'wait',
            'retry_after' => $seconds,
            'message' => (string) __('kwtsms.code.wait', ['[seconds]' => (int) $seconds], $lang),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function failure(string $error, string $message): array
    {
        return ['ok' => false, 'error' => $error, 'message' => $message];
    }

    private function windowLeft(string $bucket, int $window_s): int
    {
        try {
            $start = (int) $this->limiter->peek($bucket)['window_start'];
        } catch (\Throwable $e) {
            return $window_s;
        }

        return $start <= 0 ? $window_s : max(1, $start + $window_s - $this->now());
    }

    private function now(): int
    {
        return $this->clock === null ? $this->limiter->now() : (int) call_user_func($this->clock);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function debug(string $action, array $context): void
    {
        if ($this->log !== null) {
            $this->log->log('debug', $action, $action, $context);
        }
    }
}
