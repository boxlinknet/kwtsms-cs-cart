<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Alerts/AdminAlerts.php
 * CREATED:   2026-10-21 00:09 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Notices (the admin bell) and admin e-mails about the add-on's own health, at most once per period:
 *            raise($condition, $params, $email, $every_seconds, $instance).
 * WHY:       PRD 6.13. Task 1.6 raises the conditions its send pipeline detects: daily_cap (notice and e-mail,
 *            once per day), low_balance (notice and e-mail, once per crossing; the caller detects the crossing),
 *            suspended (notice and e-mail, once per code per 24 h), tls_failure and intl_limit (notice only, once
 *            per 24 h). Task 1.11 adds the dashboard-side conditions (test mode, cron, backlog) on the same class.
 *            Texts are language variables kwtsms.alert.<condition>.title / .text in the alert language
 *            (admin_alert_lang, else the backend default language); every parameter is HTML-escaped (PRD 10).
 *            A failing notice or mail channel is logged at error and never thrown: an alert must not break a send.
 * USAGE:     $alerts = new AdminAlerts(new StorageDataStore(), $config, $debugLog);
 *            $alerts->raise('daily_cap', ['[cap]' => '1000'], true, 172800, date('Y-m-d'));   // true = raised now
 *            Storage key kwtsms_alerts: {"<condition>[:<instance>]": <unix time last raised>}, entries older than
 *            7 days are dropped. Test seams: the $notify ($condition, $title, $text) and $mail ($condition, $subject,
 *            $body): bool callables replace the notifications center and the mailer.
 * DEPENDS:   Storage\KeyValueStoreInterface, Settings\ConfigInterface, Log\DebugLog; in a store:
 *            Tygh::$app['notifications_center'], Tygh::$app['mailer'], db_get_fields(), __().
 */

namespace Tygh\Addons\Kwtsms\Alerts;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Storage\KeyValueStoreInterface;

class AdminAlerts
{
    public const KEY = 'kwtsms_alerts';

    /**
     * Conditions with language variables kwtsms.alert.<condition>.title and .text.
     * 2026-10-21 01:13 +03: sender_removed, sender_banned (sync, ERR008), test_mode and balance_zero (the daily cron
     * health job) added, Task 1.7, task 596.
     */
    public const CONDITIONS = [
        'daily_cap', 'low_balance', 'suspended', 'tls_failure', 'intl_limit',
        'sender_removed', 'sender_banned', 'test_mode', 'balance_zero',
    ];

    /** Bookkeeping entries older than this are forgotten. */
    private const KEEP_SECONDS = 604800;

    /** @var KeyValueStoreInterface */
    private $store;

    /** @var ConfigInterface */
    private $config;

    /** @var DebugLog|null */
    private $log;

    /** @var callable|null */
    private $notify;

    /** @var callable|null */
    private $mail;

    /** @var callable|null */
    private $clock;

    /**
     * @param callable|null $notify ($condition, $title, $text): void; null = notifications center, every root admin
     * @param callable|null $mail   ($condition, $subject, $body): bool; null = the store mailer to the site admin
     * @param callable|null $clock  Returns the current Unix time (tests); null = time()
     */
    public function __construct(
        KeyValueStoreInterface $store,
        ConfigInterface $config,
        ?DebugLog $log = null,
        ?callable $notify = null,
        ?callable $mail = null,
        ?callable $clock = null
    ) {
        $this->store = $store;
        $this->config = $config;
        $this->log = $log;
        $this->notify = $notify;
        $this->mail = $mail;
        $this->clock = $clock;
    }

    /**
     * Raises a notice (and an e-mail when $email) unless the same condition and instance was raised less than
     * $every_seconds ago (0 = always).
     *
     * @param array<string,scalar> $params Placeholders of the language variables, for example ['[cap]' => 1000]
     *
     * @return bool True when the alert was raised now
     */
    public function raise(
        string $condition,
        array $params = [],
        bool $email = false,
        int $every_seconds = 86400,
        string $instance = ''
    ): bool {
        if (!in_array($condition, self::CONDITIONS, true)) {
            return false;
        }
        try {
            $now = $this->now();
            $tag = substr((string) preg_replace('/[^A-Za-z0-9_.-]/', '', $instance), 0, 40);
            $key = $condition . ($tag === '' ? '' : ':' . $tag);
            $seen = json_decode((string) $this->store->getFresh(self::KEY), true);
            $seen = is_array($seen) ? $seen : [];
            $last = isset($seen[$key]) && is_int($seen[$key]) ? $seen[$key] : null;
            if ($every_seconds > 0 && $last !== null && $now - $last < $every_seconds) {
                return false;
            }
            foreach ($seen as $k => $at) {
                if (!is_int($at) || $now - $at > self::KEEP_SECONDS) {
                    unset($seen[$k]);
                }
            }
            $seen[$key] = $now;
            $this->store->set(self::KEY, (string) json_encode($seen));

            $lang = $this->language();
            $safe = [];
            foreach ($params as $name => $value) {
                $safe[(string) $name] = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            }
            $title = (string) __('kwtsms.alert.' . $condition . '.title', $safe, $lang);
            $text = (string) __('kwtsms.alert.' . $condition . '.text', $safe, $lang);
        } catch (\Throwable $e) {
            $this->failed('alert.failed', $condition, $e);

            return false;
        }

        try {
            if ($this->notify !== null) {
                call_user_func($this->notify, $condition, $title, $text);
            } else {
                $this->notifyAdmins($title, $text, $lang);
            }
        } catch (\Throwable $e) {
            $this->failed('alert.notice_failed', $condition, $e);
        }
        if ($email) {
            try {
                $sent = $this->mail !== null
                    ? call_user_func($this->mail, $condition, $title, $text)
                    : $this->mailAdmin($title, $text, $lang);
                if ($sent !== true) {
                    $this->note('error', 'alert.mail_failed', $condition, []);
                }
            } catch (\Throwable $e) {
                $this->failed('alert.mail_failed', $condition, $e);
            }
        }
        $this->note('warning', 'alert.' . $condition, $title, ['instance' => $instance, 'email' => $email]);

        return true;
    }

    // ------------------------------------------------------------------ the store channels

    /**
     * One persistent bell notice for every active root administrator.
     */
    protected function notifyAdmins(string $title, string $text, string $lang): void
    {
        $center = \Tygh\Tygh::$app['notifications_center'];
        $ids = db_get_fields(
            'SELECT user_id FROM ?:users WHERE user_type = ?s AND company_id = ?i AND status = ?s',
            'A',
            0,
            'A'
        );
        foreach ((array) $ids as $id) {
            $center->add([
                'user_id' => (int) $id,
                'title' => $title,
                'message' => $text,
                'area' => 'A',
                'section' => 'administration',
                'tag' => 'kwtsms',
                'severity' => 'W',
                'language_code' => $lang,
                'pinned' => true,
            ]);
        }
    }

    /**
     * One e-mail to the store's site administrator address.
     */
    protected function mailAdmin(string $subject, string $text, string $lang): bool
    {
        return (bool) \Tygh\Tygh::$app['mailer']->send([
            'to' => 'company_site_administrator',
            'from' => 'default_company_site_administrator',
            'subject' => $subject,
            'body' => '<p>' . nl2br($text) . '</p>',
            'company_id' => 0,
        ], 'A', $lang);
    }

    // ------------------------------------------------------------------ internals

    private function language(): string
    {
        $lang = $this->config->adminAlertLang();
        if ($lang === '' && class_exists('\Tygh\Registry')) {
            $lang = (string) \Tygh\Registry::get('settings.Appearance.backend_default_language');
        }

        return $lang === '' ? 'en' : $lang;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function note(string $level, string $action, string $message, array $context): void
    {
        if ($this->log !== null) {
            $this->log->log($level, $action, $message, $context);
        }
    }

    private function failed(string $action, string $condition, \Throwable $e): void
    {
        $this->note('error', $action, get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':'
            . $e->getLine(), ['condition' => $condition]);
    }
}
