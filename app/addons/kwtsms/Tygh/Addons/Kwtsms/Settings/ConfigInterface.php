<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Settings/ConfigInterface.php
 * CREATED:   2026-10-20 21:19 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Contract of the add-on's typed settings: one getter per PRD 9 setting, the decrypted API
 *            password, the credentials state, and the allow-listed save().
 * WHY:       Every service (send pipeline, gateway state, logs, controllers) reads settings through this
 *            interface, so unit tests can supply an in-memory configuration and no code outside Config
 *            touches CS-Cart's Settings class or knows the storage format (Y/N, comma lists, "auto").
 * USAGE:     $config->enabled();  $config->capRecipientHour();  $config->customerSmsCountries();
 *            $rejected = $config->save('save_settings', $request_values);   // list of rejected keys
 *            Modes: save_settings, login, logout, update_admin_alerts,
 *            update_integrations (see Config::MODE_KEYS).
 * DEPENDS:   Nothing.
 */

namespace Tygh\Addons\Kwtsms\Settings;

interface ConfigInterface
{
    /** Nothing saved: no username, or no password. */
    public const CREDENTIALS_NONE = 'none';

    /** Username saved and the password decrypts with the "kwtsms1:" prefix. */
    public const CREDENTIALS_OK = 'ok';

    /** A password is saved but cannot be decrypted (crypt key changed) or lacks the prefix: log in again. */
    public const CREDENTIALS_RELOGIN = 'relogin_required';

    // General

    public function enabled(): bool;

    public function testMode(): bool;

    public function pilotMode(): bool;

    /** Calling code without "+", always one of PhoneNormalizer::countryOptions(); falls back to 965. */
    public function defaultCountryCode(): string;

    /**
     * Calling codes customers may receive SMS in. An empty saved list means the default country only.
     *
     * @return string[]
     */
    public function customerSmsCountries(): array;

    /**
     * Extra order status codes that count as "placed" besides the core paid/inventory set.
     *
     * @return string[]
     */
    public function placedStatusExtra(): array;

    public function quietHoursEnabled(): bool;

    /** "HH:MM" */
    public function quietHoursFrom(): string;

    /** "HH:MM" */
    public function quietHoursTo(): string;

    /** 1..7 */
    public function maxPagesNotification(): int;

    // Gateway

    public function apiUsername(): string;

    /**
     * The decrypted API password, or '' when none is saved or it cannot be decrypted or lacks the
     * "kwtsms1:" prefix (see credentialsState()).
     */
    public function password(): string;

    /** One of the CREDENTIALS_* constants. */
    public function credentialsState(): string;

    /** The selected Sender ID, case kept; '' when none is selected (nothing is ever preselected). */
    public function senderId(): string;

    /** 'transactional' or 'promotional' */
    public function senderType(): string;

    /** Reserved for Phase 4. */
    public function senderIdPromotional(): string;

    // Caps

    public function capRecipientHour(): int;

    public function capRecipientDay(): int;

    public function capAdminDay(): int;

    public function capStoreDay(): int;

    /** Null means "auto" (computed from the 30-day average where it is used). */
    public function lowBalanceThreshold(): ?int;

    // Admin alerts

    /**
     * Normalized admin numbers (digits only, country code included), at most 10.
     *
     * @return string[]
     */
    public function adminPhones(): array;

    /** Two-letter language code; '' means the backend default language. */
    public function adminAlertLang(): string;

    // Multi-Vendor

    /** 'per_checkout' or 'per_suborder' */
    public function mveCustomerNewOrder(): string;

    /** 'per_checkout', 'per_suborder' or 'off' */
    public function mveAdminNewOrder(): string;

    /** 'auto', 'per_suborder' or 'per_checkout' */
    public function mveCustomerStatusScope(): string;

    public function mveVendorTriggeredSms(): bool;

    /**
     * Recipients one vendor may SMS per calendar month; 0 = vendor SMS off, 100 when unset or invalid.
     * 2026-10-22 13:15 +03: added (P2-6), Task A1, task 596.
     */
    public function vendorMonthlyQuota(): int;

    // Logs and cron

    /** 'info' or 'debug' */
    public function logLevel(): string;

    /** 0 = keep until cleared */
    public function retentionMessagesDays(): int;

    public function retentionAttemptsDays(): int;

    public function retentionDebugDays(): int;

    public function cronUrlEnabled(): bool;

    public function cronKey(): string;

    // Phone and codes. 2026-10-23 15:12 +03: Task M1.1a (1.2.0), task 596. Every getter returns its default for an
    // absent setting, an out of range value or a malformed one.

    /** C3: country check on the phones of registration, checkout and profile. Default N. */
    public function phoneCountryCheck(): bool;

    /** C4: one account per phone. Default N. */
    public function phoneUnique(): bool;

    /** B1: registration needs a verified SMS code. Default N. */
    public function registerCode(): bool;

    /** B1: the registration phone is required. Default N. */
    public function registerPhoneRequired(): bool;

    /** B5: a changed account phone needs a code sent to the new number. Default N. */
    public function phoneChangeCode(): bool;

    /** B5: a notice SMS goes to the old number after a change. Default N. */
    public function phoneChangeNotice(): bool;

    /** 60..900, default 300 */
    public function otpExpirySeconds(): int;

    /** 3..10, default 5 */
    public function otpMaxAttempts(): int;

    /** 60..86400, default 900 */
    public function otpLockoutSeconds(): int;

    /** 5..1000, default 20 */
    public function otpPhoneFailCeiling(): int;

    /**
     * Seconds to wait before the 2nd, 3rd, ... code of one sequence: 1 to 5 values of 30..3600 that never decrease;
     * [60, 120, 300] for anything invalid.
     *
     * @return int[]
     */
    public function otpResendWaits(): array;

    /** 'pause' or 'continue' (P3-13), default 'pause' */
    public function otpUnreachable(): string;

    /** 1..10000, default 5 (2026-10-24 23:49 +03: was 20, Task M1.8a, P3-35, task 596) */
    public function capIpHour(): int;

    /** 1..10000, default 20 (2026-10-24 23:49 +03: was 100, Task M1.8a, P3-35, task 596) */
    public function capIpDay(): int;

    /** 2026-10-24 23:49 +03: Task M1.8a (P3-34), task 596. Code SMS per phone and hour, 1..100, default 5 */
    public function capPhoneHour(): int;

    /** 5..10000, default 30 */
    public function capIpVerifyHour(): int;

    /** 0..100000, 0 = off (default) */
    public function capStoreHour(): int;

    /** 1..1000000, default 300: code SMS (source otp) per store and day, counted before cap_store_day */
    public function capCodeDay(): int;

    /** Requests per IP and hour before a CAPTCHA token is needed, 1..1000, default 3 */
    public function captchaAfterIp(): int;

    /** Codes per phone and hour before a token is needed, 1..1000, default 2 */
    public function captchaAfterSubject(): int;

    /** Requests per store and 10 minutes before a token is needed, 1..100000, default 30 */
    public function captchaAfterStore(): int;

    /**
     * IPs and CIDR blocks (IPv4 and IPv6) of the proxies whose X-Forwarded-For is believed; invalid entries dropped.
     *
     * @return string[]
     */
    public function trustedProxies(): array;

    public function turnstileEnabled(): bool;

    /** The public site key; '' when none or malformed. */
    public function turnstileSiteKey(): string;

    /**
     * The decrypted Turnstile secret key, '' when none is saved or it cannot be decrypted or lacks the "kwtsms1:"
     * prefix. Never logged, rendered back or put in JS.
     */
    public function turnstileSecret(): string;

    /**
     * Writes the keys that $mode may write, each validated and cast, and returns the rejected keys
     * (not allowed in this mode, unknown, invalid value, or failing a precondition such as
     * "enabled = Y only when configured"). Rejected keys are not written; accepted keys are.
     *
     * @param string               $mode   One of save_settings, login, logout,
     *                                     update_admin_alerts, update_integrations, update_vendor_quotas,
     *                                     update_codes
     * @param array<string, mixed> $values Request values keyed by setting id
     *
     * @return string[] Rejected keys, in request order
     */
    public function save(
        string $mode,
        #[\SensitiveParameter]
        array $values
    ): array;

    /**
     * Replaces the cron key with 32 random hex characters. The only code that writes cron_key: no save()
     * mode accepts a key a caller chose (2026-10-20 22:18 +03, Task 1.4 fix round 1, task 596).
     *
     * @return string The new key
     *
     * @throws \RuntimeException When the key cannot be saved; the old key stays
     */
    public function regenerateCronKey(): string;
}
