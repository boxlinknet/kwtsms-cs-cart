<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/LogResender.php
 * CREATED:   2026-10-21 05:52 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Resend of one attempt from the Logs detail view: whether it may be resent (verdict), the outbox row behind
 *            a deferred attempt, the resends already made of it, and the resend itself through the one send pipeline.
 * WHY:       PRD 6.8: Resend for failed, deferred, expired or unknown attempts, POST with the gateway privilege (the
 *            controller), all gates apply, linked to the original. So the text goes through SmsService::send() with
 *            context retry_of = the original attempt id (source manual, interactive, audience admin): every switch,
 *            cap, country, coverage, balance, pilot, quiet-hours and test-mode rule decides again, and the new attempt
 *            row carries retry_of. Refused: an attempt whose outbox row is still queued (it goes out on its own) or was
 *            sent on a later try, a masked text (an OTP code or reset link is never resent), an OTP source, a number
 *            that is not stored, a text that is no longer stored, and an attempt that was already resent once (its
 *            resend is linked; a resend that failed can itself be resent).
 *            2026-10-21 08:04 +03, Task 1.14 fix round 1: an outbox row that still holds secret values (a held row
 *            is not final, so its OTP is not masked yet) is refused as masked (item 2); a resend of any attempt of
 *            the same outbox row and number counts as "already resent", and a held row of that one number is closed
 *            (expired) on resend so it never expires into another resendable row (item 5); verdicts() decides for
 *            many attempts with two list reads and one read per distinct message or outbox row (item 7).
 * USAGE:     $resender = LogResender::fromContainer(Tygh::$app);
 *            $resender->verdict($attempt_row);   // ['ok' => bool, 'reason' => 'kwtsms.logs.resend.<why>']
 *            $resender->verdicts($attempt_rows); // attempt_id => verdict, for a message's recipients
 *            $resender->resend(15, $admin_id, $ip);   // ['ok' => true, 'outcome' => 'test_queued', 'attempt_id' => 16]
 * DEPENDS:   Log\{LogStoreInterface,Schema,Redactor,DebugLog}, Queue\Outbox, Sms\{SmsService,SendResult}.
 */

namespace Tygh\Addons\Kwtsms\Admin;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Log\LogStoreInterface;
use Tygh\Addons\Kwtsms\Log\Redactor;
use Tygh\Addons\Kwtsms\Log\Schema;
use Tygh\Addons\Kwtsms\Queue\Outbox;
use Tygh\Addons\Kwtsms\Sms\SmsService;

class LogResender
{
    /** PRD 6.8: the outcomes an administrator may resend. */
    public const RESENDABLE = ['failed', 'deferred', 'expired', 'unknown'];

    /** Outbox states in which the row still goes out on its own. */
    private const QUEUED = [Outbox::STATUS_PENDING, Outbox::STATUS_SENDING];

    /**
     * 2026-10-21 08:04 +03: how far before an attempt the other attempts of its outbox row can lie (the deferred
     * attempt is written when the row is queued; quiet hours can hold a row up to a day, plus its expiry), fix round 1
     * item 5, task 596.
     */
    private const SIBLING_WINDOW = 172800;

    /** @var array<int,array<string,mixed>|null> message rows read during one verdicts() call */
    private $messages = [];

    /** @var array<int,array<string,mixed>|null> outbox rows read during one verdicts() call */
    private $rows = [];

    /** @var LogStoreInterface */
    private $store;

    /** @var SmsService */
    private $sms;

    /** @var Outbox */
    private $outbox;

    /** @var DebugLog */
    private $log;

    public function __construct(LogStoreInterface $store, SmsService $sms, Outbox $outbox, DebugLog $log)
    {
        $this->store = $store;
        $this->sms = $sms;
        $this->outbox = $outbox;
        $this->log = $log;
    }

    /**
     * @param \ArrayAccess<string,mixed> $app Tygh::$app
     */
    public static function fromContainer($app): self
    {
        return new self(
            $app['kwtsms.logs.store'],
            $app['kwtsms.sms'],
            $app['kwtsms.outbox'],
            $app['kwtsms.logs.debug']
        );
    }

    /**
     * Whether this attempt may be resent, and why not.
     *
     * @param array<string,mixed> $attempt An attempt row
     *
     * @return array{ok:bool,reason:string,resent_as:int}
     */
    public function verdict(array $attempt): array
    {
        // 2026-10-21 08:05 +03: one attempt through the batch path, so both always agree (fix round 1), task 596.
        return $this->verdicts([$attempt])[(int) $attempt['attempt_id']];
    }

    /**
     * 2026-10-21 08:05 +03: the verdicts of many attempts (the recipients of a message) with a fixed number of reads:
     * one list read for the other attempts of their outbox rows (only when there are such rows), one for the resends,
     * and one row read per distinct message and outbox row (fix round 1, item 7), task 596. Same order of checks as
     * before: outcome, OTP source, number, already resent, then the text (queued, sent later, masked, none).
     *
     * @param array<int,array<string,mixed>> $attempts Attempt rows
     *
     * @return array<int,array{ok:bool,reason:string,resent_as:int}> attempt_id => verdict, in the order given
     */
    public function verdicts(array $attempts): array
    {
        $this->messages = [];
        $this->rows = [];
        $out = [];
        $candidates = [];
        foreach ($attempts as $attempt) {
            $id = (int) $attempt['attempt_id'];
            $why = self::early($attempt);
            $out[$id] = $why === '' ? null : self::no($why);
            if ($why === '') {
                $candidates[$id] = $attempt;
            }
        }
        $resent = $candidates === [] ? [] : $this->resentAs($candidates);
        foreach ($candidates as $id => $attempt) {
            if (isset($resent[$id])) {
                $out[$id] = self::no('already', $resent[$id]);
                continue;
            }
            $text = $this->text($attempt);
            $out[$id] = $text['why'] !== '' ? self::no($text['why']) : ['ok' => true, 'reason' => '', 'resent_as' => 0];
        }

        return $out;
    }

    /** @return array{ok:bool,reason:string,resent_as:int} */
    private static function no(string $why, int $resent_as = 0): array
    {
        return ['ok' => false, 'reason' => 'kwtsms.logs.resend.' . $why, 'resent_as' => $resent_as];
    }

    /**
     * The checks that need no read: the outcome, an OTP source, a stored number. '' when they pass.
     *
     * @param array<string,mixed> $attempt
     */
    private static function early(array $attempt): string
    {
        if (!in_array((string) $attempt['outcome'], self::RESENDABLE, true)) {
            return 'not_allowed';
        }
        if ((string) $attempt['source'] === 'otp') {
            return 'masked';
        }
        if (preg_match('/^[1-9][0-9]{6,14}$/D', (string) $attempt['phone']) !== 1) {
            return 'no_phone';
        }

        return '';
    }

    /**
     * 2026-10-21 08:05 +03: attempt_id => the newest resend that covers it: a resend of the attempt itself, or of
     * another attempt of the same outbox row to the same number (the same SMS: its deferred and its expired attempt),
     * fix round 1 item 5, task 596. Both reads use the (phone, created_at) index.
     *
     * @param array<int,array<string,mixed>> $candidates attempt_id => attempt row
     *
     * @return array<int,int>
     */
    private function resentAs(array $candidates): array
    {
        $phones = [];
        $outbox = [];
        $from = PHP_INT_MAX;
        // covers[id] = the candidates a resend of attempt id counts for
        $covers = [];
        foreach ($candidates as $id => $attempt) {
            $phones[(string) $attempt['phone']] = true;
            $from = min($from, (int) $attempt['created_at']);
            $covers[$id] = [$id];
            if ((int) $attempt['outbox_id'] > 0) {
                $outbox[(int) $attempt['outbox_id']][] = $id;
            }
        }
        $phones = array_map('strval', array_keys($phones));
        if ($outbox !== []) {
            $siblings = $this->store->select(Schema::ATTEMPTS, [
                'phone' => $phones,
                'outbox_id' => array_keys($outbox),
                'created_from' => max(0, $from - self::SIBLING_WINDOW),
            ], 1000, 0);
            foreach ($siblings as $sibling) {
                $sibling_id = (int) $sibling['attempt_id'];
                foreach ($outbox[(int) $sibling['outbox_id']] ?? [] as $id) {
                    if ($sibling_id !== $id && (string) $candidates[$id]['phone'] === (string) $sibling['phone']) {
                        $covers[$sibling_id][] = $id;
                        $from = min($from, (int) $sibling['created_at']);
                    }
                }
            }
        }
        $resends = $this->store->select(Schema::ATTEMPTS, [
            'phone' => $phones,
            'retry_of' => array_keys($covers),
            'created_from' => $from,
        ], 1000, 0);
        $out = [];
        // Newest first: the first resend seen for a candidate is the one shown.
        foreach ($resends as $resend) {
            foreach ($covers[(int) $resend['retry_of']] ?? [] as $id) {
                if (!isset($out[$id]) && (string) $resend['phone'] === (string) $candidates[$id]['phone']) {
                    $out[$id] = (int) $resend['attempt_id'];
                }
            }
        }

        return $out;
    }

    /**
     * The outbox row behind a deferred attempt (status, tries, reason), or null.
     *
     * @param array<string,mixed> $attempt
     *
     * @return array{id:int,status:string,attempts:int,reason:string}|null
     */
    public function outboxState(array $attempt): ?array
    {
        $id = (int) ($attempt['outbox_id'] ?? 0);
        if ($id < 1) {
            return null;
        }
        $row = $this->outbox->find($id);
        if ($row === null) {
            return null;
        }

        return [
            'id' => $id,
            'status' => (string) $row['status'],
            'attempts' => (int) $row['attempts'],
            'reason' => (string) $row['reason'],
        ];
    }

    /**
     * The resends of an attempt (rows with retry_of = its id), found through the (phone, created_at) index.
     *
     * @param array<string,mixed> $attempt
     *
     * @return array<int,array<string,mixed>>
     */
    public function resendsOf(array $attempt): array
    {
        $phone = (string) ($attempt['phone'] ?? '');
        $id = (int) ($attempt['attempt_id'] ?? 0);
        if ($phone === '' || $id < 1) {
            return [];
        }

        return $this->store->select(
            Schema::ATTEMPTS,
            ['phone' => $phone, 'retry_of' => $id, 'created_from' => (int) $attempt['created_at']],
            20,
            0
        );
    }

    /**
     * Resends one attempt through the full pipeline. Never throws.
     *
     * @return array{ok:bool,reason:string,outcome:string,attempt_id:int,status:string,original:int}
     */
    public function resend(int $attempt_id, int $admin_id, string $ip): array
    {
        $out = [
            'ok' => false, 'reason' => '', 'outcome' => '', 'attempt_id' => 0, 'status' => '',
            'original' => $attempt_id,
        ];
        try {
            $attempt = $this->store->find(Schema::ATTEMPTS, $attempt_id);
            if ($attempt === null) {
                $out['reason'] = 'kwtsms.logs.resend.not_found';

                return $out;
            }
            $verdict = $this->verdict($attempt);
            if (!$verdict['ok']) {
                $out['reason'] = $verdict['reason'];

                return $out;
            }
            $text = $this->text($attempt);
            $context = [
                'source' => 'manual',
                'event_id' => (string) $attempt['event_id'],
                'receiver' => (string) $attempt['receiver'],
                'order_id' => (int) $attempt['order_id'],
                'parent_order_id' => (int) $attempt['parent_order_id'],
                'company_id' => (int) $attempt['company_id'],
                'storefront_id' => (int) $attempt['storefront_id'],
                'user_id' => (int) $attempt['user_id'],
                'actor_user_id' => $admin_id,
                'lang_code' => $text['lang'],
                'audience' => 'admin',
                'interactive' => true,
                'ip' => $ip,
                'retry_of' => $attempt_id,
            ];
            // The stored number is normalized; the + keeps it international so no default-country prefix is added.
            $result = $this->sms->send(['+' . (string) $attempt['phone']], $text['text'], $context);
            $first = $result->recipients()[0] ?? [];
            $out['ok'] = true;
            $out['outcome'] = (string) ($first['outcome'] ?? '');
            $out['attempt_id'] = (int) ($first['attempt_id'] ?? 0);
            $out['status'] = $result->status();
            if ($out['attempt_id'] > 0) {
                $this->closeHeldRow($attempt);
            }
            $this->log->log('info', 'logs.resend', 'Resend of attempt ' . $attempt_id, [
                'original' => $attempt_id, 'attempt_id' => $out['attempt_id'], 'outcome' => $out['outcome'],
                'status' => $out['status'], 'admin_id' => $admin_id,
            ]);
        } catch (\Throwable $e) {
            $this->log->log('error', 'logs.resend_failed', get_class($e) . ': ' . $e->getMessage() . ' in '
                . basename($e->getFile()) . ':' . $e->getLine(), ['original' => $attempt_id]);
            $out['ok'] = false;
            $out['reason'] = 'kwtsms.logs.resend.failed';
        }

        return $out;
    }

    /**
     * The text to resend and its language: the outbox row's original text when the row still exists (R18: the outbox
     * keeps the text before cleaning), else the text of the message row; why not when there is none.
     *
     * @param array<string,mixed> $attempt
     *
     * @return array{text:string,lang:string,why:string}
     */
    private function text(array $attempt): array
    {
        $out = ['text' => '', 'lang' => '', 'why' => ''];
        // 2026-10-21 08:06 +03: each message and outbox row is read once per verdicts() call (fix round 1, item 7),
        // task 596.
        $message = $this->messageRow((int) $attempt['message_id']);
        if ($message !== null) {
            $out['lang'] = (string) $message['lang_code'];
        }
        $row = $this->outboxRow((int) $attempt['outbox_id']);
        if ($row !== null) {
            if (in_array((string) $row['status'], self::QUEUED, true)) {
                $out['why'] = 'queued';

                return $out;
            }
            if ((string) $row['status'] === Outbox::STATUS_DONE) {
                $out['why'] = 'sent_later';

                return $out;
            }
            // 2026-10-21 08:06 +03: a row that still holds secret values (a held row is not final, so its OTP or
            // reset link is not masked yet) is never resent, and its text never reaches a log (fix round 1, item 2),
            // task 596.
            if (isset($row['context']['secret_values']) && $row['context']['secret_values'] !== []) {
                $out['why'] = 'masked';

                return $out;
            }
            $out['text'] = (string) $row['message'];
            if ($out['lang'] === '') {
                $out['lang'] = (string) ($row['context']['lang_code'] ?? '');
            }
        } elseif ($message !== null) {
            if ((string) $message['masked'] === 'Y') {
                $out['why'] = 'masked';

                return $out;
            }
            $out['text'] = (string) $message['message'];
        }
        if (trim($out['text']) === '') {
            $out['why'] = 'no_text';
        } elseif (strpos($out['text'], Redactor::MASK) !== false) {
            $out['why'] = 'masked';
        }

        return $out;
    }

    /**
     * 2026-10-21 08:06 +03: a message row, read once per verdicts() call (fix round 1, item 7), task 596.
     *
     * @return array<string,mixed>|null
     */
    private function messageRow(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        if (!array_key_exists($id, $this->messages)) {
            $this->messages[$id] = $this->store->find(Schema::MESSAGES, $id);
        }

        return $this->messages[$id];
    }

    /**
     * 2026-10-21 08:06 +03: an outbox row, read once per verdicts() call (fix round 1, item 7), task 596.
     *
     * @return array<string,mixed>|null
     */
    private function outboxRow(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        if (!array_key_exists($id, $this->rows)) {
            $this->rows[$id] = $this->outbox->find($id);
        }

        return $this->rows[$id];
    }

    /**
     * 2026-10-21 08:06 +03: after a resend, a held row that holds only this number is closed (expired), so reap()
     * never turns it into an "expired" attempt that could be resent again. A held row of several numbers stays: its
     * other numbers still get their own expiry, and its later attempt for this number counts as already resent
     * (resentAs()). Fix round 1, item 5, task 596.
     *
     * @param array<string,mixed> $attempt
     */
    private function closeHeldRow(array $attempt): void
    {
        $id = (int) $attempt['outbox_id'];
        $row = $id > 0 ? $this->outbox->find($id) : null;
        if ($row === null || (string) $row['status'] !== Outbox::STATUS_HELD) {
            return;
        }
        $numbers = array_map(static function ($number): string {
            return (string) preg_replace('/\D+/', '', (string) $number);
        }, (array) $row['phones']);
        if ($numbers === [(string) $attempt['phone']] && $this->outbox->markExpired($id)) {
            $this->log->log('info', 'logs.resend_closed_held', 'Held outbox row closed after its resend', [
                'outbox_id' => $id, 'original' => (int) $attempt['attempt_id'],
            ]);
        }
    }
}
