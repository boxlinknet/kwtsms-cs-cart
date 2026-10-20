<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Orders/RuntimeFlags.php
 * CREATED:   2026-10-21 01:52 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Request-scoped order flags shared by the order hooks and the SMS transport (the PRD's
 *            runtime.kwtsms.* values): new_order.<id>, edit.<id>, manual.<id>, checkout.<parent id>, cascade.<id>.
 * WHY:       PRD 4.2 rule 2: the hook change_order_status flags a new order before core dispatches its status event,
 *            the transport reads the flag (status SMS superseded), and change_order_status_post dispatches the
 *            order-placed SMS and clears it. The flags must never outlive the request; one shared container
 *            service per request gives exactly that, with no static state and no Registry key to collide with.
 * USAGE:     $flags = Tygh::$app['kwtsms.order_flags'];
 *            $flags->set('new_order', 12, ['from' => 'N', 'to' => 'O']);  $flags->get('new_order', 12);
 *            $flags->has('edit', 12);  $flags->clear('new_order', 12);  $flags->all('manual');  // id => value
 * DEPENDS:   Nothing.
 */

namespace Tygh\Addons\Kwtsms\Orders;

class RuntimeFlags
{
    /** @var array<string,array<int,mixed>> */
    private $flags = [];

    /**
     * @param mixed $value Anything but null
     */
    public function set(string $name, int $id, $value = true): void
    {
        $this->flags[$name][$id] = $value === null ? true : $value;
    }

    /**
     * @return mixed|null null when the flag is not set
     */
    public function get(string $name, int $id)
    {
        return $this->flags[$name][$id] ?? null;
    }

    public function has(string $name, int $id): bool
    {
        return isset($this->flags[$name][$id]);
    }

    public function clear(string $name, int $id): void
    {
        unset($this->flags[$name][$id]);
    }

    /**
     * @return array<int,mixed> id => value, in the order they were set
     */
    public function all(string $name): array
    {
        return $this->flags[$name] ?? [];
    }
}
