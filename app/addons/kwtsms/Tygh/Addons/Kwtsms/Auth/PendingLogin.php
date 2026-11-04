<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Auth/PendingLogin.php
 * CREATED:   2026-11-04 22:38 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The session state between a correct password and a correct code (P3-51): start(), get(), clear(),
 *            countSubmission(), markSent(), and the done mark that lets the one fn_login_user() call of the completion
 *            pass the login hook (markDone, isDone, useDone).
 * WHY:       Task M2.2a (SafatMall B3). The state lives only in the session of the browser that gave the password; it
 *            holds the user id, the area, the account type and the password change time (so a password change in
 *            between kills it), lives 600 s, takes 5 code submissions and is replaced by every new password login.
 *            Nothing here logs anybody in.
 * USAGE:     $pending = Tygh::$app['kwtsms.twofa_pending'];
 *            $pending->start($user, 'A', false, 'admin.php?dispatch=orders.manage');
 *            $state = $pending->get();     // null when absent or past expires_at (then it is also removed)
 *            $pending->countSubmission();   // 1, 2 ... after the 5th the caller clears (MAX_SUBMISSIONS)
 *            $pending->markDone($uid, 300);  $pending->useDone($uid);  // true once
 *            Session keys: kwtsms_2fa (state), kwtsms_2fa_done[<user id>] = expiry time. Test seams: the session
 *            (\ArrayAccess) and the clock.
 * DEPENDS:   Tygh::$app['session'] (ArrayAccess) unless one is given.
 */

namespace Tygh\Addons\Kwtsms\Auth;

class PendingLogin
{
    public const STATE_KEY = 'kwtsms_2fa';
    public const DONE_KEY = 'kwtsms_2fa_done';
    public const LIFETIME = 600;
    public const MAX_SUBMISSIONS = 5;

    /** @var \ArrayAccess|null */
    private $session;

    /** @var callable|null */
    private $clock;

    public function __construct(?\ArrayAccess $session = null, ?callable $clock = null)
    {
        $this->session = $session;
        $this->clock = $clock;
    }

    /**
     * A new pending state for $user (a users row), replacing any other.
     *
     * @param array<string,mixed> $user
     * @param bool                $hook True when a fn_login_user() path started it (before_dispatch then redirects)
     * @param string              $requirement code | enroll
     */
    public function start(
        array $user,
        string $area,
        bool $remember,
        string $return_url,
        bool $hook = false,
        string $requirement = 'code'
    ): void {
        $now = $this->now();
        $this->write(self::STATE_KEY, [
            'user_id' => (int) ($user['user_id'] ?? 0),
            'area' => $area,
            'account_type' => (string) ($user['user_type'] ?? ''),
            'password_change_timestamp' => (int) ($user['password_change_timestamp'] ?? 0),
            'started_at' => $now,
            'expires_at' => $now + self::LIFETIME,
            'remember' => $remember,
            'return_url' => $return_url,
            'sent' => false,
            'hook' => $hook,
            'requirement' => $requirement === 'enroll' ? 'enroll' : 'code',
            'submissions' => 0,
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(): ?array
    {
        $state = $this->read(self::STATE_KEY);
        if (!is_array($state) || (int) ($state['user_id'] ?? 0) <= 0) {
            return null;
        }
        if ($this->now() >= (int) ($state['expires_at'] ?? 0)) {
            $this->forget(self::STATE_KEY);

            return null;
        }

        return $state;
    }

    /** Counts one code submission; 0 when there is no state. */
    public function countSubmission(): int
    {
        $state = $this->get();
        if ($state === null) {
            return 0;
        }
        $state['submissions'] = (int) ($state['submissions'] ?? 0) + 1;
        $this->write(self::STATE_KEY, $state);

        return $state['submissions'];
    }

    public function markSent(): void
    {
        $state = $this->get();
        if ($state !== null) {
            $state['sent'] = true;
            $this->write(self::STATE_KEY, $state);
        }
    }

    public function clear(): void
    {
        $this->forget(self::STATE_KEY);
    }

    /** Lets one fn_login_user() of this user through the hook for $seconds. */
    public function markDone(int $user_id, int $seconds): void
    {
        if ($user_id <= 0 || $seconds <= 0) {
            return;
        }
        $all = $this->doneMarks();
        $all[$user_id] = $this->now() + $seconds;
        $this->write(self::DONE_KEY, $all);
    }

    public function isDone(int $user_id): bool
    {
        $all = $this->doneMarks();

        return $user_id > 0 && isset($all[$user_id]) && (int) $all[$user_id] > $this->now();
    }

    /** True once: the mark is used up. */
    public function useDone(int $user_id): bool
    {
        $ok = $this->isDone($user_id);
        $all = $this->doneMarks();
        if (isset($all[$user_id])) {
            unset($all[$user_id]);
            if ($all === []) {
                $this->forget(self::DONE_KEY);
            } else {
                $this->write(self::DONE_KEY, $all);
            }
        }

        return $ok;
    }

    /**
     * @return array<int,int>
     */
    private function doneMarks(): array
    {
        $all = $this->read(self::DONE_KEY);

        return is_array($all) ? $all : [];
    }

    /**
     * @return mixed
     */
    private function read(string $key)
    {
        $session = $this->session();

        return isset($session[$key]) ? $session[$key] : null;
    }

    /**
     * @param mixed $value
     */
    private function write(string $key, $value): void
    {
        $session = $this->session();
        $session[$key] = $value;
    }

    private function forget(string $key): void
    {
        $session = $this->session();
        unset($session[$key]);
    }

    private function session(): \ArrayAccess
    {
        return $this->session ?? \Tygh\Tygh::$app['session'];
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }
}
