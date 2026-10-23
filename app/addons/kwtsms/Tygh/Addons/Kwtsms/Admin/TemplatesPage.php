<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/TemplatesPage.php
 * CREATED:   2026-10-21 03:16 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The Templates page of the kwtsms admin controller: the matrix (one row per order status and receiver, with
 *            the core SMS switch of the row), the language tabs, the text and badge of each row, and every POST
 *            action of the page: update_template (save), reset_template, load_template_defaults, toggle_event,
 *            preview_template, test_template (2026-10-21 05:16 +03: "Send a test of this template"),
 *            update_brand_names (2026-10-21 05:16 +03: the SMS brand-name editor above the matrix; Task 1.12 fix
 *            round 1 items 2 and 3, rulings R30 and R33).
 * WHY:       PRD 6.4, 7 and 10. The controller stays a thin gate and router; the rules live here:
 *            - a row's switch is the core notification switch (table notification_settings, transport kwtsms), written
 *              with core's own fn_set_notification_settings(), so Administration > Notifications shows the change and
 *              the other way round; writing it also needs core's manage_notification_settings (PRD 7);
 *            - a save goes through ensureTemplate() for a row without a template yet, refuses a customer text without
 *              {store_name}, needs "Save anyway" for an unknown placeholder (the exact names shown), tells the
 *              admin what cleaning removed and warns (never blocks) about an HTML-like span, touching
 *              placeholders, entity fragments next to a placeholder and promotional wording
 *              (Templates\TemplateChecks);
 *            - the badge says "Using English text" only when the text really is the default-language text: resolve()
 *              puts a same-language generic text before a default-language exact one (Task 1.8);
 *            - the preview and the length use MessageCleaner::clean(TemplateRenderer::render(...)), the form that is
 *              billed; it makes no API call and writes nothing; an order number needs core's view_orders;
 *            - every value placed into a notification is escaped with fn_html_escape().
 * USAGE:     $page = TemplatesPage::fromContainer(Tygh::$app);
 *            $data = $page->viewData($lang, $edit_key, $draft, $can_toggle);          // GET kwtsms.templates
 *            $result = $page->save($posted);   $page->reset($posted);   $page->loadDefaults($lang);
 *            $page->toggle($posted, $may_change_switches);   $page->preview($posted, $may_view_orders);   // POST modes
 *            $page->testTemplate($posted, $admin_id, $ip);   $page->saveBrandNames($posted_brand);
 *            $result['draft'] is what the controller keeps in the session for the redirect (a refused save shows the
 *            typed text again); $result['lang'] and $result['edit'] build the redirect.
 * DEPENDS:   Templates\{TemplateRepository,TemplateRenderer,TemplateChecks,SampleData,Defaults,BrandNames},
 *            Admin\SettingsPage
 *            (its send half: the 10-an-hour cap and SmsService::sendTest()),
 *            Notifications\OrderSmsData,
 *            Sms\MessageCleaner, Log\DebugLog, Settings\ConfigInterface; CS-Cart __(), fn_set_notification(),
 *            fn_html_escape(), fn_set_notification_settings(), fn_get_order_info(), Tygh\Languages\Languages,
 *            Tygh\Providers\EventDispatcherProvider (all through protected methods a test can replace).
 * CHANGED:   2026-10-21 08:37 +03: lines over 120 columns wrapped so phpcs is clean, no change of behaviour (cleanup
 *            batch, task 596).
 *            2026-10-22 16:13 +03: Task A3, task 596. A storefront selector: viewData() takes a storefront id (0 = All storefronts, the
 *            1.0.0 page), save/reset/preview/testTemplate read kwtsms_tpl[storefront_id] (an id that is not a storefront
 *            of the store is refused, reason unknown_storefront), a reset of a storefront text deletes only that
 *            storefront's description, and storefrontSwitch() writes the per-storefront OFF override (P2-1).
 */

namespace Tygh\Addons\Kwtsms\Admin;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Notifications\OrderSmsData;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\MessageCleaner;
use Tygh\Addons\Kwtsms\Storefronts\StorefrontRules;
use Tygh\Addons\Kwtsms\Templates\BrandNames;
use Tygh\Addons\Kwtsms\Templates\Defaults;
use Tygh\Addons\Kwtsms\Templates\SampleData;
use Tygh\Addons\Kwtsms\Templates\TemplateChecks;
use Tygh\Addons\Kwtsms\Templates\TemplateRenderer;
use Tygh\Addons\Kwtsms\Templates\TemplateRepository;

class TemplatesPage
{
    /** The notification transport id of the add-on (core's notification_settings.transport_id). */
    public const TRANSPORT = 'kwtsms';

    /** Badges of a row in the language of the tab. */
    public const BADGE_EDITED = 'edited';
    public const BADGE_DEFAULT = 'default';
    public const BADGE_GENERIC = 'generic';
    public const BADGE_DEFAULT_LANG = 'default_lang';
    public const BADGE_NONE = 'none';
    /**
     * The Multi-Vendor checkout row answered by the single "Order placed" text (2026-10-21 05:03 +03, Task 1.12 fix
     * round 1 item 10).
     */
    public const BADGE_PLACED = 'placed';
    /** 2026-10-22 16:13 +03: Task A3, task 596. A storefront row that has no text of its own and is sent the All storefronts text. */
    public const BADGE_ALL_STOREFRONTS = 'all_storefronts';

    /** Longest snippet of a text in the matrix, in characters. */
    private const SNIPPET_CHARS = 90;

    /** @var TemplateRepository */
    private $templates;

    /** @var DebugLog */
    private $log;

    /** @var OrderSmsData */
    private $orders;

    /** @var ConfigInterface */
    private $config;

    /** @var SettingsPage|null The Settings page, for the send half of a test SMS (2026-10-21 05:16 +03, item 2) */
    private $settings;

    /** @var StorefrontRules|null 2026-10-22 16:13 +03: the per-storefront OFF overrides, Task A3, task 596 */
    private $rules;

    /**
     * @var array<string,string> The store brand by language, read once per request
     *                           (2026-10-21 08:28 +03, cleanup item 3, task 596)
     */
    private $brand_cache = [];

    /** @var callable|null 2026-10-23 05:57 +03: Cleanup CA1-6 (A6 N2), task 596. (string $scenario): bool, true = the antibot scenario is on */
    private $antibot_gate;

    public function __construct(
        TemplateRepository $templates,
        DebugLog $log,
        OrderSmsData $orders,
        ConfigInterface $config,
        ?SettingsPage $settings = null,
        ?StorefrontRules $rules = null,
        ?callable $antibot_gate = null
    ) {
        $this->antibot_gate = $antibot_gate;
        $this->templates = $templates;
        $this->log = $log;
        $this->orders = $orders;
        $this->config = $config;
        $this->settings = $settings;
        $this->rules = $rules;
    }

    /**
     * @param \ArrayAccess<string,mixed> $app Tygh::$app
     */
    public static function fromContainer($app): self
    {
        return new self(
            $app['kwtsms.templates'],
            $app['kwtsms.logs.debug'],
            $app['kwtsms.order_sms_data'],
            $app['kwtsms.config'],
            SettingsPage::fromContainer($app),
            $app['kwtsms.storefront_rules'],
            // 2026-10-23 05:57 +03: Cleanup CA1-6 (A6 N2), task 596. The same antibot gate the order notifier uses.
            $app['kwtsms.antibot_gate']
        );
    }

    // ================================================================== GET kwtsms.templates

    /**
     * Everything the template shows.
     *
     * @param string                    $lang_param The language tab asked for (anything else: the default language)
     * @param string                    $edit_key   "event_id:receiver" of the row whose editor is open, or ''
     * @param array<string,mixed>|null  $draft      A refused save to show again: key, lang, body, unknown, reason
     * @param bool                      $can_toggle Whether the administrator may change the core switches
     * @param bool                      $can_test   Whether the administrator holds manage_kwtsms_gateway (test send)
     * @param array<string,mixed>|null  $test_result The panel of the test just sent (shown once, in its row's editor)
     * @param int                       $storefront_id 2026-10-22 16:13 +03: Task A3, task 596. The storefront whose texts and switches
     *                                  are shown; 0 (or an id that is not a storefront of a store with two or more) = All
     *
     * @return array<string,mixed>
     */
    public function viewData(
        string $lang_param,
        string $edit_key,
        ?array $draft,
        bool $can_toggle,
        bool $can_test = false,
        ?array $test_result = null,
        int $storefront_id = 0
    ): array {
        $started = microtime(true);
        $this->note('debug', 'view.enter', 'viewData()', [
            'lang' => $lang_param,
            'edit' => $edit_key,
            'draft' => $draft !== null,
            'storefront_id' => $storefront_id,
        ]);
        // 2026-10-22 16:13 +03: Task A3, task 596. The picker needs two or more storefronts; one storefront is the 1.0.0 page.
        $known = $this->storefronts();
        $show_storefronts = count($known) >= 2;
        $sf = $show_storefronts && isset($known[$storefront_id]) ? $storefront_id : 0;
        $picker = [];
        if ($show_storefronts) {
            foreach ($known as $id => $name) {
                $picker[] = ['id' => $id, 'name' => $name];
            }
        }
        $languages = $this->languages();
        $default_lang = $this->defaultLang();
        $lang = (string) key($languages);
        if (isset($languages[$default_lang])) {
            $lang = $default_lang;
        }
        if (isset($languages[$lang_param])) {
            $lang = $lang_param;
        }
        $switches = $this->switchStates();
        $words = $this->placeholderWords($lang, $sf);

        $rows = [];
        $editor = null;
        foreach ($this->templates->rowsForUi(null, $sf) as $source) {
            $row = $this->matrixRow($source, $lang, $switches, $sf);
            if ($row['key'] === $edit_key) {
                $editor = $this->editor($source, $row, $lang, $languages[$lang], $draft, $sf);
                $row['editing'] = true;
            }
            $rows[] = $row;
        }

        // 2026-10-21 05:03 +03: entry, exit and the info row (Task 1.12 fix round 1 item 9, task 596). Names only,
        // never a body.
        $this->note('info', 'view', 'Templates page opened', [
            'lang' => $lang,
            'rows' => count($rows),
            'edit' => $editor === null ? '' : $edit_key,
            'storefront_id' => $sf,
        ], $started);
        $this->note('debug', 'view.exit', 'viewData()', ['rows' => count($rows)], $started);

        return [
            'languages' => $languages,
            'lang' => $lang,
            'lang_name' => $languages[$lang]['name'],
            'direction' => $languages[$lang]['direction'],
            'default_lang' => $default_lang,
            'default_lang_name' => isset($languages[$default_lang]) ? $languages[$default_lang]['name'] : $default_lang,
            'rows' => $rows,
            'editor' => $editor,
            // 2026-10-22 16:13 +03: Task A3, task 596.
            'storefront_id' => $sf,
            'storefront_name' => $sf > 0 ? $known[$sf] : '',
            'show_storefronts' => $show_storefronts,
            'storefronts' => $picker,
            'can_toggle' => $can_toggle,
            'sms_enabled' => $this->config->enabled(),
            'test_mode' => $this->config->testMode(),
            'has_shipped' => $this->hasShippedTexts($lang),
            // 2026-10-21 05:16 +03: the brand-name editor and the test send (Task 1.12 fix round 1 items 2 and 3,
            // task 596).
            'brand' => $this->brandBlock($lang),
            'can_test' => $can_test && $this->settings !== null,
            'test_result' => is_array($test_result)
                && ($test_result['key'] ?? '') === $edit_key
                && ($test_result['lang'] ?? '') === $lang
                ? $test_result : null,
            'test_per_hour' => SettingsPage::TEST_SMS_PER_HOUR,
            'test_country' => '+' . preg_replace('/\D/', '', $this->config->defaultCountryCode()),
            'placeholders' => $words,
            'script_config' => [
                'placeholders' => Defaults::PLACEHOLDERS,
                'free_text' => TemplateRenderer::FREE_TEXT,
                'promo_en' => TemplateChecks::PROMO_WORDS_EN,
                'promo_ar' => TemplateChecks::PROMO_WORDS_AR,
                'tlds' => MessageCleaner::URL_TLDS,
            ],
            'max_pages' => MessageCleaner::MAX_PAGES,
            'max_chars' => TemplateRepository::MAX_BODY_CHARS,
        ];
    }

    /**
     * The antibot scenario to name in the row's notice: $scenario while it is off, '' when it is on or the row has none.
     * 2026-10-23 05:57 +03: added, Cleanup CA1-6 (A6 N2), task 596.
     */
    private function captchaNotice(string $scenario): string
    {
        if ($scenario === '' || $this->antibot_gate === null) {
            return $scenario;
        }
        try {
            return (bool) call_user_func($this->antibot_gate, $scenario) ? '' : $scenario;
        } catch (\Throwable $e) {
            return $scenario;
        }
    }

    /**
     * One row of the matrix.
     *
     * @param array<string,mixed>                 $source   A row of TemplateRepository::rowsForUi()
     * @param array<string,array<string,bool>>    $switches event => receiver => on
     * @param int                                 $sf       2026-10-22 16:13 +03: Task A3, task 596. The storefront shown (0 = All)
     *
     * @return array<string,mixed>
     */
    private function matrixRow(array $source, string $lang, array $switches, int $sf = 0): array
    {
        $event_id = (string) $source['event_id'];
        $receiver = (string) $source['receiver'];
        $kind = (string) $source['kind'];
        $key = self::rowKey($event_id, $receiver);

        $own = isset($source['bodies'][$lang]) ? (string) $source['bodies'][$lang]['body'] : '';
        $has_own = trim($own) !== '';
        $resolved = $this->templates->resolve($event_id, $receiver, $lang, $sf);
        $badge = self::badge(
            $kind,
            $has_own,
            (string) ($source['bodies'][$lang]['is_edited'] ?? 'N'),
            $resolved === null ? null : (string) $resolved['fallback']
        );
        // 2026-10-22 16:13 +03: Task A3, task 596. A storefront row sent the All storefronts text of this language.
        if (
            $sf > 0 && !$has_own && $resolved !== null && (int) $resolved['storefront_id'] === 0
            && (string) $resolved['fallback'] !== TemplateRepository::FALLBACK_DEFAULT_LANG
        ) {
            $badge = self::BADGE_ALL_STOREFRONTS;
        }
        $effective = $resolved === null ? '' : (string) $resolved['body'];

        $status = (string) $source['status'];
        $status_name = $status === '' ? '' : $this->statusNameIn($status, $lang, (string) $source['status_name']);
        // 2026-10-21 08:28 +03: the reference values carry the store's real brand (cleanup item 3, task 596).
        $sent = MessageCleaner::clean(TemplateRenderer::render($effective, $this->reference($lang, $status_name, $sf)));

        $switch = null;
        $sf_switch = null;
        $note = '';
        // 2026-10-22 17:13 +03: Task A4, task 596. A declared event has a switch of its own (V rows too, OFF until
        // stored).
        if ($kind === 'placed' || $kind === 'status' || $kind === 'declared') {
            if (isset($switches[$event_id][$receiver])) {
                $switch = ['on' => $switches[$event_id][$receiver]];
                // 2026-10-22 16:13 +03: Task A3, task 596. The storefront's own override under the master state shown above.
                if ($sf > 0) {
                    $sf_switch = ['off' => $this->rules !== null && $this->rules->isSwitchedOff($sf, $event_id, $receiver)];
                }
            }
        } elseif ($kind === 'placed_multi') {
            $note = 'follows_placed';
        } else {
            $note = 'generic';
        }

        return [
            'key' => $key,
            'dom_id' => self::domId($key),
            'event_id' => $event_id,
            'receiver' => $receiver,
            'kind' => $kind,
            'status' => $status,
            'status_name' => (string) $source['status_name'],
            'switch' => $switch,
            'sf_switch' => $sf_switch,
            // 2026-10-22 17:13 +03: Task A4, task 596. The heading of a declared event's rows: its group langvar,
            // else "Other events".
            'group_key' => $kind === 'declared' ? (string) ($source['group'] ?? '') : '',
            'group_label' => $kind === 'declared' ? $this->groupLabel((string) ($source['group'] ?? '')) : '',
            // 2026-10-22 20:28 +03: Task A6 fix round 1 (P2-R12), task 596. A public-form row shows the CAPTCHA notice.
            // 2026-10-23 05:57 +03: Cleanup CA1-6 (A6 N2), task 596. Only while that scenario is off (the notice says to
            // turn it on); a scenario that is on shows nothing. No gate, or a gate that throws: the notice shows.
            'public_form' => $kind === 'declared' ? $this->captchaNotice((string) ($source['public_form'] ?? '')) : '',
            'switch_note' => $note,
            'badge' => $badge,
            'has_text' => $effective !== '',
            'snippet' => $effective === '' ? '' : self::snippet($effective),
            'units' => MessageCleaner::units($sent),
            'pages' => MessageCleaner::pages($sent),
            'unicode' => MessageCleaner::isUnicode($sent),
            'editing' => false,
        ];
    }

    /**
     * 2026-10-22 17:13 +03: Task A4, task 596. The heading text of a declared group: the language variable when it
     * exists, else
     * the shared "Other events" heading (never a raw key on the page).
     */
    private function groupLabel(string $langvar): string
    {
        if ($langvar !== '') {
            $text = (string) __($langvar);
            if ($text !== '' && $text !== '_' . $langvar && $text !== '_' . strtolower($langvar)) {
                return $text;
            }
        }

        return (string) __('kwtsms.templates.group_events');
    }

    /**
     * The badge of a row in a language. A same-language fallback to the family event is the generic status text for a
     * status row, but for the Multi-Vendor checkout row the family is the single "Order placed" row, a different
     * row, so it gets its own badge (2026-10-21 05:03 +03, Task 1.12 fix round 1 item 10, task 596).
     *
     * @param string      $kind     placed | placed_multi | generic | status
     * @param string|null $fallback resolve()'s fallback (exact | family | default_lang), null = no text at all
     */
    public static function badge(string $kind, bool $has_own, string $is_edited, ?string $fallback): string
    {
        if ($fallback === null) {
            return self::BADGE_NONE;
        }
        if ($has_own) {
            return $is_edited === 'Y' ? self::BADGE_EDITED : self::BADGE_DEFAULT;
        }
        if ($fallback === TemplateRepository::FALLBACK_DEFAULT_LANG) {
            return self::BADGE_DEFAULT_LANG;
        }
        if ($kind === 'placed_multi' && $fallback === TemplateRepository::FALLBACK_FAMILY) {
            return self::BADGE_PLACED;
        }

        return self::BADGE_GENERIC;
    }

    /**
     * The editor of one row in one language.
     *
     * @param array<string,mixed>      $source
     * @param array<string,mixed>      $row
     * @param array<string,mixed>      $language name, direction
     * @param array<string,mixed>|null $draft
     * @param int                      $sf 2026-10-22 16:13 +03: Task A3, task 596. The storefront shown (0 = All)
     *
     * @return array<string,mixed>
     */
    private function editor(array $source, array $row, string $lang, array $language, ?array $draft, int $sf = 0): array
    {
        $own = isset($source['bodies'][$lang]) ? (string) $source['bodies'][$lang]['body'] : '';
        $resolved = $this->templates->resolve($row['event_id'], $row['receiver'], $lang, $sf);
        $has_own = trim($own) !== '';

        $unknown = [];
        $reason = '';
        if ($draft !== null && ($draft['key'] ?? '') === $row['key'] && ($draft['lang'] ?? '') === $lang) {
            $body = (string) ($draft['body'] ?? '');
            $unknown = array_values(array_map('strval', (array) ($draft['unknown'] ?? [])));
            $reason = (string) ($draft['reason'] ?? '');
            $origin = 'draft';
        } elseif ($has_own) {
            $body = $own;
            $origin = 'own';
        } else {
            // No text of its own: start from the text that is sent now (the generic or the default-language text), so
            // saving makes it this row's own text.
            $body = $resolved === null ? '' : (string) $resolved['body'];
            $origin = $resolved === null ? 'none' : (string) $row['badge'];
        }
        $stored = TemplateRepository::storedForm($body);
        $status = (string) $row['status'];
        $status_name = $status === '' ? '' : $this->statusNameIn($status, $lang, (string) $row['status_name']);

        return [
            'key' => $row['key'],
            'dom_id' => $row['dom_id'],
            'event_id' => $row['event_id'],
            'receiver' => $row['receiver'],
            'kind' => $row['kind'],
            'status' => $status,
            'status_name' => $row['status_name'],
            'badge' => $row['badge'],
            'origin' => $origin,
            'storefront_id' => $sf,
            'lang' => $lang,
            'lang_name' => $language['name'],
            'direction' => $language['direction'],
            'body' => $body,
            'unknown' => $unknown,
            'reason' => $reason,
            // 2026-10-21 04:00 +03: JSON, so a name that holds a comma can still be confirmed. Task 1.12, task 596.
            'confirm' => (string) json_encode($unknown),
            'advice' => $this->advice($stored, (string) $row['receiver'], (string) $row['event_id']),
            // 2026-10-21 08:28 +03: the editor sample shows the store's real brand, cleanup item 3, task 596.
            'reference' => $this->reference($lang, $status_name, $sf),
            // 2026-10-21 05:03 +03: the row's own status name is part of the worst case (a custom status), item 4,
            // task 596.
            'worst' => $this->worstValues($lang, $status_name),
            'allowed' => Defaults::allowedPlaceholders((string) $row['event_id'], (string) $row['receiver']),
        ];
    }

    /**
     * The advice the editor shows for a body when it opens: code => what was found, as one text. The codes are
     * TemplateChecks' plus "unknown" (placeholders the page does not know; saving them needs "Save anyway").
     *
     * @return array<string,string>
     */
    private function advice(string $stored, string $receiver, string $event_id): array
    {
        $out = [];
        $found = TemplateChecks::analyze($stored, $receiver);
        if (isset($found[TemplateChecks::STORE_NAME_MISSING])) {
            $out[TemplateChecks::STORE_NAME_MISSING] = '';
        }
        $unknown = TemplateRenderer::unknownPlaceholders($stored, Defaults::allowedPlaceholders($event_id, $receiver));
        if ($unknown !== []) {
            $out['unknown'] = implode(' ', $unknown);
        }
        $codes = [
            TemplateChecks::HTML_SPAN,
            TemplateChecks::ADJACENT,
            TemplateChecks::ENTITY_FRAGMENT,
            TemplateChecks::PROMOTIONAL,
        ];
        foreach ($codes as $code) {
            if (isset($found[$code])) {
                $out[$code] = implode(' ', $found[$code]);
            }
        }

        return $out;
    }

    // ================================================================== POST update_template

    /**
     * Save one text. Tells the administrator what happened (notifications) and returns what the controller needs for
     * the redirect.
     *
     * @param array<string,mixed> $post kwtsms_tpl: event_id, receiver, lang, body, confirm
     *
     * @return array{saved:bool,reason:string,lang:string,edit:string,draft:array<string,mixed>|null,storefront_id:int}
     */
    public function save(array $post): array
    {
        $started = microtime(true);
        $input = $this->readInput($post);
        $result = [
            'saved' => false,
            'reason' => '',
            'lang' => $input['lang'],
            'edit' => $input['key'],
            'draft' => null,
            // 2026-10-22 16:13 +03: Task A3, task 596. The storefront the text belongs to (0 = All storefronts).
            'storefront_id' => max(0, $input['storefront_id']),
        ];

        if (!$this->storefrontKnown($input['storefront_id'])) {
            $result['reason'] = 'unknown_storefront';
            $result['edit'] = '';
            $result['storefront_id'] = 0;
            $this->say('E', 'kwtsms.templates.not_saved_unknown_storefront');
            $why = 'unknown_storefront';
            $this->trace('info', 'save', 'Template not saved: unknown storefront', $input, $started, $why);

            return $result;
        }
        $sf = $input['storefront_id'];

        $row = $this->rowFor($input['event_id'], $input['receiver'], $sf);
        if ($row === null || !$this->languageInstalled($input['lang'])) {
            $result['reason'] = 'unknown_row';
            $result['edit'] = '';
            $this->say('E', 'kwtsms.templates.not_saved_unknown_row');
            // 2026-10-21 05:04 +03: every refusal leaves an info row, names only (Task 1.12 fix round 1 item 9,
            // task 596).
            $this->trace(
                'info',
                'save',
                'Template not saved: unknown row or language',
                $input,
                $started,
                'unknown_row'
            );

            return $result;
        }

        $stored = TemplateRepository::storedForm($input['body']);
        $warnings = TemplateChecks::analyze($stored, $input['receiver']);
        $label = $this->rowLabel($row);

        if (isset($warnings[TemplateChecks::STORE_NAME_MISSING])) {
            $result['reason'] = 'store_name';
            $result['draft'] = $this->draft($input, [], 'store_name');
            $this->say('E', 'kwtsms.templates.not_saved_store_name');
            $this->trace('warning', 'save', 'Template not saved: {store_name} missing', $input, $started, 'store_name');

            return $result;
        }

        // 2026-10-21 05:04 +03: the two refusals the repository would make (too long, unknown placeholders not
        // confirmed) are decided HERE, before ensureTemplate(), so a refused save does not even create the row's
        // empty template (Task 1.12 fix round 1 item 6, task 596). The repository still checks them (defence in
        // depth).
        if (mb_strlen($input['body'], 'UTF-8') > TemplateRepository::MAX_BODY_CHARS) {
            $result['reason'] = 'too_long';
            $result['draft'] = $this->draft($input, [], 'too_long');
            $this->say('E', 'kwtsms.templates.not_saved_too_long', [
                '[max]' => (string) TemplateRepository::MAX_BODY_CHARS,
            ]);
            $this->trace('info', 'save', 'Template not saved: too long', $input, $started, 'too_long');

            return $result;
        }
        $unknown = TemplateRenderer::unknownPlaceholders(
            $stored,
            Defaults::allowedPlaceholders($input['event_id'], $input['receiver'])
        );
        $confirmed = json_decode($input['confirm'], true);
        $confirmed = is_array($confirmed) ? array_map('strval', array_filter($confirmed, 'is_scalar')) : [];
        $accept_unknown = $unknown !== [] && array_diff($unknown, $confirmed) === [];
        if ($unknown !== [] && !$accept_unknown) {
            $result['reason'] = 'unknown_placeholders';
            $result['draft'] = $this->draft($input, $unknown, 'unknown_placeholders');
            $this->say('W', 'kwtsms.templates.not_saved_unknown', ['[found]' => implode(' ', $unknown)]);
            $this->trace(
                'info',
                'save',
                'Template not saved: unknown placeholders',
                $input,
                $started,
                'unknown_placeholders'
            );

            return $result;
        }

        $id = $this->templates->ensureTemplate($input['event_id'], $input['receiver'], $sf);
        $saved = $this->templates->save($id, $input['lang'], $input['body'], $accept_unknown);

        if (!$saved['saved']) {
            $result['reason'] = $saved['reason'];
            if ($saved['reason'] === 'unknown_placeholders') {
                $result['draft'] = $this->draft($input, $saved['unknown'], 'unknown_placeholders');
                $this->say('W', 'kwtsms.templates.not_saved_unknown', ['[found]' => implode(' ', $saved['unknown'])]);
            } elseif ($saved['reason'] === 'too_long') {
                $result['draft'] = $this->draft($input, [], 'too_long');
                $max = (string) TemplateRepository::MAX_BODY_CHARS;
                $this->say('E', 'kwtsms.templates.not_saved_too_long', ['[max]' => $max]);
            } else {
                $result['edit'] = '';
                $this->say('E', 'kwtsms.templates.not_saved_unknown_row');
            }
            $reason = (string) $saved['reason'];
            $this->trace('info', 'save', 'Template not saved: ' . $reason, $input, $started, $reason);

            return $result;
        }

        $result['saved'] = true;
        $result['edit'] = '';
        $language = $this->languageName($input['lang']);
        $this->say('N', 'kwtsms.templates.saved', ['[row]' => $label, '[language]' => $language]);
        if ($saved['body'] === '') {
            $this->say('W', 'kwtsms.templates.saved_empty');
        }
        if ($saved['removed'] !== []) {
            $this->say('W', 'kwtsms.templates.removed', ['[found]' => self::visible($saved['removed'])]);
        }
        foreach (TemplateChecks::analyze($saved['body'], $input['receiver']) as $code => $found) {
            if ($code === TemplateChecks::STORE_NAME_MISSING) {
                continue;
            }
            $this->say('W', 'kwtsms.templates.warn_' . $code, ['[found]' => implode(' ', $found)]);
        }
        $this->trace('info', 'save', 'Template saved from the page', $input, $started, '');

        return $result;
    }

    // ================================================================== POST reset_template / load_template_defaults

    /**
     * Put the shipped text back for one row and language.
     *
     * @param array<string,mixed> $post
     *
     * @return array{lang:string,edit:string,storefront_id:int}
     */
    public function reset(array $post): array
    {
        $started = microtime(true);
        $input = $this->readInput($post);
        $this->trace('debug', 'reset.enter', 'reset()', $input, $started, '');
        $result = ['lang' => $input['lang'], 'edit' => '', 'storefront_id' => max(0, $input['storefront_id'])];
        if (!$this->storefrontKnown($input['storefront_id'])) {
            $result['storefront_id'] = 0;
            $this->say('E', 'kwtsms.templates.not_saved_unknown_storefront');
            $why = 'unknown_storefront';
            $this->trace('info', 'reset', 'Template not reset: unknown storefront', $input, $started, $why);

            return $result;
        }
        $sf = $input['storefront_id'];
        $row = $this->rowFor($input['event_id'], $input['receiver'], $sf);
        if ($row === null || !$this->languageInstalled($input['lang'])) {
            $this->say('E', 'kwtsms.templates.not_saved_unknown_row');
            $this->trace(
                'info',
                'reset',
                'Template not reset: unknown row or language',
                $input,
                $started,
                'unknown_row'
            );

            return $result;
        }

        $params = ['[row]' => $this->rowLabel($row), '[language]' => $this->languageName($input['lang'])];
        if ($sf > 0) {
            // 2026-10-22 16:13 +03: Task A3, task 596. A storefront text has no shipped text to go back to: deleting
            // its description for this language lets the All storefronts text answer again. Only that description goes.
            $deleted = $row['template_id'] > 0
                && $this->templates->deleteDescription((int) $row['template_id'], $input['lang']);
            $this->say('N', 'kwtsms.templates.reset_storefront', $params);
            $this->trace(
                'info',
                'reset',
                $deleted ? 'Storefront text removed' : 'Storefront text reset: there was none',
                $input,
                $started,
                ''
            );
            $this->trace('debug', 'reset.exit', 'reset()', $input, $started, '');

            return $result;
        }
        $id = $this->templates->ensureTemplate($input['event_id'], $input['receiver']);
        $body = $this->templates->resetToDefault($id, $input['lang']);
        if ($body === null) {
            $this->say('E', 'kwtsms.templates.not_saved_unknown_row');
            $this->trace('info', 'reset', 'Template not reset', $input, $started, 'not_found');
        } elseif ($body === '') {
            $this->say('N', 'kwtsms.templates.reset_cleared', $params);
            $this->trace(
                'info',
                'reset',
                'Template cleared: no shipped text for this row and language',
                $input,
                $started,
                'cleared'
            );
        } else {
            $this->say('N', 'kwtsms.templates.reset_done', $params);
            $this->trace('info', 'reset', 'Template reset to the shipped text', $input, $started, '');
        }
        $this->trace('debug', 'reset.exit', 'reset()', $input, $started, '');

        return $result;
    }

    /**
     * "Load default texts for <language>": fills the rows that are missing or not edited.
     *
     * @return array{lang:string}
     */
    public function loadDefaults(string $lang): array
    {
        $started = microtime(true);
        $this->note('debug', 'load_defaults.enter', 'loadDefaults()', ['lang' => $lang]);
        if (!$this->languageInstalled($lang)) {
            $this->say('E', 'kwtsms.templates.not_saved_unknown_row');
            $this->note(
                'info',
                'load_defaults',
                'Defaults not loaded: language not installed',
                ['lang' => $lang],
                $started
            );

            return ['lang' => $this->defaultLang()];
        }
        $written = $this->templates->loadDefaultsForLanguage($lang);
        $params = ['[count]' => (string) $written, '[language]' => $this->languageName($lang)];
        if ($written > 0) {
            $this->say('N', 'kwtsms.templates.loaded', $params);
        } else {
            $this->say('W', 'kwtsms.templates.loaded_none', $params);
        }
        $loaded = ['lang' => $lang, 'written' => $written];
        $this->note('info', 'load_defaults', 'Default texts loaded', $loaded, $started);
        $this->note('debug', 'load_defaults.exit', 'loadDefaults()', ['written' => $written], $started);

        return ['lang' => $lang];
    }

    // ================================================================== POST test_template

    /**
     * "Send a test of this template": the typed text, in its stored form, rendered with the sample data and sent to the
     * number typed through SettingsPage::sendTestText() (SmsService::sendTest(), ruling R30; the one 10-an-hour cap per
     * administrator; the test flag follows the store's test mode). Nothing is written to the template tables. The
     * editor comes back open with the typed text; the panel of the result is shown once.
     * 2026-10-21 05:16 +03: added, Task 1.12 fix round 1 item 2, task 596.
     *
     * @param array<string,mixed> $post event_id, receiver, lang, body, phone, intl (Y)
     *
     * @return array{sent:bool,result:array<string,mixed>|null,lang:string,edit:string,draft:array<string,mixed>|null,
     *               storefront_id:int}
     */
    public function testTemplate(array $post, int $admin_id, string $ip): array
    {
        $started = microtime(true);
        $input = $this->readInput($post);
        $phone = isset($post['phone']) && is_string($post['phone']) ? $post['phone'] : '';
        $intl = isset($post['intl']) && $post['intl'] === 'Y';
        $result = [
            'sent' => false,
            'result' => null,
            'lang' => $input['lang'],
            'edit' => $input['key'],
            'draft' => null,
            'storefront_id' => max(0, $input['storefront_id']),
        ];
        $this->trace('debug', 'test.enter', 'testTemplate()', $input, $started, '');

        if (!$this->storefrontKnown($input['storefront_id'])) {
            // 2026-10-22 16:13 +03: Task A3, task 596.
            $result['edit'] = '';
            $result['storefront_id'] = 0;
            $this->say('E', 'kwtsms.templates.not_saved_unknown_storefront');
            $this->trace('info', 'test', 'Test not sent: unknown storefront', $input, $started, 'unknown_storefront');
            $this->trace('debug', 'test.exit', 'testTemplate()', $input, $started, 'unknown_storefront');

            return $result;
        }
        $row = $this->rowFor($input['event_id'], $input['receiver'], $input['storefront_id']);
        if ($row === null || !$this->languageInstalled($input['lang'])) {
            $result['edit'] = '';
            $this->say('E', 'kwtsms.templates.not_saved_unknown_row');
            $this->trace('info', 'test', 'Test not sent: unknown row or language', $input, $started, 'unknown_row');
            // 2026-10-21 08:28 +03: a debug exit row on every way out, cleanup item 4, task 596.
            $this->trace('debug', 'test.exit', 'testTemplate()', $input, $started, 'unknown_row');

            return $result;
        }
        $result['draft'] = $this->draft($input, [], 'test');

        $status = (string) $row['status'];
        // 2026-10-21 08:28 +03: the same values as the preview (the store's real brand), so the test SMS is the
        // preview text, cleanup item 3, task 596.
        $values = $this->reference(
            $input['lang'],
            $status === '' ? '' : $this->statusNameIn($status, $input['lang'], (string) $row['status_name']),
            $input['storefront_id']
        );
        $text = TemplateRenderer::render(TemplateRepository::storedForm($input['body']), $values);
        if (MessageCleaner::clean($text) === '' || $this->settings === null) {
            $why = $this->settings === null ? 'unavailable' : 'empty';
            $this->say(
                'E',
                $this->settings === null ? 'kwtsms.templates.test_unavailable' : 'kwtsms.templates.test_empty'
            );
            $this->trace('info', 'test', 'Test not sent: nothing to send', $input, $started, $why);
            // 2026-10-21 08:28 +03: debug exit, cleanup item 4, task 596.
            $this->trace('debug', 'test.exit', 'testTemplate()', $input, $started, $why);

            return $result;
        }

        $panel = $this->settings->sendTestText($phone, $intl, $text, $admin_id, $ip, $input['lang']);
        if ($panel === null) {
            // The Settings page has said why (no number, the cap, the count failed).
            $this->trace('info', 'test', 'Test not sent', $input, $started, 'not_sent');
            // 2026-10-21 08:28 +03: debug exit, cleanup item 4, task 596.
            $this->trace('debug', 'test.exit', 'testTemplate()', $input, $started, 'not_sent');

            return $result;
        }
        $result['sent'] = true;
        $result['result'] = $panel + ['key' => $input['key'], 'lang' => $input['lang']];
        $this->log->log('info', 'templates.page.test', 'Template test sent', [
            'event_id' => $input['event_id'],
            'receiver' => $input['receiver'],
            'lang' => $input['lang'],
            'outcome' => (string) ($panel['outcome'] ?? ''),
            'status' => (string) ($panel['status'] ?? ''),
        ], (int) round((microtime(true) - $started) * 1000));
        // 2026-10-21 08:28 +03: debug exit, cleanup item 4, task 596.
        $this->trace('debug', 'test.exit', 'testTemplate()', $input, $started, '');

        return $result;
    }

    // ================================================================== POST update_brand_names

    /**
     * Save the SMS brand name of each storefront for one language. The storefront ids come from the allow-list (the
     * storefronts of this store), the language must be installed; each value goes through BrandNames::store() (cleaned
     * for storage, 20 characters, an empty value clears the language). A warning, never a block, when the English name
     * holds characters outside plain text (every English SMS would then be Unicode: 70 characters a page).
     * 2026-10-21 05:16 +03: added, Task 1.12 fix round 1 item 3, ruling R33, task 596.
     *
     * @param array<string,mixed> $post kwtsms_brand: lang, names (storefront id => text)
     *
     * @return array{lang:string,saved:int}
     */
    public function saveBrandNames(array $post): array
    {
        $started = microtime(true);
        $lang = isset($post['lang']) && is_string($post['lang']) && preg_match('/^[a-z]{2}$/', $post['lang']) === 1
            ? $post['lang']
            : '';
        $result = ['lang' => $lang !== '' ? $lang : $this->defaultLang(), 'saved' => 0];
        $this->note('debug', 'brand.enter', 'saveBrandNames()', ['lang' => $lang]);
        if (!$this->languageInstalled($lang)) {
            $this->say('E', 'kwtsms.templates.not_saved_unknown_row');
            $this->note(
                'info',
                'brand',
                'Brand names not saved: language not installed',
                ['lang' => $lang, 'saved' => 0],
                $started
            );
            // 2026-10-21 08:28 +03: a debug exit row on every way out, cleanup item 4, task 596.
            $this->note('debug', 'brand.exit', 'saveBrandNames()', ['lang' => $lang, 'saved' => 0], $started);

            return $result;
        }

        $names = isset($post['names']) && is_array($post['names']) ? $post['names'] : [];
        $allowed = $this->storefronts();
        $done = [];
        $refused = 0;
        $cut = false;
        $unicode = false;
        foreach ($names as $key => $value) {
            $id = ctype_digit((string) $key) ? (int) $key : 0;
            if ($id < 1 || !isset($allowed[$id]) || !is_string($value)) {
                $refused++;
                continue;
            }
            $before = BrandNames::typed($id, $lang);
            $stored = BrandNames::store($id, $lang, $value);
            $done[] = ['storefront_id' => $id, 'length' => mb_strlen($stored, 'UTF-8'), 'cleared' => $stored === ''];
            if ($stored !== $before) {
                $result['saved']++;
            }
            $cut = $cut || BrandNames::wasCut($value);
            $unicode = $unicode || ($lang === 'en' && MessageCleaner::isUnicode($stored));
        }

        if ($refused > 0) {
            $this->say('E', 'kwtsms.templates.brand_unknown_storefront');
        }
        if ($result['saved'] > 0) {
            $this->say('N', 'kwtsms.templates.brand_saved', ['[language]' => $this->languageName($lang)]);
        } elseif ($refused === 0) {
            $this->say('N', 'kwtsms.templates.brand_unchanged');
        }
        if ($cut) {
            $this->say('W', 'kwtsms.templates.brand_cut', ['[max]' => (string) BrandNames::MAX_LENGTH]);
        }
        if ($unicode) {
            $this->say('W', 'kwtsms.templates.brand_unicode');
        }
        $this->note('info', 'brand', 'Brand names saved from the page', [
            'lang' => $lang,
            'storefronts' => $done,
            'saved' => $result['saved'],
            'refused' => $refused,
        ], $started);
        // 2026-10-21 08:28 +03: the stored brand changed: forget the brands read for the sample values (a later call on
        // this object shows the new name), and a debug exit, cleanup items 3 and 4, task 596.
        $this->brand_cache = [];
        $exit = ['lang' => $lang, 'saved' => $result['saved']];
        $this->note('debug', 'brand.exit', 'saveBrandNames()', $exit, $started);

        return $result;
    }

    /**
     * The brand-name block for a language: one entry per storefront of the store (in Multi-Vendor the marketplace's own
     * storefronts, never a vendor), what was typed, and the fallback the input shows as its placeholder.
     *
     * @return array<string,mixed>
     */
    private function brandBlock(string $lang): array
    {
        $entries = [];
        foreach ($this->storefronts() as $id => $name) {
            $value = BrandNames::typed($id, $lang);
            $entries[] = [
                'id' => $id,
                'name' => $name,
                'value' => $value,
                'fallback' => BrandNames::fallback($id),
                'length' => mb_strlen($value, 'UTF-8'),
                'unicode' => $lang === 'en' && MessageCleaner::isUnicode($value),
            ];
        }

        return ['storefronts' => $entries, 'max' => BrandNames::MAX_LENGTH, 'warn_unicode' => $lang === 'en'];
    }

    // ================================================================== POST toggle_event

    /**
     * Turn the SMS switch of one row on or off. It is core's own switch (Administration > Notifications).
     *
     * @param array<string,mixed> $post event_id, receiver, enable (Y|N), lang
     *
     * @return array{ok:bool,lang:string,edit:string}
     */
    public function toggle(array $post, bool $may_change): array
    {
        $input = $this->readInput($post);
        $enable = isset($post['enable']) && is_string($post['enable']) ? $post['enable'] : '';
        $result = ['ok' => false, 'lang' => $input['lang'], 'edit' => ''];

        if (!$may_change) {
            $this->say('E', 'kwtsms.templates.switch_denied');
            $this->log->log('warning', 'templates.toggle', 'Switch refused: no manage_notification_settings', [
                'event_id' => $input['event_id'],
                'receiver' => $input['receiver'],
            ]);

            return $result;
        }
        $switches = $this->switchStates();
        $row = $this->rowFor($input['event_id'], $input['receiver']);
        if (
            ($enable !== 'Y' && $enable !== 'N')
            || $row === null
            // 2026-10-22 17:13 +03: declared rows have a switch too, Task A4, task 596
            || !in_array($row['kind'], ['placed', 'status', 'declared'], true)
            || !isset($switches[$input['event_id']][$input['receiver']])
        ) {
            $this->say('E', 'kwtsms.templates.switch_unknown');

            return $result;
        }

        $this->setSwitch($input['event_id'], $input['receiver'], $enable === 'Y');
        $this->log->log('info', 'templates.toggle', 'SMS switch changed from the Templates page', [
            'event_id' => $input['event_id'],
            'receiver' => $input['receiver'],
            'enabled' => $enable === 'Y',
        ]);
        $this->say('N', $enable === 'Y' ? 'kwtsms.templates.switch_on' : 'kwtsms.templates.switch_off', [
            '[row]' => $this->rowLabel($row),
        ]);
        $result['ok'] = true;

        return $result;
    }

    // ================================================================== POST update_storefront_switch

    /**
     * 2026-10-22 16:13 +03: Task A3, task 596 (P2-1). The storefront's own switch of one row: state "off" turns the
     * event and receiver OFF for that storefront only (a row in kwtsms_storefront_switches), "inherit" removes the
     * row so the storefront follows the master (core's switch, which this never touches). Only rows that have a master switch (the placed
     * row and the status rows), only a real storefront of a store with two or more, and only with core's
     * manage_notification_settings, like the master switch.
     *
     * @param array<string,mixed> $post event_id, receiver, lang, storefront_id, state (off|inherit)
     *
     * @return array{ok:bool,lang:string,storefront_id:int}
     */
    public function storefrontSwitch(array $post, bool $may_change): array
    {
        $input = $this->readInput($post);
        $state = isset($post['state']) && is_string($post['state']) ? $post['state'] : '';
        $sf = $input['storefront_id'];
        $result = ['ok' => false, 'lang' => $input['lang'], 'storefront_id' => max(0, $sf)];

        if (!$may_change) {
            $this->say('E', 'kwtsms.templates.switch_denied');
            $this->log->log('warning', 'templates.sf_switch', 'Switch refused: no manage_notification_settings', [
                'event_id' => $input['event_id'],
                'receiver' => $input['receiver'],
                'storefront_id' => max(0, $sf),
            ]);

            return $result;
        }
        if ($sf < 1 || !$this->storefrontKnown($sf)) {
            $result['storefront_id'] = 0;
            $this->say('E', 'kwtsms.templates.not_saved_unknown_storefront');

            return $result;
        }
        $switches = $this->switchStates();
        $row = $this->rowFor($input['event_id'], $input['receiver'], $sf);
        if (
            ($state !== 'off' && $state !== 'inherit')
            || $row === null
            // 2026-10-22 17:13 +03: declared rows have a switch too, Task A4, task 596
            || !in_array($row['kind'], ['placed', 'status', 'declared'], true)
            || !isset($switches[$input['event_id']][$input['receiver']])
            || $this->rules === null
        ) {
            $this->say('E', 'kwtsms.templates.switch_unknown');

            return $result;
        }

        $this->rules->setSwitch($sf, $input['event_id'], $input['receiver'], $state === 'inherit');
        $this->log->log('info', 'templates.sf_switch', 'Storefront SMS switch changed from the Templates page', [
            'event_id' => $input['event_id'],
            'receiver' => $input['receiver'],
            'storefront_id' => $sf,
            'off' => $state === 'off',
        ]);
        $known = $this->storefronts();
        $this->say('N', $state === 'off' ? 'kwtsms.templates.sf_switch_off' : 'kwtsms.templates.sf_switch_inherit', [
            '[row]' => $this->rowLabel($row),
            '[storefront]' => $known[$sf] ?? (string) $sf,
        ]);
        $result['ok'] = true;

        return $result;
    }

    // ================================================================== POST preview_template

    /**
     * The text as it would be sent, for sample data or a real order. Sends nothing, writes nothing, calls no API.
     * The answer is plain data for the script, which shows it with textContent.
     *
     * @param array<string,mixed> $post event_id, receiver, lang, body, source (sample|order), order_id
     *
     * @return array<string,mixed>
     */
    public function preview(array $post, bool $may_view_orders): array
    {
        $started = microtime(true);
        $input = $this->readInput($post);
        $source = isset($post['source']) && $post['source'] === 'order' ? 'order' : 'sample';
        // 2026-10-22 16:13 +03: Task A3, task 596. A storefront the store does not have is refused like an unknown row.
        if (!$this->storefrontKnown($input['storefront_id'])) {
            return ['ok' => false, 'error' => $this->plain('kwtsms.templates.not_saved_unknown_storefront')];
        }
        $row = $this->rowFor($input['event_id'], $input['receiver'], $input['storefront_id']);
        if ($row === null || !$this->languageInstalled($input['lang'])) {
            return ['ok' => false, 'error' => $this->plain('kwtsms.templates.not_saved_unknown_row')];
        }

        $status = (string) $row['status'];
        if ($source === 'order') {
            if (!$may_view_orders) {
                return ['ok' => false, 'error' => $this->plain('kwtsms.templates.preview_no_orders')];
            }
            $typed = isset($post['order_id']) && is_string($post['order_id']) ? trim($post['order_id']) : '';
            // 2026-10-21 04:00 +03: digits only; "12abc" is not order 12. Task 1.12, task 596.
            $order_id = preg_match('/^[0-9]{1,12}$/', $typed) === 1 ? (int) $typed : 0;
            $values = $order_id > 0 ? $this->orderValues($order_id, $input['lang'], $row) : null;
            if ($values === null) {
                $shown = (string) max(0, $order_id);

                $error = $this->plain('kwtsms.templates.preview_no_order', ['[id]' => $shown]);

                return ['ok' => false, 'error' => $error];
            }
        } else {
            $order_id = 0;
            // 2026-10-21 08:28 +03: the store's real brand, shared with the test SMS (cleanup item 3, task 596).
            $values = $this->reference(
                $input['lang'],
                $status === '' ? '' : $this->statusNameIn($status, $input['lang'], (string) $row['status_name']),
                $input['storefront_id']
            );
        }

        $stored = TemplateRepository::storedForm($input['body']);
        $text = MessageCleaner::clean(TemplateRenderer::render($stored, $values));
        $answer = [
            'ok' => true,
            'source' => $source,
            'order_id' => $order_id,
            'lang' => $input['lang'],
            'text' => $text,
            'empty' => $text === '',
            'units' => MessageCleaner::units($text),
            'pages' => MessageCleaner::pages($text),
            'unicode' => MessageCleaner::isUnicode($text),
            'over' => MessageCleaner::pages($text) > MessageCleaner::MAX_PAGES,
            'html_span' => MessageCleaner::hasHtmlLikeSpan($text),
            'leftover' => TemplateRenderer::hasLeftover($text),
            'unknown' => TemplateRenderer::unknownPlaceholders(
                $stored,
                Defaults::allowedPlaceholders($input['event_id'], $input['receiver'])
            ),
            'removed' => TemplateRepository::removedCharacters($input['body'], $stored),
        ];
        $this->log->log('debug', 'templates.preview', 'Template previewed', [
            'event_id' => $input['event_id'],
            'receiver' => $input['receiver'],
            'lang' => $input['lang'],
            'source' => $source,
            'pages' => $answer['pages'],
        ], (int) round((microtime(true) - $started) * 1000));

        return $answer;
    }

    /**
     * The placeholder values of a real order, or null when there is no such order.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,string>|null
     */
    private function orderValues(int $order_id, string $lang, array $row): ?array
    {
        $order = $this->orderInfo($order_id);
        if ($order === null) {
            return null;
        }
        $extra = [];
        if ($row['status'] !== '') {
            $extra['status'] = $row['status'];
        }
        if ($row['kind'] === 'placed_multi') {
            $children = $this->childOrderIds($order_id);
            $extra['order_ids'] = $children === [] ? [$order_id] : $children;
            $extra['vendor_name'] = '';
        }

        return $this->orders->placeholders($order, $lang, $extra);
    }

    // ================================================================== helpers: rows, input, texts

    /**
     * @return array{key:string,event_id:string,receiver:string,lang:string,body:string,confirm:string,storefront_id:int}
     */
    private function readInput(array $post): array
    {
        $text = static function (string $name) use ($post): string {
            return isset($post[$name]) && is_string($post[$name]) ? $post[$name] : '';
        };
        $event_id = $text('event_id');
        $receiver = $text('receiver');
        $lang = $text('lang');
        // 2026-10-22 16:13 +03: Task A3, task 596. Digits only; anything else is -1, which storefrontKnown() refuses.
        $sf_raw = $text('storefront_id');
        $storefront_id = $sf_raw === '' ? 0 : (ctype_digit($sf_raw) && strlen($sf_raw) <= 10 ? (int) $sf_raw : -1);

        return [
            'key' => self::rowKey($event_id, $receiver),
            'event_id' => $event_id,
            'receiver' => $receiver,
            'lang' => preg_match('/^[a-z]{2}$/', $lang) === 1 ? $lang : '',
            'body' => $text('body'),
            'confirm' => $text('confirm'),
            'storefront_id' => $storefront_id,
        ];
    }

    /**
     * 2026-10-22 16:13 +03: Task A3, task 596. 0 (All storefronts) is always valid; a positive id must be a
     * storefront of the store and the store must have two or more (with one storefront there is nothing to pick).
     */
    private function storefrontKnown(int $storefront_id): bool
    {
        if ($storefront_id === 0) {
            return true;
        }
        if ($storefront_id < 0) {
            return false;
        }
        $known = $this->storefronts();

        return count($known) >= 2 && isset($known[$storefront_id]);
    }

    /**
     * The row of the page for an event id and receiver, or null: only rows the page lists can be written.
     *
     * @return array<string,mixed>|null
     */
    private function rowFor(string $event_id, string $receiver, int $storefront_id = 0): ?array
    {
        foreach ($this->templates->rowsForUi(null, max(0, $storefront_id)) as $row) {
            if ($row['event_id'] === $event_id && $row['receiver'] === $receiver) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function rowLabel(array $row): string
    {
        // 2026-10-22 22:24 +03: Task A8 (carry P2-R10b), task 596. A vendor row is "Vendor", not "Customer".
        $receiver_key = 'kwtsms.templates.customer';
        if ($row['receiver'] === Defaults::RECEIVER_ADMIN) {
            $receiver_key = 'kwtsms.templates.admin';
        } elseif ($row['receiver'] === Defaults::RECEIVER_VENDOR) {
            $receiver_key = 'kwtsms.templates.vendor';
        }
        $receiver = (string) __($receiver_key);
        switch ($row['kind']) {
            case 'placed':
                $what = (string) __('kwtsms.templates.row_placed');
                break;
            case 'placed_multi':
                $what = (string) __('kwtsms.templates.row_placed_multi');
                break;
            case 'generic':
                $what = (string) __('kwtsms.templates.row_generic');
                break;
            default:
                $what = (string) $row['status_name'];
        }

        return $what . ' / ' . $receiver;
    }

    /**
     * @param array<string,string>             $input
     * @param array<int,string>                $unknown
     *
     * @return array<string,mixed>
     */
    private function draft(array $input, array $unknown, string $reason): array
    {
        return [
            'key' => $input['key'],
            'lang' => $input['lang'],
            'body' => $input['body'],
            'unknown' => $unknown,
            'reason' => $reason,
        ];
    }

    public static function rowKey(string $event_id, string $receiver): string
    {
        return $event_id . ':' . $receiver;
    }

    public static function domId(string $key): string
    {
        return 'kwtsms_row_' . trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $key), '_');
    }

    private static function snippet(string $text): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($line, 'UTF-8') <= self::SNIPPET_CHARS) {
            return $line;
        }

        return rtrim(mb_substr($line, 0, self::SNIPPET_CHARS, 'UTF-8')) . '...';
    }

    /**
     * Removed characters made visible: invisible and control characters are written as U+XXXX.
     *
     * @param array<int,string> $found
     */
    private static function visible(array $found): string
    {
        $out = [];
        foreach ($found as $run) {
            $text = '';
            foreach (preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                $cp = mb_ord($char, 'UTF-8');
                // 2026-10-21 03:42 +03: letters, marks, digits, punctuation, symbols and the plain space stay as they
                // are; anything else (invisible, control, special spaces) is named, so a removed tag reads whole.
                $printable = $char === ' ' || preg_match('/^[\p{L}\p{M}\p{N}\p{P}\p{S}]$/u', $char) === 1;
                $text .= $printable || $cp === false ? $char : ' ' . sprintf('U+%04X', $cp) . ' ';
            }
            $out[] = trim((string) preg_replace('/ {2,}/', ' ', $text));
        }

        return implode(' ', array_filter($out, 'strlen'));
    }

    /**
     * The placeholders the page offers as chips, each with its reference value.
     *
     * @return array<string,string> name => reference value in the tab language
     */
    private function placeholderWords(string $lang, int $sf = 0): array
    {
        // 2026-10-21 08:28 +03: the {store_name} chip shows the store's real brand, cleanup item 3, task 596.
        // 2026-10-22 18:00 +03: Task A5, task 596. The chips are the shared placeholders; the shipment ones are not
        // offered on every row.
        return array_intersect_key($this->reference($lang, '', $sf), array_flip(Defaults::PLACEHOLDERS));
    }

    /**
     * The reference values of the page (SampleData::reference) with the store's real brand as {store_name}: the matrix
     * length, the editor sample, the preview and the test SMS all use this one method, so they agree.
     * 2026-10-21 08:28 +03: added, cleanup item 3, task 596.
     *
     * @return array<string,string>
     */
    private function reference(string $lang, string $status_name, int $sf = 0): array
    {
        // 2026-10-22 16:13 +03: Task A3, task 596. A storefront view shows that storefront's own brand name.
        $brand = $sf > 0 ? $this->brandOfStorefront($sf, $lang) : $this->brandOf($lang);

        // 2026-10-22 18:00 +03: Task A5, task 596. The shipment placeholders have sample values too (the preview of
        // the shipment text).
        // 2026-10-22 19:08 +03: Task A6, task 596. And the profile ones (user id, phone, reset link).
        return SampleData::reference($lang, $status_name, $brand) + SampleData::shipment(false, $lang)
            + SampleData::profile(false) + SampleData::lowStock(false) // 2026-10-22 21:29 +03: Task A7, task 596
            + SampleData::lowBalance(false); // 2026-10-23 00:14 +03: Task A9, task 596
    }

    /**
     * 2026-10-22 16:13 +03: Task A3, task 596. The SMS brand name of one storefront in a language ('' when none or
     * unreadable).
     */
    private function brandOfStorefront(int $sf, string $lang): string
    {
        $key = $lang . '#' . $sf;
        if (!isset($this->brand_cache[$key])) {
            try {
                $this->brand_cache[$key] = BrandNames::get($sf, $lang);
            } catch (\Throwable $e) {
                $where = basename($e->getFile()) . ':' . $e->getLine();
                $this->note('error', 'brand.read_failed', 'The storefront brand could not be read', [
                    'error' => get_class($e) . ': ' . $e->getMessage() . ' in ' . $where,
                ]);
                $this->brand_cache[$key] = '';
            }
        }

        return $this->brand_cache[$key];
    }

    /**
     * The SMS brand name of the default storefront in a language, read once per language and request ('' when none is
     * named or it cannot be read: the PRD sample brand then stands in). 2026-10-21 08:28 +03: added, cleanup item 3,
     * task 596.
     */
    private function brandOf(string $lang): string
    {
        if (!isset($this->brand_cache[$lang])) {
            $this->brand_cache[$lang] = $this->brand($lang);
        }

        return $this->brand_cache[$lang];
    }

    /**
     * The brand of the default storefront, the one the Settings test SMS and the Dashboard use. 2026-10-21 08:28 +03:
     * added (a test replaces it), cleanup item 3, task 596.
     */
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
            $where = basename($e->getFile()) . ':' . $e->getLine();
            $this->note('error', 'brand.read_failed', 'The store brand could not be read', [
                'error' => get_class($e) . ': ' . $e->getMessage() . ' in ' . $where,
            ]);

            return '';
        }
    }

    /**
     * @return array<string,string>
     */
    private function worstValues(string $lang, string $own_status_name = ''): array
    {
        $status = [];
        foreach (Defaults::STATUS_CODES as $code) {
            $status[] = $this->statusNameIn($code, $lang, '');
        }
        $currency = [];
        foreach (Defaults::CURRENCIES as $iso) {
            $name = $this->langvar(Defaults::currencyLangvar($iso), $lang);
            if ($name !== null) {
                $currency[] = $name;
            }
        }

        // 2026-10-22 18:00 +03: Task A5, task 596. Plus the worst case of the shipment placeholders.
        return SampleData::worst($lang, array_values(array_filter($status, 'strlen')), $currency, $own_status_name)
            + SampleData::shipment(true, $lang) + SampleData::profile(true) // 2026-10-22 19:08 +03: Task A6, task 596
            + SampleData::lowStock(true) // 2026-10-22 21:29 +03: Task A7, task 596
            + SampleData::lowBalance(true); // 2026-10-23 00:14 +03: Task A9, task 596
    }

    /**
     * An order status name in the message language: the shipped name for the codes that have one, else the core name.
     */
    private function statusNameIn(string $code, string $lang, string $fallback): string
    {
        if (in_array($code, Defaults::STATUS_CODES, true)) {
            $name = $this->langvar(Defaults::statusLangvar($code), $lang);
            if ($name !== null) {
                return $name;
            }
        }
        $core = $this->coreStatusName($code, $lang);

        return $core !== '' ? $core : $fallback;
    }

    /**
     * A notification of one of our language variables, its values escaped (a test replaces this to record the key).
     *
     * @param array<string,string> $params
     */
    protected function say(string $type, string $key, array $params = []): void
    {
        $this->notify($type, $this->plainEscaped($key, $params), false);
    }

    /**
     * A language variable with its values escaped, for a notification (which is HTML).
     *
     * @param array<string,string> $params
     */
    private function plainEscaped(string $key, array $params): string
    {
        $escaped = [];
        foreach ($params as $name => $value) {
            $escaped[$name] = fn_html_escape((string) $value);
        }

        return (string) __($key, $escaped);
    }

    /**
     * A language variable as plain text for the script (it shows it with textContent, never as HTML).
     *
     * @param array<string,string> $params
     */
    private function plain(string $key, array $params = []): string
    {
        return (string) __($key, $params);
    }

    /**
     * One row of the add-on log for the page: names, counts and flags only, never a template body or a number.
     * 2026-10-21 05:04 +03: added, Task 1.12 fix round 1 item 9, task 596.
     *
     * @param array<string,mixed> $context
     */
    private function note(string $level, string $action, string $message, array $context, ?float $started = null): void
    {
        $ms = $started === null ? null : (int) round((microtime(true) - $started) * 1000);
        $this->log->log($level, 'templates.page.' . $action, $message, $context, $ms);
    }

    /**
     * @param array<string,string> $input
     */
    private function trace(
        string $level,
        string $action,
        string $message,
        array $input,
        float $started,
        string $reason
    ): void {
        $this->log->log($level, 'templates.page.' . $action, $message, [
            'event_id' => $input['event_id'],
            'receiver' => $input['receiver'],
            'lang' => $input['lang'],
            'reason' => $reason,
        ], (int) round((microtime(true) - $started) * 1000));
    }

    // ================================================================== lookups (tests replace these)

    /**
     * Installed languages: code => name, direction (ltr|rtl).
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
            $direction = isset($language['direction']) && $language['direction'] === 'rtl' ? 'rtl'
                : (function_exists('fn_is_rtl_language') && fn_is_rtl_language((string) $code) ? 'rtl' : 'ltr');
            $out[(string) $code] = ['name' => (string) $language['name'], 'direction' => $direction];
        }

        return $out !== [] ? $out : ['en' => ['name' => 'English', 'direction' => 'ltr']];
    }

    /**
     * The storefronts of this store: id => name (the allow-list of the brand-name editor). In Multi-Vendor these
     * are the marketplace's storefronts; a vendor is a company, not a storefront, and never appears.
     * 2026-10-21 05:16 +03: added, Task 1.12 fix round 1 item 3, task 596.
     *
     * @return array<int,string>
     */
    protected function storefronts(): array
    {
        try {
            $found = \Tygh\Tygh::$app['storefront.repository']->find();
            $out = [];
            foreach ((array) ($found[0] ?? []) as $storefront) {
                $out[(int) $storefront->storefront_id] = (string) $storefront->name;
            }
            ksort($out);

            return $out;
        } catch (\Throwable $e) {
            $where = basename($e->getFile()) . ':' . $e->getLine();
            $this->note('error', 'brand.storefronts_failed', 'The storefronts could not be listed', [
                'error' => get_class($e) . ': ' . $e->getMessage() . ' in ' . $where,
            ]);

            return [];
        }
    }

    private function languageInstalled(string $lang): bool
    {
        return $lang !== '' && isset($this->languages()[$lang]);
    }

    private function languageName(string $lang): string
    {
        $languages = $this->languages();

        return isset($languages[$lang]) ? $languages[$lang]['name'] : $lang;
    }

    protected function defaultLang(): string
    {
        $lang = (string) \Tygh\Registry::get('settings.Appearance.backend_default_language');

        return $lang !== '' ? $lang : 'en';
    }

    /**
     * The effective SMS switch of every event and receiver that has one: event => receiver => on. Read from the same
     * service the dispatcher and Administration > Notifications read (the fail-closed extension is part of it).
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
     * Core's own writer, the one Administration > Notifications uses.
     */
    protected function setSwitch(string $event_id, string $receiver, bool $on): void
    {
        fn_set_notification_settings($event_id, self::TRANSPORT, $receiver, $on ? 1 : 0);
    }

    /**
     * A language value, or null when the variable does not exist in that language.
     */
    protected function langvar(string $name, string $lang): ?string
    {
        $value = (string) __($name, [], $lang);

        return $value === '' || $value === '_' . strtolower($name) || $value === '_' . $name ? null : $value;
    }

    protected function coreStatusName(string $code, string $lang): string
    {
        $names = (array) fn_get_simple_statuses(STATUSES_ORDER, true, false, $lang);

        return isset($names[$code]) ? (string) $names[$code] : '';
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function orderInfo(int $order_id): ?array
    {
        $info = fn_get_order_info($order_id);

        return is_array($info) && $info !== [] ? $info : null;
    }

    /**
     * @return array<int,int>
     */
    protected function childOrderIds(int $parent_id): array
    {
        return array_map('intval', (array) db_get_fields(
            'SELECT order_id FROM ?:orders WHERE parent_order_id = ?i ORDER BY order_id',
            $parent_id
        ));
    }

    /**
     * Whether a text ships for the language (Reset and Load defaults have something to put back).
     */
    protected function hasShippedTexts(string $lang): bool
    {
        return is_file(\Tygh\Registry::get('config.dir.lang_packs') . $lang . '/addons/kwtsms.po');
    }

    /**
     * A notification: N notice, W warning, E error. The text is already escaped.
     */
    protected function notify(string $type, string $message, bool $escape = true): void
    {
        $titles = ['N' => 'notice', 'W' => 'warning', 'E' => 'error'];
        fn_set_notification($type, __($titles[$type] ?? 'notice'), $escape ? fn_html_escape($message) : $message);
    }
}
