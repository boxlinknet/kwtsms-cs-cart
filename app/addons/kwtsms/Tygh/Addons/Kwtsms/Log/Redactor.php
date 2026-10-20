<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Log/Redactor.php
 * CREATED:   2026-10-20 21:23 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Removes credentials from log data (redact, redactString) and masks one-time secrets in
 *            message copies (mask).
 * WHY:       PRD 10: the API password (and username) must never appear in any log in any form: raw,
 *            JSON-escaped, url-encoded or base64, saved or just typed (candidate). PRD D7: OTP codes and
 *            password-reset links are stored as ****** in every log copy. A password such as p@ss/"w\x
 *            is written differently by every encoder, so all forms are generated and replaced in one
 *            pass, longest first, so no tail of a longer form is left behind.
 * USAGE:     Redactor::redact(['request' => $json, 'password' => 'x'], [$username, $password, $candidate]);
 *              // array without credential keys, secrets replaced by [redacted] at any depth
 *            Redactor::redactString($text, $secrets);   // same rule for one string
 *            Redactor::mask('Code 482913', ['482913']); // 'Code ******'
 *            Keys removed at any depth (case-insensitive): password, api_password; username and api_username
 *            when their value is one of the secrets. Objects become "[object Class]" (their content is never
 *            read), resources "[resource]", arrays deeper than 12 levels "[max depth]".
 * DEPENDS:   Nothing (pure, static, PHP 7.4 to 8.3).
 */

namespace Tygh\Addons\Kwtsms\Log;

class Redactor
{
    public const REPLACEMENT = '[redacted]';

    public const MASK = '******';

    private const MAX_DEPTH = 12;

    /** Keys that are always removed. */
    private const SECRET_KEYS = ['password', 'api_password'];

    /** Keys that are removed when their value is one of the secrets. */
    private const IDENTITY_KEYS = ['username', 'api_username'];

    /**
     * Returns $context without credential keys and with every secret replaced by [redacted].
     *
     * @param array<mixed> $context
     * @param array<mixed> $secrets Credentials and candidate values; empty and non-scalar entries are ignored
     *
     * @return array<mixed>
     */
    public static function redact(
        array $context,
        #[\SensitiveParameter]
        array $secrets
    ): array {
        $plain = self::plainSecrets($secrets);

        return self::walk($context, self::replacementMap($plain), $plain, 0);
    }

    /**
     * Replaces every secret, in every known encoding, in one string.
     *
     * @param array<mixed> $secrets
     */
    public static function redactString(
        string $text,
        #[\SensitiveParameter]
        array $secrets
    ): string {
        $map = self::replacementMap(self::plainSecrets($secrets));

        return $map === [] ? $text : strtr($text, $map);
    }

    /**
     * D7: replaces each secret value (OTP code, reset link) with ******. Literal match, longest first.
     *
     * @param array<mixed> $secret_values
     */
    public static function mask(
        string $text,
        #[\SensitiveParameter]
        array $secret_values
    ): string {
        $map = [];
        foreach (self::plainSecrets($secret_values) as $secret) {
            $map[$secret] = self::MASK;
        }

        return $map === [] ? $text : strtr($text, $map);
    }

    /**
     * @param array<mixed> $secrets
     *
     * @return string[] Non-empty unique strings
     */
    private static function plainSecrets(array $secrets): array
    {
        $plain = [];
        foreach ($secrets as $secret) {
            if (!is_string($secret) && !is_int($secret) && !is_float($secret)) {
                continue;
            }
            $secret = (string) $secret;
            if ($secret !== '' && !in_array($secret, $plain, true)) {
                $plain[] = $secret;
            }
        }

        return $plain;
    }

    /**
     * Every form of every secret mapped to the replacement. strtr() with an array takes the longest key
     * at each position and never rescans replaced text.
     *
     * @param string[] $plain
     *
     * @return array<string, string>
     */
    private static function replacementMap(array $plain): array
    {
        $map = [];
        foreach ($plain as $secret) {
            foreach (self::forms($secret) as $form) {
                if ($form !== '') {
                    $map[$form] = self::REPLACEMENT;
                }
            }
        }

        return $map;
    }

    /**
     * @return string[]
     */
    private static function forms(string $secret): array
    {
        $forms = [$secret];

        // JSON string content (without the surrounding quotes) under the usual encoder options.
        $both = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        foreach ([0, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, $both] as $flags) {
            $json = json_encode($secret, $flags);
            if (is_string($json)) {
                $forms[] = substr($json, 1, -1);
            }
        }

        // URL query forms, upper-case and lower-case percent hex.
        foreach ([rawurlencode($secret), urlencode($secret)] as $url) {
            $forms[] = $url;
            $forms[] = (string) preg_replace_callback(
                '/%[0-9A-F]{2}/',
                static function (array $m): string {
                    return strtolower($m[0]);
                },
                $url
            );
        }

        // Base64: standard and URL-safe, padded and unpadded, url-encoded, and as JSON string content.
        $b64 = base64_encode($secret);
        $safe = strtr($b64, '+/', '-_');
        array_push(
            $forms,
            $b64,
            rtrim($b64, '='),
            $safe,
            rtrim($safe, '='),
            rawurlencode($b64),
            substr((string) json_encode($b64), 1, -1)
        );

        return $forms;
    }

    /**
     * @param array<mixed>          $data
     * @param array<string, string> $map
     * @param string[]              $plain
     *
     * @return array<mixed>
     */
    private static function walk(array $data, array $map, array $plain, int $depth): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $lower = strtolower($key);
                if (in_array($lower, self::SECRET_KEYS, true)) {
                    continue;
                }
                if (in_array($lower, self::IDENTITY_KEYS, true) && self::isOneOf($value, $plain)) {
                    continue;
                }
                $key = $map === [] ? $key : strtr($key, $map);
            }
            $out[$key] = self::value($value, $map, $plain, $depth);
        }

        return $out;
    }

    /**
     * @param mixed                 $value
     * @param array<string, string> $map
     * @param string[]              $plain
     *
     * @return mixed
     */
    private static function value($value, array $map, array $plain, int $depth)
    {
        if (is_string($value)) {
            return $map === [] ? $value : strtr($value, $map);
        }
        if (is_array($value)) {
            return $depth + 1 >= self::MAX_DEPTH ? '[max depth]' : self::walk($value, $map, $plain, $depth + 1);
        }
        if (is_int($value) || is_float($value)) {
            return in_array((string) $value, $plain, true) ? self::REPLACEMENT : $value;
        }
        if (is_object($value)) {
            return '[object ' . get_class($value) . ']';
        }
        if (is_resource($value)) {
            return '[resource]';
        }

        return $value;
    }

    /**
     * @param mixed    $value
     * @param string[] $plain
     */
    private static function isOneOf($value, array $plain): bool
    {
        return (is_string($value) || is_int($value)) && in_array(trim((string) $value), $plain, true);
    }
}
