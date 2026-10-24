<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Security/RateLimiter.php
 * CREATED:   2026-10-23 19:51 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Atomic counters ("buckets") in ?:kwtsms_rate_limits (InnoDB): hit() counts in a fixed window with one
 *            statement that returns its own post-increment value (P3-18); claimStep() claims the next step of a
 *            sliding resend sequence with a compare-and-set (P3-19); undoStep() puts a claimed step back; lock(),
 *            lockedUntil(), reset() and prune() complete the set.
 * WHY:       Task M1.2 (SafatMall E1, rulings P3-18 and P3-19). A read-modify-write counter lets 20 parallel wrong-code
 *            submissions all pass the limit, and a "last sent" check lets two parallel requests both pass the resend
 *            wait. Here the database decides: the returned value of hit() is the caller's own, and exactly one of
 *            several parallel claimStep() calls wins.
 * USAGE:     $limiter = new RateLimiter();                          // or new RateLimiter($clock) in tests
 *            $n = $limiter->hit('ip_h:203.0.113.9', 3600);          // 1, 2, 3 ... in a window of 3600 s
 *            $limiter->peek($bucket);       // ['hits', 'window_start', 'last_at', 'locked_until'] (ints, 0 if absent)
 *            $claim = $limiter->claimStep('resend:register:+965XXXXXXXX', [60, 120, 300], 3600);
 *            //   ['ok' => bool, 'retry_after' => seconds, 'prev_hits' => int, 'prev_last_at' => int,
 *            //    'claimed_at' => int]
 *            // 2026-10-23 21:27 +03: Task M1.3 (M1.2 review), task 596. The undo passes claimed_at as the 4th
 *            // argument, so it never erases the claim of another request (3 arguments match the hit count only):
 *            $limiter->undoStep($bucket, $claim['prev_hits'], $claim['prev_last_at'], $claim['claimed_at']);
 *            //   e.g. the SMS was not sent
 *            $limiter->lock($bucket, 900);  $limiter->lockedUntil($bucket);  $limiter->reset($bucket);
 *            $limiter->prune($before, 5000);   // rows idle since before $before, not locked now; returns rows deleted
 *            Bucket names are at most 128 characters: a longer name becomes "<kind>:" + sha256 of the rest
 *            (RateLimiter::bucketName(), applied by every method). Every time value comes from the injected clock,
 *            never from the database (NOW(), UNIX_TIMESTAMP()), so tests set the time.
 *            Test seams (protected): run(), field(), row().
 * DEPENDS:   CS-Cart db_query(), db_get_field(), db_get_row() (not used without them); table ?:kwtsms_rate_limits
 *            (bucket varchar(128) PK, hits, window_start, last_at, locked_until; KEY last_at).
 */

namespace Tygh\Addons\Kwtsms\Security;

class RateLimiter
{
    /** The longest bucket name the table holds. */
    public const MAX_BUCKET = 128;

    /**
     * The atomic count of hit() (P3-18): the first hit inserts hits = 1; a later one adds 1, or restarts at 1 when the
     * window passed (window_start <= now - window). LAST_INSERT_ID(expr) makes the new value readable on the same
     * connection with SELECT LAST_INSERT_ID(). The order of the two IF columns matters: hits is assigned before
     * window_start, and MariaDB evaluates the assignments left to right, so hits still sees the old window_start.
     * Arguments: bucket, window_start, last_at, now - window, now - window.
     */
    public const HIT_SQL = 'INSERT INTO ?:kwtsms_rate_limits (bucket, hits, window_start, last_at)'
        . ' VALUES (?s, LAST_INSERT_ID(1), ?i, ?i)'
        . ' ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(IF(window_start <= ?i, 1, hits + 1)),'
        . ' window_start = IF(window_start <= ?i, VALUES(window_start), window_start), last_at = VALUES(last_at)';

    /** @var callable|null */
    private $clock;

    /**
     * @param callable|null $clock Returns the current Unix time (tests); null = time()
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock;
    }

    /**
     * The current Unix time of this limiter (the injected clock).
     */
    public function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }

    /**
     * The name a bucket is stored under: at most 128 characters; a longer name becomes "<kind>:" (the text before its
     * first colon) followed by the sha256 of everything after that colon.
     */
    public static function bucketName(string $bucket): string
    {
        if (strlen($bucket) <= self::MAX_BUCKET) {
            return $bucket;
        }
        $pos = strpos($bucket, ':');
        if ($pos === false || $pos > 48) {
            return ':' . hash('sha256', $bucket);
        }

        return substr($bucket, 0, $pos) . ':' . hash('sha256', substr($bucket, $pos + 1));
    }

    /**
     * Counts one hit in the fixed window that started at the first hit of the bucket.
     *
     * 2026-10-23 20:46 +03: Task M1.2 fix round 1 (minor 2), task 596. Fails closed: when the statement throws or
     * LAST_INSERT_ID() comes back 0 (a query on an AUTO_INCREMENT table in between, a reconnect), the number of this
     * hit is unknown, so the call is logged (no bucket name, no address) and answers PHP_INT_MAX, which is over every
     * limit. It never reads as a first hit.
     *
     * @return int This hit's number in its window (1 for the first); PHP_INT_MAX when it could not be counted
     */
    public function hit(string $bucket, int $window_s): int
    {
        $now = $this->now();
        $edge = $now - max(1, $window_s);
        try {
            $this->run(self::HIT_SQL, [self::bucketName($bucket), $now, $now, $edge, $edge]);
            $n = (int) $this->field('SELECT LAST_INSERT_ID()', []);
        } catch (\Throwable $e) {
            $this->report('rate limiter hit failed: ' . get_class($e));

            return PHP_INT_MAX;
        }
        if ($n < 1) {
            $this->report('rate limiter hit: LAST_INSERT_ID() returned ' . $n . ', counted as over the limit');

            return PHP_INT_MAX;
        }

        return $n;
    }

    /**
     * One log line (a test seam). Never holds a bucket name, an address or a phone number.
     */
    protected function report(string $message): void
    {
        error_log('kwtsms: ' . $message);
    }

    /**
     * The stored counters of a bucket (zeros when it does not exist). An expired window is not reset here.
     *
     * @return array{hits: int, window_start: int, last_at: int, locked_until: int}
     */
    public function peek(string $bucket): array
    {
        $row = $this->fetch(self::bucketName($bucket));

        return $row === null
            ? ['hits' => 0, 'window_start' => 0, 'last_at' => 0, 'locked_until' => 0]
            : $row;
    }

    /**
     * The pure decision of claimStep() for the stored row (null = no row). The next wait is
     * waits[min(hits, count) - 1] after last_at; after $idle_s seconds with no step the sequence restarts (the
     * effective previous count is 0).
     *
     * @param array{hits: int, last_at: int}|null $row
     * @param int[]                               $waits
     *
     * @return array{ok: bool, retry_after: int, prev_hits: int, prev_last_at: int}
     */
    public static function decide(?array $row, int $now, array $waits, int $idle_s): array
    {
        $steps = array_values(array_filter(array_map('intval', $waits), static function (int $wait): bool {
            return $wait > 0;
        }));
        if ($steps === []) {
            $steps = [60];
        }
        $hits = $row === null ? 0 : (int) $row['hits'];
        $last = $row === null ? 0 : (int) $row['last_at'];
        if ($hits > 0 && $now - $last >= max(1, $idle_s)) {
            return ['ok' => true, 'retry_after' => 0, 'prev_hits' => 0, 'prev_last_at' => $last];
        }
        if ($hits > 0) {
            $wait = $steps[min($hits, count($steps)) - 1];
            $left = $last + $wait - $now;
            if ($left > 0) {
                return ['ok' => false, 'retry_after' => $left, 'prev_hits' => $hits, 'prev_last_at' => $last];
            }
        }

        return ['ok' => true, 'retry_after' => 0, 'prev_hits' => $hits, 'prev_last_at' => $last];
    }

    /**
     * Claims the next step of a sliding sequence: allowed when the wait since the last step has passed. The claim is a
     * compare-and-set on (hits, last_at) as read, so of several parallel callers exactly one wins; a lost race is
     * read again once, and a second loss answers "not ok" with the fresh wait.
     *
     * @param int[] $waits  Seconds to wait before the 2nd, 3rd ... step; the last one repeats
     * @param int   $idle_s Seconds with no step after which the sequence restarts
     *
     * @return array{ok: bool, retry_after: int, prev_hits: int, prev_last_at: int, claimed_at: int} claimed_at is the
     *                time the claim wrote (0 when not ok)
     */
    public function claimStep(string $bucket, array $waits, int $idle_s): array
    {
        $name = self::bucketName($bucket);
        $decision = ['ok' => false, 'retry_after' => 1, 'prev_hits' => 0, 'prev_last_at' => 0, 'claimed_at' => 0];
        for ($try = 0; $try < 2; $try++) {
            $now = $this->now();
            $row = $this->fetch($name);
            $decision = self::decide($row, $now, $waits, $idle_s);
            if (!$decision['ok']) {
                return $decision + ['claimed_at' => 0];
            }
            if ($this->swap($name, $row, $decision['prev_hits'] + 1, $now)) {
                // 2026-10-23 20:46 +03: claimed_at, Task M1.2 fix round 1 (minor 1), task 596: undoStep() matches it.
                return $decision + ['claimed_at' => $now];
            }
        }
        // Lost twice: somebody else took the step. The fresh state tells how long to wait.
        $fresh = self::decide($this->fetch($name), $this->now(), $waits, $idle_s);

        return [
            'ok' => false,
            'retry_after' => $fresh['ok'] ? 1 : $fresh['retry_after'],
            'prev_hits' => $fresh['prev_hits'],
            'prev_last_at' => $fresh['prev_last_at'],
            'claimed_at' => 0,
        ];
    }

    /**
     * Puts a claimed step back (compare-and-set: only when nobody claimed a later step since).
     * 2026-10-23 20:46 +03: Task M1.2 fix round 1 (minor 1), task 596. $claimed_at is the time the claim wrote
     * (claimStep()['claimed_at']): the undo matches it as well as the hit count, so it can never erase the claim of
     * another request after a reset. Without it (null) only the hit count is matched, as before.
     */
    public function undoStep(string $bucket, int $prev_hits, int $prev_last_at, ?int $claimed_at = null): void
    {
        $sql = 'UPDATE ?:kwtsms_rate_limits SET hits = ?i, last_at = ?i WHERE bucket = ?s AND hits = ?i';
        $args = [max(0, $prev_hits), max(0, $prev_last_at), self::bucketName($bucket), max(0, $prev_hits) + 1];
        if ($claimed_at !== null) {
            $sql .= ' AND last_at = ?i';
            $args[] = max(0, $claimed_at);
        }
        $this->run($sql, $args);
    }

    /**
     * Locks the bucket for $seconds from now (a later lock replaces an earlier one).
     */
    // 2026-10-24 05:56 +03: Task M1.3 fix round 1 (I2, ruling P3-R4), task 596. $clear_count also resets the count and
    // the window, so the count starts again when the lock ends (hits stay as they were by default).
    public function lock(string $bucket, int $seconds, bool $clear_count = false): void
    {
        $now = $this->now();
        $this->run(
            'INSERT INTO ?:kwtsms_rate_limits (bucket, hits, window_start, last_at, locked_until)'
            . ' VALUES (?s, 0, 0, ?i, ?i)'
            . ' ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until), last_at = VALUES(last_at)'
            . ($clear_count ? ', hits = 0, window_start = 0' : ''),
            [self::bucketName($bucket), $now, $now + max(1, $seconds)]
        );
    }

    /**
     * @return int Unix time the bucket is locked until (0 = never locked); compare with the clock
     */
    public function lockedUntil(string $bucket): int
    {
        return (int) $this->field(
            'SELECT locked_until FROM ?:kwtsms_rate_limits WHERE bucket = ?s',
            [self::bucketName($bucket)]
        );
    }

    /**
     * Forgets the bucket (count, window, step and lock).
     */
    public function reset(string $bucket): void
    {
        $this->run('DELETE FROM ?:kwtsms_rate_limits WHERE bucket = ?s', [self::bucketName($bucket)]);
    }

    /**
     * Deletes one chunk of buckets idle since before $before and not locked now.
     *
     * 2026-10-23 20:46 +03: Task M1.2 fix round 1 (minor 3), task 596. $prefix limits the delete to bucket names
     * that start with it (tests prune only their own buckets); '' = every bucket, what the cron does.
     *
     * @return int Rows deleted; fewer than $limit means none are left
     */
    public function prune(int $before, int $limit, string $prefix = ''): int
    {
        $sql = 'DELETE FROM ?:kwtsms_rate_limits WHERE last_at < ?i AND locked_until < ?i';
        $args = [max(0, $before), $this->now()];
        if ($prefix !== '') {
            // ?s, not ?l: ?l doubles a backslash, so the escape of _ and % here would turn into a literal backslash.
            $sql .= ' AND bucket LIKE ?s';
            $args[] = addcslashes($prefix, '%_\\') . '%';
        }

        return $this->run($sql . ' LIMIT ?i', array_merge($args, [max(1, $limit)]));
    }

    // 2026-10-23 21:27 +03: pruneCodes() moved to Codes\OtpService (the code store), Task M1.3 (M1.2 review
    // minor 7), task 596.

    /**
     * The compare-and-set of claimStep(): writes the new step only when the row is still as it was read (or still
     * absent). True = this caller won.
     *
     * @param array{hits: int, last_at: int}|null $seen
     */
    private function swap(string $name, ?array $seen, int $hits, int $now): bool
    {
        if ($seen === null) {
            return $this->run(
                'INSERT IGNORE INTO ?:kwtsms_rate_limits (bucket, hits, window_start, last_at) VALUES (?s, ?i, ?i, ?i)',
                [$name, $hits, $now, $now]
            ) === 1;
        }

        return $this->run(
            'UPDATE ?:kwtsms_rate_limits SET hits = ?i, last_at = ?i WHERE bucket = ?s AND hits = ?i AND last_at = ?i',
            [$hits, $now, $name, (int) $seen['hits'], (int) $seen['last_at']]
        ) === 1;
    }

    /**
     * @return array{hits: int, window_start: int, last_at: int, locked_until: int}|null
     */
    private function fetch(string $name): ?array
    {
        $row = $this->row(
            'SELECT hits, window_start, last_at, locked_until FROM ?:kwtsms_rate_limits WHERE bucket = ?s',
            [$name]
        );
        if (!is_array($row) || $row === []) {
            return null;
        }

        return [
            'hits' => (int) $row['hits'],
            'window_start' => (int) $row['window_start'],
            'last_at' => (int) $row['last_at'],
            'locked_until' => (int) $row['locked_until'],
        ];
    }

    /**
     * One write statement (a test seam).
     *
     * @param array<int,mixed> $args
     *
     * @return int Rows affected
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

        return is_array($row) ? $row : null;
    }
}
