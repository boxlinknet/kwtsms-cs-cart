<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Cron/CronKey.php
 * CREATED:   2026-10-21 01:13 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The check that lets a web request run kwtsms_cron.run: URL mode switched on, a stored key of at least 32
 *            characters, and the same key in the header X-Kwtsms-Cron-Key or in the POST field kwtsms_cron_key,
 *            compared with hash_equals().
 * WHY:       PRD 6.11 and 10. kwtsms_cron.run is a trusted controller (no administrator login), and the permission
 *            schema also lets any logged-in administrator reach it, so the controller authenticates EVERY web
 *            request with the key, a logged-in administrator included: the key sits in a header or the POST body,
 *            never in the query string, so a link or an image tag on another site cannot start a run. The command
 *            line needs no key (it is a process on the server) and does not use this class.
 * USAGE:     $supplied = CronKey::supplied($_SERVER, $_POST);
 *            $reason = CronKey::deny($config->cronUrlEnabled(), $config->cronKey(), $supplied);
 *            // null = let the request run; otherwise url_mode_off | no_stored_key | no_key | bad_key (never shown to
 *            // the caller: the controller answers 403 with {"ok":false} whatever the reason)
 * DEPENDS:   Nothing.
 */

namespace Tygh\Addons\Kwtsms\Cron;

final class CronKey
{
    /** A key shorter than this is never accepted, stored or supplied. */
    public const MIN_LENGTH = 32;

    /** The $_SERVER entry of the request header X-Kwtsms-Cron-Key. */
    public const SERVER_HEADER = 'HTTP_X_KWTSMS_CRON_KEY';

    /** The POST field that can carry the key instead of the header. */
    public const POST_FIELD = 'kwtsms_cron_key';

    private function __construct()
    {
    }

    /**
     * Why a request must be refused, or null when it may run.
     *
     * @param bool  $url_enabled The cron_url_enabled setting
     * @param string $stored     The stored key (Config::cronKey(): '' when none or malformed)
     * @param mixed $supplied    What the request carried; anything that is not a string is refused
     */
    public static function deny(bool $url_enabled, string $stored, $supplied): ?string
    {
        if (!$url_enabled) {
            return 'url_mode_off';
        }
        if (strlen($stored) < self::MIN_LENGTH) {
            return 'no_stored_key';
        }
        if (!is_string($supplied) || $supplied === '') {
            return 'no_key';
        }
        if (strlen($supplied) < self::MIN_LENGTH || !hash_equals($stored, $supplied)) {
            return 'bad_key';
        }

        return null;
    }

    /**
     * The key a request carries: the header when it is present at all, else the POST field. The query string is
     * never read (it ends up in server logs and in the Referer).
     *
     * @param array<string,mixed> $server $_SERVER
     * @param array<string,mixed> $post   $_POST
     *
     * @return mixed
     */
    public static function supplied(array $server, array $post)
    {
        if (array_key_exists(self::SERVER_HEADER, $server)) {
            return $server[self::SERVER_HEADER];
        }

        return $post[self::POST_FIELD] ?? null;
    }
}
