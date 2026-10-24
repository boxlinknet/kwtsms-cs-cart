<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Antibot/SuspicionGate.php
 * CREATED:   2026-10-24 23:49 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      "Only when suspicious" CAPTCHA mode of the SMS code request: requiresToken() counts the request against
 *            three counters (this IP in an hour, codes issued for this phone in the last hour, the whole store in 10
 *            minutes) and says whether a CAPTCHA token is needed; verify() checks a token with the Turnstile driver.
 * WHY:       Task M1.8a (SafatMall E2, rulings P3-8, P3-22). Only our own Turnstile widget can be shown on demand, so
 *            the mode exists only while Turnstile is the active antibot driver; with any other provider (or none) the
 *            request is only rate limited. P3-22: the counters count the current request, so with captcha_after_ip 3
 *            requests 1 to 3 in the hour need no token and the 4th does.
 * USAGE:     $gate = Tygh::$app['kwtsms.suspicion_gate'];
 *            if ($gate->requiresToken($ip, '+96550123401') && !$gate->verify($_REQUEST, $ip)) { ... answer captcha }
 *            requiresToken(): buckets ip_s:<ip or /64> (3600 s) and store_s (600 s) through RateLimiter::hit(); the
 *            subject count is a read of kwtsms_otp_codes (purpose in the code purposes, subject, last 3600 s) + 1.
 *            All three are taken on every call (no short circuit), a subject '' reads no codes, and a counter that
 *            fails closed (PHP_INT_MAX) is suspicious. false and nothing counted when Turnstile is not the driver.
 *            verify(): the Turnstile driver's validateHttpRequest() directly (no session skip), false without a
 *            driver. Test seams (protected): issuedCount(), turnstileActive(), driver().
 * DEPENDS:   Security\RateLimiter, Security\ClientIp, Settings\ConfigInterface (captcha_after_*), Antibot\
 *            TurnstileDriver, table ?:kwtsms_otp_codes (KEY purpose_subject).
 */

namespace Tygh\Addons\Kwtsms\Antibot;

use Tygh\Addons\Kwtsms\Security\ClientIp;
use Tygh\Addons\Kwtsms\Security\RateLimiter;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Web\Antibot\IAntibotDriver;

class SuspicionGate
{
    /** The purposes the code request serves (the index prefix of the subject read). */
    public const PURPOSES = ['register', 'phone_change'];

    public const IP_WINDOW = 3600;
    public const SUBJECT_WINDOW = 3600;
    public const STORE_WINDOW = 600;

    /** @var RateLimiter */
    private $limiter;

    /** @var ConfigInterface */
    private $config;

    /** @var callable|null */
    private $clock;

    public function __construct(RateLimiter $limiter, ConfigInterface $config, ?callable $clock = null)
    {
        $this->limiter = $limiter;
        $this->config = $config;
        $this->clock = $clock;
    }

    /**
     * Counts this request and says whether it needs a CAPTCHA token (P3-22).
     */
    public function requiresToken(string $ip, string $subject): bool
    {
        if (!$this->turnstileActive()) {
            return false;
        }
        $now = $this->clock === null ? $this->limiter->now() : (int) call_user_func($this->clock);
        // hit() answers PHP_INT_MAX when it could not count: that is over every threshold.
        $ip_hits = $this->limiter->hit('ip_s:' . ClientIp::bucketKey($ip), self::IP_WINDOW);
        $by_ip = $ip_hits > $this->config->captchaAfterIp();
        $by_subject = $subject !== ''
            && $this->issuedCount($subject, $now - self::SUBJECT_WINDOW) + 1 > $this->config->captchaAfterSubject();
        $by_store = $this->limiter->hit('store_s', self::STORE_WINDOW) > $this->config->captchaAfterStore();

        $suspicious = $by_ip || $by_subject || $by_store;
        // 2026-10-24 23:55 +03: Task M1.8a (SafatMall E2), task 596. A hook for the dev guard test add-on
        // (kwtsms_code_suspicion): a store test forces "suspicious" without sending 4 requests. No handler, no effect.
        if (function_exists('fn_set_hook')) {
            fn_set_hook('kwtsms_code_suspicion', $suspicious, $ip, $subject);
        }

        return $suspicious;
    }

    /**
     * A token check with the Turnstile driver itself, with no session skip. False when there is no driver.
     *
     * @param array<string,mixed> $request
     */
    public function verify(array $request, string $ip): bool
    {
        $driver = $this->driver();
        if ($driver === null) {
            return false;
        }

        return (bool) $driver->validateHttpRequest($request);
    }

    /**
     * Codes issued for the subject since $since (any status). Test seam.
     */
    protected function issuedCount(string $subject, int $since): int
    {
        return (int) db_get_field(
            'SELECT COUNT(*) FROM ?:kwtsms_otp_codes WHERE purpose IN (?a) AND subject = ?s AND created_at >= ?i',
            self::PURPOSES,
            $subject,
            max(0, $since)
        );
    }

    /**
     * True when the active core antibot driver is ours. Test seam.
     */
    protected function turnstileActive(): bool
    {
        try {
            $app = \Tygh\Tygh::$app;

            return isset($app['antibot']) && $app['antibot']->getDriver() instanceof TurnstileDriver;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The driver that checks the token. Test seam.
     */
    protected function driver(): ?IAntibotDriver
    {
        try {
            $app = \Tygh\Tygh::$app;
            if (!isset($app['antibot'])) {
                return null;
            }
            $driver = $app['antibot']->getDriver();

            return $driver instanceof TurnstileDriver ? $driver : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
