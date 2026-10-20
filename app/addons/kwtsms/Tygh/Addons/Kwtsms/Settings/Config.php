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
use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;

class Config implements ConfigInterface
{
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
    ];

    /**
     * Which keys each save mode may write, in the order they are validated and written. enabled comes
     * last so that a sender_id saved in the same request counts when "configured" is checked.
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
        'regenerate_cron_key' => ['cron_key'],
        'update_admin_alerts' => ['admin_phones', 'admin_alert_lang'],
        'update_integrations' => [
            'mve_customer_new_order', 'mve_admin_new_order', 'mve_customer_status_scope', 'mve_vendor_triggered_sms',
        ],
    ];

    /**
     * Validation of the keys that need no context. Types: flag (Y/N), int (min, max), enum (values),
     * time (HH:MM), country, codes (calling-code list), statuses (order status letters), threshold
     * (auto or a number), phones (up to 10 numbers), lang (two letters or empty), hex32.
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
        'cron_url_enabled' => ['flag'],
        'cron_key' => ['hex32'],
    ];

    private const TIME_PATTERN = '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D';
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
        $codes = $this->list($this->raw('customer_sms_countries'), '/^[0-9]{1,4}$/D');

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
        $stored = $this->raw('api_password');
        if ($stored === '') {
            return '';
        }

        $password = '';
        try {
            $plain = $this->decrypt($stored);
            if (is_string($plain) && strncmp($plain, self::PASSWORD_PREFIX, strlen(self::PASSWORD_PREFIX)) === 0) {
                $password = (string) substr($plain, strlen(self::PASSWORD_PREFIX));
            }
        } catch (\Throwable $e) {
            $password = '';
        }

        return $password;
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
                $stored = $mode === 'logout'
                    ? $this->normalizeClear($key, $values[$key])
                    : $this->normalize($key, $values[$key]);
                if ($stored === null) {
                    $failed[$key] = true;
                    continue;
                }
                $this->backend->write($key, $stored);
            }
        }

        return array_values(array_filter($requested, static function (string $key) use ($failed): bool {
            return isset($failed[$key]);
        }));
    }

    public function regenerateCronKey(): string
    {
        $key = bin2hex(random_bytes(16));
        if ($this->save('regenerate_cron_key', ['cron_key' => $key]) !== []) {
            throw new \RuntimeException('The cron key could not be saved');
        }

        return $key;
    }

    /**
     * Username and password are saved together or not at all: a half-saved pair would send the old
     * password with the new username.
     *
     * @param array<string, mixed> $values
     * @param array<string, true>  $failed
     */
    private function saveLogin(array $values, array &$failed): void
    {
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
        $this->backend->write('api_username', $username);
        $this->backend->write('api_password', $encrypted);
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

                return ($codes === null || $codes === []) ? null : implode(',', $codes);
            case 'statuses':
                $codes = $this->tokens($value, '/^[A-Z]$/D', false, true);

                return $codes === null ? null : implode(',', $codes);
            case 'threshold':
                return $this->normalizeThreshold($value);
            case 'phones':
                $phones = $this->tokens($value, '/^[0-9]{7,15}$/D', false);

                return ($phones === null || count($phones) > self::MAX_ADMIN_PHONES) ? null : implode(',', $phones);
            case 'lang':
                $text = is_string($value) ? strtolower(trim($value)) : null;

                return ($text !== null && preg_match('/^(?:[a-z]{2})?$/D', $text) === 1) ? $text : null;
            case 'hex32':
                $text = is_string($value) ? trim($value) : '';

                return preg_match('/^[0-9a-f]{32}$/D', $text) === 1 ? $text : null;
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
