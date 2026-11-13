<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Events/RmaSmsData.php
 * CREATED:   2026-11-12 07:40 +03
 * TASK:      596 M3.1
 * AUTHOR:    kwtSMS
 * WHAT:      The data_class of the rma.status_changed.<status> declarations (schemas/kwtsms/events.php): computes the
 *            one placeholder the payload does not carry, {return_status}, the name of the return status in the
 *            language the SMS is rendered in (kwtsms_lang, set by OrderNotifier), read with
 *            fn_get_simple_statuses(STATUSES_RETURN, false, false, <lang>). An unknown status or a failing read gives
 *            the status code. The name is trimmed, white space collapsed and cut to MAX_STATUS characters.
 * WHY:       Task M3.1 (B1, P3-63). The core payload has only the status code (return_info.status); a customer reads
 *            "Declined", not "D", and in the language of the SMS, not of the admin who changed it. Nothing typed by a
 *            customer (return_info.comment) is ever returned.
 * USAGE:     'data_class' => \Tygh\Addons\Kwtsms\Events\RmaSmsData::class; OrderNotifier builds it with no
 *            arguments and calls values($data, $receiver). A unit test replaces the status read with useStatusLookup().
 * DEPENDS:   Notifications\DeclaredSmsData. Core: STATUSES_RETURN and fn_get_simple_statuses() (only called while the
 *            rma add-on is active, which the declaration's 'addon' => 'rma' guarantees).
 */

namespace Tygh\Addons\Kwtsms\Events;

use Tygh\Addons\Kwtsms\Notifications\DeclaredSmsData;

final class RmaSmsData implements DeclaredSmsData
{
    /** Longest status name put into an SMS, in characters. */
    public const MAX_STATUS = 40;

    /** @var (callable(string): array<string,string>)|null replaces the core read (tests) */
    private static $lookup = null;

    /** @var array<string,array<string,string>> lang => status code => name, read once per request and language */
    private static $names = [];

    /**
     * @param (callable(string): array<string,string>)|null $lookup lang => [status code => name]; null = core again
     */
    public static function useStatusLookup(?callable $lookup): void
    {
        self::$lookup = $lookup;
        self::$names = [];
    }

    /**
     * @param array<string,mixed> $data     The event data: return_info.status, kwtsms_lang
     * @param string              $receiver C, A or V (the same name for all three)
     *
     * @return array{placeholders:array<string,string>}
     */
    public function values(array $data, string $receiver): array
    {
        $return = isset($data['return_info']) && is_array($data['return_info']) ? $data['return_info'] : [];
        $code = $return['status'] ?? '';
        $code = is_scalar($code) && !is_bool($code) ? strtoupper(trim((string) $code)) : '';
        $lang = strtolower(trim(is_scalar($data['kwtsms_lang'] ?? null) ? (string) $data['kwtsms_lang'] : ''));
        if (preg_match('/^[a-z]{2}$/D', $lang) !== 1) {
            $lang = 'en';
        }

        $name = '';
        if ($code !== '') {
            $names = self::namesIn($lang);
            $name = isset($names[$code]) ? self::clean($names[$code]) : '';
        }

        return ['placeholders' => ['return_status' => $name !== '' ? $name : $code]];
    }

    /**
     * Status code (upper case) => name in $lang; [] when the read fails (the caller then falls back to the code).
     *
     * @return array<string,string>
     */
    private static function namesIn(string $lang): array
    {
        if (isset(self::$names[$lang])) {
            return self::$names[$lang];
        }
        $names = [];
        try {
            $raw = self::$lookup !== null
                ? (self::$lookup)($lang)
                : fn_get_simple_statuses(STATUSES_RETURN, false, false, $lang);
            foreach ((array) $raw as $code => $name) {
                if (is_scalar($name)) {
                    $names[strtoupper((string) $code)] = (string) $name;
                }
            }
        } catch (\Throwable $e) {
            $names = [];
        }
        self::$names[$lang] = $names;

        return $names;
    }

    private static function clean(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $name));

        return mb_strlen($name, 'UTF-8') > self::MAX_STATUS
            ? rtrim(mb_substr($name, 0, self::MAX_STATUS, 'UTF-8'))
            : $name;
    }
}
