<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Codes/OtpResult.php
 * CREATED:   2026-10-23 21:26 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The answer of OtpService::issue() and verify(): a status and the numbers that go with it.
 * WHY:       Task M1.3 (SafatMall D1, D4). The code itself lives only in an "issued" result, in memory, to be put in
 *            the SMS: __debugInfo() and jsonSerialize() leave it out, so a dump or a JSON answer of the result never
 *            carries it.
 * USAGE:     issue():  ISSUED (code, code_id, expires_in, step), WAIT (retry_after), LOCKED (retry_after)
 *            verify(): OK, WRONG (attempts_left), MALFORMED, LOCKED (retry_after), EXPIRED, NONE, IP_LIMITED
 *                      (retry_after)
 *            $r->status;  $r->retry_after;  $r->attempts_left;  $r->expires_in;  $r->code_id;  $r->code();  $r->step
 *            step = ['bucket', 'prev_hits', 'prev_last_at', 'claimed_at'], what revoke() gives back to the limiter.
 * DEPENDS:   Nothing.
 */

namespace Tygh\Addons\Kwtsms\Codes;

final class OtpResult implements \JsonSerializable
{
    public const ISSUED = 'issued';
    public const WAIT = 'wait';
    public const LOCKED = 'locked';
    public const OK = 'ok';
    public const WRONG = 'wrong';
    public const MALFORMED = 'malformed';
    public const EXPIRED = 'expired';
    public const NONE = 'none';
    public const IP_LIMITED = 'ip_limited';

    /** @var string */
    public $status;

    /** @var int Seconds until the next try may succeed (wait, locked, ip_limited) */
    public $retry_after = 0;

    /** @var int Wrong attempts left before the lockout (wrong) */
    public $attempts_left = 0;

    /** @var int Seconds the new code is valid (issued) */
    public $expires_in = 0;

    /** @var int The code row (issued) */
    public $code_id = 0;

    /**
     * 2026-10-24 05:58 +03: Task M1.3 fix round 1 (review minor 5), task 596. The code is held XOR-ed with a random
     * pad of the same length (two binary strings), so var_export(), (array), get_object_vars() and print_r() of the
     * object never show the digits; read it with code(). This is not encryption, only a guard against a dump. (A
     * closure does not work: print_r() lists its bound variables.)
     *
     * @var string
     */
    private $masked = '';

    /** @var string */
    private $pad = '';

    /** @var array<string,int|string> The send step claimed by issue(), for revoke() */
    public $step = [];

    private function __construct(string $status)
    {
        $this->status = $status;
    }

    /**
     * @param array<string,int|string> $step
     */
    public static function issued(
        #[\SensitiveParameter]
        string $code,
        int $code_id,
        int $expires_in,
        array $step
    ): self {
        $r = new self(self::ISSUED);
        $r->pad = random_bytes(max(1, strlen($code)));
        $r->masked = $code ^ $r->pad;
        $r->code_id = $code_id;
        $r->expires_in = $expires_in;
        $r->step = $step;

        return $r;
    }

    public static function wait(int $retry_after): self
    {
        $r = new self(self::WAIT);
        $r->retry_after = max(1, $retry_after);

        return $r;
    }

    public static function locked(int $retry_after): self
    {
        $r = new self(self::LOCKED);
        $r->retry_after = max(1, $retry_after);

        return $r;
    }

    public static function ipLimited(int $retry_after): self
    {
        $r = new self(self::IP_LIMITED);
        $r->retry_after = max(1, $retry_after);

        return $r;
    }

    public static function wrong(int $attempts_left): self
    {
        $r = new self(self::WRONG);
        $r->attempts_left = max(0, $attempts_left);

        return $r;
    }

    /**
     * A result that carries no number: ok, malformed, expired, none.
     */
    public static function of(string $status): self
    {
        return new self($status);
    }

    /**
     * The code of an issued result, '' for every other result. Put it in the SMS context secret values, never in a
     * log or an answer.
     */
    public function code(): string
    {
        return $this->masked === '' ? '' : (string) ($this->masked ^ $this->pad);
    }

    /**
     * @return array<string,int|string> what serialize() keeps: the plain fields only (neither the code nor its pad)
     */
    public function __serialize(): array
    {
        return $this->jsonSerialize();
    }

    /**
     * @return array<string,int|string> what var_dump() and print_r() show: never the code or the step
     */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize() + ['code_id' => $this->code_id];
    }

    /**
     * @return array<string,int|string> the fields a controller may answer with: never the code
     */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status,
            'retry_after' => $this->retry_after,
            'attempts_left' => $this->attempts_left,
            'expires_in' => $this->expires_in,
        ];
    }
}
