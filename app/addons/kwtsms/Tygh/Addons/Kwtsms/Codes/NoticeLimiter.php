<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Codes/NoticeLimiter.php
 * CREATED:   2026-10-29 20:03 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The limits of the "your number was changed" notice to the old phone (branch review I1, ruling P3-R14):
 *            the request that triggers the notice counts against the per-IP hour and day buckets (the same buckets as
 *            a code request), and one account gets at most 1 notice per hour. refusal() returns null when the notice
 *            may go out, else the reason it is skipped ('ip_hour', 'ip_day', 'account_hour'); a skip leaves a debug
 *            log row with the reason and no phone. A counter that cannot be read counts as over the limit.
 * WHY:       A script could register accounts with victims' numbers and clear each phone: one notice SMS per account,
 *            with no per-IP limit and the store daily cap as the only stop.
 * USAGE:     $reason = (new NoticeLimiter($config, $rate_limiter, $debug_log))->refusal($ip, $user_id);
 *            null = send the notice; a string = save the change, skip the notice.
 * DEPENDS:   Settings\ConfigInterface (capIpHour, capIpDay), Security\{RateLimiter,ClientIp}, Log\DebugLog (optional).
 */

namespace Tygh\Addons\Kwtsms\Codes;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Security\ClientIp;
use Tygh\Addons\Kwtsms\Security\RateLimiter;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

class NoticeLimiter
{
    /** @var ConfigInterface */
    private $config;

    /** @var RateLimiter */
    private $limiter;

    /** @var DebugLog|null */
    private $log;

    public function __construct(ConfigInterface $config, RateLimiter $limiter, ?DebugLog $log = null)
    {
        $this->config = $config;
        $this->limiter = $limiter;
        $this->log = $log;
    }

    /**
     * @return string|null null = the notice may go out; else the reason it is skipped
     */
    public function refusal(string $ip, int $user_id): ?string
    {
        $ip_part = ClientIp::bucketKey($ip);
        $reason = null;
        if ($this->limiter->hit('ip_h:' . $ip_part, CodeRequest::HOUR) > $this->config->capIpHour()) {
            $reason = 'ip_hour';
        }
        // Both IP buckets are always hit, like a code request: the notice is one more request of this address.
        if ($this->limiter->hit('ip_d:' . $ip_part, CodeRequest::DAY) > $this->config->capIpDay() && $reason === null) {
            $reason = 'ip_day';
        }
        if ($reason === null && $this->limiter->hit('notice_u:' . $user_id, CodeRequest::HOUR) > 1) {
            $reason = 'account_hour';
        }
        if ($reason !== null && $this->log !== null) {
            try {
                $this->log->log(
                    'info',
                    'phone_changed.notice_skipped',
                    'the old-number notice was skipped (limit)',
                    ['reason' => $reason, 'user_id' => $user_id]
                );
            } catch (\Throwable $e) {
                unset($e); // the log must not break the save
            }
        }

        return $reason;
    }
}
