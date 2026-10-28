<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Settings/Config.php
 * CREATED:   2026-10-20 21:45 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The add-on's typed settings (one getter per PRD 9 setting) and the only code that writes them:
 *            save($mode, $values) writes the keys that mode may write, each validated and cast, and returns
 *            the rejected keys.
 * WHY:       PRD 9: every mode saves only its own allow-listed keys; credentials only through login;
 *            cron_key only through Regenerate; sender_id only from the synced list; enabled only when
 *            configured. A posted form can carry any field, so nothing outside Config writes a setting and
 *            a getter never returns a stored value that would not have passed save(). The API password is
 *            stored as fn_encrypt_text('kwtsms1:' . $password) and password() requires that prefix after
 *            decrypting, so a changed crypt key (or a tampered value) reads as "log in again", never as a
 *            wrong password sent to the gateway.
 * USAGE:     $config = new Config(new CsCartSettingsBackend(), $gatewayState);   (wired in Task 1.6)
 *            $config->capRecipientHour();                                   // int, default 5
 *            $rejected = $config->save('save_settings', $_REQUEST['kwtsms_settings']);   // e.g. ['sender_id']
 *            $config->save('login', ['api_username' => $u, 'api_password' => $p]);       // both or neither
 *            $key = $config->regenerateCronKey();
 *            Modes and their keys: Config::MODE_KEYS. Defaults (equal to addon.xml): Config::defaults().
 *            2026-10-20 21:45 +03, decisions beyond the brief: mode "logout" (PRD 6.1 Disconnect) may only
 *            clear api_username, api_password, sender_id and set enabled to N; credentialsState() reports
 *            none / ok / relogin_required; limits of the numeric keys are listed in SPECS.
 * DEPENDS:   Settings\SettingsBackendInterface, Gateway\GatewayState, Sms\PhoneNormalizer (country list),
 *            CS-Cart fn_encrypt_text() and fn_decrypt_text() unless crypt callables are injected (tests).
 */

namespace Tygh\Addons\Kwtsms\Settings;

use Tygh\Addons\Kwtsms\Gateway\GatewayState;
use Tygh\Addons\Kwtsms\Log\ReportsFailures;
use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;

class Config implements ConfigInterface
{
    // 2026-10-21 03:19 +03: safeMessage() for the reason of a refused write (no query, no value), Task 1.10 fix
    // round 1 item 14 (carry from Task 1.4), task 596.
    use ReportsFailures;

    /** Prepended to the password before encryption and required after decryption. */
    public const PASSWORD_PREFIX = 'kwtsms1:';

    /**
     * Default of every setting, as stored text. Equal to the hidden settings of addon.xml (a unit test
     * compares them). cron_key is generated at install, so its default is empty.
     *
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'enabled' => 'N',
        'test_mode' => 'Y',
        'pilot_mode' => 'N',
        'default_country_code' => '965',
        'customer_sms_countries' => '965',
        'placed_status_extra' => 'B',
        'quiet_hours_enabled' => 'N',
        'quiet_hours_from' => '22:00',
        'quiet_hours_to' => '08:00',
        'max_pages_notification' => '3',
        'api_username' => '',
        'api_password' => '',
        'sender_id' => '',
        'sender_type' => 'transactional',
        'sender_id_promotional' => '',
        'cap_recipient_hour' => '5',
        'cap_recipient_day' => '20',
        'cap_admin_day' => '300',
        'cap_store_day' => '1000',
        'low_balance_threshold' => 'auto',
        'admin_phones' => '',
        'admin_alert_lang' => '',
        'mve_customer_new_order' => 'per_checkout',
        'mve_admin_new_order' => 'per_checkout',
        'mve_customer_status_scope' => 'auto',
        'mve_vendor_triggered_sms' => 'Y',
        'log_level' => 'info',
        'retention_messages_days' => '0',
        'retention_attempts_days' => '365',
        'retention_debug_days' => '30',
        'cron_url_enabled' => 'N',
        'cron_key' => '',
        // 2026-10-22 13:15 +03: recipients one vendor may SMS per calendar month (P2-6); equals addon.xml,
        // Task A1, task 596.
        'vendor_monthly_quota' => '100',
        // 2026-10-23 15:12 +03: the phone and SMS-code settings of 1.2.0 (P3 data model); equals addon.xml. Every
        // feature switch is N: nothing turns on by itself. Task M1.1a, task 596.
        'phone_country_check' => 'N',
        'phone_unique' => 'N',
        'register_code' => 'N',
        'register_phone_required' => 'N',
        'phone_change_code' => 'N',
        'phone_change_notice' => 'N',
        'otp_expiry_seconds' => '300',
        'otp_max_attempts' => '5',
        'otp_lockout_seconds' => '900',
        'otp_phone_fail_ceiling' => '20',
        'otp_resend_waits' => '60,120,300',
        'otp_unreachable' => 'pause',
        // 2026-10-24 23:49 +03: Task M1.8a (P3-35, kwtsms.com test checklist 6B), task 596. 20 -> 5 and 100 -> 20: a
        // block after 3 to 5 different numbers from one device. Existing stores keep the values they saved. Equals
        // addon.xml.
        'cap_ip_hour' => '5',
        'cap_ip_day' => '20',
        // 2026-10-24 23:49 +03: Task M1.8a (P3-34), task 596. At most 5 code SMS to one phone per hour, all IPs.
        'cap_phone_hour' => '5',
        'cap_ip_verify_hour' => '30',
        'cap_store_hour' => '0',
        'captcha_after_ip' => '3',
        'captcha_after_subject' => '2',
        'captcha_after_store' => '30',
        'trusted_proxies' => '',
        'turnstile_enabled' => 'N',
        'turnstile_site_key' => '',
        'turnstile_secret' => '',
    ];

    /**
     * 2026-10-23 15:12 +03: the storage_data key of the phone index backfill state (P3-31). Named here so the
     * uninstall list of addon.xml and its parity test stay exact from 1.2.0 on; the backfill (Task M1.6) reads and
     * writes it. Task M1.1a, task 596.
     */
    public const KEY_PHONE_INDEX_STATE = 'kwtsms_phone_index_state';

    /**
     * Which keys each save mode may write, in the order they are validated and written. enabled comes
     * last so that a sender_id saved in the same request counts when "configured" is checked.
     * 2026-10-20 22:18 +03: no mode names cron_key. The key is written by regenerateCronKey() alone, so a caller
     * can never choose one (Task 1.4 fix round 1, task 596).
     *
     * @var array<string, string[]>
     */
    public const MODE_KEYS = [
        'save_settings' => [
            'default_country_code', 'customer_sms_countries', 'placed_status_extra', 'test_mode', 'pilot_mode',
            'quiet_hours_enabled', 'quiet_hours_from', 'quiet_hours_to', 'max_pages_notification',
            'sender_id', 'sender_type',
            'cap_recipient_hour', 'cap_recipient_day', 'cap_admin_day', 'cap_store_day', 'low_balance_threshold',
            'mve_customer_new_order', 'mve_admin_new_order', 'mve_customer_status_scope', 'mve_vendor_triggered_sms',
            'log_level', 'retention_messages_days', 'retention_attempts_days', 'retention_debug_days',
            'cron_url_enabled',
            'enabled',
        ],
        'login' => ['api_username', 'api_password'],
        'logout' => ['api_username', 'api_password', 'sender_id', 'enabled'],
        'update_admin_alerts' => ['admin_phones', 'admin_alert_lang'],
        'update_integrations' => [
            'mve_customer_new_order', 'mve_admin_new_order', 'mve_customer_status_scope', 'mve_vendor_triggered_sms',
        ],
        // 2026-10-22 22:22 +03: Task A8 (SafatMall F4, P2-6), task 596. The Vendor SMS page writes the monthly quota.
        'update_vendor_quotas' => ['vendor_monthly_quota'],
        // 2026-10-23 15:12 +03: Task M1.1a (P3-23), task 596. The "Phone and codes" section of Settings. Only the
        // keys listed here are writable; each later task of Milestone 1 adds the keys of its own fields. The Turnstile
        // secret is first because the save rule for an empty post (keep the stored secret) is part of this task.
        // 2026-10-23 19:56 +03: Task M1.2 (P3-6, P3-7, P3-26), task 596. The per-IP request limits, the store hourly
        // cap and the trusted proxy list.
        // 2026-10-23 21:32 +03: Task M1.3 (P3-2, P3-3, P3-29), task 596. The code lifetime, the wrong-attempt limits,
        // the resend waits and the per-IP verify limit.
        // 2026-10-24 12:38 +03: Task M1.4 (P3-13), task 596. otp_unreachable: pause or continue when codes cannot be
        // sent.
        // 2026-10-24 15:28 +03: Task M1.5 (C3, P3-15), task 596. phone_country_check: the server-side phone rule
        // switch.
        // 2026-10-24 17:14 +03: Task M1.6 (C4, P3-31), task 596. phone_unique: one account per phone (starts after the
        // phone index is built).
        // 2026-10-24 21:10 +03: Task M1.7b (PRD 6.15), task 596. turnstile_enabled and turnstile_site_key: the other
        // two fields of the "CAPTCHA (Cloudflare Turnstile)" section.
        'update_codes' => [
            'turnstile_enabled', 'turnstile_site_key', 'turnstile_secret', 'cap_ip_hour', 'cap_ip_day',
            'cap_store_hour', 'trusted_proxies',
            'otp_expiry_seconds', 'otp_max_attempts', 'otp_lockout_seconds', 'otp_phone_fail_ceiling',
            'otp_resend_waits', 'cap_ip_verify_hour', 'otp_unreachable', 'phone_country_check', 'phone_unique',
            // 2026-10-24 23:49 +03: Task M1.8a (P3-34, SafatMall E2), task 596. The per-phone request cap and the three
            // thresholds after which the code request needs a CAPTCHA token.
            'cap_phone_hour', 'captcha_after_ip', 'captcha_after_subject', 'captcha_after_store',
            // 2026-10-28 11:07 +03: Task M1.10a (SafatMall B1, P3-28), task 596. The registration code and the
            // mandatory phone.
            'register_code', 'register_phone_required',
            // 2026-10-28 20:27 +03: Task M1.10b (SafatMall B5, P3-17), task 596. The phone change code and the
            // notice to the old number.
            'phone_change_code', 'phone_change_notice',
        ],
    ];

    /**
     * Validation of the keys that need no context. Types: flag (Y/N), int (min, max), enum (values),
     * time (HH:MM), country, codes (calling-code list), statuses (order status letters), threshold
     * (auto or a number), phones (up to 10 numbers), lang (two letters or empty).
     * Ranges are mine (PRD 9 gives defaults only): the caps allow 1 up to ten times the default or more,
     * retention allows 0 (keep) up to ten years.
     *
     * @var array<string, array<int, mixed>>
     */
    private const SPECS = [
        'test_mode' => ['flag'],
        'pilot_mode' => ['flag'],
        'default_country_code' => ['country'],
        'customer_sms_countries' => ['codes'],
        'placed_status_extra' => ['statuses'],
        'quiet_hours_enabled' => ['flag'],
        'quiet_hours_from' => ['time'],
        'quiet_hours_to' => ['time'],
        'max_pages_notification' => ['int', 1, 7],
        'sender_type' => ['enum', ['transactional', 'promotional']],
        'cap_recipient_hour' => ['int', 1, 1000],
        'cap_recipient_day' => ['int', 1, 10000],
        'cap_admin_day' => ['int', 1, 100000],
        'cap_store_day' => ['int', 1, 1000000],
        'low_balance_threshold' => ['threshold'],
        'admin_phones' => ['phones'],
        'admin_alert_lang' => ['lang'],
        'mve_customer_new_order' => ['enum', ['per_checkout', 'per_suborder']],
        'mve_admin_new_order' => ['enum', ['per_checkout', 'per_suborder', 'off']],
        'mve_customer_status_scope' => ['enum', ['auto', 'per_suborder', 'per_checkout']],
        'mve_vendor_triggered_sms' => ['flag'],
        'log_level' => ['enum', ['info', 'debug']],
        'retention_messages_days' => ['int', 0, 3650],
        'retention_attempts_days' => ['int', 0, 3650],
        'retention_debug_days' => ['int', 0, 3650],
        // 2026-10-22 13:15 +03: 0 = vendor SMS off; no save mode names it until the Vendor SMS page (Task A8),
        // Task A1, task 596.
        'vendor_monthly_quota' => ['int', 0, 100000],
        'cron_url_enabled' => ['flag'],
        // 2026-10-23 15:12 +03: Task M1.1a, task 596. Ranges of the 1.2.0 settings (P3 data model). The three
        // captcha_after_* ranges are mine (the plan gives defaults only): 1 up to 1000 per IP and subject, 100000 for
        // the store window. 'waits' = 1 to 5 whole numbers (min, max, count) that never decrease; 'proxies' = a list
        // of IPs or CIDR blocks; 'sitekey' = the public Turnstile site key.
        'phone_country_check' => ['flag'],
        'phone_unique' => ['flag'],
        'register_code' => ['flag'],
        'register_phone_required' => ['flag'],
        'phone_change_code' => ['flag'],
        'phone_change_notice' => ['flag'],
        'otp_expiry_seconds' => ['int', 60, 900],
        'otp_max_attempts' => ['int', 3, 10],
        'otp_lockout_seconds' => ['int', 60, 86400],
        'otp_phone_fail_ceiling' => ['int', 5, 1000],
        'otp_resend_waits' => ['waits', 30, 3600, 5],
        'otp_unreachable' => ['enum', ['pause', 'continue']],
        'cap_ip_hour' => ['int', 1, 10000],
        'cap_ip_day' => ['int', 1, 10000],
        'cap_phone_hour' => ['int', 1, 100], // 2026-10-24 23:49 +03: Task M1.8a (P3-34), task 596
        'cap_ip_verify_hour' => ['int', 5, 10000],
        'cap_store_hour' => ['int', 0, 100000],
        'captcha_after_ip' => ['int', 1, 1000],
        'captcha_after_subject' => ['int', 1, 1000],
        'captcha_after_store' => ['int', 1, 100000],
        'trusted_proxies' => ['proxies'],
        'turnstile_enabled' => ['flag'],
        'turnstile_site_key' => ['sitekey'],
    ];

    private const TIME_PATTERN = '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D';
    /** 2026-10-23 17:13 +03: the one site key pattern, read and write side (fix round 1, I1, task 596). */
    private const SITE_KEY_PATTERN = '/^[0-9A-Za-z_\-]{1,100}$/D';
    private const USERNAME_PATTERN = '/^[\x21-\x7E]{1,100}$/D';
    private const MAX_PASSWORD_LENGTH = 200;
    private const MAX_ADMIN_PHONES = 10;
    private const MAX_THRESHOLD = 10000000;

    /** @var SettingsBackendInterface */
    private $backend;

    /** @var GatewayState */
    private $state;

    /** @var callable|null */
    private $encrypt;

    /** @var callable|null */
    private $decrypt;

    /**
     * @var array<string, string> key => why the settings store refused the last write of it (class, safe message,
     *                            file:line; never the value). 2026-10-21 03:19 +03: added, Task 1.10 fix round 1
     *                            item 14, task 596.
     */
    private $write_errors = [];

    /** @var array<int, true>|null calling code => true */
    private static $country_codes;

    /**
     * @param callable|null $encrypt Text to ciphertext; null = fn_encrypt_text (tests inject their own)
     * @param callable|null $decrypt Ciphertext to text; null = fn_decrypt_text
     */
    public function __construct(
        SettingsBackendInterface $backend,
        GatewayState $state,
        ?callable $encrypt = null,
        ?callable $decrypt = null
    ) {
        $this->backend = $backend;
        $this->state = $state;
        $this->encrypt = $encrypt;
        $this->decrypt = $decrypt;
    }

    /**
     * 2026-10-23 17:16 +03: fix round 1, finding M7, task 596. The one rule for "this posted secret field is empty,
     * keep the stored secret": a string that is empty after trim(). Config::save() and SettingsPage::saveCodes()
     * both call it, so they cannot disagree about what was saved.
     *
     * @param mixed $value
     */
    public static function isBlankSecretPost($value): bool
    {
        return is_string($value) && trim($value) === '';
    }

    /**
     * @return array<string, string> Every setting id and its default
     */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    // ------------------------------------------------------------------ getters

    public function enabled(): bool
    {
        return $this->flag('enabled');
    }

    public function testMode(): bool
    {
        return $this->flag('test_mode');
    }

    public function pilotMode(): bool
    {
        return $this->flag('pilot_mode');
    }

    public function defaultCountryCode(): string
    {
        $code = $this->raw('default_country_code');

        return $this->isCountryCode($code) ? $code : self::DEFAULTS['default_country_code'];
    }

    public function customerSmsCountries(): array
    {
        $codes = array_values(array_filter(
            $this->list($this->raw('customer_sms_countries'), '/^[0-9]{1,4}$/D'),
            [$this, 'isCountryCode']
        ));

        return $codes === [] ? [$this->defaultCountryCode()] : $codes;
    }

    public function placedStatusExtra(): array
    {
        return $this->list($this->raw('placed_status_extra'), '/^[A-Z]$/D');
    }

    public function quietHoursEnabled(): bool
    {
        return $this->flag('quiet_hours_enabled');
    }

    public function quietHoursFrom(): string
    {
        return $this->time('quiet_hours_from');
    }

    public function quietHoursTo(): string
    {
        return $this->time('quiet_hours_to');
    }

    public function maxPagesNotification(): int
    {
        return $this->number('max_pages_notification');
    }

    public function apiUsername(): string
    {
        return $this->raw('api_username');
    }

    public function password(): string
    {
        // 2026-10-23 15:12 +03: the decrypt-and-check-prefix steps moved to prefixedSecret(), shared with
        // turnstileSecret() (Task M1.1a, task 596). Behaviour unchanged.
        return $this->prefixedSecret('api_password');
    }

    public function credentialsState(): string
    {
        if ($this->apiUsername() === '' || $this->raw('api_password') === '') {
            return self::CREDENTIALS_NONE;
        }

        return $this->password() !== '' ? self::CREDENTIALS_OK : self::CREDENTIALS_RELOGIN;
    }

    public function senderId(): string
    {
        return $this->raw('sender_id');
    }

    public function senderType(): string
    {
        return $this->enum('sender_type');
    }

    public function senderIdPromotional(): string
    {
        return $this->raw('sender_id_promotional');
    }

    public function capRecipientHour(): int
    {
        return $this->number('cap_recipient_hour');
    }

    public function capRecipientDay(): int
    {
        return $this->number('cap_recipient_day');
    }

    public function capAdminDay(): int
    {
        return $this->number('cap_admin_day');
    }

    public function capStoreDay(): int
    {
        return $this->number('cap_store_day');
    }

    public function lowBalanceThreshold(): ?int
    {
        $value = $this->raw('low_balance_threshold');

        return ($this->isDigits($value, 8) && (int) $value <= self::MAX_THRESHOLD) ? (int) $value : null;
    }

    public function adminPhones(): array
    {
        return array_slice($this->list($this->raw('admin_phones'), '/^[0-9]{7,15}$/D'), 0, self::MAX_ADMIN_PHONES);
    }

    public function adminAlertLang(): string
    {
        $lang = $this->raw('admin_alert_lang');

        return preg_match('/^[a-z]{2}$/D', $lang) === 1 ? $lang : '';
    }

    public function mveCustomerNewOrder(): string
    {
        return $this->enum('mve_customer_new_order');
    }

    public function mveAdminNewOrder(): string
    {
        return $this->enum('mve_admin_new_order');
    }

    public function mveCustomerStatusScope(): string
    {
        return $this->enum('mve_customer_status_scope');
    }

    public function mveVendorTriggeredSms(): bool
    {
        return $this->flag('mve_vendor_triggered_sms');
    }

    /**
     * 2026-10-22 13:15 +03: monthly recipient quota per vendor (P2-6). 100 when the setting object is absent (an
     * upgrade that has not created it yet) or holds anything but a whole number from 0 to 100000; 0 is a real value
     * (vendor SMS off). Task A1, task 596.
     */
    public function vendorMonthlyQuota(): int
    {
        return $this->number('vendor_monthly_quota');
    }

    public function logLevel(): string
    {
        return $this->enum('log_level');
    }

    public function retentionMessagesDays(): int
    {
        return $this->number('retention_messages_days');
    }

    public function retentionAttemptsDays(): int
    {
        return $this->number('retention_attempts_days');
    }

    public function retentionDebugDays(): int
    {
        return $this->number('retention_debug_days');
    }

    public function cronUrlEnabled(): bool
    {
        return $this->flag('cron_url_enabled');
    }

    public function cronKey(): string
    {
        $key = $this->raw('cron_key');

        return preg_match('/^[0-9a-f]{32}$/D', $key) === 1 ? $key : '';
    }

    // ------------------------------------------------------------------ phone and codes (2026-10-23 15:12 +03,
    // Task M1.1a, task 596). A stored value that is out of range or malformed reads as the default.

    public function phoneCountryCheck(): bool
    {
        return $this->flag('phone_country_check');
    }

    public function phoneUnique(): bool
    {
        return $this->flag('phone_unique');
    }

    public function registerCode(): bool
    {
        return $this->flag('register_code');
    }

    public function registerPhoneRequired(): bool
    {
        return $this->flag('register_phone_required');
    }

    public function phoneChangeCode(): bool
    {
        return $this->flag('phone_change_code');
    }

    public function phoneChangeNotice(): bool
    {
        return $this->flag('phone_change_notice');
    }

    public function otpExpirySeconds(): int
    {
        return $this->number('otp_expiry_seconds');
    }

    public function otpMaxAttempts(): int
    {
        return $this->number('otp_max_attempts');
    }

    public function otpLockoutSeconds(): int
    {
        return $this->number('otp_lockout_seconds');
    }

    public function otpPhoneFailCeiling(): int
    {
        return $this->number('otp_phone_fail_ceiling');
    }

    public function otpResendWaits(): array
    {
        return $this->parseWaits($this->raw('otp_resend_waits')) ?? [60, 120, 300];
    }

    public function otpUnreachable(): string
    {
        return $this->enum('otp_unreachable');
    }

    public function capIpHour(): int
    {
        return $this->number('cap_ip_hour');
    }

    public function capIpDay(): int
    {
        return $this->number('cap_ip_day');
    }

    // 2026-10-24 23:49 +03: Task M1.8a (P3-34), task 596. Code SMS to one phone per hour (all IPs, all purposes).
    public function capPhoneHour(): int
    {
        return $this->number('cap_phone_hour');
    }

    public function capIpVerifyHour(): int
    {
        return $this->number('cap_ip_verify_hour');
    }

    public function capStoreHour(): int
    {
        return $this->number('cap_store_hour');
    }

    public function captchaAfterIp(): int
    {
        return $this->number('captcha_after_ip');
    }

    public function captchaAfterSubject(): int
    {
        return $this->number('captcha_after_subject');
    }

    public function captchaAfterStore(): int
    {
        return $this->number('captcha_after_store');
    }

    public function trustedProxies(): array
    {
        // 2026-10-23 17:13 +03: the split and the valid-entry filter are proxyEntries(), shared with normalize()
        // (fix round 1, finding I1, Task M1.1a, task 596).
        return array_values(array_filter($this->proxyEntries($this->raw('trusted_proxies')), [$this, 'isIpOrCidr']));
    }

    /**
     * 2026-10-23 17:13 +03: fix round 1, I1, task 596. The unique entries of a proxy list split on commas, semicolons
     * and white space, valid or not.
     *
     * @return string[]
     */
    private function proxyEntries(string $text): array
    {
        $entries = (array) preg_split('/[,;\s]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_map('strval', $entries)));
    }

    public function turnstileEnabled(): bool
    {
        return $this->flag('turnstile_enabled');
    }

    public function turnstileSiteKey(): string
    {
        $key = $this->raw('turnstile_site_key');

        return preg_match(self::SITE_KEY_PATTERN, $key) === 1 ? $key : '';
    }

    public function turnstileSecret(): string
    {
        return $this->prefixedSecret('turnstile_secret');
    }

    // ------------------------------------------------------------------ save

    public function save(
        string $mode,
        #[\SensitiveParameter]
        array $values
    ): array {
        $allowed = self::MODE_KEYS[$mode] ?? [];
        $requested = array_map('strval', array_keys($values));
        $failed = [];
        foreach ($requested as $key) {
            if (!in_array($key, $allowed, true)) {
                $failed[$key] = true;
            }
        }

        if ($mode === 'login') {
            $this->saveLogin($values, $failed);
        } else {
            foreach ($allowed as $key) {
                if (!array_key_exists($key, $values)) {
                    continue;
                }
                // 2026-10-23 15:12 +03: Task M1.1a, task 596. The Turnstile secret key is stored like the API password
                // (prefix kwtsms1:, encrypted). The settings form never shows it, so an empty post means "keep the
                // stored one" (not a write and not a rejection); only a new non-empty value replaces it.
                if ($mode === 'update_codes' && $key === 'turnstile_secret') {
                    if (self::isBlankSecretPost($values[$key])) {
                        continue;
                    }
                    $stored = $this->encryptPassword($values[$key]);
                } else {
                    $stored = $mode === 'logout'
                        ? $this->normalizeClear($key, $values[$key])
                        : $this->normalize($key, $values[$key]);
                }
                // 2026-10-20 22:26 +03: enabled = Y is refused when the same request empties the sender or asks
                // for one that was refused or could not be saved, task 596 (Task 1.4 fix round 1).
                if (
                    $key === 'enabled'
                    && $stored === 'Y'
                    && array_key_exists('sender_id', $values)
                    && (isset($failed['sender_id']) || $this->senderId() === '')
                ) {
                    $stored = null;
                }
                if ($stored === null) {
                    $failed[$key] = true;
                    continue;
                }
                // 2026-10-22 14:53 +03: the global Sender ID before this write, for the ban below. Task A2, task 596.
                $previous_sender = $key === 'sender_id' ? $this->senderId() : '';
                // 2026-10-20 22:16 +03: a write the backend refuses is a rejected key, not a saved one, task 596.
                if (!$this->tryWrite($key, $stored)) {
                    $failed[$key] = true;
                    continue;
                }
                // 2026-10-21 01:58 +03: the ERR008 ban lasts only until another Sender ID (or none) is saved
                // (PRD 6.1), so saving a different value forgets it, Task 1.7 fix round 1 item 3, task 596.
                // 2026-10-22 14:53 +03: clears only the ban of the Sender ID that was replaced; a storefront
                // Sender ID's ban stays (per-sender map), task A2, task 596.
                if ($key === 'sender_id' && $stored !== $previous_sender) {
                    $this->state->clearSenderBan($previous_sender);
                }
            }
        }

        return array_values(array_filter($requested, static function (string $key) use ($failed): bool {
            return isset($failed[$key]);
        }));
    }

    /**
     * 2026-10-20 22:18 +03: writes the new key straight to the backend (task 596). The backend throws a
     * RuntimeException that names the key only when it cannot save it; the old key stays.
     */
    public function regenerateCronKey(): string
    {
        $key = bin2hex(random_bytes(16));
        $this->backend->write('cron_key', $key);

        return $key;
    }

    /**
     * Username and password are saved together or not at all: a half-saved pair would send the old
     * password with the new username.
     *
     * @param array<string, mixed> $values
     * @param array<string, true>  $failed
     */
    private function saveLogin(
        #[\SensitiveParameter]
        array $values,
        array &$failed
    ): void {
        $has_user = array_key_exists('api_username', $values);
        $has_password = array_key_exists('api_password', $values);
        $username = $has_user ? $this->normalizeUsername($values['api_username']) : null;
        $encrypted = $has_password ? $this->encryptPassword($values['api_password']) : null;

        if ($username === null || $encrypted === null) {
            foreach (['api_username' => $has_user, 'api_password' => $has_password] as $key => $present) {
                if ($present) {
                    $failed[$key] = true;
                }
            }

            return;
        }

        // 2026-10-20 22:16 +03: a refused write leaves the old pair, task 596. The username goes first; when the
        // password then fails, the old username is put back so the pair never mixes old and new.
        $old_username = $this->backend->read('api_username');
        if (!$this->tryWrite('api_username', $username)) {
            $failed['api_username'] = $failed['api_password'] = true;

            return;
        }
        if (!$this->tryWrite('api_password', $encrypted)) {
            $failed['api_username'] = $failed['api_password'] = true;
            if (!$this->tryWrite('api_username', $old_username ?? '')) {
                throw new \RuntimeException('Setting "api_username" could not be restored after a failed login save');
            }
        }
    }

    /**
     * Writes one validated value. False when the backend refuses it (it throws, naming the key only).
     */
    private function tryWrite(string $key, string $value): bool
    {
        unset($this->write_errors[$key]);
        try {
            $this->backend->write($key, $value);
        } catch (\Exception $e) {
            // 2026-10-21 03:19 +03: the backend's reason is logged (one error_log line: key, class, message without
            // any query or digit run, file:line; never the value) and kept for lastWriteError(), Task 1.10 fix round 1
            // item 14 (carry from Task 1.4), task 596.
            $reason = get_class($e) . ': ' . $this->safeMessage($e->getMessage())
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
            $this->write_errors[$key] = $reason;
            error_log('kwtsms: setting "' . $key . '" was not saved: ' . $reason);

            return false;
        }

        return true;
    }

    /**
     * Why the settings store refused the last write of $key in this request, or null (it was not refused). Never
     * holds the value. 2026-10-21 03:19 +03: added, Task 1.10 fix round 1 item 14, task 596.
     */
    public function lastWriteError(string $key): ?string
    {
        return $this->write_errors[$key] ?? null;
    }

    // ------------------------------------------------------------------ normalizers (save)

    /**
     * Stored form of a value for a key outside the login and logout modes, or null to reject it.
     *
     * @param mixed $value
     */
    private function normalize(string $key, $value): ?string
    {
        if ($key === 'sender_id') {
            return $this->normalizeSender($value);
        }
        if ($key === 'enabled') {
            $flag = $this->normalizeFlag($value);
            if ($flag === 'Y' && !$this->state->isConfigured($this)[0]) {
                return null;
            }

            return $flag;
        }
        if (!isset(self::SPECS[$key])) {
            return null;
        }
        $spec = self::SPECS[$key];

        switch ($spec[0]) {
            case 'flag':
                return $this->normalizeFlag($value);
            case 'int':
                return $this->normalizeInt($value, $spec[1], $spec[2]);
            case 'enum':
                $text = is_string($value) ? trim($value) : null;

                return ($text !== null && in_array($text, $spec[1], true)) ? $text : null;
            case 'time':
                $text = is_string($value) ? trim($value) : '';

                return preg_match(self::TIME_PATTERN, $text) === 1 ? $text : null;
            case 'country':
                return $this->normalizeCountry($value);
            case 'codes':
                $codes = $this->tokens($value, '/^[0-9]{1,4}$/D', true);

                // 2026-10-20 22:26 +03: every code is a calling code of the phone rules table, task 596.
                return ($codes === null || $codes === [] || array_filter($codes, [$this, 'isCountryCode']) !== $codes)
                    ? null
                    : implode(',', $codes);
            case 'statuses':
                $codes = $this->tokens($value, '/^[A-Z]$/D', false, true);

                return $codes === null ? null : implode(',', $codes);
            case 'threshold':
                return $this->normalizeThreshold($value);
            case 'phones':
                return $this->normalizePhones($value);
            case 'lang':
                $text = is_string($value) ? strtolower(trim($value)) : null;

                return ($text !== null && preg_match('/^(?:[a-z]{2})?$/D', $text) === 1) ? $text : null;
            // 2026-10-23 17:13 +03: fix round 1, finding I1 (Task M1.1a, task 596). The write side of the three list
            // specs, on the same checks the getters use: parseWaits(), isIpOrCidr() and SITE_KEY_PATTERN.
            case 'waits':
                $waits = is_string($value) ? $this->parseWaits(trim($value)) : null;

                return $waits === null ? null : implode(',', $waits);
            case 'proxies':
                if (!is_string($value)) {
                    return null;
                }
                $entries = $this->proxyEntries($value);

                // One invalid entry refuses the whole list (like the calling-code list); an empty list is allowed.
                return array_filter($entries, [$this, 'isIpOrCidr']) === $entries ? implode("\n", $entries) : null;
            case 'sitekey':
                $text = is_string($value) ? trim($value) : null;

                $valid = $text !== null && ($text === '' || preg_match(self::SITE_KEY_PATTERN, $text) === 1);

                return $valid ? $text : null;
        }

        return null;
    }

    /**
     * Logout may only clear: an empty credential or sender, and enabled = N.
     *
     * @param mixed $value
     */
    private function normalizeClear(string $key, $value): ?string
    {
        if ($key === 'enabled') {
            return $this->normalizeFlag($value) === 'N' ? 'N' : null;
        }

        return (is_string($value) && trim($value) === '') ? '' : null;
    }

    /**
     * @param mixed $value
     */
    private function normalizeFlag($value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'Y' : 'N';
        }
        $text = is_string($value) ? strtoupper(trim($value)) : '';

        return ($text === 'Y' || $text === 'N') ? $text : null;
    }

    /**
     * @param mixed $value
     */
    private function normalizeInt($value, int $min, int $max): ?string
    {
        if (is_int($value)) {
            $text = (string) $value;
        } elseif (is_string($value)) {
            $text = trim($value);
        } else {
            return null;
        }
        if (!$this->isDigits($text, 9)) {
            return null;
        }
        $number = (int) $text;

        return ($number >= $min && $number <= $max) ? (string) $number : null;
    }

    /**
     * @param mixed $value
     */
    private function normalizeCountry($value): ?string
    {
        $text = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : '');

        return $this->isCountryCode($text) ? $text : null;
    }

    /**
     * Admin numbers: separated by commas, semicolons or line breaks; each one is checked by
     * PhoneNormalizer::validateInput() with the default country as hint (a local number gets the country code,
     * a landline or a malformed number is refused) and stored normalized, without repeats. One bad number
     * refuses the whole list. 2026-10-20 22:26 +03, task 596 (Task 1.4 fix round 1).
     *
     * @param mixed $value
     */
    private function normalizePhones($value): ?string
    {
        if (is_array($value)) {
            $raw = [];
            foreach ($value as $item) {
                if (!is_string($item) && !is_int($item)) {
                    return null;
                }
                $raw[] = (string) $item;
            }
        } elseif (is_string($value)) {
            $raw = (array) preg_split('/[,;\r\n]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        } else {
            return null;
        }

        $hint = $this->defaultCountryCode();
        $phones = [];
        foreach ($raw as $number) {
            $number = trim($number);
            if ($number === '') {
                continue;
            }
            // Digits (Latin, Arabic-Indic, Persian) and the usual formatting only.
            if (preg_match('/^[+0-9\s().\-\x{0660}-\x{0669}\x{06F0}-\x{06F9}]+$/u', $number) !== 1) {
                return null;
            }
            [$valid, , $normalized] = PhoneNormalizer::validateInput($number, $hint);
            if (!$valid) {
                return null;
            }
            if (!in_array($normalized, $phones, true)) {
                $phones[] = $normalized;
            }
        }

        return count($phones) > self::MAX_ADMIN_PHONES ? null : implode(',', $phones);
    }

    /**
     * @param mixed $value
     */
    private function normalizeThreshold($value): ?string
    {
        $text = is_int($value) ? (string) $value : (is_string($value) ? strtolower(trim($value)) : '');
        if ($text === 'auto') {
            return 'auto';
        }

        return ($this->isDigits($text, 8) && (int) $text <= self::MAX_THRESHOLD) ? (string) (int) $text : null;
    }

    /**
     * sender_id: empty (nothing selected) or exactly one of the cached Sender IDs, case kept.
     *
     * @param mixed $value
     */
    private function normalizeSender($value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }

        return ($value === '' || in_array($value, $this->state->senderIds(), true)) ? $value : null;
    }

    /**
     * @param mixed $value
     */
    private function normalizeUsername($value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return preg_match(self::USERNAME_PATTERN, $text) === 1 ? $text : null;
    }

    /**
     * Validates a pasted password and returns its stored form, or null.
     *
     * @param mixed $value
     */
    private function encryptPassword(
        #[\SensitiveParameter]
        $value
    ): ?string {
        $password = is_string($value) ? trim($value) : '';
        if (
            $password === ''
            || !mb_check_encoding($password, 'UTF-8')
            || mb_strlen($password, 'UTF-8') > self::MAX_PASSWORD_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/', $password) === 1
        ) {
            return null;
        }
        try {
            $stored = $this->encrypt(self::PASSWORD_PREFIX . $password);
        } catch (\Throwable $e) {
            return null;
        }

        return $stored === '' ? null : $stored;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * 2026-10-23 15:12 +03: the plain value of a secret stored as fn_encrypt_text('kwtsms1:' . $secret), or '' when
     * none is saved, it cannot be decrypted or it lacks the prefix (a changed crypt key or a tampered value reads as
     * "not set", never as a wrong secret sent out). Shared by password() and turnstileSecret(), Task M1.1a, task 596.
     */
    private function prefixedSecret(string $key): string
    {
        $stored = $this->raw($key);
        if ($stored === '') {
            return '';
        }

        $secret = '';
        try {
            $plain = $this->decrypt($stored);
            if (is_string($plain) && strncmp($plain, self::PASSWORD_PREFIX, strlen(self::PASSWORD_PREFIX)) === 0) {
                $secret = (string) substr($plain, strlen(self::PASSWORD_PREFIX));
            }
        } catch (\Throwable $e) {
            $secret = '';
        }

        return $secret;
    }

    /**
     * 2026-10-23 15:12 +03: "30,60,120" to [30, 60, 120] when it holds 1 to the allowed count whole numbers inside the
     * range of the otp_resend_waits spec that never decrease; null for anything else (empty item, text, sign, too
     * many, out of range, decreasing). Task M1.1a, task 596.
     *
     * @return int[]|null
     */
    private function parseWaits(string $stored): ?array
    {
        [, $min, $max, $count] = self::SPECS['otp_resend_waits'];
        $items = explode(',', $stored);
        if (count($items) > $count) {
            return null;
        }
        $waits = [];
        foreach ($items as $item) {
            $item = trim($item);
            if (!$this->isDigits($item, 5) || (int) $item < $min || (int) $item > $max) {
                return null;
            }
            if ($waits !== [] && (int) $item < $waits[count($waits) - 1]) {
                return null;
            }
            $waits[] = (int) $item;
        }

        return $waits;
    }

    /** An IPv4 or IPv6 address, or one with a /prefix inside the family's range. */
    private function isIpOrCidr(string $entry): bool
    {
        $parts = explode('/', $entry);
        if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (count($parts) === 1) {
            return true;
        }
        $max = strpos($parts[0], ':') !== false ? 128 : 32;

        return $this->isDigits($parts[1], 3) && (int) $parts[1] <= $max;
    }

    private function encrypt(
        #[\SensitiveParameter]
        string $text
    ): string {
        return $this->encrypt === null
            ? (string) fn_encrypt_text($text)
            : (string) call_user_func($this->encrypt, $text);
    }

    /**
     * @return mixed
     */
    private function decrypt(string $text)
    {
        return $this->decrypt === null ? fn_decrypt_text($text) : call_user_func($this->decrypt, $text);
    }

    /** The stored value, or the default when the setting does not exist. */
    private function raw(string $key): string
    {
        $value = $this->backend->read($key);

        return $value === null ? self::DEFAULTS[$key] : $value;
    }

    private function flag(string $key): bool
    {
        $value = $this->raw($key);

        return ($value === 'Y' || $value === 'N' ? $value : self::DEFAULTS[$key]) === 'Y';
    }

    private function number(string $key): int
    {
        [, $min, $max] = self::SPECS[$key];
        $value = $this->raw($key);
        if ($this->isDigits($value, 9) && (int) $value >= $min && (int) $value <= $max) {
            return (int) $value;
        }

        return (int) self::DEFAULTS[$key];
    }

    private function enum(string $key): string
    {
        $value = $this->raw($key);

        return in_array($value, self::SPECS[$key][1], true) ? $value : self::DEFAULTS[$key];
    }

    private function time(string $key): string
    {
        $value = $this->raw($key);

        return preg_match(self::TIME_PATTERN, $value) === 1 ? $value : self::DEFAULTS[$key];
    }

    private function isDigits(string $text, int $max_length): bool
    {
        return $text !== '' && strlen($text) <= $max_length && ctype_digit($text);
    }

    private function isCountryCode(string $code): bool
    {
        if (self::$country_codes === null) {
            self::$country_codes = array_fill_keys(array_keys(PhoneNormalizer::countryOptions()), true);
        }

        return preg_match('/^[1-9][0-9]{0,3}$/D', $code) === 1 && isset(self::$country_codes[(int) $code]);
    }

    /**
     * Comma separated stored list to its valid, unique entries.
     *
     * @return string[]
     */
    private function list(string $stored, string $pattern): array
    {
        $items = [];
        foreach (explode(',', $stored) as $item) {
            $item = trim($item);
            if (preg_match($pattern, $item) === 1 && !in_array($item, $items, true)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Splits a posted list (string or array) into unique tokens that all match $pattern; null when the
     * value has the wrong type or any token is invalid. With $strict_commas the list is split on commas
     * only and an empty token (empty value, leading or trailing comma) is invalid; otherwise it is split on
     * commas, semicolons and white space and an empty value is an empty list.
     *
     * @param mixed $value
     *
     * @return string[]|null
     */
    private function tokens($value, string $pattern, bool $strict_commas, bool $upper = false): ?array
    {
        if (is_array($value)) {
            $raw = [];
            foreach ($value as $item) {
                if (!is_string($item) && !is_int($item)) {
                    return null;
                }
                $raw[] = (string) $item;
            }
        } elseif (is_string($value)) {
            $raw = $strict_commas ? explode(',', $value) : preg_split('/[,;\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        } else {
            return null;
        }

        $tokens = [];
        foreach ((array) $raw as $token) {
            $token = trim($token);
            $token = $upper ? strtoupper($token) : $token;
            if (preg_match($pattern, $token) !== 1) {
                return null;
            }
            if (!in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }
}
