<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Codes/OtpService.php
 * CREATED:   2026-10-23 21:27 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The SMS code engine: issue() draws a 6-digit code, stores only its keyed hash and claims the next resend
 *            step; verify() counts the attempt atomically before it compares, locks after too many wrong ones, and
 *            marks the code verified; hasVerified() / consume() let a form use a verified code once; revoke() gives
 *            a code back when its SMS could not be sent; pruneCodes() deletes old code rows (the cron).
 * WHY:       Task M1.3 (SafatMall D1, D2, D3, D4; rulings P3-1 to P3-5, P3-18, P3-19, P3-29). Brute force is stopped by
 *            the database, not by PHP: the attempt number comes from one atomic statement (RateLimiter::hit()) and is
 *            taken BEFORE the comparison, so 20 parallel wrong submissions compare exactly 5 times. The wrong count is
 *            per (purpose, phone, IP) so a third party cannot lock a victim out, with a per-phone ceiling across IPs
 *            and a per-IP verify limit. The code is never stored, logged or returned except in the "issued" result.
 * USAGE:     $otp = Tygh::$app['kwtsms.otp'];
 *            //   or new OtpService($limiter, $hasher, $config, $clock, $random, $log)
 *            $r = $otp->issue('register', '+96550000001', $user_id, hash('sha256', session_id()), $ip, $storefront);
 *            //   ISSUED: send $r->code() in the SMS, or $otp->revoke($r) when it could not be sent
 *            //   WAIT / LOCKED: $r->retry_after seconds
 *            $r = $otp->verify('register', '+96550000001', $typed, $user_id, $session_hash, $ip);
 *            //   OK, WRONG (attempts_left), MALFORMED, LOCKED, EXPIRED, NONE, IP_LIMITED
 *            $otp->hasVerified('register', '+96550000001', $user_id, $session_hash);   // verified and not expired
 *            $otp->consume('register', '+96550000001', $user_id, $session_hash);       // verified -> used, once
 *            $otp->pruneCodes($before, 5000, '');     // cron: rows created before $before, in chunks
 *            Buckets (RateLimiter): otp_send:<purpose>:<phone> (resend steps), otp_fail:<purpose>:<phone>:<ip> (wrong
 *            attempts, window = otp_lockout_seconds), otp_failp:<purpose>:<phone> (per-phone ceiling, 3600 s),
 *            ip_v:<ip> (submissions per IP, 3600 s). The IP is the address as ClientIp resolved it; an IPv6 address is
 *            keyed by its /64. Every time value comes from the injected clock (default: the limiter's clock).
 *            Test seams (protected): run(), field(), row().
 * DEPENDS:   Security\RateLimiter, Codes\CodeHasher, Codes\CodeInput, Codes\OtpResult, Security\ClientIp,
 *            Settings\ConfigInterface, Log\DebugLog (optional); table ?:kwtsms_otp_codes (KEY purpose_subject).
 */

namespace Tygh\Addons\Kwtsms\Codes;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Security\ClientIp;
use Tygh\Addons\Kwtsms\Security\RateLimiter;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

class OtpService
{
    /** Seconds with no code request after which the resend sequence starts again (P3-19). */
    public const SEND_IDLE_SECONDS = 3600;

    /** The window of the per-phone ceiling and of the per-IP verify counter (P3-29). */
    public const HOUR = 3600;

    /** @var RateLimiter */
    private $limiter;

    /** @var CodeHasher */
    private $hasher;

    /** @var ConfigInterface */
    private $config;

    /** @var callable|null */
    private $clock;

    /** @var callable|null */
    private $random;

    /** @var DebugLog|null */
    private $log;

    /**
     * @param callable|null $clock  Returns the Unix time; null = the limiter's clock
     * @param callable|null $random (int $min, int $max): int; null = random_int()
     */
    public function __construct(
        RateLimiter $limiter,
        CodeHasher $hasher,
        ConfigInterface $config,
        ?callable $clock = null,
        ?callable $random = null,
        ?DebugLog $log = null
    ) {
        $this->limiter = $limiter;
        $this->hasher = $hasher;
        $this->config = $config;
        $this->clock = $clock;
        $this->random = $random;
        $this->log = $log;
    }

    /**
     * A new code: 6 ASCII digits from a uniform draw of 0..999999, zero-padded (P3-4).
     */
    public function newCode(): string
    {
        $n = $this->random === null ? random_int(0, 999999) : (int) call_user_func($this->random, 0, 999999);

        return str_pad((string) $n, CodeInput::LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Draws a code and stores its hash. Does not send. Order (task M1.3): lockout check, resend step claim, cancel
     * every active code of this (purpose, subject), insert the new row.
     */
    public function issue(
        string $purpose,
        string $subject,
        int $user_id,
        string $session_hash,
        string $ip,
        int $storefront_id
    ): OtpResult {
        $now = $this->now();
        $ip_part = ClientIp::bucketKey($ip);
        $locked = $this->lockRemaining($purpose, $subject, $ip_part, $now);
        if ($locked > 0) {
            $this->debug('otp.issue.locked', ['purpose' => $purpose]);

            return OtpResult::locked($locked);
        }
        $bucket = self::sendBucket($purpose, $subject);
        $claim = $this->limiter->claimStep($bucket, $this->config->otpResendWaits(), self::SEND_IDLE_SECONDS);
        if (!$claim['ok']) {
            $this->debug('otp.issue.wait', ['purpose' => $purpose, 'retry_after' => $claim['retry_after']]);

            return OtpResult::wait((int) $claim['retry_after']);
        }
        $step = [
            'bucket' => $bucket,
            'prev_hits' => (int) $claim['prev_hits'],
            'prev_last_at' => (int) $claim['prev_last_at'],
            'claimed_at' => (int) $claim['claimed_at'],
        ];
        $code = $this->newCode();
        $salt = $this->hasher->newSalt();
        $expiry = $this->config->otpExpirySeconds();
        try {
            $this->run(
                "UPDATE ?:kwtsms_otp_codes SET status = 'C'"
                . " WHERE purpose = ?s AND subject = ?s AND status IN ('A','V')",
                [$purpose, $subject]
            );
            $code_id = $this->run('INSERT INTO ?:kwtsms_otp_codes ?e', [[
                'purpose' => $purpose,
                'subject' => $subject,
                'user_id' => max(0, $user_id),
                'session_hash' => $session_hash,
                'code_hash' => $this->hasher->hash($purpose, $subject, $code, $salt),
                'salt' => $salt,
                'status' => 'A',
                'created_at' => $now,
                'expires_at' => $now + $expiry,
                'ip' => substr($ip, 0, 45),
                'storefront_id' => max(0, $storefront_id),
            ]]);
        } catch (\Throwable $e) {
            $this->giveBack($step);

            throw $e;
        }
        $this->info('otp.issue', 'code issued', [
            'purpose' => $purpose,
            'code_id' => $code_id,
            'user_id' => $user_id,
            'storefront_id' => $storefront_id,
            'expires_in' => $expiry,
        ]);

        return OtpResult::issued($code, $code_id, $expiry, $step);
    }

    /**
     * The SMS of an issued code could not be sent: cancel that row and give the resend step back (P3-13).
     */
    public function revoke(OtpResult $issued): void
    {
        if ($issued->status !== OtpResult::ISSUED) {
            return;
        }
        $this->run(
            "UPDATE ?:kwtsms_otp_codes SET status = 'C' WHERE code_id = ?i AND status = 'A'",
            [$issued->code_id]
        );
        $this->giveBack($issued->step);
        $this->debug('otp.revoke', ['code_id' => $issued->code_id]);
    }

    /**
     * Checks a typed code. Order (task M1.3): normalize (a malformed entry counts nothing); the per-IP submission
     * counter; the lockouts; the newest code row of this (purpose, subject, session, user); expiry; then the two
     * wrong-attempt counters are taken BEFORE the comparison; a count above the maximum is refused without comparing.
     */
    public function verify(
        string $purpose,
        string $subject,
        string $raw_code,
        int $user_id,
        string $session_hash,
        string $ip
    ): OtpResult {
        $code = CodeInput::normalize($raw_code);
        if ($code === null) {
            return OtpResult::of(OtpResult::MALFORMED);
        }
        $now = $this->now();
        $ip_part = ClientIp::bucketKey($ip);
        $lockout = $this->config->otpLockoutSeconds();

        // hit() answers PHP_INT_MAX when it could not count: that is over every limit.
        if ($this->limiter->hit('ip_v:' . $ip_part, self::HOUR) > $this->config->capIpVerifyHour()) {
            $this->info('otp.ip_limited', 'too many code submissions from one address', [
                'purpose' => $purpose,
                'ip' => $ip_part,
            ]);

            return OtpResult::ipLimited($this->windowLeft('ip_v:' . $ip_part, self::HOUR, $now));
        }
        $locked = $this->lockRemaining($purpose, $subject, $ip_part, $now);
        if ($locked > 0) {
            return OtpResult::locked($locked);
        }
        $row = $this->row(
            'SELECT code_id, code_hash, salt, status, expires_at FROM ?:kwtsms_otp_codes'
            . ' WHERE purpose = ?s AND subject = ?s AND session_hash = ?s AND user_id = ?i'
            . ' ORDER BY code_id DESC LIMIT 1',
            [$purpose, $subject, $session_hash, max(0, $user_id)]
        );
        if ($row === null) {
            // A stranger without a code row of this session spends only its own ip_v budget.
            $this->hasher->dummy($code);

            return OtpResult::of(OtpResult::NONE);
        }
        if ((int) $row['expires_at'] <= $now) {
            return OtpResult::of(OtpResult::EXPIRED);
        }

        $fail_ip = self::failIpBucket($purpose, $subject, $ip_part);
        $fail_phone = self::failPhoneBucket($purpose, $subject);
        $max = $this->config->otpMaxAttempts();
        $n = $this->limiter->hit($fail_ip, $lockout);
        // 2026-10-24 05:56 +03: Task M1.3 fix round 1 (I1), task 596. The per-phone count is taken only for an attempt
        // that is really compared (n <= max): a request refused for its own address's count is not a wrong attempt
        // against the phone, so one address cannot push the phone to the ceiling with a burst of refused requests.
        $m = $n > $max ? 0 : $this->limiter->hit($fail_phone, self::HOUR);
        $ceiling = $this->config->otpPhoneFailCeiling();
        if ($n > $max || $m > $ceiling) {
            // Refused without a comparison. 2026-10-24 05:56 +03: Task M1.3 fix round 1 (I2), task 596: no re-lock
            // here, the ceiling lock below lasts otp_lockout_seconds and clears the count.
            return OtpResult::locked(max($this->lockRemaining($purpose, $subject, $ip_part, $now), 1));
        }

        $match = $this->hasher->matches(
            (string) $row['code_hash'],
            $purpose,
            $subject,
            $code,
            (string) $row['salt']
        );
        if ($match) {
            $won = $this->run(
                "UPDATE ?:kwtsms_otp_codes SET status = 'V' WHERE code_id = ?i AND status = 'A' AND expires_at > ?i",
                [(int) $row['code_id'], $now]
            );
            if ($won === 1) {
                $this->limiter->reset($fail_ip);
                $this->limiter->reset($fail_phone);
                $this->limiter->reset(self::sendBucket($purpose, $subject));
                $this->debug('otp.verified', ['purpose' => $purpose, 'code_id' => (int) $row['code_id']]);

                return OtpResult::of(OtpResult::OK);
            }

            // A cancelled, used or already verified code, or another request verified it first.
            return OtpResult::of(OtpResult::NONE);
        }

        $ceiling_reached = $m >= $ceiling;
        if ($ceiling_reached) {
            // 2026-10-24 05:56 +03: Task M1.3 fix round 1 (I2, P3-R4), task 596: the count restarts when the lock ends.
            $this->limiter->lock($fail_phone, $lockout, true);
        }
        if ($n >= $max) {
            $this->limiter->lock($fail_ip, $lockout);
        }
        if ($ceiling_reached || $n >= $max) {
            $this->info('otp.lockout', 'code lockout set', [
                'purpose' => $purpose,
                'scope' => $ceiling_reached ? 'phone' : 'phone_ip',
                'seconds' => $lockout,
            ]);

            return OtpResult::locked($lockout);
        }

        return OtpResult::wrong($max - (int) $n);
    }

    /**
     * Whether this session holds a verified, unexpired code for the subject (P3-5: the same session may submit the
     * form again without a new code until it expires).
     */
    public function hasVerified(string $purpose, string $subject, int $user_id, string $session_hash): bool
    {
        return (int) $this->field(
            "SELECT code_id FROM ?:kwtsms_otp_codes WHERE purpose = ?s AND subject = ?s AND session_hash = ?s"
            . " AND user_id = ?i AND status = 'V' AND expires_at > ?i ORDER BY code_id DESC LIMIT 1",
            [$purpose, $subject, $session_hash, max(0, $user_id), $this->now()]
        ) > 0;
    }

    /**
     * Uses the verified code up (verified -> used). True once; false when there is none or it was used.
     */
    public function consume(string $purpose, string $subject, int $user_id, string $session_hash): bool
    {
        return $this->run(
            "UPDATE ?:kwtsms_otp_codes SET status = 'U' WHERE purpose = ?s AND subject = ?s AND session_hash = ?s"
            . " AND user_id = ?i AND status = 'V' AND expires_at > ?i ORDER BY code_id DESC LIMIT 1",
            [$purpose, $subject, $session_hash, max(0, $user_id), $this->now()]
        ) === 1;
    }

    /**
     * Deletes one chunk of code rows created before $before (any status: a code lives 15 minutes at most). The cron
     * calls it (prune_logs) with $subject = ''; a test passes its own subject so it never touches a foreign row.
     * 2026-10-23 21:27 +03: moved here from RateLimiter, Task M1.3 (M1.2 review minor 7), task 596.
     *
     * @return int Rows deleted; fewer than $limit means none are left
     */
    public function pruneCodes(int $before, int $limit, string $subject = ''): int
    {
        $sql = 'DELETE FROM ?:kwtsms_otp_codes WHERE created_at < ?i';
        $args = [max(0, $before)];
        if ($subject !== '') {
            $sql .= ' AND subject = ?s';
            $args[] = $subject;
        }

        return $this->run($sql . ' LIMIT ?i', array_merge($args, [max(1, $limit)]));
    }

    // 2026-10-24 05:47 +03: the parameter $ip_key was renamed $ip_part (the release gate reads "key" in a name as a
    // secret), Task M1.3, task 596.
    public static function sendBucket(string $purpose, string $subject): string
    {
        return 'otp_send:' . $purpose . ':' . $subject;
    }

    public static function failIpBucket(string $purpose, string $subject, string $ip_part): string
    {
        return 'otp_fail:' . $purpose . ':' . $subject . ':' . $ip_part;
    }

    public static function failPhoneBucket(string $purpose, string $subject): string
    {
        return 'otp_failp:' . $purpose . ':' . $subject;
    }

    /**
     * Seconds the (phone, IP) pair or the whole phone is locked for (0 = not locked).
     */
    private function lockRemaining(string $purpose, string $subject, string $ip_part, int $now): int
    {
        $until = max(
            $this->limiter->lockedUntil(self::failPhoneBucket($purpose, $subject)),
            $this->limiter->lockedUntil(self::failIpBucket($purpose, $subject, $ip_part))
        );

        return max(0, $until - $now);
    }

    /**
     * Seconds until the fixed window of a bucket ends (at least 1; $window_s when it cannot be read).
     */
    private function windowLeft(string $bucket, int $window_s, int $now): int
    {
        try {
            $start = $this->limiter->peek($bucket)['window_start'];
        } catch (\Throwable $e) {
            return $window_s;
        }

        return max(1, $start + $window_s - $now);
    }

    /**
     * @param array<string,int|string> $step
     */
    private function giveBack(array $step): void
    {
        $this->limiter->undoStep(
            (string) $step['bucket'],
            (int) $step['prev_hits'],
            (int) $step['prev_last_at'],
            (int) $step['claimed_at']
        );
    }

    private function now(): int
    {
        return $this->clock === null ? $this->limiter->now() : (int) call_user_func($this->clock);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function info(string $action, string $message, array $context): void
    {
        if ($this->log !== null) {
            $this->log->log('info', $action, $message, $context);
        }
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

    /**
     * One write statement (a test seam): rows affected for UPDATE and DELETE, the new id for INSERT.
     *
     * @param array<int,mixed> $args
     */
    protected function run(string $sql, array $args): int
    {
        return (int) db_query($sql, ...$args);
    }

    /**
     * One value (a test seam).
     *
     * @param array<int,mixed> $args
     *
     * @return mixed
     */
    protected function field(string $sql, array $args)
    {
        return db_get_field($sql, ...$args);
    }

    /**
     * One row (a test seam).
     *
     * @param array<int,mixed> $args
     *
     * @return array<string,mixed>|null
     */
    protected function row(string $sql, array $args): ?array
    {
        $row = db_get_row($sql, ...$args);

        return is_array($row) && $row !== [] ? $row : null;
    }
}
