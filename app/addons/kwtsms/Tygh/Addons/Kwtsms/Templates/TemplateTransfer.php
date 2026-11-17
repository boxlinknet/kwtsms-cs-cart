<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Templates/TemplateTransfer.php
 * CREATED:   2026-11-16 23:30 +03
 * TASK:      596 (Task M3.10, B10, P3-71)
 * AUTHOR:    kwtSMS
 * WHAT:      Export of every stored template body to a JSON file, and the import of such a file in two steps: preview()
 *            (a dry run: counts new / changed / unchanged / refused, with a reason per refused row, writes nothing)
 *            and apply() (writes the rows the preview showed, each through TemplateRepository::save()).
 * WHY:       P3-71: a merchant moves the texts between a staging and a live store, or keeps a copy before a bulk edit.
 *            The file is untrusted input: it is size-capped, decoded strictly, every field is checked against a
 *            fixed shape, and a row is accepted only if the Templates page itself lists that event, receiver and
 *            storefront (so an id the add-on does not declare is refused). The file is only ever parsed as JSON; it
 *            is never executed, included or stored. Switches, conditions, senders and brand names are not in the
 *            file and never imported. The export holds event ids, receivers, storefront ids, language codes and
 *            template text only: no credentials, no phone numbers, no customer data.
 * USAGE:     $t = TemplateTransfer::fromContainer(Tygh::$app);
 *            $json = $t->export();                                  // download body
 *            $p = $t->preview($json_from_upload);   // ['rows' => [...], 'sha' => sha256, 'errors' => [codes]]
 *            $r = $t->apply($json, $sha, $keep_keys, $admin_id, $p['fingerprints']);
 *                                                // ['saved' => int, 'refused' => [rows with reason]]
 *            Row: event_id, receiver, storefront_id, lang, status (new|changed|unchanged|refused), reason,
 *            unknown (placeholders not known to the event; reason unknown_placeholders is applied only when
 *            $keep_unknown is true).
 * DEPENDS:   TemplateRepository (rowsForUi, ensureTemplate, save), TemplateChecks, TemplateRenderer, Defaults,
 *            Log\DebugLog (optional).
 */

namespace Tygh\Addons\Kwtsms\Templates;

use Tygh\Addons\Kwtsms\Log\DebugLog;

class TemplateTransfer
{
    public const FORMAT = 'kwtsms-templates';
    public const VERSION = 1;

    /** The biggest file accepted, in bytes (1 MiB). */
    public const MAX_BYTES = 1048576;

    /** The most rows one file may carry (a full store is a few hundred). */
    public const MAX_ROWS = 5000;

    /**
     * 2026-11-17 00:30 +03: Task M3.10 fix round 1 (review I2), task 596. The most rows a preview lists (every row that
     * is not "unchanged"). A file with more is refused whole (too_many_changes): apply writes only rows the admin saw.
     */
    public const MAX_SHOWN = 300;

    public const STATUS_NEW = 'new';
    public const STATUS_CHANGED = 'changed';
    public const STATUS_UNCHANGED = 'unchanged';
    public const STATUS_REFUSED = 'refused';

    private const ROW_KEYS = ['event_id', 'receiver', 'storefront_id', 'lang_code', 'body'];

    /** @var TemplateRepository */
    private $templates;

    /** @var DebugLog|null */
    private $log;

    public function __construct(TemplateRepository $templates, ?DebugLog $log = null)
    {
        $this->templates = $templates;
        $this->log = $log;
    }

    /**
     * @param \ArrayAccess<string,mixed> $app Tygh::$app
     */
    public static function fromContainer($app): self
    {
        return new self($app['kwtsms.templates'], $app['kwtsms.logs.debug']);
    }

    /**
     * 2026-11-17 07:00 +03: M3.10b item 2, task 596. A php.ini size ("8M", "512K", "1G", plain bytes) in
     * bytes; -1, 0, empty and
     * unreadable values are "no limit" (PHP_INT_MAX).
     */
    public static function iniBytes(string $value): int
    {
        $value = strtolower(trim($value));
        if ($value === '' || preg_match('/^(-?\d+)([kmg]?)$/', $value, $m) !== 1 || (int) $m[1] <= 0) {
            return PHP_INT_MAX;
        }
        $factor = ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824];

        return (int) $m[1] * $factor[$m[2]];
    }

    /**
     * 2026-11-17 07:00 +03: M3.10b item 2, task 596. The effective size limit of the import upload: the smaller of
     * post_max_size and
     * upload_max_filesize, never above MAX_BYTES. The import form's script refuses a larger file before it is sent
     * (core answers an over-size POST before the add-on guard runs, and a large upload stalls).
     */
    public static function uploadLimit(string $post_max_size, string $upload_max_filesize): int
    {
        return min(self::iniBytes($post_max_size), self::iniBytes($upload_max_filesize), self::MAX_BYTES);
    }

    /**
     * Every stored body (edited and seeded) as the JSON file.
     */
    public function export(): string
    {
        $out = [];
        // 2026-11-17 07:07 +03: M3.10b item 1 change, task 596. Only a stored row the Templates page does not
        // list for this store (event or receiver not listed, language not installed, storefront unknown) is
        // left out. A listed row stays even if its body would be refused on import: the backup holds
        // everything the admin can see.
        $languages = $this->languages();
        $storefronts = $this->storefronts();
        $ui = [];
        foreach ($this->storedRows() as $r) {
            $sf = (int) $r['storefront_id'];
            if (!isset($languages[(string) $r['lang_code']])) {
                continue;
            }
            if ($sf < 0 || ($sf > 0 && (count($storefronts) < 2 || !isset($storefronts[$sf])))) {
                continue;
            }
            if (!isset($ui[$sf])) {
                $ui[$sf] = (array) $this->templates->rowsForUi(null, $sf);
            }
            $listed = false;
            foreach ($ui[$sf] as $candidate) {
                $same = $candidate['event_id'] === (string) $r['event_id'];
                if ($same && $candidate['receiver'] === (string) $r['receiver']) {
                    $listed = true;
                    break;
                }
            }
            if (!$listed) {
                continue;
            }
            $out[] = [
                'event_id' => (string) $r['event_id'],
                'receiver' => (string) $r['receiver'],
                'storefront_id' => (int) $r['storefront_id'],
                'lang_code' => (string) $r['lang_code'],
                'body' => (string) $r['body'],
            ];
        }
        $json = json_encode(
            ['format' => self::FORMAT, 'version' => self::VERSION, 'exported_at' => time(), 'templates' => $out],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        return $json === false ? '' : $json;
    }

    /**
     * Dry run: what an import of this file would do. Writes nothing.
     *
     * 2026-11-17 00:30 +03: Task M3.10 fix round 1 (review I1), task 596. 'fingerprints' = key => sha1 of the stored
     * body of every new or changed row (and of every unknown-placeholder row) at preview time: the write set apply()
     * is limited to.
     *
     * @return array{rows:array<int,array<string,mixed>>,sha:string,errors:string[],fingerprints:array<string,string>}
     */
    public function preview(string $json): array
    {
        $ev = $this->evaluate($json);
        $rows = [];
        $fp = [];
        foreach ($ev['rows'] as $row) {
            $writable = !in_array($row['status'], [self::STATUS_UNCHANGED, self::STATUS_REFUSED], true);
            if ($writable || $row['reason'] === 'unknown_placeholders') {
                $fp[$row['key']] = $row['fp'];
            }
            unset($row['stored'], $row['fp']);
            $rows[] = $row;
        }

        return ['rows' => $rows, 'sha' => hash('sha256', $json), 'errors' => $ev['errors'], 'fingerprints' => $fp];
    }

    /**
     * Writes the new and changed rows of the file the preview was made from. Refuses the whole file when its sha256 is
     * not the one previewed. Rows are re-evaluated at write time (the store may have changed since the preview).
     *
     * 2026-11-17 00:30 +03: Task M3.10 fix round 1 (review I1, I2), task 596. $fingerprints is the preview's write set
     * (key => sha1 of the stored body then): a row not in it is skipped (not_previewed), a row whose stored body is no
     * longer that one is skipped (changed_since_preview). $keep lists the row keys whose unknown placeholders the
     * admin accepted one by one (it replaces the old single yes for every row).
     *
     * @param array<string,string> $fingerprints
     * @param string[]             $keep
     *
     * @return array{saved:int,refused:array<int,array<string,mixed>>}
     */
    public function apply(string $json, string $sha, array $keep, int $admin_id, array $fingerprints): array
    {
        $result = ['saved' => 0, 'refused' => []];
        if ($sha === '' || !hash_equals(hash('sha256', $json), $sha)) {
            $result['refused'][] = ['reason' => 'file_changed'];
            $this->note('warning', 'import.refused', ['reason' => 'file_changed', 'admin_id' => $admin_id]);

            return $result;
        }
        $ev = $this->evaluate($json);
        foreach ($ev['errors'] as $code) {
            $result['refused'][] = ['reason' => $code];
        }
        foreach ($ev['rows'] as $row) {
            $unknown_ok = $row['status'] === self::STATUS_REFUSED && $row['reason'] === 'unknown_placeholders'
                && in_array($row['key'], $keep, true);
            if (!in_array($row['status'], [self::STATUS_NEW, self::STATUS_CHANGED], true) && !$unknown_ok) {
                if ($row['status'] === self::STATUS_REFUSED) {
                    $result['refused'][] = $this->brief($row);
                }
                continue;
            }
            if (!isset($fingerprints[$row['key']])) {
                $row['reason'] = 'not_previewed';
                $result['refused'][] = $this->brief($row);
                continue;
            }
            if (!hash_equals((string) $fingerprints[$row['key']], $row['fp'])) {
                $row['reason'] = 'changed_since_preview';
                $result['refused'][] = $this->brief($row);
                continue;
            }
            $id = $this->templates->ensureTemplate($row['event_id'], $row['receiver'], $row['storefront_id']);
            $saved = $id > 0
                ? $this->templates->save($id, $row['lang'], $row['stored'], $unknown_ok)
                : ['saved' => false, 'reason' => 'not_found'];
            if ($saved['saved']) {
                $result['saved']++;
            } else {
                $row['reason'] = (string) $saved['reason'];
                $result['refused'][] = $this->brief($row);
            }
        }
        $this->note('info', 'import.applied', [
            'admin_id' => $admin_id,
            'saved' => $result['saved'],
            'refused' => count($result['refused']),
            'kept_unknown' => count($keep),
        ]);

        return $result;
    }

    // ------------------------------------------------------------------ evaluation

    /**
     * @return array{rows:array<int,array<string,mixed>>,errors:string[]}
     */
    private function evaluate(string $json): array
    {
        $none = static function (string $code): array {
            return ['rows' => [], 'errors' => [$code]];
        };
        if (strlen($json) > self::MAX_BYTES) {
            return $none('too_large');
        }
        // 2026-11-17 00:30 +03: Task M3.10 fix round 1 (M3), task 596. A leading UTF-8 BOM is dropped before
        // decoding; the sha stays on the original bytes.
        if (strncmp($json, "\xEF\xBB\xBF", 3) === 0) {
            $json = substr($json, 3);
        }
        if ($json === '' || !mb_check_encoding($json, 'UTF-8')) {
            return $none('not_json');
        }
        $data = json_decode($json, true, 6);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            return $none('not_json');
        }
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['exported_at', 'format', 'templates', 'version']) {
            return $none('bad_format');
        }
        if (
            ($data['format'] ?? null) !== self::FORMAT || ($data['version'] ?? null) !== self::VERSION
            || !is_int($data['exported_at'] ?? null) || !is_array($data['templates'] ?? null)
            || $data['templates'] !== array_values($data['templates'])
        ) {
            return $none('bad_format');
        }
        if (count($data['templates']) > self::MAX_ROWS) {
            return $none('too_many');
        }

        $languages = $this->languages();
        $storefronts = $this->storefronts();
        $ui = [];
        $seen = [];
        $rows = [];
        foreach ($data['templates'] as $raw) {
            $row = [
                'event_id' => '', 'receiver' => '', 'storefront_id' => 0, 'lang' => '',
                'status' => self::STATUS_REFUSED, 'reason' => '', 'unknown' => [], 'stored' => '',
                'key' => '', 'fp' => '',
            ];
            if (!is_array($raw)) {
                $row['reason'] = 'bad_row';
                $rows[] = $row;
                continue;
            }
            $row['event_id'] = is_string($raw['event_id'] ?? null) ? mb_substr($raw['event_id'], 0, 100, 'UTF-8') : '';
            $row['receiver'] = is_string($raw['receiver'] ?? null) ? mb_substr($raw['receiver'], 0, 4, 'UTF-8') : '';
            $row['storefront_id'] = is_int($raw['storefront_id'] ?? null) ? $raw['storefront_id'] : -1;
            $row['lang'] = is_string($raw['lang_code'] ?? null) ? mb_substr($raw['lang_code'], 0, 8, 'UTF-8') : '';
            $row['key'] = $row['event_id'] . '|' . $row['receiver'] . '|' . $row['storefront_id'] . '|' . $row['lang'];
            $row['reason'] = $this->checkRow($raw, $languages, $storefronts, $ui, $seen, $row);
            $rows[] = $row;
        }

        $shown = 0;
        foreach ($rows as $r) {
            if ($r['status'] !== self::STATUS_UNCHANGED) {
                $shown++;
            }
        }
        if ($shown > self::MAX_SHOWN) {
            return $none('too_many_changes');
        }

        return ['rows' => $rows, 'errors' => []];
    }

    /**
     * Checks one row; fills $row (status, stored, unknown). Returns the refusal reason or ''.
     *
     * @param array<string,mixed>        $raw
     * @param array<string,mixed>        $languages
     * @param array<int,string>          $storefronts
     * @param array<int,array<int,array<string,mixed>>> $ui
     * @param array<string,bool>         $seen
     * @param array<string,mixed>        $row
     */
    private function checkRow(
        array $raw,
        array $languages,
        array $storefronts,
        array &$ui,
        array &$seen,
        array &$row
    ): string {
        $fail = static function (array &$row, string $reason): string {
            $row['status'] = self::STATUS_REFUSED;

            return $reason;
        };
        $keys = array_keys($raw);
        if (array_diff($keys, self::ROW_KEYS) !== [] || array_diff(self::ROW_KEYS, $keys) !== []) {
            return $fail($row, 'bad_row');
        }
        if (!is_string($raw['body']) || !is_string($raw['event_id']) || !is_string($raw['receiver'])) {
            return $fail($row, 'bad_row');
        }
        $event_ok = preg_match('/^[a-z0-9_.]{1,100}$/', $raw['event_id']) === 1;
        if (!$event_ok || preg_match('/^[ACV]$/', $raw['receiver']) !== 1) {
            return $fail($row, 'unknown_row');
        }
        if (preg_match('/^[a-z]{2}$/', $row['lang']) !== 1 || !isset($languages[$row['lang']])) {
            return $fail($row, 'unknown_language');
        }
        $sf = $row['storefront_id'];
        if ($sf < 0 || ($sf > 0 && (count($storefronts) < 2 || !isset($storefronts[$sf])))) {
            return $fail($row, 'unknown_storefront');
        }
        $key = $raw['event_id'] . '|' . $raw['receiver'] . '|' . $sf . '|' . $row['lang'];
        if (isset($seen[$key])) {
            return $fail($row, 'duplicate');
        }
        $seen[$key] = true;

        if (!isset($ui[$sf])) {
            $ui[$sf] = (array) $this->templates->rowsForUi(null, $sf);
        }
        $listed = null;
        foreach ($ui[$sf] as $candidate) {
            if ($candidate['event_id'] === $raw['event_id'] && $candidate['receiver'] === $raw['receiver']) {
                $listed = $candidate;
                break;
            }
        }
        if ($listed === null) {
            return $fail($row, 'unknown_row');
        }

        if (mb_strlen($raw['body'], 'UTF-8') > TemplateRepository::MAX_BODY_CHARS) {
            return $fail($row, 'too_long');
        }
        $stored = TemplateRepository::storedForm($raw['body']);
        $row['stored'] = $stored;
        if ($stored === '') {
            return $fail($row, 'empty');
        }
        $warnings = TemplateChecks::analyze($stored, $raw['receiver'], $raw['event_id']);
        $refusals = [
            TemplateChecks::CODE_TEMPLATE => 'code_template',
            TemplateChecks::CODE_STORE_NAME => 'code_store_name',
            TemplateChecks::STORE_NAME_MISSING => 'store_name',
        ];
        foreach ($refusals as $code => $reason) {
            if (isset($warnings[$code])) {
                return $fail($row, $reason);
            }
        }

        $current = isset($listed['bodies'][$row['lang']]['body'])
            ? (string) $listed['bodies'][$row['lang']]['body'] : null;
        $row['fp'] = sha1((string) $current);
        if ($current === $stored) {
            $row['status'] = self::STATUS_UNCHANGED;

            return '';
        }
        $row['unknown'] = TemplateRenderer::unknownPlaceholders(
            $stored,
            Defaults::allowedPlaceholders($raw['event_id'], $raw['receiver'])
        );
        if ($row['unknown'] !== []) {
            return $fail($row, 'unknown_placeholders');
        }
        $row['status'] = $current === null || $current === '' ? self::STATUS_NEW : self::STATUS_CHANGED;

        return '';
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function brief(array $row): array
    {
        return [
            'event_id' => $row['event_id'],
            'receiver' => $row['receiver'],
            'storefront_id' => $row['storefront_id'],
            'lang' => $row['lang'],
            'key' => $row['key'],
            'reason' => $row['reason'],
        ];
    }

    // ------------------------------------------------------------------ seams (overridden by unit tests)

    /**
     * @return array<int,array<string,mixed>> event_id, receiver, storefront_id, lang_code, body
     */
    protected function storedRows(): array
    {
        return (array) db_get_array(
            'SELECT t.event_id, t.receiver, t.storefront_id, d.lang_code, d.body'
            . ' FROM ?:kwtsms_templates AS t'
            . ' INNER JOIN ?:kwtsms_template_descriptions AS d ON d.template_id = t.template_id'
            . " WHERE d.body <> '' ORDER BY t.event_id, t.receiver, t.storefront_id, d.lang_code"
        );
    }

    /**
     * @return array<string,mixed> code => language (the installed languages)
     */
    protected function languages(): array
    {
        $out = [];
        foreach ((array) \Tygh\Languages\Languages::getAll() as $code => $language) {
            if (in_array($language['status'] ?? 'A', ['A', 'H'], true)) {
                $out[(string) $code] = true;
            }
        }

        return $out;
    }

    /**
     * @return array<int,string> storefront id => name
     */
    protected function storefronts(): array
    {
        try {
            $found = \Tygh\Tygh::$app['storefront.repository']->find();
            $out = [];
            foreach ((array) ($found[0] ?? []) as $storefront) {
                $out[(int) $storefront->storefront_id] = (string) $storefront->name;
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function note(string $level, string $action, array $context): void
    {
        if ($this->log !== null) {
            $this->log->log($level, 'templates.' . $action, 'Templates transfer', $context);
        }
    }
}
