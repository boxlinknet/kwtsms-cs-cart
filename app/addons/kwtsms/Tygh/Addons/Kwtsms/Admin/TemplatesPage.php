<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/TemplatesPage.php
 * CREATED:   2026-10-21 03:16 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The Templates page of the kwtsms admin controller: the matrix (one row per order status and receiver, with
 *            the core SMS switch of the row), the language tabs, the text and badge of each row, and every POST
 *            action of the page: update_template (save), reset_template, load_template_defaults, toggle_event,
 *            preview_template.
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
 *            $result['draft'] is what the controller keeps in the session for the redirect (a refused save shows the
 *            typed text again); $result['lang'] and $result['edit'] build the redirect.
 * DEPENDS:   Templates\{TemplateRepository,TemplateRenderer,TemplateChecks,SampleData,Defaults},
 *            Notifications\OrderSmsData,
 *            Sms\MessageCleaner, Log\DebugLog, Settings\ConfigInterface; CS-Cart __(), fn_set_notification(),
 *            fn_html_escape(), fn_set_notification_settings(), fn_get_order_info(), Tygh\Languages\Languages,
 *            Tygh\Providers\EventDispatcherProvider (all through protected methods a test can replace).
 */

namespace Tygh\Addons\Kwtsms\Admin;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Notifications\OrderSmsData;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\MessageCleaner;
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

    public function __construct(
        TemplateRepository $templates,
        DebugLog $log,
        OrderSmsData $orders,
        ConfigInterface $config
    ) {
        $this->templates = $templates;
        $this->log = $log;
        $this->orders = $orders;
        $this->config = $config;
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
            $app['kwtsms.config']
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
     *
     * @return array<string,mixed>
     */
    public function viewData(string $lang_param, string $edit_key, ?array $draft, bool $can_toggle): array
    {
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
        $words = $this->placeholderWords($lang);

        $rows = [];
        $editor = null;
        foreach ($this->templates->rowsForUi() as $source) {
            $row = $this->matrixRow($source, $lang, $switches);
            if ($row['key'] === $edit_key) {
                $editor = $this->editor($source, $row, $lang, $languages[$lang], $draft);
                $row['editing'] = true;
            }
            $rows[] = $row;
        }

        return [
            'languages' => $languages,
            'lang' => $lang,
            'lang_name' => $languages[$lang]['name'],
            'direction' => $languages[$lang]['direction'],
            'default_lang' => $default_lang,
            'default_lang_name' => isset($languages[$default_lang]) ? $languages[$default_lang]['name'] : $default_lang,
            'rows' => $rows,
            'editor' => $editor,
            'can_toggle' => $can_toggle,
            'sms_enabled' => $this->config->enabled(),
            'test_mode' => $this->config->testMode(),
            'has_shipped' => $this->hasShippedTexts($lang),
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
     * One row of the matrix.
     *
     * @param array<string,mixed>                 $source   A row of TemplateRepository::rowsForUi()
     * @param array<string,array<string,bool>>    $switches event => receiver => on
     *
     * @return array<string,mixed>
     */
    private function matrixRow(array $source, string $lang, array $switches): array
    {
        $event_id = (string) $source['event_id'];
        $receiver = (string) $source['receiver'];
        $kind = (string) $source['kind'];
        $key = self::rowKey($event_id, $receiver);

        $own = isset($source['bodies'][$lang]) ? (string) $source['bodies'][$lang]['body'] : '';
        $has_own = trim($own) !== '';
        $resolved = $this->templates->resolve($event_id, $receiver, $lang);
        if ($resolved === null) {
            $badge = self::BADGE_NONE;
        } elseif ($has_own) {
            $badge = ($source['bodies'][$lang]['is_edited'] ?? 'N') === 'Y' ? self::BADGE_EDITED : self::BADGE_DEFAULT;
        } elseif ($resolved['fallback'] === TemplateRepository::FALLBACK_DEFAULT_LANG) {
            $badge = self::BADGE_DEFAULT_LANG;
        } else {
            $badge = self::BADGE_GENERIC;
        }
        $effective = $resolved === null ? '' : (string) $resolved['body'];

        $status = (string) $source['status'];
        $status_name = $status === '' ? '' : $this->statusNameIn($status, $lang, (string) $source['status_name']);
        $sent = MessageCleaner::clean(TemplateRenderer::render($effective, SampleData::reference($lang, $status_name)));

        $switch = null;
        $note = '';
        if ($kind === 'placed' || $kind === 'status') {
            if (isset($switches[$event_id][$receiver])) {
                $switch = ['on' => $switches[$event_id][$receiver]];
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
     * The editor of one row in one language.
     *
     * @param array<string,mixed>      $source
     * @param array<string,mixed>      $row
     * @param array<string,mixed>      $language name, direction
     * @param array<string,mixed>|null $draft
     *
     * @return array<string,mixed>
     */
    private function editor(array $source, array $row, string $lang, array $language, ?array $draft): array
    {
        $own = isset($source['bodies'][$lang]) ? (string) $source['bodies'][$lang]['body'] : '';
        $resolved = $this->templates->resolve($row['event_id'], $row['receiver'], $lang);
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
            'lang' => $lang,
            'lang_name' => $language['name'],
            'direction' => $language['direction'],
            'body' => $body,
            'unknown' => $unknown,
            'reason' => $reason,
            // 2026-10-21 04:00 +03: JSON, so a name that holds a comma can still be confirmed. Task 1.12, task 596.
            'confirm' => (string) json_encode($unknown),
            'advice' => $this->advice($stored, (string) $row['receiver'], (string) $row['event_id']),
            'reference' => SampleData::reference(
                $lang,
                $status === '' ? '' : $this->statusNameIn($status, $lang, (string) $row['status_name'])
            ),
            'worst' => $this->worstValues($lang),
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
     * @return array{saved:bool,reason:string,lang:string,edit:string,draft:array<string,mixed>|null}
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
        ];

        $row = $this->rowFor($input['event_id'], $input['receiver']);
        if ($row === null || !$this->languageInstalled($input['lang'])) {
            $result['reason'] = 'unknown_row';
            $result['edit'] = '';
            $this->say('E', 'kwtsms.templates.not_saved_unknown_row');

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

        $unknown = TemplateRenderer::unknownPlaceholders(
            $stored,
            Defaults::allowedPlaceholders($input['event_id'], $input['receiver'])
        );
        $confirmed = json_decode($input['confirm'], true);
        $confirmed = is_array($confirmed) ? array_map('strval', array_filter($confirmed, 'is_scalar')) : [];
        $accept_unknown = $unknown !== [] && array_diff($unknown, $confirmed) === [];

        $id = $this->templates->ensureTemplate($input['event_id'], $input['receiver']);
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
     * @return array{lang:string,edit:string}
     */
    public function reset(array $post): array
    {
        $input = $this->readInput($post);
        $result = ['lang' => $input['lang'], 'edit' => ''];
        $row = $this->rowFor($input['event_id'], $input['receiver']);
        if ($row === null || !$this->languageInstalled($input['lang'])) {
            $this->say('E', 'kwtsms.templates.not_saved_unknown_row');

            return $result;
        }

        $id = $this->templates->ensureTemplate($input['event_id'], $input['receiver']);
        $body = $this->templates->resetToDefault($id, $input['lang']);
        $params = ['[row]' => $this->rowLabel($row), '[language]' => $this->languageName($input['lang'])];
        if ($body === null) {
            $this->say('E', 'kwtsms.templates.not_saved_unknown_row');
        } elseif ($body === '') {
            $this->say('N', 'kwtsms.templates.reset_cleared', $params);
        } else {
            $this->say('N', 'kwtsms.templates.reset_done', $params);
        }

        return $result;
    }

    /**
     * "Load default texts for <language>": fills the rows that are missing or not edited.
     *
     * @return array{lang:string}
     */
    public function loadDefaults(string $lang): array
    {
        if (!$this->languageInstalled($lang)) {
            $this->say('E', 'kwtsms.templates.not_saved_unknown_row');

            return ['lang' => $this->defaultLang()];
        }
        $written = $this->templates->loadDefaultsForLanguage($lang);
        $params = ['[count]' => (string) $written, '[language]' => $this->languageName($lang)];
        if ($written > 0) {
            $this->say('N', 'kwtsms.templates.loaded', $params);
        } else {
            $this->say('W', 'kwtsms.templates.loaded_none', $params);
        }

        return ['lang' => $lang];
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
            || !in_array($row['kind'], ['placed', 'status'], true)
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
        $row = $this->rowFor($input['event_id'], $input['receiver']);
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
            $values = SampleData::reference(
                $input['lang'],
                $status === '' ? '' : $this->statusNameIn($status, $input['lang'], (string) $row['status_name'])
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
     * @return array{key:string,event_id:string,receiver:string,lang:string,body:string,confirm:string}
     */
    private function readInput(array $post): array
    {
        $text = static function (string $name) use ($post): string {
            return isset($post[$name]) && is_string($post[$name]) ? $post[$name] : '';
        };
        $event_id = $text('event_id');
        $receiver = $text('receiver');
        $lang = $text('lang');

        return [
            'key' => self::rowKey($event_id, $receiver),
            'event_id' => $event_id,
            'receiver' => $receiver,
            'lang' => preg_match('/^[a-z]{2}$/', $lang) === 1 ? $lang : '',
            'body' => $text('body'),
            'confirm' => $text('confirm'),
        ];
    }

    /**
     * The row of the page for an event id and receiver, or null: only rows the page lists can be written.
     *
     * @return array<string,mixed>|null
     */
    private function rowFor(string $event_id, string $receiver): ?array
    {
        foreach ($this->templates->rowsForUi() as $row) {
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
        $is_admin = $row['receiver'] === Defaults::RECEIVER_ADMIN;
        $receiver = (string) __($is_admin ? 'kwtsms.templates.admin' : 'kwtsms.templates.customer');
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
    private function placeholderWords(string $lang): array
    {
        return SampleData::reference($lang, '');
    }

    /**
     * @return array<string,string>
     */
    private function worstValues(string $lang): array
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

        return SampleData::worst($lang, array_values(array_filter($status, 'strlen')), $currency);
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
