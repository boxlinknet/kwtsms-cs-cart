<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/IntegrationsPage.php
 * CREATED:   2026-10-21 04:16 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The Integrations page of the kwtsms admin controller: the phone readiness checks of the core store, the
 *            placed status set behind the new-order rule, the Multi-Vendor settings (Multi-Vendor only), the core
 *            "SMS notifications" (Clickatell) warning, the CAPTCHA recommendation, the add-ons that come later, and the
 *            read-only default country. Handles update_integrations.
 * WHY:       PRD 6.3 and 6.7. An SMS needs a phone number the customer had to give and the gateway can route: the three
 *            readiness checks (a phone field required at checkout, the core setting Supported phone numbers set to
 *            "Phone number with country selection", the store default country equal to the add-on's country hint) each
 *            link to the core page that fixes them. The four Multi-Vendor settings exist only in Multi-Vendor (the page
 *            does not show them elsewhere and a POST for them elsewhere changes nothing). Core sms_notifications sends
 *            its own SMS, so an active one is a warning. Fake guest orders cost credits, so a CAPTCHA on the checkout
 *            scenario is recommended. The default country is edited on Settings only. Every link is shown only to an
 *            administrator who may open the core page. The labels use the names the core gives its own setting.
 * USAGE:     $page = IntegrationsPage::fromContainer(Tygh::$app);
 *            $view = $page->viewData($has_privilege);        // GET kwtsms.integrations; callable (string): bool
 *            $page->update($_REQUEST['kwtsms_integrations']);   // POST update_integrations
 *            IntegrationsPage::phoneChecks($fields, $mode, $store_iso, $hint)   // pure
 * DEPENDS:   Settings\ConfigInterface, Sms\PhoneNormalizer, Orders\NewOrderDetector, Log\DebugLog; CS-Cart
 *            db_get_array(), Registry, fn_get_order_paid_statuses(), fn_get_simple_statuses(), fn_allowed_for(),
 *            fn_set_notification(), __() (through protected methods a test replaces).
 * CHANGED:   2026-10-21 07:02 +03: header tidied; the readiness labels use the core names (fix round 1, Tasks 1.13
 *            and 1.15, task 596).
 */

namespace Tygh\Addons\Kwtsms\Admin;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Orders\NewOrderDetector;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;

class IntegrationsPage
{
    /** The Multi-Vendor settings, the only keys update_integrations may write. */
    public const MVE_KEYS = [
        'mve_customer_new_order', 'mve_admin_new_order', 'mve_customer_status_scope', 'mve_vendor_triggered_sms',
    ];

    /** The value lists of the three enumerated Multi-Vendor settings (equal to Config's allow-lists). */
    public const MVE_OPTIONS = [
        'mve_customer_new_order' => ['per_checkout', 'per_suborder'],
        'mve_admin_new_order' => ['per_checkout', 'per_suborder', 'off'],
        'mve_customer_status_scope' => ['auto', 'per_suborder', 'per_checkout'],
    ];

    /** Core's "Structure and fields" of the checkout (block manager), where a checkout field is shown or required. */
    public const CHECKOUT_FIELDS_LINK = 'customization.update_mode?type=block_manager&status=enable'
        . '&frontend_url=customization.edit_checkout';

    /** Core phone validation mode that lets the customer pick the country (PRD 6.3). */
    public const MODE_COUNTRY_SELECTION = 'phone_number_with_country_selection';

    /**
     * Add-ons whose events get SMS in a later version (PRD 6.5 phase 2), shown as "Available in a later version".
     *
     * @var string[]
     */
    // 2026-10-23 04:21 +03: Task A13, task 596. Back in stock and product reviews listed on their own, the rest of the
    // list is "Coming in 1.2".
    public const LATER_ADDONS = [
        'rma', 'call_requests', 'back_in_stock', 'gift_certificates', 'product_reviews', 'discussion',
        'vendor_communication',
    ];

    /**
     * 2026-10-23 04:21 +03: Task A13, task 596. The areas that send SMS in 1.1, in page order; 'vendors' exists in
     * Multi-Vendor only.
     *
     * @var string[]
     */
    public const AREAS = ['orders', 'storefronts', 'profiles', 'low_stock', 'vendors'];

    /** @var ConfigInterface */
    private $config;

    /** @var DebugLog */
    private $log;

    public function __construct(ConfigInterface $config, DebugLog $log)
    {
        $this->config = $config;
        $this->log = $log;
    }

    /**
     * @param \ArrayAccess<string,mixed> $app Tygh::$app
     */
    public static function fromContainer($app): self
    {
        return new self($app['kwtsms.config'], $app['kwtsms.logs.debug']);
    }

    // ================================================================== pure rules

    /**
     * The three phone readiness checks (PRD 6.3).
     *
     * @param array<int,array<string,mixed>> $fields Profile fields of type phone: field_id, field_name,
     * checkout_show,
     *                                                  checkout_required
     * @param string                         $mode      Core Appearance > phone_validation_mode
     * @param string                         $store_iso Core default country (ISO 3166-1 alpha-2)
     * @param string                         $hint      The add-on's default country calling code
     *
     * @return array<int,array<string,mixed>>
     */
    public static function phoneChecks(array $fields, string $mode, string $store_iso, string $hint): array
    {
        // 1. A phone field that is shown AND required at checkout (it reaches every order, guests too).
        $required = null;
        $shown = null;
        foreach ($fields as $field) {
            $is_shown = (string) ($field['checkout_show'] ?? '') === 'Y';
            $is_required = (string) ($field['checkout_required'] ?? '') === 'Y';
            if ($is_shown && $is_required && $required === null) {
                $required = $field;
            }
            if ($is_shown && $shown === null) {
                $shown = $field;
            }
        }
        if ($required !== null) {
            $phone = ['ok' => true, 'detail' => 'ok', 'field_id' => (int) $required['field_id']];
        } elseif ($shown !== null) {
            $phone = ['ok' => false, 'detail' => 'not_required', 'field_id' => (int) $shown['field_id']];
        } elseif ($fields !== []) {
            $phone = ['ok' => false, 'detail' => 'not_shown', 'field_id' => (int) $fields[0]['field_id']];
        } else {
            $phone = ['ok' => false, 'detail' => 'no_field', 'field_id' => 0];
        }

        // 3. The store default country has the add-on's calling code.
        $store_code = $store_iso === '' ? null : PhoneNormalizer::callingCodeForIso($store_iso);
        if ($store_code === null) {
            $country = ['ok' => false, 'detail' => 'unknown', 'store_code' => ''];
        } else {
            $same = $store_code === $hint;
            $country = ['ok' => $same, 'detail' => $same ? 'ok' : 'mismatch', 'store_code' => $store_code];
        }

        return [
            ['id' => 'phone_required'] + $phone,
            ['id' => 'validation_mode', 'ok' => $mode === self::MODE_COUNTRY_SELECTION, 'detail' => $mode],
            ['id' => 'default_country'] + $country + ['addon_code' => $hint],
        ];
    }

    // ================================================================== GET kwtsms.integrations

    /**
     * Everything the template shows.
     *
     * @param callable $has_privilege (string $privilege): bool, for the core privileges behind the links
     *
     * @return array<string,mixed>
     */
    public function viewData(callable $has_privilege): array
    {
        $hint = $this->config->defaultCountryCode();
        $checks = self::phoneChecks(
            $this->phoneFields(),
            $this->phoneValidationMode(),
            $this->storeDefaultCountry(),
            $hint
        );
        // 2026-10-21 04:19 +03: in CS-Cart 4.21 the checkout field flags (shown, required) are edited in the block
        // manager
        // ("Structure and fields" of the checkout), which needs the core edit_blocks privilege; the profile field
        // page no
        // longer holds them (Task 1.13, task 596).
        $may_checkout_fields = call_user_func($has_privilege, 'edit_blocks') === true;
        $may_users = call_user_func($has_privilege, 'view_users') === true;
        $may_settings = call_user_func($has_privilege, 'view_settings') === true;
        $may_addons = call_user_func($has_privilege, 'update_settings') === true;

        $ready = true;
        foreach ($checks as $i => $check) {
            $ready = $ready && $check['ok'];
            if ($check['id'] === 'phone_required') {
                if ($check['detail'] === 'no_field') {
                    // No phone field at all: the profile fields list is where one is added.
                    $checks[$i]['link'] = $may_users ? 'profile_fields.manage' : '';
                } else {
                    $checks[$i]['link'] = $may_checkout_fields ? self::CHECKOUT_FIELDS_LINK : '';
                }
            } elseif ($check['id'] === 'validation_mode') {
                $checks[$i]['link'] = $may_settings ? 'settings.manage?section_id=Appearance' : '';
            } else {
                $checks[$i]['link'] = $may_settings ? 'settings.manage?section_id=Checkout' : '';
            }
        }

        $is_mve = $this->isMultiVendor();
        $later = [];
        foreach (self::LATER_ADDONS as $id) {
            $later[] = ['id' => $id, 'active' => $this->addonActive($id)];
        }

        return [
            'checks' => $checks,
            'ready' => $ready,
            'default_country_code' => $hint,
            'default_country_label' => $this->countryLabel($hint),
            'placed_statuses' => $this->placedStatuses(),
            'is_multivendor' => $is_mve,
            'mve' => $is_mve ? $this->mveView() : null,
            // 2026-10-22 22:28 +03: Task A8, task 596. The Vendor SMS page (Multi-Vendor only) is linked for an
            // administrator who may open it.
            'vendor_sms_link' => $is_mve && call_user_func($has_privilege, 'manage_kwtsms') === true
                ? 'kwtsms.vendors' : '',
            // 2026-10-23 04:21 +03: Task A13, task 596. What sends SMS in 1.1, with the live numbers.
            'areas' => $this->areas($is_mve, $has_privilege),
            'clickatell' => [
                'active' => $this->addonActive('sms_notifications'),
                'link' => $may_addons ? 'addons.manage' : '',
            ],
            'captcha' => [
                'checkout' => $this->captchaForCheckout(),
                'recaptcha' => $this->addonActive('recaptcha'),
                'link' => $may_settings ? 'settings.manage?section_id=Image_verification' : '',
            ],
            'later' => $later,
        ];
    }

    /**
     * The areas that send SMS: orders (status, updated, shipments), storefronts, profile events, low stock (with the
     * core threshold) and, in Multi-Vendor, vendor SMS (approved phones, quota). Each row: id, params for the text,
     * link (page mode, '' when the administrator may not open it).
     *
     * 2026-10-23 04:21 +03: Task A13, task 596.
     *
     * @return array<int,array<string,mixed>>
     */
    private function areas(bool $is_mve, callable $has_privilege): array
    {
        $may = call_user_func($has_privilege, 'manage_kwtsms') === true;
        $may_settings = call_user_func($has_privilege, 'view_settings') === true;
        $stores = $this->storefrontCounts();
        $vendors = $is_mve ? $this->vendorCounts() : ['approved' => 0, 'total' => 0];
        $quota = $this->config->vendorMonthlyQuota();
        $rows = [
            ['id' => 'orders', 'params' => [], 'link' => $may ? 'kwtsms.templates' : ''],
            [
                'id' => 'storefronts',
                'params' => ['[count]' => (string) $stores['total'], '[own]' => (string) $stores['own']],
                'link' => $may ? 'kwtsms.settings' : '',
            ],
            ['id' => 'profiles', 'params' => [], 'link' => $may ? 'kwtsms.templates' : ''],
            [
                'id' => 'low_stock',
                'params' => ['[threshold]' => (string) $this->lowStockThreshold()],
                'link' => $may_settings ? 'settings.manage?section_id=General' : '',
            ],
        ];
        if ($is_mve) {
            $rows[] = [
                'id' => 'vendors',
                'params' => [
                    '[approved]' => (string) $vendors['approved'],
                    '[total]' => (string) $vendors['total'],
                    '[quota]' => (string) $quota,
                ],
                'quota_off' => $quota <= 0,
                // 2026-10-23 08:41 +0300: cleanup CA2-8 (A13 minor 4), task 596. Per-plan limits override the quota while vendor_plans is on.
                'plans' => $this->addonActive('vendor_plans'),
                'link' => $may ? 'kwtsms.vendors' : '',
            ];
        }

        return $rows;
    }

    /**
     * @return array{values: array<string,string>, options: array<string,string[]>}
     */
    private function mveView(): array
    {
        return [
            'values' => [
                'mve_customer_new_order' => $this->config->mveCustomerNewOrder(),
                'mve_admin_new_order' => $this->config->mveAdminNewOrder(),
                'mve_customer_status_scope' => $this->config->mveCustomerStatusScope(),
                'mve_vendor_triggered_sms' => $this->config->mveVendorTriggeredSms() ? 'Y' : 'N',
            ],
            'options' => self::MVE_OPTIONS,
        ];
    }

    /**
     * The statuses that count as "order placed": the core paid statuses plus the configured extras, code => name.
     *
     * @return array<string,string>
     */
    private function placedStatuses(): array
    {
        $names = $this->statusNames();
        $out = [];
        foreach (NewOrderDetector::placedStatuses($this->paidStatuses(), $this->config->placedStatusExtra()) as $code) {
            $out[$code] = isset($names[$code]) && $names[$code] !== '' ? $names[$code] : $code;
        }

        return $out;
    }

    // ================================================================== POST update_integrations

    /**
     * Saves the Multi-Vendor settings. Outside Multi-Vendor nothing is written.
     *
     * @param mixed $posted kwtsms_integrations of the request
     *
     * @return array{saved: string[], rejected: string[], refused_edition: bool}
     */
    public function update($posted): array
    {
        $posted = is_array($posted) ? $posted : [];
        if (!$this->isMultiVendor()) {
            $this->log->log('info', 'integrations.update', 'refused', ['reason' => 'not_multivendor']);
            $this->notify('E', $this->text('kwtsms.integrations.mve_only'), false);

            return ['saved' => [], 'rejected' => [], 'refused_edition' => true];
        }

        $values = [];
        $rejected = [];
        foreach (self::MVE_KEYS as $key) {
            if (!array_key_exists($key, $posted)) {
                continue;
            }
            if (!is_string($posted[$key])) {
                $rejected[] = $key;
                continue;
            }
            $values[$key] = $posted[$key];
        }
        if ($values !== []) {
            foreach ($this->config->save('update_integrations', $values) as $key) {
                $rejected[] = (string) $key;
            }
        }
        $rejected = array_values(array_unique($rejected));
        $saved = array_values(array_diff(array_keys($values), $rejected));

        $this->log->log('info', 'integrations.update', 'update_integrations', [
            'saved' => $saved,
            'rejected' => $rejected,
        ]);

        if ($saved !== []) {
            $done = $rejected === [] ? 'kwtsms.integrations.saved' : 'kwtsms.integrations.saved_others';
            $this->notify('N', $this->text($done), false);
        }
        foreach ($rejected as $key) {
            $field = (string) __('kwtsms.integrations.field.' . $key);
            $this->notify('E', $this->text('kwtsms.integrations.not_saved', ['[field]' => $field]), false);
        }

        return ['saved' => $saved, 'rejected' => $rejected, 'refused_edition' => false];
    }

    // ================================================================== seams

    /**
     * Profile fields of type phone for customers, with their checkout flags.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function phoneFields(): array
    {
        return (array) db_get_array(
            'SELECT field_id, field_name, checkout_show, checkout_required FROM ?:profile_fields'
            . ' WHERE field_type = ?s AND profile_type = ?s ORDER BY position, field_id',
            'P',
            'U'
        );
    }

    protected function phoneValidationMode(): string
    {
        return (string) \Tygh\Registry::get('settings.Appearance.phone_validation_mode');
    }

    protected function storeDefaultCountry(): string
    {
        $iso = (string) \Tygh\Registry::get('settings.General.default_country');
        if ($iso === '') {
            $iso = (string) \Tygh\Registry::get('settings.Checkout.default_country');
        }

        return strtoupper($iso);
    }

    /**
     * Storefronts and how many of them have their own Sender ID. 2026-10-23 04:21 +03: Task A13, task 596.
     *
     * @return array{total:int,own:int}
     */
    protected function storefrontCounts(): array
    {
        return [
            'total' => (int) db_get_field('SELECT COUNT(*) FROM ?:storefronts'),
            'own' => (int) db_get_field(
                'SELECT COUNT(*) FROM ?:kwtsms_brand_names b INNER JOIN ?:storefronts s'
                . ' ON s.storefront_id = b.storefront_id WHERE b.sender_id <> ?s',
                ''
            ),
        ];
    }

    /**
     * Active vendors and those with an approved phone. 2026-10-23 04:21 +03: Task A13, task 596.
     *
     * @return array{approved:int,total:int}
     */
    protected function vendorCounts(): array
    {
        return [
            'total' => (int) db_get_field('SELECT COUNT(*) FROM ?:companies WHERE status = ?s', 'A'),
            'approved' => (int) db_get_field(
                'SELECT COUNT(*) FROM ?:kwtsms_vendor_phones p INNER JOIN ?:companies c'
                . ' ON c.company_id = p.company_id WHERE c.status = ?s AND p.approved_at > 0 AND p.phone <> ?s',
                'A',
                ''
            ),
        ];
    }

    /** The core low stock notification threshold (Settings > General). 2026-10-23 04:21 +03: Task A13, task 596. */
    protected function lowStockThreshold(): int
    {
        return (int) \Tygh\Registry::get('settings.General.low_stock_threshold');
    }

    protected function isMultiVendor(): bool
    {
        return function_exists('fn_allowed_for') && fn_allowed_for('MULTIVENDOR');
    }

    protected function addonActive(string $id): bool
    {
        return (string) \Tygh\Registry::get('addons.' . $id . '.status') === 'A';
    }

    /** Whether the core "checkout" CAPTCHA scenario is on (the recaptcha add-on shares it). */
    protected function captchaForCheckout(): bool
    {
        return (string) \Tygh\Registry::get('settings.Image_verification.use_for.checkout') === 'Y';
    }

    /**
     * @return array<int,mixed>
     */
    protected function paidStatuses(): array
    {
        return (array) fn_get_order_paid_statuses();
    }

    /**
     * Order status code => name in the admin language.
     *
     * @return array<string,string>
     */
    protected function statusNames(): array
    {
        return array_map('strval', (array) fn_get_simple_statuses(STATUSES_ORDER, false, true));
    }

    /** "Kuwait (+965)" in the admin language; the bare code when the country is not known. */
    protected function countryLabel(string $code): string
    {
        $options = PhoneNormalizer::countryOptions();
        $lang = defined('CART_LANGUAGE') ? (string) CART_LANGUAGE : 'en';
        try {
            $names = (array) db_get_hash_single_array(
                'SELECT code, country FROM ?:country_descriptions WHERE lang_code = ?s',
                ['code', 'country'],
                $lang
            );
        } catch (\Throwable $e) {
            $this->log->log('error', 'integrations.country_names_failed', get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
            $names = [];
        }
        $localized = SettingsPage::localizeCountryOptions($options, array_map('strval', $names));

        if (ctype_digit($code) && isset($localized[(int) $code])) {
            return (string) $localized[(int) $code];
        }

        return '(+' . $code . ')';
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
