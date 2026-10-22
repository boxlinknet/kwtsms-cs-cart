<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Vendors/VendorQuota.php
 * CREATED:   2026-10-22 22:16 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The monthly SMS quota of a Multi-Vendor vendor: how many recipients its V messages may reach in a calendar
 *            month (store time zone), what it has used, and whether one more send fits.
 * WHY:       Phase 2 Task A8 (SafatMall F4, ruling P2-6): vendor SMS cost the marketplace credits, so each vendor has a
 *            monthly number of recipients: the setting vendor_monthly_quota (default 100), or a plan limit from
 *            ?:kwtsms_vendor_quotas when the vendor_plans add-on is active and the vendor's plan has a row; 0 = vendor
 *            SMS off. The quota is soft: sends already in flight are not counted (the message row is written when the
 *            gateway answers). The count reads SmsService::SENT_OUTCOMES, the one list the caps use (R21).
 * USAGE:     $quota = Tygh::$app['kwtsms.vendor_quota'];
 *            $quota->limit(7);                         // plan override or the setting
 *            $quota->used(7, $quota->monthStart());   // SUM(recipient_count) of this month's V messages
 *            $quota->allows(7, 1);                     // false at limit 0 or when used + 1 would pass the limit
 *            $quota->planLimits(); $quota->setPlanLimit(3, 20);   // the Vendor SMS page
 *            The database touches are protected methods, so the unit test replaces them.
 * DEPENDS:   Tables kwtsms_messages (index idx_company_created_at), kwtsms_vendor_quotas; ?:companies.plan_id and
 * ?:vendor_plans (only when the vendor_plans add-on is active); Settings\ConfigInterface::vendorMonthlyQuota(),
 *            Sms\SmsService::SENT_OUTCOMES.
 */

namespace Tygh\Addons\Kwtsms\Vendors;

use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\SmsService;

class VendorQuota
{
    /** @var ConfigInterface */
    private $config;

    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
    }

    /**
     * The vendor's monthly recipient limit: the plan row when vendor_plans is active and the vendor's plan has one,
     * else the setting.
     */
    public function limit(int $company_id): int
    {
        if ($this->plansActive()) {
            $plan_id = $this->companyPlan($company_id);
            if ($plan_id > 0) {
                $plan = $this->planLimit($plan_id);
                if ($plan !== null) {
                    return max(0, $plan);
                }
            }
        }

        return max(0, $this->config->vendorMonthlyQuota());
    }

    /**
     * Recipients of the vendor's V messages created at or after $month_start with a sent outcome.
     */
    public function used(int $company_id, int $month_start): int
    {
        return max(0, $this->sumRecipients($company_id, $month_start, SmsService::SENT_OUTCOMES));
    }

    /**
     * True when $recipients more recipients still fit into this month's limit. Limit 0 never allows.
     */
    public function allows(int $company_id, int $recipients): bool
    {
        $limit = $this->limit($company_id);
        if ($limit <= 0) {
            return false;
        }

        return $this->used($company_id, $this->monthStart()) + max(0, $recipients) <= $limit;
    }

    /**
     * Unix time of the first day of the month of $at (default now), 00:00 in the store time zone (PHP's default time
     * zone, which CS-Cart sets from the store setting).
     */
    public function monthStart(?int $at = null): int
    {
        $at = $at ?? $this->now();

        return (int) mktime(0, 0, 0, (int) date('n', $at), 1, (int) date('Y', $at));
    }

    /**
     * Plan id => monthly limit of the stored overrides.
     *
     * @return array<int,int>
     */
    public function planLimits(): array
    {
        $out = [];
        foreach ($this->allPlanLimits() as $plan_id => $limit) {
            $out[(int) $plan_id] = (int) $limit;
        }

        return $out;
    }

    /**
     * Stores a plan override; null removes it (the plan follows the setting again).
     */
    public function setPlanLimit(int $plan_id, ?int $limit): void
    {
        if ($plan_id <= 0) {
            return;
        }
        if ($limit === null) {
            $this->deletePlanLimit($plan_id);

            return;
        }
        $this->writePlanLimit($plan_id, max(0, $limit));
    }

    // ------------------------------------------------------------------ the store (replaced by tests)

    protected function plansActive(): bool
    {
        return (string) \Tygh\Registry::get('addons.vendor_plans.status') === 'A';
    }

    protected function companyPlan(int $company_id): int
    {
        return (int) db_get_field('SELECT plan_id FROM ?:companies WHERE company_id = ?i', $company_id);
    }

    protected function planLimit(int $plan_id): ?int
    {
        $value = db_get_field('SELECT monthly_limit FROM ?:kwtsms_vendor_quotas WHERE plan_id = ?i', $plan_id);

        return $value === false || $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * @param string[] $statuses
     */
    protected function sumRecipients(int $company_id, int $since, array $statuses): int
    {
        return (int) db_get_field(
            'SELECT COALESCE(SUM(recipient_count), 0) FROM ?:kwtsms_messages'
            . ' WHERE company_id = ?i AND created_at >= ?i AND receiver = ?s AND status IN (?a)',
            $company_id,
            $since,
            'V',
            $statuses
        );
    }

    /**
     * @return array<int,int>
     */
    protected function allPlanLimits(): array
    {
        return (array) db_get_hash_single_array(
            'SELECT plan_id, monthly_limit FROM ?:kwtsms_vendor_quotas ORDER BY plan_id',
            ['plan_id', 'monthly_limit']
        );
    }

    protected function writePlanLimit(int $plan_id, int $limit): void
    {
        db_query('REPLACE INTO ?:kwtsms_vendor_quotas ?e', ['plan_id' => $plan_id, 'monthly_limit' => $limit]);
    }

    protected function deletePlanLimit(int $plan_id): void
    {
        db_query('DELETE FROM ?:kwtsms_vendor_quotas WHERE plan_id = ?i', $plan_id);
    }

    protected function now(): int
    {
        return time();
    }
}
