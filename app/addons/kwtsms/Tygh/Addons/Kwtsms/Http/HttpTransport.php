<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Http/HttpTransport.php
 * CREATED:   2026-10-20 22:21 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The add-on's own cURL client: one POST of a JSON body to https://www.kwtsms.com and back,
 *            with TLS verified, HTTPS only, no redirects, hard timeouts, and a result that says what kind
 *            of failure happened and whether the request may have reached the gateway.
 * WHY:       PRD 4.4 and 10: Tygh\Http sets CURLOPT_SSL_VERIFYPEER to 0, follows redirects, logs every
 *            request body (with the API password) into the core logs table, and its "timeout" is only the
 *            connect timeout. None of that is acceptable for a credentials-in-the-body API, so the add-on
 *            has its own few lines. This class has no logger and no setting: it cannot write a body or a
 *            credential anywhere. The only way to talk to another host is the constructor's host set,
 *            which exists for the TLS probe in the test suite (self-signed.badssl.com) and is never read
 *            from a constant or a setting in shipped code.
 * USAGE:     $r = (new HttpTransport())->post('https://www.kwtsms.com/API/balance/', $json, 3, 8);
 *            $r = ['http_status' => 200, 'body' => '{"result":"OK",...}', 'error' => null, 'sent' => true, 'ms' => 143]
 *            error: null | 'dns' | 'connect' | 'tls' | 'timeout'.
 *              dns, connect, tls: nothing was sent (sent = false), the call may be retried later.
 *              timeout: connected, the request may have been received but no complete reply came
 *              (sent = true), so a send must be reported as unknown and never retried by itself.
 *            A non-2xx HTTP status is NOT an error here: kwtSMS answers some failures with 403 or 406 and a
 *            JSON body, so the caller always parses the body first.
 *            A URL whose scheme, host or port is not allowed throws InvalidArgumentException before cURL
 *            is touched. $t->lastError() holds the last cURL message (never a body) for the debug log.
 * DEPENDS:   ext-curl.
 */

namespace Tygh\Addons\Kwtsms\Http;

class HttpTransport
{
    /** The only host the shipped add-on talks to. */
    public const HOST = 'www.kwtsms.com';

    /** cURL error numbers that mean the secure channel could not be set up or verified. */
    private const TLS_ERRNOS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 82, 83, 90, 91];

    /** cURL error numbers raised after the request left: the gateway may have it, no complete reply came. */
    private const AFTER_SEND_ERRNOS = [16, 18, 52, 55, 56, 92];

    /** cURL "operation timed out". */
    private const ERRNO_TIMEOUT = 28;

    /** @var string[] Lower-case host names this instance may contact */
    private $allowed_hosts;

    /** @var string */
    private $last_error = '';

    /**
     * @param string[] $allowed_hosts Test-only: host names to talk to instead of www.kwtsms.com (the TLS probe).
     *                                Plain lower-case host names; the default is the kwtSMS host alone.
     *
     * @throws \InvalidArgumentException For an empty set or an entry that is not a plain host name
     */
    public function __construct(array $allowed_hosts = [self::HOST])
    {
        $hosts = [];
        foreach ($allowed_hosts as $host) {
            $host = is_string($host) ? strtolower($host) : '';
            if (preg_match('/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/D', $host) !== 1) {
                throw new \InvalidArgumentException('HttpTransport: an allowed host must be a plain host name');
            }
            $hosts[] = $host;
        }
        if ($hosts === []) {
            throw new \InvalidArgumentException('HttpTransport: the allowed host set is empty');
        }
        $this->allowed_hosts = $hosts;
    }

    /**
     * POSTs a JSON body. Never throws for a network failure (the result says what happened).
     *
     * @param string $url       https URL on an allowed host, path made of letters, digits and ._~/- only
     * @param string $json      Request body, sent byte for byte
     * @param int    $connect_s Connect timeout in seconds (1 to 60)
     * @param int    $total_s   Total timeout in seconds (at least the connect timeout, at most 120)
     *
     * @return array{http_status:int, body:string, error:?string, sent:bool, ms:int}
     *
     * @throws \InvalidArgumentException When the URL is not https on an allowed host
     */
    public function post(
        string $url,
        #[\SensitiveParameter]
        string $json,
        int $connect_s,
        int $total_s
    ): array {
        $this->assertAllowed($url);
        $options = $this->curlOptions($url, $json, $connect_s, $total_s);

        $this->last_error = '';
        $started = microtime(true);
        $raw = $this->execute($options);
        $ms = max(0, (int) round((microtime(true) - $started) * 1000));

        if ($raw['errno'] === 0 && is_string($raw['body'])) {
            return [
                'http_status' => (int) $raw['http_status'],
                'body' => $raw['body'],
                'error' => null,
                'sent' => true,
                'ms' => $ms,
            ];
        }

        $this->last_error = $this->cleanMessage((string) $raw['error']);
        [$error, $sent] = $this->classify(
            (int) $raw['errno'],
            (float) $raw['connect_time'],
            (float) $raw['appconnect_time']
        );

        return ['http_status' => 0, 'body' => '', 'error' => $error, 'sent' => $sent, 'ms' => $ms];
    }

    /**
     * The cURL options of one call. TLS is verified (peer 1, host 2, TLS 1.2 or newer), only HTTPS is
     * allowed for the request and for any redirect, redirects are never followed, and both timeouts are
     * set. Public so the test suite can pin the exact array.
     *
     * @return array<int,mixed>
     */
    public function curlOptions(
        string $url,
        #[\SensitiveParameter]
        string $json,
        int $connect_s,
        int $total_s
    ): array {
        $connect = max(1, min(60, $connect_s));
        $total = max($connect, min(120, $total_s));

        return [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            // "Expect:" switches off the 100-continue round trip some libcurl versions add to larger bodies.
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Expect:'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => 1,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => 0,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => $connect,
            CURLOPT_TIMEOUT => $total,
            CURLOPT_NOSIGNAL => 1,
        ];
    }

    /**
     * The last cURL error message (for example "Could not resolve host"), cleaned and cut to 200
     * characters; empty after a call that reached the gateway. It never holds a request or reply body.
     */
    public function lastError(): string
    {
        return $this->last_error;
    }

    /**
     * Runs cURL. Protected so the test suite can replace it with a canned outcome; the real path is
     * covered by tests/Live/ApiClientTest.php and a closed-port case in the unit tests.
     *
     * @param array<int,mixed> $options
     *
     * @return array{body:string|false, errno:int, error:string, http_status:int, connect_time:float,
     *               appconnect_time:float, starttransfer_time:float}
     */
    protected function execute(
        #[\SensitiveParameter]
        array $options
    ): array {
        $ch = curl_init();
        if ($ch === false) {
            return [
                'body' => false, 'errno' => 2, 'error' => 'curl_init failed', 'http_status' => 0,
                'connect_time' => 0.0, 'appconnect_time' => 0.0, 'starttransfer_time' => 0.0,
            ];
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $out = [
            'body' => $body,
            'errno' => curl_errno($ch),
            'error' => curl_error($ch),
            'http_status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'connect_time' => (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME),
            'appconnect_time' => (float) curl_getinfo($ch, CURLINFO_APPCONNECT_TIME),
            'starttransfer_time' => (float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME),
        ];
        unset($ch);

        return $out;
    }

    /**
     * https only, host in the allowed set, port absent or 443, and a URL made of plain characters (no
     * user info, query, fragment, backslash, blank or control character), so PHP and cURL cannot read
     * the host differently.
     *
     * @throws \InvalidArgumentException
     */
    private function assertAllowed(string $url): void
    {
        $ok = preg_match('#^https://([a-z0-9.\-]+)(?::443)?(/[A-Za-z0-9._~/\-]*)?$#Di', $url, $m) === 1
            && in_array(strtolower($m[1]), $this->allowed_hosts, true);
        if (!$ok) {
            throw new \InvalidArgumentException('HttpTransport: the URL is not https on an allowed host');
        }
    }

    /**
     * @return array{0:string,1:bool} [error category, request may have reached the gateway]
     */
    private function classify(int $errno, float $connect_time, float $appconnect_time): array
    {
        if ($errno === 5 || $errno === 6) {
            return ['dns', false];
        }
        if (in_array($errno, self::TLS_ERRNOS, true)) {
            return ['tls', false];
        }
        if ($errno === self::ERRNO_TIMEOUT) {
            // Timed out after the handshake finished: the request went out. Before that: connect problem.
            return $appconnect_time > 0.0 ? ['timeout', true] : ['connect', false];
        }
        if (in_array($errno, self::AFTER_SEND_ERRNOS, true)) {
            return ['timeout', true];
        }

        // 2026-10-20 23:14 +03: any other errno is "not sent" only while the TLS handshake had not finished.
        // After it the request may have reached kwtSMS, and calling it "not sent" would let the outbox send the
        // SMS again (a duplicate and a double charge). Fix round 1, task 596.
        return $appconnect_time > 0.0 ? ['timeout', true] : ['connect', false];
    }

    private function cleanMessage(string $message): string
    {
        return substr((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $message), 0, 200);
    }
}
