<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Notifications/EventConditions.php
 * CREATED:   2026-11-16 12:55 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The SMS conditions of one event, receiver and storefront (P3-68): a minimum order total, a list of
 *            payment methods, a list of shipping methods, and "staff quiet hours" for an admin or vendor alert.
 * WHY:       Task M3.7 (B7): a merchant wants the admin alert only for orders of 5 KWD or more, a vendor alert only
 *            for one payment method, or a night alert held until the quiet window ends.
 * USAGE:     $c = Tygh::$app['kwtsms.event_conditions'];
 *            $r = $c->passes('order.status_changed.c', 'C', $storefront_id, $order);
 *              // ['ok' => bool, 'reason' => '' | condition_min_total | condition_payment | condition_shipping,
 *              //  'staff_quiet' => bool]; one primary-key read per event, receiver and storefront, cached per request
 *            $s = $c->save($event_id, $receiver, $storefront_id, $posted);   // ['ok' => bool, 'errors' => string[]]
 *            $c->forget();                                                   // drops the per-request cache
 *            Lookup: the storefront's own row, else the storefront-0 row, else no conditions. Order conditions apply
 *            only to an event with an order (an array with a positive order_id); staff_quiet applies to A and V only.
 *            The conditions column is JSON written by save() and never trusted on read: every type is checked.
 *            The database touches are protected methods, so the unit test replaces them.
 * DEPENDS:   Table kwtsms_event_conditions (schema 1.4.0, DDL in addon.xml); core tables payments, shippings and
 *            their descriptions for the lists of active methods.
 */

namespace Tygh\Addons\Kwtsms\Notifications;

class EventConditions
{
    public const REASON_MIN_TOTAL = 'condition_min_total';
    public const REASON_PAYMENT = 'condition_payment';
    public const REASON_SHIPPING = 'condition_shipping';
    // 2026-11-16 16:40 +03: M3.7 fix round 1, task 596 (M1): a minimum is set but the order has no readable total.
    public const REASON_PLATFORM_MISSING = 'platform_missing';

    /** The decimal a minimum total is posted as: up to 10 integer digits and up to 3 fraction digits. */
    // 2026-11-16 16:40 +03: M3.7 fix round 1, task 596 (M2): the D modifier, so a trailing newline no longer matches.
    public const DECIMAL = '/^\d{1,10}(\.\d{1,3})?$/D';

    /** At most this many ids of one list are stored (a store has far fewer methods; bounds the JSON). */
    private const MAX_IDS = 200;

    private const NONE = ['min_total' => '', 'payment_ids' => [], 'shipping_ids' => [], 'staff_quiet' => false];

    /** @var array<string,array{min_total:string,payment_ids:int[],shipping_ids:int[],staff_quiet:bool}> */
    private $cache = [];

    /**
     * @param array<string,mixed> $order The order of the event (an empty array for an event without an order)
     *
     * @return array{ok:bool,reason:string,staff_quiet:bool}
     */
    public function passes(string $event_id, string $receiver, int $storefront_id, array $order): array
    {
        $none = ['ok' => true, 'reason' => '', 'staff_quiet' => false];
        if ($event_id === '' || ($receiver !== 'C' && $receiver !== 'A' && $receiver !== 'V')) {
            return $none;
        }
        $cond = $this->effective($event_id, $receiver, max(0, $storefront_id));
        // 2026-11-16 16:40 +03: M3.7 fix round 1, task 596 (I2): the placed events are exempt from quiet hours (plan
        // ruling 17), so no staff_quiet for them.
        $none['staff_quiet'] = $cond['staff_quiet'] && $receiver !== 'C' && self::supportsQuiet($event_id);
        // Order conditions apply to an event that has an order; any other event ignores them.
        if ((int) ($order['order_id'] ?? 0) > 0) {
            if ($cond['min_total'] !== '') {
                $total = self::totalOf($order);
                if ($total === null) {
                    // 2026-11-16 16:40 +03: M3.7 fix round 1, task 596 (M1): fail closed, but do not blame the order.
                    return ['ok' => false, 'reason' => self::REASON_PLATFORM_MISSING, 'staff_quiet' => false];
                }
                if (self::compare($total, $cond['min_total']) < 0) {
                    return ['ok' => false, 'reason' => self::REASON_MIN_TOTAL, 'staff_quiet' => false];
                }
            }
            $payment = (int) ($order['payment_id'] ?? 0);
            if ($cond['payment_ids'] !== [] && !in_array($payment, $cond['payment_ids'], true)) {
                return ['ok' => false, 'reason' => self::REASON_PAYMENT, 'staff_quiet' => false];
            }
            $shipping = array_intersect(self::shippingIds($order), $cond['shipping_ids']);
            if ($cond['shipping_ids'] !== [] && $shipping === []) {
                return ['ok' => false, 'reason' => self::REASON_SHIPPING, 'staff_quiet' => false];
            }
        }

        return $none;
    }

    /**
     * Validates and writes the conditions of one scope. An empty form deletes the row (the scope then follows the
     * storefront-0 row, or has no conditions); for a storefront while All storefronts has a row it stores "{}", an
     * override with no limits.
     *
     * @param array<string,mixed> $posted min_total (string), payment_ids (list), shipping_ids (list), staff_quiet
     *
     * @return array{ok:bool,errors:string[]}
     */
    public function save(string $event_id, string $receiver, int $storefront_id, array $posted): array
    {
        $errors = [];
        if (
            $event_id === '' || strlen($event_id) > 128 || ($receiver !== 'C' && $receiver !== 'A' && $receiver !== 'V')
            || $storefront_id < 0
        ) {
            return ['ok' => false, 'errors' => ['kwtsms.conditions.err_row']];
        }

        $min = '';
        if (isset($posted['min_total'])) {
            if (!is_string($posted['min_total'])) {
                $errors[] = 'kwtsms.conditions.err_min_total';
            } else {
                $typed = trim($posted['min_total']);
                if ($typed !== '' && preg_match(self::DECIMAL, $typed) !== 1) {
                    $errors[] = 'kwtsms.conditions.err_min_total';
                } elseif ($typed !== '' && self::compare($typed, '0') > 0) {
                    $min = $typed;
                }
            }
        }
        $payments = $this->postedIds(
            $posted,
            'payment_ids',
            $this->activePayments(),
            'kwtsms.conditions.err_payment',
            $errors
        );
        $shippings = $this->postedIds(
            $posted,
            'shipping_ids',
            $this->activeShippings(),
            'kwtsms.conditions.err_shipping',
            $errors
        );
        // 2026-11-16 16:40 +03: M3.7 fix round 1, task 596 (I2): not for the placed events (exempt from quiet hours,
        // plan ruling 17).
        $quiet = ($receiver === 'A' || $receiver === 'V') && self::supportsQuiet($event_id)
            && isset($posted['staff_quiet']) && $posted['staff_quiet'] === '1';
        if ($errors !== []) {
            return ['ok' => false, 'errors' => array_values(array_unique($errors))];
        }

        $json = [];
        if ($min !== '') {
            $json['min_total'] = $min;
        }
        if ($payments !== []) {
            $json['payment_ids'] = $payments;
        }
        if ($shippings !== []) {
            $json['shipping_ids'] = $shippings;
        }
        if ($quiet) {
            $json['staff_quiet'] = '1';
        }
        if ($json === [] && ($storefront_id === 0 || !$this->hasRow($event_id, $receiver, 0))) {
            $this->deleteRow($event_id, $receiver, $storefront_id);
        } elseif ($json === []) {
            // 2026-11-16 16:40 +03: M3.7 fix round 1, task 596 (I3): a storefront with an empty form while All
            // storefronts has a row stores "{}", an
            // override with no limits (effective() lets a storefront row win even when it is empty).
            $this->writeRow($event_id, $receiver, $storefront_id, '{}');
        } else {
            $this->writeRow($event_id, $receiver, $storefront_id, (string) json_encode($json));
        }
        $this->forget();

        return ['ok' => true, 'errors' => []];
    }

    /**
     * 2026-11-16 16:40 +03: M3.7 fix round 1, task 596 (I3): whether the All storefronts row of this event and
     * receiver exists.
     */
    public function hasRow(string $event_id, string $receiver, int $storefront_id): bool
    {
        return isset($this->readRows($event_id, $receiver, $storefront_id)[$storefront_id]);
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /**
     * The conditions of ONE scope (no fallback) for the editor, one query for many rows.
     *
     * @param string[] $event_ids
     *
     * @return array<string,array{min_total:string,payment_ids:int[],shipping_ids:int[],staff_quiet:bool}> keyed
     *         "event|receiver"; only rows that exist
     */
    public function scope(array $event_ids, int $storefront_id): array
    {
        $out = [];
        $ids = array_values(array_unique(array_filter($event_ids, 'is_string')));
        if ($ids === []) {
            return $out;
        }
        foreach ($this->loadScope($ids, max(0, $storefront_id)) as $key => $json) {
            $out[(string) $key] = self::parse((string) $json);
        }

        return $out;
    }

    /**
     * Active methods for the editor and the validation.
     *
     * @return array{payments:array<int,string>,shippings:array<int,string>}
     */
    public function options(): array
    {
        return ['payments' => $this->activePayments(), 'shippings' => $this->activeShippings()];
    }

    // ------------------------------------------------------------------ reading

    /**
     * @return array{min_total:string,payment_ids:int[],shipping_ids:int[],staff_quiet:bool}
     */
    private function effective(string $event_id, string $receiver, int $storefront_id): array
    {
        $key = $storefront_id . '|' . $event_id . '|' . $receiver;
        if (!isset($this->cache[$key])) {
            $rows = $this->readRows($event_id, $receiver, $storefront_id);
            // The storefront's row beats the storefront-0 row, even when it is empty of limits.
            if ($storefront_id > 0 && isset($rows[$storefront_id])) {
                $this->cache[$key] = self::parse((string) $rows[$storefront_id]);
            } elseif (isset($rows[0])) {
                $this->cache[$key] = self::parse((string) $rows[0]);
            } else {
                $this->cache[$key] = self::NONE;
            }
        }

        return $this->cache[$key];
    }

    /**
     * Never trusts the stored JSON: anything of the wrong type is dropped (that limit is then off).
     *
     * @return array{min_total:string,payment_ids:int[],shipping_ids:int[],staff_quiet:bool}
     */
    public static function parse(string $json): array
    {
        $out = self::NONE;
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $out;
        }
        if (
            isset($data['min_total']) && is_string($data['min_total'])
            && preg_match(self::DECIMAL, $data['min_total']) === 1
        ) {
            $out['min_total'] = $data['min_total'];
        }
        foreach (['payment_ids', 'shipping_ids'] as $name) {
            // 2026-11-16 13:08 +03: a JSON object is not a list of ids: dropped, that limit is off (task 596 M3.7).
            if (isset($data[$name]) && is_array($data[$name]) && $data[$name] === array_values($data[$name])) {
                $ids = [];
                foreach (array_slice($data[$name], 0, self::MAX_IDS) as $id) {
                    if (is_int($id) && $id > 0) {
                        $ids[$id] = $id;
                    } elseif (is_string($id) && ctype_digit($id) && strlen($id) <= 10 && (int) $id > 0) {
                        $ids[(int) $id] = (int) $id;
                    }
                }
                $out[$name] = array_values($ids);
            }
        }
        $out['staff_quiet'] = isset($data['staff_quiet']) && $data['staff_quiet'] === '1';

        return $out;
    }

    /**
     * The order total as a decimal string in the primary currency; an unreadable or negative total is 0.
     *
     * @param array<string,mixed> $order
     */
    private static function totalOf(array $order): ?string
    {
        if (!isset($order['total'])) {
            return null;
        }
        $total = $order['total'];
        if (is_int($total)) {
            return $total > 0 ? (string) $total : '0';
        }
        if (is_float($total)) {
            return $total > 0 ? sprintf('%.4F', $total) : '0';
        }
        if (is_string($total) && preg_match('/^\d{1,15}(\.\d{1,8})?$/', trim($total)) === 1) {
            return trim($total);
        }

        return null;
    }

    /**
     * 2026-11-16 16:40 +03: M3.7 fix round 1, task 596 (I2): quiet hours do not apply to kwtsms.order_placed and
     * kwtsms.order_placed.multi (plan ruling 17).
     */
    public static function supportsQuiet(string $event_id): bool
    {
        return strncmp($event_id, 'kwtsms.order_placed', 19) !== 0;
    }

    /**
     * Every shipping id of the order itself: the shipping lines, then the chosen shippings of its product groups.
     * A child order of a multi-vendor checkout carries its own lines, so the parent's are never read.
     *
     * @param array<string,mixed> $order
     *
     * @return int[]
     */
    private static function shippingIds(array $order): array
    {
        $ids = [];
        $take = static function ($line) use (&$ids): void {
            if (is_array($line) && isset($line['shipping_id']) && is_scalar($line['shipping_id'])) {
                $id = (int) $line['shipping_id'];
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        };
        if (isset($order['shipping']) && is_array($order['shipping'])) {
            array_map($take, $order['shipping']);
        }
        if (isset($order['product_groups']) && is_array($order['product_groups'])) {
            foreach ($order['product_groups'] as $group) {
                if (is_array($group) && isset($group['chosen_shippings']) && is_array($group['chosen_shippings'])) {
                    array_map($take, $group['chosen_shippings']);
                }
            }
        }

        return array_values($ids);
    }

    /**
     * Compares two non-negative decimal strings without floats: -1, 0 or 1.
     */
    private static function compare(string $a, string $b): int
    {
        $left = explode('.', $a, 2) + [1 => ''];
        $right = explode('.', $b, 2) + [1 => ''];
        $li = ltrim($left[0], '0');
        $ri = ltrim($right[0], '0');
        if (strlen($li) !== strlen($ri)) {
            return strlen($li) < strlen($ri) ? -1 : 1;
        }
        $width = max(strlen($left[1]), strlen($right[1]));
        $cmp = strcmp($li . str_pad($left[1], $width, '0'), $ri . str_pad($right[1], $width, '0'));

        return $cmp < 0 ? -1 : ($cmp > 0 ? 1 : 0);
    }

    /**
     * @param array<string,mixed> $posted
     * @param array<int,string>   $active
     * @param string[]            $errors
     *
     * @return int[]
     */
    private function postedIds(array $posted, string $name, array $active, string $error, array &$errors): array
    {
        if (!isset($posted[$name])) {
            return [];
        }
        if (!is_array($posted[$name]) || count($posted[$name]) > self::MAX_IDS) {
            $errors[] = $error;

            return [];
        }
        $ids = [];
        foreach ($posted[$name] as $id) {
            if (!is_string($id) || !ctype_digit($id) || strlen($id) > 10 || !isset($active[(int) $id])) {
                $errors[] = $error;

                return [];
            }
            $ids[(int) $id] = (int) $id;
        }

        return array_values($ids);
    }

    // ------------------------------------------------------------------ the store (replaced by tests)

    /**
     * One primary-key read: the storefront's row and the storefront-0 row of this event and receiver.
     *
     * @return array<int,string> storefront id => JSON
     */
    protected function readRows(string $event_id, string $receiver, int $storefront_id): array
    {
        $out = [];
        $rows = db_get_array(
            'SELECT storefront_id, conditions FROM ?:kwtsms_event_conditions'
            . ' WHERE event_id = ?s AND receiver = ?s AND storefront_id IN (?n)',
            $event_id,
            $receiver,
            $storefront_id > 0 ? [$storefront_id, 0] : [0]
        );
        foreach ((array) $rows as $row) {
            $out[(int) $row['storefront_id']] = (string) $row['conditions'];
        }

        return $out;
    }

    /**
     * @param string[] $event_ids
     *
     * @return array<string,string> "event|receiver" => JSON
     */
    protected function loadScope(array $event_ids, int $storefront_id): array
    {
        $out = [];
        $rows = db_get_array(
            'SELECT event_id, receiver, conditions FROM ?:kwtsms_event_conditions'
            . ' WHERE event_id IN (?a) AND storefront_id = ?i',
            $event_ids,
            $storefront_id
        );
        foreach ((array) $rows as $row) {
            $out[$row['event_id'] . '|' . $row['receiver']] = (string) $row['conditions'];
        }

        return $out;
    }

    protected function writeRow(string $event_id, string $receiver, int $storefront_id, string $json): void
    {
        db_query('REPLACE INTO ?:kwtsms_event_conditions ?e', [
            'event_id' => $event_id,
            'receiver' => $receiver,
            'storefront_id' => $storefront_id,
            'conditions' => $json,
            'updated_at' => time(),
        ]);
    }

    protected function deleteRow(string $event_id, string $receiver, int $storefront_id): void
    {
        db_query(
            'DELETE FROM ?:kwtsms_event_conditions WHERE event_id = ?s AND receiver = ?s AND storefront_id = ?i',
            $event_id,
            $receiver,
            $storefront_id
        );
    }

    /**
     * @return array<int,string> payment id => name, active methods only
     */
    protected function activePayments(): array
    {
        $lang = defined('DESCR_SL') ? (string) DESCR_SL : 'en';

        return (array) db_get_hash_single_array(
            'SELECT p.payment_id, d.payment FROM ?:payments AS p'
            . ' LEFT JOIN ?:payment_descriptions AS d ON d.payment_id = p.payment_id AND d.lang_code = ?s'
            . " WHERE p.status = 'A' ORDER BY p.position, p.payment_id",
            ['payment_id', 'payment'],
            $lang
        );
    }

    /**
     * @return array<int,string> shipping id => name, active methods only
     */
    protected function activeShippings(): array
    {
        $lang = defined('DESCR_SL') ? (string) DESCR_SL : 'en';

        return (array) db_get_hash_single_array(
            'SELECT s.shipping_id, d.shipping FROM ?:shippings AS s'
            . ' LEFT JOIN ?:shipping_descriptions AS d ON d.shipping_id = s.shipping_id AND d.lang_code = ?s'
            . " WHERE s.status = 'A' ORDER BY s.position, s.shipping_id",
            ['shipping_id', 'shipping'],
            $lang
        );
    }
}
