<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Codes/CodeAvailability.php
 * CREATED:   2026-10-24 12:36 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Whether a code SMS can be sent for a storefront right now (state()), what a send result means for a code
 *            request (classify()), and the admin alert that follows a "not sendable" state (alert()).
 * WHY:       Task M1.4 (SafatMall I3; rulings P3-13, P3-14, P3-24, PRD 6.12 "OTP blocked with KWT-SMS"). A code
 *            feature must never skip its verification silently: when SMS sending is off-line the request is paused or
 *            (setting otp_unreachable = continue) let through with an alert, and a per-number outcome (rate limit,
 *            number not covered) is never a reason to bypass. This class is the one place that names those states, so
 *            CodeRequest (M1.8a) and the forms decide from one answer.
 * USAGE:     $av = Tygh::$app['kwtsms.code_availability'];
 *            $av->state(1);   // 'ok' | 'off' | 'gateway' | 'balance' | 'pilot' | 'sender'
 *              off      global sending is OFF (enabled = N): every code feature is inactive, forms behave like core
 *              gateway  the breaker is open, sending is suspended, or the add-on is not configured
 *              balance  the cached balance is under 1 credit (a known balance; an unknown one is "not configured")
 *              pilot    pilot mode (only the admin numbers receive SMS)
 *              sender   the storefront's Sender ID is KWT-SMS and test mode is OFF (nothing would reach a handset)
 *            $av->classify($outcome, $reason);   // 'sent' | 'number' | 'busy' | 'unsendable'
 *            $av->alert('balance');              // bell and e-mail, once per reason per 24 h (AdminAlerts)
 *            state() reads cached state only: no API call, ever.
 * DEPENDS:   Settings\ConfigInterface, Gateway\GatewayState, Storefronts\SenderResolver, Alerts\AdminAlerts (optional).
 */

namespace Tygh\Addons\Kwtsms\Codes;

use Tygh\Addons\Kwtsms\Alerts\AdminAlerts;
use Tygh\Addons\Kwtsms\Gateway\GatewayState;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Storefronts\SenderResolver;

class CodeAvailability
{
    public const OK = 'ok';
    public const OFF = 'off';
    public const GATEWAY = 'gateway';
    public const BALANCE = 'balance';
    public const PILOT = 'pilot';
    public const SENDER = 'sender';

    /** The reasons alert() names; any other value is raised as "other". */
    public const ALERT_REASONS = ['gateway', 'balance', 'pilot', 'sender', 'daily_cap', 'other'];

    public const SENT = 'sent';
    public const NUMBER = 'number';
    public const BUSY = 'busy';
    public const UNSENDABLE = 'unsendable';

    /** Outcomes where the gateway took the number. */
    private const SENT_OUTCOMES = ['accepted', 'test_queued', 'partial_accept'];

    /** Outcomes about this number only (P3-10): never a bypass, never an alert. */
    private const NUMBER_OUTCOMES = [
        'rate_limited', 'no_coverage', 'blocked_country', 'invalid', 'duplicate', 'no_phone',
    ];

    /** @var ConfigInterface */
    private $config;

    /** @var GatewayState */
    private $state;

    /** @var SenderResolver */
    private $senders;

    /** @var AdminAlerts|null */
    private $alerts;

    public function __construct(
        ConfigInterface $config,
        GatewayState $state,
        SenderResolver $senders,
        ?AdminAlerts $alerts = null
    ) {
        $this->config = $config;
        $this->state = $state;
        $this->senders = $senders;
        $this->alerts = $alerts;
    }

    /**
     * 'ok', 'off' or the reason a code SMS cannot be sent for this storefront now (the first that applies, in the order
     * of the class comment).
     */
    public function state(int $storefront_id): string
    {
        if (!$this->config->enabled()) {
            return self::OFF;
        }
        if ($this->state->suspendedCode() !== null || $this->state->breaker()['open']) {
            return self::GATEWAY;
        }
        $configured = $this->state->isConfigured($this->config);
        if ($configured[0] !== true) {
            return self::GATEWAY;
        }
        $balance = $this->state->balance();
        if ($balance !== null && (float) $balance['available'] < 1.0) {
            return self::BALANCE;
        }
        if ($this->config->pilotMode()) {
            return self::PILOT;
        }
        if (
            $this->senders->forStorefront($storefront_id) === SenderResolver::KWT_TEST_SENDER
            && !$this->config->testMode()
        ) {
            return self::SENDER;
        }

        return self::OK;
    }

    /**
     * What a send result means for the code request: sent (the gateway took it), number (this number only: rate
     * limit, no coverage, country, bad number), busy (the store hourly cap) or unsendable (everything else, including
     * every deferral and failure that is not about the number). $reason is the attempt reason; today it does not change
     * the answer, it is part of the signature because a deferral is told by its reason (P3-13).
     */
    public function classify(string $outcome, string $reason): string
    {
        if (in_array($outcome, self::SENT_OUTCOMES, true)) {
            return self::SENT;
        }
        if (in_array($outcome, self::NUMBER_OUTCOMES, true)) {
            return self::NUMBER;
        }
        if ($outcome === 'blocked_hourly_cap') {
            return self::BUSY;
        }

        return self::UNSENDABLE;
    }

    /**
     * Raises the admin alert otp_unavailable (bell and e-mail) for a reason, once per reason per 24 hours (P3-24).
     * Never throws: a failed alert must not break the request.
     */
    public function alert(string $reason): void
    {
        if ($this->alerts === null) {
            return;
        }
        $reason = in_array($reason, self::ALERT_REASONS, true) ? $reason : 'other';
        try {
            $this->alerts->raise('otp_unavailable', ['[reason]' => $reason], true, 86400, $reason);
        } catch (\Throwable $e) {
            // The alert is best effort; AdminAlerts already logs its own failures.
            return;
        }
    }
}
