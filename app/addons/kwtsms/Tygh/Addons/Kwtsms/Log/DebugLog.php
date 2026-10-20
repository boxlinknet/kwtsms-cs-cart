<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Log/DebugLog.php
 * CREATED:   2026-10-20 21:35 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The add-on's debug and diagnostics log (table kwtsms_debug_log): level filter, credential
 *            redaction, JSON context, one request id per request, the acting admin.
 * WHY:       PRD 11: every public method logs entry and exit at debug, main actions at info, errors at error;
 *            with the setting log_level = info the debug rows are dropped. PRD 10: credentials (saved or just
 *            typed) never appear in any log in any form, so message, action and context all pass through
 *            Redactor before the row is built (2026-10-20 21:39 +03: the second pass over the encoded JSON was
 *            removed, a mutation test showed it never changed a result, task 596). A logger must never
 *            break the page it runs on: any failure is swallowed and reported to the PHP error log.
 * USAGE:     $log = new DebugLog($store, $config, $actorIdProvider);   (wired in Task 1.6)
 *            $log->log('info', 'gateway.login', 'Logged in', ['balance' => 150.0], 312);   // row id or null
 *            $log->log('debug', 'sms.send.enter', 'send()', ['recipients' => 2]);          // dropped at info
 *            if ($log->isEnabled('debug')) { ...build an expensive context... }
 *            $log->addSecret($candidatePassword);   // an unsaved value to keep out of the log
 *            Levels: debug, info, warning, error (case-insensitive; anything else is info). The setting
 *            log_level is info (default) or debug; anything else behaves like info.
 * DEPENDS:   Log\LogStoreInterface, Log\Redactor, Log\RowCleaner, Log\Schema, Settings\ConfigInterface.
 */

namespace Tygh\Addons\Kwtsms\Log;

use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

class DebugLog
{
    use ReportsFailures;

    private const LEVELS = ['debug' => 100, 'info' => 200, 'warning' => 300, 'error' => 400];

    /** The context column is text (64 KB): a longer JSON is replaced by a small marker. */
    private const CONTEXT_LIMIT = 60000;

    /** @var LogStoreInterface */
    private $store;

    /** @var ConfigInterface */
    private $config;

    /** @var callable|null returns the acting user id */
    private $actor;

    /** @var callable|null returns the current Unix time */
    private $clock;

    /** @var string */
    private $request_id;

    /** @var string[] */
    private $candidates = [];

    /**
     * @param callable|null $actor Returns the id of the user doing the request (0 = none); null = always 0
     * @param callable|null $clock Returns the current Unix time (tests); null = time()
     */
    public function __construct(
        LogStoreInterface $store,
        ConfigInterface $config,
        ?callable $actor = null,
        ?callable $clock = null
    ) {
        $this->store = $store;
        $this->config = $config;
        $this->actor = $actor;
        $this->clock = $clock;
        $this->request_id = bin2hex(random_bytes(8));
    }

    /**
     * Registers a value that is not saved yet (a password being tried) so it is kept out of every row.
     */
    public function addSecret(
        #[\SensitiveParameter]
        string $secret
    ): void {
        if ($secret !== '') {
            $this->candidates[] = $secret;
        }
    }

    /**
     * 2026-10-20 22:20 +03: what var_dump() and print_r() show (task 596, Task 1.4 fix round 1). The candidate
     * secrets, the configuration (credentials) and the store never appear, only how many candidates exist.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'request_id' => $this->request_id,
            'candidates' => count($this->candidates) . ' (values hidden)',
        ];
    }

    /**
     * Whether a row of this level would be stored (callers skip building expensive contexts).
     */
    public function isEnabled(string $level): bool
    {
        $debug_on = strtolower(trim($this->config->logLevel())) === 'debug';

        return self::LEVELS[$this->level($level)] >= self::LEVELS[$debug_on ? 'debug' : 'info'];
    }

    /**
     * @param array<mixed> $context Secrets and credential keys are removed before it is encoded
     *
     * @return int|null The row id, or null when the level is below the setting or the write failed
     */
    public function log(string $level, string $action, string $message, array $context = [], ?int $ms = null): ?int
    {
        try {
            $level = $this->level($level);
            if (!$this->isEnabled($level)) {
                return null;
            }
            $secrets = array_merge([$this->config->apiUsername(), $this->config->password()], $this->candidates);

            return $this->store->insert(Schema::DEBUG, RowCleaner::clean(Schema::DEBUG, [
                'created_at' => $this->now(),
                'level' => $level,
                'action' => Redactor::redactString($action, $secrets),
                'message' => Redactor::redactString($message, $secrets),
                'context' => $this->encode(Redactor::redact($context, $secrets)),
                'duration_ms' => max(0, (int) $ms),
                'request_id' => $this->request_id,
                'user_id' => $this->actor === null ? 0 : (int) call_user_func($this->actor),
            ]));
        } catch (\Throwable $e) {
            $this->reportFailure($e);

            return null;
        }
    }

    private function level(string $level): string
    {
        $level = strtolower(trim($level));

        return isset(self::LEVELS[$level]) ? $level : 'info';
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }

    /**
     * @param array<mixed> $context Already redacted: json_encode() only changes how a string is written, and
     *                              a secret in any written form was replaced before
     */
    private function encode(array $context): string
    {
        if ($context === []) {
            return '';
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
            | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = json_encode($context, $flags);
        if ($json === false) {
            return '{"_unencodable":true}';
        }

        return strlen($json) > self::CONTEXT_LIMIT
            ? (string) json_encode(['_truncated' => true, '_bytes' => strlen($json)])
            : $json;
    }
}
