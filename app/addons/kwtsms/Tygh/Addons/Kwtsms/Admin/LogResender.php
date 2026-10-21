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
 * USAGE:     $resender = LogResender::fromContainer(Tygh::$app);
 *            $resender->verdict($attempt_row);   // ['ok' => bool, 'reason' => 'kwtsms.logs.resend.<why>']
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
        $no = static function (string $why, int $resent_as = 0): array {
            return ['ok' => false, 'reason' => 'kwtsms.logs.resend.' . $why, 'resent_as' => $resent_as];
        };
        if (!in_array((string) $attempt['outcome'], self::RESENDABLE, true)) {
            return $no('not_allowed');
        }
        if ((string) $attempt['source'] === 'otp') {
            return $no('masked');
        }
        if (preg_match('/^[1-9][0-9]{6,14}$/D', (string) $attempt['phone']) !== 1) {
            return $no('no_phone');
        }
        $resends = $this->resendsOf($attempt);
        if ($resends !== []) {
            return $no('already', (int) $resends[0]['attempt_id']);
        }
        $text = $this->text($attempt);
        if ($text['why'] !== '') {
            return $no($text['why']);
        }

        return ['ok' => true, 'reason' => '', 'resent_as' => 0];
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
        $message_id = (int) $attempt['message_id'];
        $message = $message_id > 0 ? $this->store->find(Schema::MESSAGES, $message_id) : null;
        if ($message !== null) {
            $out['lang'] = (string) $message['lang_code'];
        }
        $outbox_id = (int) $attempt['outbox_id'];
        $row = $outbox_id > 0 ? $this->outbox->find($outbox_id) : null;
        if ($row !== null) {
            if (in_array((string) $row['status'], self::QUEUED, true)) {
                $out['why'] = 'queued';

                return $out;
            }
            if ((string) $row['status'] === Outbox::STATUS_DONE) {
                $out['why'] = 'sent_later';

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
}
