<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Auth/BackupCodes.php
 * CREATED:   2026-11-05 07:25 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The one-time backup codes of an enrolled administrator or vendor administrator (P3-52 (3), Open 3): ten
 *            codes of ten characters from ABCDEFGHJKLMNPQRSTUVWXYZ23456789 shown as XXXXX-XXXXX, drawn from a CSPRNG,
 *            stored only as a keyed hash with a salt of its own (table kwtsms_backup_codes), accepted once.
 * WHY:       Task M2.2b. When the SMS cannot be sent (gateway down, balance zero, sender not allowed) an enrolled
 *            administrator would be locked out; a backup code signs in instead of the SMS code. The plain codes exist
 *            only in the return value of generate(): the caller shows them once and never stores or logs them.
 * USAGE:     $codes = Tygh::$app['kwtsms.backup_codes'];
 *            $plain = $codes->generate($user_id);        // string[10] 'ABCDE-FGH23', the old set is deleted first
 *            $codes->use($user_id, $typed);              // bool; true once per code (compare-and-set on used_at)
 *            $codes->remaining($user_id);                // int
 *            BackupCodes::normalize($typed);             // 'ABCDEFGH23' or null (case, spaces, dash, Arabic digits)
 *            Counting: the CALLER counts wrong tries in the same otp_fail buckets as SMS codes
 * (OtpService::verifyWith()).
 *            Test seams (protected): removeAll(), insertRow(), unusedRows(), claim(), countUnused().
 * DEPENDS:   Codes\CodeHasher (hash(), newSalt()), Sms\PhoneNormalizer::DIGITS_MAP, CS-Cart db_query / db_get_array /
 *            db_get_field, table ?:kwtsms_backup_codes (PK code_id, KEY idx_user_id (user_id, used_at)).
 */

namespace Tygh\Addons\Kwtsms\Auth;

use Tygh\Addons\Kwtsms\Codes\CodeHasher;
use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;

class BackupCodes
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const LENGTH = 10;
    public const COUNT = 10;

    /** The purpose word inside the hash input, so a backup hash can never equal an SMS code hash. */
    private const PURPOSE = 'backup';

    /** @var CodeHasher */
    private $hasher;

    /** @var callable|null (int $min, int $max): int; null = random_int() */
    private $random;

    /** @var callable|null Returns the Unix time; null = time() */
    private $clock;

    public function __construct(CodeHasher $hasher, ?callable $random = null, ?callable $clock = null)
    {
        $this->hasher = $hasher;
        $this->random = $random;
        $this->clock = $clock;
    }

    /**
     * A new set of ten codes; the account's old set (used or not) is deleted first, so the old codes stop working.
     *
     * @return string[] The plain codes, grouped XXXXX-XXXXX; the only time they exist
     */
    public function generate(int $user_id): array
    {
        if ($user_id <= 0) {
            throw new \InvalidArgumentException('kwtsms: backup codes need a real account');
        }
        $plain = [];
        while (count($plain) < self::COUNT) {
            $code = $this->draw();
            $plain[$code] = $code;
        }
        $now = $this->now();
        $this->removeAll($user_id);
        foreach ($plain as $code) {
            $salt = $this->hasher->newSalt();
            $hash = $this->hasher->hash(self::PURPOSE, (string) $user_id, $code, $salt);
            $this->insertRow($user_id, $hash, $salt, $now);
        }

        return array_map(static function (string $code): string {
            return substr($code, 0, 5) . '-' . substr($code, 5);
        }, array_values($plain));
    }

    /**
     * True once per code. Every unused row of the account is compared (hash_equals, no early exit), then the matching
     * row is claimed with UPDATE ... WHERE used_at = 0: affected 1 wins, so two requests with the same code cannot both
     * sign in.
     */
    public function use(
        int $user_id,
        #[\SensitiveParameter]
        string $raw
    ): bool {
        $code = self::normalize($raw);
        if ($user_id <= 0 || $code === null) {
            return false;
        }
        $won = 0;
        foreach ($this->unusedRows($user_id) as $row) {
            $hash = $this->hasher->hash(self::PURPOSE, (string) $user_id, $code, (string) $row['salt']);
            if (hash_equals((string) $row['code_hash'], $hash) && $won === 0) {
                $won = (int) $row['code_id'];
            }
        }

        return $won > 0 && $this->claim($won, $this->now()) === 1;
    }

    /** 2026-11-05 07:27 +03: Task M2.2b, task 596. Deletes the account's whole set (used and unused), exact user id. */
    public function clear(int $user_id): void
    {
        if ($user_id > 0) {
            $this->removeAll($user_id);
        }
    }

    public function remaining(int $user_id): int
    {
        return $user_id > 0 ? $this->countUnused($user_id) : 0;
    }

    /**
     * The entry as stored: upper case, without spaces, hyphens and no-break spaces, Arabic digits as ASCII. Null when
     * it
     * is not ten characters of the alphabet.
     */
    public static function normalize(
        #[\SensitiveParameter]
        string $raw
    ): ?string {
        $text = strtr($raw, PhoneNormalizer::DIGITS_MAP);
        $text = strtoupper(str_replace([' ', '-', "\u{00A0}"], '', $text));

        return preg_match('/^[' . self::ALPHABET . ']{' . self::LENGTH . '}$/D', $text) === 1 ? $text : null;
    }

    private function draw(): string
    {
        $code = '';
        $last = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LENGTH; $i++) {
            $n = $this->random === null ? random_int(0, $last) : (int) call_user_func($this->random, 0, $last);
            $code .= self::ALPHABET[$n % strlen(self::ALPHABET)];
        }

        return $code;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }

    // ------------------------------------------------------------------ the database (replaced by tests)

    protected function removeAll(int $user_id): void
    {
        db_query('DELETE FROM ?:kwtsms_backup_codes WHERE user_id = ?i', $user_id);
    }

    protected function insertRow(int $user_id, string $hash, string $salt, int $now): void
    {
        db_query(
            'INSERT INTO ?:kwtsms_backup_codes (user_id, code_hash, salt, created_at, used_at)'
            . ' VALUES (?i, ?s, ?s, ?i, 0)',
            $user_id,
            $hash,
            $salt,
            $now
        );
    }

    /** @return array<int,array<string,mixed>> code_id, code_hash, salt of the unused codes */
    protected function unusedRows(int $user_id): array
    {
        return (array) db_get_array(
            'SELECT code_id, code_hash, salt FROM ?:kwtsms_backup_codes WHERE user_id = ?i AND used_at = 0',
            $user_id
        );
    }

    /** Affected rows: 1 = this request used the code up. */
    protected function claim(int $code_id, int $now): int
    {
        return (int) db_query(
            'UPDATE ?:kwtsms_backup_codes SET used_at = ?i WHERE code_id = ?i AND used_at = 0',
            max(1, $now),
            $code_id
        );
    }

    protected function countUnused(int $user_id): int
    {
        return (int) db_get_field(
            'SELECT COUNT(*) FROM ?:kwtsms_backup_codes WHERE user_id = ?i AND used_at = 0',
            $user_id
        );
    }
}
