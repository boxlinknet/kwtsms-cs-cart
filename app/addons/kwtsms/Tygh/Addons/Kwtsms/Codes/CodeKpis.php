<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Codes/CodeKpis.php
 * CREATED:   2026-11-16 23:47 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The SMS-code numbers of the Dashboard: codes sent in the last 30 days, the percent verified, and the
 *            numbers locked now (masked) with the Unlock action. fold() keeps one finished day in the storage key
 *            kwtsms_code_days before the cron prunes the code rows (they live one day).
 * WHY:       Task M3.19 (P3-76, SafatMall G3). The code table is pruned after a day, so a 30-day figure needs a daily
 *            fold; and a customer locked out by wrong codes needs a button, not a database edit.
 * USAGE:     $kpis = new CodeKpis($store, function () use ($otp) { return $otp; }, $log);
 *            $kpis->fold($day_start);                 // cron, before pruning otp_codes; UTC day start, idempotent
 *            $s = $kpis->summary(time());             // ['issued', 'verified_pct', 'locked', 'locked_other']
 *            $n = $kpis->unlock($subject, $admin_id); // rows deleted; 0 = that subject is not locked now
 * DEPENDS:   Storage\KeyValueStoreInterface, Codes\OtpService (lockedSubjects, unlockSubject),
 *            Auth\TwoFactorFlow::mask, Log\DebugLog (optional), tables kwtsms_otp_codes (idx_created_at) and
 *            kwtsms_rate_limits (PK bucket).
 */

namespace Tygh\Addons\Kwtsms\Codes;

use Tygh\Addons\Kwtsms\Auth\TwoFactorFlow;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Storage\KeyValueStoreInterface;

class CodeKpis
{
    /** Storage key: {"YYYY-MM-DD": {issued, verified, by_purpose: {purpose: {issued, verified}}}}. */
    public const KEY = 'kwtsms_code_days';

    /** Days kept in the key (one more than the 30 the Dashboard shows). */
    public const KEEP_DAYS = 31;

    /** Days the Dashboard figures cover, today included. */
    public const WINDOW_DAYS = 30;

    /** Locked numbers listed at most. */
    public const LIST_MAX = 50;

    private const DAY = 86400;

    /** @var KeyValueStoreInterface */
    private $store;

    /** @var callable Returns the OtpService; lazy, because building it needs config.crypt_key and fold() does not */
    private $otp_factory;

    /** @var OtpService|null */
    private $otp;

    /** @var DebugLog|null */
    private $log;

    /** @var callable|null */
    private $clock;

    /** @var string */
    private $secret;

    /**
     * @param callable      $otp_factory Returns the OtpService (called on the first lock read or unlock only)
     * @param callable|null $clock       Returns the Unix time; null = time()
     * @param string        $secret      Key of the unlock token (the page never holds a full number, only its token)
     */
    public function __construct(
        KeyValueStoreInterface $store,
        callable $otp_factory,
        ?DebugLog $log = null,
        ?callable $clock = null,
        string $secret = ''
    ) {
        $this->secret = $secret;
        $this->store = $store;
        $this->otp_factory = $otp_factory;
        $this->log = $log;
        $this->clock = $clock;
    }

    /**
     * Writes the counts of the UTC day that starts at $day_start into the stored days (a count never falls: when part
     * of the day was pruned already, the larger stored number stays) and drops the days older than KEEP_DAYS. Issued
     * = every code row of the day; verified = rows with status V (verified) or U (used).
     */
    public function fold(int $day_start): void
    {
        $day_start = $day_start - ($day_start % self::DAY);
        $days = $this->readDays();
        $key = gmdate('Y-m-d', $day_start);
        $new = ['issued' => 0, 'verified' => 0, 'by_purpose' => []];
        foreach ($this->counts($day_start, $day_start + self::DAY) as $row) {
            $n = (int) $row['n'];
            $purpose = (string) $row['purpose'];
            $ok = $this->isVerified((string) $row['status']);
            $new['issued'] += $n;
            $new['verified'] += $ok ? $n : 0;
            if (!isset($new['by_purpose'][$purpose])) {
                $new['by_purpose'][$purpose] = ['issued' => 0, 'verified' => 0];
            }
            $new['by_purpose'][$purpose]['issued'] += $n;
            $new['by_purpose'][$purpose]['verified'] += $ok ? $n : 0;
        }
        if (isset($days[$key])) {
            $new = $this->larger($days[$key], $new);
        }
        $days[$key] = $new;
        $oldest = gmdate('Y-m-d', $day_start - (self::KEEP_DAYS - 1) * self::DAY);
        foreach (array_keys($days) as $k) {
            if ((string) $k < $oldest) {
                unset($days[$k]);
            }
        }
        ksort($days);
        $this->store->set(self::KEY, (string) json_encode($days));
    }

    /**
     * @return array{issued:int, verified_pct:?float, locked_other:int, unlock_enabled:bool,
     *               locked:array<int,array{masked:string, subject:string, token:string, until:int}>}
     */
    public function summary(int $now): array
    {
        $today = $now - ($now % self::DAY);
        $days = $this->readDays();
        // The code rows still in the table (today, and yesterday until the cron folds it) are counted live; a day in
        // both places takes the larger number, so nothing is counted twice.
        foreach ($this->liveByDay($today - self::DAY, $now + 1) as $key => $live) {
            $days[$key] = isset($days[$key]) ? $this->larger($days[$key], $live) : $live;
        }
        $first = gmdate('Y-m-d', $today - (self::WINDOW_DAYS - 1) * self::DAY);
        $last = gmdate('Y-m-d', $today);
        $issued = 0;
        $verified = 0;
        foreach ($days as $key => $day) {
            if ((string) $key >= $first && (string) $key <= $last) {
                $issued += (int) $day['issued'];
                $verified += (int) $day['verified'];
            }
        }
        $locks = $this->otp()->lockedSubjects($now);
        $subjects = $locks['subjects'];
        arsort($subjects);
        $locked = [];
        foreach ($subjects as $subject => $until) {
            if (count($locked) >= self::LIST_MAX) {
                break;
            }
            $locked[] = [
                'masked' => self::maskSubject((string) $subject),
                'subject' => (string) $subject,
                'token' => $this->token((string) $subject),
                'until' => (int) $until,
            ];
        }

        return [
            'issued' => $issued,
            'verified_pct' => $issued > 0 ? round($verified * 100 / $issued, 1) : null,
            'locked' => $locked,
            'locked_other' => (int) $locks['other'] + max(0, count($subjects) - count($locked)),
            // 2026-11-17 00:30 +03: Task M3.19 ruling (M6), task 596. The page shows no Unlock button without a key.
            'unlock_enabled' => $this->canUnlock(),
        ];
    }

    /**
     * Lifts the lock of one number. Only a subject that is locked right now is accepted (the text must equal one of
     * the listed subjects, so what reaches the delete is always a canonical subject of ours); anything else deletes
     * nothing. The log line holds the masked number, never the number.
     *
     * @return int Rows deleted (0 = that subject is not locked now)
     */
    public function unlock(string $subject, int $admin_id): int
    {
        $now = $this->now();
        $locks = $this->otp()->lockedSubjects($now);
        if ($subject === '' || !isset($locks['subjects'][$subject])) {
            return 0;
        }
        $deleted = $this->otp()->unlockSubject($subject);
        if ($this->log !== null) {
            $this->log->log('info', 'otp.unlocked', 'Number unlocked', [
                'subject' => self::maskSubject($subject),
                'admin_id' => $admin_id,
                'deleted' => $deleted,
            ]);
        }

        return $deleted;
    }

    /**
     * The opaque token of a subject: what the page posts back instead of the number (HMAC, so the page source holds
     * no phone number, not even a hashed one that could be guessed).
     */
    public function token(string $subject): string
    {
        // 2026-11-17 00:30 +03: Task M3.19 ruling (coordinator, M6), task 596. The HMAC never runs with an empty key: a
        // key that is a public constant would make the tokens guessable for a known number.
        if (!$this->canUnlock()) {
            return '';
        }

        return hash_hmac('sha256', $subject, 'kwtsms-unlock|' . $this->secret);
    }

    /** False when the key of the unlock token is empty (config.crypt_key): then no token is made or accepted. */
    public function canUnlock(): bool
    {
        return $this->secret !== '';
    }

    /**
     * Unlock of the number a page token stands for: only a token of a number locked right now matches.
     *
     * @return int Rows deleted (0 = no locked number has that token)
     */
    public function unlockByToken(string $token, int $admin_id): int
    {
        if (!$this->canUnlock() || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return 0;
        }
        foreach (array_keys($this->otp()->lockedSubjects($this->now())['subjects']) as $subject) {
            if (hash_equals($this->token((string) $subject), $token)) {
                return $this->unlock((string) $subject, $admin_id);
            }
        }

        return 0;
    }

    /**
     * The number as the page shows it: +965****0001. A 2FA subject "<user id>:<phone>" shows its phone only.
     */
    public static function maskSubject(string $subject): string
    {
        $pos = strrpos($subject, ':');

        return TwoFactorFlow::mask($pos === false ? $subject : substr($subject, $pos + 1));
    }

    /**
     * Code rows of a time range grouped by purpose and status (a test seam).
     *
     * @return array<int,array{purpose:string, status:string, n:int}>
     */
    protected function counts(int $from, int $to): array
    {
        $rows = db_get_array(
            'SELECT purpose, status, COUNT(*) AS n FROM ?:kwtsms_otp_codes WHERE created_at >= ?i AND created_at < ?i'
            . ' GROUP BY purpose, status',
            $from,
            $to
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Code rows from $from on, as stored-day shaped arrays by UTC day key (a test seam).
     *
     * @return array<string,array{issued:int, verified:int}>
     */
    protected function liveByDay(int $from, int $to): array
    {
        $rows = db_get_array(
            'SELECT FLOOR(created_at / 86400) AS d, status, COUNT(*) AS n FROM ?:kwtsms_otp_codes'
            . ' WHERE created_at >= ?i AND created_at < ?i GROUP BY d, status',
            $from,
            $to
        );
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $key = gmdate('Y-m-d', (int) $row['d'] * self::DAY);
            if (!isset($out[$key])) {
                $out[$key] = ['issued' => 0, 'verified' => 0];
            }
            $out[$key]['issued'] += (int) $row['n'];
            $out[$key]['verified'] += $this->isVerified((string) $row['status']) ? (int) $row['n'] : 0;
        }

        return $out;
    }

    private function isVerified(string $status): bool
    {
        return $status === 'V' || $status === 'U';
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     *
     * @return array<string,mixed> the day with the larger issued and verified of the two (by_purpose from $b when
     *                             present, else $a)
     */
    private function larger(array $a, array $b): array
    {
        $out = [
            'issued' => max((int) ($a['issued'] ?? 0), (int) ($b['issued'] ?? 0)),
            'verified' => max((int) ($a['verified'] ?? 0), (int) ($b['verified'] ?? 0)),
        ];
        $by = $b['by_purpose'] ?? ($a['by_purpose'] ?? []);
        if ((int) ($b['issued'] ?? 0) < (int) ($a['issued'] ?? 0) && isset($a['by_purpose'])) {
            $by = $a['by_purpose'];
        }
        $out['by_purpose'] = is_array($by) ? $by : [];

        return $out;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function readDays(): array
    {
        $data = json_decode((string) $this->store->getFresh(self::KEY), true);
        $out = [];
        if (is_array($data)) {
            foreach ($data as $key => $day) {
                if (is_string($key) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $key) === 1 && is_array($day)) {
                    $out[$key] = $day;
                }
            }
        }

        return $out;
    }

    private function otp(): OtpService
    {
        if ($this->otp === null) {
            $this->otp = call_user_func($this->otp_factory);
        }

        return $this->otp;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }
}
