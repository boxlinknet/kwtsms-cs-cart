<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Auth/TwoFactorStore.php
 * CREATED:   2026-11-04 22:38 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The table kwtsms_twofa (one row per enrolled account: user_id, enabled Y/N, the 2FA phone in canonical
 *            form, enrolled_at, updated_at) behind row(), enable(), disable(), delete() and the count of enrolled
 *            root administrators.
 * WHY:       Task M2.2a (SafatMall B3, P3-49, P3-52). The row read happens on every login while a 2FA switch is on, so
 *            it is one primary-key SELECT. The phone is written only by a code proof (the caller's job); this class
 *            never invents one. delete() also removes the account's backup codes (table kwtsms_backup_codes, written
 *            by M2.2b).
 * USAGE:     $store = Tygh::$app['kwtsms.twofa_store'];
 *            $store->row($user_id);               // ['user_id','enabled','phone','enrolled_at','updated_at'] or null
 *            $store->enable($user_id, '+96550123401');
 *            $store->disable($user_id);  $store->delete($user_id);
 *            $store->enrolledRootAdmins($except_user_id);   // active root administrators with an enabled row
 *            Test seams: the protected readRow(), writeEnable(), writeDisable(), removeRows(), countEnrolledRoots().
 * DEPENDS:   CS-Cart db_get_row(), db_query(), db_get_field().
 */

namespace Tygh\Addons\Kwtsms\Auth;

class TwoFactorStore
{
    /** @var callable|null Returns the Unix time; null = time() */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function row(int $user_id): ?array
    {
        if ($user_id <= 0) {
            return null;
        }

        return $this->readRow($user_id);
    }

    /** Enrols the account: enabled Y with this canonical phone. The first enrolment time is kept. */
    public function enable(int $user_id, string $phone): void
    {
        if ($user_id <= 0 || $phone === '') {
            throw new \InvalidArgumentException('kwtsms: a 2FA row needs a real account and a phone');
        }
        $this->writeEnable($user_id, $phone, $this->now());
    }

    public function disable(int $user_id): void
    {
        if ($user_id > 0) {
            $this->writeDisable($user_id, $this->now());
        }
    }

    /** The row and the backup codes of the account (exact user id). */
    public function delete(int $user_id): void
    {
        if ($user_id > 0) {
            $this->removeRows($user_id);
        }
    }

    /**
     * 2026-11-05 02:24 +03: Task M2.2a fix 1 (C1), task 596. True when the number is the enabled 2FA phone of another
     * account, or the phone of an administrator or vendor account other than $user_id. The staff phone is stored as
     * typed, so the rows are narrowed by a LIKE on the last seven digits (a '%' between the digits) and then compared
     * in canonical form with the store's default country.
     */
    public function phoneHeldByAnother(int $user_id, string $phone, string $default_cc): bool
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if (!is_string($digits) || strlen($digits) < 7) {
            return false;
        }
        if ($this->countOtherEnrolled($user_id, $phone) > 0) {
            return true;
        }
        $pattern = '%' . implode('%', str_split(substr($digits, -7))) . '%';
        foreach ($this->staffPhones($user_id, $pattern) as $raw) {
            if (\Tygh\Addons\Kwtsms\Phones\PhoneFormat::fromRaw((string) $raw, $default_cc) === $phone) {
                return true;
            }
        }

        return false;
    }

    /** Active root administrators (company 0) other than $except_user_id that have an enabled row. */
    public function enrolledRootAdmins(int $except_user_id): int
    {
        return $this->countEnrolledRoots($except_user_id);
    }

    // ------------------------------------------------------------------ the database (replaced by tests)

    /**
     * @return array<string,mixed>|null
     */
    protected function readRow(int $user_id): ?array
    {
        $row = db_get_row(
            'SELECT user_id, enabled, phone, enrolled_at, updated_at FROM ?:kwtsms_twofa WHERE user_id = ?i',
            $user_id
        );

        return is_array($row) && $row !== [] ? $row : null;
    }

    protected function countOtherEnrolled(int $user_id, string $phone): int
    {
        return (int) db_get_field(
            "SELECT COUNT(*) FROM ?:kwtsms_twofa WHERE enabled = 'Y' AND phone = ?s AND user_id <> ?i",
            $phone,
            $user_id
        );
    }

    /** @return array<int,string> the raw phone column of the other administrators and vendors that may match */
    protected function staffPhones(int $user_id, string $pattern): array
    {
        return (array) db_get_fields(
            "SELECT phone FROM ?:users WHERE user_type IN ('A', 'V') AND user_id <> ?i AND phone LIKE ?s",
            $user_id,
            $pattern
        );
    }

    protected function writeEnable(int $user_id, string $phone, int $now): void
    {
        db_query(
            'INSERT INTO ?:kwtsms_twofa (user_id, enabled, phone, enrolled_at, updated_at) VALUES (?i, ?s, ?s, ?i, ?i)'
            . ' ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), phone = VALUES(phone),'
            . ' enrolled_at = IF(enrolled_at = 0, VALUES(enrolled_at), enrolled_at), updated_at = VALUES(updated_at)',
            $user_id,
            'Y',
            $phone,
            $now,
            $now
        );
    }

    protected function writeDisable(int $user_id, int $now): void
    {
        db_query(
            "UPDATE ?:kwtsms_twofa SET enabled = 'N', updated_at = ?i WHERE user_id = ?i",
            $now,
            $user_id
        );
    }

    protected function removeRows(int $user_id): void
    {
        db_query('DELETE FROM ?:kwtsms_twofa WHERE user_id = ?i', $user_id);
        db_query('DELETE FROM ?:kwtsms_backup_codes WHERE user_id = ?i', $user_id);
    }

    protected function countEnrolledRoots(int $except_user_id): int
    {
        return (int) db_get_field(
            "SELECT COUNT(*) FROM ?:users u INNER JOIN ?:kwtsms_twofa t ON t.user_id = u.user_id AND t.enabled = 'Y'"
            . " WHERE u.user_type = 'A' AND u.is_root = 'Y' AND u.company_id = 0 AND u.status = 'A'"
            . ' AND u.user_id <> ?i',
            $except_user_id
        );
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }
}
