<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Codes/CodeHasher.php
 * CREATED:   2026-10-23 21:26 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The keyed hash of an SMS code: hash_hmac('sha256', purpose|subject|code|salt, key) (ruling P3-1), a random
 *            salt per code row, and the constant-time comparison.
 * WHY:       Task M1.3 (SafatMall D4). The code is never stored. The key comes from outside the database (derived from
 *            config.crypt_key by the ServiceProvider), so a database dump alone cannot brute-force the 10^6 code
 *            space; the salt and the purpose and subject in the message make every row's hash unique.
 * USAGE:     $hasher = new CodeHasher($key, $debug_log);          // $debug_log optional
 *            $salt = $hasher->newSalt();                          // 32 hex characters
 *            $hash = $hasher->hash('register', '+96550000001', '123456', $salt);   // 64 hex characters
 *            $hasher->matches($hash, 'register', '+96550000001', $typed, $salt);   // bool; writes one debug row
 *            $hasher->dummy($typed);                              // same work, never matches, writes nothing
 *            The key is never logged and never shown by var_dump() / print_r() (__debugInfo()).
 *            The debug row otp.compare holds the purpose, subjectRef() (12 hex characters of an HMAC of the subject
 *            with the key: a handle to group rows that cannot be reversed to the number) and whether it matched:
 *            never the code, the phone number, the key or a hash.
 * DEPENDS:   Log\DebugLog (optional).
 */

namespace Tygh\Addons\Kwtsms\Codes;

use Tygh\Addons\Kwtsms\Log\DebugLog;

final class CodeHasher
{
    /** @var string */
    private $key;

    /** @var DebugLog|null */
    private $log;

    public function __construct(
        #[\SensitiveParameter]
        string $key,
        ?DebugLog $log = null
    ) {
        $this->key = $key;
        $this->log = $log;
    }

    /**
     * @return array<string,string> what var_dump() and print_r() show: never the key
     */
    public function __debugInfo(): array
    {
        return ['key' => '(hidden)'];
    }

    /**
     * 16 random bytes as 32 hex characters.
     */
    public function newSalt(): string
    {
        return bin2hex(random_bytes(16));
    }

    // 2026-10-24 05:59 +03: Task M1.3 fix round 1 (review minors 6, 7), task 596. #[\SensitiveParameter] on every code
    // parameter (a stack trace on PHP 8.2+ shows no value; a comment on 7.4); subjectRef() is a keyed handle.
    public function hash(
        string $purpose,
        string $subject,
        #[\SensitiveParameter]
        string $code,
        string $salt
    ): string {
        return hash_hmac('sha256', $purpose . '|' . $subject . '|' . $code . '|' . $salt, $this->key);
    }

    /**
     * Compares a typed code with the stored hash in constant time and writes the debug row otp.compare (one row per
     * comparison; the parallel-attempt test counts them).
     */
    public function matches(
        string $expected,
        string $purpose,
        string $subject,
        #[\SensitiveParameter]
        string $code,
        string $salt
    ): bool {
        $match = $expected !== '' && hash_equals($expected, $this->hash($purpose, $subject, $code, $salt));
        if ($this->log !== null) {
            $this->log->log('debug', 'otp.compare', 'code compared', [
                'purpose' => $purpose,
                'subject_ref' => $this->subjectRef($subject),
                'match' => $match,
            ]);
        }

        return $match;
    }

    /**
     * A 12-hex-character handle of a subject for debug rows: an HMAC with the hasher key, so the log alone cannot be
     * brute-forced back to the phone number (the number space is small).
     */
    public function subjectRef(string $subject): string
    {
        return substr(hash_hmac('sha256', 'subject_ref|' . $subject, $this->key), 0, 12);
    }

    /**
     * The same work as matches() for a request that has no code row to compare with, so its timing does not tell
     * "no row" from "wrong code". Never matches, writes nothing.
     */
    public function dummy(
        #[\SensitiveParameter]
        string $code
    ): bool {
        hash_equals(str_repeat('0', 64), $this->hash('dummy', 'dummy', $code, str_repeat('0', 32)));

        return false;
    }
}
