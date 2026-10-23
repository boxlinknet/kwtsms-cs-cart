<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Security/ClientIp.php
 * CREATED:   2026-10-23 19:49 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The address of the visitor, for the per-IP limits of the SMS code requests: REMOTE_ADDR, and only when
 *            REMOTE_ADDR is inside the configured trusted proxy list the right-most X-Forwarded-For entry that is not
 *            itself a trusted proxy. bucketKey() turns an address into the name part of a rate-limit bucket (an IPv4
 *            address as it is, an IPv6 address as its /64 prefix).
 * WHY:       Task M1.2 (ruling P3-7, SafatMall E1). Core fn_get_ip() trusts HTTP_X_FORWARDED_FOR, HTTP_CLIENT_IP,
 *            HTTP_VIA and eight more client headers, so a script could pick any address per request and walk around a
 *            per-IP limit (SMS pumping). Here a header counts only behind a proxy the administrator named.
 * USAGE:     ClientIp::resolve($_SERVER, ['10.0.0.0/8', '2001:db8::/32']);   // '203.0.113.9'
 *            ClientIp::current();                                            // the same, with the Settings list
 *            ClientIp::bucketKey('2001:db8:1:2:3:4:5:6');                    // '2001:db8:1:2::/64'
 *            resolve() returns '' when REMOTE_ADDR is missing or not an address (command line); callers then use a
 *            bucket of their own for "unknown". Never uses fn_get_ip().
 * DEPENDS:   PHP inet_pton(), inet_ntop(); Tygh::$app['kwtsms.config'] for current() only.
 */

namespace Tygh\Addons\Kwtsms\Security;

class ClientIp
{
    /**
     * @param array<string,mixed> $server        $_SERVER
     * @param string[]            $trusted_cidrs IP addresses and CIDR blocks of the trusted proxies (IPv4 and IPv6)
     *
     * @return string The client address in its canonical text form ('' when REMOTE_ADDR is not an address)
     */
    public static function resolve(array $server, array $trusted_cidrs): string
    {
        $remote = self::canonical($server['REMOTE_ADDR'] ?? '');
        if ($remote === '') {
            return '';
        }
        $trusted = [];
        foreach ($trusted_cidrs as $entry) {
            if (self::parseBlock((string) $entry) !== null) {
                $trusted[] = (string) $entry;
            }
        }
        if ($trusted === [] || !self::inAny($remote, $trusted)) {
            return $remote;
        }

        $header = $server['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!is_string($header) || $header === '') {
            return $remote;
        }
        $hops = array_reverse(array_map('trim', explode(',', $header)));
        foreach ($hops as $hop) {
            $ip = self::canonical($hop);
            if ($ip === '') {
                // A hop that is not an address cannot be placed in the chain: nothing to the left of it is believed.
                return $remote;
            }
            if (!self::inAny($ip, $trusted)) {
                return $ip;
            }
        }

        return $remote;
    }

    /**
     * The client address of the running request, with the trusted proxy list of Settings.
     */
    public static function current(): string
    {
        $proxies = [];
        if (class_exists('Tygh\\Tygh') && isset(\Tygh\Tygh::$app['kwtsms.config'])) {
            $proxies = \Tygh\Tygh::$app['kwtsms.config']->trustedProxies();
        }

        return self::resolve($_SERVER, $proxies);
    }

    /**
     * The address part of a rate-limit bucket name: an IPv4 address as it is, an IPv6 address as its /64 prefix
     * ('2001:db8:1:2::/64'). An IPv4-mapped IPv6 address is the IPv4 address. '' stays ''.
     */
    public static function bucketKey(string $ip): string
    {
        $canonical = self::canonical($ip);
        if ($canonical === '') {
            return '';
        }
        $packed = (string) inet_pton($canonical);
        if (strlen($packed) === 4) {
            return $canonical;
        }

        return (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /**
     * The canonical text form of an address ('' for anything that is not an address). An IPv4-mapped IPv6 address
     * (::ffff:1.2.3.4) becomes the IPv4 address.
     *
     * @param mixed $text
     */
    private static function canonical($text): string
    {
        if (!is_string($text) || trim($text) === '' || strlen($text) > 64) {
            return '';
        }
        $packed = @inet_pton(self::withoutPort(trim($text)));
        if ($packed === false) {
            return '';
        }
        if (strlen($packed) === 16 && substr($packed, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            $packed = substr($packed, 12);
        }

        return (string) inet_ntop($packed);
    }

    /**
     * 2026-10-23 20:47 +03: Task M1.2 fix round 1 (minor 4), task 596. A forwarded hop can carry a port
     * ("198.51.100.7:51234", "[2001:db8::1]:443", "[2001:db8::1]"), as some cloud load balancers send it. The port is
     * removed (it must be 0..65535) before the address is validated; anything else is returned as it is and fails
     * validation. A bare IPv6 address has colons but no brackets: it is never cut.
     */
    private static function withoutPort(string $text): string
    {
        $bracketed = preg_match('/^\[([0-9A-Fa-f:.]+)\](?::([0-9]{1,5}))?$/', $text, $m) === 1;
        $dotted = !$bracketed && preg_match('/^([0-9]{1,3}(?:\.[0-9]{1,3}){3}):([0-9]{1,5})$/', $text, $m) === 1;
        if ($bracketed || $dotted) {
            if (isset($m[2]) && (int) $m[2] > 65535) {
                return '';
            }

            return $m[1];
        }

        return $text;
    }

    /**
     * @param string[] $blocks
     */
    private static function inAny(string $ip, array $blocks): bool
    {
        $packed = (string) inet_pton($ip);
        foreach ($blocks as $block) {
            $parsed = self::parseBlock($block);
            if ($parsed === null || strlen($parsed[0]) !== strlen($packed)) {
                continue;
            }
            if (self::samePrefix($packed, $parsed[0], $parsed[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0:string,1:int}|null The packed network address and the prefix length; null when invalid
     */
    private static function parseBlock(string $entry): ?array
    {
        $entry = trim($entry);
        $bits = null;
        if (strpos($entry, '/') !== false) {
            [$entry, $mask] = explode('/', $entry, 2);
            if ($mask === '' || !ctype_digit($mask)) {
                return null;
            }
            $bits = (int) $mask;
        }
        $packed = @inet_pton($entry);
        if ($packed === false) {
            return null;
        }
        // A trusted IPv4-mapped IPv6 address means the IPv4 address, as canonical() reads the client.
        // 2026-10-23 20:48 +03: Task M1.2 fix round 1 (review minor 5), task 596. A mapped CIDR (::ffff:10.0.0.0/104)
        // folds too (the prefix loses its 96 mapped bits), so it matches the IPv4 client; a mapped block shorter than
        // /96 covers more than IPv4 and stays a 16-byte IPv6 block.
        $mapped = strlen($packed) === 16 && substr($packed, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff";
        if ($mapped && ($bits === null || $bits >= 96)) {
            $packed = substr($packed, 12);
            $bits = $bits === null ? null : $bits - 96;
        }
        $max = strlen($packed) * 8;
        if ($bits === null) {
            $bits = $max;
        }
        if ($bits < 0 || $bits > $max) {
            return null;
        }

        return [$packed, $bits];
    }

    private static function samePrefix(string $a, string $b, int $bits): bool
    {
        $whole = intdiv($bits, 8);
        if ($whole > 0 && substr($a, 0, $whole) !== substr($b, 0, $whole)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$whole]) & $mask) === (ord($b[$whole]) & $mask);
    }
}
