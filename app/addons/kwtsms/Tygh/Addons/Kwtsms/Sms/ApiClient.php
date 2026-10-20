<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Sms/ApiClient.php
 * CREATED:   2026-10-20 22:35 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The kwtSMS REST client: balance(), senderIds(), coverage(), validate(), send(). Builds each JSON
 *            request, runs it through the request hook, the global rate slot and the hardened transport, and
 *            turns the reply (or the failure) into one array shape with a code and its source.
 * WHY:       PRD 6.1, 6.2 steps 12-14, 10 and Appendix A. Everything that talks to kwtSMS goes through this
 *            class, so the rules live in one place: credentials only in the JSON body and never anywhere
 *            else; "test" always the string "1" or "0"; kwtSMS answers some errors with HTTP 403 or 406 and
 *            a JSON body, so the body is parsed whatever the status; a body that is not JSON keeps its raw
 *            text (64 KB) and any ERRnnn found in it; failures that never reached the gateway are LOCAL_*
 *            codes with code_source = local; chunks of at most 200 numbers and 2500 characters with a
 *            200 ms pause between them; nothing here ever throws to the caller.
 * USAGE:     $client = new ApiClient($user, $pass, new HttpTransport(), $guard, $debugLog, 'web');
 *            // context: web (connect 3 s, total 8 s), admin (5 / 20), cli (5 / 30); anything else is web.
 *            $r = $client->balance();     // ['result'=>'OK','available'=>150.0,'purchased'=>1000.0, ...meta]
 *            $r = $client->senderIds();   // ['senderids'=>['KWT-SMS', ...]]
 *            $r = $client->coverage();    // ['prefixes'=>['965', ...]]
 *            $r = $client->validate(['96598765432', '123']);
 *                // ['mobile'=>['OK'=>[], 'ER'=>[], 'NR'=>[]], 'calls'=>[...]]
 *            $r = $client->send('MyShop', $numbers, 'Hello', true);   // aggregate over the chunks, see below
 *            Meta on every result: result (OK|ERROR, a send or validate over several chunks may be PARTIAL),
 *            code, code_source (api|local), description (the server's, or a short English diagnostic for a
 *            local code; the admin text is ApiErrors::describe($code)), endpoint, http_status, sent (the
 *            request may have reached the gateway), ms, started_ms, ended_ms, raw_request (the JSON without
 *            username and password), raw_response (at most 64 KB, valid UTF-8), raw_response_invalid_utf8.
 *            send(): 'calls' holds one entry per chunk (the meta above plus chunk, recipients,
 *            recipient_count, test, msg-id, numbers, points-charged, balance-after and unix-timestamp);
 *            the top level adds msg-ids, msg-id (the first), numbers and
 *            points-charged (sums), balance-after (the last). A chunk after a failed one is not sent: it is
 *            LOCAL_SKIPPED with skipped_because = the code that stopped the send. Only ERR006, ERR025 and
 *            ERR026 (a problem with that chunk's numbers) and LOCAL_CANCELLED (the hook is asked again for
 *            each chunk) let the next chunk go.
 *            unix-timestamp is returned as received. 2026-10-20 22:45 +03: the vendor notes say it is GMT+3
 *            server time; two live sends (test="1") showed the value equal to the UTC epoch to the second, so
 *            it is NOT shifted here (tests/Live/ApiClientTest.php asserts it against time() and fails if that
 *            ever changes), task 596.
 *            Request hook (ruling R1): before EVERY HTTP call, fn_set_hook('kwtsms_api_request_pre',
 *            $endpoint, $guard_view, $cancel) with $endpoint one of balance, senderid, coverage, validate,
 *            send and $guard_view the payload without username and password (send carries test). A handler
 *            that sets $cancel, or throws, stops the call: LOCAL_CANCELLED, no HTTP. The payload that is sent
 *            is the client's own, a handler cannot edit it. Where fn_set_hook() does not exist (a pure unit
 *            context) the constructor's $request_guard callable is asked instead (it must return exactly
 *            true); with neither, the client refuses to send (LOCAL_NO_GUARD).
 *            Test seams (protected): hookExists(), runHook(), pause(), baseUrl().
 * DEPENDS:   Http\HttpTransport, Sms\ApiGuard, Log\DebugLog, ext-json, ext-mbstring.
 */

namespace Tygh\Addons\Kwtsms\Sms;

use Tygh\Addons\Kwtsms\Http\HttpTransport;
use Tygh\Addons\Kwtsms\Log\DebugLog;

class ApiClient
{
    /** The only base URL. There is no setting and no constant to change it (PRD 10). */
    public const BASE_URL = 'https://www.kwtsms.com/API/';

    public const MAX_NUMBERS = 200;

    /** Longest comma-joined "mobile" value of one call. */
    public const MAX_MOBILE_CHARS = 2500;

    /** Longest raw reply kept (bytes). */
    public const RAW_LIMIT = 65536;

    /** Pause between two chunks of one send, in microseconds. */
    public const PAUSE_US = 200000;

    /** The five endpoints; the first four are read-only. */
    public const ENDPOINTS = ['balance', 'senderid', 'coverage', 'validate', 'send'];

    /** Connect and total timeout in seconds by context. */
    private const TIMEOUTS = ['web' => [3, 8], 'admin' => [5, 20], 'cli' => [5, 30]];

    /**
     * Errors about one chunk only: the next chunk may still go. ERR006, ERR025 and ERR026 are about that
     * chunk's numbers; LOCAL_CANCELLED is a hook handler's verdict on one call (it is asked again for the
     * next chunk). 2026-10-20 22:38 +03: LOCAL_CANCELLED added so the test suite can observe every chunk
     * through the hook and cancel each one (zero HTTP), task 596.
     */
    private const KEEP_GOING = ['ERR006', 'ERR025', 'ERR026', 'LOCAL_CANCELLED'];

    /** Longest description kept (bytes). */
    private const DESCRIPTION_LIMIT = 500;

    /** json_encode flags: no JSON_HEX_*, slashes as they are, non-ASCII as \uXXXX (ASCII on the wire). */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES;

    /** Short English diagnostics for the codes raised here (the admin text is ApiErrors::describe). */
    private const LOCAL_TEXT = [
        'LOCAL_DNS' => 'DNS lookup failed',
        'LOCAL_CONNECT' => 'Could not connect',
        'LOCAL_TLS' => 'TLS handshake or certificate verification failed',
        'LOCAL_TIMEOUT' => 'No complete reply in time after the request was sent',
        'LOCAL_BAD_JSON' => 'The reply is not a usable JSON result',
        'LOCAL_HTTP' => 'HTTP status without a usable reply',
        'LOCAL_CANCELLED' => 'Cancelled by a kwtsms_api_request_pre handler',
        'LOCAL_BAD_INPUT' => 'Request refused before sending',
        'LOCAL_BREAKER' => 'Circuit breaker is open',
        'LOCAL_DEFERRED' => 'An earlier call in this request could not reach the gateway',
        'LOCAL_BUSY' => 'The global request slot lock was not available',
        'LOCAL_NO_GUARD' => 'No request hook and no guard callable',
        'LOCAL_REFUSED' => 'Host not allowed',
        'LOCAL_SKIPPED' => 'Not attempted after an earlier failure',
        'LOCAL_ERROR' => 'Unexpected error',
    ];

    /** Transport error category to code. */
    private const TRANSPORT_CODES = [
        'dns' => 'LOCAL_DNS',
        'connect' => 'LOCAL_CONNECT',
        'tls' => 'LOCAL_TLS',
        'timeout' => 'LOCAL_TIMEOUT',
    ];

    /** @var string */
    private $user;

    /** @var string */
    private $pass;

    /** @var HttpTransport */
    private $transport;

    /** @var ApiGuard */
    private $guard;

    /** @var DebugLog */
    private $log;

    /** @var string web|admin|cli */
    private $context;

    /** @var callable|null */
    private $request_guard;

    /**
     * @param string        $user          API username (not the account mobile number)
     * @param string        $pass          API password
     * @param string        $context       web, admin or cli: picks the timeouts; anything else is web
     * @param callable|null $request_guard Used only where fn_set_hook() does not exist: ($endpoint, $guard_view),
     *                                     must return exactly true to let the request go
     */
    public function __construct(
        string $user,
        #[\SensitiveParameter]
        string $pass,
        HttpTransport $transport,
        ApiGuard $guard,
        DebugLog $log,
        string $context,
        ?callable $request_guard = null
    ) {
        $this->user = $user;
        $this->pass = $pass;
        $this->transport = $transport;
        $this->guard = $guard;
        $this->log = $log;
        $this->context = isset(self::TIMEOUTS[$context]) ? $context : 'web';
        $this->request_guard = $request_guard;

        // Whatever a log line or a context array holds, these two never get in (PRD 10).
        $this->log->addSecret($pass);
        $this->log->addSecret($user);
    }

    // ------------------------------------------------------------------ public calls

    /**
     * POST /balance/.
     *
     * @return array<string,mixed> Meta plus available (float) and purchased (?float)
     */
    public function balance(): array
    {
        return $this->single('balance');
    }

    /**
     * POST /senderid/.
     *
     * @return array<string,mixed> Meta plus senderids (string[])
     */
    public function senderIds(): array
    {
        return $this->single('senderid');
    }

    /**
     * POST /coverage/.
     *
     * @return array<string,mixed> Meta plus prefixes (string[])
     */
    public function coverage(): array
    {
        return $this->single('coverage');
    }

    /**
     * POST /validate/ (in chunks of 200 numbers when there are more).
     *
     * @param array<int,mixed> $numbers Strings to check, as typed (no commas)
     *
     * @return array<string,mixed> Meta plus mobile (OK, ER, NR lists) and calls
     */
    public function validate(array $numbers): array
    {
        $started = $this->nowMs();
        $numbers = array_values($numbers);
        $this->note('debug', 'validate.enter', 'validate()', ['count' => count($numbers)]);

        $clean = [];
        foreach ($numbers as $number) {
            if (!is_string($number) || preg_match('/^[\x21-\x2B\x2D-\x7E]{1,30}$/D', $number) !== 1) {
                return $this->finishAggregate('validate', [], 'LOCAL_BAD_INPUT', $started);
            }
            $clean[] = $number;
        }
        if ($clean === []) {
            return $this->finishAggregate('validate', [], 'LOCAL_BAD_INPUT', $started);
        }

        $calls = $this->runChunks('validate', self::chunk($clean), function (array $chunk): array {
            return ['mobile' => implode(',', $chunk)];
        });
        $result = $this->aggregate('validate', $calls, $started);

        $merged = ['OK' => [], 'ER' => [], 'NR' => []];
        foreach ($calls as $call) {
            foreach ($merged as $group => $list) {
                $merged[$group] = array_merge($merged[$group], $call['mobile'][$group] ?? []);
            }
        }
        $result['mobile'] = $merged;
        $this->note('debug', 'validate.exit', (string) $result['result'], $this->summary($result), $result['ms']);

        return $result;
    }

    /**
     * POST /send/ in chunks of at most 200 numbers and 2500 characters of "mobile".
     *
     * @param array<int,mixed> $numbers Normalized numbers, digits only (1 to 15)
     * @param bool             $test    true sends "1" (queued, not delivered), false sends "0" (a real SMS)
     *
     * @return array<string,mixed> Aggregate over the chunks, see the file header
     */
    public function send(string $sender, array $numbers, string $message, bool $test): array
    {
        $started = $this->nowMs();
        $numbers = array_values($numbers);
        $this->note('debug', 'send.enter', 'send()', [
            'sender' => $sender,
            'recipients' => count($numbers),
            'message_length' => strlen($message),
            'test' => $test ? '1' : '0',
        ]);

        $clean = [];
        foreach ($numbers as $number) {
            if (is_int($number)) {
                $number = (string) $number;
            }
            if (!is_string($number) || preg_match('/^[0-9]{1,15}$/D', $number) !== 1) {
                return $this->finishAggregate('send', [], 'LOCAL_BAD_INPUT', $started);
            }
            $clean[] = $number;
        }
        if ($clean === [] || preg_match('/^[\x20-\x7E]{1,30}$/D', $sender) !== 1) {
            return $this->finishAggregate('send', [], 'LOCAL_BAD_INPUT', $started);
        }

        $flag = $test ? '1' : '0';
        $payload_for = function (array $chunk) use ($sender, $message, $flag): array {
            return ['sender' => $sender, 'mobile' => implode(',', $chunk), 'message' => $message, 'test' => $flag];
        };
        $calls = $this->runChunks('send', self::chunk($clean), $payload_for);
        foreach ($calls as $i => $call) {
            $calls[$i]['test'] = $flag;
        }

        $result = $this->aggregate('send', $calls, $started);
        $result['test'] = $flag;
        $this->note('info', 'send.exit', (string) $result['result'], $this->summary($result), $result['ms']);

        return $result;
    }

    /**
     * Splits numbers into calls of at most 200 numbers and 2500 characters of comma-joined text, in order,
     * each call as full as it can be.
     *
     * @param array<int,mixed> $numbers
     *
     * @return array<int,array<int,string>>
     */
    public static function chunk(array $numbers): array
    {
        $chunks = [];
        $current = [];
        $length = 0;
        foreach ($numbers as $number) {
            $number = (string) $number;
            $add = strlen($number) + ($current === [] ? 0 : 1);
            if ($current !== [] && (count($current) >= self::MAX_NUMBERS || $length + $add > self::MAX_MOBILE_CHARS)) {
                $chunks[] = $current;
                $current = [];
                $length = 0;
                $add = strlen($number);
            }
            $current[] = $number;
            $length += $add;
        }
        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Never shows the credentials (PRD 10).
     *
     * @return array<string,string>
     */
    public function __debugInfo(): array
    {
        return ['context' => $this->context, 'user' => '[redacted]', 'pass' => '[redacted]', 'base' => self::BASE_URL];
    }

    /**
     * @return array<mixed>
     *
     * @throws \LogicException Always: serializing would write the credentials out
     */
    public function __serialize(): array
    {
        throw new \LogicException('ApiClient cannot be serialized');
    }

    /**
     * @param array<mixed> $data
     *
     * @throws \LogicException Always
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('ApiClient cannot be unserialized');
    }

    // ------------------------------------------------------------------ test seams

    /** Whether CS-Cart's hook function exists (it always does in a store). */
    protected function hookExists(): bool
    {
        return function_exists('fn_set_hook');
    }

    /**
     * Fires kwtsms_api_request_pre. fn_set_hook() takes its arguments by reference, hence variables.
     *
     * @param string               $endpoint
     * @param array<string,mixed>  $view
     * @param mixed                $cancel
     */
    protected function runHook(string &$endpoint, array &$view, &$cancel): void
    {
        fn_set_hook('kwtsms_api_request_pre', $endpoint, $view, $cancel);
    }

    /** The pause between two chunks of one send. */
    protected function pause(): void
    {
        usleep(self::PAUSE_US);
    }

    /** The URL prefix of all endpoints. */
    protected function baseUrl(): string
    {
        return self::BASE_URL;
    }

    // ------------------------------------------------------------------ one call

    /**
     * balance, senderid, coverage: one call with an empty payload, logged around.
     *
     * @return array<string,mixed>
     */
    private function single(string $endpoint): array
    {
        $this->note('debug', $endpoint . '.enter', $endpoint . '()');
        $result = $this->request($endpoint, []);
        $this->note('debug', $endpoint . '.exit', (string) $result['result'], $this->summary($result), $result['ms']);

        return $result;
    }

    /**
     * Runs one HTTP call end to end: encode, hook, slot, transport, reply. Never throws.
     *
     * @param array<string,mixed> $payload The request without username and password
     *
     * @return array<string,mixed> Meta plus the endpoint's own fields
     */
    private function request(string $endpoint, array $payload): array
    {
        $started = $this->nowMs();
        $raw_request = '';
        try {
            $encoded = json_encode($payload, self::JSON_FLAGS);
            $json = json_encode(['username' => $this->user, 'password' => $this->pass] + $payload, self::JSON_FLAGS);
            if ($encoded === false || $json === false) {
                return $this->local($endpoint, 'LOCAL_BAD_INPUT', $started, '');
            }
            $raw_request = $encoded === '[]' ? '{}' : $encoded;

            $blocked = $this->guard->blockReason();
            if ($blocked !== null) {
                return $this->local($endpoint, $blocked, $started, $raw_request);
            }
            $refused = $this->hookVerdict($endpoint, $payload);
            if ($refused !== null) {
                return $this->local($endpoint, $refused, $started, $raw_request);
            }
            try {
                $this->guard->acquire();
            } catch (ApiGuardException $e) {
                return $this->local($endpoint, $e->apiCode(), $started, $raw_request);
            }

            [$connect, $total] = self::TIMEOUTS[$this->context];
            try {
                $reply = $this->transport->post($this->baseUrl() . $endpoint . '/', $json, $connect, $total);
            } catch (\InvalidArgumentException $e) {
                $this->noteThrowable('transport_refused', $e);

                return $this->local($endpoint, 'LOCAL_REFUSED', $started, $raw_request);
            }
        } catch (\Throwable $e) {
            $this->noteThrowable('request_failed', $e);

            return $this->local($endpoint, 'LOCAL_ERROR', $started, $raw_request);
        }

        // From here the request may have reached the gateway: nothing below may lose the reply.
        return $this->afterTransport($endpoint, $reply, $started, $raw_request);
    }

    /**
     * Turns the transport's result into the result array and updates the breaker.
     *
     * @param array{http_status:int, body:string, error:?string, sent:bool, ms:int} $reply
     *
     * @return array<string,mixed>
     */
    private function afterTransport(string $endpoint, array $reply, int $started, string $raw_request): array
    {
        if ($reply['error'] !== null) {
            $code = self::TRANSPORT_CODES[$reply['error']] ?? 'LOCAL_CONNECT';
            $this->guardCall(function (): void {
                $this->guard->recordTransportFailure();
            });
            $this->note('warning', 'transport_failed', $code, [
                'endpoint' => $endpoint,
                'error' => $reply['error'],
                'sent' => $reply['sent'],
                'detail' => $this->transport->lastError(),
            ], $reply['ms']);

            return $this->local($endpoint, $code, $started, $raw_request, (bool) $reply['sent'], (int) $reply['ms']);
        }

        $this->guardCall(function (): void {
            $this->guard->recordReached();
        });

        try {
            return $this->interpret($endpoint, $reply, $started, $raw_request);
        } catch (\Throwable $e) {
            // The parser is defensive; if it ever fails the body is still handed over, as unreadable.
            $this->noteThrowable('parse_failed', $e);

            return $this->local(
                $endpoint,
                'LOCAL_BAD_JSON',
                $started,
                $raw_request,
                true,
                (int) $reply['ms'],
                (int) $reply['http_status']
            );
        }
    }

    /**
     * @param array{http_status:int, body:string, error:?string, sent:bool, ms:int} $reply
     *
     * @return array<string,mixed>
     */
    private function interpret(string $endpoint, array $reply, int $started, string $raw_request): array
    {
        $body = $reply['body'];
        $status = (int) $reply['http_status'];

        $valid = $body === '' || mb_check_encoding($body, 'UTF-8');
        $raw = $valid ? $body : $this->substituteUtf8($body);
        if (strlen($raw) > self::RAW_LIMIT) {
            $raw = (string) mb_strcut($raw, 0, self::RAW_LIMIT, 'UTF-8');
        }

        $json = strncmp($body, "\xEF\xBB\xBF", 3) === 0 ? substr($body, 3) : $body;
        $decoded = json_decode($json, true, 64, JSON_INVALID_UTF8_SUBSTITUTE);
        $object = is_array($decoded) && array_key_exists('result', $decoded) ? $decoded : null;

        $meta = [
            'endpoint' => $endpoint,
            'http_status' => $status,
            'sent' => true,
            'ms' => (int) $reply['ms'],
            'started_ms' => $started,
            'ended_ms' => $this->nowMs(),
            'raw_request' => $raw_request,
            'raw_response' => $raw,
            'raw_response_invalid_utf8' => !$valid,
        ];

        if ($object !== null && $object['result'] === 'OK') {
            $fields = $this->shape($endpoint, $object);
            if ($fields !== null) {
                return ['result' => 'OK', 'code' => null, 'code_source' => null, 'description' => ''] + $meta + $fields;
            }

            $text = 'The reply has no usable ' . $endpoint . ' data';

            return $this->errorResult($meta, 'LOCAL_BAD_JSON', 'local', $text);
        }

        [$code, $source] = $this->errorCode($object, $body, $status);
        $description = $object !== null ? $this->cleanDescription($object['description'] ?? null) : '';
        if ($source === 'local' && $description === '') {
            $description = $code === 'LOCAL_HTTP'
                ? 'HTTP ' . $status . ' without a usable reply'
                : self::LOCAL_TEXT[$code];
        }

        return $this->errorResult($meta, $code, $source, $description);
    }

    /**
     * @param array<string,mixed> $meta
     *
     * @return array<string,mixed>
     */
    private function errorResult(array $meta, string $code, string $source, string $description): array
    {
        return ['result' => 'ERROR', 'code' => $code, 'code_source' => $source, 'description' => $description] + $meta;
    }

    /**
     * The error code of a reply that is not an OK result: the server's own code when it looks like ERRnnn,
     * else the first ERRnnn anywhere in the body, else LOCAL_HTTP (non-2xx) or LOCAL_BAD_JSON.
     *
     * @param array<string,mixed>|null $object
     *
     * @return array{0:string,1:string} code, source
     */
    private function errorCode(?array $object, string $body, int $status): array
    {
        if ($object !== null && isset($object['code']) && is_string($object['code'])) {
            $code = strtoupper(trim($object['code']));
            if (preg_match('/^ERR[0-9]{3}$/D', $code) === 1) {
                return [$code, 'api'];
            }
        }
        if (preg_match('/ERR[0-9]{3}/i', $body, $m) === 1) {
            return [strtoupper($m[0]), 'api'];
        }

        return [$status < 200 || $status > 299 ? 'LOCAL_HTTP' : 'LOCAL_BAD_JSON', 'local'];
    }

    /**
     * The endpoint-specific fields of an OK reply, or null when a required one is missing or unusable
     * (read-only endpoints are strict; a send is never turned into an error, so it is never retried by
     * mistake).
     *
     * @param array<string,mixed> $reply
     *
     * @return array<string,mixed>|null
     */
    private function shape(string $endpoint, array $reply): ?array
    {
        switch ($endpoint) {
            case 'balance':
                $available = $this->toFloat($reply['available'] ?? null);

                if ($available === null) {
                    return null;
                }

                return ['available' => $available, 'purchased' => $this->toFloat($reply['purchased'] ?? null)];

            case 'senderid':
                $list = $this->toStringList($reply['senderid'] ?? null);

                return $list === null ? null : ['senderids' => $list];

            case 'coverage':
                $list = $this->toStringList($reply['prefixes'] ?? ($reply['list'] ?? null));

                return $list === null ? null : ['prefixes' => $list];

            case 'validate':
                if (!isset($reply['mobile']) || !is_array($reply['mobile'])) {
                    return null;
                }
                $groups = [];
                foreach (['OK', 'ER', 'NR'] as $group) {
                    $groups[$group] = $this->toStringList($reply['mobile'][$group] ?? []) ?? [];
                }

                return ['mobile' => $groups];

            default: // send
                $id = $reply['msg-id'] ?? null;
                $stamp = $this->toFloat($reply['unix-timestamp'] ?? null);

                return [
                    'msg-id' => (is_string($id) || is_int($id)) && (string) $id !== '' ? (string) $id : null,
                    'numbers' => (int) ($this->toFloat($reply['numbers'] ?? null) ?? 0),
                    'points-charged' => $this->toFloat($reply['points-charged'] ?? null),
                    'balance-after' => $this->toFloat($reply['balance-after'] ?? null),
                    'unix-timestamp' => $stamp === null ? null : (int) $stamp,
                ];
        }
    }

    /**
     * A result for a call that never produced a gateway reply.
     *
     * @return array<string,mixed>
     */
    private function local(
        string $endpoint,
        string $code,
        int $started,
        string $raw_request,
        bool $sent = false,
        int $ms = 0,
        int $http_status = 0
    ): array {
        $text = self::LOCAL_TEXT[$code] ?? self::LOCAL_TEXT['LOCAL_ERROR'];
        if (!isset(self::LOCAL_TEXT[$code])) {
            $code = 'LOCAL_ERROR';
        }

        return [
            'result' => 'ERROR',
            'code' => $code,
            'code_source' => 'local',
            'description' => $text,
            'endpoint' => $endpoint,
            'http_status' => $http_status,
            'sent' => $sent,
            'ms' => $ms,
            'started_ms' => $started,
            'ended_ms' => $this->nowMs(),
            'raw_request' => $raw_request,
            'raw_response' => '',
            'raw_response_invalid_utf8' => false,
        ];
    }

    // ------------------------------------------------------------------ the request hook

    /**
     * Asks the request hook (or the guard callable) whether this call may go out.
     *
     * @param array<string,mixed> $payload
     *
     * @return string|null null = go ahead, else the LOCAL_* code
     */
    private function hookVerdict(string $endpoint, array $payload): ?string
    {
        if ($this->hookExists()) {
            $cancel = false;
            $hook_endpoint = $endpoint;
            $view = $payload;
            try {
                $this->runHook($hook_endpoint, $view, $cancel);
            } catch (\Throwable $e) {
                $this->noteThrowable('hook_failed', $e);

                return 'LOCAL_CANCELLED';
            }

            return $cancel ? 'LOCAL_CANCELLED' : null;
        }

        if ($this->request_guard === null) {
            return 'LOCAL_NO_GUARD';
        }
        try {
            $allowed = call_user_func($this->request_guard, $endpoint, $payload);
        } catch (\Throwable $e) {
            $this->noteThrowable('guard_failed', $e);

            return 'LOCAL_CANCELLED';
        }

        return $allowed === true ? null : 'LOCAL_CANCELLED';
    }

    // ------------------------------------------------------------------ chunks

    /**
     * Sends the chunks one after the other. After a failure that is not about that chunk's own numbers the
     * rest are not sent: they are LOCAL_SKIPPED with skipped_because.
     *
     * @param array<int,array<int,string>> $chunks
     * @param callable                     $payload_for ($chunk): array payload without credentials
     *
     * @return array<int,array<string,mixed>>
     */
    private function runChunks(string $endpoint, array $chunks, callable $payload_for): array
    {
        $calls = [];
        $stopped_by = null;
        $attempted = 0;
        foreach ($chunks as $i => $chunk) {
            if ($stopped_by !== null) {
                $call = $this->local($endpoint, 'LOCAL_SKIPPED', $this->nowMs(), '');
                $call['skipped'] = true;
                $call['skipped_because'] = $stopped_by;
            } else {
                if ($attempted > 0) {
                    $this->pause();
                }
                $attempted++;
                $call = $this->request($endpoint, $payload_for($chunk));
                if ($call['result'] !== 'OK' && !in_array($call['code'], self::KEEP_GOING, true)) {
                    $stopped_by = (string) $call['code'];
                }
            }
            $call['chunk'] = $i;
            $call['recipients'] = $chunk;
            $call['recipient_count'] = count($chunk);
            $calls[] = $call;
        }

        return $calls;
    }

    /**
     * One result over all chunks of a send or validate.
     *
     * @param array<int,array<string,mixed>> $calls
     *
     * @return array<string,mixed>
     */
    private function aggregate(string $endpoint, array $calls, int $started): array
    {
        $ok = array_filter($calls, function (array $call): bool {
            return $call['result'] === 'OK';
        });
        $failed = array_values(array_filter($calls, function (array $call): bool {
            return $call['result'] !== 'OK';
        }));
        $result = count($ok) === count($calls) ? 'OK' : (count($ok) > 0 ? 'PARTIAL' : 'ERROR');

        $ids = [];
        $numbers = 0;
        $points = 0.0;
        $balance = null;
        $stamp = null;
        $http_status = 0;
        $sent = false;
        $ms = 0;
        foreach ($calls as $call) {
            $sent = $sent || $call['sent'];
            $ms += $call['ms'];
            if ($call['http_status'] > 0) {
                $http_status = $call['http_status'];
            }
            if ($call['result'] !== 'OK' || $endpoint !== 'send') {
                continue;
            }
            if ($call['msg-id'] !== null) {
                $ids[] = $call['msg-id'];
            }
            $numbers += $call['numbers'];
            $points += (float) $call['points-charged'];
            if ($call['balance-after'] !== null) {
                $balance = $call['balance-after'];
            }
            if ($call['unix-timestamp'] !== null) {
                $stamp = $call['unix-timestamp'];
            }
        }

        $first = $failed[0] ?? null;
        $out = [
            'result' => $result,
            'code' => $first === null ? null : $first['code'],
            'code_source' => $first === null ? null : $first['code_source'],
            'description' => $first === null ? '' : $first['description'],
            'endpoint' => $endpoint,
            'http_status' => $http_status,
            'sent' => $sent,
            'ms' => $ms,
            'started_ms' => $started,
            'ended_ms' => $this->nowMs(),
            'calls' => $calls,
        ];
        if ($endpoint === 'send') {
            $out += [
                'msg-ids' => $ids,
                'msg-id' => $ids[0] ?? null,
                'numbers' => $numbers,
                'points-charged' => $points,
                'balance-after' => $balance,
                'unix-timestamp' => $stamp,
            ];
        }

        return $out;
    }

    /**
     * An aggregate for a send or validate that was refused before any call.
     *
     * @param array<int,array<string,mixed>> $calls
     *
     * @return array<string,mixed>
     */
    private function finishAggregate(string $endpoint, array $calls, string $code, int $started): array
    {
        $out = $this->aggregate($endpoint, $calls, $started);
        $out['result'] = 'ERROR';
        $out['code'] = $code;
        $out['code_source'] = 'local';
        $out['description'] = self::LOCAL_TEXT[$code];
        if ($endpoint === 'validate') {
            $out['mobile'] = ['OK' => [], 'ER' => [], 'NR' => []];
        }
        $this->note('debug', $endpoint . '.exit', $code, ['code' => $code]);

        return $out;
    }

    // ------------------------------------------------------------------ small helpers

    /**
     * Runs a breaker bookkeeping call; a storage error there must never lose a reply that already arrived.
     */
    private function guardCall(callable $call): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            $this->noteThrowable('guard_bookkeeping_failed', $e);
        }
    }

    /**
     * @param mixed $value
     */
    private function toFloat($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (is_string($value) && is_numeric($value)) {
            $float = (float) $value;

            return is_finite($float) ? $float : null;
        }

        return null;
    }

    /**
     * A list of strings from a JSON list of scalars, or a comma or blank separated string; null otherwise.
     *
     * @param mixed $value
     *
     * @return string[]|null
     */
    private function toStringList($value): ?array
    {
        if (is_string($value)) {
            $parts = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);

            return $parts === false ? null : $parts;
        }
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) || is_int($item)) {
                $out[] = (string) $item;
            }
        }

        return $out;
    }

    /**
     * @param mixed $description
     */
    private function cleanDescription($description): string
    {
        if (!is_string($description)) {
            return '';
        }
        $text = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $description));

        return (string) mb_strcut($text, 0, self::DESCRIPTION_LIMIT, 'UTF-8');
    }

    /**
     * Replaces invalid UTF-8 sequences with U+FFFD.
     */
    private function substituteUtf8(string $text): string
    {
        $encoded = json_encode($text, JSON_INVALID_UTF8_SUBSTITUTE);
        $decoded = $encoded === false ? null : json_decode($encoded);

        return is_string($decoded) ? $decoded : '';
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * The few facts about a result that are safe to log (no numbers, no text, no bodies).
     *
     * @param array<string,mixed> $result
     *
     * @return array<string,mixed>
     */
    private function summary(array $result): array
    {
        $out = [
            'result' => $result['result'],
            'code' => $result['code'],
            'code_source' => $result['code_source'],
            'http_status' => $result['http_status'],
            'sent' => $result['sent'],
        ];
        if (isset($result['calls'])) {
            $out['calls'] = count($result['calls']);
        }

        return $out;
    }

    /**
     * @param array<mixed> $context
     */
    private function note(string $level, string $action, string $message, array $context = [], ?int $ms = null): void
    {
        $this->log->log($level, 'api.' . $action, $message, $context, $ms);
    }

    private function noteThrowable(string $action, \Throwable $e): void
    {
        $where = basename($e->getFile()) . ':' . $e->getLine();
        $this->note('error', $action, get_class($e) . ': ' . $e->getMessage() . ' at ' . $where);
    }
}
