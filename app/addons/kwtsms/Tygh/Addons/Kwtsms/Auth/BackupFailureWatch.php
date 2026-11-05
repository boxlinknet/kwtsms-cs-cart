<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Auth/BackupFailureWatch.php
 * CREATED:   2026-11-05 11:18 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Counts the failed backup-code attempts of one account over an hour and raises the twofa_backup_guessing
 *            alert (bell, once per hour per account) when the count reaches THRESHOLD (10).
 * WHY:       Task M2.2b fix 1 (review I2, ruling: backup codes do not share the account-wide SMS lockout). The backup
 *            path is limited per address and account (OtpService::verifyWith, purpose 2fa_backup) and never locks the
 *            account; a guesser spread over many addresses is therefore made visible to the administrators instead.
 * USAGE:     $watch->failed($user_id);    // after a wrong or refused backup code; true when the alert was raised now
 * DEPENDS:   Security\RateLimiter (hit); Alerts\AdminAlerts::raise() through Tygh::$app['kwtsms.alerts'] or the $alert
 *            seam: callable ($condition, array $params, bool $email, int $every, string $instance).
 */

namespace Tygh\Addons\Kwtsms\Auth;

use Tygh\Addons\Kwtsms\Security\RateLimiter;

class BackupFailureWatch
{
    /** Failed backup attempts of one account within WINDOW that raise the alert. */
    public const THRESHOLD = 10;

    /** Seconds of the counting window (and of the alert dedupe). */
    public const WINDOW = 3600;

    /** @var RateLimiter */
    private $limiter;

    /** @var callable|null */
    private $alert;

    public function __construct(RateLimiter $limiter, ?callable $alert = null)
    {
        $this->limiter = $limiter;
        $this->alert = $alert;
    }

    /**
     * One failed backup attempt of the account. Never throws.
     *
     * @return bool True when the alert was raised by this call
     */
    public function failed(int $user_id): bool
    {
        try {
            $n = $this->limiter->hit('twofa_bkfail:' . $user_id, self::WINDOW);
            if ($n !== self::THRESHOLD) {
                return false;
            }
            $params = ['[user]' => (string) $user_id];
            if ($this->alert !== null) {
                call_user_func($this->alert, 'twofa_backup_guessing', $params, false, self::WINDOW, (string) $user_id);
            } else {
                \Tygh\Tygh::$app['kwtsms.alerts']->raise('twofa_backup_guessing', $params, false, self::WINDOW, (string) $user_id);
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
