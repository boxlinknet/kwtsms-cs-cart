<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Orders/BackInStockNotifier.php
 * CREATED:   2026-11-14 09:50 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The back-in-stock fan-out (B3, P3-65): one dispatch of core's product.back_in_stock (a product is
 *            available again; payload = subscribers' e-mail list, product, product_id, url, company_id,
 *            storefront_id, lang_code) becomes one SMS per registered subscriber that has a phone, at most
 *            bis_max_recipients (1..500) per dispatch, one API call per text language.
 * WHY:       The only event where one dispatch reaches many strangers' phones, so cost and abuse are bounded here:
 *            (1) the recipients are accounts, not e-mails (Orders\BackInStockRecipients: active customer accounts
 *            with a phone; Ultimate with share_users = N only the dispatch's company), so subscribing someone
 *            else's address reaches nobody who did not register that address; (2) the hard cap keeps one dispatch
 *            under the kwtSMS queue limit of 1,000 live messages; (3) per recipient the declaration's key
 *            bis:<product id>:<user id> (window 86400, required) is claimed BEFORE the send, so a product restocked
 *            or re-subscribed ten times in a day sends one SMS per account; a refusal (no text, bad payload)
 *            claims nothing; (4) the SMS text holds the product name and link only, never the subscriber's e-mail
 *            or name; (5) no e-mail or phone reaches any log row of this class: only counts and the product id.
 *            The customer order-SMS opt-out does not apply (the customer asked for this SMS).
 * USAGE:     Called by OrderNotifier::notify() for product.back_in_stock after the storefront switch:
 *            $sent = $notifier->notify($payload, $storefront_id, $declaration, $send);
 *            $send (array $phones, string $text, array $context): bool is OrderNotifier's after-response send; null
 *            sends through SmsService::send() in the request (unit tests, other callers).
 *            Debug rows: bis.fanout (subscribers, accounts, duplicates, sent, calls), bis.capped (count). Info row:
 *            bis.sent (product id, counts). Error row: bis.dedupe_key (token name only, once per dispatch).
 * DEPENDS:   Orders\{BackInStockRecipients,DispatchKeys,Diagnostics}, Notifications\{EventDeclaration,OrderSmsData},
 *            Templates\{TemplateRepository,TemplateRenderer,Defaults}, Settings\ConfigInterface, Sms\SmsService,
 *            Log\DebugLog.
 */

namespace Tygh\Addons\Kwtsms\Orders;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Notifications\EventDeclaration;
use Tygh\Addons\Kwtsms\Notifications\OrderSmsData;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\SmsService;
use Tygh\Addons\Kwtsms\Templates\Defaults;
use Tygh\Addons\Kwtsms\Templates\TemplateRenderer;
use Tygh\Addons\Kwtsms\Templates\TemplateRepository;

class BackInStockNotifier
{
    /** @var SmsService */
    private $sms;

    /** @var ConfigInterface */
    private $config;

    /** @var BackInStockRecipients */
    private $recipients;

    /** @var DispatchKeys */
    private $keys;

    /** @var TemplateRepository */
    private $templates;

    /** @var OrderSmsData */
    private $data;

    /** @var Diagnostics */
    private $diagnostics;

    /** @var DebugLog|null */
    private $log;

    /** @var callable|null (int $product_id, string $lang, string $fallback): string, the catalog name in a language */
    private $product_name;

    /**
     * @param callable|null $product_name (int $product_id, string $lang, string $fallback): string; null = the name of
     *                                    the payload (the dispatch's language) for every recipient
     */
    public function __construct(
        SmsService $sms,
        ConfigInterface $config,
        BackInStockRecipients $recipients,
        DispatchKeys $keys,
        TemplateRepository $templates,
        OrderSmsData $data,
        Diagnostics $diagnostics,
        ?DebugLog $log = null,
        ?callable $product_name = null
    ) {
        $this->sms = $sms;
        $this->config = $config;
        $this->recipients = $recipients;
        $this->keys = $keys;
        $this->templates = $templates;
        $this->data = $data;
        $this->diagnostics = $diagnostics;
        $this->log = $log;
        $this->product_name = $product_name;
    }

    /**
     * @param array<string,mixed> $payload      The event data of core (kwtsms_ctx already removed by the caller)
     * @param int                 $storefront_id The storefront of this dispatch (OrderNotifier's single resolution)
     * @param callable|null       $send         (array $phones, string $text, array $context): bool
     *
     * @return int Numbers handed to the send pipeline (after the cap, the duplicate keys and the text)
     */
    public function notify(array $payload, int $storefront_id, EventDeclaration $decl, ?callable $send = null): int
    {
        $event = Defaults::EVENT_BIS;
        $company_id = max(0, (int) ($payload['company_id'] ?? 0));
        $order = ['company_id' => $company_id, 'storefront_id' => max(0, $storefront_id)];
        // An integer or a string of digits only (a catalog id); anything else is not a product id.
        $raw_id = $payload['product_id'] ?? null;
        $product_id = is_int($raw_id) || (is_string($raw_id) && preg_match('/^[0-9]{1,10}$/D', $raw_id) === 1)
            ? (int) $raw_id : 0;
        // A payload without the product id cannot build the key or the link: no send (fail closed), one row, no
        // database read.
        if ($product_id <= 0) {
            $this->refuse('invalid', 'platform_missing', $order);

            return 0;
        }
        $payload['product_id'] = $product_id;
        $subscribers = isset($payload['subscribers']) && is_array($payload['subscribers'])
            ? $payload['subscribers'] : [];

        $accounts = $this->recipients->phones($subscribers, $company_id);
        $eligible = count($accounts);
        $stats = [
            'product_id' => $product_id,
            'subscribers' => count($subscribers),
            'accounts' => $eligible,
            'duplicates' => 0,
            'unresolved' => 0,
            'sent' => 0,
            'calls' => 0,
        ];
        if ($accounts === []) {
            $this->debug('bis.fanout', 'no registered subscriber with a phone', $stats);

            return 0;
        }

        // 2026-11-14 11:40 +03: M3.3 fix round 1 (I1), task 596. The cap counts messages claimed, not accounts seen:
        // the walk below claims each key in order, skips the deduped and unresolved ones, and stops claiming when
        // `claimed` reaches the cap, so an account that already got today's SMS never uses up a slot.
        $cap = $this->config->bisMaxRecipients();
        $claimed = 0;
        $stats['capped'] = 0;

        // Group by the language of the text that really answers (a missing text falls back, so two languages can
        // share one call). A group without any text sends nothing and claims nothing.
        $fallback_lang = $this->payloadLang($payload);
        $groups = [];
        $missing = [];
        $templates = [];
        foreach ($accounts as $account) {
            $lang = $account['lang'] !== '' ? $account['lang'] : $fallback_lang;
            if (!array_key_exists($lang, $templates)) {
                $templates[$lang] = $this->templates->resolve(
                    $event,
                    Defaults::RECEIVER_CUSTOMER,
                    $lang,
                    $storefront_id
                );
                if ($templates[$lang] === null) {
                    $missing[] = $lang;
                }
            }
            if ($templates[$lang] === null) {
                continue;
            }
            $text_lang = (string) $templates[$lang]['lang'];
            $groups[$text_lang]['body'] = (string) $templates[$lang]['body'];
            $groups[$text_lang]['accounts'][] = $account;
        }
        foreach ($missing as $lang) {
            $this->refuse(OrderNotifier::NO_TEMPLATE, $event . ' ' . $lang, $order);
        }

        foreach ($groups as $text_lang => $group) {
            $phones = [];
            foreach ($group['accounts'] as $account) {
                if ($claimed >= $cap) {
                    // The cap is reached: no further claim; this eligible account gets the e-mail only.
                    $stats['capped']++;
                    continue;
                }
                $key = $decl->dedupeKey(
                    $payload + ['kwtsms_ctx' => ['user_id' => $account['user_id']]],
                    Defaults::RECEIVER_CUSTOMER,
                    $event,
                    time()
                );
                if ($key === '') {
                    // An unresolved key sends nothing to this account (P3-64).
                    $stats['unresolved']++;
                    continue;
                }
                if (!$this->keys->claim($key, $decl->dedupeWindow())) {
                    $stats['duplicates']++;
                    continue;
                }
                $claimed++;
                $phones[] = $account['phone'];
            }
            if ($phones === []) {
                continue;
            }
            $name = $this->nameIn($product_id, (string) $text_lang, $payload);
            $vars = $this->data->placeholders(
                ['storefront_id' => $storefront_id, 'company_id' => $company_id, 'lang_code' => (string) $text_lang],
                (string) $text_lang
            );
            $vars['product_name'] = $name;
            $vars['product_url'] = isset($payload['url']) && is_scalar($payload['url']) ? (string) $payload['url'] : '';
            $text = TemplateRenderer::render($group['body'], $vars);
            $context = [
                'source' => 'event',
                'event_id' => $event,
                'receiver' => Defaults::RECEIVER_CUSTOMER,
                'order_id' => 0,
                'parent_order_id' => 0,
                'company_id' => $company_id,
                'storefront_id' => max(0, $storefront_id),
                'user_id' => 0,
                'lang_code' => (string) $text_lang,
                'interactive' => false,
                'country_iso' => '',
            ];
            if ($send !== null) {
                $ok = (bool) call_user_func($send, $phones, $text, $context);
            } else {
                $this->sms->send($phones, $text, $context);
                $ok = true;
            }
            if ($ok) {
                $stats['sent'] += count($phones);
                $stats['calls']++;
            }
        }

        if ($stats['capped'] > 0) {
            $this->debug('bis.capped', 'more subscribers than the cap: the rest get the e-mail only', [
                'product_id' => $product_id, 'cap' => $cap, 'count' => $stats['capped'],
            ]);
        }
        if ($stats['unresolved'] > 0 && $this->log !== null) {
            // The token name only (the key itself would hold ids, never an e-mail, but the row stays minimal).
            $this->log->log('error', 'bis.dedupe_key', 'dedupe key not resolved: those recipients got no SMS', [
                'event_id' => $event, 'token' => $decl->dedupeKeyError(), 'count' => $stats['unresolved'],
            ]);
        }
        $this->debug('bis.fanout', 'back in stock fan-out', $stats);
        if ($stats['sent'] > 0 && $this->log !== null) {
            $this->log->log('info', 'bis.sent', 'back in stock SMS handed to the send pipeline', [
                'event_id' => $event,
                'product_id' => $product_id,
                'recipients' => $stats['sent'],
                'calls' => $stats['calls'],
                'capped' => $stats['capped'],
            ]);
        }

        return $stats['sent'];
    }

    /**
     * The name of the product in the language of the text: the catalog's, else the payload's (the dispatch language).
     *
     * @param array<string,mixed> $payload
     */
    private function nameIn(int $product_id, string $lang, array $payload): string
    {
        $fallback = isset($payload['product']['name']) && is_scalar($payload['product']['name'])
            ? (string) $payload['product']['name'] : '';
        if ($this->product_name === null) {
            return $fallback;
        }
        $name = call_user_func($this->product_name, $product_id, $lang, $fallback);

        return is_scalar($name) && (string) $name !== '' ? (string) $name : $fallback;
    }

    /**
     * The language of a recipient whose account has none: the dispatch's (core's company language), else the
     * storefront default.
     *
     * @param array<string,mixed> $payload
     */
    private function payloadLang(array $payload): string
    {
        $lang = isset($payload['lang_code']) && is_string($payload['lang_code'])
            ? strtolower($payload['lang_code']) : '';
        if (preg_match('/^[a-z]{2}$/D', $lang) === 1) {
            return $lang;
        }

        return $this->data->customerLang(['storefront_id' => (int) ($payload['storefront_id'] ?? 0)]);
    }

    /**
     * One attempt row for a whole dispatch that sends nothing (no number, no e-mail).
     *
     * @param array<string,mixed> $order
     */
    private function refuse(string $outcome, string $reason, array $order): void
    {
        $this->diagnostics->recordOutcome(
            $outcome,
            $reason,
            $order,
            Defaults::EVENT_BIS,
            Defaults::RECEIVER_CUSTOMER,
            []
        );
        if ($this->log !== null) {
            $this->log->log('info', 'order_sms.skipped', $outcome, [
                'event_id' => Defaults::EVENT_BIS, 'receiver' => Defaults::RECEIVER_CUSTOMER, 'order_id' => 0,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $context counts and ids only
     */
    private function debug(string $action, string $message, array $context): void
    {
        if ($this->log !== null && $this->log->isEnabled('debug')) {
            $this->log->log('debug', $action, $message, $context);
        }
    }
}
