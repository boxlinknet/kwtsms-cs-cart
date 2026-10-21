<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/SettingsPage.php
 * CREATED:   2026-10-21 01:57 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The Settings page of the kwtsms admin controller: the data the template shows (gateway login or the
 *            connected view, Sender ID, general settings, limits, quiet hours, logs, cron, test SMS) and every POST
 *            action of the page (login, logout, save_settings, test_sms, sync, refresh_balance, retry_gateway,
 *            resume_daily_cap, regenerate_cron_key).
 * WHY:       PRD 6.1, 6.3, 6.14, 9 and 11. The controller file stays a thin gate and router; the rules live here:
 *            credentials only through login (never rendered back), the KWT-SMS acknowledgement before test mode goes
 *            off, the default country from PhoneNormalizer::countryOptions() (int keys: isset, compared as text), the
 *            customer countries always holding the default country, enabled only when configured (with the reason),
 *            10 test SMS an hour per administrator (own count on the attempt log, any outcome), settings.save logged
 *            at info with key names only, and every value placed into a notification escaped with fn_html_escape().
 * USAGE:     $page = SettingsPage::fromContainer(Tygh::$app);
 *            $view_data = $page->viewData();                       // GET kwtsms.settings
 *            $page->login($user, $pass);  $page->save($_REQUEST['kwtsms_settings'], $ack);  ...   // POST modes
 *            SettingsPage::prepare($posted, $current, $ack)        // pure: posted form -> values + reasons
 * DEPENDS:   Settings\ConfigInterface, Gateway\{GatewayState,SyncService}, Sms\{SmsService,PhoneNormalizer},
 *            Log\{AttemptLog,DebugLog}, Templates\{BrandNames,TemplateRenderer}; CS-Cart fn_set_notification(), __(),
 *            fn_html_escape() (through protected methods a test can replace).
 */

namespace Tygh\Addons\Kwtsms\Admin;

use Tygh\Addons\Kwtsms\Gateway\GatewayState;
use Tygh\Addons\Kwtsms\Gateway\SyncService;
use Tygh\Addons\Kwtsms\Log\AttemptLog;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\ApiErrors;
use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;
use Tygh\Addons\Kwtsms\Sms\SendResult;
use Tygh\Addons\Kwtsms\Sms\SmsService;
use Tygh\Addons\Kwtsms\Templates\BrandNames;
use Tygh\Addons\Kwtsms\Templates\TemplateRenderer;

class SettingsPage
{
    /** The kwtSMS test sender (PRD 6.1): never preselected, never seeded; selecting it shows a warning. */
    public const TEST_SENDER = 'KWT-SMS';

    /** PRD 6.14: test SMS per administrator per hour, and the longest optional suffix. */
    public const TEST_SMS_PER_HOUR = 10;
    public const TEST_SMS_SUFFIX_MAX = 40;
    public const TEST_SMS_EVENT = 'kwtsms.test_sms';
    public const TEST_SMS_TEXT = '{store_name}: kwtSMS test message';

    /**
     * Keys the Settings form may save (Config mode save_settings without the Multi-Vendor keys, which belong to the
     * Integrations page, Task 1.13).
     *
     * @var string[]
     */
    public const FORM_KEYS = [
        'enabled', 'test_mode', 'pilot_mode', 'default_country_code', 'customer_sms_countries', 'placed_status_extra',
        'max_pages_notification', 'sender_id', 'sender_type',
        'cap_recipient_hour', 'cap_recipient_day', 'cap_admin_day', 'cap_store_day', 'low_balance_threshold',
        'quiet_hours_enabled', 'quiet_hours_from', 'quiet_hours_to',
        'log_level', 'retention_messages_days', 'retention_attempts_days', 'retention_debug_days', 'cron_url_enabled',
    ];

    /** Keys posted as a list (multi-select, checkboxes); every other key must be one string. */
    private const LIST_KEYS = ['customer_sms_countries', 'placed_status_extra'];

    /** @var ConfigInterface */
    private $config;

    /** @var GatewayState */
    private $state;

    /** @var SyncService */
    private $sync;

    /** @var SmsService */
    private $sms;

    /** @var AttemptLog */
    private $attempts;

    /** @var DebugLog */
    private $log;

    /** @var array<int,string>|null Country dropdown in the admin language (2026-10-21 03:16 +03, fix round 1 item 8) */
    private $country_options = null;

    public function __construct(
        ConfigInterface $config,
        GatewayState $state,
        SyncService $sync,
        SmsService $sms,
        AttemptLog $attempts,
        DebugLog $log
    ) {
        $this->config = $config;
        $this->state = $state;
        $this->sync = $sync;
        $this->sms = $sms;
        $this->attempts = $attempts;
        $this->log = $log;
    }

    /**
     * @param \ArrayAccess<string,mixed> $app Tygh::$app
     */
    public static function fromContainer($app): self
    {
        return new self(
            $app['kwtsms.config'],
            $app['kwtsms.state'],
            $app['kwtsms.sync'],
            $app['kwtsms.sms'],
            $app['kwtsms.logs.attempts'],
            $app['kwtsms.logs.debug']
        );
    }

    // ================================================================== GET kwtsms.settings

    /**
     * Everything the template shows. Never holds the password; the username only when the account is connected
     * (the connected view shows it read-only, PRD 6.1).
     *
     * @return array<string,mixed>
     */
    public function viewData(bool $change_account = false): array
    {
        $credentials = $this->config->credentialsState();
        $connected = $credentials === ConfigInterface::CREDENTIALS_OK;
        list($configured, $reason) = $this->state->isConfigured($this->config);
        $suspended = $this->state->suspendedCode();
        $balance = $this->state->balance();
        $senders = $this->state->senderIds();
        $settings = [];
        foreach (self::FORM_KEYS as $key) {
            $settings[$key] = $this->current($key);
        }

        return [
            'connected' => $connected,
            'relogin' => $credentials === ConfigInterface::CREDENTIALS_RELOGIN,
            'show_login' => !$connected || $change_account,
            'change_account' => $connected && $change_account,
            'username' => $connected ? $this->config->apiUsername() : '',
            'configured' => $configured,
            'configured_reason' => $configured ? '' : $this->reasonText((string) $reason),
            'suspended' => $suspended !== null,
            'suspended_text' => $suspended === null ? '' : ApiErrors::describe($suspended),
            'balance' => $balance === null ? null : [
                'available' => self::number($balance['available']),
                'purchased' => $balance['purchased'] === null ? '' : self::number($balance['purchased']),
                'synced_at' => (int) $balance['synced_at'],
            ],
            'last_sync' => (int) $this->state->lastSync(),
            'senders' => $senders,
            'sender_in_list' => in_array($this->config->senderId(), $senders, true),
            'test_sender' => self::TEST_SENDER,
            'settings' => $settings,
            // 2026-10-21 03:16 +03: names in the admin language (fix round 1 item 8), task 596.
            'countries' => $this->countryOptions(),
            'max_pages' => range(1, 7),
            'daily_cap_tripped' => $this->dailyCapTripped(),
            'cron_last_run' => (int) $this->state->lastCron(),
            'cron_key' => $this->config->cronKey(),
            'test_sms_text' => $this->testSmsText(''),
            'test_sms_per_hour' => self::TEST_SMS_PER_HOUR,
            'test_sms_suffix_max' => self::TEST_SMS_SUFFIX_MAX,
            'default_country_label' => $this->countryLabel($this->config->defaultCountryCode()),
        ];
    }

    // ================================================================== POST actions

    /**
     * PRD 6.1 login. The values arrive raw (fn_trusted_vars in the controller), so a password with "<" or a quote
     * reaches kwtSMS unchanged; SyncService trims and saves nothing unless /balance/ answers OK.
     */
    public function login(
        string $username,
        #[\SensitiveParameter]
        string $password
    ): bool {
        $result = $this->sync->login($username, $password);
        foreach ($result['warnings'] as $warning) {
            $this->notify('W', (string) $warning);
        }
        if (!$result['ok']) {
            $this->notify('E', (string) $result['message']);

            return false;
        }
        $this->notify('N', (string) $result['message']);
        if (isset($result['sync']['ok']) && !$result['sync']['ok']) {
            // 2026-10-21 03:16 +03: the reason of the failed sync, not a generic text (Task 1.10 fix round 1 item 1),
            // task 596.
            $this->say('W', 'kwtsms.settings.sync_failed_reason', ['[reason]' => $this->syncReason($result['sync'])]);
        }

        return true;
    }

    /**
     * Why a sync failed: SyncService's message, else the text of its code, else "no reason given".
     * 2026-10-21 03:16 +03: added, Task 1.10 fix round 1 item 1, task 596.
     *
     * @param mixed $summary SyncService::sync() result
     */
    private function syncReason($summary): string
    {
        $summary = is_array($summary) ? $summary : [];
        $message = isset($summary['message']) && is_string($summary['message']) ? trim($summary['message']) : '';
        if ($message !== '') {
            return $message;
        }
        $code = isset($summary['code']) && is_string($summary['code']) ? $summary['code'] : '';

        return $code !== '' ? ApiErrors::describe($code) : (string) __('kwtsms.settings.sync_no_reason');
    }

    /** Class, message and file:line of a caught throwable, for an error row (no trace, PRD 10). */
    private static function describeThrowable(\Throwable $e): string
    {
        return get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    /** PRD 6.1 Disconnect: credentials, sender and cached data cleared, SMS off. */
    public function logout(): bool
    {
        try {
            $this->sync->logout();
        } catch (\RuntimeException $e) {
            // 2026-10-21 03:16 +03: with file:line (Task 1.10 fix round 1 item 9), task 596.
            $this->log->log('error', 'settings.logout_failed', self::describeThrowable($e));
            $this->say('E', 'kwtsms.settings.disconnect_failed');

            return false;
        }
        $this->say('N', 'kwtsms.settings.disconnected');

        return true;
    }

    /**
     * save_settings: only FORM_KEYS, validated by prepare() and by Config::save(). Logs settings.save at info with
     * key names and reasons only (never a value).
     *
     * @param mixed $posted kwtsms_settings of the request
     * @param mixed $ack    The KWT-SMS acknowledgement checkbox (Y when ticked)
     *
     * @return array{saved: string[], rejected: array<string,string>}
     */
    public function save($posted, $ack): array
    {
        $current = [
            'test_mode' => $this->config->testMode() ? 'Y' : 'N',
            'sender_id' => $this->config->senderId(),
            'default_country_code' => $this->config->defaultCountryCode(),
            // 2026-10-21 03:16 +03: the account's list decides the effective sender (fix round 1 item 4), task 596.
            'sender_ids' => $this->state->senderIds(),
        ];
        $prepared = self::prepare(is_array($posted) ? $posted : [], $current, $ack);
        $values = $prepared['values'];
        $reasons = $prepared['reasons'];

        $rejected = $values === [] ? [] : $this->config->save('save_settings', $values);
        foreach ($rejected as $key) {
            $reasons[$key] = $this->rejectionReason($key);
        }
        $saved = array_values(array_diff(array_keys($values), $rejected));

        // 2026-10-21 03:19 +03: the store's own reason for a key it refused (no value), Task 1.10 fix round 1 item 14,
        // task 596.
        $store_errors = [];
        foreach ($reasons as $key => $reason) {
            if ($reason === 'not_stored') {
                $store_errors[$key] = (string) $this->config->lastWriteError($key);
            }
        }
        $this->log->log('info', 'settings.save', 'save_settings', ['saved' => $saved, 'rejected' => $reasons]
            + ($store_errors === [] ? [] : ['store_errors' => $store_errors]));

        // 2026-10-21 03:16 +03: "Settings saved" only when nothing was refused; next to a refusal the notice says the
        // OTHER settings were saved (Task 1.10 fix round 1 item 10), task 596.
        if ($saved !== []) {
            $this->say('N', $reasons === [] ? 'kwtsms.settings.saved' : 'kwtsms.settings.saved_others');
        }
        foreach ($reasons as $key => $reason) {
            $this->notify('E', $this->rejectionText($key, $reason), false);
        }

        return ['saved' => $saved, 'rejected' => $reasons];
    }

    /**
     * Turns the posted form into Config values. Pure (no CS-Cart, no I/O).
     * - only FORM_KEYS; a list key takes a list of strings (empty items dropped), any other key one string;
     * - default_country_code must be a key of PhoneNormalizer::countryOptions() (int keys: isset after an exact
     *   digits check, then compared as text, so "0965", "965 " or "965.0" are refused);
     * - customer_sms_countries always holds the default country (the posted one, else the current one);
     * - low_balance_threshold empty = auto;
     * - test_mode N while it is on and the sender (posted, else current) is KWT-SMS needs $ack === 'Y' (PRD 6.1).
     *   2026-10-21 03:16 +03 (fix round 1 item 4, task 596): the posted sender counts only when it is in
     *   $current['sender_ids']; choosing KWT-SMS while test mode is already off needs the acknowledgement too
     *   (refused: the sender stays, reason kwtsms_ack_sender).
     *
     * @param array<string,mixed>  $posted
     * @param array<string,mixed>  $current test_mode, sender_id, default_country_code as stored; sender_ids the list
     * @param mixed                $ack
     *
     * @return array{values: array<string,mixed>, reasons: array<string,string>}
     */
    public static function prepare(array $posted, array $current, $ack): array
    {
        $values = [];
        $reasons = [];
        foreach (self::FORM_KEYS as $key) {
            if (!array_key_exists($key, $posted)) {
                continue;
            }
            $value = $posted[$key];
            if (in_array($key, self::LIST_KEYS, true)) {
                if (!is_array($value)) {
                    $reasons[$key] = 'invalid';
                    continue;
                }
                $list = [];
                foreach ($value as $item) {
                    if (!is_string($item)) {
                        $reasons[$key] = 'invalid';
                        continue 2;
                    }
                    if (trim($item) !== '') {
                        $list[] = trim($item);
                    }
                }
                $values[$key] = $list;
                continue;
            }
            if (!is_string($value)) {
                $reasons[$key] = 'invalid';
                continue;
            }
            $values[$key] = $value;
        }

        if (array_key_exists('default_country_code', $values)) {
            $code = trim($values['default_country_code']);
            if (!self::isCountryOption($code)) {
                unset($values['default_country_code']);
                $reasons['default_country_code'] = 'unknown_country';
            } else {
                $values['default_country_code'] = $code;
            }
        }
        $default = $values['default_country_code'] ?? (string) ($current['default_country_code'] ?? '');
        if (array_key_exists('customer_sms_countries', $values)) {
            $codes = $values['customer_sms_countries'];
            if ($default !== '' && !in_array($default, $codes, true)) {
                array_unshift($codes, $default);
            }
            $values['customer_sms_countries'] = implode(',', $codes);
        }
        if (array_key_exists('placed_status_extra', $values)) {
            $values['placed_status_extra'] = implode(',', $values['placed_status_extra']);
        }
        if (array_key_exists('low_balance_threshold', $values) && trim($values['low_balance_threshold']) === '') {
            $values['low_balance_threshold'] = 'auto';
        }

        // 2026-10-21 03:16 +03: the acknowledgement guards the resulting state "test mode off with KWT-SMS", reached
        // either by turning test mode off or by choosing KWT-SMS while it is already off. The effective sender is the
        // posted one only when it is on the account's list (Config refuses any other, the saved one stays). Task 1.10
        // fix round 1 item 4, task 596.
        $current_test = ($current['test_mode'] ?? 'Y') === 'N' ? 'N' : 'Y';
        $current_sender = (string) ($current['sender_id'] ?? '');
        $listed = isset($current['sender_ids']) && is_array($current['sender_ids']) ? $current['sender_ids'] : [];
        $posted_sender = array_key_exists('sender_id', $values) ? trim((string) $values['sender_id']) : null;
        $sender = $posted_sender !== null && in_array($posted_sender, $listed, true) ? $posted_sender : $current_sender;
        $posted_test = array_key_exists('test_mode', $values) ? strtoupper(trim((string) $values['test_mode'])) : '';
        $test_after = in_array($posted_test, ['Y', 'N'], true) ? $posted_test : $current_test;
        if ($test_after === 'N' && $sender === self::TEST_SENDER && $ack !== 'Y') {
            if ($current_test === 'Y') {
                unset($values['test_mode']);
                $reasons['test_mode'] = 'kwtsms_ack';
            } elseif ($current_sender !== self::TEST_SENDER) {
                unset($values['sender_id']);
                $reasons['sender_id'] = 'kwtsms_ack_sender';
            }
        }

        return ['values' => $values, 'reasons' => $reasons];
    }

    /**
     * PRD 6.14 test SMS: the fixed text plus an optional suffix of up to 40 characters, to one number (the default
     * country is added to a local number unless the number is ticked as international), at most 10 an hour per
     * administrator. Sent through the one send pipeline (source manual, interactive, audience admin), so every gate,
     * cap and log applies; the attempt row records the administrator and the IP.
     *
     * @param mixed $input kwtsms_test of the request: phone, intl (Y), suffix
     *
     * @return array<string,mixed>|null The result panel, or null when nothing was sent (a notification says why)
     */
    public function testSms($input, int $admin_id, string $ip, string $lang_code): ?array
    {
        $input = is_array($input) ? $input : [];
        $phone = isset($input['phone']) && is_string($input['phone']) ? trim($input['phone']) : '';
        $intl = isset($input['intl']) && $input['intl'] === 'Y';
        $suffix = isset($input['suffix']) && is_string($input['suffix']) ? trim($input['suffix']) : '';

        if ($phone === '') {
            $this->say('E', 'kwtsms.test_sms.phone_required');

            return null;
        }
        if (mb_strlen($suffix, 'UTF-8') > self::TEST_SMS_SUFFIX_MAX) {
            $this->say('E', 'kwtsms.test_sms.suffix_too_long', ['[max]' => (string) self::TEST_SMS_SUFFIX_MAX]);

            return null;
        }

        return $this->sendTestText($phone, $intl, $this->testSmsText($suffix, $lang_code), $admin_id, $ip, $lang_code);
    }

    /**
     * The send half of a test SMS, for any text: at most 10 an hour per administrator (the one count, whoever sends,
     * on the attempt log), the number normalized with the default country unless it is international, the text sent
     * through SmsService::sendTest() (ruling R30: it works before SMS are switched on, every other gate applies).
     * Used by testSms() (the fixed text) and by the Templates page's "Send a test of this template" (a rendered
     * template with sample data).
     * 2026-10-21 05:19 +03: split out of testSms(), behaviour of testSms() unchanged. Task 1.12 fix round 1 item 2, task 596.
     *
     * @return array<string,mixed>|null The result panel, or null when nothing was sent (a notification says why)
     */
    public function sendTestText(string $phone, bool $intl, string $text, int $admin_id, string $ip, string $lang_code): ?array
    {
        $phone = trim($phone);
        if ($phone === '') {
            $this->say('E', 'kwtsms.test_sms.phone_required');

            return null;
        }
        try {
            $sent = $this->attempts->countRecent('', '', 3600, [], [
                'event_id' => self::TEST_SMS_EVENT,
                'actor_user_id' => $admin_id,
            ]);
        } catch (\Throwable $e) {
            // 2026-10-21 03:16 +03: with file:line (Task 1.10 fix round 1 item 9), task 596.
            $this->log->log('error', 'settings.test_sms_count_failed', self::describeThrowable($e));
            $this->say('E', 'kwtsms.test_sms.unavailable');

            return null;
        }
        if ($sent >= self::TEST_SMS_PER_HOUR) {
            $this->log->log('warning', 'settings.test_sms_capped', 'test SMS limit reached', [
                'admin_id' => $admin_id,
                'sent_last_hour' => $sent,
            ]);
            $this->say('E', 'kwtsms.test_sms.capped', ['[max]' => (string) self::TEST_SMS_PER_HOUR]);

            return null;
        }

        // An international number keeps its own code: a leading + stops the default-country rule (D18).
        $digits_only = preg_replace('/^\s*(\+|00)/', '', $phone);
        $to = $intl ? '+' . $digits_only : $phone;
        $normalized = PhoneNormalizer::normalize($to, $intl ? '' : $this->config->defaultCountryCode());

        // 2026-10-21 03:16 +03: sendTest(): the test SMS works before SMS is switched on (PRD 6.9 order); every other
        // gate applies (ruling R30, Task 1.10 fix round 1 item 2), task 596.
        $result = $this->sms->sendTest([$to], $text, [
            'source' => 'manual',
            'event_id' => self::TEST_SMS_EVENT,
            'receiver' => 'A',
            'audience' => 'admin',
            'interactive' => true,
            'actor_user_id' => $admin_id,
            'ip' => $ip,
            'lang_code' => $lang_code,
        ]);

        return $this->resultPanel($result, $normalized);
    }

    /** Sync now: balance, Sender IDs and coverage (PRD 6.1). */
    public function syncNow(): bool
    {
        $summary = $this->sync->sync('admin');
        if (!empty($summary['ok'])) {
            $this->say('N', 'kwtsms.settings.synced');

            return true;
        }
        if (($summary['skipped'] ?? null) === 'no_credentials') {
            $this->notify('E', $this->reasonText('no_credentials'), false);

            return false;
        }
        // 2026-10-21 03:16 +03: the reason of the failed sync (Task 1.10 fix round 1 item 1), task 596.
        $this->say('E', 'kwtsms.settings.sync_failed_reason', ['[reason]' => $this->syncReason($summary)]);

        return false;
    }

    /** Refresh balance (PRD 6.1): one live /balance/. */
    public function refreshBalance(): bool
    {
        $balance = $this->sync->refreshBalanceIfAllowed(true, 'admin');
        if ($balance === null) {
            $this->say('E', 'kwtsms.settings.balance_not_refreshed');

            return false;
        }
        $this->say('N', 'kwtsms.settings.balance_refreshed', ['[balance]' => self::number($balance)]);

        return true;
    }

    /** Retry now (PRD 6.1) for a suspended gateway. */
    public function retryGateway(): bool
    {
        $result = $this->sync->retrySuspension(true, 'admin');
        if ($result['state'] === 'resumed') {
            $this->say('N', 'kwtsms.settings.resumed');

            return true;
        }
        if ($result['state'] === 'not_suspended') {
            $this->say('N', 'kwtsms.settings.not_suspended');

            return true;
        }
        $this->say('E', 'kwtsms.settings.still_suspended', [
            '[reason]' => ApiErrors::describe((string) $result['code']),
        ]);

        return false;
    }

    /** Resume now (PRD 6.14): the store daily count starts again. */
    public function resumeDailyCap(): void
    {
        $this->state->resumeDailyCap();
        $this->log->log('info', 'settings.daily_cap_resumed', 'resume now');
        $this->say('N', 'kwtsms.settings.resumed_daily_cap');
    }

    /** Regenerate the cron key (PRD 6.11). A failed write keeps the old key, is logged and reported. */
    public function regenerateCronKey(): bool
    {
        try {
            $this->config->regenerateCronKey();
        } catch (\RuntimeException $e) {
            // 2026-10-21 03:16 +03: with file:line (Task 1.10 fix round 1 item 9), task 596.
            $this->log->log('error', 'settings.cron_key_failed', self::describeThrowable($e));
            $this->say('E', 'kwtsms.settings.key_not_regenerated');

            return false;
        }
        $this->log->log('info', 'settings.cron_key_regenerated', 'cron key regenerated');
        $this->say('N', 'kwtsms.settings.key_regenerated');

        return true;
    }

    // ================================================================== helpers

    /** The test SMS text: "{store_name}: kwtSMS test message" plus the suffix. */
    public function testSmsText(string $suffix, string $lang_code = ''): string
    {
        $lang = $lang_code !== '' ? $lang_code : (defined('CART_LANGUAGE') ? (string) CART_LANGUAGE : 'en');
        $text = TemplateRenderer::render(self::TEST_SMS_TEXT, ['store_name' => $this->brand($lang)]);

        return $suffix === '' ? $text : $text . ' ' . $suffix;
    }

    /**
     * The result panel of a test SMS.
     *
     * @return array<string,mixed>
     */
    private function resultPanel(SendResult $result, string $normalized): array
    {
        $recipient = $result->recipients()[0] ?? [];
        $outcome = (string) ($recipient['outcome'] ?? '');
        $ids = $result->messageIds();
        // 2026-10-21 03:16 +03: the number the pipeline itself normalized and logged (the attempt row's phone); the
        // page's own normalization only when the pipeline kept none (Task 1.10 fix round 1 item 5), task 596.
        $pipeline_phone = (string) ($recipient['phone'] ?? '');

        return [
            'status' => $result->status(),
            'phone' => $pipeline_phone !== '' ? $pipeline_phone : $normalized,
            'outcome' => $outcome,
            'outcome_text' => $outcome === '' ? '' : $this->text('kwtsms.outcome.' . $outcome),
            'reason' => (string) ($recipient['reason'] ?? ''),
            'error' => $result->errorMessage(),
            'msg_id' => $ids[0] ?? '',
            'points' => in_array($outcome, SendResult::ACCEPTED_OUTCOMES, true)
                ? self::number($result->pointsCharged())
                : '',
            'balance_after' => $result->balanceAfter() === null ? '' : self::number((float) $result->balanceAfter()),
            'test' => $result->isTest() && in_array($outcome, SendResult::ACCEPTED_OUTCOMES, true),
            'at' => time(),
        ];
    }

    private function dailyCapTripped(): bool
    {
        try {
            $midnight = (int) strtotime('today');
            $since = max($midnight, $this->state->dailyCapResumedAt());
            $seconds = max(1, time() - $since + 1);

            return $this->attempts->countRecent('', '', $seconds, ['blocked_daily_cap']) > 0;
        } catch (\Throwable $e) {
            // 2026-10-21 03:16 +03: logged before the fallback (Task 1.10 fix round 1 item 3), task 596.
            $this->log->log('error', 'settings.daily_cap_check_failed', self::describeThrowable($e));

            return false;
        }
    }

    /** Why Config::save() refused a key (it returns names only). */
    private function rejectionReason(string $key): string
    {
        // 2026-10-21 08:25 +03: the store-refused check comes FIRST (it was last): the early returns for `enabled`
        // and `sender_id` below hid a failed store write behind their own reason, so the page said "invalid" or
        // "not in the list" and the settings.save row had no store_errors, cleanup item 2, task 596.
        if (method_exists($this->config, 'lastWriteError') && $this->config->lastWriteError($key) !== null) {
            return 'not_stored';
        }
        if ($key === 'enabled') {
            list($ok, $why) = $this->state->isConfigured($this->config);
            // 2026-10-21 03:16 +03: the stored switch decides the wording: still off, or on but unable to send
            // (Task 1.10 fix round 1 item 10), task 596.
            if ($ok) {
                return 'invalid';
            }

            return ($this->config->enabled() ? 'on_not_ready:' : 'not_configured:') . (string) $why;
        }
        if ($key === 'sender_id') {
            return 'sender_not_in_list';
        }
        // 2026-10-21 03:16 +03: a key the settings store refused (Config logs its reason), Task 1.10 fix round 1
        // item 14, task 596. 2026-10-21 08:25 +03: that check moved to the top of this method, cleanup item 2.

        return 'invalid';
    }

    private function rejectionText(string $key, string $reason): string
    {
        if ($reason === 'kwtsms_ack') {
            return $this->text('kwtsms.settings.ack_required');
        }
        // 2026-10-21 03:16 +03: KWT-SMS chosen while test mode is already off (Task 1.10 fix round 1 item 4), task 596.
        if ($reason === 'kwtsms_ack_sender') {
            return $this->text('kwtsms.settings.ack_required_sender');
        }
        $prefixes = [
            'not_configured:' => 'kwtsms.settings.enable_refused',
            'on_not_ready:' => 'kwtsms.settings.on_not_ready',
        ];
        foreach ($prefixes as $prefix => $langvar) {
            if (strpos($reason, $prefix) === 0) {
                $why = $this->knownReason(substr($reason, strlen($prefix)));

                return $this->text($langvar, ['[reason]' => (string) __('kwtsms.reason.' . $why)]);
            }
        }
        $field = (string) __('kwtsms.settings.field.' . $key);
        $why_keys = [
            'unknown_country' => 'kwtsms.reason.unknown_country',
            'sender_not_in_list' => 'kwtsms.reason.sender_unknown',
            'not_stored' => 'kwtsms.reason.not_stored',
        ];
        $why = (string) __($why_keys[$reason] ?? 'kwtsms.reason.invalid');

        return $this->text('kwtsms.settings.not_saved', ['[field]' => $field, '[reason]' => $why]);
    }

    /** The text of an isConfigured() reason. */
    private function reasonText(string $reason): string
    {
        return $this->text('kwtsms.reason.' . $this->knownReason($reason));
    }

    /** An isConfigured() reason with a language variable (anything else reads as "log in first"). */
    private function knownReason(string $reason): string
    {
        $known = [
            'no_credentials', 'relogin_required', 'not_logged_in', 'no_sender_ids', 'sender_not_in_list',
            'sender_banned',
        ];

        return in_array($reason, $known, true) ? $reason : 'no_credentials';
    }

    /**
     * @return string|int|float|bool
     */
    private function current(string $key)
    {
        switch ($key) {
            case 'enabled':
                return $this->config->enabled() ? 'Y' : 'N';
            case 'test_mode':
                return $this->config->testMode() ? 'Y' : 'N';
            case 'pilot_mode':
                return $this->config->pilotMode() ? 'Y' : 'N';
            case 'default_country_code':
                return $this->config->defaultCountryCode();
            case 'customer_sms_countries':
                return $this->config->customerSmsCountries();
            case 'placed_status_extra':
                return $this->config->placedStatusExtra();
            case 'max_pages_notification':
                return $this->config->maxPagesNotification();
            case 'sender_id':
                return $this->config->senderId();
            case 'sender_type':
                return $this->config->senderType();
            case 'cap_recipient_hour':
                return $this->config->capRecipientHour();
            case 'cap_recipient_day':
                return $this->config->capRecipientDay();
            case 'cap_admin_day':
                return $this->config->capAdminDay();
            case 'cap_store_day':
                return $this->config->capStoreDay();
            case 'low_balance_threshold':
                $threshold = $this->config->lowBalanceThreshold();

                return $threshold === null ? '' : (string) $threshold;
            case 'quiet_hours_enabled':
                return $this->config->quietHoursEnabled() ? 'Y' : 'N';
            case 'quiet_hours_from':
                return $this->config->quietHoursFrom();
            case 'quiet_hours_to':
                return $this->config->quietHoursTo();
            case 'log_level':
                return $this->config->logLevel();
            case 'retention_messages_days':
                return $this->config->retentionMessagesDays();
            case 'retention_attempts_days':
                return $this->config->retentionAttemptsDays();
            case 'retention_debug_days':
                return $this->config->retentionDebugDays();
            case 'cron_url_enabled':
                return $this->config->cronUrlEnabled() ? 'Y' : 'N';
        }

        return '';
    }

    private static function isCountryOption(string $code): bool
    {
        if (preg_match('/^[1-9][0-9]{0,3}$/D', $code) !== 1) {
            return false;
        }
        $options = PhoneNormalizer::countryOptions();

        return isset($options[(int) $code]) && (string) (int) $code === $code;
    }

    private function countryLabel(string $code): string
    {
        // 2026-10-21 03:16 +03: the label in the admin language (fix round 1 item 8), task 596.
        $options = $this->countryOptions();

        return ctype_digit($code) && isset($options[(int) $code]) ? (string) $options[(int) $code] : $code;
    }

    /**
     * The country dropdown in the admin language (cached per object). 2026-10-21 03:16 +03: added, Task 1.10 fix
     * round 1 item 8, task 596.
     *
     * @return array<int,string>
     */
    private function countryOptions(): array
    {
        if ($this->country_options === null) {
            $lang = defined('CART_LANGUAGE') ? (string) CART_LANGUAGE : 'en';
            $this->country_options = self::localizeCountryOptions(
                PhoneNormalizer::countryOptions(),
                $this->countryNames($lang)
            );
        }

        return $this->country_options;
    }

    /**
     * Country options with the names of core ?:country_descriptions: a calling code that belongs to exactly one ISO2
     * code of PhoneNormalizer::ISO_CALLING_CODES gets that country's name; any other keeps the English name. The first
     * option (Kuwait) stays first; the rest are sorted by the shown name. Pure.
     * 2026-10-21 03:16 +03: added, Task 1.10 fix round 1 item 8, task 596.
     *
     * @param array<int,string>    $options      PhoneNormalizer::countryOptions()
     * @param array<string,string> $names_by_iso ISO2 => country name in the admin language
     *
     * @return array<int,string> calling code => "Name (+code)"
     */
    public static function localizeCountryOptions(array $options, array $names_by_iso): array
    {
        $isos_by_code = [];
        foreach (PhoneNormalizer::ISO_CALLING_CODES as $iso => $code) {
            $isos_by_code[(string) $code][] = (string) $iso;
        }
        $localized = [];
        foreach ($options as $code => $label) {
            $isos = $isos_by_code[(string) $code] ?? [];
            $name = count($isos) === 1 && isset($names_by_iso[$isos[0]]) ? trim((string) $names_by_iso[$isos[0]]) : '';
            $localized[$code] = $name !== '' ? $name . ' (+' . $code . ')' : (string) $label;
        }
        $first_code = array_key_first($options);
        $first = $first_code === null ? [] : [$first_code => $localized[$first_code]];
        unset($localized[$first_code]);
        uasort($localized, static function (string $a, string $b): int {
            return strcmp(mb_strtolower($a, 'UTF-8'), mb_strtolower($b, 'UTF-8'));
        });

        return $first + $localized;
    }

    /**
     * ISO2 => country name in $lang from core ?:country_descriptions; [] when it cannot be read (logged).
     * 2026-10-21 03:16 +03: added, Task 1.10 fix round 1 item 8, task 596.
     *
     * @return array<string,string>
     */
    protected function countryNames(string $lang): array
    {
        try {
            $names = db_get_hash_single_array(
                'SELECT code, country FROM ?:country_descriptions WHERE lang_code = ?s',
                ['code', 'country'],
                $lang
            );

            return is_array($names) ? array_map('strval', $names) : [];
        } catch (\Throwable $e) {
            $this->log->log('error', 'settings.country_names_failed', self::describeThrowable($e));

            return [];
        }
    }

    /** A credit amount without trailing zeros: 1990, 1990.5. */
    private static function number(float $value): string
    {
        $text = number_format($value, 2, '.', '');

        return rtrim(rtrim($text, '0'), '.');
    }

    /** The brand of the default storefront (BrandNames, PRD 6.4); '' when none is named. */
    protected function brand(string $lang): string
    {
        try {
            $storefront_id = 0;
            $app = \Tygh\Tygh::$app;
            if (isset($app['storefront.repository'])) {
                $storefront = $app['storefront.repository']->findDefault();
                $storefront_id = $storefront ? (int) $storefront->storefront_id : 0;
            }

            return BrandNames::get($storefront_id, $lang);
        } catch (\Throwable $e) {
            // 2026-10-21 03:16 +03: logged before the fallback (Task 1.10 fix round 1 item 3), task 596.
            $this->log->log('error', 'settings.brand_failed', self::describeThrowable($e));

            return '';
        }
    }

    /**
     * A language variable with its placeholder values escaped (PRD 10: __() does not escape placeholder values,
     * and notifications are rendered without escaping).
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

    /**
     * A notification of one of our language variables, its placeholder values escaped by text().
     *
     * @param array<string,string> $params
     */
    private function say(string $type, string $key, array $params = []): void
    {
        $this->notify($type, $this->text($key, $params), false);
    }

    /**
     * A notification: N notice, W warning, E error. A text that comes from elsewhere (SyncService messages, API
     * texts) is escaped here ($escape true); a text built by text() already has its values escaped.
     */
    protected function notify(string $type, string $message, bool $escape = true): void
    {
        $titles = ['N' => 'notice', 'W' => 'warning', 'E' => 'error'];
        fn_set_notification($type, __($titles[$type] ?? 'notice'), $escape ? fn_html_escape($message) : $message);
    }
}
