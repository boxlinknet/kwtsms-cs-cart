<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Notifications/DeclaredSmsData.php
 * CREATED:   2026-10-22 17:11 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The hook of declaration format v2 for computed values: a class named in `data_class` turns the event data
 *            into the placeholders, phone, secret values and TTL that a plain dotted path cannot give.
 * WHY:       Ruling R4, Task A4: tracking-number cleaning (A5), the password-reset link and the b_phone fallback (A6),
 *            the "+N" of the low-stock text (A7) are computed, not read. One mechanism instead of one branch per
 *            event in OrderNotifier.
 * USAGE:     'data_class' => \Acme\ShippedSmsData::class in schemas/kwtsms/events.php. The class has a public
 *            constructor without arguments and implements values(). Every key of the answer is optional.
 * DEPENDS:   Nothing.
 */

namespace Tygh\Addons\Kwtsms\Notifications;

interface DeclaredSmsData
{
    /**
     * @param array<string,mixed> $data     The event data the dispatcher handed over. 2026-10-23 05:59 +03: Cleanup
     *                                      CA1-7, task 596: for receiver C it also holds kwtsms_lang, the language
     *                                      the SMS is rendered in (a two-letter code)
     * @param string              $receiver C, A or V
     *
     * @return array{placeholders?:array<string,string>,phone?:string,secret_values?:array<int,string>,ttl?:int}
     *         placeholders: name => value, wins over the declared paths; phone: the customer number (C only);
     *         secret_values: strings masked in every log copy of the SMS; ttl: seconds the outbox row stays valid
     */
    public function values(array $data, string $receiver): array;
}
