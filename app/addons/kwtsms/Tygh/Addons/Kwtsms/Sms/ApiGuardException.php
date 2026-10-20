<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Sms/ApiGuardException.php
 * CREATED:   2026-10-20 22:25 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Thrown by ApiGuard::acquire() when an API call must not go out; carries the LOCAL_* code.
 * WHY:       acquire() returns void (brief), so "do not call now" needs a channel. ApiClient turns this into
 *            a result with that code and code_source = local, and no HTTP call is made.
 * USAGE:     try { $guard->acquire(); } catch (ApiGuardException $e) { $code = $e->apiCode(); }
 *            Codes: LOCAL_BREAKER (breaker open), LOCAL_DEFERRED (a transport failure already happened in this
 *            request), LOCAL_BUSY (the global slot lock was not available within 2 s).
 * DEPENDS:   Nothing.
 */

namespace Tygh\Addons\Kwtsms\Sms;

final class ApiGuardException extends \RuntimeException
{
    /** @var string */
    private $api_code;

    public function __construct(string $api_code)
    {
        parent::__construct('kwtSMS API call not allowed now: ' . $api_code);
        $this->api_code = $api_code;
    }

    public function apiCode(): string
    {
        return $this->api_code;
    }
}
