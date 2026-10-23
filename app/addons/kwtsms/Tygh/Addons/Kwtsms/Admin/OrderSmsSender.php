<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/OrderSmsSender.php
 * CREATED:   2026-10-23 02:04 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The manual SMS of the admin order page (Task A11): validates one phone and one text on the server,
 *            then sends through SmsService::send() with the order's context, and answers a small result array
 *            for the page.
 * WHY:       Phase 2 Task A11 (Milestone A, 1.1.0). An administrator contacts a customer from the order without
 *            leaving the order page. The send path is the one pipeline of the add-on, so every gate applies (test
 *            mode, enabled, configured, per-number manual caps 5 an hour and 20 a day, the store daily cap,
 *            countries, coverage, cleaning, 7 pages, balance), and every outcome, including each refusal, is an
 *            attempt row with the order id, the order's storefront (its Sender ID), the administrator id and the IP.
 * USAGE:     $sender = OrderSmsSender::fromContainer(Tygh::$app);
 *            $data = $sender->dialogData(72);                  // null when the order does not exist
 *            $r = $sender->send(72, '+965 5019 3801', 'Your parcel is ready', $admin_id, $ip);
 *            // ['ok' => true, 'outcome' => 'test_queued', 'message_id' => 41, 'points' => 1, 'balance_after' => 99.0,
 *            //  'reason' => '']
 *            A refusal before the pipeline (order_not_found, phone_required, phone_invalid, text_empty, text_too_long)
 *            answers ok false, outcome '' and the reason code; nothing is sent and no row is written for it.
 * DEPENDS:   Sms\{SmsService,MessageCleaner,SendResult}, Settings\ConfigInterface, Orders\OrderSource,
 *            Notifications\OrderSmsData (customerPhone, countryIso), Templates\TemplateRepository::storedForm(),
 *            Log\DebugLog.
 */

namespace Tygh\Addons\Kwtsms\Admin;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Notifications\OrderSmsData;
use Tygh\Addons\Kwtsms\Orders\OrderSource;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\MessageCleaner;
use Tygh\Addons\Kwtsms\Sms\SendResult;
use Tygh\Addons\Kwtsms\Sms\SmsService;
use Tygh\Addons\Kwtsms\Templates\TemplateRepository;

class OrderSmsSender
{
    /** Event id of every manual order SMS (the Logs page labels it). */
    // 2026-10-23 02:30 +03: the id has no kwtsms. prefix: the package gate reads kwtsms.<x> as a language variable, and
    // like core's own order.* ids it is an event of the order, Task A11, task 596.
    public const EVENT_ID = 'order.manual_sms';

    /** The typed text is cut off for good measure: 7 pages of the smallest page size is 469 characters. */
    public const TEXT_MAX_CHARS = 2000;

    /** One number: the typed length and the characters an input may hold. */
    public const PHONE_MAX_CHARS = 32;

    /** @var SmsService */
    private $sms;

    /** @var ConfigInterface */
    private $config;

    /** @var OrderSource */
    private $orders;

    /** @var DebugLog|null */
    private $log;

    public function __construct(SmsService $sms, ConfigInterface $config, OrderSource $orders, ?DebugLog $log = null)
    {
        $this->sms = $sms;
        $this->config = $config;
        $this->orders = $orders;
        $this->log = $log;
    }

    /**
     * @param \ArrayAccess<string,mixed> $app Tygh::$app
     */
    public static function fromContainer($app): self
    {
        return $app['kwtsms.order_sms_sender'];
    }

    /**
     * What the order page dialog needs: the customer phone to start from, whether test mode is on, the page limit.
     *
     * @return array{order_id:int,phone:string,test_mode:bool,max_pages:int,text_max:int}|null null: no such order
     */
    public function dialogData(int $order_id): ?array
    {
        $order = $this->orders->order($order_id);
        if ($order === []) {
            return null;
        }

        return [
            'order_id' => $order_id,
            'phone' => $this->defaultPhone($order),
            'test_mode' => $this->config->testMode(),
            'max_pages' => MessageCleaner::MAX_PAGES,
            'text_max' => self::TEXT_MAX_CHARS,
        ];
    }

    /**
     * Sends one manual SMS about an order. Never throws.
     *
     * @return array{ok:bool,outcome:string,message_id:int,points:int,balance_after:?float,reason:string}
     */
    public function send(int $order_id, string $phone, string $text, int $admin_id, string $ip): array
    {
        try {
            $order = $this->orders->order($order_id);
            if ($order === []) {
                return self::refusal('order_not_found');
            }
            $phone = trim($phone);
            if ($phone === '') {
                $phone = $this->defaultPhone($order);
            }
            $why = self::phoneProblem($phone);
            if ($why !== '') {
                return self::refusal($why);
            }
            $why = self::textProblem($text);
            if ($why !== '') {
                return self::refusal($why);
            }

            // Same stored form as a template body (ruling R18): emoji, hidden characters, entities and tags go.
            $body = TemplateRepository::storedForm($text);
            $result = $this->sms->send([$phone], $body, [
                'source' => 'manual',
                'event_id' => self::EVENT_ID,
                'receiver' => 'C',
                'order_id' => $order_id,
                'parent_order_id' => (int) ($order['parent_order_id'] ?? 0),
                'company_id' => (int) ($order['company_id'] ?? 0),
                'storefront_id' => (int) ($order['storefront_id'] ?? 0),
                'user_id' => (int) ($order['user_id'] ?? 0),
                'country_iso' => OrderSmsData::countryIso($order),
                'lang_code' => (string) ($order['lang_code'] ?? ''),
                'audience' => 'admin',
                'interactive' => true,
                'actor_user_id' => $admin_id,
                'ip' => $ip,
            ]);
            $out = self::answer($result);
            $this->note('info', 'order_sms.manual', 'manual order SMS', [
                'order_id' => $order_id,
                'admin_id' => $admin_id,
                'ip' => $ip,
                'outcome' => $out['outcome'],
                'message_id' => $out['message_id'],
            ]);

            return $out;
        } catch (\Throwable $e) {
            $this->note('error', 'order_sms.manual_failed', get_class($e) . ': ' . $e->getMessage() . ' in '
                . basename($e->getFile()) . ':' . $e->getLine(), ['order_id' => $order_id, 'admin_id' => $admin_id]);

            return self::refusal('failed');
        }
    }

    /**
     * Why a typed phone cannot go to the pipeline: '' when it is one number, else a reason code. The pipeline
     * normalizes and verifies it (outcome invalid / no_phone is its own attempt row).
     */
    public static function phoneProblem(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return 'phone_required';
        }
        // 2026-10-23 02:18 +03: only digits (any script), + ( ) - . and plain spaces: a line break or a comma means
        // several numbers, and a list is not one number. Task A11, task 596.
        $too_long = mb_strlen($phone, 'UTF-8') > self::PHONE_MAX_CHARS;
        if ($too_long || preg_match('/^[\p{Nd}+()\-. \x{A0}]+$/u', $phone) !== 1) {
            return 'phone_invalid';
        }

        return '';
    }

    /**
     * Why a typed text cannot go to the pipeline: '' when it may, else a reason code. Page count is the pipeline's
     * (blocked_too_long, 7 pages).
     */
    public static function textProblem(string $text): string
    {
        if (mb_strlen($text, 'UTF-8') > self::TEXT_MAX_CHARS) {
            return 'text_too_long';
        }
        $stored = TemplateRepository::storedForm($text);
        if ($stored === '' || MessageCleaner::clean($stored) === '') {
            return 'text_empty';
        }

        return '';
    }

    /**
     * @param array<string,mixed> $order
     */
    private function defaultPhone(array $order): string
    {
        $profile = '';
        if ((int) ($order['user_id'] ?? 0) > 0) {
            $profile = $this->orders->profilePhone((int) $order['user_id']);
        }

        return OrderSmsData::customerPhone($order, $profile);
    }

    /**
     * @return array{ok:bool,outcome:string,message_id:int,points:int,balance_after:?float,reason:string}
     */
    private static function refusal(string $reason): array
    {
        return [
            'ok' => false, 'outcome' => '', 'message_id' => 0, 'points' => 0, 'balance_after' => null,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{ok:bool,outcome:string,message_id:int,points:int,balance_after:?float,reason:string}
     */
    private static function answer(SendResult $result): array
    {
        $recipient = $result->recipients()[0] ?? [];
        $outcome = (string) ($recipient['outcome'] ?? '');
        $ok = in_array($outcome, SendResult::ACCEPTED_OUTCOMES, true);

        return [
            'ok' => $ok,
            'outcome' => $outcome,
            'message_id' => (int) ($recipient['message_id'] ?? 0),
            'points' => $ok ? (int) round($result->pointsCharged()) : 0,
            'balance_after' => $result->balanceAfter(),
            'reason' => (string) ($recipient['reason'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    private function note(string $level, string $action, string $message, array $context): void
    {
        if ($this->log !== null) {
            $this->log->log($level, $action, $message, $context);
        }
    }
}
