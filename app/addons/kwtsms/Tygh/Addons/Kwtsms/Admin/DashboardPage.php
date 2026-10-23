<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/DashboardPage.php
 * CREATED:   2026-10-21 03:12 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The Dashboard of the kwtsms admin controller (PRD 6.9): the setup checklist, the go-live checklist, the
 *            status figures (enabled, connected, test mode, pilot mode, Sender ID, balance, days left, last sync, cron,
 *            outbox), the warnings, the Problems panel, the counters, credits by event and receiver, the test-mode
 *            card and the last 10 messages; plus the page's GET fallbacks (drain up to 20 due outbox rows, sync when
 *            stale) and the add-on's own health notices (PRD 6.13) that only a page view can raise.
 * WHY:       PRD 6.9, 6.11 and 6.13. The rules are static and take plain facts (tests need no store); viewData()
 *            gathers the facts. A GET handler never writes EXCEPT here, by the PRD's explicit fallbacks: refresh()
 *            drains the outbox and syncs when stale (both are what the cron does every minute and every day, so a
 *            store without a cron still works), and raises the bell notices of a cron that has not run and of an
 *            outbox backlog (the cron cannot report its own absence). Everything else on the page is read-only.
 *            Nothing here calls /send/ itself: a drained outbox row goes through SmsService, with every gate and test
 *            flag it always has. The one /balance/ the page may trigger is the "Refresh balance" button (POST, once
 *            per click) and the stale sync.
 * USAGE:     $page = DashboardPage::fromContainer(Tygh::$app);
 *            $page->refresh();                       // GET kwtsms.dashboard, before viewData()
 *            $data = $page->viewData($has_privilege); // template data; $has_privilege(string $privilege): bool
 *            DashboardPage::setupSteps($facts), goLiveSteps(), warnings(), daysLeft(), cronState(), logUrl(): the
 *            pure rules.
 *            Filtered-log links use the Schema filter names: kwtsms.logs?tab=<messages|attempts|debug>&outcome=..
 *            &api_code=..&created_from=<unix>; the Logs page (Task 1.14) must accept them.
 * DEPENDS:   Settings\ConfigInterface, Gateway\{GatewayState,SyncService}, Sms\{SmsService,ApiErrors,PhoneNormalizer},
 *            Alerts\AdminAlerts, Log\DebugLog, Admin\{DashboardStats,OutcomeGroups,Navigation,RequestGate},
 *            Templates\BrandNames, Notifications\NotificationSettingsExtender; CS-Cart __(), Registry.
 */

namespace Tygh\Addons\Kwtsms\Admin;

use Tygh\Addons\Kwtsms\Alerts\AdminAlerts;
use Tygh\Addons\Kwtsms\Gateway\GatewayState;
use Tygh\Addons\Kwtsms\Gateway\SyncService;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Notifications\NotificationSettingsExtender;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\ApiErrors;
use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;
use Tygh\Addons\Kwtsms\Sms\SmsService;
use Tygh\Addons\Kwtsms\Templates\BrandNames;
use Tygh\Registry;

class DashboardPage
{
    /** PRD 6.11: the cron has not run for this long. */
    public const CRON_STALE_SECONDS = 7200;

    /** PRD 6.11: an outbox row waits this long before it is a backlog. */
    public const BACKLOG_SECONDS = 600;

    /** The web fallbacks: rows drained per page view, and the seconds the drain may take. */
    public const DRAIN_LIMIT = 20;
    public const DRAIN_SECONDS = 5.0;

    /** Notices that stay until fixed are raised again after this long (23 h, like the cron's daily health job). */
    public const REPEAT_SECONDS = 82800;

    public const LAST_MESSAGES = 10;

    /**
     * 2026-10-21 05:16 +03: when the drain has used more than this many seconds, the stale sync is left for a
     * later open of the page (page time budget, Task 1.11 fix round 1 item 8, task 596).
     */
    public const SYNC_SKIP_AFTER_SECONDS = 3.0;

    /**
     * 2026-10-21 06:21 +03: the timeout context of the sync the page runs on open: web, 3 s to connect and 8 s in all
     * for each call (admin is 5 and 20 s), Task 1.11 fix round 2 item 1, task 596.
     */
    // 2026-10-23 03:45 +03: Task A12 item 5 (R53), task 596. Still the web timeouts, now with the 3 s admin background
    // slot cap (the storefront web context caps the slot wait at 1 s).
    public const SYNC_CONTEXT = 'admin_background';

    /** Where a merchant asks kwtSMS for a country (PRD 6.9 request-coverage link) and orders a Sender ID. */
    public const URL_COVERAGE = 'https://www.kwtsms.com/support.html';
    public const URL_SENDER_ID = 'https://www.kwtsms.com/sender-id-help.html';
    public const URL_RECHARGE = 'https://www.kwtsms.com/';

    /** Crypt keys the installer or a tutorial leaves behind. */
    private const WEAK_KEYS = ['abcdef', 'abcdefgh', 'key', 'crypt_key', 'secret', 'changeme'];

    /** @var ConfigInterface */
    private $config;

    /** @var GatewayState */
    private $state;

    /** @var SyncService */
    private $sync;

    /** @var SmsService */
    private $sms;

    /** @var DashboardStats */
    private $stats;

    /** @var AdminAlerts|null */
    private $alerts;

    /** @var DebugLog */
    private $log;

    /** @var callable|null */
    private $clock;

    /** @var array<string,mixed>|null The health facts of this request (refresh() computes them once) */
    private $health;

    /** @var DashboardState|null The cron watch time and the "Mark as done" flags (null: nothing is kept) */
    private $marks;

    /** @var callable|null Returns microtime(true) (tests); null = microtime(true) */
    private $timer;

    /**
     * 2026-10-21 05:16 +03: $marks (cron watch, R36 step marks) and $timer (page time budget) added, Task 1.11 fix
     * round 1, task 596.
     */
    public function __construct(
        ConfigInterface $config,
        GatewayState $state,
        SyncService $sync,
        SmsService $sms,
        DashboardStats $stats,
        ?AdminAlerts $alerts,
        DebugLog $log,
        ?callable $clock = null,
        ?DashboardState $marks = null,
        ?callable $timer = null
    ) {
        $this->config = $config;
        $this->state = $state;
        $this->sync = $sync;
        $this->sms = $sms;
        $this->stats = $stats;
        $this->alerts = $alerts;
        $this->log = $log;
        $this->clock = $clock;
        $this->marks = $marks;
        $this->timer = $timer;
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
            new DashboardStats(),
            $app['kwtsms.alerts'],
            $app['kwtsms.logs.debug'],
            null,
            new DashboardState($app['kwtsms.storage'])
        );
    }

    // ================================================================== GET fallbacks and health notices

    /**
     * The PRD 6.11 fallbacks of opening the dashboard: drain up to 20 due outbox rows (what the cron does every
     * minute) and sync when the last sync is stale (what it does once a day), then raise the notices that need a
     * page view. Never throws; each step is logged and the page renders whatever happens.
     *
     * 2026-10-21 05:16 +03: a drain that took more than SYNC_SKIP_AFTER_SECONDS leaves the stale sync for a later
     * open (the page must not run past its time budget; the cron or the next open does it). Debug enter and exit
     * rows. Task 1.11 fix round 1 items 8 and 13, task 596.
     * 2026-10-21 06:21 +03: the sync that does run asks for the 'web' timeouts (3 s to connect, 8 s in all for each
     * call); the first version of this comment said so, but syncIfStale() took no context and the sync ran with the
     * admin set of 5 and 20 s. Task 1.11 fix round 2 item 1, task 596.
     *
     * @return array{claimed:int,due:int,synced:bool,sync_skipped:bool,raised:string[]}
     */
    public function refresh(): array
    {
        $started = $this->enter('dashboard.refresh', 'refresh()');
        $out = ['claimed' => 0, 'due' => 0, 'synced' => false, 'sync_skipped' => false, 'raised' => []];
        $drain_started = $this->microtime();
        try {
            $drain = $this->sms->drainDue(self::DRAIN_LIMIT, self::DRAIN_SECONDS);
            $out['claimed'] = (int) ($drain['claimed'] ?? 0);
            $out['due'] = (int) ($drain['due'] ?? 0);
        } catch (\Throwable $e) {
            $this->failed('dashboard.drain_failed', $e);
        }
        if ($this->microtime() - $drain_started > self::SYNC_SKIP_AFTER_SECONDS) {
            $out['sync_skipped'] = true;
            $this->log->log('warning', 'dashboard.sync_skipped', 'the drain used the page time budget', [
                'claimed' => $out['claimed'],
            ]);
        } else {
            try {
                // 2026-10-21 06:21 +03: 'web' timeouts, see the comment above (fix round 2 item 1, task 596).
                $out['synced'] = $this->sync->syncIfStale(SyncService::DAY_SECONDS, self::SYNC_CONTEXT);
            } catch (\Throwable $e) {
                $this->failed('dashboard.sync_failed', $e);
            }
        }
        try {
            $out['raised'] = $this->raiseHealthNotices();
        } catch (\Throwable $e) {
            $this->failed('dashboard.notices_failed', $e);
        }
        $this->log->log('info', 'dashboard.refresh', 'opened', $out);
        $this->leave('dashboard.refresh', $started, 'done', $out);

        return $out;
    }

    /**
     * PRD 6.13 conditions only a page view can detect: the cron has not run for 2 h, outbox rows older than 10 min
     * (notices only, no e-mail, "until fixed": raised again every 23 h), and test mode on while SMS is on (notice
     * only, raised again every 23 h; the daily cron health job raises the same notice with the same throttle).
     *
     * @return string[] The conditions raised now
     */
    public function raiseHealthNotices(): array
    {
        $started = $this->enter('dashboard.notices', 'raiseHealthNotices()');
        $raised = [];
        if ($this->alerts === null) {
            $this->leave('dashboard.notices', $started, 'no alert channel', ['raised' => 0]);

            return $raised;
        }
        $health = $this->health();
        $cron_down = in_array($health['cron_state'], ['stale', 'never'], true);
        if ($cron_down && $this->alerts->raise('cron_missing', [], false, self::REPEAT_SECONDS)) {
            $raised[] = 'cron_missing';
        }
        $count = ['[count]' => $health['backlog']];
        if ($health['backlog'] > 0 && $this->alerts->raise('outbox_backlog', $count, false, self::REPEAT_SECONDS)) {
            $raised[] = 'outbox_backlog';
        }
        if ($this->config->enabled() && $this->config->testMode()) {
            $held = $this->stats->heldTest($this->now() - 30 * 86400, $this->now());
            $params = ['[count]' => $held['messages'], '[credits]' => self::number($held['credits'])];
            if ($this->alerts->raise('test_mode', $params, false, self::REPEAT_SECONDS)) {
                $raised[] = 'test_mode';
            }
        }
        $this->leave('dashboard.notices', $started, 'checked', ['raised' => $raised]);

        return $raised;
    }

    /**
     * After the Settings form was saved: SMS on with test mode on, newly so (SMS were off, or test mode was off),
     * raises the "SMS test mode is on" bell notice now, whatever the 23 h repeat (PRD 6.13: "Enabled and test mode
     * on" with the held credits). Never throws.
     * 2026-10-21 05:16 +03: also (R36) a saved default country marks the "Default country" step done, and SMS
     * switched on starts the cron watch again, so a cron that never ran is dated from that moment. $saved = the keys
     * the form saved.
     * Task 1.11 fix round 1 items 1, 3 and 13, task 596.
     *
     * @param string[] $saved
     */
    public function afterSettingsSaved(bool $was_enabled, bool $was_test_mode, array $saved = []): bool
    {
        $started = $this->enter('dashboard.after_save', 'afterSettingsSaved()', ['saved' => count($saved)]);
        try {
            if ($this->marks !== null) {
                if (in_array('default_country_code', $saved, true)) {
                    $this->marks->mark('country');
                }
                if (!$was_enabled && $this->config->enabled()) {
                    $this->marks->startCronWatch(true);
                }
            }
        } catch (\Throwable $e) {
            $this->failed('dashboard.save_marks_failed', $e);
        }
        $raised = false;
        try {
            $newly = !($was_enabled && $was_test_mode);
            if ($this->alerts !== null && $this->config->enabled() && $this->config->testMode() && $newly) {
                $held = $this->stats->heldTest($this->now() - 30 * 86400, $this->now());
                $raised = $this->alerts->raise(
                    'test_mode',
                    ['[count]' => $held['messages'], '[credits]' => self::number($held['credits'])],
                    false,
                    0
                );
            }
        } catch (\Throwable $e) {
            $this->failed('dashboard.save_notice_failed', $e);
        }
        $this->leave('dashboard.after_save', $started, $raised ? 'raised' : 'nothing raised');

        return $raised;
    }

    /**
     * Ruling R36: marks an optional setup step (default country, brand name, SMS switches) as done. The controller
     * has already checked the method, the privilege and the security hash. Never throws.
     *
     * @return bool False for a step that can't be marked
     */
    public function markStep(string $step): bool
    {
        // 2026-10-21 06:21 +03: only an allow-listed step id is logged, never the raw POST value (Task 1.11 fix round 2
        // item 2, task 596).
        $id = in_array($step, DashboardState::MARKABLE, true) ? $step : 'unknown';
        $started = $this->enter('dashboard.mark_step', 'markStep()', ['step' => $id]);
        $ok = false;
        try {
            $ok = $this->marks !== null && $this->marks->mark($step);
            if ($ok) {
                $this->log->log('info', 'dashboard.step_marked', 'step marked as done', ['step' => $id]);
            }
        } catch (\Throwable $e) {
            $this->failed('dashboard.mark_step_failed', $e);
        }
        $this->leave('dashboard.mark_step', $started, $ok ? 'marked' : 'refused');

        return $ok;
    }

    /**
     * Cron state and outbox backlog, read once per request.
     *
     * @return array{cron_state:string,cron_last:?int,backlog:int,waiting:int,held:int,oldest:?int}
     */
    private function health(): array
    {
        if ($this->health === null) {
            $now = $this->now();
            $last = $this->state->lastCron();
            $box = $this->stats->outbox($now, self::BACKLOG_SECONDS);
            $enabled = $this->config->enabled();
            // 2026-10-21 05:16 +03: a cron that never ran is dated from the time the add-on first saw it missing
            // with SMS on (written once, never touched by a sync, login or send), NOT from the last sync: the
            // page's own fallback sync touches that and a dead cron would never be reported. Task 1.11 fix round 1
            // item 1, task 596.
            $watch = null;
            if ($enabled && ($last === null || $last <= 0) && $this->marks !== null) {
                $watch = $this->marks->cronWatchSince();
                if ($watch === null) {
                    $watch = $this->marks->startCronWatch();
                }
            }
            $this->health = [
                'cron_state' => self::cronState($last, $watch, $now, $enabled),
                'cron_last' => $last,
                'backlog' => $box['backlog'],
                'waiting' => $box['waiting'],
                'held' => $box['held'],
                'oldest' => $box['oldest'],
            ];
        }

        return $this->health;
    }

    // ================================================================== GET kwtsms.dashboard

    /**
     * Everything the template shows. Read-only. Links the administrator may not follow are dropped.
     *
     * @param callable $has_privilege (string $privilege): bool
     *
     * @return array<string,mixed>
     */
    public function viewData(callable $has_privilege): array
    {
        $started = $this->enter('dashboard.view', 'viewData()');
        $now = $this->now();
        $today = (int) strtotime('today', $now);
        $health = $this->health();
        $credentials = $this->config->credentialsState();
        $connected = $credentials === ConfigInterface::CREDENTIALS_OK;
        list($configured, $reason) = $this->state->isConfigured($this->config);
        $suspended = $this->state->suspendedCode();
        $balance = $this->state->balance();
        $senders = $this->state->senderIds();
        $sender = $this->config->senderId();
        $counters = $this->stats->counters($now, $today);
        $available = $balance === null ? null : (float) $balance['available'];
        $edited = $this->stats->editedLanguages();
        $pages = Navigation::PAGES;

        $facts = [
            'now' => $now,
            'enabled' => $this->config->enabled(),
            'test_mode' => $this->config->testMode(),
            'pilot_mode' => $this->config->pilotMode(),
            'connected' => $connected,
            'relogin' => $credentials === ConfigInterface::CREDENTIALS_RELOGIN,
            'configured' => $configured,
            'sender_id' => $sender,
            'sender_in_list' => $sender !== '' && in_array($sender, $senders, true),
            'sender_is_test' => $sender === SettingsPage::TEST_SENDER,
            'default_country_label' => $this->countryLabel($this->config->defaultCountryCode()),
            'brand' => $this->brand(),
            'brand_saved' => $this->stats->brandSaved(),
            'test_sms_done' => $this->stats->testSmsAccepted(),
            'switches_reviewed' => $this->switchesReviewed(),
            // 2026-10-23 04:23 +03: Task A13, task 596. Multi-Vendor only: the optional "Vendor phones approved" step.
            'is_multivendor' => $this->isMultiVendor(),
            'vendor_phones_approved' => $this->isMultiVendor() ? $this->approvedVendorPhones() : 0,
            'admin_phones' => count($this->config->adminPhones()),
            'edited_langs' => $edited,
            'live_accepted' => $this->stats->liveAccepted(),
            'suspended' => $suspended,
            'balance' => $available,
            'low_threshold' => $this->lowThreshold($now),
            'cap_tripped' => $this->dailyCapTripped($now),
            'cron_state' => $health['cron_state'],
            'backlog' => $health['backlog'],
            'sync_failures' => $connected ? $this->state->syncFailures() : 0,
            'weak_crypt_key' => self::isWeakCryptKey((string) Registry::get('config.crypt_key')),
            'marks' => $this->marks === null ? [] : array_keys($this->marks->marks()),
        ];

        $can = [
            'gateway' => $has_privilege('manage_kwtsms_gateway'),
            'logs' => $has_privilege('view_kwtsms_logs'),
            'manage' => $has_privilege('manage_kwtsms'),
        ];
        $setup = self::setupSteps($facts);
        $golive = self::goLiveSteps($facts);

        $month = $counters['live']['month']['credits'];
        $held = $this->stats->heldTest($now - 30 * 86400, $now);

        $data = [
            'now' => $now,
            'can' => $can,
            'enabled' => $facts['enabled'],
            'test_mode' => $facts['test_mode'],
            'pilot_mode' => $facts['pilot_mode'],
            'connected' => $connected,
            'connection' => $this->connection($connected, $facts['relogin'], $suspended, $configured, (string) $reason),
            'sender' => [
                'id' => $sender,
                'type' => $this->config->senderType(),
                'is_test_sender' => $facts['sender_is_test'],
            ],
            'balance' => $balance === null ? null : [
                'available' => self::number((float) $balance['available']),
                'purchased' => $balance['purchased'] === null ? '' : self::number((float) $balance['purchased']),
                'synced_at' => (int) $balance['synced_at'],
            ],
            'days_left' => self::daysLeft($available, (float) $month),
            'last_sync' => (int) $this->state->lastSync(),
            'cron' => ['state' => $health['cron_state'], 'last' => (int) $health['cron_last']],
            'outbox' => [
                'waiting' => $health['waiting'],
                'held' => $health['held'],
                'backlog' => $health['backlog'],
                'oldest_minutes' => $health['oldest'] === null ? 0 : (int) floor(max(0, $now - $health['oldest']) / 60),
            ],
            'warnings' => $this->warningRows(self::warnings($facts)),
            'setup' => [
                'complete' => self::setupComplete($setup, $facts),
                'steps' => $this->withLinks($setup, $has_privilege),
            ],
            'golive' => ['steps' => $this->withLinks($golive, $has_privilege)],
            'test_card' => [
                'show' => $facts['test_mode'] || $held['messages'] > 0,
                'messages' => $held['messages'],
                'credits' => self::number($held['credits']),
                'since' => $held['first_at'],
            ],
            'counters' => $this->counterRows($counters),
            'problems' => $this->problemData($now, $can['logs']),
            'credits_by_event' => $this->creditRows($now),
            'month_start' => (int) strtotime('first day of this month 00:00:00', $now),
            'last_month_start' => (int) strtotime('first day of last month 00:00:00', $now),
            'last_messages' => $this->lastMessageRows(),
            'logs_url' => $can['logs'] ? 'kwtsms.logs' : '',
            'urls' => [
                'recharge' => self::URL_RECHARGE,
                'coverage' => self::URL_COVERAGE,
                'sender_id' => self::URL_SENDER_ID,
                'queue' => 'https://www.kwtsms.com/',
            ],
            'has_templates_page' => in_array('templates', $pages, true),
        ];
        $this->leave('dashboard.view', $started, 'built', [
            'warnings' => count($data['warnings']),
            'setup_complete' => $data['setup']['complete'],
        ]);

        return $data;
    }

    // ================================================================== pure rules

    /**
     * PRD 6.9 setup checklist: Log in, Choose Sender ID, Default country, Brand name, Send a test SMS, Review SMS
     * switches, Turn off test mode, Turn on SMS. The first open step is "next", the others "todo".
     *
     * @param array<string,mixed> $f Facts (see DashboardPageTest::fresh())
     *
     * @return array<int,array{id:string,state:string,value:string,page:string,anchor:string}>
     */
    public static function setupSteps(array $f): array
    {
        $marks = isset($f['marks']) && is_array($f['marks']) ? $f['marks'] : [];
        $steps = [
            self::step('login', !empty($f['connected']), '', 'settings', 'kwtsms_account'),
            self::step(
                'sender',
                !empty($f['connected']) && !empty($f['sender_in_list']),
                (string) $f['sender_id'],
                'settings',
                'kwtsms_sender'
            ),
            // 2026-10-21 05:16 +03: R36. Default country, Brand name and Review SMS switches are the merchant's to
            // judge: they are done when the merchant marked them (or saved Settings, for the country), the brand and
            // the switches also when their data exist. The country no longer starts as done. Task 1.11 fix round 1
            // item 3, task 596.
            self::step(
                'country',
                in_array('country', $marks, true),
                (string) $f['default_country_label'],
                'settings',
                'kwtsms_default_country',
                true
            ),
            self::step(
                'brand',
                !empty($f['brand_saved']) || in_array('brand', $marks, true),
                (string) $f['brand'],
                'templates',
                'kwtsms_brand',
                true
            ),
            self::step('test_sms', !empty($f['test_sms_done']), '', 'settings', 'kwtsms_test_sms'),
            self::step(
                'switches',
                !empty($f['switches_reviewed']) || in_array('switches', $marks, true),
                '',
                'templates',
                '',
                true
            ),
            self::step('test_mode_off', empty($f['test_mode']), '', 'settings', 'kwtsms_test_mode'),
            self::step('enable', !empty($f['enabled']), '', 'settings', 'kwtsms_enabled'),
        ];
        // 2026-10-23 04:23 +03: Task A13, task 596. Multi-Vendor only, optional: done when a vendor phone is approved
        // (or the merchant marked it), placed after "Review the SMS switches".
        if (!empty($f['is_multivendor'])) {
            array_splice($steps, 6, 0, [self::step(
                'vendor_phones',
                (int) ($f['vendor_phones_approved'] ?? 0) > 0 || in_array('vendor_phones', $marks, true),
                '',
                'vendors',
                '',
                true
            )]);
        }

        return self::markNext($steps);
    }

    /**
     * PRD 6.9 go-live checklist: transactional Sender ID ordered, EN/AR templates reviewed, admin phones entered,
     * pilot run done, test mode off. State done / todo, and "progress" for a pilot run under way.
     *
     * @param array<string,mixed> $f
     *
     * @return array<int,array{id:string,state:string,value:string,page:string,anchor:string}>
     */
    public static function goLiveSteps(array $f): array
    {
        $ordered = !empty($f['connected']) && !empty($f['sender_in_list']) && empty($f['sender_is_test']);
        $langs = isset($f['edited_langs']) && is_array($f['edited_langs']) ? $f['edited_langs'] : [];
        $pilot = !empty($f['live_accepted']) ? 'done' : (!empty($f['pilot_mode']) ? 'progress' : 'todo');

        return [
            self::step('sender_ordered', $ordered, (string) $f['sender_id'], 'sender_id_help', ''),
            self::step('templates', in_array('en', $langs, true) && in_array('ar', $langs, true), '', 'templates', ''),
            self::step('admin_phones', (int) $f['admin_phones'] > 0, '', 'admin_alerts', ''),
            ['state' => $pilot] + self::step('pilot', false, '', 'settings', 'kwtsms_pilot_mode'),
            self::step('test_mode_off', empty($f['test_mode']), '', 'settings', 'kwtsms_test_mode'),
        ];
    }

    /**
     * @return array{id:string,state:string,value:string,page:string,anchor:string,optional:bool}
     */
    private static function step(
        string $id,
        bool $done,
        string $value,
        string $page,
        string $anchor,
        bool $optional = false
    ): array {
        return [
            'id' => $id,
            'state' => $done ? 'done' : 'todo',
            'value' => $value,
            'page' => $page,
            'anchor' => $anchor,
            'optional' => $optional,
        ];
    }

    /**
     * @param array<int,array<string,string>> $steps
     *
     * @return array<int,array<string,string>>
     */
    private static function markNext(array $steps): array
    {
        foreach ($steps as $i => $step) {
            if ($step['state'] !== 'done') {
                $steps[$i]['state'] = 'next';
                break;
            }
        }

        return $steps;
    }

    /**
     * 2026-10-21 05:16 +03: R36, the checklist must be completable. Complete when every step is done, OR when SMS
     * are on, test mode is off and kwtSMS accepted at least one live message (the store is evidently running).
     * Task 1.11 fix round 1 item 3, task 596.
     *
     * @param array<int,array<string,mixed>> $steps
     * @param array<string,mixed>            $f     Facts: enabled, test_mode, live_accepted
     */
    public static function setupComplete(array $steps, array $f = []): bool
    {
        if (!empty($f['enabled']) && empty($f['test_mode']) && !empty($f['live_accepted'])) {
            return true;
        }
        foreach ($steps as $step) {
            if ($step['state'] !== 'done') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whole days of balance left at the 30-day rate of live credits; null when there is no balance or no live use.
     */
    public static function daysLeft(?float $available, float $credits_30_days): ?int
    {
        if ($available === null || $credits_30_days <= 0.0) {
            return null;
        }

        return (int) floor(max(0.0, $available) / ($credits_30_days / 30));
    }

    /**
     * 'off' when SMS is off, 'ok' (the cron ran within 2 h), 'stale' (it ran, but over 2 h ago), 'never' (it never ran
     * and the account was set up over 2 h ago), 'new' (it never ran, but the account is recent or has no date: wait).
     * The reference time of a store that never ran the cron is the cron watch time (DashboardState): when the add-on
     * first saw the cron missing with SMS on, or when SMS were last switched on. It is NOT the last sync (the page's
     * own fallback sync touches that). 2026-10-21 05:16 +03, Task 1.11 fix round 1 item 1, task 596.
     */
    public static function cronState(?int $last_cron, ?int $reference, int $now, bool $enabled): string
    {
        if (!$enabled) {
            return 'off';
        }
        if ($last_cron !== null && $last_cron > 0) {
            return $now - $last_cron > self::CRON_STALE_SECONDS ? 'stale' : 'ok';
        }
        if ($reference !== null && $reference > 0 && $now - $reference > self::CRON_STALE_SECONDS) {
            return 'never';
        }

        return 'new';
    }

    /**
     * The warnings above the page, most urgent first. Each: id, level (error or warning), code (an API code or ''),
     * params (placeholders of kwtsms.dash.warn.<id>[.<variant>]), action (a POST mode a gateway administrator can
     * press, or ''), variant ('' or the suffix of the language variable: the cron "stale" or "never").
     *
     * @param array<string,mixed> $f
     *
     * @return array<int,array{id:string,level:string,code:string,params:array<string,string>,action:string,variant:string}>
     */
    public static function warnings(array $f): array
    {
        $out = [];
        $add = static function (
            string $id,
            string $level,
            string $code = '',
            array $params = [],
            string $action = '',
            string $variant = ''
        ) use (&$out): void {
            $out[] = [
                'id' => $id, 'level' => $level, 'code' => $code, 'params' => $params, 'action' => $action,
                'variant' => $variant,
            ];
        };
        $on = !empty($f['enabled']);
        $balance = $f['balance'] ?? null;

        if (!empty($f['suspended'])) {
            $add('suspended', 'error', (string) $f['suspended'], [], 'retry_gateway');
        }
        if (!empty($f['relogin'])) {
            $add('relogin', 'error');
        }
        if ($on && $balance !== null && $balance < 1.0) {
            $add('balance_zero', 'error');
        } elseif ($on && $balance !== null && $balance < (float) $f['low_threshold']) {
            $add('low_balance', 'warning', '', ['[threshold]' => (string) (int) $f['low_threshold']]);
        }
        if (!empty($f['cap_tripped'])) {
            $add('daily_cap', 'warning', '', [], 'resume_daily_cap');
        }
        if (in_array($f['cron_state'], ['stale', 'never'], true)) {
            $add('cron_missing', 'warning', '', [], '', (string) $f['cron_state']);
        }
        if ((int) $f['backlog'] > 0) {
            $add('outbox_backlog', 'warning', '', ['[count]' => (string) (int) $f['backlog']]);
        }
        if ((int) $f['sync_failures'] > 0) {
            $add('sync_failing', 'warning', '', [], 'sync');
        }
        if (!empty($f['weak_crypt_key'])) {
            $add('weak_crypt_key', 'warning');
        }

        return $out;
    }

    /**
     * A link to the Logs page filtered the way the Dashboard figure was counted. Filter names are those of
     * Log\Schema; a list becomes name[]=a&name[]=b. The Logs page itself arrives with Task 1.14.
     *
     * @param array<string,mixed> $filters
     */
    public static function logUrl(string $tab, array $filters): string
    {
        $query = ['tab=' . rawurlencode($tab)];
        foreach ($filters as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $query[] = rawurlencode((string) $name) . '%5B%5D=' . rawurlencode((string) $item);
                }
                continue;
            }
            $query[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }

        return 'kwtsms.logs?' . implode('&', $query);
    }

    /** PRD 4.4: a default or short crypt key makes the stored API password weak. */
    public static function isWeakCryptKey(string $key): bool
    {
        return strlen($key) < 16 || in_array(strtolower($key), self::WEAK_KEYS, true);
    }

    // ================================================================== view data pieces

    /**
     * The connection card: "Connected", or the failing condition named.
     *
     * @return array{state:string,text:string}
     */
    private function connection(
        bool $connected,
        bool $relogin,
        ?string $suspended,
        bool $configured,
        string $reason
    ): array {
        if ($suspended !== null) {
            return ['state' => 'paused', 'text' => ApiErrors::describe($suspended)];
        }
        if ($relogin) {
            return ['state' => 'error', 'text' => (string) __('kwtsms.reason.relogin_required')];
        }
        if (!$connected) {
            return ['state' => 'off', 'text' => (string) __('kwtsms.reason.no_credentials')];
        }
        if (!$configured) {
            $known = [
                'no_credentials', 'relogin_required', 'not_logged_in', 'no_sender_ids', 'sender_not_in_list',
                'sender_banned',
            ];
            $key = in_array($reason, $known, true) ? $reason : 'no_credentials';

            return ['state' => 'error', 'text' => (string) __('kwtsms.reason.' . $key)];
        }

        return ['state' => 'ok', 'text' => ''];
    }

    /**
     * Adds the placeholders that need the services: the API reason, escaped (the text goes through __() unescaped).
     *
     * @param array<int,array<string,mixed>> $warnings
     *
     * @return array<int,array<string,mixed>>
     */
    private function warningRows(array $warnings): array
    {
        $rows = [];
        foreach ($warnings as $w) {
            $params = $w['params'];
            if ($w['id'] === 'suspended') {
                $params['[reason]'] = htmlspecialchars(ApiErrors::describe((string) $w['code']), ENT_QUOTES, 'UTF-8');
            }
            // 2026-10-21 14:10 +03: low_balance and outbox_backlog count something, so their language variables
            // have a singular and a plural form ("1 credit" / "3 credits"; six forms in Arabic): core's __() picks
            // the form from a number at the FIRST position of the params and fills [n] with it. The named params
            // stay as they were. Cleanup 2 item 4, task 596.
            if ($w['id'] === 'low_balance' && isset($params['[threshold]'])) {
                $params = [0 => (int) $params['[threshold]']] + $params;
            } elseif ($w['id'] === 'outbox_backlog' && isset($params['[count]'])) {
                $params = [0 => (int) $params['[count]']] + $params;
            }
            $w['params'] = $params;
            $rows[] = $w;
        }

        return $rows;
    }

    /**
     * Steps with their link: the page URL when the page exists and the administrator may open it, or the external
     * address (the Sender ID help page); '' when there is nothing to open.
     *
     * @param array<int,array<string,string>> $steps
     *
     * @return array<int,array<string,mixed>>
     */
    private function withLinks(array $steps, callable $has_privilege): array
    {
        $out = [];
        foreach ($steps as $step) {
            $href = '';
            $external = false;
            $page = $step['page'];
            if ($page === 'sender_id_help') {
                $href = self::URL_SENDER_ID;
                $external = true;
            } elseif (
                in_array($page, Navigation::PAGES, true)
                && isset(RequestGate::MODES[$page])
                && $has_privilege(RequestGate::MODES[$page][1])
            ) {
                $href = 'kwtsms.' . $page;
            } elseif (
                $page === 'templates'
                && $step['id'] === 'switches'
                && $has_privilege('manage_notification_settings')
            ) {
                // The Templates page is not there yet: the same switches live under Administration > Notifications.
                $href = 'notification_settings.manage?receiver_type=C';
            }
            $step['href'] = $href;
            $step['external'] = $external;
            $out[] = $step;
        }

        return $out;
    }

    /**
     * Counters as table rows: a row per group, and the credits row.
     *
     * @param array<string,array<string,array<string,int|float>>> $c
     *
     * @return array<string,mixed>
     */
    private function counterRows(array $c): array
    {
        $rows = [];
        foreach (['accepted', 'failed', 'skipped', 'deferred'] as $group) {
            $row = ['group' => $group];
            foreach (['live', 'test'] as $mode) {
                foreach (['today', 'week', 'month'] as $window) {
                    $row[$mode . '_' . $window] = (int) $c[$mode][$window][$group];
                }
            }
            $rows[] = $row;
        }
        $credits = ['group' => 'credits'];
        foreach (['live', 'test'] as $mode) {
            foreach (['today', 'week', 'month'] as $window) {
                $credits[$mode . '_' . $window] = self::number((float) $c[$mode][$window]['credits']);
            }
        }
        $rows[] = $credits;

        return ['rows' => $rows];
    }

    /**
     * @return array<string,mixed>
     */
    private function problemData(int $now, bool $links): array
    {
        $since = $now - 7 * 86400;
        $p = $this->stats->problems($since, $now);
        $outcomes = [];
        $total = 0;
        foreach ($p['outcomes'] as $outcome => $count) {
            $total += $count;
            $outcomes[] = [
                'outcome' => $outcome,
                'count' => $count,
                'href' => $links ? self::logUrl('attempts', ['outcome' => $outcome, 'created_from' => $since]) : '',
            ];
        }
        $codes = [];
        foreach ($p['codes'] as $row) {
            $codes[] = [
                'code' => $row['code'],
                'text' => ApiErrors::describe($row['code']),
                'count' => $row['count'],
                'href' => $links
                    ? self::logUrl('messages', ['api_code' => $row['code'], 'created_from' => $since])
                    : '',
            ];
        }
        $options = PhoneNormalizer::countryOptions();
        $countries = [];
        foreach ($p['countries'] as $cc => $count) {
            $cc = (string) $cc;
            $countries[] = [
                'code' => $cc,
                'label' => $cc !== '' && isset($options[(int) $cc])
                    ? (string) $options[(int) $cc]
                    : ($cc === '' ? '' : '+' . $cc),
                'count' => $count,
            ];
        }

        return [
            'since' => $since,
            'total' => $total,
            'outcomes' => $outcomes,
            'codes' => $codes,
            'countries' => $countries,
            'countries_href' => $links
                ? self::logUrl('attempts', ['outcome' => OutcomeGroups::BY_COUNTRY, 'created_from' => $since])
                : '',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function creditRows(int $now): array
    {
        $this_start = (int) strtotime('first day of this month 00:00:00', $now);
        $last_start = (int) strtotime('first day of last month 00:00:00', $now);
        $rows = [];
        foreach ($this->stats->creditsByEvent($last_start, $this_start, $now) as $row) {
            $rows[] = [
                'event_id' => $row['event_id'],
                'event_label' => $this->eventLabel($row['event_id']),
                'receiver' => $row['receiver'],
                'receiver_label' => $this->receiverLabel($row['receiver']),
                'this_live' => self::number($row['this_live']),
                'this_test' => self::number($row['this_test']),
                'last_live' => self::number($row['last_live']),
                'last_test' => self::number($row['last_test']),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function lastMessageRows(): array
    {
        $rows = [];
        foreach ($this->stats->lastMessages(self::LAST_MESSAGES) as $row) {
            $row['credits'] = self::number((float) $row['credits']);
            $row['event_label'] = $this->eventLabel($row['event_id']);
            $row['receiver_label'] = $this->receiverLabel($row['receiver']);
            $row['status_label'] = $this->statusLabel($row['status']);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Plain-language name of an event id, '' when the id is not one of ours (the page then shows the id itself).
     * Plain text: the template escapes it.
     */
    private function eventLabel(string $event_id): string
    {
        if ($event_id === SettingsPage::TEST_SMS_EVENT) {
            return (string) __('kwtsms.dash.event.test_sms');
        }
        // 2026-10-23 02:10 +03: the manual SMS of the order page, Task A11, task 596.
        if ($event_id === OrderSmsSender::EVENT_ID) {
            return (string) __('kwtsms.dash.event.manual_order_sms');
        }
        if (strpos($event_id, 'kwtsms.order_placed') === 0) {
            return (string) __('kwtsms.dash.event.order_placed');
        }
        if (preg_match('/^order\.status_changed\.([a-z0-9])$/D', $event_id, $m) === 1) {
            $code = strtoupper($m[1]);
            $names = $this->orderStatuses();

            return (string) __('kwtsms.dash.event.status_changed', ['[status]' => $names[$code] ?? $code]);
        }

        return '';
    }

    private function receiverLabel(string $receiver): string
    {
        return in_array($receiver, ['C', 'A', 'V'], true)
            ? (string) __('kwtsms.dash.receiver.' . $receiver)
            : $receiver;
    }

    /** Short label of a message status; the long outcome reason, or the raw code, for one without a short label. */
    private function statusLabel(string $status): string
    {
        // 2026-10-21 06:21 +03: 'accepted' left this list (it returned above, the entry was unreachable) and so did its
        // kwtsms.dash.msg.accepted language variable, Task 1.11 fix round 2 item 4, task 596.
        $short = [
            'test_queued', 'partial_accept', 'failed', 'unknown', 'rejected', 'deferred', 'expired',
            'invalid', 'cancelled', 'blocked_balance', 'no_coverage',
        ];
        if ($status === 'accepted') {
            // 2026-10-21 05:16 +03: PRD 6.8, "Accepted by kwtSMS" (the template adds the tooltip). Task 1.11 fix
            // round 1 item 10, task 596.
            return (string) __('kwtsms.dash.group.accepted');
        }
        if (in_array($status, $short, true)) {
            return (string) __('kwtsms.dash.msg.' . $status);
        }

        return in_array($status, OutcomeGroups::all(), true) ? (string) __('kwtsms.outcome.' . $status) : $status;
    }

    /**
     * Order status code => name, from the core status list.
     *
     * @return array<string,string>
     */
    protected function orderStatuses(): array
    {
        try {
            return array_map('strval', $this->fetchOrderStatuses());
        } catch (\Throwable $e) {
            // 2026-10-21 05:16 +03: logged before the fallback, Task 1.11 fix round 1 item 2, task 596.
            $this->failed('dashboard.order_statuses_failed', $e);

            return [];
        }
    }

    /**
     * The core status list (a seam: tests replace it).
     *
     * @return array<string,string>
     */
    protected function fetchOrderStatuses(): array
    {
        return function_exists('fn_get_simple_statuses')
            ? (array) fn_get_simple_statuses(STATUSES_ORDER, true, true)
            : [];
    }

    // ================================================================== facts

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }

    private function microtime(): float
    {
        return $this->timer === null ? microtime(true) : (float) call_user_func($this->timer);
    }

    /**
     * Entry line of a public method at debug (sanitized context: counts and ids, never a value from the request).
     * 2026-10-21 05:16 +03: added, Task 1.11 fix round 1 item 13, task 596.
     *
     * @param array<string,mixed> $context
     */
    private function enter(string $action, string $label, array $context = []): float
    {
        $this->log->log('debug', $action . '.enter', $label, $context);

        return $this->microtime();
    }

    /**
     * Exit line of a public method at debug, with its duration in milliseconds.
     *
     * @param array<string,mixed> $context
     */
    private function leave(string $action, float $started, string $result, array $context = []): void
    {
        $this->log->log(
            'debug',
            $action . '.exit',
            $result,
            $context,
            (int) round(($this->microtime() - $started) * 1000)
        );
    }

    /** The balance level under which the page warns: the setting, else the cached daily "auto" level, else 100. */
    private function lowThreshold(int $now): int
    {
        $set = $this->config->lowBalanceThreshold();
        if ($set !== null) {
            return $set;
        }
        $auto = $this->state->lowBalanceAuto(date('Y-m-d', $now));

        return $auto === null ? 100 : $auto;
    }

    protected function dailyCapTripped(int $now): bool
    {
        try {
            $since = max((int) strtotime('today', $now), $this->state->dailyCapResumedAt());
            $seconds = max(1, $now - $since + 1);

            return $this->stats->outcomeSince('blocked_daily_cap', $now - $seconds) > 0;
        } catch (\Throwable $e) {
            // 2026-10-21 05:16 +03: logged before the fallback, Task 1.11 fix round 1 item 2, task 596.
            $this->failed('dashboard.daily_cap_check_failed', $e);

            return false;
        }
    }

    /** Whether this store is Multi-Vendor. 2026-10-23 04:23 +03: Task A13, task 596. */
    protected function isMultiVendor(): bool
    {
        return function_exists('fn_allowed_for') && fn_allowed_for('MULTIVENDOR');
    }

    /** Active vendors with an approved SMS phone. 2026-10-23 04:23 +03: Task A13, task 596. */
    protected function approvedVendorPhones(): int
    {
        try {
            return (int) db_get_field(
                'SELECT COUNT(*) FROM ?:kwtsms_vendor_phones p INNER JOIN ?:companies c'
                . ' ON c.company_id = p.company_id WHERE c.status = ?s AND p.approved_at > 0 AND p.phone <> ?s',
                'A',
                ''
            );
        } catch (\Throwable $e) {
            $this->failed('dashboard.vendor_phones_failed', $e);

            return 0;
        }
    }

    /** True when the administrator saved the Notifications page (or the Templates matrix) at least once. */
    protected function switchesReviewed(): bool
    {
        try {
            return NotificationSettingsExtender::storedRows() !== [];
        } catch (\Throwable $e) {
            // 2026-10-21 05:16 +03: logged before the fallback, Task 1.11 fix round 1 item 2, task 596.
            $this->failed('dashboard.switches_check_failed', $e);

            return false;
        }
    }

    /** The brand of the default storefront in the backend language ('' when none is named). */
    protected function brand(): string
    {
        try {
            $storefront_id = 0;
            $app = \Tygh\Tygh::$app;
            if (isset($app['storefront.repository'])) {
                $storefront = $app['storefront.repository']->findDefault();
                $storefront_id = $storefront ? (int) $storefront->storefront_id : 0;
            }

            return BrandNames::get($storefront_id, defined('CART_LANGUAGE') ? (string) CART_LANGUAGE : 'en');
        } catch (\Throwable $e) {
            // 2026-10-21 05:16 +03: logged before the fallback, Task 1.11 fix round 1 item 2, task 596.
            $this->failed('dashboard.brand_failed', $e);

            return '';
        }
    }

    private function countryLabel(string $code): string
    {
        $options = PhoneNormalizer::countryOptions();

        return ctype_digit($code) && isset($options[(int) $code]) ? (string) $options[(int) $code] : $code;
    }

    /** A credit amount without trailing zeros: 1990, 1990.5. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function failed(string $action, \Throwable $e): void
    {
        $this->log->log(
            'error',
            $action,
            get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()
        );
    }
}
