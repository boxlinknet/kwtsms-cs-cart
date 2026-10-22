<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Templates/Defaults.php
 * CREATED:   2026-10-20 23:01 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The list of Phase 1 SMS template rows (one per event id and receiver) with their language variable
 *            name, family, order status, allowed placeholders and secret placeholders; the language variable
 *            naming for templates, order status names and currency names.
 * WHY:       PRD 6.4. The seeder (TemplateRepository::seedDefaults), the Templates page and the transport all
 *            need the same answer to "which templates exist, and what may a text say". The default TEXTS are
 *            not here: they ship in var/langs/{en,ar}/addons/kwtsms.po (PRD 4.4), where a translator can edit
 *            them, and are read from there.
 * USAGE:     All methods are static and pure.
 *              Defaults::events()                 -> list of rows:
 *                  ['event_id' => 'order.status_changed.p', 'receiver' => 'C',
 *                   'langvar' => 'kwtsms.tpl.order.status_changed.p.C',
 *                   'parent' => 'order.status_changed'   (family event used when this row has no text; '' = none),
 *                   'status' => 'P'                      (order status code of a status row; '' = none),
 *                   'placeholders' => Defaults::PLACEHOLDERS, 'secret' => [], 'mve_only' => false]
 *              Defaults::langvar($event_id, $receiver)  -> 'kwtsms.tpl.<event id>.<receiver>'
 *              Defaults::parseLangvar($name)            -> ['event_id' => ..., 'receiver' => ...] or null
 *              Defaults::allowedPlaceholders($event_id, $receiver)
 *              Defaults::secretPlaceholders()           -> [] in Phase 1; OTP codes, reset links later
 *              Defaults::statusLangvar('P')             -> 'kwtsms.status.P'
 *              Defaults::currencyLangvar('KWD')         -> 'kwtsms.currency.KWD'
 *            Language variable naming (decided in Task 1.8): the event id is used as is, dots included, as
 *            CS-Cart core names its own (event.order.status_changed.name); the receiver is the last segment, so
 *            the name is reversible. Statuses are upper case like core status codes (kwtsms.status.P); events
 *            are lower case like event ids; receivers are upper case (C, A).
 * DEPENDS:   Nothing (pure PHP 7.4).
 */

namespace Tygh\Addons\Kwtsms\Templates;

class Defaults
{
    public const RECEIVER_CUSTOMER = 'C';
    public const RECEIVER_ADMIN = 'A';
    /** 2026-10-22 21:29 +03: Task A7, task 596. The vendor receiver (Multi-Vendor, declared events only). */
    public const RECEIVER_VENDOR = 'V';

    /** Our own new-order event (PRD 4.1) and its Multi-Vendor "one SMS for the whole checkout" variant. */
    public const EVENT_PLACED = 'kwtsms.order_placed';
    public const EVENT_PLACED_MULTI = 'kwtsms.order_placed.multi';

    /**
     * 2026-10-22 17:59 +03: Task A5 (SafatMall F2), task 596. Core's order.updated and order.shipment_updated, shipped
     * with default texts (declared in schemas/kwtsms/events.php).
     */
    public const EVENT_UPDATED = 'order.updated';
    public const EVENT_SHIPMENT = 'order.shipment_updated';

    /**
     * 2026-10-22 19:08 +03: Task A6 (SafatMall F5 part), task 596. The welcome SMS (core's profile.created.c), the
     * masked password
     * recovery SMS (core's profile.password_recover.c) and the add-on's own admin registration alert.
     */
    public const EVENT_WELCOME = 'profile.created.c';
    public const EVENT_RECOVER = 'profile.password_recover.c';
    public const EVENT_REGISTERED = 'kwtsms.customer_registered';

    /** 2026-10-22 21:29 +03: Task A7 (SafatMall F5 part), task 596. The add-on's own low-stock event (A and V). */
    public const EVENT_LOW_STOCK = 'kwtsms.low_stock';

    /** 2026-10-23 00:14 +03: Task A9, task 596. The add-on's own low-balance event (A only). */
    public const EVENT_LOW_BALANCE = 'kwtsms.low_balance';

    /** Core's generic status event; order.status_changed.<status> events are its children. */
    public const EVENT_STATUS = 'order.status_changed';

    /** Prefix of every template language variable. */
    public const LANGVAR_PREFIX = 'kwtsms.tpl.';

    /** Phase 1 placeholders (PRD 6.4), without braces. */
    public const PLACEHOLDERS = [
        'store_name', 'order_id', 'order_ids', 'vendor_name', 'order_total', 'currency', 'order_status',
        'customer_first_name', 'customer_last_name', 'order_date', 'payment_method', 'shipping_method',
    ];

    /**
     * 2026-10-22 17:59 +03: Task A5, task 596. Placeholders a shipped event has on top of PLACEHOLDERS (their values
     * come from the event's declaration and data class).
     */
    // 2026-10-22 18:38 +03: Task A5 fix round 1 (I1), task 596: shipment_info, the sentence part of the default
    // shipment text.
    public const EVENT_PLACEHOLDERS = [
        self::EVENT_SHIPMENT => ['tracking_number', 'carrier', 'tracking_url', 'shipment_info'],
        // 2026-10-22 19:08 +03: Task A6, task 596. The profile events (values from Profiles\ProfileSmsData).
        self::EVENT_WELCOME => ['user_id', 'customer_phone'],
        self::EVENT_REGISTERED => ['user_id', 'customer_phone'],
        // 2026-10-22 20:28 +03: valid_minutes, Task A6 fix round 1
        self::EVENT_RECOVER => ['user_id', 'customer_phone', 'reset_url', 'valid_minutes'],
        // 2026-10-22 21:29 +03: Task A7, task 596. The low-stock values (Orders\LowStockSmsData).
        self::EVENT_LOW_STOCK => ['product_name', 'product_code', 'stock_amount', 'more_count'],
        // 2026-10-23 00:14 +03: Task A9, task 596. Credits left and the alert level (Alerts\LowBalanceSms).
        self::EVENT_LOW_BALANCE => ['balance', 'threshold'],
    ];

    /**
     * 2026-10-22 19:08 +03: Task A6, task 596. Placeholders whose value is a secret even before the declarations are
     * registered
     * (seeding, unit tests): the password-reset link, masked in every log copy (D7).
     */
    public const EVENT_SECRETS = [
        self::EVENT_RECOVER => ['reset_url'],
    ];

    /**
     * Order statuses with a shipped name (kwtsms.status.<code>). A and Y both mean "Awaiting call" (core and
     * the call_requests add-on have used both codes); the caller falls back to the core name for any other.
     */
    public const STATUS_CODES = ['A', 'B', 'C', 'D', 'F', 'I', 'O', 'P', 'Y'];

    /** @var array<string,array{placeholders:array<int,string>,secret:array<int,string>}> Declared events (Task A4) */
    private static $declared = [];

    /** Currencies with a shipped name (kwtsms.currency.<ISO>). */
    public const CURRENCIES = ['KWD', 'SAR', 'AED', 'QAR', 'BHD', 'OMR', 'USD', 'EUR', 'EGP'];

    /** Statuses that have their own customer text; every other status uses the generic one. */
    private const OWN_STATUS_TEXTS = ['P', 'C', 'I', 'F', 'D', 'B', 'O'];

    /**
     * The Phase 1 template rows, in the order they are seeded and listed.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function events(): array
    {
        $rows = [
            self::row(self::EVENT_PLACED, self::RECEIVER_CUSTOMER),
            self::row(self::EVENT_PLACED, self::RECEIVER_ADMIN),
            self::row(self::EVENT_PLACED_MULTI, self::RECEIVER_CUSTOMER, self::EVENT_PLACED, '', true),
            // 2026-10-21 01:59 +03: the admin's one SMS per Multi-Vendor checkout ({order_ids}), Task 1.9 carry,
            // task 596.
            self::row(self::EVENT_PLACED_MULTI, self::RECEIVER_ADMIN, self::EVENT_PLACED, '', true),
            self::row(self::EVENT_STATUS, self::RECEIVER_CUSTOMER),
            self::row(self::EVENT_STATUS, self::RECEIVER_ADMIN),
        ];
        foreach (self::OWN_STATUS_TEXTS as $status) {
            $rows[] = self::row(
                self::EVENT_STATUS . '.' . strtolower($status),
                self::RECEIVER_CUSTOMER,
                self::EVENT_STATUS,
                $status
            );
        }
        // 2026-10-22 18:01 +03: Task A5 (SafatMall F2), task 596. Core's order.updated and order.shipment_updated,
        // shipped with default
        // texts (after the status rows, the order of the .po files).
        $rows[] = self::row(self::EVENT_UPDATED, self::RECEIVER_CUSTOMER);
        $rows[] = self::row(self::EVENT_UPDATED, self::RECEIVER_ADMIN);
        $rows[] = self::row(self::EVENT_SHIPMENT, self::RECEIVER_CUSTOMER);
        // 2026-10-22 19:08 +03: Task A6 (SafatMall F5 part), task 596. Welcome SMS, admin registration alert,
        // recovery SMS.
        $rows[] = self::row(self::EVENT_WELCOME, self::RECEIVER_CUSTOMER);
        $rows[] = self::row(self::EVENT_REGISTERED, self::RECEIVER_ADMIN);
        $rows[] = self::row(self::EVENT_RECOVER, self::RECEIVER_CUSTOMER);
        // 2026-10-22 21:29 +03: Task A7 (SafatMall F5 part), task 596. Low-stock SMS: admin, vendor.
        $rows[] = self::row(self::EVENT_LOW_STOCK, self::RECEIVER_ADMIN);
        $rows[] = self::row(self::EVENT_LOW_STOCK, self::RECEIVER_VENDOR, '', '', true);
        // 2026-10-22 22:24 +03: Task A8 (SafatMall F4, R9), task 596. The vendor's new-order SMS (one per child order
        // of a Multi-Vendor
        // checkout; child id, child total, marketplace brand, no {vendor_name}).
        $rows[] = self::row(self::EVENT_PLACED, self::RECEIVER_VENDOR, '', '', true);
        // 2026-10-23 00:16 +03: Task A9, task 596. Low kwtSMS balance: admin.
        $rows[] = self::row(self::EVENT_LOW_BALANCE, self::RECEIVER_ADMIN);

        return $rows;
    }

    /**
     * Language variable name of a template (without the "Languages::" context).
     */
    public static function langvar(string $event_id, string $receiver): string
    {
        return self::LANGVAR_PREFIX . $event_id . '.' . $receiver;
    }

    /**
     * Inverse of langvar(): null when the name is not a template language variable.
     *
     * @return array{event_id:string,receiver:string}|null
     */
    public static function parseLangvar(string $name): ?array
    {
        if (strpos($name, self::LANGVAR_PREFIX) !== 0) {
            return null;
        }
        $rest = substr($name, strlen(self::LANGVAR_PREFIX));
        $dot = strrpos($rest, '.');
        if ($dot === false || $dot === 0 || $dot === strlen($rest) - 1) {
            return null;
        }

        return ['event_id' => substr($rest, 0, $dot), 'receiver' => substr($rest, $dot + 1)];
    }

    /**
     * Placeholders a text of this event and receiver may use without "Save anyway".
     *
     * @return array<int,string>
     */
    public static function allowedPlaceholders(string $event_id, string $receiver): array
    {
        // 2026-10-22 17:12 +03: Task A4, task 596. A declared event adds the placeholder names of its declaration.
        // 2026-10-22 17:59 +03: Task A5, task 596. A shipped event (order.shipment_updated) knows its own names even
        // before the declarations are registered (seeding, unit tests).
        $declared = array_merge(
            self::EVENT_PLACEHOLDERS[$event_id] ?? [],
            self::$declared[$event_id]['placeholders'] ?? []
        );

        if ($declared === []) {
            return self::PLACEHOLDERS;
        }

        return array_values(array_unique(array_merge(self::PLACEHOLDERS, $declared)));
    }

    /**
     * 2026-10-22 17:12 +03: Task A4, task 596. The placeholder names (and the secret ones) of the declared events, so
     * the Templates
     * page and the unknown-placeholder check know {tracking} of a declaration. Set once per request by the container.
     *
     * @param array<string,array{placeholders:array<int,string>,secret:array<int,string>}> $declared event id => names
     */
    public static function registerDeclared(array $declared): void
    {
        self::$declared = $declared;
    }

    /**
     * Placeholders whose value is a secret (OTP code, password-reset link): masked in every log copy (D7).
     * Empty in Phase 1; the OTP events of Phase 3 add theirs here.
     *
     * @return array<int,string>
     */
    public static function secretPlaceholders(string $event_id = ''): array
    {
        // 2026-10-22 17:12 +03: Task A4, task 596. The secret names of a declared event (`secret` of format v2).
        // 2026-10-22 19:08 +03: Task A6, task 596. Plus the secret names every build knows (the recovery SMS reset
        // link).
        return $event_id === '' ? [] : array_values(array_unique(array_merge(
            self::EVENT_SECRETS[$event_id] ?? [],
            self::$declared[$event_id]['secret'] ?? []
        )));
    }

    public static function statusLangvar(string $code): string
    {
        return 'kwtsms.status.' . strtoupper($code);
    }

    public static function currencyLangvar(string $iso): string
    {
        return 'kwtsms.currency.' . strtoupper($iso);
    }

    /**
     * @return array<string,mixed>
     */
    private static function row(
        string $event_id,
        string $receiver,
        string $parent = '',
        string $status = '',
        bool $mve_only = false
    ): array {
        return [
            'event_id' => $event_id,
            'receiver' => $receiver,
            'langvar' => self::langvar($event_id, $receiver),
            'parent' => $parent,
            'status' => $status,
            'placeholders' => self::allowedPlaceholders($event_id, $receiver),
            'secret' => self::secretPlaceholders($event_id),
            'mve_only' => $mve_only,
        ];
    }
}
