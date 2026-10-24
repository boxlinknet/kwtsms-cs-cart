<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Codes/CodeSender.php
 * CREATED:   2026-10-24 12:37 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Sends the two code and security texts through SmsService: the code SMS (template otp.code plus the Web OTP
 *            last line "@<storefront host> #<code>") and the notice to the OLD number after a phone change
 *            (template security.phone_changed).
 * WHY:       Task M1.4 (SafatMall G2, H2; rulings P3-9, P3-12, P3-17, P3-37, decision D7). A code SMS is never deferred
 *            by quiet hours (source "otp"), carries a ttl equal to the code lifetime (a deferred code expires with its
 *            code), names the store, and ends with the line Android and Chrome use to fill the code in automatically
 *            (Web OTP). The code goes into the send context secret_values, so SmsService masks it in every local copy:
 *            the log row, both raw copies, the attempt reason, the outbox row (also after the row is final) and the
 *            Logs detail view. Only the API request body carries the real code.
 * USAGE:     $sender = Tygh::$app['kwtsms.code_sender'];
 *            $r = $sender->sendCode('+96550123401', '048213', 'register', 'en', 1, 0, '203.0.113.5');
 *            //   ['outcome' => 'test_queued', 'reason' => ''] (the canonical outcome of the one recipient;
 *            //   CodeAvailability::classify() tells what it means for the request)
 *            $sender->sendPhoneChangedNotice('+96550123401', 15, 'en', 1);
 *            Body of sendCode(): the otp.code text of the storefront and language (TemplateRepository::resolve(),
 *            {store_name} {code} {minutes}), then "\n@" . host . " #" . code. host = the host of the storefront URL, no
 *            scheme, port or path. The Web OTP line is added here and is never part of an editable template.
 *            Context of sendCode(): source otp, event_id otp.<purpose> (logged), receiver C, audience storefront,
 *            interactive true, secret_values [code], ttl = otp_expiry_seconds, storefront_id, user_id, ip.
 *            Context of sendPhoneChangedNotice(): event_id security.phone_changed, ttl 1800, not interactive.
 *            Never throws; an internal error answers outcome "failed", reason "exception" (logged without the code).
 * DEPENDS:   a send callable (array $phones, string $text, array $context): SendResult (SmsService::send), Templates\
 *            TemplateRepository (resolve), Templates\TemplateRenderer, Settings\ConfigInterface (otpExpirySeconds),
 *            callables for the storefront URL and the brand name, Log\DebugLog (optional).
 */

namespace Tygh\Addons\Kwtsms\Codes;

use Tygh\Addons\Kwtsms\Log\AttemptLog;
use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\SendResult;
use Tygh\Addons\Kwtsms\Templates\Defaults;
use Tygh\Addons\Kwtsms\Templates\TemplateRenderer;
use Tygh\Addons\Kwtsms\Templates\TemplateRepository;

class CodeSender
{
    /** Seconds the phone-changed notice may wait in the outbox: the bound of SmsService::rowTtl(), stated, not clamped. */
    public const NOTICE_TTL = 1800;

    /** @var callable (array $phones, string $text, array $context): SendResult */
    private $send;

    /** @var TemplateRepository */
    private $templates;

    /** @var ConfigInterface */
    private $config;

    /** @var callable (int $storefront_id): string The storefront URL (or host) */
    private $storefront_url;

    /** @var callable (int $storefront_id, string $lang): string The SMS brand name ({store_name}) */
    private $brand;

    /** @var DebugLog|null */
    private $log;

    /** @var callable|null Returns the current Unix time (tests); null = time() */
    private $clock;

    /** @var callable|null (int $outbox_id): bool, finalizes and masks a waiting outbox row (Outbox::markExpired) */
    private $cancel;

    /** @var AttemptLog|null 2026-10-24 23:51 +03: Task M1.8a (M1.4 review minor 4), task 596. no_template rows. */
    private $attempts;

    /**
     * @param callable      $send           (array $phones, string $text, array $context): SendResult
     * @param callable      $storefront_url (int $storefront_id): string, the storefront URL
     *                                      ("https://shop.example.com/en/")
     * @param callable      $brand          (int $storefront_id, string $lang): string, the SMS brand name
     * @param callable|null $clock          Returns the current Unix time (tests); null = time()
     * @param callable|null $cancel         (int $outbox_id): bool, cancels a waiting row (2026-10-24 14:40 +03,
     * Task M1.4
     *                                      fix round 1, minor 2, task 596)
     */
    public function __construct(
        callable $send,
        TemplateRepository $templates,
        ConfigInterface $config,
        callable $storefront_url,
        callable $brand,
        ?DebugLog $log = null,
        ?callable $clock = null,
        ?callable $cancel = null,
        ?AttemptLog $attempts = null
    ) {
        $this->attempts = $attempts;
        $this->cancel = $cancel;
        $this->send = $send;
        $this->templates = $templates;
        $this->config = $config;
        $this->storefront_url = $storefront_url;
        $this->brand = $brand;
        $this->log = $log;
        $this->clock = $clock;
    }

    /**
     * The host of a storefront URL: no scheme, port, path, credentials or trailing dot, lower case. A value without a
     * scheme ("shop.example.com/en") is read as a host and path. '' when no host can be read.
     */
    public static function hostOf(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (strpos($url, '//') === false) {
            $url = '//' . $url;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? rtrim(strtolower($host), '.') : '';
    }

    /**
     * Sends one code SMS.
     *
     * @return array{outcome:string,reason:string}
     */
    public function sendCode(
        string $phone,
        #[\SensitiveParameter]
        string $code,
        string $purpose,
        string $lang,
        int $storefront_id,
        int $user_id,
        string $ip
    ): array {
        try {
            $resolved = $this->templates->resolve(
                Defaults::EVENT_OTP_CODE,
                Defaults::RECEIVER_CUSTOMER,
                $lang,
                $storefront_id
            );
            if ($resolved === null || trim((string) $resolved['body']) === '') {
                // 2026-10-24 23:51 +03: Task M1.8a (M1.4 review minor 4), task 596. The Logs show why no code left.
                $this->noTemplateRow($phone, 'otp.' . $purpose, $storefront_id, $user_id, $ip);

                return $this->answer('no_template', 'no_template', $purpose);
            }
            $ttl = $this->config->otpExpirySeconds();
            $text = TemplateRenderer::render((string) $resolved['body'], [
                'store_name' => (string) call_user_func($this->brand, $storefront_id, $lang),
                'code' => $code,
                'minutes' => (string) (int) ceil($ttl / 60),
            ]);
            // The Web OTP line: "@host #code", always the last line, never editable.
            $host = self::hostOf((string) call_user_func($this->storefront_url, $storefront_id));
            // 2026-10-24 14:40 +03: Task M1.4 fix round 1 (minor 5), task 596. No host, no line (never "@ #code").
            if ($host !== '') {
                $text .= "\n@" . $host . ' #' . $code;
            }

            $result = call_user_func($this->send, [$phone], $text, [
                'source' => 'otp',
                'event_id' => 'otp.' . $purpose,
                'receiver' => Defaults::RECEIVER_CUSTOMER,
                'audience' => 'storefront',
                'interactive' => true,
                'secret_values' => [$code],
                'ttl' => $ttl,
                'storefront_id' => $storefront_id,
                'user_id' => $user_id,
                'ip' => $ip,
                'lang_code' => $lang,
            ]);

            return $this->fromResult($result, $purpose);
        } catch (\Throwable $e) {
            return $this->failed($e, $purpose);
        }
    }

    /**
     * Sends the notice "your number was changed" to the OLD number (B5, P3-17). No code, no link, no new number.
     *
     * @return array{outcome:string,reason:string}
     */
    public function sendPhoneChangedNotice(string $old_phone, int $user_id, string $lang, int $storefront_id): array
    {
        $purpose = 'phone_changed';
        try {
            $resolved = $this->templates->resolve(
                Defaults::EVENT_PHONE_CHANGED,
                Defaults::RECEIVER_CUSTOMER,
                $lang,
                $storefront_id
            );
            if ($resolved === null || trim((string) $resolved['body']) === '') {
                // 2026-10-24 23:51 +03: Task M1.8a (M1.4 review minor 4), task 596.
                $this->noTemplateRow($old_phone, Defaults::EVENT_PHONE_CHANGED, $storefront_id, $user_id, '');

                return $this->answer('no_template', 'no_template', $purpose);
            }
            $now = $this->clock === null ? time() : (int) call_user_func($this->clock);
            $text = TemplateRenderer::render((string) $resolved['body'], [
                'store_name' => (string) call_user_func($this->brand, $storefront_id, $lang),
                'date' => date('Y-m-d H:i', $now),
            ]);

            $result = call_user_func($this->send, [$old_phone], $text, [
                'source' => 'otp',
                'event_id' => Defaults::EVENT_PHONE_CHANGED,
                'receiver' => Defaults::RECEIVER_CUSTOMER,
                'audience' => 'storefront',
                'interactive' => false,
                'secret_values' => [],
                'ttl' => self::NOTICE_TTL,
                'storefront_id' => $storefront_id,
                'user_id' => $user_id,
                'lang_code' => $lang,
            ]);

            return $this->fromResult($result, $purpose);
        } catch (\Throwable $e) {
            return $this->failed($e, $purpose);
        }
    }

    /**
     * 2026-10-24 23:51 +03: Task M1.8a (M1.4 review minor 4), task 596. The attempt row of a text that could not be
     * built (every language of the template is empty): outcome and reason no_template, so "every skipped SMS writes
     * an attempt row" holds. Never holds a code or a text. A failed write is the log's own business (it returns 0).
     */
    private function noTemplateRow(string $phone, string $event_id, int $storefront_id, int $user_id, string $ip): void
    {
        if ($this->attempts === null) {
            return;
        }
        try {
            $this->attempts->record([
                'source' => 'otp',
                'event_id' => $event_id,
                'receiver' => Defaults::RECEIVER_CUSTOMER,
                'storefront_id' => $storefront_id,
                'user_id' => $user_id,
                'phone_raw' => $phone,
                'phone' => (string) preg_replace('/[^0-9]/', '', $phone),
                'outcome' => 'no_template',
                'reason' => 'no_template',
                'ip' => $ip === '' ? null : $ip,
                'is_test' => $this->config->testMode() ? 'Y' : 'N',
            ]);
        } catch (\Throwable $e) {
            unset($e); // the audit row must not break the request
        }
    }

    /**
     * @param mixed $result A SendResult
     *
     * @return array{outcome:string,reason:string,outbox_id?:int}
     */
    private function fromResult($result, string $purpose): array
    {
        if (!$result instanceof SendResult) {
            return $this->answer('failed', 'bad_result', $purpose);
        }
        $recipients = $result->recipients();
        if ($recipients === []) {
            return $this->answer('failed', (string) $result->errorCode(), $purpose);
        }

        $answer = $this->answer((string) $recipients[0]['outcome'], (string) $recipients[0]['reason'], $purpose);
        // 2026-10-24 14:40 +03: Task M1.4 fix round 1 (minor 2), task 596. A deferred code waits in the outbox:
        // name the row so
        // the caller can cancel it once the customer was told codes are unavailable.
        if ((int) $recipients[0]['outbox_id'] > 0) {
            $answer['outbox_id'] = (int) $recipients[0]['outbox_id'];
        }

        return $answer;
    }

    /**
     * 2026-10-24 14:40 +03: Task M1.4 fix round 1 (minor 2), task 596. Cancels the outbox row of a deferred code
     * SMS: the
     * row becomes final (expired) and its text is masked, so a code the customer was told will not come never arrives.
     * False when there is no row, no cancel callable, or the row is already final.
     */
    public function cancelDeferred(int $outbox_id): bool
    {
        if ($outbox_id <= 0 || $this->cancel === null) {
            return false;
        }
        try {
            return (bool) call_user_func($this->cancel, $outbox_id);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array{outcome:string,reason:string}
     */
    private function answer(string $outcome, string $reason, string $purpose): array
    {
        if ($this->log !== null) {
            // Names only: never the code, the phone or the text.
            $this->log->log('debug', 'otp.send', 'Code SMS sent', [
                'purpose' => $purpose,
                'outcome' => $outcome,
                'reason' => $reason,
            ]);
        }

        return ['outcome' => $outcome, 'reason' => $reason];
    }

    /**
     * @return array{outcome:string,reason:string}
     */
    private function failed(\Throwable $e, string $purpose): array
    {
        if ($this->log !== null) {
            try {
                $this->log->log('error', 'otp.send_failed', 'Code SMS failed', [
                    'purpose' => $purpose,
                    'error' => get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine(),
                ]);
            } catch (\Throwable $ignored) {
                // Logging must not hide the failure.
                unset($ignored);
            }
        }

        return ['outcome' => 'failed', 'reason' => 'exception'];
    }
}
