<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Auth/TwoFactorStats.php
 * CREATED:   2026-11-07 02:46 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Read-only numbers about two-step sign-in for the Integrations page and the Dashboard checklist: how many
 *            accounts of each user type have it on, and how many administrators have fewer than N backup codes left.
 * WHY:       Task M2.7 (1.3.0). "Backup codes left under 3 for any admin" (PRD 6.12) must be visible to the store owner
 *            before an administrator is locked out of the SMS path with no way back. Only counts, never a phone or a
 *            code.
 * USAGE:     $by_type = TwoFactorStats::enrolledByType();       // ['A' => 2, 'C' => 40, 'V' => 0]
 *            $n = TwoFactorStats::adminsLowOnBackupCodes(3);    // administrators with 0 to 2 unused codes
 *            $n = TwoFactorStats::adminsEnrolled();             // active administrators with two-step on
 * DEPENDS:   CS-Cart db_get_array() / db_get_field(); tables kwtsms_twofa (PK user_id), kwtsms_backup_codes
 *            (idx_user_id (user_id, used_at)), users. Called from the admin pages only, never on a login path.
 */

namespace Tygh\Addons\Kwtsms\Auth;

class TwoFactorStats
{
    /** Administrators with fewer unused backup codes than this are flagged (PRD 6.12, Integrations and Dashboard). */
    public const LOW_BACKUP_CODES = 3;

    /**
     * Accounts with two-step sign-in on, by user type (A administrator, V vendor administrator, C customer).
     *
     * @return array{A:int,V:int,C:int}
     */
    public static function enrolledByType(): array
    {
        // 2026-11-07 02:47 +03: Task M2.7, task 596. ANALYZE at 200,000 enrolled rows (docs/evidence/1.3.0/M2.7): the
        // join driven from kwtsms_twofa read 175 ms; vendors and administrators are few, so they are counted from the
        // users side (STRAIGHT_JOIN, one PRIMARY lookup each) and customers are the enabled total minus those two.
        $out = ['A' => 0, 'V' => 0, 'C' => 0];
        $rows = (array) db_get_array(
            "SELECT u.user_type AS user_type, COUNT(*) AS n FROM ?:users u STRAIGHT_JOIN ?:kwtsms_twofa t"
            . " ON t.user_id = u.user_id WHERE t.enabled = 'Y' AND u.user_type IN ('A', 'V') GROUP BY u.user_type"
        );
        foreach ($rows as $row) {
            $out[(string) $row['user_type']] = (int) $row['n'];
        }
        $total = (int) db_get_field("SELECT COUNT(*) FROM ?:kwtsms_twofa WHERE enabled = 'Y'");
        $out['C'] = max(0, $total - $out['A'] - $out['V']);

        return $out;
    }

    /** Active administrators with two-step on. */
    public static function adminsEnrolled(): int
    {
        // 2026-11-07 02:47 +03: Task M2.7, task 596. STRAIGHT_JOIN from users: 25 ms against 143 ms at 200,000 rows.
        return (int) db_get_field(
            "SELECT COUNT(*) FROM ?:users u STRAIGHT_JOIN ?:kwtsms_twofa t ON t.user_id = u.user_id AND t.enabled = 'Y'"
            . " WHERE u.user_type = 'A' AND u.status = 'A'"
        );
    }

    /**
     * Active administrators with two-step on and fewer than $min unused backup codes (none at all counts).
     */
    public static function adminsLowOnBackupCodes(int $min = self::LOW_BACKUP_CODES): int
    {
        // 2026-11-07 02:47 +03: Task M2.7, task 596. STRAIGHT_JOIN from users, as adminsEnrolled(): 26 ms at 200k rows.
        return (int) db_get_field(
            "SELECT COUNT(*) FROM ?:users u STRAIGHT_JOIN ?:kwtsms_twofa t ON t.user_id = u.user_id AND t.enabled = 'Y'"
            . " WHERE u.user_type = 'A' AND u.status = 'A' AND ("
            . 'SELECT COUNT(*) FROM ?:kwtsms_backup_codes b WHERE b.user_id = u.user_id AND b.used_at = 0'
            . ') < ?i',
            max(1, $min)
        );
    }
}
