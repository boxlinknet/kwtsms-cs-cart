<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Auth/ReturnUrl.php
 * CREATED:   2026-11-04 19:08 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      ReturnUrl::safe($url, $current_host, $fallback): the address a customer is sent to after a sign-in. A
 *            relative address or one on the current host is kept; anything else gives the fallback.
 * WHY:       Task M2.1 (P3-47, P3-50, review focus "open redirect"). return_url comes from the request; a login that
 *            redirects to another site is a phishing tool.
 * USAGE:     $to = ReturnUrl::safe((string) $_REQUEST['return_url'], 'shop.example.com', 'index.php');
 *            Refused (fallback): another host, a protocol-relative address (//host, ///host), any backslash, a scheme
 *            other than http and https, a scheme without a host ("https:evil"), userinfo, a colon in the first path
 *            segment ("evil:x"), control characters (also inside, "java\tscript:"), an empty value. The host is
 *            compared without port and without case; a current host of '' keeps no absolute address.
 * DEPENDS:   Nothing.
 */

namespace Tygh\Addons\Kwtsms\Auth;

final class ReturnUrl
{
    public static function safe(string $url, string $current_host, string $fallback): string
    {
        // 2026-11-04 19:12 +03: Task M2.1, task 596. Control characters are looked for BEFORE trimming (trim() would
        // drop a trailing NUL, tab or newline and hide it); only spaces are trimmed.
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $url) === 1) {
            return $fallback;
        }
        $url = trim($url, ' ');
        if ($url === '') {
            return $fallback;
        }
        if (strncmp($url, '//', 2) === 0) {
            return $fallback;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return $fallback;
        }
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        if ($scheme === '' && $host === '') {
            // A path: a colon before the first slash is read by a browser as a scheme ("evil:x").
            $first = explode('/', explode('?', explode('#', $url)[0])[0])[0];
            if (strpos($first, ':') !== false || strncmp($url, ':', 1) === 0) {
                return $fallback;
            }

            return $url;
        }
        $web = $scheme === 'http' || $scheme === 'https';
        if (!$web || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return $fallback;
        }
        $current = strtolower(trim($current_host));
        if ($current === '') {
            return $fallback;
        }
        $current = self::withoutPort($current);

        return $host === $current ? $url : $fallback;
    }

    private static function withoutPort(string $host): string
    {
        // [::1]:8095 keeps its brackets; host:port loses the port.
        if (strncmp($host, '[', 1) === 0) {
            $end = strpos($host, ']');

            return $end === false ? $host : substr($host, 0, $end + 1);
        }
        $colon = strrpos($host, ':');

        return $colon === false ? $host : substr($host, 0, $colon);
    }
}
