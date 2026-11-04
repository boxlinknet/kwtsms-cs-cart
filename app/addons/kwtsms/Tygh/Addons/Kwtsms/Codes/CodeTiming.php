<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Codes/CodeTiming.php
 * CREATED:   2026-11-04 19:08 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Pads a request to a fixed minimum duration: start() marks the request start, pad($floor_ms) sleeps
 *            whatever is left of the floor, overran() tells whether the work took longer than the floor.
 * WHY:       Task M2.1 (SafatMall B2, ruling P3-43, Open 9). A host without a finish function does the code work in the
 *            request, so a known phone (a real SMS send) would answer slower than an unknown one. Every answer of the
 *            sign-in and recovery code requests is padded to FLOOR_MS from the request start, so response time says
 *            nothing about the number.
 * USAGE:     $t = new CodeTiming();  $t->start();  ...work...;  $t->pad(CodeTiming::FLOOR_MS);
 *            $t->overran();      // true when the work alone took longer than the floor (nothing was slept)
 *            $t->elapsedMs();    // milliseconds from start() to the end of pad()
 *            Test seams: the constructor takes the millisecond clock and the sleep (microseconds).
 * DEPENDS:   Nothing.
 */

namespace Tygh\Addons\Kwtsms\Codes;

final class CodeTiming
{
    /** The floor of every sign-in and recovery code answer on a host without a finish function (P3-43). */
    public const FLOOR_MS = 3000;

    /** @var callable (): int */
    private $clock_ms;

    /** @var callable (int $us): void */
    private $sleep_us;

    /** @var int|null */
    private $started;

    /** @var bool */
    private $overran = false;

    /** @var int */
    private $elapsed = 0;

    /**
     * @param callable|null $clock_ms Returns the current time in milliseconds; null = the monotonic clock
     * @param callable|null $sleep_us Sleeps this many microseconds; null = usleep()
     */
    public function __construct(?callable $clock_ms = null, ?callable $sleep_us = null)
    {
        $this->clock_ms = $clock_ms ?? static function (): int {
            return (int) floor(hrtime(true) / 1000000);
        };
        $this->sleep_us = $sleep_us ?? static function (int $us): void {
            usleep($us);
        };
    }

    public function start(): void
    {
        $this->started = $this->now();
        $this->overran = false;
        $this->elapsed = 0;
    }

    /**
     * Sleeps the rest of $floor_ms. Without start() the floor counts from now; a clock that went backwards sleeps at
     * most the whole floor.
     */
    public function pad(int $floor_ms): void
    {
        $floor_ms = max(0, $floor_ms);
        $started = $this->started ?? $this->now();
        $used = max(0, $this->now() - $started);
        $this->overran = $used > $floor_ms;
        if ($used < $floor_ms) {
            call_user_func($this->sleep_us, ($floor_ms - $used) * 1000);
            $used = $floor_ms;
        }
        $this->elapsed = $used;
    }

    public function overran(): bool
    {
        return $this->overran;
    }

    public function elapsedMs(): int
    {
        return $this->elapsed;
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock_ms);
    }
}
