<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Profiles/OrderSmsScope.php
 * CREATED:   2026-10-29 00:03 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Whether any customer order SMS that the opt-out covers is ON for a storefront: an order status change,
 *            "order updated" or "shipment created" with the customer (C) receiver switched on.
 * WHY:       Task M1.11s item 2 (SafatMall note 2). The registration and profile forms showed "Send me SMS about my
 *            order status" (ticked) on a store where every customer order SMS was OFF, which promised an SMS that never
 *            comes. The box is hidden while there is nothing to opt out of. A stored choice is never touched.
 * USAGE:     $scope = new OrderSmsScope($declared_events, $storefront_rules);
 *            $scope->anyOn($storefront_id);   // true: show the box; false: hide it
 *            "On" is the effective switch of Administration > Notifications (the same service the dispatcher reads)
 *            and, for a storefront id above 0, the storefront has not turned that event off for itself. The scope is
 *            the one OrderNotifier::optedOut() gates: every order.status_changed.* event and every declaration with
 *            opt_out (order.updated, order.shipment_updated). The order-placed receipt is not in it.
 *            The two reads are protected methods, so the unit test replaces them.
 * DEPENDS:   Notifications\EventsSchemaExtender (STATUS_PREFIX), Notifications\EventDeclaration, Storefronts\StorefrontRules,
 *            core EventDispatcherProvider::getNotificationSettings().
 */

namespace Tygh\Addons\Kwtsms\Profiles;

use Tygh\Addons\Kwtsms\Notifications\EventsSchemaExtender;
use Tygh\Addons\Kwtsms\Storefronts\StorefrontRules;

class OrderSmsScope
{
    private const TRANSPORT = 'kwtsms';
    private const CUSTOMER = 'C';

    /** @var array<string,\Tygh\Addons\Kwtsms\Notifications\EventDeclaration> */
    private $declared;

    /** @var StorefrontRules|null */
    private $rules;

    /**
     * @param array<string,\Tygh\Addons\Kwtsms\Notifications\EventDeclaration> $declared Validated declarations
     */
    public function __construct(array $declared, ?StorefrontRules $rules)
    {
        $this->declared = $declared;
        $this->rules = $rules;
    }

    public function anyOn(int $storefront_id): bool
    {
        foreach ($this->customerSwitches() as $event_id => $on) {
            if (!$on || !$this->inScope($event_id)) {
                continue;
            }
            if ($storefront_id > 0 && $this->rules !== null && $this->rules->isSwitchedOff($storefront_id, $event_id, self::CUSTOMER)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function inScope(string $event_id): bool
    {
        return strpos($event_id, EventsSchemaExtender::STATUS_PREFIX) === 0
            || (isset($this->declared[$event_id]) && $this->declared[$event_id]->optOut());
    }

    /**
     * Event id => the effective SMS switch of the customer receiver, for every event that has one.
     *
     * @return array<string,bool>
     */
    protected function customerSwitches(): array
    {
        $out = [];
        foreach ((array) \Tygh\Providers\EventDispatcherProvider::getNotificationSettings() as $event_id => $event) {
            $transports = is_array($event) ? ($event['receivers'][self::CUSTOMER] ?? null) : null;
            if (is_array($transports) && array_key_exists(self::TRANSPORT, $transports)) {
                $out[(string) $event_id] = (bool) $transports[self::TRANSPORT];
            }
        }

        return $out;
    }
}
