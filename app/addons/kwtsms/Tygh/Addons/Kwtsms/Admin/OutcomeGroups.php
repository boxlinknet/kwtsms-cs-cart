<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/OutcomeGroups.php
 * CREATED:   2026-10-21 03:12 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The four Dashboard counter groups (accepted, failed, skipped, deferred) as lists of canonical outcome
 *            codes (PRD Appendix B), and the outcomes that count as a problem in the "Problems (7 days)" panel.
 * WHY:       PRD 6.9. Every outcome of Sms\SmsService::OUTCOMES belongs to exactly one group (a unit test checks it),
 *            so the counters never lose or double an attempt. The groups are:
 *              accepted  kwtSMS took the message (live) or queued it as a test (test mode), also partly;
 *              failed    the send ended badly: failed, rejected, unknown (no reply, never resent) and expired
 *                        (waited in the outbox until it was too late);
 *              deferred  waiting in the outbox for a retry, quiet hours or a paused gateway;
 *              skipped   the add-on withheld it: a gate refused it (blocked_*, invalid, no_phone, no_coverage,
 *                        rate_limited, cancelled, no_template) or it was a no-op (duplicate, duplicate_event,
 *                        superseded, switched_off, not_notified).
 *            Routine no-ops are skipped but never shown as problems: nobody has to act on them.
 * USAGE:     OutcomeGroups::kind('blocked_balance')   -> 'skipped'
 *            OutcomeGroups::problemOutcomes()          -> the outcomes the Problems panel counts
 * DEPENDS:   Nothing.
 */

namespace Tygh\Addons\Kwtsms\Admin;

final class OutcomeGroups
{
    public const ACCEPTED = ['accepted', 'test_queued', 'partial_accept'];

    public const FAILED = ['failed', 'unknown', 'rejected', 'expired'];

    public const DEFERRED = ['deferred'];

    public const SKIPPED = [
        'invalid', 'duplicate', 'no_phone', 'no_coverage', 'blocked_country', 'blocked_disabled',
        'blocked_not_configured', 'blocked_empty', 'blocked_too_long', 'blocked_placeholder', 'blocked_balance',
        'blocked_pilot', 'blocked_daily_cap', 'rate_limited', 'cancelled', 'switched_off', 'not_notified',
        'superseded', 'duplicate_event', 'no_template',
        // 2026-10-23 19:55 +03: Task M1.2 (P3-26), task 596. The store hourly cap, next to blocked_daily_cap.
        'blocked_hourly_cap',
    ];

    /** Skipped outcomes that are nothing to act on. */
    public const ROUTINE = ['duplicate', 'duplicate_event', 'superseded', 'switched_off', 'not_notified'];

    /** The outcomes whose count by country the Problems panel adds (calling code of the number). */
    public const BY_COUNTRY = ['no_coverage', 'blocked_country'];

    private function __construct()
    {
    }

    /**
     * @return string 'accepted', 'failed', 'deferred', 'skipped', or '' for a code that is not an outcome
     */
    public static function kind(string $outcome): string
    {
        $groups = [
            'accepted' => self::ACCEPTED,
            'failed' => self::FAILED,
            'deferred' => self::DEFERRED,
            'skipped' => self::SKIPPED,
        ];
        foreach ($groups as $kind => $list) {
            if (in_array($outcome, $list, true)) {
                return $kind;
            }
        }

        return '';
    }

    /**
     * @return string[] Every outcome code of the four groups
     */
    public static function all(): array
    {
        return array_merge(self::ACCEPTED, self::FAILED, self::DEFERRED, self::SKIPPED);
    }

    /**
     * Failed, deferred and skipped outcomes that someone may have to act on, in the order the panel lists them.
     *
     * @return string[]
     */
    public static function problemOutcomes(): array
    {
        return array_merge(
            self::FAILED,
            self::DEFERRED,
            array_values(array_diff(self::SKIPPED, self::ROUTINE))
        );
    }
}
