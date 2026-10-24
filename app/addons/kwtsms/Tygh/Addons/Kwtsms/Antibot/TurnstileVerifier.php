<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Antibot/TurnstileVerifier.php
 * CREATED:   2026-10-24 19:41 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Checks one Cloudflare Turnstile token against the siteverify endpoint: a form POST of secret, response and
 *            remoteip over HTTPS (connect 3 s, total 5 s), and a verdict {ok, code}. Success needs `success === true`
 *            and, with production keys, the returned hostname to be a storefront host and the returned action the
 *            expected one. Cloudflare's documented test keys skip the two strict checks (P3-32). A transport failure
 *            or an `internal-error` answer fails open (ok = true, code 'unavailable') with a warning log (PRD 6.15);
 *            the per-IP and per-phone limits still apply.
 * WHY:       Task M1.7a (PRD 6.15, SafatMall E2): the CAPTCHA before an SMS code request, so a script cannot pump SMS.
 *            The hostname and action checks stop a token minted on another site or for another form.
 * USAGE:     $v = new TurnstileVerifier(new HttpTransport(['challenges.cloudflare.com']), $secret, $site_key, $log);
 *            $v->verify($token, $ip, 'register', ['shop.example.com']);   // ['ok' => true, 'code' => 'ok']
 *            The secret and the token are never logged, never put in an exception message and never in a dump
 *            (__debugInfo() hides the secret). The log rows hold the verdict code only.
 * DEPENDS:   Http\HttpTransport (postForm), Log\DebugLog (optional).
 */

namespace Tygh\Addons\Kwtsms\Antibot;

use Tygh\Addons\Kwtsms\Http\HttpTransport;
use Tygh\Addons\Kwtsms\Log\DebugLog;

class TurnstileVerifier
{
    /** The siteverify endpoint (Cloudflare, server-side validation page). */
    public const URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** The only host this verifier talks to. */
    public const HOST = 'challenges.cloudflare.com';

    /** Cloudflare's documented test site keys (Testing page, read 2026-10-24): public platform facts. */
    public const TEST_SITE_KEYS = [
        '1x00000000000000000000AA',
        '2x00000000000000000000AB',
        '1x00000000000000000000BB',
        '2x00000000000000000000BB',
        '3x00000000000000000000FF',
    ];

    /** Cloudflare's documented test secret keys: always passes, always fails, token already spent. */
    public const TEST_SECRET_KEYS = [
        '1x0000000000000000000000000000000AA',
        '2x0000000000000000000000000000000AA',
        '3x0000000000000000000000000000000AA',
    ];

    /** A token is at most 2048 characters (server-side validation page). */
    public const MAX_TOKEN_LENGTH = 2048;

    private const CONNECT_S = 3;
    private const TOTAL_S = 5;

    /** @var HttpTransport */
    private $http;

    /** @var string */
    private $secret;

    /** @var string */
    private $site_key;

    /** @var DebugLog|null */
    private $log;

    public function __construct(
        HttpTransport $http,
        #[\SensitiveParameter]
        string $secret,
        // 2026-10-24 19:49 +03: Task M1.7a, task 596. The package gate asks for the attribute on every key parameter.
        #[\SensitiveParameter]
        string $site_key,
        ?DebugLog $log = null
    ) {
        $this->http = $http;
        $this->secret = $secret;
        $this->site_key = $site_key;
        $this->log = $log;
    }

    /**
     * @param string[] $allowed_hosts Storefront host names a production token may have been solved on
     *
     * @return array{ok: bool, code: string} code: 'ok', 'unavailable' (fail open), or the reason
     */
    public function verify(
        #[\SensitiveParameter]
        string $token,
        string $remote_ip,
        string $expected_action,
        array $allowed_hosts
    ): array {
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return $this->verdict(false, 'missing-input-response');
        }
        if ($this->secret === '') {
            return $this->verdict(false, 'missing-input-secret');
        }

        $fields = ['secret' => $this->secret, 'response' => $token];
        if ($remote_ip !== '') {
            $fields['remoteip'] = $remote_ip;
        }
        try {
            $reply = $this->http->postForm(
                self::URL,
                http_build_query($fields, '', '&'),
                self::CONNECT_S,
                self::TOTAL_S
            );
        } catch (\Throwable $e) {
            return $this->unavailable('transport_exception');
        }
        if ($reply['error'] !== null) {
            return $this->unavailable('transport_' . $reply['error']);
        }
        $data = json_decode($reply['body'], true);
        if ($reply['http_status'] >= 500 || !is_array($data) || !array_key_exists('success', $data)) {
            return $this->unavailable('unreadable_answer');
        }

        $codes = isset($data['error-codes']) && is_array($data['error-codes']) ? $data['error-codes'] : [];
        if ($data['success'] !== true) {
            if (in_array('internal-error', $codes, true)) {
                return $this->unavailable('internal-error');
            }
            $first = isset($codes[0]) && is_string($codes[0]) && preg_match('/^[a-z\-]{1,40}$/D', $codes[0]) === 1
                ? $codes[0] : 'invalid-input-response';

            return $this->verdict(false, $first);
        }

        if ($this->usesTestKey()) {
            return $this->verdict(true, 'ok');
        }
        $hostname = isset($data['hostname']) && is_string($data['hostname']) ? strtolower($data['hostname']) : '';
        $hosts = array_map('strtolower', array_map('strval', $allowed_hosts));
        if ($hostname === '' || !in_array($hostname, $hosts, true)) {
            return $this->verdict(false, 'hostname-mismatch');
        }
        $action = isset($data['action']) && is_string($data['action']) ? $data['action'] : '';
        if ($action !== $expected_action) {
            return $this->verdict(false, 'action-mismatch');
        }

        return $this->verdict(true, 'ok');
    }

    /** True while a documented Cloudflare test key is configured (P3-32). */
    public function usesTestKey(): bool
    {
        return in_array($this->site_key, self::TEST_SITE_KEYS, true)
            || in_array($this->secret, self::TEST_SECRET_KEYS, true);
    }

    /**
     * 2026-10-24 19:41 +03: Task M1.7a, task 596. A dump shows the site key (public) and never the secret.
     *
     * @return array<string,string>
     */
    public function __debugInfo()
    {
        return ['site_key' => $this->site_key, 'secret' => '[hidden]'];
    }

    /**
     * @return array{ok: bool, code: string}
     */
    private function verdict(bool $ok, string $code): array
    {
        $this->note($ok ? 'debug' : 'info', 'verdict ' . $code, ['ok' => $ok, 'code' => $code]);

        return ['ok' => $ok, 'code' => $code];
    }

    /**
     * Fail open (PRD 6.15): the answer is not about this visitor, so the CAPTCHA does not block; the rate limits do.
     *
     * @return array{ok: bool, code: string}
     */
    private function unavailable(string $reason): array
    {
        $this->note('warning', 'siteverify unavailable, failing open', ['reason' => $reason]);

        return ['ok' => true, 'code' => 'unavailable'];
    }

    /**
     * @param array<string,mixed> $context Verdict facts only: never the secret, the token or an address
     */
    private function note(string $level, string $message, array $context): void
    {
        if ($this->log === null) {
            return;
        }
        try {
            $this->log->log($level, 'turnstile.verify', $message, $context);
        } catch (\Throwable $e) {
            // A log failure never changes the verdict.
            return;
        }
    }
}
