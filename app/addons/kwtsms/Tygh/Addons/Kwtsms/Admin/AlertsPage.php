<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/AlertsPage.php
 * CREATED:   2026-10-21 04:03 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The Admin SMS Alerts page of the kwtsms admin controller: the list of up to 10 administrator numbers
 *            (checked one by one, saved when they pass the local checks), the alert language, the read-only admin
 *            switches, and the kwtSMS /validate/ verdict per number. Handles update_admin_alerts (save, or check only).
 * WHY:       PRD 6.3 and 6.6. Config refuses a whole list for one bad number and does not say which, so this page runs
 *            PhoneNormalizer::validateInput() on each number itself (per-number reasons), passes Config only the
 *            numbers that passed, and so a typo never costs the other nine. Local checks decide saving; the /validate/
 *            verdict is a warning, shown next to the number ("kwtSMS cannot route this number" / "Could not check with
 *            kwtSMS now") and as a W notice, also when kwtSMS cannot be reached or no account is connected.
 *            /validate/ is called only here: on save and on the Check helper, once per request, with the numbers that
 *            passed. A list where every number was refused keeps the saved list; an empty box clears it. The log holds
 *            counts and key names, never a number. The Check helper saves nothing and never says "saved".
 * USAGE:     $page = AlertsPage::fromContainer(Tygh::$app);
 *            $view  = $page->viewData($flash_or_null);          // GET kwtsms.admin_alerts
 *            $flash = $page->update($_REQUEST['kwtsms_alerts'], $check_only);   // POST update_admin_alerts[.check]
 *            AlertsPage::parse($text, $hint)    // pure: ['entries' => [...], 'accepted' => [...], 'ignored' => n]
 *            AlertsPage::verdicts($numbers, $api_reply)   // pure: number => ['status', 'code']
 *            The flash goes into the session for one redirect: mode, rows (raw, phone, ok, reason, repeat, verdict,
 *            verdict_code), saved_count, kept_old, lang_ok, ignored, typed, at.
 * DEPENDS:   Settings\ConfigInterface, Sms\{PhoneNormalizer,ApiErrors}, Log\DebugLog; CS-Cart fn_set_notification(),
 *            __(), fn_html_escape(), Tygh\Languages\Languages, EventDispatcherProvider (through protected methods a
 *            test replaces).
 * CHANGED:   2026-10-21 07:02 +03: header tidied; the kwtSMS verdict also raises a W notice; the Check helper
 *            never says "not saved" (fix round 1, Tasks 1.13 and 1.15, task 596).
 */

namespace Tygh\Addons\Kwtsms\Admin;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;

class AlertsPage
{
    /** PRD 6.6: at most 10 numbers. */
    public const MAX_NUMBERS = 10;

    /** Entries looked at per request; the rest are counted and ignored (a pasted spreadsheet column). */
    public const MAX_ENTRIES = 50;

    /** Longest typed number kept for display (characters). */
    public const MAX_TOKEN_LENGTH = 40;

    /** The notification transport id (core notification_settings.transport_id). */
    private const TRANSPORT = 'kwtsms';

    /** Digits (Latin, Arabic-Indic, Persian) and the usual formatting: the same set Config accepts. */
    private const NUMBER_CHARS = '/^[+0-9\s().\-\x{0660}-\x{0669}\x{06F0}-\x{06F9}]+$/u';

    /** @var ConfigInterface */
    private $config;

    /** @var DebugLog */
    private $log;

    /** @var callable (string $context): \Tygh\Addons\Kwtsms\Sms\ApiClient */
    private $client_factory;

    public function __construct(ConfigInterface $config, DebugLog $log, callable $client_factory)
    {
        $this->config = $config;
        $this->log = $log;
        $this->client_factory = $client_factory;
    }

    /**
     * @param \ArrayAccess<string,mixed> $app Tygh::$app
     */
    public static function fromContainer($app): self
    {
        return new self($app['kwtsms.config'], $app['kwtsms.logs.debug'], $app['kwtsms.api_client_factory']);
    }

    // ================================================================== pure rules

    /**
     * Splits the typed text on commas (also the Arabic comma and semicolon), semicolons and line breaks and checks
     * every
     * number on its own. A number that passes is normalized with the default country as hint; a repeat of an accepted
     * number is kept once and marked; the 11th different number is refused with reason "limit"; a number that fails
     * keeps
     * the reason of PhoneNormalizer (email, too_short, too_long, bad_length, not_mobile, empty) or "characters".
     *
     * @return array{entries: array<int,array<string,mixed>>, accepted: string[], ignored: int}
     */
    public static function parse(string $text, string $hint): array
    {
        $tokens = (array) preg_split('/[,;\r\n\x{060C}\x{061B}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $entries = [];
        $accepted = [];
        $seen = 0;
        $ignored = 0;
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }
            if ($seen >= self::MAX_ENTRIES) {
                $ignored++;
                continue;
            }
            $seen++;
            $raw = mb_strlen($token, 'UTF-8') > self::MAX_TOKEN_LENGTH
                ? mb_substr($token, 0, self::MAX_TOKEN_LENGTH, 'UTF-8') . '...'
                : $token;
            // 2026-10-21 04:08 +03: an e-mail address is named as one, not as "bad characters" (Task 1.13, task 596).
            if (strpos($token, '@') !== false) {
                $entries[] = ['raw' => $raw, 'ok' => false, 'reason' => 'email', 'phone' => '', 'repeat' => false];
                continue;
            }
            if (preg_match(self::NUMBER_CHARS, $token) !== 1) {
                $entries[] = ['raw' => $raw, 'ok' => false, 'reason' => 'characters', 'phone' => '', 'repeat' => false];
                continue;
            }
            list($valid, $reason, $normalized) = PhoneNormalizer::validateInput($token, $hint);
            if (!$valid) {
                $entries[] = [
                    'raw' => $raw,
                    'ok' => false,
                    'reason' => (string) $reason,
                    'phone' => (string) $normalized,
                    'repeat' => false,
                ];
                continue;
            }
            $normalized = (string) $normalized;
            if (in_array($normalized, $accepted, true)) {
                $entries[] = ['raw' => $raw, 'ok' => true, 'reason' => '', 'phone' => $normalized, 'repeat' => true];
                continue;
            }
            if (count($accepted) >= self::MAX_NUMBERS) {
                $entries[] = [
                    'raw' => $raw,
                    'ok' => false,
                    'reason' => 'limit',
                    'phone' => $normalized,
                    'repeat' => false,
                ];
                continue;
            }
            $accepted[] = $normalized;
            $entries[] = ['raw' => $raw, 'ok' => true, 'reason' => '', 'phone' => $normalized, 'repeat' => false];
        }

        return ['entries' => $entries, 'accepted' => $accepted, 'ignored' => $ignored];
    }

    /**
     * What the /validate/ reply says about each number: ok (routable), cannot_route (the gateway sorted it into ER or
     * NR), or unchecked (the call failed, or the reply does not mention the number). For unchecked, code is the error
     * code of the call ('' when the reply simply did not list the number).
     *
     * @param string[]            $numbers Normalized numbers (digits only)
     * @param array<string,mixed> $api     ApiClient::validate() result
     *
     * @return array<string,array{status:string,code:string}>
     */
    public static function verdicts(array $numbers, array $api): array
    {
        $lists = ['OK' => [], 'ER' => [], 'NR' => []];
        $mobile = isset($api['mobile']) && is_array($api['mobile']) ? $api['mobile'] : [];
        foreach (array_keys($lists) as $group) {
            foreach ((isset($mobile[$group]) && is_array($mobile[$group]) ? $mobile[$group] : []) as $item) {
                // The gateway may echo a number with a plus or leading zeros.
                $digits = ltrim((string) preg_replace('/\D+/', '', (string) $item), '0');
                if ($digits !== '') {
                    $lists[$group][$digits] = true;
                }
            }
        }
        $code = isset($api['code']) && is_string($api['code']) ? $api['code'] : '';

        $out = [];
        foreach ($numbers as $number) {
            $number = (string) $number;
            if (isset($lists['OK'][$number])) {
                $out[$number] = ['status' => 'ok', 'code' => ''];
            } elseif (isset($lists['ER'][$number]) || isset($lists['NR'][$number])) {
                $out[$number] = ['status' => 'cannot_route', 'code' => ''];
            } else {
                $out[$number] = ['status' => 'unchecked', 'code' => $code];
            }
        }

        return $out;
    }

    // ================================================================== GET kwtsms.admin_alerts

    /**
     * Everything the template shows.
     *
     * @param array<string,mixed>|null $flash The result of the last update, shown once
     *
     * @return array<string,mixed>
     */
    public function viewData(?array $flash): array
    {
        $phones = $this->config->adminPhones();
        $languages = $this->languages();

        // 2026-10-21 04:30 +03: after a Check the box shows what was typed (nothing was saved), Task 1.13, task 596.
        $text = $phones === [] ? '' : '+' . implode("\n+", $phones);
        if (
            $flash !== null
            && ($flash['mode'] ?? '') === 'check'
            && isset($flash['typed'])
            && is_string($flash['typed'])
        ) {
            $text = $flash['typed'];
        }

        return [
            'phones' => $phones,
            'phones_text' => $text,
            'max' => self::MAX_NUMBERS,
            'max_entries' => self::MAX_ENTRIES,
            'lang' => $this->config->adminAlertLang(),
            'languages' => $languages,
            'default_lang_name' => $this->defaultLanguageName($languages),
            'switches' => $this->adminSwitches(),
            'sms_enabled' => $this->config->enabled(),
            'test_mode' => $this->config->testMode(),
            'connected' => $this->connected(),
            'default_country_code' => $this->config->defaultCountryCode(),
            'result' => $flash === null ? null : $this->withVerdictTexts($flash),
        ];
    }

    /**
     * The flash with the plain-language reason of every failed /validate/ call (the code is stored, the text is made
     * now,
     * in the language of the page).
     *
     * @param array<string,mixed> $flash
     *
     * @return array<string,mixed>
     */
    private function withVerdictTexts(array $flash): array
    {
        if (!isset($flash['rows']) || !is_array($flash['rows'])) {
            return $flash;
        }
        foreach ($flash['rows'] as $i => $row) {
            $code = is_array($row) && isset($row['verdict_code']) && is_string($row['verdict_code'])
                ? $row['verdict_code']
                : '';
            if ($code !== '' && $code !== 'not_connected') {
                $flash['rows'][$i]['verdict_text'] = \Tygh\Addons\Kwtsms\Sms\ApiErrors::describe($code);
            }
        }

        return $flash;
    }

    /**
     * The admin-receiver switches, read-only: the order placed switch (null when the event has none) and the status
     * switches that are on, with their names.
     *
     * @return array{placed: ?bool, status_total: int, status_on: array<string,string>}
     */
    private function adminSwitches(): array
    {
        $states = $this->switchStates();
        $names = $this->statusNames();
        $placed = isset($states['kwtsms.order_placed']['A']) ? (bool) $states['kwtsms.order_placed']['A'] : null;
        $total = 0;
        $on = [];
        foreach ($states as $event_id => $receivers) {
            if (strpos((string) $event_id, 'order.status_changed.') !== 0 || !isset($receivers['A'])) {
                continue;
            }
            $total++;
            if ($receivers['A']) {
                $code = strtoupper(substr((string) $event_id, strlen('order.status_changed.')));
                $on[$code] = isset($names[$code]) ? (string) $names[$code] : $code;
            }
        }

        return ['placed' => $placed, 'status_total' => $total, 'status_on' => $on];
    }

    // ================================================================== POST update_admin_alerts

    /**
     * Saves the typed list and language (unless $check_only) and asks kwtSMS about the numbers that passed.
     *
     * @param mixed $posted kwtsms_alerts of the request
     *
     * @return array<string,mixed> The flash (see the file header)
     */
    public function update($posted, bool $check_only): array
    {
        $posted = is_array($posted) ? $posted : [];
        $text = isset($posted['admin_phones']) && is_string($posted['admin_phones']) ? $posted['admin_phones'] : null;
        $lang = isset($posted['admin_alert_lang']) && is_string($posted['admin_alert_lang'])
            ? strtolower(trim($posted['admin_alert_lang']))
            : null;

        $parsed = $text === null
            ? ['entries' => [], 'accepted' => [], 'ignored' => 0]
            : self::parse($text, $this->config->defaultCountryCode());
        $accepted = $parsed['accepted'];
        $has_text = $text !== null && trim((string) preg_replace('/[,;\x{060C}\x{061B}\s]+/u', '', $text)) !== '';

        $lang_ok = $lang === null || $lang === '' || isset($this->languages()[$lang]);
        $saved_count = 0;
        $kept_old = false;
        $stored = false;
        $store_failed = false;

        if (!$check_only && $text !== null) {
            $values = [];
            if ($accepted === [] && $has_text) {
                // Everything typed was refused: keep the saved list rather than wipe it.
                $kept_old = true;
            } else {
                // A plus sign makes the country code explicit, so the default country hint cannot reinterpret it.
                $values['admin_phones'] = $accepted === [] ? '' : '+' . implode(',+', $accepted);
            }
            if ($lang !== null && $lang_ok) {
                $values['admin_alert_lang'] = $lang;
            }
            if ($values !== []) {
                $rejected = $this->config->save('update_admin_alerts', $values);
                $store_failed = in_array('admin_phones', $rejected, true)
                    || in_array('admin_alert_lang', $rejected, true);
                $stored = !$store_failed;
                if ($stored && isset($values['admin_phones'])) {
                    $saved_count = count($accepted);
                }
            }
        }

        // The gateway's opinion: one call, only for numbers that passed, never decides saving.
        $verdicts = [];
        if ($accepted !== []) {
            if (!$this->connected()) {
                foreach ($accepted as $number) {
                    $verdicts[$number] = ['status' => 'unchecked', 'code' => 'not_connected'];
                }
            } else {
                $verdicts = self::verdicts($accepted, $this->gatewayValidate($accepted));
            }
        }

        $rows = [];
        foreach ($parsed['entries'] as $entry) {
            $verdict = $entry['ok'] && isset($verdicts[$entry['phone']]) ? $verdicts[$entry['phone']] : null;
            $rows[] = [
                'raw' => $entry['raw'],
                'phone' => $entry['phone'],
                'ok' => $entry['ok'],
                'reason' => $entry['reason'],
                'repeat' => $entry['repeat'],
                'verdict' => $verdict === null ? '' : $verdict['status'],
                'verdict_code' => $verdict === null ? '' : $verdict['code'],
            ];
        }

        $refused = 0;
        foreach ($rows as $row) {
            if (!$row['ok']) {
                $refused++;
            }
        }
        // 2026-10-21 07:04 +03: how many numbers kwtSMS can't route and how many it could not be asked about, for the
        // W notices (fix round 1, Tasks 1.13 and 1.15, task 596).
        $cannot_route = 0;
        $unchecked = 0;
        foreach ($verdicts as $verdict) {
            if ($verdict['status'] === 'cannot_route') {
                $cannot_route++;
            } elseif ($verdict['status'] === 'unchecked') {
                $unchecked++;
            }
        }
        $this->log->log('info', 'alerts.update', $check_only ? 'check' : 'save', [
            'typed' => count($parsed['entries']),
            'accepted' => count($accepted),
            'refused' => $refused,
            'saved' => $saved_count,
            'kept_old' => $kept_old,
            'lang_ok' => $lang_ok,
            'store_failed' => $store_failed,
            'validated' => $accepted !== [] && $this->connected(),
        ]);

        $this->say(
            $check_only,
            $stored,
            $store_failed,
            $saved_count,
            $kept_old,
            $refused,
            $lang_ok,
            $text === null,
            $cannot_route,
            $unchecked
        );

        return [
            'mode' => $check_only ? 'check' : 'save',
            'rows' => $rows,
            'saved_count' => $saved_count,
            'kept_old' => $kept_old,
            'lang_ok' => $lang_ok,
            'ignored' => $parsed['ignored'],
            'typed' => $check_only && $text !== null ? mb_substr($text, 0, 2000, 'UTF-8') : '',
            'at' => time(),
        ];
    }

    /** The notifications of one update (the table shows the per-number detail). */
    private function say(
        bool $check_only,
        bool $stored,
        bool $store_failed,
        int $saved_count,
        bool $kept_old,
        int $refused,
        bool $lang_ok,
        bool $nothing_posted,
        int $cannot_route = 0,
        int $unchecked = 0
    ): void {
        if ($nothing_posted) {
            $this->notify('E', $this->text('kwtsms.alerts.nothing_posted'), false);

            return;
        }
        if ($store_failed) {
            $this->notify('E', $this->text('kwtsms.alerts.not_stored'), false);
        }
        if (!$check_only && $stored) {
            $this->notify('N', $this->text('kwtsms.alerts.saved', ['[count]' => (string) $saved_count]), false);
        }
        if ($kept_old) {
            $this->notify('W', $this->text('kwtsms.alerts.kept_old'), false);
        }
        if ($refused > 0 && !$kept_old) {
            // 2026-10-21 07:04 +03: a check saves nothing, so it never says "not saved" (fix round 1, task 596).
            $key = $check_only ? 'kwtsms.alerts.refused_check' : 'kwtsms.alerts.refused';
            $this->notify('W', $this->text($key, ['[count]' => (string) $refused]), false);
        }
        if (!$lang_ok) {
            $this->notify('W', $this->text('kwtsms.alerts.lang_refused'), false);
        }
        // 2026-10-21 07:04 +03: the kwtSMS verdict is a warning next to the badge, also when kwtSMS can't be reached
        // (fix round 1, task 596). It never blocks saving.
        if ($cannot_route > 0) {
            $this->notify(
                'W',
                $this->text(
                    $cannot_route === 1 ? 'kwtsms.alerts.warn.cannot_route_one' : 'kwtsms.alerts.warn.cannot_route',
                    ['[count]' => (string) $cannot_route]
                ),
                false
            );
        }
        if ($unchecked > 0) {
            $this->notify('W', $this->text('kwtsms.alerts.warn.unchecked'), false);
        }
    }

    // ================================================================== seams

    private function connected(): bool
    {
        return $this->config->credentialsState() === ConfigInterface::CREDENTIALS_OK;
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
            $this->log->log('error', 'alerts.validate_failed', get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());

            return ['result' => 'ERROR', 'code' => 'LOCAL_ERROR'];
        }
    }

    /**
     * Installed languages: code => name, direction.
     *
     * @return array<string,array{name:string,direction:string}>
     */
    protected function languages(): array
    {
        $out = [];
        foreach ((array) \Tygh\Languages\Languages::getAll() as $code => $language) {
            if (!in_array($language['status'] ?? 'A', ['A', 'H'], true)) {
                continue;
            }
            $direction = isset($language['direction']) && $language['direction'] === 'rtl' ? 'rtl' : 'ltr';
            $out[(string) $code] = ['name' => (string) $language['name'], 'direction' => $direction];
        }

        return $out !== [] ? $out : ['en' => ['name' => 'English', 'direction' => 'ltr']];
    }

    /**
     * The name of the backend default language (what an empty alert language means).
     *
     * @param array<string,array{name:string,direction:string}> $languages
     */
    protected function defaultLanguageName(array $languages): string
    {
        $code = (string) \Tygh\Registry::get('settings.Appearance.backend_default_language');
        $code = $code !== '' ? $code : 'en';

        return isset($languages[$code]) ? $languages[$code]['name'] : $code;
    }

    /**
     * The effective SMS switch of every event and receiver: event => receiver => on. Read from the service the
     * dispatcher
     * and Administration > Notifications read, so the fail-closed extension is part of it.
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
     * Order status code => name in the admin language.
     *
     * @return array<string,string>
     */
    protected function statusNames(): array
    {
        return array_map('strval', (array) fn_get_simple_statuses(STATUSES_ORDER, false, true));
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
