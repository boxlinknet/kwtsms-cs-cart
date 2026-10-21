<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Orders/OrderNotifier.php
 * CREATED:   2026-10-21 01:55 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      What the SMS transport does with one event for one receiver once core has handed it over (the switch is
 *            on and the Notify rules allow it): decide whether this SMS must go out, claim its dispatch key, find the
 *            numbers, the language and the text, render it and call SmsService::send().
 * WHY:       PRD 4.2 rules 2, 3, 5 and 6.4. In order:
 *            order.status_changed.<s>  superseded when the order is new in this request (the order-placed SMS
 *                                      replaces it) or waits for the order-placed SMS of an admin-created order;
 *                                      switched_off when a Multi-Vendor vendor caused it and
 *                                      mve_vendor_triggered_sms = N; for a customer of a per_checkout checkout
 *                                      superseded (reason checkout_waiting) until the LAST child reaches the status,
 *                                      then one SMS for the checkout; duplicate_event when the 24 h status key
 *                                      (status:<order or p<parent>>:<S>:<receiver>) was already claimed.
 *            kwtsms.order_placed       unit order or suborder: key order_placed:<order id>:<r>; unit checkout: key
 *                                      order_placed:<parent id>:<r>, text kwtsms.order_placed.multi with {order_ids};
 *                                      duplicate_event when the permanent key exists.
 *            declared third-party      text <event id>; placeholders from the declared data paths; no key (the
 *                                      declaring add-on dispatches once).
 *            Then: no_phone (no customer phone / no admin number), no_template (no text in the language or the
 *            default language), else render() and send() with the order context. Every skip writes an attempt row
 *            (Diagnostics::recordOutcome), so the Logs explain every SMS that did not go out.
 * USAGE:     $notifier->notify('order.status_changed.c', 'C', ['order_info' => $order_info]);   // SmsTransport
 *            Returns true when send() was called (or queued for after the response).
 *            2026-10-21 03:11 +03, Task 1.9 scenario 14, task 596: in a storefront request served by PHP-FPM the
 *            send() itself runs after the response (register_shutdown_function + fastcgi_finish_request, see
 *            flushDeferred()); everything that decides the SMS (key claim, numbers, text) still runs in the request.
 *            2026-10-21 04:20 +03, Task 1.9 fix round 1 item 1, task 596: the rendered SMS is first written to the
 *            outbox in the request, due now (SmsService::queueAfterResponse(), logged as order_sms.deferred with its
 *            outbox_id); flushDeferred() sends exactly those rows through the outbox claim (sendQueued()). A worker
 *            that dies after the response leaves the row for the next cron drain: never lost, never sent twice.
 *            When the outbox cannot take it, the SMS is sent in the request.
 * DEPENDS:   Sms\SmsService, Settings\ConfigInterface, Templates\{TemplateRepository,TemplateRenderer,Defaults},
 *            Orders\{DispatchKeys,CheckoutRules,RuntimeFlags,Diagnostics,OrderSource}, Notifications\OrderSmsData,
 *            Log\DebugLog.
 */

namespace Tygh\Addons\Kwtsms\Orders;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Notifications\EventsSchemaExtender;
use Tygh\Addons\Kwtsms\Notifications\OrderSmsData;
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\SmsService;
use Tygh\Addons\Kwtsms\Templates\Defaults;
use Tygh\Addons\Kwtsms\Templates\TemplateRenderer;
use Tygh\Addons\Kwtsms\Templates\TemplateRepository;

class OrderNotifier
{
    public const SUPERSEDED = 'superseded';
    public const DUPLICATE = 'duplicate_event';
    public const NO_PHONE = 'no_phone';
    public const NO_TEMPLATE = 'no_template';

    /** @var SmsService */
    private $sms;

    /** @var ConfigInterface */
    private $config;

    /** @var TemplateRepository */
    private $templates;

    /** @var DispatchKeys */
    private $keys;

    /** @var OrderSmsData */
    private $data;

    /** @var CheckoutRules */
    private $rules;

    /** @var RuntimeFlags */
    private $flags;

    /** @var Diagnostics */
    private $diagnostics;

    /** @var OrderSource */
    private $orders;

    /** @var DebugLog|null */
    private $log;

    /** @var array<string,array<string,mixed>> declared third-party events */
    private $declared;

    /** @var callable (array $actor): bool, true = send after the response (2026-10-21 03:11 +03, task 596) */
    private $after_response;

    /** @var int[] outbox ids waiting for flushDeferred() (2026-10-21 04:20 +03: were closures, task 596) */
    private $deferred = [];

    /** @var bool */
    private $shutdown_registered = false;

    /**
     * @param array<mixed,mixed> $declared       The kwtsms events schema (fn_get_schema('kwtsms', 'events'))
     * @param callable|null      $after_response (array $actor): bool; null = sendsAfterResponse() (2026-10-21 03:11
     *                                           +03, Task 1.9 scenario 14, task 596)
     */
    public function __construct(
        SmsService $sms,
        ConfigInterface $config,
        TemplateRepository $templates,
        DispatchKeys $keys,
        OrderSmsData $data,
        CheckoutRules $rules,
        RuntimeFlags $flags,
        Diagnostics $diagnostics,
        OrderSource $orders,
        ?DebugLog $log = null,
        array $declared = [],
        ?callable $after_response = null
    ) {
        $this->after_response = $after_response ?? [self::class, 'sendsAfterResponse'];
        $this->sms = $sms;
        $this->config = $config;
        $this->templates = $templates;
        $this->keys = $keys;
        $this->data = $data;
        $this->rules = $rules;
        $this->flags = $flags;
        $this->diagnostics = $diagnostics;
        $this->orders = $orders;
        $this->log = $log;
        $this->declared = EventsSchemaExtender::validDeclarations($declared);
    }

    /**
     * @param array<string,mixed> $payload The event data (order_info, kwtsms, anything a third party passes)
     */
    public function notify(string $event_id, string $receiver, array $payload): bool
    {
        $started = microtime(true);
        if ($receiver !== 'C' && $receiver !== 'A') {
            return false;
        }
        $order = isset($payload['order_info']) && is_array($payload['order_info']) ? $payload['order_info'] : [];
        $this->debug('order_sms.notify', 'enter', [
            'event_id' => $event_id, 'receiver' => $receiver, 'order_id' => (int) ($order['order_id'] ?? 0),
        ]);

        if (strpos($event_id, EventsSchemaExtender::STATUS_PREFIX) === 0) {
            $sent = $this->statusSms($event_id, $receiver, $order);
        } elseif ($event_id === Defaults::EVENT_PLACED) {
            $meta = isset($payload['kwtsms']) && is_array($payload['kwtsms']) ? $payload['kwtsms'] : [];
            $sent = $this->placedSms($receiver, $order, $meta);
        } elseif (isset($this->declared[$event_id])) {
            $sent = $this->declaredSms($event_id, $receiver, $payload);
        } else {
            $sent = false;
        }

        $this->debug('order_sms.notify', 'exit', [
            'event_id' => $event_id, 'receiver' => $receiver, 'sent' => $sent,
        ], $started);

        return $sent;
    }

    // ------------------------------------------------------------------------------------------ status

    /**
     * @param array<string,mixed> $order
     */
    private function statusSms(string $event_id, string $receiver, array $order): bool
    {
        $order_id = (int) ($order['order_id'] ?? 0);
        $status = strtoupper(substr($event_id, strlen(EventsSchemaExtender::STATUS_PREFIX)));
        if ($order_id <= 0 || $status === '') {
            return false;
        }
        if ($this->flags->has('new_order', $order_id) || $this->flags->has('manual', $order_id)) {
            $this->skip(self::SUPERSEDED, 'new_order', $order, $event_id, $receiver);

            return false;
        }

        $actor = $this->orders->actor();
        $by_vendor = $this->rules->isMultiVendor() && $actor['area'] === 'A' && $actor['company_id'] > 0;
        if ($by_vendor && !$this->config->mveVendorTriggeredSms()) {
            $this->skip(Diagnostics::SWITCHED_OFF, 'mve_vendor_triggered_sms', $order, $event_id, $receiver);

            return false;
        }

        $scope_id = (string) $order_id;
        $context_order = $order;
        $extra = [];
        $parent_id = (int) ($order['parent_order_id'] ?? 0);
        if ($receiver === 'C' && $this->rules->isMultiVendor() && $parent_id > 0) {
            $children = $this->orders->children($parent_id);
            $companies = array_map(static function (array $child): int {
                return (int) ($child['company_id'] ?? 0);
            }, $children);
            if ($children !== [] && $this->rules->statusScope($companies) === CheckoutRules::PER_CHECKOUT) {
                foreach ($children as $child) {
                    if (strtoupper((string) ($child['status'] ?? '')) !== $status) {
                        // The last child of a parent cascade sends; only a lone child change is worth a row.
                        if (!$this->flags->has('cascade', $order_id)) {
                            $this->skip(self::SUPERSEDED, 'checkout_waiting', $order, $event_id, $receiver);
                        }

                        return false;
                    }
                }
                $scope_id = 'p' . $parent_id;
                $parent = $this->orders->order($parent_id);
                if ($parent !== []) {
                    // 2026-10-21 04:20 +03: the no-op "+ parent_order_id" is gone (fix round 1 item 7), task 596.
                    $context_order = $parent;
                    $context_order['status'] = $status;
                }
                $extra = [
                    'order_ids' => array_map(static function (array $child): int {
                        return (int) $child['order_id'];
                    }, $children),
                    'vendor_name' => '',
                ];
            }
        }

        $key = DispatchKeys::status($scope_id, $status, $receiver);
        if (!$this->keys->claim($key, DispatchKeys::STATUS_WINDOW)) {
            $this->skip(self::DUPLICATE, $key, $order, $event_id, $receiver);

            return false;
        }

        return $this->deliver($event_id, $receiver, $context_order, $extra, $actor, [
            'order_id' => $scope_id[0] === 'p' ? $parent_id : $order_id,
            'parent_order_id' => $parent_id,
        ]);
    }

    // ------------------------------------------------------------------------------------------ order placed

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $meta  unit, parent_order_id, order_ids
     */
    private function placedSms(string $receiver, array $order, array $meta): bool
    {
        $order_id = (int) ($order['order_id'] ?? 0);
        if ($order_id <= 0) {
            return false;
        }
        $unit = isset($meta['unit']) ? (string) $meta['unit'] : CheckoutRules::UNIT_ORDER;
        $template_event = Defaults::EVENT_PLACED;
        $extra = [];
        $key_id = $order_id;
        $ids = ['order_id' => $order_id, 'parent_order_id' => (int) ($order['parent_order_id'] ?? 0)];
        if ($unit === CheckoutRules::UNIT_CHECKOUT) {
            $parent_id = (int) ($meta['parent_order_id'] ?? $order_id);
            $key_id = $parent_id;
            $template_event = Defaults::EVENT_PLACED_MULTI;
            $extra = [
                'order_ids' => isset($meta['order_ids']) && is_array($meta['order_ids']) ? $meta['order_ids'] : [],
                'vendor_name' => '',
            ];
            $ids = ['order_id' => $parent_id, 'parent_order_id' => $parent_id];
        }

        $key = DispatchKeys::orderPlaced($key_id, $receiver);
        if (!$this->keys->claim($key)) {
            $this->skip(self::DUPLICATE, $key, $order, $template_event, $receiver);

            return false;
        }

        return $this->deliver($template_event, $receiver, $order, $extra, $this->orders->actor(), $ids);
    }

    // ------------------------------------------------------------------------------------------ third party

    /**
     * @param array<string,mixed> $payload
     */
    private function declaredSms(string $event_id, string $receiver, array $payload): bool
    {
        $decl = $this->declared[$event_id];
        if (!in_array($receiver, $decl['receivers'], true)) {
            return false;
        }
        $order = isset($payload['order_info']) && is_array($payload['order_info']) ? $payload['order_info'] : [];
        $extra = ['custom' => []];
        foreach ($decl['placeholders'] as $name => $path) {
            if (is_string($name) && preg_match('/^[a-z0-9_]{1,40}$/D', $name) === 1 && is_string($path)) {
                $value = self::path($payload, $path);
                $extra['custom'][$name] = is_scalar($value) ? (string) $value : '';
            }
        }
        if ($receiver === 'C' && isset($decl['phone']) && is_string($decl['phone'])) {
            $phone = self::path($payload, $decl['phone']);
            if (is_scalar($phone) && trim((string) $phone) !== '') {
                $order['phone'] = trim((string) $phone);
            }
        }

        return $this->deliver($event_id, $receiver, $order, $extra, $this->orders->actor(), [
            'order_id' => (int) ($order['order_id'] ?? 0),
            'parent_order_id' => (int) ($order['parent_order_id'] ?? 0),
        ]);
    }

    // ------------------------------------------------------------------------------------------ delivery

    /**
     * Numbers, language, text, render, send. $event_id is the template's event and the event of the log rows.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $extra placeholders extras (order_ids, vendor_name) and custom => [name => value]
     * @param array<string,mixed> $actor OrderSource::actor()
     * @param array<string,int>   $ids   order_id, parent_order_id of the log rows
     */
    private function deliver(
        string $event_id,
        string $receiver,
        array $order,
        array $extra,
        array $actor,
        array $ids
    ): bool {
        if ($receiver === 'A') {
            $phones = $this->config->adminPhones();
            $lang = $this->data->adminLang($this->config->adminAlertLang());
        } else {
            $phone = OrderSmsData::customerPhone($order, $this->orders->profilePhone((int) ($order['user_id'] ?? 0)));
            $phones = $phone === '' ? [] : [$phone];
            $lang = $this->data->customerLang($order);
        }
        $log_order = array_merge($order, $ids);
        if ($phones === []) {
            $reason = $receiver === 'A' ? 'admin_phones_empty' : 'order_has_no_phone';
            $this->skip(self::NO_PHONE, $reason, $log_order, $event_id, $receiver);

            return false;
        }

        $template = $this->templates->resolve($event_id, $receiver, $lang);
        if ($template === null) {
            $this->skip(self::NO_TEMPLATE, $event_id . ' ' . $lang, $log_order, $event_id, $receiver, $phones);

            return false;
        }
        $text_lang = (string) $template['lang'];
        $custom = isset($extra['custom']) && is_array($extra['custom']) ? $extra['custom'] : [];
        unset($extra['custom']);
        $vars = $this->data->placeholders($order, $text_lang, $extra);
        foreach ($custom as $name => $value) {
            $vars[$name] = $value;
        }
        $text = TemplateRenderer::render((string) $template['body'], $vars);
        $context = [
            'source' => 'event',
            'event_id' => $event_id,
            'receiver' => $receiver,
            'order_id' => (int) ($ids['order_id'] ?? 0),
            'parent_order_id' => (int) ($ids['parent_order_id'] ?? 0),
            'company_id' => (int) ($order['company_id'] ?? 0),
            'storefront_id' => (int) ($order['storefront_id'] ?? 0),
            'user_id' => (int) ($order['user_id'] ?? 0),
            'actor_user_id' => (int) ($actor['user_id'] ?? 0),
            'actor_company_id' => (int) ($actor['company_id'] ?? 0),
            'lang_code' => $text_lang,
            'audience' => ($actor['area'] ?? '') === 'C' ? 'storefront' : 'admin',
            'interactive' => false,
            'country_iso' => $receiver === 'C' ? OrderSmsData::countryIso($order) : '',
        ];
        $log = [
            'event_id' => $event_id,
            'receiver' => $receiver,
            'order_id' => (int) ($ids['order_id'] ?? 0),
            'template' => $template['fallback'] ?? '',
            'lang' => $text_lang,
        ];

        // 2026-10-21 03:11 +03: a storefront request (checkout, payment callback, repay) sends after the response,
        // so the shopper never waits for the gateway (scenario 14 measured about 0.75 s per kwtSMS call); the
        // dispatch key is already claimed above, so the decision is final. Task 1.9, task 596.
        // 2026-10-21 04:20 +03: the SMS waits in the outbox, due now, not in PHP memory (fix round 1 item 1), task 596.
        if (call_user_func($this->after_response, $actor)) {
            $outbox_id = $this->sms->queueAfterResponse($phones, $text, $context);
            if ($outbox_id > 0) {
                $this->deferred[] = $outbox_id;
                if (!$this->shutdown_registered) {
                    $this->shutdown_registered = true;
                    register_shutdown_function([$this, 'flushDeferred']);
                }
                $this->info('order_sms.deferred', 'order SMS queued to go out after the response', $log + [
                    'outbox_id' => $outbox_id,
                ]);

                return true;
            }
            $this->info('order_sms.deferred', 'the outbox could not take the SMS: sent in the request', $log);
        }
        $result = $this->sms->send($phones, $text, $context);
        $log['status'] = $result->status();
        $this->info('order_sms.sent', 'order SMS handed to the send pipeline', $log);

        return true;
    }

    /**
     * Sends what a storefront request deferred, after the response went to the browser (fastcgi_finish_request()).
     * Runs as a shutdown function; public only for that. Never throws.
     * 2026-10-21 03:11 +03: added, Task 1.9 scenario 14, task 596.
     * 2026-10-21 04:20 +03: sends the queued outbox rows by id through the outbox claim; a row the cron already
     * took is left to it (fix round 1 item 1), task 596.
     */
    public function flushDeferred(): void
    {
        if ($this->deferred === []) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        $ids = $this->deferred;
        $this->deferred = [];
        try {
            $results = $this->sms->sendQueued($ids);
            foreach ($ids as $id) {
                $this->info('order_sms.sent', isset($results[$id])
                    ? 'deferred order SMS handed to the send pipeline'
                    : 'deferred order SMS already taken by another drain', [
                    'outbox_id' => $id,
                    'status' => isset($results[$id]) ? $results[$id]->status() : '',
                ]);
            }
        } catch (\Throwable $e) {
            FailureLog::report($this->log, 'order_sms.deferred_failed', $e);
        }
    }

    /**
     * Default rule for sending after the response: a storefront web request served by PHP-FPM.
     * 2026-10-21 03:11 +03: added, Task 1.9 scenario 14, task 596.
     *
     * @param array<string,mixed> $actor OrderSource::actor()
     */
    public static function sendsAfterResponse(array $actor): bool
    {
        return ($actor['area'] ?? '') === 'C' && PHP_SAPI !== 'cli' && function_exists('fastcgi_finish_request');
    }

    /**
     * @param array<string,mixed> $order
     * @param string[]|null       $phones
     */
    private function skip(
        string $outcome,
        string $reason,
        array $order,
        string $event_id,
        string $receiver,
        ?array $phones = null
    ): void {
        $this->diagnostics->recordOutcome($outcome, $reason, $order, $event_id, $receiver, $phones);
        $this->info('order_sms.skipped', $outcome, [
            'event_id' => $event_id,
            'receiver' => $receiver,
            'order_id' => (int) ($order['order_id'] ?? 0),
            'reason' => $reason,
        ]);
    }

    /**
     * A dotted path in the event data ("shipment.tracking_number"), null when missing.
     *
     * @param array<string,mixed> $data
     *
     * @return mixed|null
     */
    private static function path(array $data, string $path)
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function info(string $action, string $message, array $context): void
    {
        if ($this->log !== null) {
            $this->log->log('info', $action, $message, $context);
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function debug(string $action, string $message, array $context, ?float $started = null): void
    {
        if ($this->log !== null && $this->log->isEnabled('debug')) {
            $this->log->log(
                'debug',
                $action,
                $message,
                $context,
                $started === null ? null : (int) round((microtime(true) - $started) * 1000)
            );
        }
    }
}
