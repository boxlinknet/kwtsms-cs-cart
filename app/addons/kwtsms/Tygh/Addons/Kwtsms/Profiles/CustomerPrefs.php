<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Profiles/CustomerPrefs.php
 * CREATED:   2026-10-23 00:52 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The customer's SMS preference: whether the account wants order-status SMS (statuses, order updates,
 *            shipments). Stored in kwtsms_customer_prefs, one row per customer who opted out.
 * WHY:       Phase 2 Task A10 (SafatMall F6, P2-4). The opt-out belongs to the user account, not the phone: a guest
 *            order with the same phone still gets its SMS. No row means allowed (the default is ticked). A customer who
 *            opts back in has the row removed, so the table holds only opt-outs. The order receipt and security SMS
 *            never read this (OrderNotifier asks only for status events and declarations with opt_out).
 * USAGE:     $prefs = Tygh::$app['kwtsms.customer_prefs'];
 *            $prefs->orderSmsAllowed($user_id);   // user 0 -> true; no row -> true; one PK SELECT, cached per request
 *            $prefs->save($user_id, false);       // opt out (row N); true removes the row
 *            The three database touches are protected methods, so the unit test replaces them.
 * DEPENDS:   Table kwtsms_customer_prefs (schema 1.1.0, PK user_id); CS-Cart db_* functions.
 */

namespace Tygh\Addons\Kwtsms\Profiles;

class CustomerPrefs
{
    public const ALLOWED = 'Y';
    public const OPTED_OUT = 'N';

    /** @var array<int,bool> user_id => allowed (cache of this request) */
    private $cache = [];

    public function orderSmsAllowed(int $user_id): bool
    {
        if ($user_id <= 0) {
            return true;
        }
        if (!array_key_exists($user_id, $this->cache)) {
            $this->cache[$user_id] = $this->readChoice($user_id) !== self::OPTED_OUT;
        }

        return $this->cache[$user_id];
    }

    public function save(int $user_id, bool $allowed): void
    {
        if ($user_id <= 0) {
            return;
        }
        if ($allowed) {
            $this->deleteChoice($user_id);
        } else {
            $this->writeChoice($user_id, self::OPTED_OUT);
        }
        $this->cache[$user_id] = $allowed;
    }

    // ------------------------------------------------------------------ the store (replaced by tests)

    protected function readChoice(int $user_id): string
    {
        return (string) db_get_field('SELECT order_sms FROM ?:kwtsms_customer_prefs WHERE user_id = ?i', $user_id);
    }

    protected function writeChoice(int $user_id, string $choice): void
    {
        db_query('REPLACE INTO ?:kwtsms_customer_prefs ?e', [
            'user_id' => $user_id,
            'order_sms' => $choice,
            'updated_at' => time(),
        ]);
    }

    protected function deleteChoice(int $user_id): void
    {
        db_query('DELETE FROM ?:kwtsms_customer_prefs WHERE user_id = ?i', $user_id);
    }
}
