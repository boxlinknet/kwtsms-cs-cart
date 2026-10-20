<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Sms/SendResult.php
 * CREATED:   2026-10-21 00:06 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The answer of SmsService::send(): one entry per recipient (raw input, normalized number, canonical
 *            outcome, reason, code, attempt / message / outbox ids), the msg-ids, points charged, balance after,
 *            the test flag, and an overall status: sent, partial, failed, skipped or deferred.
 * WHY:       PRD 6.2 step 16 and Appendix B. Callers (order events, the test SMS form, resend, fn_kwtsms_send)
 *            need one stable shape: the form shows msg-id, points and balance after; an interactive caller shows
 *            errorMessage(); fn_kwtsms_send() returns toArray().
 * USAGE:     $r = $sms->send($phones, $text, $context);
 *            $r->status();          // sent | partial | failed | skipped | deferred
 *            $r->acceptedCount();   // recipients the gateway accepted (accepted, test_queued, partial_accept)
 *            $r->outcomes();        // raw phone => outcome (an exact repeat of one raw string keeps the last)
 *            $r->messageIds();  $r->errorCode();  $r->errorMessage();  $r->toArray();
 *            Status rules (duplicates never count): every recipient accepted -> sent (partial when the gateway
 *            accepted fewer numbers than it was given); some accepted -> partial; none accepted: any deferred ->
 *            deferred, else any failed / unknown / rejected / expired -> failed, else (all blocked locally) skipped.
 * DEPENDS:   Nothing.
 */

namespace Tygh\Addons\Kwtsms\Sms;

final class SendResult
{
    public const SENT = 'sent';
    public const PARTIAL = 'partial';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';
    public const DEFERRED = 'deferred';

    /** Outcomes where the gateway took the number. */
    public const ACCEPTED_OUTCOMES = ['accepted', 'test_queued', 'partial_accept'];

    /** Outcomes that need the admin's attention (as opposed to a local block). */
    public const FAILED_OUTCOMES = ['failed', 'unknown', 'rejected', 'expired'];

    /** @var array<int,array<string,mixed>> */
    private $recipients;

    /** @var array<string,mixed> */
    private $meta;

    /** @var string */
    private $status;

    /**
     * @param array<int,array<string,mixed>> $recipients phone_raw, phone, outcome, reason, code, attempt_id,
     *                                                   message_id, outbox_id
     * @param array<string,mixed>            $meta       message_ids, points_charged, balance_after, test,
     *                                                   error_code, error_message
     */
    public function __construct(array $recipients, array $meta = [])
    {
        $this->recipients = [];
        foreach ($recipients as $recipient) {
            $this->recipients[] = [
                'phone_raw' => (string) ($recipient['phone_raw'] ?? ''),
                'phone' => (string) ($recipient['phone'] ?? ''),
                'outcome' => (string) ($recipient['outcome'] ?? 'failed'),
                'reason' => (string) ($recipient['reason'] ?? ''),
                'code' => isset($recipient['code']) && $recipient['code'] !== '' ? (string) $recipient['code'] : null,
                'attempt_id' => (int) ($recipient['attempt_id'] ?? 0),
                'message_id' => (int) ($recipient['message_id'] ?? 0),
                'outbox_id' => (int) ($recipient['outbox_id'] ?? 0),
            ];
        }
        $this->meta = $meta + [
            'message_ids' => [],
            'points_charged' => 0.0,
            'balance_after' => null,
            'test' => false,
            'error_code' => null,
            'error_message' => '',
            'status' => null,
        ];
        $this->status = is_string($this->meta['status']) ? $this->meta['status'] : $this->computeStatus();
    }

    /**
     * A send refused before the pipeline ran (for example more than 1000 recipients for fn_kwtsms_send()).
     */
    public static function refused(string $code, string $message = ''): self
    {
        return new self([], ['status' => self::FAILED, 'error_code' => $code, 'error_message' => $message]);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function acceptedCount(): int
    {
        $count = 0;
        foreach ($this->recipients as $recipient) {
            if (in_array($recipient['outcome'], self::ACCEPTED_OUTCOMES, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<string,string> raw phone => outcome
     */
    public function outcomes(): array
    {
        $out = [];
        foreach ($this->recipients as $recipient) {
            $out[$recipient['phone_raw']] = $recipient['outcome'];
        }

        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recipients(): array
    {
        return $this->recipients;
    }

    /**
     * @return string[]
     */
    public function messageIds(): array
    {
        return array_values(array_map('strval', (array) $this->meta['message_ids']));
    }

    /**
     * The code of the first recipient that was not sent (a kwtSMS ERRnnn or LOCAL_* code, else its outcome),
     * or the code of a refused send; null when everything went out.
     */
    public function errorCode(): ?string
    {
        if (is_string($this->meta['error_code']) && $this->meta['error_code'] !== '') {
            return $this->meta['error_code'];
        }
        foreach ($this->recipients as $recipient) {
            $outcome = $recipient['outcome'];
            if ($outcome === 'duplicate' || in_array($outcome, self::ACCEPTED_OUTCOMES, true)) {
                continue;
            }

            return $recipient['code'] ?? $recipient['outcome'];
        }

        return null;
    }

    /** Text for an interactive caller to show ('' for background sends and when nothing failed). */
    public function errorMessage(): string
    {
        return (string) $this->meta['error_message'];
    }

    public function pointsCharged(): float
    {
        return (float) $this->meta['points_charged'];
    }

    public function balanceAfter(): ?float
    {
        return $this->meta['balance_after'] === null ? null : (float) $this->meta['balance_after'];
    }

    public function isTest(): bool
    {
        return (bool) $this->meta['test'];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $ids = $this->messageIds();

        return [
            'status' => $this->status,
            'accepted' => $this->acceptedCount(),
            'outcomes' => $this->outcomes(),
            'recipients' => $this->recipients,
            'message_ids' => $ids,
            'msg_id' => $ids[0] ?? null,
            'points_charged' => $this->pointsCharged(),
            'balance_after' => $this->balanceAfter(),
            'test' => $this->isTest(),
            'error_code' => $this->errorCode(),
            'error_message' => $this->errorMessage(),
        ];
    }

    private function computeStatus(): string
    {
        $counted = array_values(array_filter(
            array_column($this->recipients, 'outcome'),
            static function (string $o): bool {
                return $o !== 'duplicate';
            }
        ));
        if ($counted === []) {
            return self::SKIPPED;
        }
        $accepted = array_intersect($counted, self::ACCEPTED_OUTCOMES);
        if (count($accepted) === count($counted)) {
            return in_array('partial_accept', $counted, true) ? self::PARTIAL : self::SENT;
        }
        if ($accepted !== []) {
            return self::PARTIAL;
        }
        if (in_array('deferred', $counted, true)) {
            return self::DEFERRED;
        }

        return array_intersect($counted, self::FAILED_OUTCOMES) !== [] ? self::FAILED : self::SKIPPED;
    }
}
