<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Orders/AfterResponse.php
 * CREATED:   2026-10-21 16:51 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Decides when a storefront request sends its order SMS: after the response (PHP-FPM
 *            fastcgi_finish_request or LiteSpeed litespeed_finish_request), by the cron (neither function, and the
 *            cron ran within the last 2 cron intervals), or in the request (neither function, no recent cron).
 *            finish() ends the response with the function the chosen mode names.
 * WHY:       Final review I2 (ruling R52): with only fastcgi_finish_request accepted, a LiteSpeed LSAPI or Apache
 *            mod_php host sent both order SMS inside the checkout request, at or over the PRD 16 budget of +1.5 s
 *            p95. On those hosts the SMS now waits in the outbox for the cron when the cron runs; else it is sent
 *            in the request, where ApiClient caps the slot wait of a web call (ApiGuard::WEB_MAX_WAIT_MS).
 * USAGE:     $seam = new AfterResponse();                       // real functions, real cron heartbeat
 *            $mode = $seam->mode($actor);                       // fpm | litespeed | cron | inline
 *            AfterResponse::finish($mode);                      // in the shutdown function, before sending
 *            Test seams: the constructor takes the function test, the last cron time, the clock and the SAPI.
 *            Public hook kwtsms_after_response_finisher ($actor, &$finisher): another add-on may set the detected
 *            finisher ('fpm' or 'litespeed') to '' (none), e.g. when its host buffers the response anyway; any
 *            other value is ignored.
 * DEPENDS:   Nothing at construction; the default last-cron reader is the caller's (ServiceProvider passes
 *            GatewayState::lastCron).
 */

namespace Tygh\Addons\Kwtsms\Orders;

final class AfterResponse
{
    public const FPM = 'fpm';
    public const LITESPEED = 'litespeed';
    public const CRON = 'cron';
    public const INLINE = 'inline';

    /** The cron runs every minute (Help, Settings); a heartbeat within 2 intervals means it is running. */
    public const CRON_FRESH_SECONDS = 120;

    /** @var callable (string $name): bool */
    private $function_exists;

    /** @var callable (): ?int */
    private $last_cron;

    /** @var callable (): int */
    private $now;

    /** @var string */
    private $sapi;

    /**
     * @param callable|null $function_exists (string $name): bool; null = function_exists
     * @param callable|null $last_cron       (): ?int unix time of the last cron run; null = never
     * @param callable|null $now             (): int; null = time()
     */
    public function __construct(
        ?callable $function_exists = null,
        ?callable $last_cron = null,
        ?callable $now = null,
        string $sapi = PHP_SAPI
    ) {
        $this->function_exists = $function_exists ?? 'function_exists';
        $this->last_cron = $last_cron ?? static function (): ?int {
            return null;
        };
        $this->now = $now ?? 'time';
        $this->sapi = $sapi;
    }

    /**
     * The send mode for this request. Only a storefront web request leaves the request path; the admin area and
     * the command line send in the request, as before.
     *
     * @param array<string,mixed> $actor OrderSource::actor()
     */
    public function mode(array $actor): string
    {
        if (($actor['area'] ?? '') !== 'C' || $this->sapi === 'cli') {
            return self::INLINE;
        }
        $finisher = $this->finisher();
        if (function_exists('fn_set_hook')) {
            $hooked = $finisher;
            $hook_actor = $actor;
            fn_set_hook('kwtsms_after_response_finisher', $hook_actor, $hooked);
            if ($hooked === '') {
                $finisher = '';
            }
        }
        if ($finisher !== '') {
            return $finisher;
        }

        return $this->cronRunning() ? self::CRON : self::INLINE;
    }

    /**
     * True for the modes that send in a shutdown function after the response.
     */
    public static function afterResponse(string $mode): bool
    {
        return $mode === self::FPM || $mode === self::LITESPEED;
    }

    /**
     * Ends the response with the function of the mode, when it exists. Never throws.
     */
    public static function finish(string $mode): void
    {
        if ($mode === self::FPM && function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif ($mode === self::LITESPEED && function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
    }

    /**
     * 'fpm', 'litespeed' or '' (neither function exists).
     */
    private function finisher(): string
    {
        if (call_user_func($this->function_exists, 'fastcgi_finish_request')) {
            return self::FPM;
        }
        if (call_user_func($this->function_exists, 'litespeed_finish_request')) {
            return self::LITESPEED;
        }

        return '';
    }

    private function cronRunning(): bool
    {
        try {
            $last = call_user_func($this->last_cron);
        } catch (\Throwable $e) {
            return false;
        }
        if ($last === null || (int) $last <= 0) {
            return false;
        }
        $age = (int) call_user_func($this->now) - (int) $last;

        return $age >= 0 && $age <= self::CRON_FRESH_SECONDS;
    }
}
