<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Orders/CheckoutRules.php
 * CREATED:   2026-10-21 01:53 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The Multi-Vendor rules of PRD 4.2 rule 5 as two answers: for which unit an order-placed SMS is sent
 *            (the plain order, each suborder, the whole checkout, or not at all) and whether a customer status SMS is
 *            per checkout or per suborder.
 * WHY:       A Multi-Vendor checkout of three vendors is one parent order (status T, never dispatched by core) and
 *            three child orders. The customer must get one "order received" SMS that lists the child ids (never the
 *            parent id, which customers cannot look up) unless the admin chose one per suborder; the admin gets one
 *            per checkout, one per suborder or none. Settings: mve_customer_new_order (per_checkout | per_suborder),
 *            mve_admin_new_order (per_checkout | per_suborder | off), mve_customer_status_scope (auto | per_suborder
 *            | per_checkout). "auto" is per_checkout only when the marketplace fulfils the orders of EVERY child's
 *            vendor (fn_are_company_orders_fulfilled_by_marketplace(), true with the order_fulfillment add-on and a
 *            fulfilment vendor plan): one shipment from the customer's point of view. A mixed checkout is per
 *            suborder, because those vendors ship on their own.
 * USAGE:     $rules = new CheckoutRules($config, fn_allowed_for('MULTIVENDOR'), $fulfilled);   // ServiceProvider wires
 *            $fulfilled to fn_are_company_orders_fulfilled_by_marketplace()
 *            $rules->placedUnit('C', $parent_order_id);   // order | suborder | checkout | off
 *            $rules->statusScope([1, 2, 3]);              // per_checkout | per_suborder (company ids of the children)
 * DEPENDS:   Settings\ConfigInterface.
 */

namespace Tygh\Addons\Kwtsms\Orders;

use Tygh\Addons\Kwtsms\Settings\ConfigInterface;

class CheckoutRules
{
    public const UNIT_ORDER = 'order';
    public const UNIT_SUBORDER = 'suborder';
    public const UNIT_CHECKOUT = 'checkout';
    public const UNIT_OFF = 'off';

    public const PER_CHECKOUT = 'per_checkout';
    public const PER_SUBORDER = 'per_suborder';

    /** @var ConfigInterface */
    private $config;

    /** @var bool */
    private $is_mve;

    /** @var callable|null (int $company_id): bool|null */
    private $fulfilled;

    /**
     * @param callable|null $fulfilled (int $company_id): ?bool, fn_are_company_orders_fulfilled_by_marketplace
     */
    public function __construct(ConfigInterface $config, bool $is_mve, ?callable $fulfilled = null)
    {
        $this->config = $config;
        $this->is_mve = $is_mve;
        $this->fulfilled = $fulfilled;
    }

    public function isMultiVendor(): bool
    {
        return $this->is_mve;
    }

    /**
     * The unit an order-placed SMS of this receiver is sent for.
     *
     * @param int $parent_order_id 0 for an order that is not a child of a checkout
     */
    public function placedUnit(string $receiver, int $parent_order_id): string
    {
        if ($receiver !== 'C' && $receiver !== 'A') {
            return self::UNIT_OFF;
        }
        if (!$this->is_mve || $parent_order_id <= 0) {
            return self::UNIT_ORDER;
        }
        $mode = $receiver === 'C' ? $this->config->mveCustomerNewOrder() : $this->config->mveAdminNewOrder();
        if ($mode === 'off') {
            return $receiver === 'A' ? self::UNIT_OFF : self::UNIT_CHECKOUT;
        }

        return $mode === self::PER_SUBORDER ? self::UNIT_SUBORDER : self::UNIT_CHECKOUT;
    }

    /**
     * Scope of the customer status SMS of a checkout.
     *
     * @param int[] $company_ids Vendor of each child order
     */
    public function statusScope(array $company_ids): string
    {
        if (!$this->is_mve) {
            return self::PER_SUBORDER;
        }
        $setting = $this->config->mveCustomerStatusScope();
        if ($setting === self::PER_CHECKOUT || $setting === self::PER_SUBORDER) {
            return $setting;
        }
        if ($company_ids === [] || $this->fulfilled === null) {
            return self::PER_SUBORDER;
        }
        foreach ($company_ids as $company_id) {
            if (call_user_func($this->fulfilled, (int) $company_id) !== true) {
                return self::PER_SUBORDER;
            }
        }

        return self::PER_CHECKOUT;
    }
}
