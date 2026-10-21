<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Orders/Diagnostics.php
 * CREATED:   2026-10-21 01:53 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Writes the attempt rows that explain an order SMS that was NOT sent before it reached the send
 *            pipeline: switched_off and not_notified for receivers core never handed to the transport
 *            (recordSkipped(), PRD 4.2 rule 6), and the transport's own outcomes (superseded, duplicate_event,
 *            no_phone, no_template, switched_off for vendor-triggered SMS) through recordOutcome().
 * WHY:       An admin who expected an SMS must find a row saying why: the SMS switch of that status is off, the
 *            Notify customer box was unticked (or the order came from the REST API / admin area, where core sets
 *            every notify rule to false), the order was new so the status SMS was replaced by "order received", the
 *            same status was already sent within 24 h, and so on. One row per receiver (one for all the admin
 *            numbers since 2026-10-21 04:21 +03, see USAGE), the customer row carrying the order's phone.
 *            The decision "core handed it over" is recomputed exactly as the dispatcher does it: the fail-closed
 *            switch of the event and receiver, then core's Ruleset of the same notify rules.
 * USAGE:     $diag->recordSkipped($order_info, 'order.status_changed.c', $force_notification);        // C and A
 *            $diag->recordSkipped($order_info, 'kwtsms.order_placed', $rules, ['C']);                 // C only
 *            $diag->recordOutcome('duplicate_event', 'status:12:C:C', $order_info, 'order.status_changed.c', 'C');
 *            2026-10-21 04:21 +03, Task 1.9 fix round 1 item 4, task 596: recordSkipped() writes nothing while SMS
 *            is off or the add-on is not set up (the $active callable: enabled and configured); the administrators
 *            get ONE row per outcome, carrying the first admin number, not one row per admin number.
 *            2026-10-21 05:08 +03: recordOutcome() follows the same gate (Task 1.9 fix round 2 item 3), task 596.
 * DEPENDS:   Log\AttemptLog, Settings\ConfigInterface, Sms\PhoneNormalizer, Notifications\OrderSmsData; the switch
 *            reader and core's notification settings Factory (wired in ServiceProvider).
 */

namespace Tygh\Addons\Kwtsms\Orders;

use Tygh\Addons\Kwtsms\Log\AttemptLog;
use Tygh\Addons\Kwtsms\Notifications\EventsSchemaExtender;
use Tygh\Addons\Kwtsms\Notifications\OrderSmsData;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\PhoneNormalizer;

class Diagnostics
{
    public const SWITCHED_OFF = 'switched_off';
    public const NOT_NOTIFIED = 'not_notified';

    /** @var AttemptLog */
    private $attempts;

    /** @var ConfigInterface */
    private $config;

    /** @var callable (string $event_id, string $receiver): bool */
    private $switch;

    /** @var callable (array $rules): \Tygh\Notifications\Settings\Ruleset */
    private $ruleset;

    /** @var callable|null (int $user_id): string */
    private $profile_phone;

    /** @var callable|null (): array{user_id:int,company_id:int} */
    private $actor;

    /** @var callable|null (): bool, SMS on and the add-on set up (2026-10-21 04:21 +03, fix round 1 item 4, task 596) */
    private $active;

    /**
     * @param callable      $switch        (string $event_id, string $receiver): bool, the fail-closed SMS switch
     * @param callable      $ruleset       (array $rules): Ruleset, core's notification settings Factory::create
     * @param callable|null $profile_phone (int $user_id): string, last link of the phone chain
     * @param callable|null $actor         (): ['user_id' => int, 'company_id' => int], who caused the change
     * @param callable|null $active        (): bool, true while SMS is on and the add-on is set up; null = the global
     *                                     switch only (2026-10-21 04:21 +03, fix round 1 item 4, task 596)
     */
    public function __construct(
        AttemptLog $attempts,
        ConfigInterface $config,
        callable $switch,
        callable $ruleset,
        ?callable $profile_phone = null,
        ?callable $actor = null,
        ?callable $active = null
    ) {
        $this->attempts = $attempts;
        $this->config = $config;
        $this->switch = $switch;
        $this->ruleset = $ruleset;
        $this->profile_phone = $profile_phone;
        $this->actor = $actor;
        $this->active = $active;
    }

    /**
     * One row per receiver core did not hand to the SMS transport.
     *
     * @param array<string,mixed> $order_info
     * @param array<string,mixed> $rules      The notify rules of the change ($force_notification)
     * @param string[]            $receivers  Receivers to consider
     */
    public function recordSkipped(
        array $order_info,
        string $event_id,
        array $rules,
        array $receivers = ['C', 'A']
    ): void {
        if ((int) ($order_info['order_id'] ?? 0) <= 0) {
            return;
        }
        try {
            // 2026-10-21 04:21 +03: no rows while SMS is off or not set up (fix round 1 item 4), task 596.
            if (!$this->active()) {
                return;
            }
            $probe = [];
            foreach ($receivers as $receiver) {
                $probe[$receiver] = [EventsSchemaExtender::TRANSPORT_ID => true];
            }
            $notified = call_user_func($this->ruleset, $rules)->apply($probe);
            foreach ($receivers as $receiver) {
                if (!call_user_func($this->switch, $event_id, $receiver)) {
                    $this->recordOutcome(self::SWITCHED_OFF, '', $order_info, $event_id, $receiver);
                } elseif (empty($notified[$receiver][EventsSchemaExtender::TRANSPORT_ID])) {
                    $this->recordOutcome(self::NOT_NOTIFIED, '', $order_info, $event_id, $receiver);
                }
            }
        } catch (\Throwable $e) {
            error_log('kwtsms: order diagnostics failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    /**
     * Attempt rows for one outcome of one receiver: the customer's phone, or one row per admin number (one row
     * without a phone when there is none).
     *
     * @param array<string,mixed> $order_info
     * @param string[]|null       $phones     Numbers to write; null = the receiver's own numbers
     */
    public function recordOutcome(
        string $outcome,
        string $reason,
        array $order_info,
        string $event_id,
        string $receiver,
        ?array $phones = null
    ): void {
        // 2026-10-21 05:08 +03: the transport's rows (superseded, duplicate_event, no_phone, no_template) follow the
        // same gate (fix round 2 item 3), task 596.
        if (!$this->active()) {
            return;
        }
        $actor = $this->actor !== null ? (array) call_user_func($this->actor) : [];
        $default_cc = $this->config->defaultCountryCode();
        $hint = PhoneNormalizer::callingCodeForIso(OrderSmsData::countryIso($order_info)) ?? $default_cc;
        if ($phones === null) {
            $phones = $receiver === 'A' ? $this->config->adminPhones() : [$this->customerPhone($order_info)];
        }
        // 2026-10-21 04:21 +03: one row for the administrators, the first admin number standing for all (fix round 1
        // item 4), task 596.
        $phones = $receiver === 'A' ? array_slice(array_values($phones), 0, 1) : $phones;
        if ($phones === []) {
            $phones = [''];
        }
        $base = [
            'source' => 'event',
            'event_id' => $event_id,
            'receiver' => $receiver,
            'company_id' => (int) ($order_info['company_id'] ?? 0),
            'parent_order_id' => (int) ($order_info['parent_order_id'] ?? 0),
            'storefront_id' => (int) ($order_info['storefront_id'] ?? 0),
            'order_id' => (int) ($order_info['order_id'] ?? 0),
            'user_id' => (int) ($order_info['user_id'] ?? 0),
            'actor_user_id' => (int) ($actor['user_id'] ?? 0),
            'outcome' => $outcome,
            'reason' => $reason,
            // 2026-10-21 03:12 +03: the test flag at the time of the order event (Dashboard counters, PRD 6.9);
            // Task 1.11, task 596.
            'is_test' => $this->config->testMode() ? 'Y' : 'N',
        ];
        foreach ($phones as $raw) {
            $raw = (string) $raw;
            $this->attempts->record($base + [
                'phone_raw' => $raw,
                'phone' => $raw === '' ? '' : PhoneNormalizer::normalize($raw, $receiver === 'A' ? $default_cc : $hint),
            ]);
        }
    }

    /**
     * True while SMS is on and the add-on is set up (the $active callable; the global switch alone without one).
     * 2026-10-21 05:08 +03: added, shared by recordSkipped() and recordOutcome() (fix round 2 item 3), task 596.
     * 2026-10-21 07:53 +03: public, the one predicate OrderNotifier and OrderHooks also ask before they write
     * anything (Task 1.9 follow-up of the Task 1.14 review), task 596.
     */
    public function active(): bool
    {
        return $this->active !== null ? (bool) call_user_func($this->active) : $this->config->enabled();
    }

    /**
     * @param array<string,mixed> $order_info
     */
    private function customerPhone(array $order_info): string
    {
        $profile = '';
        $user_id = (int) ($order_info['user_id'] ?? 0);
        if ($this->profile_phone !== null && $user_id > 0) {
            $profile = (string) call_user_func($this->profile_phone, $user_id);
        }

        return OrderSmsData::customerPhone($order_info, $profile);
    }
}
