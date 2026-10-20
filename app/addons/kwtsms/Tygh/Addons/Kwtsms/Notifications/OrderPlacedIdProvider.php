<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Notifications/OrderPlacedIdProvider.php
 * CREATED:   2026-10-21 01:55 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Unique event id of a kwtsms.order_placed dispatch: kwtsms.order_placed.p<order or parent id> for a plain
 *            order or a whole checkout, kwtsms.order_placed.o<child id> for one suborder.
 * WHY:       PRD 4.2 rule 4. The core dispatcher skips a second dispatch with the same (id, transport, receiver) in a
 *            request. Core's OrderProvider ids (order.<id><status>) carry no event id, so our event uses its own ids;
 *            a checkout and its suborders need different ids, or the second suborder of a per-suborder checkout
 *            would be dropped in the request that places them. (.o<child> is also the id the vendor receiver will
 *            use in Phase 2; the dispatcher keys by receiver too, so they never collide.)
 * USAGE:     new OrderPlacedIdProvider(12, 'p');   // getId() = kwtsms.order_placed.p12
 * DEPENDS:   Tygh\Notifications\EventIdProviders\IProvider (CS-Cart core).
 */

namespace Tygh\Addons\Kwtsms\Notifications;

use Tygh\Notifications\EventIdProviders\IProvider;

class OrderPlacedIdProvider implements IProvider
{
    public const KIND_PLACED = 'p';
    public const KIND_SUBORDER = 'o';

    /** @var string */
    private $id;

    /**
     * @param int    $id   Order id, parent order id (kind p) or child order id (kind o)
     * @param string $kind p or o (anything else is read as p)
     */
    public function __construct(int $id, string $kind)
    {
        $kind = $kind === self::KIND_SUBORDER ? self::KIND_SUBORDER : self::KIND_PLACED;
        $this->id = 'kwtsms.order_placed.' . $kind . $id;
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }
}
