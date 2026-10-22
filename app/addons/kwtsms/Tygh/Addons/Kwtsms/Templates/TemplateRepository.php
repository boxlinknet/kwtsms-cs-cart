<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Templates/TemplateRepository.php
 * CREATED:   2026-10-20 23:11 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Storage of the SMS templates (tables kwtsms_templates and kwtsms_template_descriptions): picks the
 *            text for an event, receiver and language with the PRD 6.4 fallback, lists one row per order status
 *            and receiver for the Templates page, saves a body cleaned, resets a body to the shipped text, fills
 *            the texts of a language added later, and seeds the shipped defaults at install.
 * WHY:       PRD 6.4 and 8. One template per (event id, receiver) with a body per language. The shipped defaults
 *            live in var/langs/{en,ar}/addons/kwtsms.po, where translators work; "Reset to default" and "Load
 *            default texts" read that file, never the editable language variable, so an admin edit of the
 *            langvar cannot come back as the "default".
 * USAGE:     $repo = new TemplateRepository($debugLog = null, $defaultLang = '');   // wired by ServiceProvider (1.9)
 *            $repo->resolve('order.status_changed.x', 'C', 'ar')
 *                -> ['body' => ..., 'fallback' => 'exact'|'family'|'default_lang', 'template_id' => 12,
 *                    'event_id' => 'order.status_changed', 'lang' => 'ar'] or null (skip: no_template).
 *                Order: the event in the language, its family event (the event id without its last segment, when
 *                that still has a dot: order.status_changed.x -> order.status_changed) in the language, the
 *                event, then its family in the store default language. A blank body counts as missing.
 *            $repo->save($id, 'en', $body, $confirm_unknown)
 *                -> ['saved' => bool, 'reason' => ''|'unknown_placeholders'|'not_found'|'too_long'|'bad_language',
 *                    'body' => cleaned body, 'removed' => ['<b>', ...], 'unknown' => ['{typo}', ...]]
 *                The body is stored by MessageCleaner::cleanForStorage() (ruling R18: cleaning happens once, at
 *                send; typed backslash-n stays as typed and is decoded there), CRLF folded to LF, characters
 *                above U+FFFF removed (the connection is 3-byte utf8), at most 5000 characters. An unknown
 *                placeholder needs $confirm_unknown. An emptied body is saved: the next fallback answers.
 *            $repo->resetToDefault($id, 'en')   -> the text now stored ('' when no text ships for that language
 *                                                  or template), null when the template does not exist
 *            $repo->loadDefaultsForLanguage('ar') -> number of rows written; only rows that are missing or not
 *                                                  edited (see isUnedited(): a copy of the default language text
 *                                                  counts as not edited, the default language's own edit never)
 *            $repo->seedDefaults(['en', 'ar'])  -> INSERT IGNORE of every shipped default of those languages
 *            $repo->rowsForUi()                 -> rows for the Templates page (see rowsForUi())
 *            $repo->ensureTemplate($event_id, $receiver) -> template_id, created when missing
 *            TemplateRepository::removedCharacters($input, $stored) -> what cleaning took out, as a list of runs
 *            2026-10-22 14:53 +03, Task A2, task 596: resolve(), ensureTemplate(), rowsForUi() and resolveSql() take a
 *            trailing $storefront_id = 0; the answer of resolve() also
 *            carries storefront_id. Order P2-3: per language, the storefront's text (exact, family) then the
 *            all-storefronts text (exact, family); the default language only after both.
 *            Logging (when a DebugLog is given): info templates.save|reset|seed|load_defaults, debug
 *            templates.resolve; never the body.
 * DEPENDS:   CS-Cart db_* functions, Tygh\Languages\Po, Tygh\Registry (config.dir.lang_packs, backend default
 *            language), fn_get_simple_statuses() and fn_allowed_for(); Templates\Defaults, Templates\TemplateRenderer,
 *            Sms\MessageCleaner, Log\DebugLog (optional).
 */

namespace Tygh\Addons\Kwtsms\Templates;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Sms\MessageCleaner;
use Tygh\Languages\Po;
use Tygh\Registry;

class TemplateRepository
{
    public const FALLBACK_EXACT = 'exact';
    public const FALLBACK_FAMILY = 'family';
    public const FALLBACK_DEFAULT_LANG = 'default_lang';

    /** Longest body save() accepts, in characters. Seven Unicode pages are about 470; this is generous. */
    public const MAX_BODY_CHARS = 5000;

    /** removedCharacters() aligns the two texts when the middle parts have at most this many cells in all. */
    private const DIFF_CELL_LIMIT = 250000;

    /**
     * The hot query (one per SMS): the candidate templates of a receiver in the candidate languages.
     * Uses the unique key (event_id, receiver, storefront_id) and the primary key (template_id, lang_code).
     * 2026-10-22 14:53 +03: storefront_id selected and filtered (IN the storefront and 0 = all storefronts), the
     * key is uniq_event_receiver_sf; Task A2 (P2-3), task 596.
     */
    private const RESOLVE_SQL = 'SELECT t.template_id, t.event_id, t.storefront_id, d.lang_code, d.body'
        . ' FROM ?:kwtsms_templates AS t'
        . ' INNER JOIN ?:kwtsms_template_descriptions AS d ON d.template_id = t.template_id'
        . ' WHERE t.receiver = ?s AND t.event_id IN (?a) AND t.storefront_id IN (?a) AND d.lang_code IN (?a)';

    /** @var DebugLog|null */
    private $log;

    /** @var string */
    private $default_lang;

    /**
     * @param string $default_lang Store default language; '' = the backend default language of the store
     */
    public function __construct(?DebugLog $log = null, string $default_lang = '')
    {
        $this->log = $log;
        $this->default_lang = $default_lang;
    }

    // ------------------------------------------------------------------------------------------ resolve

    /**
     * The text to send for an event, receiver and language, or null when no template has one.
     *
     * 2026-10-22 14:53 +03: $storefront_id added; the answer carries storefront_id, the row that answered (0 = the
     * all-storefronts text). Order P2-3: for each language (the message language, then the store default), the
     * storefront's own text (exact event, then family) before the all-storefronts text. Task A2, task 596.
     *
     * @return array{body:string,fallback:string,template_id:int,event_id:string,lang:string,storefront_id:int}|null
     */
    public function resolve(string $event_id, string $receiver, string $lang, int $storefront_id = 0): ?array
    {
        $started = microtime(true);
        $found = $this->pick($event_id, $receiver, $lang, $storefront_id);
        if ($this->log !== null && $this->log->isEnabled('debug')) {
            $this->trace('debug', 'resolve', 'resolve()', [
                'event_id' => $event_id,
                'receiver' => $receiver,
                'lang' => $lang,
                'storefront_id' => $storefront_id,
                'fallback' => $found === null ? 'none' : $found['fallback'],
            ], $started);
        }

        return $found;
    }

    /**
     * The query resolve() runs, with its values filled in. For EXPLAIN and ANALYZE only (tests, the index check):
     * resolve() itself runs RESOLVE_SQL with placeholders.
     */
    public function resolveSql(string $event_id, string $receiver, string $lang, int $storefront_id = 0): string
    {
        list($events, $langs) = $this->candidates($event_id, $lang);

        // 2026-10-22 14:53 +03: $storefront_id added (ruling R2), task A2, task 596.
        return $this->quote(self::RESOLVE_SQL, $receiver, $events, self::storefrontIds($storefront_id), $langs);
    }

    /**
     * 2026-10-22 14:53 +03: the storefront ids a lookup may answer from, most specific first: the storefront, then 0
     * (all storefronts). Task A2, task 596.
     *
     * @return array<int,int>
     */
    private static function storefrontIds(int $storefront_id): array
    {
        return $storefront_id > 0 ? [$storefront_id, 0] : [0];
    }

    /**
     * Seam for the unit test: db_quote().
     *
     * @param mixed ...$args
     */
    protected function quote(string $sql, ...$args): string
    {
        return db_quote($sql, ...$args);
    }

    /**
     * Seam for the unit test: the hot query.
     *
     * @param array<int,string> $events
     * @param array<int,int>    $storefronts
     * @param array<int,string> $langs
     *
     * @return array<int,array<string,mixed>>
     */
    protected function fetchRows(string $receiver, array $events, array $storefronts, array $langs): array
    {
        return (array) db_get_array(self::RESOLVE_SQL, $receiver, $events, $storefronts, $langs);
    }

    /**
     * The event ids and the languages resolve() may answer from, most specific first.
     *
     * @return array{0:array<int,string>,1:array<int,string>}
     */
    private function candidates(string $event_id, string $lang): array
    {
        $events = [$event_id];
        $family = self::parentEventOf($event_id);
        if ($family !== '') {
            $events[] = $family;
        }
        $langs = [];
        foreach ([$lang, $this->defaultLang()] as $code) {
            if ($code !== '' && !in_array($code, $langs, true)) {
                $langs[] = $code;
            }
        }

        return [$events, $langs];
    }

    /**
     * @return array{body:string,fallback:string,template_id:int,event_id:string,lang:string,storefront_id:int}|null
     */
    private function pick(string $event_id, string $receiver, string $lang, int $storefront_id): ?array
    {
        if ($event_id === '' || $receiver === '') {
            return null;
        }
        list($events, $langs) = $this->candidates($event_id, $lang);

        $storefronts = self::storefrontIds($storefront_id);
        $rows = $this->fetchRows($receiver, $events, $storefronts, $langs);
        $index = [];
        foreach ($rows as $row) {
            $index[$row['lang_code']][(int) ($row['storefront_id'] ?? 0)][$row['event_id']] = $row;
        }

        foreach ($langs as $i => $code) {
            foreach ($storefronts as $sf) {
                foreach ($events as $j => $event) {
                    $row = $index[$code][$sf][$event] ?? null;
                    if ($row === null || trim((string) $row['body']) === '') {
                        continue;
                    }
                    if ($i > 0) {
                        $fallback = self::FALLBACK_DEFAULT_LANG;
                    } else {
                        $fallback = $j === 0 ? self::FALLBACK_EXACT : self::FALLBACK_FAMILY;
                    }

                    return [
                        'body' => (string) $row['body'],
                        'fallback' => $fallback,
                        'template_id' => (int) $row['template_id'],
                        'event_id' => (string) $row['event_id'],
                        'lang' => (string) $row['lang_code'],
                        'storefront_id' => (int) ($row['storefront_id'] ?? 0),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * The family event: the event id without its last segment, when what is left still has a dot
     * (order.status_changed.p -> order.status_changed; order.status_changed -> none).
     */
    private static function parentEventOf(string $event_id): string
    {
        $dot = strrpos($event_id, '.');
        if ($dot === false) {
            return '';
        }
        $family = substr($event_id, 0, $dot);

        return strpos($family, '.') === false ? '' : $family;
    }

    // ------------------------------------------------------------------------------------------ templates

    /**
     * The id of the template for an event and receiver, created (empty) when it does not exist yet.
     */
    public function ensureTemplate(string $event_id, string $receiver, int $storefront_id = 0): int
    {
        // 2026-10-22 14:53 +03: per storefront (0 = all storefronts), Task A2, task 596.
        $storefront_id = max(0, $storefront_id);
        db_query('INSERT IGNORE INTO ?:kwtsms_templates ?e', [
            'event_id' => $event_id,
            'receiver' => $receiver,
            'storefront_id' => $storefront_id,
            'updated_at' => time(),
        ]);

        return (int) db_get_field(
            'SELECT template_id FROM ?:kwtsms_templates WHERE event_id = ?s AND receiver = ?s AND storefront_id = ?i',
            $event_id,
            $receiver,
            $storefront_id
        );
    }

    /**
     * Rows for the Templates page: first the order-placed rows (the Multi-Vendor checkout row only in
     * Multi-Vendor), then the generic status rows, then one row per order status of the store and receiver
     * (C, then A), custom statuses included. A status row with no text of its own "uses the generic text".
     *
     * Row: event_id, receiver, kind (placed|placed_multi|generic|status), status ('' or the code), status_name,
     * template_id (0 = no template row yet; call ensureTemplate() before save()), uses_generic, updated_at, and
     * bodies: lang => ['body' => ..., 'is_edited' => 'Y'|'N'] for every language that has a row.
     *
     * @param string|null $status_lang   Language of the status names; null = the admin language
     * @param int         $storefront_id 0 = the all-storefronts texts; else the texts of that storefront (template_id 0
     *                                   = the storefront has no row yet; ensureTemplate() with the same id makes one)
     *
     * @return array<int,array<string,mixed>>
     */
    public function rowsForUi(?string $status_lang = null, int $storefront_id = 0): array
    {
        $started = microtime(true);
        $templates = [];
        // 2026-10-22 14:53 +03: only the rows of the asked storefront (0 = the all-storefronts texts, as in 1.0.0),
        // Task A2, task 596.
        $found = (array) db_get_array(
            'SELECT template_id, event_id, receiver, updated_at FROM ?:kwtsms_templates WHERE storefront_id = ?i',
            max(0, $storefront_id)
        );
        foreach ($found as $t) {
            $templates[$t['event_id'] . '|' . $t['receiver']] = $t;
        }
        $bodies = [];
        $found = (array) db_get_array(
            'SELECT template_id, lang_code, body, is_edited FROM ?:kwtsms_template_descriptions'
        );
        foreach ($found as $d) {
            $bodies[(int) $d['template_id']][$d['lang_code']] = [
                'body' => (string) $d['body'],
                'is_edited' => (string) $d['is_edited'],
            ];
        }

        $row = static function (
            string $event_id,
            string $receiver,
            string $kind,
            string $status,
            string $status_name
        ) use (
            $templates,
            $bodies
        ): array {
            $t = $templates[$event_id . '|' . $receiver] ?? null;
            $id = $t === null ? 0 : (int) $t['template_id'];
            $own = $id > 0 ? ($bodies[$id] ?? []) : [];
            $has_text = false;
            foreach ($own as $b) {
                $has_text = $has_text || trim($b['body']) !== '';
            }

            return [
                'event_id' => $event_id,
                'receiver' => $receiver,
                'kind' => $kind,
                'status' => $status,
                'status_name' => $status_name,
                'template_id' => $id,
                'uses_generic' => $kind === 'status' && !$has_text,
                'updated_at' => $t === null ? 0 : (int) $t['updated_at'],
                'bodies' => $own,
            ];
        };

        $receivers = [Defaults::RECEIVER_CUSTOMER, Defaults::RECEIVER_ADMIN];
        $rows = [];
        foreach ($receivers as $receiver) {
            $rows[] = $row(Defaults::EVENT_PLACED, $receiver, 'placed', '', '');
        }
        if (fn_allowed_for('MULTIVENDOR')) {
            $rows[] = $row(Defaults::EVENT_PLACED_MULTI, Defaults::RECEIVER_CUSTOMER, 'placed_multi', '', '');
            // 2026-10-21 03:14 +03: the administrator's one SMS per checkout is sent from this template
            // (OrderNotifier, receiver A), so the Templates page lists it too. Task 1.12, task 596.
            $rows[] = $row(Defaults::EVENT_PLACED_MULTI, Defaults::RECEIVER_ADMIN, 'placed_multi', '', '');
        }
        foreach ($receivers as $receiver) {
            $rows[] = $row(Defaults::EVENT_STATUS, $receiver, 'generic', '', '');
        }
        $lang = $status_lang ?? (defined('DESCR_SL') ? (string) DESCR_SL : $this->defaultLang());
        foreach ($this->orderStatuses($lang) as $code => $name) {
            foreach ($receivers as $receiver) {
                $rows[] = $row(
                    Defaults::EVENT_STATUS . '.' . strtolower((string) $code),
                    $receiver,
                    'status',
                    strtoupper((string) $code),
                    (string) $name
                );
            }
        }
        $this->trace('debug', 'rows', 'rowsForUi()', ['rows' => count($rows)], $started);

        return $rows;
    }

    /**
     * The order statuses of the store as code => name (a test replaces this).
     *
     * @return array<string,string>
     */
    protected function orderStatuses(string $lang): array
    {
        return (array) fn_get_simple_statuses(STATUSES_ORDER, false, false, $lang);
    }

    // ------------------------------------------------------------------------------------------ save

    /**
     * Save a body for one language of one template.
     *
     * @return array{saved:bool,reason:string,body:string,removed:array<int,string>,unknown:array<int,string>}
     */
    public function save(int $id, string $lang, string $body, bool $confirm_unknown): array
    {
        $started = microtime(true);
        $result = ['saved' => false, 'reason' => '', 'body' => '', 'removed' => [], 'unknown' => []];

        $template = $this->templateById($id);
        if (!self::validLang($lang)) {
            $result['reason'] = 'bad_language';
        } elseif ($template === null) {
            $result['reason'] = 'not_found';
        } elseif (mb_strlen($body, 'UTF-8') > self::MAX_BODY_CHARS) {
            $result['reason'] = 'too_long';
        } else {
            $stored = self::forStorage($body);
            $result['body'] = $stored;
            $result['removed'] = self::removedCharacters($body, $stored);
            $result['unknown'] = TemplateRenderer::unknownPlaceholders(
                $stored,
                Defaults::allowedPlaceholders($template['event_id'], $template['receiver'])
            );
            if ($result['unknown'] !== [] && !$confirm_unknown) {
                $result['reason'] = 'unknown_placeholders';
            } else {
                $this->writeBody($id, $lang, $stored, 'Y');
                $result['saved'] = true;
            }
        }

        $saved = $result['saved'];
        $this->trace($saved ? 'info' : 'warning', 'save', $saved ? 'Template saved' : 'Template not saved', [
            'template_id' => $id,
            'event_id' => $template === null ? '' : $template['event_id'],
            'receiver' => $template === null ? '' : $template['receiver'],
            'lang' => $lang,
            'reason' => $result['reason'],
            'length' => mb_strlen($result['body'], 'UTF-8'),
            'removed' => count($result['removed']),
            'unknown' => count($result['unknown']),
        ], $started);

        return $result;
    }

    /**
     * What cleaning took out of a body: the runs of the input that are not in the stored text, in order of
     * appearance, each once. Blanks (space, tab, newline) at the ends of a run are not reported.
     * Cleaning also replaces (smart quotes, Arabic digits, no-break spaces), so the replaced character is listed.
     * CRLF and lone CR count as LF. A diff of the two texts (common start and end cut off, then a longest common
     * subsequence); for very large middles a count of characters instead.
     *
     * @return array<int,string>
     */
    public static function removedCharacters(string $input, string $stored): array
    {
        $input = (string) preg_replace('/\r\n?/', "\n", $input);
        if (preg_match('//u', $input) !== 1) {
            $input = (string) mb_convert_encoding($input, 'UTF-8', 'UTF-8');
        }
        $a = preg_split('//u', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $b = preg_split('//u', $stored, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($a);
        $m = count($b);

        $start = 0;
        while ($start < $n && $start < $m && $a[$start] === $b[$start]) {
            $start++;
        }
        $end = 0;
        while ($end < $n - $start && $end < $m - $start && $a[$n - 1 - $end] === $b[$m - 1 - $end]) {
            $end++;
        }
        $a = array_slice($a, $start, $n - $start - $end);
        $b = array_slice($b, $start, $m - $start - $end);
        if ($a === []) {
            return [];
        }

        $runs = count($a) * count($b) <= self::DIFF_CELL_LIMIT ? self::deletedRuns($a, $b) : self::surplusRuns($a, $b);

        $out = [];
        foreach ($runs as $run) {
            // Blanks around a removed run (the trimmed space, the newline) are not news.
            $run = trim($run, " \t\n");
            if ($run !== '' && !in_array($run, $out, true)) {
                $out[] = $run;
            }
        }

        return $out;
    }

    /**
     * The runs of $a that a longest common subsequence with $b leaves out.
     *
     * @param array<int,string> $a
     * @param array<int,string> $b
     *
     * @return array<int,string>
     */
    private static function deletedRuns(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $len = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $len[$i][$j] = $a[$i] === $b[$j]
                    ? $len[$i + 1][$j + 1] + 1
                    : max($len[$i + 1][$j], $len[$i][$j + 1]);
            }
        }

        $runs = [];
        $run = '';
        $i = 0;
        $j = 0;
        while ($i < $n) {
            if ($j < $m && $a[$i] === $b[$j]) {
                if ($run !== '') {
                    $runs[] = $run;
                    $run = '';
                }
                $i++;
                $j++;
            } elseif ($j < $m && $len[$i][$j + 1] > $len[$i + 1][$j]) {
                $j++;
            } else {
                $run .= $a[$i];
                $i++;
            }
        }
        if ($run !== '') {
            $runs[] = $run;
        }

        return $runs;
    }

    /**
     * Fallback for very large texts: each character the input has more often than the stored text, as a run of
     * one, in order of first appearance.
     *
     * @param array<int,string> $a
     * @param array<int,string> $b
     *
     * @return array<int,string>
     */
    private static function surplusRuns(array $a, array $b): array
    {
        $count = array_count_values($b);
        $runs = [];
        foreach ($a as $char) {
            if (($count[$char] ?? 0) > 0) {
                $count[$char]--;
            } else {
                $runs[] = $char;
            }
        }

        return $runs;
    }

    // ------------------------------------------------------------------------------------------ reset and load

    /**
     * Put the shipped text back for one language of one template (read from the shipped .po file, not from the
     * editable language variable). Without a shipped text the body becomes empty and the next fallback answers.
     *
     * @return string|null The body now stored; null when the template does not exist
     */
    public function resetToDefault(int $id, string $lang): ?string
    {
        $started = microtime(true);
        $template = $this->templateById($id);
        if ($template === null || !self::validLang($lang)) {
            return null;
        }

        $shipped = $this->shippedBodies($lang);
        $body = $shipped[$template['event_id'] . '|' . $template['receiver']] ?? '';
        $this->writeBody($id, $lang, $body, 'N');
        $this->trace('info', 'reset', 'Template reset to the shipped text', [
            'template_id' => $id,
            'event_id' => $template['event_id'],
            'receiver' => $template['receiver'],
            'lang' => $lang,
            'shipped' => $body !== '',
        ], $started);

        return $body;
    }

    /**
     * "Load default texts for <language>": write the shipped text of the language into every default row that
     * is missing or not edited. A row counts as not edited when its flag is N, or when it is flagged edited but is a
     * copy of the default language text in another language: core copies the descriptions (text and flag) of the
     * default language when a language is added, so an edited English row arrives as an "edited" copy that nobody
     * edited in the new language. The default language's own rows are never overwritten when edited (see
     * isUnedited(), fix round 1 item 2).
     *
     * @return int Rows written
     */
    public function loadDefaultsForLanguage(string $lang): int
    {
        $started = microtime(true);
        $shipped = self::validLang($lang) ? $this->shippedBodies($lang) : [];
        $written = 0;
        if ($shipped !== []) {
            $ids = $this->defaultTemplateIds();
            $existing = [];
            $found = (array) db_get_array(
                'SELECT template_id, lang_code, body, is_edited FROM ?:kwtsms_template_descriptions'
                . ' WHERE template_id IN (?n)',
                array_values($ids)
            );
            foreach ($found as $d) {
                $existing[(int) $d['template_id']][$d['lang_code']] = $d;
            }

            foreach ($ids as $key => $id) {
                if (!isset($shipped[$key])) {
                    continue;
                }
                $current = $existing[$id][$lang] ?? null;
                if ($current !== null) {
                    if (!self::isUnedited($current, $existing[$id], $this->defaultLang())) {
                        continue;
                    }
                    if ($current['is_edited'] === 'N' && (string) $current['body'] === $shipped[$key]) {
                        continue;
                    }
                }
                $this->writeBody($id, $lang, $shipped[$key], 'N');
                $written++;
            }
        }
        $this->trace('info', 'load_defaults', 'Default texts loaded', ['lang' => $lang, 'rows' => $written], $started);

        return $written;
    }

    /**
     * Whether "Load default texts" may overwrite this row. Not edited when its flag is N. A row flagged edited is
     * still treated as not edited when it is a copy that core made: the language is not the store default
     * language AND the row holds exactly the text of the default language row of the same template (core copies
     * the default language descriptions, text and flag, when a language is added). A row of the default language
     * itself is never a copy, and a text that merely equals another non-default language is somebody's edit.
     * 2026-10-21 00:03 +03: the rule is limited to copies of the default language, fix round 1 item 2, task 596
     * (before, the edited source row of a clone looked unedited because the clone held the same text).
     *
     * @param array<string,mixed>               $row          The description row of the language
     * @param array<string,array<string,mixed>> $all          Every description row of the template, by language
     * @param string                            $default_lang Store default language
     */
    private static function isUnedited(array $row, array $all, string $default_lang): bool
    {
        if ($row['is_edited'] === 'N') {
            return true;
        }
        $body = (string) $row['body'];
        if ((string) $row['lang_code'] === $default_lang || trim($body) === '' || !isset($all[$default_lang])) {
            return false;
        }

        return (string) $all[$default_lang]['body'] === $body;
    }

    /**
     * Seed the shipped default texts of the given languages (install, upgrade). Never touches a row that
     * exists: INSERT IGNORE, so edited and emptied texts survive and a second call writes nothing. A language
     * without a shipped file gets no rows (the default language answers for it).
     *
     * @param array<int,string> $langs Two-letter language codes
     */
    public function seedDefaults(array $langs): void
    {
        $started = microtime(true);
        $ids = $this->defaultTemplateIds();

        $seeded = [];
        foreach (array_unique($langs) as $lang) {
            $lang = (string) $lang;
            if (!self::validLang($lang)) {
                continue;
            }
            $rows = [];
            foreach ($this->shippedBodies($lang) as $key => $body) {
                if (isset($ids[$key])) {
                    $rows[] = ['template_id' => $ids[$key], 'lang_code' => $lang, 'body' => $body, 'is_edited' => 'N'];
                }
            }
            if ($rows !== []) {
                db_query('INSERT IGNORE INTO ?:kwtsms_template_descriptions ?m', $rows);
                $seeded[] = $lang;
            }
        }
        $context = ['langs' => $seeded, 'templates' => count($ids)];
        $this->trace('info', 'seed', 'Default templates seeded', $context, $started);
    }

    /**
     * The template id of every default row, created when missing: "event_id|receiver" => template_id.
     *
     * @return array<string,int>
     */
    private function defaultTemplateIds(): array
    {
        $ids = [];
        foreach (Defaults::events() as $row) {
            $key = $row['event_id'] . '|' . $row['receiver'];
            $ids[$key] = $this->ensureTemplate($row['event_id'], $row['receiver']);
        }

        return $ids;
    }

    // ------------------------------------------------------------------------------------------ shipped files

    /**
     * Where the shipped file of a language is (a test points this elsewhere).
     */
    protected function shippedPath(string $lang): string
    {
        return Registry::get('config.dir.lang_packs') . $lang . '/addons/kwtsms.po';
    }

    /**
     * The shipped default texts of a language: "event_id|receiver" => cleaned body. Empty when the language has
     * no shipped file. Read with core's own reader (Po::getValues) so the text is what core installs.
     *
     * @return array<string,string>
     */
    protected function shippedBodies(string $lang): array
    {
        $path = $this->shippedPath($lang);
        if (!self::validLang($lang) || !is_file($path)) {
            return [];
        }
        $values = Po::getValues($path, 'Languages');
        if (!is_array($values)) {
            $message = 'The shipped language file could not be read';
            $this->trace('error', 'po', $message, ['lang' => $lang], microtime(true));

            return [];
        }

        $out = [];
        foreach ($values as $data) {
            $parsed = Defaults::parseLangvar((string) ($data['id'] ?? ''));
            if ($parsed === null) {
                continue;
            }
            $body = self::forStorage(implode('', (array) ($data['msgstr'] ?? [])));
            if ($body !== '') {
                $out[$parsed['event_id'] . '|' . $parsed['receiver']] = $body;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------ helpers

    /**
     * @return array{template_id:int,event_id:string,receiver:string}|null
     */
    private function templateById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = db_get_row('SELECT template_id, event_id, receiver FROM ?:kwtsms_templates WHERE template_id = ?i', $id);

        if (!is_array($row) || $row === []) {
            return null;
        }

        return [
            'template_id' => (int) $row['template_id'],
            'event_id' => (string) $row['event_id'],
            'receiver' => (string) $row['receiver'],
        ];
    }

    private function writeBody(int $id, string $lang, string $body, string $edited): void
    {
        db_query(
            'INSERT INTO ?:kwtsms_template_descriptions (template_id, lang_code, body, is_edited)'
            . ' VALUES (?i, ?s, ?s, ?s) ON DUPLICATE KEY UPDATE body = ?s, is_edited = ?s',
            $id,
            $lang,
            $body,
            $edited,
            $body,
            $edited
        );
        db_query('UPDATE ?:kwtsms_templates SET updated_at = ?i WHERE template_id = ?i', time(), $id);
    }

    /**
     * The form save() stores a body in: cleaned for storage (ruling R18), 4-byte characters removed, trimmed. For the
     * Templates page, which checks the stored form (the {store_name} rule, the advice) before it saves.
     * 2026-10-21 03:14 +03: public wrapper added, Task 1.12, task 596.
     */
    public static function storedForm(string $body): string
    {
        return self::forStorage($body);
    }

    /**
     * The form a body is stored in: cleaned for storage (ruling R18), 4-byte characters removed, trimmed.
     */
    private static function forStorage(string $body): string
    {
        $clean = MessageCleaner::cleanForStorage($body);

        return trim((string) preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $clean));
    }

    private static function validLang(string $lang): bool
    {
        return preg_match('/^[a-z]{2}$/', $lang) === 1;
    }

    protected function defaultLang(): string
    {
        if ($this->default_lang !== '') {
            return $this->default_lang;
        }
        $lang = (string) Registry::get('settings.Appearance.backend_default_language');

        return $lang !== '' ? $lang : 'en';
    }

    /**
     * @param array<string,mixed> $context
     */
    private function trace(string $level, string $action, string $message, array $context, float $started): void
    {
        if ($this->log !== null) {
            $ms = (int) round((microtime(true) - $started) * 1000);
            $this->log->log($level, 'templates.' . $action, $message, $context, $ms);
        }
    }
}
