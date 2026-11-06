<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Alerts/FailSpike.php
 * CREATED:   2026-11-06 22:05 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The send-failure spike alert (P3-41, P3-57): with fail_spike_alert ON, more than fail_spike_threshold
 *            failed sends in the last 900 s raise the admin bell notice and e-mail (AdminAlerts condition
 *            send_fail_spike) and leave one pending staff SMS (event kwtsms.send_fail_spike, receiver A).
 * WHY:       kwtsms.com best-practices article section 9 (alert on a sudden increase in errors). Default OFF: an
 *            upgrade never starts sending anything. check() runs on every cron run (the health job), raises at most
 *            once per clock hour (the hour is stored, so a quiet hour costs no query); dispatchPending() runs in the
 *            drain_outbox step, writes "sent" BEFORE it dispatches (the LowBalanceSms pattern: a failed send is never
 *            retried, so the cron cannot storm) and never counts itself: the attempts of the alert SMS are left out of
 *            the count, so a failing alert SMS cannot raise the alert again. A failure is outcome failed or rejected,
 *            unknown except a cap reservation, or deferred as gateway_unreachable or suspended (Log\
 *            FailureCountStoreInterface). The alert text and the SMS carry two numbers only: no phone number, no
 *            reason, no secret.
 *            Storage key kwtsms_fail_spike_sms (JSON): {"hour": <start of the clock hour last raised>, "pending":
 *            {"at": <time raised>, "hour": <hour start>, "count": n}, "sent": <hour start already consumed>}.
 *            Dedupe key of the SMS event: send_fail_spike:<hour start>:A (window 0).
 * USAGE:     $spike = new FailSpike($config, $attempts, $alerts, $store, $dispatch);
 *            $spike->check();             // true when the alert was raised now; CronRunner health job
 *            $spike->dispatchPending();   // true when the SMS event was dispatched now; CronRunner drain_outbox
 * DEPENDS:   Settings\ConfigInterface (failSpikeAlert, failSpikeThreshold), Log\AttemptLog, Alerts\AdminAlerts,
 *            Storage\KeyValueStoreInterface, a dispatch callable (string $event_id, array $data): void.
 */

namespace Tygh\Addons\Kwtsms\Alerts;

use Tygh\Addons\Kwtsms\Log\AttemptLog;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Storage\KeyValueStoreInterface;

class FailSpike
{
    public const KEY = 'kwtsms_fail_spike_sms';
    public const EVENT_ID = 'kwtsms.send_fail_spike';
    public const CONDITION = 'send_fail_spike';

    /** The window the failures are counted over, seconds (P3-57). */
    public const WINDOW_SECONDS = 900;

    /** A pending SMS older than this is consumed without being sent (the cron was down). */
    public const MAX_AGE = 3600;

    /** @var ConfigInterface */
    private $config;

    /** @var AttemptLog */
    private $attempts;

    /** @var AdminAlerts */
    private $alerts;

    /** @var KeyValueStoreInterface */
    private $store;

    /** @var callable|null */
    private $dispatch;

    /** @var callable|null */
    private $clock;

    /**
     * @param callable|null $dispatch (string $event_id, array $data): void, through event.dispatcher; null = no SMS
     * @param callable|null $clock    Returns the current Unix time (tests); null = time()
     */
    public function __construct(
        ConfigInterface $config,
        AttemptLog $attempts,
        AdminAlerts $alerts,
        KeyValueStoreInterface $store,
        ?callable $dispatch = null,
        ?callable $clock = null
    ) {
        $this->config = $config;
        $this->attempts = $attempts;
        $this->alerts = $alerts;
        $this->store = $store;
        $this->dispatch = $dispatch;
        $this->clock = $clock;
    }

    /**
     * Counts the failures of the last 900 s and raises the alert when there are more than the threshold. At most one
     * alert per clock hour. Never throws.
     *
     * @return bool True when the alert was raised now
     */
    public function check(): bool
    {
        try {
            if (!$this->config->failSpikeAlert()) {
                return false;
            }
            $now = $this->now();
            $hour = $now - $now % 3600;
            $data = self::read($this->store->getFresh(self::KEY));
            if ((int) ($data['hour'] ?? 0) >= $hour) {
                return false;
            }
            $count = $this->attempts->countFailures(self::WINDOW_SECONDS, [self::EVENT_ID]);
            if ($count <= $this->config->failSpikeThreshold()) {
                return false;
            }
            $raised = $this->alerts->raise(
                self::CONDITION,
                ['[count]' => $count, '[minutes]' => self::WINDOW_SECONDS / 60],
                true,
                3600,
                (string) $hour
            );
            // Fresh again: the alert bookkeeping shares no key with this one, but a concurrent run might have written.
            $data = self::read($this->store->getFresh(self::KEY));
            $data['hour'] = $hour;
            if ($raised) {
                $data['pending'] = ['at' => $now, 'hour' => $hour, 'count' => $count];
            }
            $this->store->set(self::KEY, (string) json_encode($data));

            return $raised;
        } catch (\Throwable $e) {
            error_log('kwtsms: send failure spike check failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());

            return false;
        }
    }

    /**
     * Dispatches kwtsms.send_fail_spike for the pending alert, if any. The alert is consumed first (at most once), and
     * is consumed without an SMS when it is stale or the switch was turned off since. Never throws.
     *
     * @return bool True when the event was dispatched now
     */
    public function dispatchPending(): bool
    {
        try {
            $data = self::read($this->store->getFresh(self::KEY));
            $pending = $data['pending'] ?? null;
            if (!is_array($pending) || !isset($pending['hour']) || !is_numeric($pending['hour'])) {
                return false;
            }
            $hour = (int) $pending['hour'];
            if ($hour <= 0 || $hour <= (int) ($data['sent'] ?? 0)) {
                return false;
            }
            // Consumed first: whatever happens below, this alert's SMS is never dispatched again.
            $data['sent'] = $hour;
            unset($data['pending']);
            $this->store->set(self::KEY, (string) json_encode($data));

            if (
                $this->dispatch === null
                || !$this->config->failSpikeAlert()
                || $this->now() - (int) ($pending['at'] ?? 0) > self::MAX_AGE
            ) {
                return false;
            }
            call_user_func($this->dispatch, self::EVENT_ID, [
                'count' => max(0, (int) ($pending['count'] ?? 0)),
                'minutes' => (int) (self::WINDOW_SECONDS / 60),
                'crossing_at' => $hour,
            ]);

            return true;
        } catch (\Throwable $e) {
            error_log('kwtsms: send failure spike SMS failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());

            return false;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function read(?string $raw): array
    {
        $data = json_decode((string) $raw, true);

        return is_array($data) ? $data : [];
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }
}
