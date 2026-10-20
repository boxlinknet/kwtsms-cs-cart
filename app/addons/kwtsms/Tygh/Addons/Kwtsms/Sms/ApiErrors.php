<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Sms/ApiErrors.php
 * CREATED:   2026-10-20 22:20 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Maps a kwtSMS error code (ERRnnn from the gateway, or LOCAL_* from this add-on) to a
 *            plain-language reason in the admin's language, through the language variable
 *            kwtsms.err.<code>.
 * WHY:       PRD Appendix A and 6.1: the admin sees one reason per code (ERR003 names the API user, ERR012
 *            says "more than 7 pages", LOCAL_TIMEOUT says to check the archive before resending). Texts live
 *            in the two .po files so they translate and edit like every other string. A code that is not in
 *            the table gets one generic text that carries the code, and the server's own description is
 *            kept elsewhere (the message row), so nothing is lost.
 * USAGE:     ApiErrors::describe('ERR003');        // "Wrong API username or password. Use the API user ..."
 *            ApiErrors::describe('ERR777');        // generic text with ERR777
 *            ApiErrors::source('LOCAL_DNS');       // 'local'   (any other code: 'api')
 *            ApiErrors::SERVER_CODES, ApiErrors::LOCAL_CODES: the codes that have a language variable.
 *            A code is trimmed and upper-cased first; anything that is not 1 to 32 of A-Z, 0-9 or "_" is
 *            reported as UNKNOWN, so markup or a newline in a server reply can never reach the page.
 * DEPENDS:   CS-Cart __() for the language variables (var/langs/{en,ar}/addons/kwtsms.po).
 *
 * UPSTREAM:  The structure follows kwtsms-php v1.7.1, src/ApiErrors.php (MIT, Copyright (c) 2026 kwtSMS /
 *            boxlink net), commit 132285b9f39bcfe33925dfbf9ea614026b87240a, whose table of codes was the
 *            starting point. sha256 of that file at the tag (recorded 2026-10-20 23:16 +03, task 596):
 *            d0ae34ed6ce77f3837a406545ba06cab890454c495058d2b1dcb55f840d2373d
 *            Changes: texts moved to language variables (EN and AR), the code list follows
 *            PRD Appendix A (plus ERR034, ERR035, ERR036 and the LOCAL_* codes), codes the add-on never
 *            receives (status and delivery-report endpoints) are not listed and take the generic text, and
 *            "action" sentences are merged into the one reason.
 */

namespace Tygh\Addons\Kwtsms\Sms;

class ApiErrors
{
    /** Codes the gateway returns on the five endpoints this add-on calls (PRD Appendix A). */
    public const SERVER_CODES = [
        'ERR001', 'ERR002', 'ERR003', 'ERR004', 'ERR005', 'ERR006', 'ERR007', 'ERR008', 'ERR009', 'ERR010',
        'ERR011', 'ERR012', 'ERR013', 'ERR024', 'ERR025', 'ERR026', 'ERR027', 'ERR031', 'ERR032', 'ERR033',
        'ERR034', 'ERR035', 'ERR036',
    ];

    /** Codes this add-on raises itself, with no gateway reply (code_source = local). */
    public const LOCAL_CODES = [
        'LOCAL_TIMEOUT', 'LOCAL_CONNECT', 'LOCAL_TLS', 'LOCAL_DNS', 'LOCAL_BAD_JSON', 'LOCAL_HTTP',
        'LOCAL_CANCELLED', 'LOCAL_HTML', 'LOCAL_BAD_INPUT', 'LOCAL_BREAKER', 'LOCAL_DEFERRED', 'LOCAL_BUSY',
        'LOCAL_NO_GUARD', 'LOCAL_REFUSED', 'LOCAL_SKIPPED', 'LOCAL_ERROR',
    ];

    /**
     * The reason for a code, in the current language. Never throws and never echoes a malformed code.
     */
    public static function describe(string $code): string
    {
        $code = self::normalize($code);
        if (in_array($code, self::SERVER_CODES, true) || in_array($code, self::LOCAL_CODES, true)) {
            return (string) __('kwtsms.err.' . $code);
        }

        // $code is A-Z, 0-9 and "_" only, so it is safe in a notification; escaped anyway (PRD 10).
        return (string) __('kwtsms.err.unknown', ['[code]' => htmlspecialchars($code, ENT_QUOTES, 'UTF-8')]);
    }

    /**
     * 'local' for a LOCAL_* code, 'api' for everything the gateway said (known or not).
     */
    public static function source(string $code): string
    {
        return strncmp(self::normalize($code), 'LOCAL_', 6) === 0 ? 'local' : 'api';
    }

    /**
     * Trim, upper-case, and accept only 1 to 32 of A-Z, 0-9 and "_"; anything else is UNKNOWN.
     */
    private static function normalize(string $code): string
    {
        $code = strtoupper(trim($code));

        return preg_match('/^[A-Z0-9_]{1,32}$/D', $code) === 1 ? $code : 'UNKNOWN';
    }
}
