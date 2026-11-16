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
 *            2026-10-21 07:53 +03, Task 1.9 follow-up of the Task 1.14 review, task 596: while SMS is off or the
 *            add-on is not set up (Diagnostics::active(), the same predicate as the diagnostics), notify() returns
 *            at once: no dispatch key, no outbox row, no attempt or info row (a status key claimed then would block
 *            that status SMS for 24 h once SMS is on). Only a debug row, when the log level is debug.
 *            2026-10-21 08:04 +03, ruling R37, task 596: SMS on but not set up writes ONE blocked_not_configured
 *            attempt row per receiver (the order, the event, the reason) before it returns; still no key, no outbox
 *            row.
 *            2026-10-21 16:52 +03, final review I2 (ruling R52), task 596: the send mode comes from the AfterResponse
 *            seam: after the response under PHP-FPM (fastcgi_finish_request) or LiteSpeed (litespeed_finish_request);
 *            with neither, the outbox row is left for the cron when the cron ran within 2 intervals (log
 *            order_sms.queued_for_cron), else the SMS is sent in the request (ApiClient caps a web call's slot wait
 *            at 1 s; past it the send is deferred to the outbox).
 *            2026-10-22 17:20 +03, Task A4, task 596: declared events use format v2 (Notifications\EventDeclaration):
 *            receiver V (the vendor's approved number from kwtsms.vendor_phones, else no_phone / vendor_not_approved),
 *            paths for phone, language, country, user, vendor and storefront, the data_class hook, secret
 *            placeholders (masked in every log copy) and a dedupe key claimed after the numbers and the text are
 *            known (duplicate_event, or the declared reason).
 *            2026-10-22 22:18 +03, Task A8, task 596 (R11): every V SMS (the new-order SMS of a child order and the
 *            low-stock SMS alike) passes the vendor's monthly quota in deliver() after its approved number is found:
 *            over the quota is rate_limited / vendor_quota, written with the number, no key claimed by deliver().
 *            2026-10-23 00:54 +03, Task A10 (SafatMall F6, P2-4, R10), task 596: the fixed check order is active,
 *            storefront switch,
 *            customer opt-out, dispatch-key claim. A customer (receiver C, user_id > 0) who unticked "SMS about my
 *            order status" gets no order.status_changed.*, no order.updated and no order.shipment_updated (every event
 *            whose declaration says opt_out): switched_off / customer_opt_out, no key claimed. The receipt
 *            (kwtsms.order_placed) and security SMS never pass this check; a guest order (user_id 0) is never gated.
 *            2026-11-12 02:05 +03, task 596 M3.0: a declared event's data_class that also implements PayloadEnricher
 *            adds kwtsms_ctx to the payload first (an incoming kwtsms_ctx is removed in notify()); the dedupe key gets
 *            the event id and the clock ({event}, {hour}); a declaration with dedupe 'required' whose key does not
 *            resolve is not sent (switched_off / dedupe_unresolved and the error row, P3-64).
 * DEPENDS:   Sms\SmsService, Settings\ConfigInterface, Templates\{TemplateRepository,TemplateRenderer,Defaults},
 *            Orders\{DispatchKeys,CheckoutRules,RuntimeFlags,Diagnostics,OrderSource}, Notifications\OrderSmsData,
 *            Log\DebugLog, Vendors\VendorQuota.
 */

namespace Tygh\Addons\Kwtsms\Orders;

use Tygh\Addons\Kwtsms\Log\DebugLog;
use Tygh\Addons\Kwtsms\Notifications\DeclaredSmsData;
use Tygh\Addons\Kwtsms\Notifications\EventDeclaration;
use Tygh\Addons\Kwtsms\Notifications\EventsSchemaExtender;
use Tygh\Addons\Kwtsms\Notifications\OrderSmsData;
use Tygh\Addons\Kwtsms\Notifications\PayloadEnricher; // 2026-11-12 02:05 +03: task 596 M3.0
use Tygh\Addons\Kwtsms\Notifications\VendorPhoneSourceInterface;
use Tygh\Addons\Kwtsms\Profiles\CustomerPrefs; // 2026-10-23 00:54 +03: Task A10, task 596
use Tygh\Addons\Kwtsms\Security\RateLimiter; // 2026-11-14 07:40 +03: Task M3.2 review I2, task 596
use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\SmsService;
use Tygh\Addons\Kwtsms\Storefronts\StorefrontRules;
use Tygh\Addons\Kwtsms\Templates\Defaults;
use Tygh\Addons\Kwtsms\Templates\TemplateRenderer;
use Tygh\Addons\Kwtsms\Templates\TemplateRepository;
use Tygh\Addons\Kwtsms\Vendors\VendorQuota;

class OrderNotifier
{
    public const SUPERSEDED = 'superseded';

    /** 2026-11-12 02:05 +03: task 596 M3.0 (P3-64). Attempt reason of a required dedupe key that did not resolve (no send). */
    public const DEDUPE_UNRESOLVED = 'dedupe_unresolved';
    /** 2026-10-22 22:18 +03: Task A8, task 596. A V SMS over the vendor's monthly quota (reason vendor_quota). */
    public const RATE_LIMITED = 'rate_limited';
    public const DUPLICATE = 'duplicate_event';
    public const NO_PHONE = 'no_phone';
    public const NO_TEMPLATE = 'no_template';
    /**
     * 2026-11-14 07:40 +03: Task M3.2 review I2 (ruling), task 596. A visitor can pick the vendor of a public-form
     * event (call request), so those V SMS get a per-vendor daily ceiling and a quota reserve, and every V send needs
     * an active vendor. Refused before the dedupe key is claimed: a refusal burns no key and no quota.
     */
    public const VENDOR_DAILY_CEILING = 10;
    public const VENDOR_RESERVE_PERCENT = 50;
    public const VENDOR_INACTIVE = 'vendor_inactive';
    public const VENDOR_QUOTA_RESERVED = 'vendor_quota_reserved';
    public const VENDOR_DAY_CEILING = 'vendor_day_ceiling';
    /** 2026-11-15 01:51 +03: task 596 M3.6. The vendor changed its own order: it gets no SMS about it. */
    public const SELF_TRIGGERED = 'self_triggered';

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

    /** @var array<string,EventDeclaration> declared events (format v2, 2026-10-22 17:11 +03: Task A4, task 596) */
    private $declared;

    /** @var VendorPhoneSourceInterface|null Approved vendor numbers for receiver V (null = nobody approved) */
    private $vendor_phones;

    /** @var callable|null 2026-10-22 20:27 +03: Task A6 fix round 1 (P2-R12), task 596. Antibot scenario gate. */
    private $antibot_gate;

    /** @var RateLimiter|null 2026-11-14 07:40 +03: Task M3.2 review I2, task 596 */
    private $limiter;

    /** @var BackInStockNotifier|null 2026-11-14 09:55 +03: Task M3.3 (B3), task 596 */
    private $back_in_stock;

    /** @var VendorQuota|null 2026-10-22 22:18 +03: Task A8 (R8, R11), task 596. Null = no quota (unit rigs). */
    private $vendor_quota;

    /** @var CustomerPrefs|null 2026-10-23 00:54 +03: Task A10 (R8), task 596. */
    private $prefs;

    /** @var array<string,DeclaredSmsData> data_class instances, by class name */
    private $data_classes = [];

    /**
     * @var callable (array $actor): string|bool, an AfterResponse mode; true = fpm, false = inline (2026-10-21 03:11
     *      +03, task 596; 2026-10-21 16:52 +03: a mode string, final review I2, task 596)
     */
    private $after_response;

    /** @var string AfterResponse mode of the rows in $deferred (2026-10-21 16:52 +03: final review I2, task 596) */
    private $finish_mode = AfterResponse::FPM;

    /** @var int[] outbox ids waiting for flushDeferred() (2026-10-21 04:20 +03: were closures, task 596) */
    private $deferred = [];

    /** @var bool */
    private $shutdown_registered = false;

    /** @var StorefrontRules|null The per-storefront switches (2026-10-22 14:53 +03, Task A2, task 596) */
    private $storefront_rules;

    /** @var callable|null 2026-10-23 03:30 +03: Task A12 item 1, task 596. The stale-cron drain seam. */
    private $drain_stale;

    /** @var bool 2026-10-23 03:30 +03: Task A12 item 1, task 596. One stale drain per request. */
    private $stale_drained = false;

    /** @var int The storefront id of the notification being handled: one resolution per send (ruling R3) */
    private $storefront = 0;

    /**
     * @param array<mixed,mixed> $declared       The kwtsms events schema (fn_get_schema('kwtsms', 'events'))
     * @param callable|null      $after_response (array $actor): string|bool; null = sendsAfterResponse() (2026-10-21
     *                                           03:11 +03, Task 1.9 scenario 14, task 596). 2026-10-21 16:52 +03: an
     *                                           AfterResponse mode (fpm, litespeed, cron, inline) or a bool (true =
     *                                           fpm), final review I2, task 596.
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
        ?callable $after_response = null,
        ?StorefrontRules $storefront_rules = null,
        ?VendorPhoneSourceInterface $vendor_phones = null,
        // 2026-10-22 20:27 +03: Task A6 fix round 1 (P2-R12, R8), task 596. fn(string $scenario): bool, true when
        // the core antibot
        // scenario is active (provider set up and scenario on). Null = not active (fail closed).
        ?callable $antibot_gate = null,
        // 2026-10-22 22:18 +03: Task A8 (R8, R11), task 596. The vendor's monthly quota; nullable, appended last.
        ?VendorQuota $vendor_quota = null,
        // 2026-10-23 00:54 +03: Task A10 (R8, R10), task 596. The customer opt-out; nullable, appended last (null =
        // nobody opted out).
        ?CustomerPrefs $prefs = null,
        // 2026-10-23 03:30 +03: Task A12 item 1 (R8, R53), task 596. () => void: drains up to 5 due outbox rows;
        // called once after the request's own inline send when the cron heartbeat is stale. Nullable, appended last.
        ?callable $drain_stale = null,
        // 2026-11-14 07:40 +03: Task M3.2 review I2, task 596. The daily ceiling of a public-form V SMS (null = no
        // ceiling). Appended last.
        ?RateLimiter $limiter = null,
        // 2026-11-14 09:55 +03: Task M3.3, task 596. The back-in-stock fan-out (null = it sends nothing).
        ?BackInStockNotifier $back_in_stock = null
    ) {
        $this->back_in_stock = $back_in_stock;
        $this->limiter = $limiter;
        $this->drain_stale = $drain_stale;
        $this->prefs = $prefs;
        $this->vendor_quota = $vendor_quota;
        $this->antibot_gate = $antibot_gate;
        $this->storefront_rules = $storefront_rules;
        $this->vendor_phones = $vendor_phones;
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
        // 2026-10-22 17:11 +03: Task A4, task 596. Validated objects; V survives only in Multi-Vendor.
        $this->declared = EventsSchemaExtender::declarations($declared, $this->rules->isMultiVendor());
    }

    /**
     * @param array<string,mixed> $payload The event data (order_info, kwtsms, anything a third party passes)
     */
    public function notify(string $event_id, string $receiver, array $payload): bool
    {
        $started = microtime(true);
        if ($receiver !== 'C' && $receiver !== 'A' && $receiver !== 'V') {
            return false;
        }
        // 2026-10-22 17:11 +03: Task A4, task 596. The vendor receiver belongs to declared events only (A8 opens it for
        // kwtsms.order_placed by declaring it).
        // 2026-11-15 01:51 +03: task 596 M3.6 (P3-67). V is also accepted for the order status events in Multi-Vendor
        // (EventsSchemaExtender::statusReceivers): the core schema only has the receiver there, and the SMS column
        // only where the merchant switched it on.
        if (
            $receiver === 'V'
            && !(isset($this->declared[$event_id]) && in_array('V', $this->declared[$event_id]->receivers(), true))
            && !($this->rules->isMultiVendor() && strpos($event_id, EventsSchemaExtender::STATUS_PREFIX) === 0)
        ) {
            return false;
        }
        // 2026-11-12 02:05 +03: task 596 M3.0. kwtsms_ctx is the add-on's own key, filled only by a declaration's
        // PayloadEnricher: one that arrives with the core payload is removed before anything reads
        // it (storefront path, order path, placeholders, dedupe tokens), so no spoofed phone or company reaches a send.
        unset($payload['kwtsms_ctx']);
        // 2026-11-12 04:49 +03: task 596 M3.0 fix round 1 (I1, P3-62: enrich BEFORE the declaration is evaluated). The
        // enricher runs here, before orderOf(), storefrontOf() and optedOut(), so a storefront or user_id path into
        // kwtsms_ctx.* reaches the storefront switch and the opt-out. A throw skips the SMS as invalid /
        // platform_missing below, after the active check, with the order from orderOf().
        $enrich_failed = false;
        $payload = $this->enrich($event_id, $receiver, $payload, $enrich_failed);
        $order = $this->orderOf($event_id, $payload);
        // 2026-10-21 07:53 +03: SMS off or not set up: nothing claimed, queued or written (Task 1.14 review
        // follow-up), task 596.
        if (!$this->diagnostics->active()) {
            // 2026-10-21 08:04 +03: SMS on but not set up: one blocked_not_configured row with the reason, still no
            // key and no outbox row (ruling R37, PRD goal 5), task 596.
            if ($this->config->enabled() && $this->handles($event_id)) {
                $this->diagnostics->recordNotConfigured($order, $event_id, $receiver);
            }
            $this->debug('order_sms.notify', 'SMS is off or not set up: nothing to do', [
                'event_id' => $event_id, 'receiver' => $receiver, 'order_id' => (int) ($order['order_id'] ?? 0),
            ]);

            return false;
        }
        $this->debug('order_sms.notify', 'enter', [
            'event_id' => $event_id, 'receiver' => $receiver, 'order_id' => (int) ($order['order_id'] ?? 0),
        ]);
        // 2026-11-12 04:49 +03: task 596 M3.0 fix round 1 (I1). The enricher threw: no send, as a throwing values()
        // does.
        if ($enrich_failed) {
            $this->skip('invalid', 'platform_missing', $order, $event_id, $receiver);

            return false;
        }
        // 2026-11-12 04:49 +03: task 596 M3.0 fix round 1 (I1). A declared user_id path (often kwtsms_ctx.user_id)
        // reaches the order before optedOut(), so an opted-out customer is never treated as a guest. declaredSms()
        // copies it too.
        if (isset($this->declared[$event_id])) {
            $user_path = $this->declared[$event_id]->path('user_id');
            $user_raw = $user_path === '' ? null : EventDeclaration::value($payload, $user_path);
            $user_id = is_scalar($user_raw) && !is_bool($user_raw) ? (int) $user_raw : 0;
            if ($user_id > 0) {
                $order['user_id'] = $user_id;
            }
        }

        // 2026-10-22 14:53 +03: ONE storefront id per send (ruling R3): the order's, else the event declaration's
        // `storefront` path, else the payload's, else 0. It is used by the storefront switch below, the template
        // lookup and the send context. Then the storefront gate (ruling R10: active, storefront switch, opt-out,
        // key claim): a storefront that turned this event OFF for itself writes one switched_off attempt and claims
        // no key. Task A2 (P2-1), task 596.
        $this->storefront = $this->storefrontOf($event_id, $order, $payload);
        if (
            $this->storefront_rules !== null
            && $this->storefront_rules->isSwitchedOff($this->storefront, $event_id, $receiver)
        ) {
            $this->skip(Diagnostics::SWITCHED_OFF, 'storefront_switch', $order, $event_id, $receiver);

            return false;
        }

        // 2026-11-14 09:55 +03: Task M3.3 (B3, P3-65), task 596. Back in stock is a fan-out of many accounts, not one
        // order: BackInStockNotifier looks up the registered subscribers, caps them, claims one key per account and
        // sends one call per language. Its checks replace the single-order ones below (no order, no opt-out: the
        // customer asked for this SMS). A transport without the notifier sends nothing.
        if ($event_id === Defaults::EVENT_BIS && $receiver === 'C' && isset($this->declared[$event_id])) {
            return $this->backInStock($payload, $this->declared[$event_id]);
        }

        // 2026-10-23 00:54 +03: Task A10 (SafatMall F6, P2-4, ruling R10), task 596. The customer opt-out comes right
        // after the storefront
        // switch and before any dispatch-key claim: the first failing check sets the reason.
        if ($this->optedOut($event_id, $receiver, $order)) {
            $this->skip(Diagnostics::SWITCHED_OFF, 'customer_opt_out', $order, $event_id, $receiver);

            return false;
        }

        // 2026-10-22 17:59 +03: Task A5, task 596. order.updated of an order that is new (or admin-created and waiting
        // for its Notify boxes) in this request is superseded: it already gets kwtsms.order_placed. Core dispatches
        // order.updated twice in Order management (order_management.php:736 and :762); both are superseded and burn
        // no dedupe key.
        if ($event_id === Defaults::EVENT_UPDATED && $this->isNewInThisRequest($order)) {
            $this->skip(self::SUPERSEDED, 'new_order', $order, $event_id, $receiver);

            return false;
        }

        // 2026-11-15 01:51 +03: task 596 M3.6 (P3-67). A vendor SMS for an order status or update event: only for the
        // vendor's own child order (a parent order or a marketplace order has no vendor), and never for a change the
        // vendor made itself. Both write one attempt row and burn no key.
        if (
            $receiver === 'V'
            && (strpos($event_id, EventsSchemaExtender::STATUS_PREFIX) === 0 || $event_id === Defaults::EVENT_UPDATED)
        ) {
            $order_company = (int) ($order['company_id'] ?? 0);
            if ($order_company <= 0) {
                // [] phones: the order's customer number is not this receiver's number.
                $this->skip(self::SUPERSEDED, 'no_vendor', $order, $event_id, $receiver, []);

                return false;
            }
            $actor = $this->orders->actor();
            if (
                $this->rules->isMultiVendor() && ($actor['area'] ?? '') === 'A'
                // 2026-11-15 18:12 +03: task 596 M3.6 fix round 1 (I1). The signed-in user's OWN company, never the
                // selected one: a root administrator (0) working vendor 7's orders through the picker still texts it.
                && (int) ($actor['auth_company_id'] ?? 0) === $order_company
            ) {
                $this->skip(Diagnostics::SWITCHED_OFF, self::SELF_TRIGGERED, $order, $event_id, $receiver, []);

                return false;
            }
        }

        if (strpos($event_id, EventsSchemaExtender::STATUS_PREFIX) === 0) {
            $sent = $this->statusSms($event_id, $receiver, $order);
        } elseif ($event_id === Defaults::EVENT_PLACED) {
            $meta = isset($payload['kwtsms']) && is_array($payload['kwtsms']) ? $payload['kwtsms'] : [];
            $sent = $this->placedSms($receiver, $order, $meta);
        } elseif (isset($this->declared[$event_id])) {
            $sent = $this->declaredSms($event_id, $receiver, $payload, $order);
        } else {
            $sent = false;
        }

        $this->debug('order_sms.notify', 'exit', [
            'event_id' => $event_id, 'receiver' => $receiver, 'sent' => $sent,
        ], $started);

        return $sent;
    }

    /**
     * 2026-11-14 09:55 +03: Task M3.3 (B3), task 596. Runs the back-in-stock fan-out with this notifier's send rule.
     * True when at least one number was handed to the send pipeline. Never throws (the transport's catch is the
     * last resort, but a lookup error must not lose the e-mail path either).
     *
     * @param array<string,mixed> $payload
     */
    private function backInStock(array $payload, EventDeclaration $decl): bool
    {
        if ($this->back_in_stock === null) {
            return false;
        }
        $actor = $this->orders->actor();
        $send = function (array $phones, string $text, array $context) use ($actor): bool {
            $context['actor_user_id'] = (int) ($actor['user_id'] ?? 0);
            $context['actor_company_id'] = (int) ($actor['company_id'] ?? 0);
            $context['audience'] = ($actor['area'] ?? '') === 'C' ? 'storefront' : 'admin';
            $log = [
                'event_id' => Defaults::EVENT_BIS,
                'receiver' => 'C',
                'order_id' => 0,
                'template' => '',
                'lang' => (string) $context['lang_code'],
                'recipients' => count($phones),
            ];

            return $this->dispatchSms($phones, $text, $context, $log, $actor);
        };

        return $this->back_in_stock->notify($payload, $this->storefront, $decl, $send) > 0;
    }

    /**
     * 2026-10-23 00:54 +03: Task A10 (SafatMall F6, P2-4), task 596. True when this SMS goes to a customer account
     * that opted out of
     * order-status SMS: receiver C, an order of a registered user, and an event of the opt-out scope (the status
     * events and every declaration with opt_out). A guest order (user_id 0) is never gated: the choice belongs to the
     * account, not the phone.
     *
     * @param array<string,mixed> $order
     */
    private function optedOut(string $event_id, string $receiver, array $order): bool
    {
        if ($this->prefs === null || $receiver !== 'C') {
            return false;
        }
        $user_id = (int) ($order['user_id'] ?? 0);
        if ($user_id <= 0) {
            return false;
        }
        $in_scope = strpos($event_id, EventsSchemaExtender::STATUS_PREFIX) === 0
            || (isset($this->declared[$event_id]) && $this->declared[$event_id]->optOut());

        return $in_scope && !$this->prefs->orderSmsAllowed($user_id);
    }

    /**
     * 2026-10-22 17:59 +03: Task A5, task 596. True while the order carries the new_order or manual flag of this
     * request.
     *
     * @param array<string,mixed> $order
     */
    private function isNewInThisRequest(array $order): bool
    {
        $order_id = (int) ($order['order_id'] ?? 0);

        return $order_id > 0 && ($this->flags->has('new_order', $order_id) || $this->flags->has('manual', $order_id));
    }

    /**
     * 2026-10-22 14:53 +03: the storefront id of one notification (ruling R3), Task A2, task 596.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $payload
     */
    private function storefrontOf(string $event_id, array $order, array $payload): int
    {
        $id = (int) ($order['storefront_id'] ?? 0);
        $declared_path = isset($this->declared[$event_id]) ? $this->declared[$event_id]->path('storefront') : '';
        if ($id <= 0 && $declared_path !== '') {
            $value = EventDeclaration::value($payload, $declared_path);
            $id = is_scalar($value) ? (int) $value : 0;
        }
        if ($id <= 0) {
            $id = (int) ($payload['storefront_id'] ?? 0);
        }

        return max(0, $id);
    }

    /**
     * 2026-10-22 17:11 +03: Task A4, task 596. The order array of an event: a declared event names its path (default
     * order_info), every other event hands order_info.
     *
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    private function orderOf(string $event_id, array $payload): array
    {
        $path = EventDeclaration::DEFAULT_ORDER_PATH;
        if (isset($this->declared[$event_id]) && $this->declared[$event_id]->path('order') !== '') {
            $path = $this->declared[$event_id]->path('order');
        }
        $order = EventDeclaration::value($payload, $path);

        return is_array($order) ? $order : [];
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
        // 2026-11-15 01:51 +03: task 596 M3.6 (P3-67). The vendor's status key is claimed by deliver() once the
        // approved number, the quota and the text are known, so a vendor without an approved number or over its quota
        // burns no key (C and A keep claiming here, as since 1.0.0).
        if ($receiver === 'V') {
            return $this->deliver($event_id, $receiver, $context_order, $extra, $actor, [
                'order_id' => $order_id,
                'parent_order_id' => $parent_id,
            ], ['key' => $key, 'window' => DispatchKeys::STATUS_WINDOW, 'reason' => '']);
        }
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

        // 2026-11-14 08:30 +03: Task M3.2 review I2 (c), task 596. An inactive vendor is refused BEFORE the placed
        // key is claimed (deliver() asks again for the other events).
        if ($this->vendorRefused($order, $ids, $template_event, $receiver)) {
            return false;
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
     * A declared event (format v2). 2026-10-22 17:11 +03: Task A4, task 596: paths for phone, country, language,
     * user, vendor and
     * storefront, the data_class hook (R4), secret placeholders, and the dedupe key claimed just before the send.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $order   The order array at the declaration's `order` path
     */
    private function declaredSms(string $event_id, string $receiver, array $payload, array $order): bool
    {
        $decl = $this->declared[$event_id];
        if (!in_array($receiver, $decl->receivers(), true)) {
            return false;
        }
        // 2026-11-12 02:05 +03: task 596 M3.0 (ruling PayloadEnricher). 2026-11-12 04:49 +03: fix round 1 (I1): the
        // payload arrives already enriched by notify() (enrich()), which also removed any incoming kwtsms_ctx.
        // 2026-10-22 20:27 +03: Task A6 fix round 1 (P2-R12, PRD 6.5 / R25), task 596. A public-form event
        // (registration) that comes from a
        // storefront request sends only while the core antibot scenario is active: Tygh\Web\Antibot::
        // isValidationRequiredForScenario() (app/Tygh/Web/Antibot.php:121-129: enabled, driver set up,
        // use_for[scenario]
        // = Y), the scenario the core rules assign to profiles.update and checkout.add_profile
        // (app/schemas/security/antibot_rules.php:19 and :54). Sends from the admin area are not gated.
        if (
            $decl->publicForm() !== ''
            && ($this->orders->actor()['area'] ?? '') === 'C'
            && !($this->antibot_gate !== null && (bool) call_user_func($this->antibot_gate, $decl->publicForm()))
        ) {
            $this->skip(Diagnostics::SWITCHED_OFF, 'antibot_required', $order, $event_id, $receiver);

            return false;
        }
        $extra = ['custom' => []];
        foreach ($decl->placeholders() as $name => $path) {
            $value = EventDeclaration::value($payload, $path);
            $extra['custom'][$name] = is_scalar($value) ? (string) $value : '';
        }
        $computed = [];
        if ($decl->dataClass() !== '') {
            try {
                // 2026-10-23 05:59 +03: Cleanup CA1-7 (A5), task 596. A customer SMS is rendered in the language
                // deliver() resolves (the declared lang path when it is a valid code, else the order's language chain);
                // the data class is told it as kwtsms_lang, so computed wording follows the template.
                $data_payload = $payload;
                if ($receiver === 'C') {
                    $lang_path = $decl->path('lang');
                    $path_lang = $lang_path === '' ? null : EventDeclaration::value($payload, $lang_path);
                    $path_lang = is_scalar($path_lang) && !is_bool($path_lang)
                        ? strtolower(trim((string) $path_lang)) : '';
                    $requested = preg_match('/^[a-z]{2}$/D', $path_lang) === 1
                        ? $path_lang : $this->data->customerLang($order);
                    // 2026-10-23 08:23 +03: Task CA1 fix round 1 (important 1), task 596. The language the template
                    // lookup really answers from (a blank or missing text falls back to the default language), the
                    // one deliver() renders in.
                    $found = $this->templates->resolve($event_id, $receiver, $requested, $this->storefront);
                    $data_payload['kwtsms_lang'] = is_array($found) && isset($found['lang']) && $found['lang'] !== ''
                        ? (string) $found['lang'] : $requested;
                } else {
                    // 2026-10-23 08:32 +0300: cleanup CA2-3 (A7 M3), task 596. The same for A and V: the language
                    // deliver() resolves for
                    // them (admin alert language, the vendor's language, else the backend default), after the fallback.
                    $company_path = $decl->path('company_id');
                    $company_raw = $company_path === '' ? null : EventDeclaration::value($payload, $company_path);
                    $company = is_scalar($company_raw) && !is_bool($company_raw) && (int) $company_raw > 0
                        ? (int) $company_raw : (int) ($order['company_id'] ?? 0);
                    $requested = $receiver === 'A'
                        ? $this->data->adminLang($this->config->adminAlertLang())
                        : $this->data->adminLang($this->vendorLang($company));
                    $found = $this->templates->resolve($event_id, $receiver, $requested, $this->storefront);
                    $data_payload['kwtsms_lang'] = is_array($found) && isset($found['lang']) && $found['lang'] !== ''
                        ? (string) $found['lang'] : $requested;
                }
                $computed = $this->dataClass($decl->dataClass())->values($data_payload, $receiver);
            } catch (\Throwable $e) {
                // 2026-10-23 06:01 +03: Cleanup CA1-8 (A4 M2), task 596. A data_class is often third-party code: a
                // warning (not an error of this add-on) with a plain sentence, and never the exception text, which can
                // carry event data such as a reset link.
                $this->dataClassFailed($decl->dataClass(), $e);
                $this->skip('invalid', 'platform_missing', $order, $event_id, $receiver);

                return false;
            }
        }
        if (isset($computed['placeholders']) && is_array($computed['placeholders'])) {
            foreach ($computed['placeholders'] as $name => $value) {
                if (is_string($name) && preg_match('/^[a-z0-9_]{1,40}$/D', $name) === 1 && is_scalar($value)) {
                    $extra['custom'][$name] = (string) $value;
                }
            }
        }
        $secrets = [];
        foreach ($decl->secretNames() as $name) {
            if (($extra['custom'][$name] ?? '') !== '') {
                $secrets[] = $extra['custom'][$name];
            }
        }
        if (isset($computed['secret_values']) && is_array($computed['secret_values'])) {
            foreach ($computed['secret_values'] as $value) {
                if (is_scalar($value) && (string) $value !== '') {
                    $secrets[] = (string) $value;
                }
            }
        }
        $extra['secret_values'] = $secrets;
        $extra['ttl'] = isset($computed['ttl']) ? max(0, (int) $computed['ttl']) : 0;

        // The order array carries what OrderSmsData reads: phone, language, country, user, vendor, storefront.
        $scalar = static function (string $path) use ($payload): string {
            $value = $path === '' ? null : EventDeclaration::value($payload, $path);

            return is_scalar($value) && !is_bool($value) ? trim((string) $value) : '';
        };
        if ($receiver === 'C') {
            $phone = $scalar($decl->path('phone'));
            if ($phone === '' && isset($computed['phone']) && is_string($computed['phone'])) {
                $phone = trim($computed['phone']);
            }
            if ($phone !== '') {
                $order['phone'] = $phone;
            }
        }
        $lang = strtolower($scalar($decl->path('lang')));
        if (preg_match('/^[a-z]{2}$/D', $lang) === 1) {
            $order['lang_code'] = $lang;
        }
        $country = strtoupper($scalar($decl->path('country')));
        if (preg_match('/^[A-Z]{2}$/D', $country) === 1) {
            $order['s_country'] = $country;
        }
        foreach (['user_id', 'company_id'] as $key) {
            $id = (int) $scalar($decl->path($key));
            if ($id > 0) {
                $order[$key] = $id;
            }
        }
        if ($this->storefront > 0 && (int) ($order['storefront_id'] ?? 0) <= 0) {
            $order['storefront_id'] = $this->storefront;
        }

        $dedupe = null;
        // 2026-11-12 02:05 +03: task 596 M3.0 (P3-64). The event id and the clock for the reserved tokens {event},
        // {hour}.
        $key = $decl->dedupeKey($payload, $receiver, $event_id, time());
        if ($key !== '') {
            $dedupe = ['key' => $key, 'window' => $decl->dedupeWindow(), 'reason' => $decl->dedupeReason()];
        } elseif ($decl->dedupeKeyError() !== '') {
            // A declaration without 'required' (format v2 third parties, the 1.3.0 events): the send goes out without
            // dedupe; the error row says why. 2026-11-12 02:05 +03: task 596 M3.0 (P3-64): with 'required' it fails
            // closed: the same error row, one switched_off / dedupe_unresolved attempt, no send, no key.
            if ($this->log !== null) {
                $this->log->log(
                    'error',
                    'order_sms.dedupe_key',
                    $decl->required()
                        ? 'dedupe key not resolved: the SMS was not sent (the throttle is required)'
                        : 'dedupe key not resolved: no dedupe for this SMS',
                    ['event_id' => $event_id, 'receiver' => $receiver, 'token' => $decl->dedupeKeyError()]
                );
            }
            if ($decl->required()) {
                $this->skip(Diagnostics::SWITCHED_OFF, self::DEDUPE_UNRESOLVED, $order, $event_id, $receiver);

                return false;
            }
        }

        return $this->deliver($event_id, $receiver, $order, $extra, $this->orders->actor(), [
            'order_id' => (int) ($order['order_id'] ?? 0),
            'parent_order_id' => (int) ($order['parent_order_id'] ?? 0),
        ], $dedupe, $decl->publicForm() !== '' ? $decl->publicForm() : $decl->vendorCap());
    }

    /**
     * The language of a vendor company, or '' (Multi-Vendor functions absent or no company). 2026-10-23 08:32 +0300:
     * split out of
     * deliver() so declaredSms() resolves the same language, cleanup CA2-3 (A7 M3), task 596.
     */
    private function vendorLang(int $company_id): string
    {
        return $company_id > 0 && function_exists('fn_get_company_language')
            ? strtolower((string) fn_get_company_language($company_id)) : '';
    }

    /**
     * 2026-11-12 04:49 +03: task 596 M3.0 fix round 1 (I1, P3-62). The PayloadEnricher step of a declared event,
     * run by notify() before the declaration is evaluated: a data_class that implements PayloadEnricher adds kwtsms_ctx
     * (language, user, company, plan id). kwtsms_ctx.company_id is always an int afterwards. Any other event, or a
     * receiver the declaration does not have, keeps its payload. A throw sets $failed (the caller skips as invalid /
     * platform_missing) and returns the payload unenriched.
     *
     * @param array<string,mixed> $payload without kwtsms_ctx
     *
     * @return array<string,mixed>
     */
    private function enrich(string $event_id, string $receiver, array $payload, bool &$failed): array
    {
        $decl = $this->declared[$event_id] ?? null;
        if (
            $decl === null || !in_array($receiver, $decl->receivers(), true) || $decl->dataClass() === ''
            || !is_subclass_of($decl->dataClass(), PayloadEnricher::class)
        ) {
            return $payload;
        }
        try {
            $enricher = $this->dataClass($decl->dataClass());
            $enriched = $enricher instanceof PayloadEnricher ? $enricher->enrich($payload, $receiver) : $payload;
        } catch (\Throwable $e) {
            $this->dataClassFailed($decl->dataClass(), $e);
            $failed = true;

            return $payload;
        }
        $ctx = isset($enriched['kwtsms_ctx']) && is_array($enriched['kwtsms_ctx']) ? $enriched['kwtsms_ctx'] : [];
        $company = $ctx['company_id'] ?? 0;
        $ctx['company_id'] = is_scalar($company) && !is_bool($company) ? (int) $company : 0;
        $enriched['kwtsms_ctx'] = $ctx;

        return $enriched;
    }

    /**
     * Logs a data_class whose values() threw: warning level, class names and file:line only. Never throws.
     * 2026-10-23 06:01 +03: added, Cleanup CA1-8 (A4 M2), task 596.
     */
    private function dataClassFailed(string $class, \Throwable $e): void
    {
        $line = 'data_class ' . $class . ' threw ' . get_class($e) . ' at ' . basename($e->getFile()) . ':'
            . $e->getLine() . '; the SMS was skipped';
        error_log('kwtsms: order_sms.data_class_failed: ' . $line);
        if ($this->log === null) {
            return;
        }
        try {
            $this->log->log('warning', 'order_sms.data_class_failed', $line);
        } catch (\Throwable $ignored) {
            error_log('kwtsms: order_sms.data_class_failed: the debug log could not take the line');
        }
    }

    /**
     * The instance of a data_class; throws when the class is missing or does not implement DeclaredSmsData.
     */
    private function dataClass(string $class): DeclaredSmsData
    {
        if (!isset($this->data_classes[$class])) {
            $instance = class_exists($class) ? new $class() : null;
            if (!$instance instanceof DeclaredSmsData) {
                throw new \RuntimeException('data_class ' . $class . ' is not a DeclaredSmsData');
            }
            $this->data_classes[$class] = $instance;
        }

        return $this->data_classes[$class];
    }

    // ------------------------------------------------------------------------------------------ delivery

    /**
     * Numbers, language, text, render, send. $event_id is the template's event and the event of the log rows.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $extra placeholders extras (order_ids, vendor_name) and custom => [name => value]
     * @param array<string,mixed> $actor OrderSource::actor()
     * @param array<string,int>   $ids   order_id, parent_order_id of the log rows
     * @param array<string,mixed>|null $dedupe 2026-10-22 17:12 +03: Task A4, task 596. key, window, reason of a
     * declared event's
     *                                         dedupe; claimed once the numbers and the text are known, just before
     * the send
     */
    private function deliver(
        string $event_id,
        string $receiver,
        array $order,
        array $extra,
        array $actor,
        array $ids,
        ?array $dedupe = null,
        string $public_form = ''
    ): bool {
        // 2026-11-14 07:40 +03: Task M3.2 review I2 (c), task 596. Every V send needs an ACTIVE vendor, checked
        // before its number is read; a company row that cannot be read is a platform_missing refusal.
        if ($this->vendorRefused($order, $ids, $event_id, $receiver)) {
            return false;
        }
        if ($receiver === 'A') {
            $phones = $this->config->adminPhones();
            $lang = $this->data->adminLang($this->config->adminAlertLang());
        } elseif ($receiver === 'V') {
            // 2026-10-22 17:12 +03: Task A4, task 596. The vendor's approved number (P2-5) and the vendor's language,
            // else the
            // backend default language.
            $company_id = (int) ($order['company_id'] ?? 0);
            $phone = $company_id > 0 && $this->vendor_phones !== null
                ? $this->vendor_phones->approvedPhone($company_id) : '';
            $phones = $phone === '' ? [] : [$phone];
            $lang = $this->data->adminLang($this->vendorLang($company_id));
        } else {
            $phone = OrderSmsData::customerPhone($order, $this->orders->profilePhone((int) ($order['user_id'] ?? 0)));
            $phones = $phone === '' ? [] : [$phone];
            $lang = $this->data->customerLang($order);
        }
        $log_order = array_merge($order, $ids);
        if ($phones === []) {
            $reason = $receiver === 'A' ? 'admin_phones_empty' : 'order_has_no_phone';
            if ($receiver === 'V') {
                $reason = 'vendor_not_approved';
            }
            $this->skip(self::NO_PHONE, $reason, $log_order, $event_id, $receiver, $receiver === 'V' ? [] : null);

            return false;
        }
        // 2026-10-22 22:18 +03: Task A8 (ruling R11, P2-6), task 596. The vendor's monthly quota applies to every V SMS
        // (the new-order SMS of a child and the low-stock SMS): over it, nothing goes out and the attempt says why.
        if (
            $receiver === 'V'
            && $this->vendor_quota !== null
            && !$this->vendor_quota->allows((int) ($order['company_id'] ?? 0), count($phones))
        ) {
            $this->skip(self::RATE_LIMITED, 'vendor_quota', $log_order, $event_id, $receiver, $phones);

            return false;
        }
        // 2026-11-14 07:40 +03: Task M3.2 review I2 (a, b), task 596. A public-form V SMS (the visitor chose the
        // vendor): refused once the vendor used the reserve share of its monthly quota (order and other V SMS keep
        // the rest), and once the vendor's daily ceiling is reached. Both are read-only here; the day counter is hit
        // only after the key is claimed, so a refusal or a duplicate burns neither.
        $day_bucket = '';
        if ($receiver === 'V' && $public_form !== '') {
            if (
                $this->vendor_quota !== null
                && $this->vendor_quota->reserveReached((int) ($order['company_id'] ?? 0), self::VENDOR_RESERVE_PERCENT)
            ) {
                $this->skip(self::RATE_LIMITED, self::VENDOR_QUOTA_RESERVED, $log_order, $event_id, $receiver, $phones);

                return false;
            }
            if ($this->limiter !== null) {
                $day_bucket = 'vday:' . $public_form . ':' . (int) ($order['company_id'] ?? 0);
                $seen = $this->limiter->peek($day_bucket);
                $live = $seen['window_start'] > $this->limiter->now() - 86400;
                if ($seen['hits'] >= self::VENDOR_DAILY_CEILING && $live) {
                    $this->skip(
                        self::RATE_LIMITED,
                        self::VENDOR_DAY_CEILING,
                        $log_order,
                        $event_id,
                        $receiver,
                        $phones
                    );

                    return false;
                }
            }
        }

        // 2026-10-22 14:53 +03: the storefront's own text first (P2-3), Task A2, task 596.
        $template = $this->templates->resolve($event_id, $receiver, $lang, $this->storefront);
        if ($template === null) {
            $this->skip(self::NO_TEMPLATE, $event_id . ' ' . $lang, $log_order, $event_id, $receiver, $phones);

            return false;
        }
        $text_lang = (string) $template['lang'];
        $custom = isset($extra['custom']) && is_array($extra['custom']) ? $extra['custom'] : [];
        // 2026-10-22 17:12 +03: Task A4, task 596. Secret values (masked in every log copy) and the outbox TTL come
        // from the
        // declaration; neither is a placeholder of the order data.
        $secret_values = isset($extra['secret_values']) && is_array($extra['secret_values'])
            ? $extra['secret_values'] : [];
        $ttl = (int) ($extra['ttl'] ?? 0);
        unset($extra['custom'], $extra['secret_values'], $extra['ttl']);
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
            'storefront_id' => $this->storefront,
            'user_id' => (int) ($order['user_id'] ?? 0),
            'actor_user_id' => (int) ($actor['user_id'] ?? 0),
            'actor_company_id' => (int) ($actor['company_id'] ?? 0),
            'lang_code' => $text_lang,
            'audience' => ($actor['area'] ?? '') === 'C' ? 'storefront' : 'admin',
            'interactive' => false,
            'country_iso' => $receiver === 'C' ? OrderSmsData::countryIso($order) : '',
        ];
        if ($secret_values !== []) {
            $context['secret_values'] = $secret_values;
        }
        if ($ttl > 0) {
            $context['ttl'] = $ttl;
        }
        // 2026-11-15 20:38 +03: Task M3.18 (G2, P3-75), task 596. The vendor's own Sender ID goes only on a V SMS
        // and on
        // the C SMS of one vendor's own order (an order with a company, never the parent of a multi-vendor checkout);
        // never on A. The service still checks the switch, the receiver and the company.
        if ($this->usesVendorSender($receiver, $order, $ids)) {
            $context['vendor_sender'] = '1';
        }
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
        // 2026-10-21 16:52 +03: the mode decides: fpm/litespeed send after the response, cron leaves the row for the
        // cron, inline sends now (final review I2, ruling R52), task 596.
        // 2026-10-22 17:12 +03: Task A4, task 596. The declared dedupe key is claimed here, after the numbers and the
        // text are
        // known: an SMS that cannot go out burns no key.
        if ($dedupe !== null && !$this->keys->claim((string) $dedupe['key'], (int) $dedupe['window'])) {
            $why = (string) ($dedupe['reason'] !== '' ? $dedupe['reason'] : $dedupe['key']);
            $this->skip(self::DUPLICATE, $why, $log_order, $event_id, $receiver, $phones);

            return false;
        }
        if ($day_bucket !== '') {
            // 2026-11-14 07:40 +03: Task M3.2 review I2, task 596. Counts a claimed SMS only.
            $this->limiter->hit($day_bucket, 86400);
        }
        return $this->dispatchSms($phones, $text, $context, $log, $actor);
    }

    /**
     * 2026-11-15 20:38 +03: Task M3.18 (G2), task 596. Whether this SMS may go out under the vendor's own Sender ID:
     * a V SMS of a vendor, or a C SMS of an order that belongs to one vendor (company_id > 0, not a parent order and
     * linked to an order id). Multi-Vendor only; the A SMS never.
     *
     * @param array<string,mixed> $order
     * @param array<string,int>   $ids
     */
    private function usesVendorSender(string $receiver, array $order, array $ids): bool
    {
        if (!$this->rules->isMultiVendor() || (int) ($order['company_id'] ?? 0) <= 0) {
            return false;
        }
        // The per-checkout SMS is logged against the parent (order id = parent id): never a vendor's.
        $parent_id = (int) ($ids['parent_order_id'] ?? 0);
        if ($parent_id > 0 && $parent_id === (int) ($ids['order_id'] ?? 0)) {
            return false;
        }
        if ($receiver === 'V') {
            return true;
        }

        return $receiver === 'C'
            && (int) ($ids['order_id'] ?? 0) > 0
            && (string) ($order['is_parent_order'] ?? 'N') !== 'Y';
    }

    /**
     * 2026-11-14 09:55 +03: Task M3.3 (back-in-stock SMS), task 596. The send tail of deliver(), unchanged, split out
     * so the back-in-stock fan-out uses the same after-response / cron / inline rule: the rendered text goes to the
     * outbox (fpm, litespeed, cron) or is sent in the request.
     *
     * @param string[]            $phones
     * @param array<string,mixed> $context SmsService::send() context
     * @param array<string,mixed> $log     info row context (ids only)
     * @param array<string,mixed> $actor   OrderSource::actor()
     */
    private function dispatchSms(array $phones, string $text, array $context, array $log, array $actor): bool
    {
        $mode = $this->sendMode($actor);
        if (AfterResponse::afterResponse($mode) || $mode === AfterResponse::CRON) {
            $outbox_id = $this->sms->queueAfterResponse($phones, $text, $context);
            if ($outbox_id > 0 && $mode === AfterResponse::CRON) {
                $this->info('order_sms.queued_for_cron', 'order SMS left in the outbox for the cron', $log + [
                    'outbox_id' => $outbox_id,
                ]);

                return true;
            }
            if ($outbox_id > 0) {
                $this->finish_mode = $mode;
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
        $this->drainStaleCron($mode, $actor);

        return true;
    }

    /**
     * 2026-10-23 03:30 +03: Task A12 item 1 (R53), task 596. A storefront request that sends inline because the cron
     * heartbeat is stale (no finisher function, no cron within 2 intervals) also drains up to 5 due outbox rows after
     * its own send, so a row queued while the cron still ran is sent, not left to expire after 30 minutes. Once per
     * request; never throws.
     *
     * @param array<string,mixed> $actor OrderSource::actor()
     */
    private function drainStaleCron(string $mode, array $actor): void
    {
        if (
            $this->drain_stale === null
            || $this->stale_drained
            || $mode !== AfterResponse::INLINE
            || ($actor['area'] ?? '') !== 'C'
        ) {
            return;
        }
        $this->stale_drained = true;
        try {
            call_user_func($this->drain_stale);
        } catch (\Throwable $e) {
            FailureLog::report($this->log, 'order_sms.stale_drain_failed', $e);
        }
    }

    /**
     * Sends what a storefront request deferred, after the response went to the browser (fastcgi_finish_request()).
     * Runs as a shutdown function; public only for that. Never throws.
     * 2026-10-21 03:11 +03: added, Task 1.9 scenario 14, task 596.
     * 2026-10-21 04:20 +03: sends the queued outbox rows by id through the outbox claim; a row the cron already
     * took is left to it (fix round 1 item 1), task 596.
     * 2026-10-21 16:52 +03: ends the response with the function of the mode (fastcgi_finish_request or
     * litespeed_finish_request), final review I2, task 596.
     */
    public function flushDeferred(): void
    {
        if ($this->deferred === []) {
            return;
        }
        AfterResponse::finish($this->finish_mode);
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
     * True for the events this transport sends: order status, kwtsms.order_placed, declared third-party events.
     * 2026-10-21 08:04 +03: added, ruling R37, task 596.
     */
    private function handles(string $event_id): bool
    {
        return strpos($event_id, EventsSchemaExtender::STATUS_PREFIX) === 0 || $event_id === Defaults::EVENT_PLACED
            || isset($this->declared[$event_id]);
    }

    /**
     * Default rule for sending after the response: a storefront web request served by PHP-FPM.
     * 2026-10-21 03:11 +03: added, Task 1.9 scenario 14, task 596.
     * 2026-10-21 16:52 +03: or by LiteSpeed (AfterResponse), final review I2, task 596.
     *
     * @param array<string,mixed> $actor OrderSource::actor()
     */
    public static function sendsAfterResponse(array $actor): bool
    {
        return AfterResponse::afterResponse((new AfterResponse())->mode($actor));
    }

    /**
     * The AfterResponse mode of the seam for this actor; true is fpm, any other non-string is inline.
     * 2026-10-21 16:52 +03: added, final review I2, task 596.
     *
     * @param array<string,mixed> $actor
     */
    private function sendMode(array $actor): string
    {
        $mode = call_user_func($this->after_response, $actor);
        if ($mode === true) {
            return AfterResponse::FPM;
        }
        $known = [AfterResponse::FPM, AfterResponse::LITESPEED, AfterResponse::CRON, AfterResponse::INLINE];

        return is_string($mode) && in_array($mode, $known, true) ? $mode : AfterResponse::INLINE;
    }

    /**
     * @param array<string,mixed> $order
     * @param string[]|null       $phones
     */
    /**
     * 2026-11-14 08:30 +03: Task M3.2 review I2 (c), task 596. A V SMS needs an active vendor: companies.status A. A
     * company row that cannot be read is invalid / platform_missing (no warning), a vendor that is not active is
     * no_phone / vendor_inactive. Writes the attempt row and answers true when it refused.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $ids
     */
    private function vendorRefused(array $order, array $ids, string $event_id, string $receiver): bool
    {
        if ($receiver !== 'V' || $this->vendor_quota === null) {
            return false;
        }
        $status = $this->vendor_quota->companyStatus((int) ($order['company_id'] ?? 0));
        if ($status === '') {
            $this->skip('invalid', 'platform_missing', array_merge($order, $ids), $event_id, $receiver);

            return true;
        }
        if ($status !== 'A') {
            $this->skip(self::NO_PHONE, self::VENDOR_INACTIVE, array_merge($order, $ids), $event_id, $receiver);

            return true;
        }

        return false;
    }

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

    // 2026-10-22 17:20 +03, Task A4, task 596: the dotted-path reader moved to EventDeclaration::value().

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
