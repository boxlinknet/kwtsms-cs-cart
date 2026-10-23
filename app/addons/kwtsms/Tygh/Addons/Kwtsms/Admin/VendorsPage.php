<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/VendorsPage.php
 * CREATED:   2026-10-22 22:25 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The Vendor SMS page of the kwtsms admin controller (Multi-Vendor only): the vendors with their phone,
 *            the number a root administrator approved, the approval state, and the quota used this month; the Approve
 *            and Revoke buttons; the monthly quota (the default and one limit per vendor plan). Handles
 *            update_vendor_phone (approve | revoke) and update_vendor_quotas.
 * WHY:       Phase 2 Task A8 (SafatMall F4, rulings P2-5 and P2-6). A vendor's SMS go to one number that a root
 *            administrator approved (the vendor types its phone itself, so nobody else can vouch for it), and each
 *            vendor has a monthly number of recipients. The approval shows the kwtSMS /validate/ verdict like Admin
 *            SMS Alerts (PRD 6.6): a number kwtSMS cannot route is a warning next to the approval, never a block. The
 *            controller's gate (Admin\RequestGate) already limits every mode to root administrators on All storefronts
 *            with the manage_kwtsms privilege and a valid security hash; a vendor administrator never reaches this
 *            class.
 * USAGE:     $page = VendorsPage::fromContainer(Tygh::$app);
 *            $view = $page->viewData($has_privilege, $page_no);          // GET kwtsms.vendors
 *            $page->updatePhone($_POST['kwtsms_vendor'], 'approve', $admin_id);   // POST update_vendor_phone.approve
 *            $page->updateQuotas($_POST['kwtsms_quota']);                 // POST update_vendor_quotas
 *            Every database or core touch is a protected method, so the unit test replaces it.
 * DEPENDS:   Vendors\{VendorPhones,VendorQuota}, Settings\ConfigInterface (vendor_monthly_quota through
 *            Config::save('update_vendor_quotas')), Admin\AlertsPage::verdicts(), Log\DebugLog; CS-Cart db_*(),
 *            fn_set_notification(), __(), Tygh\Registry, Tygh\Providers\EventDispatcherProvider.
 */

namespace Tygh\Addons\Kwtsms\Admin;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Templates\Defaults;
use Tygh\Addons\Kwtsms\Vendors\VendorPhones;
use Tygh\Addons\Kwtsms\Vendors\VendorQuota;

class VendorsPage
{
    /** Vendors per page. */
    public const PER_PAGE = 25;

    /** Largest quota a field takes (the Config range of vendor_monthly_quota). */
    public const MAX_QUOTA = 100000;

    /** The notification transport id (core notification_settings.transport_id). */
    private const TRANSPORT = 'kwtsms';

    /** @var ConfigInterface */
    private $config;

    /** @var DebugLog */
    private $log;

    /** @var VendorPhones */
    private $phones;

    /** @var VendorQuota */
    private $quota;

    /** @var callable (string $context): \Tygh\Addons\Kwtsms\Sms\ApiClient */
    private $client_factory;

    public function __construct(
        ConfigInterface $config,
        DebugLog $log,
        VendorPhones $phones,
        VendorQuota $quota,
        callable $client_factory
    ) {
        $this->config = $config;
        $this->log = $log;
        $this->phones = $phones;
        $this->quota = $quota;
        $this->client_factory = $client_factory;
    }

    /**
     * @param \ArrayAccess<string,mixed> $app Tygh::$app
     */
    public static function fromContainer($app): self
    {
        return new self(
            $app['kwtsms.config'],
            $app['kwtsms.logs.debug'],
            $app['kwtsms.vendor_phones'],
            $app['kwtsms.vendor_quota'],
            $app['kwtsms.api_client_factory']
        );
    }

    // ================================================================== GET kwtsms.vendors

    /**
     * Everything the template shows.
     *
     * @param callable $has_privilege (string $privilege): bool, for the links to other pages
     *
     * @return array<string,mixed>
     */
    public function viewData(callable $has_privilege, int $page = 1): array
    {
        $month_start = $this->quota->monthStart();
        $total = $this->countVendors();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);

        $rows = [];
        foreach ($this->listVendors(($page - 1) * self::PER_PAGE, self::PER_PAGE) as $vendor) {
            $id = (int) $vendor['company_id'];
            $state = $this->phones->state($id);
            $limit = $this->quota->limit($id);
            $rows[] = [
                'company_id' => $id,
                'name' => (string) $vendor['company'],
                'active' => (string) $vendor['status'] === VendorPhones::STATUS_ACTIVE,
                'phone' => $state['raw'],
                'normalized' => $state['normalized'],
                // 2026-10-23 08:34 +0300: cleanup CA2-4 (A8 copy), task 596. "Number used": on a phone-changed row it
                // is the number that
                // was approved (the profile number is not used until it is approved), marked as changed.
                'number' => $state['status'] === 'changed' ? $state['approved_phone'] : $state['normalized'],
                'number_changed' => $state['status'] === 'changed',
                'status' => $state['status'],
                'reason' => $state['reason'],
                'approved_at' => $state['approved_at'],
                'used' => $this->quota->used($id, $month_start),
                'limit' => $limit,
                'can_approve' => (string) $vendor['status'] === VendorPhones::STATUS_ACTIVE
                    && in_array($state['status'], ['none', 'changed', 'approved'], true),
                'can_revoke' => $state['status'] === 'approved',
            ];
        }

        $plans = [];
        $active_plans = $this->plansActive();
        if ($active_plans) {
            $stored = $this->quota->planLimits();
            foreach ($this->plans() as $plan_id => $name) {
                $plans[] = [
                    'plan_id' => (int) $plan_id,
                    'name' => (string) $name,
                    'limit' => array_key_exists((int) $plan_id, $stored) ? $stored[(int) $plan_id] : null,
                ];
            }
        }

        $switches = $this->switchStates();

        return [
            'is_multivendor' => $this->isMultiVendor(),
            'sms_enabled' => $this->config->enabled(),
            'default_quota' => $this->config->vendorMonthlyQuota(),
            'max_quota' => self::MAX_QUOTA,
            'plans_active' => $active_plans,
            'plans' => $plans,
            'rows' => $rows,
            'month_start' => $month_start,
            'switches' => [
                'placed' => $switches[Defaults::EVENT_PLACED]['V'] ?? null,
                'low_stock' => $switches[Defaults::EVENT_LOW_STOCK]['V'] ?? null,
            ],
            // 2026-10-23 08:34 +0300: cleanup CA2-4 (A8 copy), task 596. The "Both are off" note shows only while
            // neither switch is On.
            'switches_all_off' => ($switches[Defaults::EVENT_PLACED]['V'] ?? null) !== true
                && ($switches[Defaults::EVENT_LOW_STOCK]['V'] ?? null) !== true,
            'search' => ['page' => $page, 'pages' => $pages, 'total' => $total, 'per_page' => self::PER_PAGE],
            'prev_url' => $page > 1 ? 'kwtsms.vendors?page=' . ($page - 1) : '',
            'next_url' => $page < $pages ? 'kwtsms.vendors?page=' . ($page + 1) : '',
            'can_open_templates' => call_user_func($has_privilege, 'manage_kwtsms') === true,
        ];
    }

    // ================================================================== POST update_vendor_phone

    /**
     * Approves or revokes the SMS number of one vendor.
     *
     * @param mixed  $posted   kwtsms_vendor of the request (company_id)
     * @param string $action   approve | revoke (anything else changes nothing)
     * @param int    $admin_id The signed-in root administrator
     *
     * @return array{ok:bool,reason:string,company_id:int}
     */
    public function updatePhone($posted, string $action, int $admin_id): array
    {
        $posted = is_array($posted) ? $posted : [];
        $company_id = isset($posted['company_id']) && is_string($posted['company_id'])
            && ctype_digit($posted['company_id']) && strlen($posted['company_id']) <= 10
            ? (int) $posted['company_id'] : 0;
        $result = ['ok' => false, 'reason' => '', 'company_id' => $company_id];

        if (!$this->isMultiVendor()) {
            $this->notify('E', $this->text('kwtsms.vendors.mve_only'), false);
            $result['reason'] = 'not_multivendor';

            return $result;
        }
        if (($action !== 'approve' && $action !== 'revoke') || $company_id <= 0 || !$this->vendorExists($company_id)) {
            $this->notify('E', $this->text('kwtsms.vendors.unknown'), false);
            $result['reason'] = 'no_company';

            return $result;
        }
        $name = $this->companyName($company_id);

        if ($action === 'revoke') {
            $this->phones->revoke($company_id);
            $this->log->log('info', 'vendors.revoke', 'vendor number approval revoked', [
                'company_id' => $company_id, 'admin_id' => $admin_id,
            ]);
            $this->notify('N', $this->text('kwtsms.vendors.revoked', ['[vendor]' => $name]), false);

            return ['ok' => true, 'reason' => '', 'company_id' => $company_id];
        }

        $approved = $this->phones->approve($company_id, $admin_id);
        $this->log->log('info', 'vendors.approve', $approved['ok'] ? 'approved' : 'refused', [
            'company_id' => $company_id, 'admin_id' => $admin_id, 'reason' => $approved['reason'],
        ]);
        if (!$approved['ok']) {
            $key = 'kwtsms.alerts.reason.' . $approved['reason'];
            $checks = ['empty', 'email', 'too_short', 'too_long', 'bad_length', 'not_mobile'];
            $why = in_array($approved['reason'], $checks, true)
                ? $this->text($key, ['[max]' => '0'])
                : $this->text('kwtsms.vendors.reason.' . $approved['reason']);
            $this->notify(
                'E',
                $this->text('kwtsms.vendors.not_approved', ['[vendor]' => $name, '[reason]' => $why]),
                false
            );
            $result['reason'] = $approved['reason'];

            return $result;
        }
        $this->notify('N', $this->text('kwtsms.vendors.approved', ['[vendor]' => $name]), false);
        $this->verdictNotice($approved['phone']);

        return ['ok' => true, 'reason' => '', 'company_id' => $company_id];
    }

    /**
     * The kwtSMS /validate/ verdict of the approved number as a warning (never blocks the approval), like Admin SMS
     * Alerts: a number kwtSMS cannot route, or one it could not be asked about.
     */
    private function verdictNotice(string $phone): void
    {
        if ($this->config->credentialsState() !== ConfigInterface::CREDENTIALS_OK) {
            $this->notify('W', $this->text('kwtsms.vendors.warn.unchecked'), false);

            return;
        }
        $verdict = AlertsPage::verdicts([$phone], $this->gatewayValidate([$phone]))[$phone];
        if ($verdict['status'] === 'cannot_route') {
            $this->notify('W', $this->text('kwtsms.vendors.warn.cannot_route'), false);
        } elseif ($verdict['status'] === 'unchecked') {
            $this->notify('W', $this->text('kwtsms.vendors.warn.unchecked'), false);
        }
    }

    // ================================================================== POST update_vendor_quotas

    /**
     * Saves the default monthly quota and the per-plan limits.
     *
     * @param mixed $posted kwtsms_quota of the request: default, plans[plan_id]
     *
     * @return array{saved:bool,rejected:string[]}
     */
    public function updateQuotas($posted): array
    {
        $posted = is_array($posted) ? $posted : [];
        $result = ['saved' => false, 'rejected' => []];
        if (!$this->isMultiVendor()) {
            $this->notify('E', $this->text('kwtsms.vendors.mve_only'), false);

            return $result;
        }

        if (array_key_exists('default', $posted)) {
            $value = self::wholeNumber($posted['default']);
            $refused = $value === null
                || $this->config->save('update_vendor_quotas', ['vendor_monthly_quota' => (string) $value]) !== [];
            if ($refused) {
                $result['rejected'][] = 'default';
            }
        }
        if ($this->plansActive() && isset($posted['plans']) && is_array($posted['plans'])) {
            $known = $this->plans();
            foreach ($posted['plans'] as $plan_id => $raw) {
                $plan_id = is_string($plan_id) || is_int($plan_id) ? (string) $plan_id : '';
                if (!ctype_digit($plan_id) || strlen($plan_id) > 10 || !isset($known[(int) $plan_id])) {
                    $result['rejected'][] = 'plan';
                    continue;
                }
                if (is_string($raw) && trim($raw) === '') {
                    $this->quota->setPlanLimit((int) $plan_id, null);
                    continue;
                }
                $value = self::wholeNumber($raw);
                if ($value === null) {
                    $result['rejected'][] = 'plan';
                    continue;
                }
                $this->quota->setPlanLimit((int) $plan_id, $value);
            }
        }

        $this->log->log('info', 'vendors.quotas', 'update_vendor_quotas', ['rejected' => $result['rejected']]);
        if ($result['rejected'] === []) {
            $result['saved'] = true;
            $this->notify('N', $this->text('kwtsms.vendors.quota.saved'), false);
        } else {
            $this->notify(
                'E',
                $this->text('kwtsms.vendors.quota.not_saved', ['[max]' => (string) self::MAX_QUOTA]),
                false
            );
        }

        return $result;
    }

    /**
     * A whole number from 0 to MAX_QUOTA typed as digits (Latin or Arabic-Indic), else null.
     *
     * @param mixed $raw
     */
    public static function wholeNumber($raw): ?int
    {
        if (!is_string($raw)) {
            return null;
        }
        $digits = strtr(trim($raw), [
            "\u{0660}" => '0', "\u{0661}" => '1', "\u{0662}" => '2', "\u{0663}" => '3', "\u{0664}" => '4',
            "\u{0665}" => '5', "\u{0666}" => '6', "\u{0667}" => '7', "\u{0668}" => '8', "\u{0669}" => '9',
        ]);
        if ($digits === '' || strlen($digits) > 6 || !ctype_digit($digits)) {
            return null;
        }
        $value = (int) $digits;

        return $value <= self::MAX_QUOTA ? $value : null;
    }

    // ================================================================== seams

    protected function isMultiVendor(): bool
    {
        return function_exists('fn_allowed_for') && fn_allowed_for('MULTIVENDOR');
    }

    protected function plansActive(): bool
    {
        return (string) \Tygh\Registry::get('addons.vendor_plans.status') === 'A';
    }

    protected function countVendors(): int
    {
        return (int) db_get_field('SELECT COUNT(*) FROM ?:companies WHERE company_id > 0');
    }

    /**
     * @return array<int,array<string,mixed>> company_id, company, status
     */
    protected function listVendors(int $offset, int $limit): array
    {
        return (array) db_get_array(
            'SELECT company_id, company, status FROM ?:companies WHERE company_id > 0'
            . ' ORDER BY company ASC, company_id ASC LIMIT ?i, ?i',
            $offset,
            $limit
        );
    }

    protected function vendorExists(int $company_id): bool
    {
        $found = db_get_field('SELECT company_id FROM ?:companies WHERE company_id = ?i', $company_id);

        return (int) $found === $company_id;
    }

    protected function companyName(int $company_id): string
    {
        return (string) db_get_field('SELECT company FROM ?:companies WHERE company_id = ?i', $company_id);
    }

    /**
     * Vendor plan id => name in the admin language.
     *
     * @return array<int,string>
     */
    protected function plans(): array
    {
        $lang = defined('CART_LANGUAGE') ? (string) CART_LANGUAGE : 'en';
        $rows = (array) db_get_array(
            'SELECT p.plan_id, d.plan FROM ?:vendor_plans AS p'
            . ' LEFT JOIN ?:vendor_plan_descriptions AS d ON d.plan_id = p.plan_id AND d.lang_code = ?s'
            . ' ORDER BY p.position ASC, p.plan_id ASC',
            $lang
        );
        $out = [];
        foreach ($rows as $row) {
            $name = $row['plan'] !== null && $row['plan'] !== '' ? $row['plan'] : $row['plan_id'];
            $out[(int) $row['plan_id']] = (string) $name;
        }

        return $out;
    }

    /**
     * ApiClient::validate() for the numbers, in the admin context. Never throws: a failure is an ERROR reply with a
     * code.
     *
     * @param string[] $numbers
     *
     * @return array<string,mixed>
     */
    protected function gatewayValidate(array $numbers): array
    {
        try {
            $client = call_user_func($this->client_factory, 'admin');

            return (array) $client->validate($numbers);
        } catch (\Throwable $e) {
            $this->log->log('error', 'vendors.validate_failed', get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());

            return ['result' => 'ERROR', 'code' => 'LOCAL_ERROR'];
        }
    }

    /**
     * Event id => receiver => effective SMS switch (the service the dispatcher reads, fail closed).
     *
     * @return array<string,array<string,bool>>
     */
    protected function switchStates(): array
    {
        $out = [];
        foreach ((array) \Tygh\Providers\EventDispatcherProvider::getNotificationSettings() as $event_id => $event) {
            if (!is_array($event) || !isset($event['receivers']) || !is_array($event['receivers'])) {
                continue;
            }
            foreach ($event['receivers'] as $receiver => $transports) {
                if (is_array($transports) && array_key_exists(self::TRANSPORT, $transports)) {
                    $out[(string) $event_id][(string) $receiver] = (bool) $transports[self::TRANSPORT];
                }
            }
        }

        return $out;
    }

    /**
     * A language variable with its placeholder values escaped (PRD 10).
     *
     * @param array<string,string> $params
     */
    protected function text(string $key, array $params = []): string
    {
        $escaped = [];
        foreach ($params as $name => $value) {
            $escaped[$name] = fn_html_escape((string) $value);
        }

        return (string) __($key, $escaped);
    }

    /** A notification: N notice, W warning, E error. */
    protected function notify(string $type, string $message, bool $escape = true): void
    {
        $titles = ['N' => 'notice', 'W' => 'warning', 'E' => 'error'];
        fn_set_notification($type, __($titles[$type] ?? 'notice'), $escape ? fn_html_escape($message) : $message);
    }
}
