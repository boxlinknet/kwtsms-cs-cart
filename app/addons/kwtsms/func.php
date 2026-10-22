<?php

/*
 * FILE:      app/addons/kwtsms/func.php
 * CREATED:   2026-10-20 19:53 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Procedural functions of the kwtSMS add-on: the install and uninstall handlers named in
 *            addon.xml and the renderer of the visible "info" setting.
 * WHY:       addon.xml cannot express a random value (cron_key) or the HTML of the settings info item.
 *            Services and hooks arrive in later tasks; this file stays free of business logic.
 *            2026-10-20 20:46 +03: reworded when the table sweep was removed from fn_kwtsms_uninstall()
 *            (addon.xml names every table), task 596.
 * USAGE:     Loaded by CS-Cart: fn_kwtsms_install() and fn_kwtsms_uninstall() by the add-on installer,
 *            fn_kwtsms_settings_info() by Admin > Add-ons > kwtSMS (settings page).
 *            2026-10-21 00:20 +03: fn_kwtsms_send(), the public send API of the extension contract (PRD 5.2), Task
 *            1.6, task 596:  $r = fn_kwtsms_send(['+96550123401'], 'Your text', ['event_id' => 'my_addon.shipped']);
 *            2026-10-21 01:13 +03: fn_kwtsms_cron_run() and the helpers of the controller kwtsms_cron.run (cron key
 *            check, JSON reply, throttled log of a refused request), Task 1.7, task 596:
 *            php /var/www/cscart/admin.php --dispatch=kwtsms_cron.run
 *            2026-10-21 01:58 +03: the six order hook handlers fn_kwtsms_change_order_status(), ..._post(),
 *            fn_kwtsms_edit_place_order(), fn_kwtsms_place_order_manually_post(), fn_kwtsms_place_order_post(),
 *            fn_kwtsms_change_order_status_child_order() (PRD 4.3), delegating to Tygh::$app['kwtsms.order_hooks'],
 *            Task 1.9, task 596.
 * DEPENDS:   CS-Cart 4.18.1 to 4.21.2 core (Tygh\Settings, fn_url, fn_html_escape), Tygh::$app['kwtsms.sms'],
 *            Tygh::$app['kwtsms.cron'].
 */

use Tygh\Addons\Kwtsms\Cron\CronKey;
use Tygh\Addons\Kwtsms\Sms\ApiErrors;
use Tygh\Addons\Kwtsms\Sms\SendResult;
use Tygh\Addons\Kwtsms\Sms\SmsService;
use Tygh\Addons\Kwtsms\Templates\TemplateRepository;
use Tygh\Languages\Languages;
use Tygh\Registry; // 2026-10-22 21:27 +03: Task A7, task 596
use Tygh\Settings;
use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

/**
 * Install handler (addon.xml functions, for="install"). Runs after the tables, privileges and settings
 * exist.
 *
 * Generates the cron key: 32 random hex characters, new on every install, never a static default.
 * Seeds the default SMS templates of every installed language (2026-10-20 23:12 +03, Task 1.8, task 596).
 * Nothing else is seeded: notification switches are fail-closed and need no rows (PRD 4.1).
 *
 * @return void
 */
function fn_kwtsms_install(): void
{
    // 2026-10-20 19:53 +03: cron_key generated here (PRD 9), task 596.
    Settings::instance(['company_id' => 0, 'storefront_id' => 0])
        ->updateValue('cron_key', bin2hex(random_bytes(16)), 'kwtsms');

    // 2026-10-20 23:12 +03: the shipped default SMS texts are copied into the template tables for every
    // installed language (PRD 6.4), INSERT IGNORE, so a second run changes nothing. A failure here must not
    // undo the install: the admin can load the defaults from the Templates page. Task 1.8, task 596.
    // 2026-10-21 00:05 +03: moved into fn_kwtsms_seed_default_templates(), which also tells the admin.
    fn_kwtsms_seed_default_templates();
}

/**
 * Seed the shipped default SMS texts for every installed language. Never throws: a failure goes to the PHP error
 * log (class, message, file:line, no trace) and to the admin as an error notification (langvar
 * kwtsms.templates_seed_failed), and the install carries on, because the texts can be loaded later from the
 * Templates page.
 * 2026-10-21 00:05 +03: extracted from fn_kwtsms_install() and the notification added, Task 1.8 fix round 1
 * item 6, task 596.
 *
 * @param TemplateRepository|null $templates Repository to seed (a test passes one that fails); null = the real one
 *
 * @return bool True when seeded
 */
function fn_kwtsms_seed_default_templates(?TemplateRepository $templates = null): bool
{
    try {
        $templates = $templates ?? new TemplateRepository();
        $templates->seedDefaults(array_map('strval', array_keys(Languages::getAll())));

        return true;
    } catch (\Throwable $e) {
        error_log('kwtsms: seeding the default templates failed: ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        fn_set_notification('E', __('error'), __('kwtsms.templates_seed_failed'));

        return false;
    }
}

/**
 * Uninstall handler (addon.xml functions, for="uninstall"). Runs before the queries of addon.xml and
 * before the settings and language values are removed.
 *
 * Intentionally empty in 1.0.0: every table, privilege, grant, notification row, storage key and language
 * value is removed by the uninstall queries of addon.xml, by exact name, so the add-on can never drop
 * something it does not own (no sweep over the "kwtsms_" prefix). Cleanup that SQL cannot express (for
 * example persistent admin notices) is added here by the task that creates it.
 *
 * @return void
 */
function fn_kwtsms_uninstall(): void
{
    // 2026-10-20 20:28 +03: the table sweep was removed, addon.xml drops exactly the nine tables (review
    // finding: the sweep was broader than "our tables" and duplicated addon.xml), task 596.
}

/**
 * Renderer of the "info" setting: a button that opens the add-on's own Settings page. Every other
 * setting of the add-on is hidden and is edited there.
 *
 * @return string HTML, every dynamic part escaped
 */
function fn_kwtsms_settings_info(): string
{
    // 2026-10-20 19:53 +03: link to kwtsms.settings (PRD 9), task 596.
    $url = fn_url('kwtsms.settings');

    return '<a class="btn btn-primary" href="' . fn_html_escape($url) . '">'
        . fn_html_escape(__('kwtsms.open_settings'))
        . '</a>';
}

/*
 * Order hooks (PRD 4.3), registered in init.php. Each handler delegates to Tygh\Addons\Kwtsms\Orders\OrderHooks and
 * never throws: an SMS problem must not break a checkout, a payment callback or an admin save. Core line numbers
 * are CS-Cart 4.21.2.
 * 2026-10-21 01:58 +03: added, Task 1.9, task 596.
 */

/**
 * Hook change_order_status (fn.cart.php:2953), before core dispatches order.status_changed.<status>: flags a new
 * order so its status SMS is superseded by the order-placed SMS.
 *
 * @param string              $status_to          New status (B when the stock ran out)
 * @param string              $status_from        Old status
 * @param array<string,mixed> $order_info         fn_get_order_info() row
 * @param mixed               $force_notification Notify rules
 * @param array<string,mixed> $order_statuses     Unused
 * @param bool                $place_order        True inside fn_place_order()
 */
function fn_kwtsms_change_order_status(
    $status_to,
    $status_from,
    $order_info,
    $force_notification,
    $order_statuses,
    $place_order
) {
    try {
        Tygh::$app['kwtsms.order_hooks']->onChangeOrderStatus(
            (string) $status_to,
            (string) $status_from,
            is_array($order_info) ? $order_info : [],
            $force_notification,
            (bool) $place_order
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('change_order_status', $e);
    }
}

/**
 * Hook change_order_status_post (fn.cart.php:3038): dispatches kwtsms.order_placed for a flagged order with the same
 * notify rules, records why no status SMS was handed over (diagnostics), clears the flag.
 *
 * @param int                 $order_id
 * @param string              $status_to
 * @param string              $status_from
 * @param mixed               $force_notification
 * @param bool                $place_order
 * @param array<string,mixed> $order_info
 * @param mixed               $edp_data           Unused
 */
function fn_kwtsms_change_order_status_post(
    $order_id,
    $status_to,
    $status_from,
    $force_notification,
    $place_order,
    $order_info,
    $edp_data
) {
    try {
        Tygh::$app['kwtsms.order_hooks']->onChangeOrderStatusPost(
            (int) $order_id,
            (string) $status_to,
            (string) $status_from,
            $force_notification,
            (bool) $place_order,
            is_array($order_info) ? $order_info : []
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('change_order_status_post', $e);
    }
}

/**
 * Hook edit_place_order (fn.cart.php:1358): the admin is editing this order; it is never a new order.
 *
 * @param int $order_id
 */
function fn_kwtsms_edit_place_order($order_id)
{
    try {
        Tygh::$app['kwtsms.order_hooks']->onEditPlaceOrder((int) $order_id);
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('edit_place_order', $e);
    }
}

/**
 * Hook place_order_manually_post (backend order_management.php:779): the order-placed SMS of an order the admin
 * created, with the admin's Notify boxes (inside fn_place_order() AREA A forces every notify rule to false).
 *
 * @param array<string,mixed> $cart
 * @param array<string,mixed> $params
 * @param array<string,mixed> $customer_auth
 * @param string              $action
 * @param int                 $issuer_id
 * @param mixed               $force_notification The admin's notify rules
 * @param mixed               $order_info         Unused (undefined in core when no notification is required)
 * @param mixed               $edp_data           Unused
 * @param bool                $is_order_placed_notification_required Unused
 */
function fn_kwtsms_place_order_manually_post(
    $cart,
    $params,
    $customer_auth,
    $action,
    $issuer_id,
    $force_notification,
    $order_info = null,
    $edp_data = null,
    $is_order_placed_notification_required = true
) {
    try {
        Tygh::$app['kwtsms.order_hooks']->onPlaceOrderManuallyPost($force_notification);
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('place_order_manually_post', $e);
    }
}

/**
 * Hook place_order_post (fn.cart.php:1724): on the parent of a Multi-Vendor checkout, once every child exists, the
 * one checkout SMS.
 *
 * @param array<string,mixed> $cart
 * @param array<string,mixed> $auth
 * @param string              $action
 * @param int                 $issuer_id
 * @param int                 $parent_order_id 0 on the parent (or a plain order)
 * @param int                 $order_id
 * @param string              $order_status
 * @param array<string,mixed> $short_order_data
 * @param mixed               $notification_rules Unused (all false on a parent)
 */
function fn_kwtsms_place_order_post(
    $cart,
    $auth,
    $action,
    $issuer_id,
    $parent_order_id,
    $order_id,
    $order_status,
    $short_order_data,
    $notification_rules
) {
    try {
        Tygh::$app['kwtsms.order_hooks']->onPlaceOrderPost(
            (int) $parent_order_id,
            (int) $order_id,
            is_array($short_order_data) ? $short_order_data : []
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('place_order_post', $e);
    }
}

/**
 * Hook change_order_status_child_order (fn.cart.php:2806): a parent order moves this child in the current request.
 *
 * @param int    $order_id            Parent order id
 * @param string $status_to
 * @param string $status_from
 * @param mixed  $force_notification
 * @param bool   $place_order
 * @param int    $child_order_id
 * @param string $child_status_to
 * @param bool   $change_child_status
 * @param string $child_status_from
 */
function fn_kwtsms_change_order_status_child_order(
    $order_id,
    $status_to,
    $status_from,
    $force_notification,
    $place_order,
    $child_order_id,
    $child_status_to,
    $change_child_status,
    $child_status_from
) {
    try {
        Tygh::$app['kwtsms.order_hooks']->onChangeOrderStatusChildOrder(
            (int) $order_id,
            (int) $child_order_id,
            (bool) $change_child_status
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('change_order_status_child_order', $e);
    }
}

/**
 * 2026-10-22 18:02 +03: Task A5 (R18), task 596. Hook update_shipment_before_send_notification (fn.cart.php:9091):
 * the shipment was
 * created and core is about to dispatch order.shipment_updated with $force_notification. The hook create_shipment
 * (fn.cart.php:9013) runs before the shipment exists and carries no notification rules, so it cannot tell whether
 * the customer will be notified; this one can.
 *
 * @param array<string,mixed> $shipment_data
 * @param int                 $shipment_id
 * @param int                 $group_no
 * @param bool                $all_products
 * @param mixed               $force_notification
 * @param array<string,mixed> $order_info
 * @param array<string,mixed> $shipment
 */
function fn_kwtsms_update_shipment_before_send_notification(
    $shipment_data,
    $shipment_id,
    $group_no,
    $all_products,
    $force_notification,
    $order_info,
    $shipment = []
) {
    try {
        Tygh::$app['kwtsms.order_hooks']->onShipmentBeforeSend(
            is_array($order_info) ? $order_info : [],
            $force_notification
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('update_shipment_before_send_notification', $e);
    }
}

/**
 * 2026-10-22 19:09 +03: Task A6 (SafatMall F5 part, P2-7), task 596. Hook update_profile (fn.users.php, end of
 * fn_update_user): a
 * customer account was added or updated. A storefront or checkout registration dispatches the admin registration
 * alert (kwtsms.customer_registered); the welcome SMS itself is core's profile.created.c with the SMS transport.
 * Never throws.
 *
 * @param string              $action            add or update
 * @param array<string,mixed> $user_data
 * @param array<string,mixed> $current_user_data
 */
function fn_kwtsms_update_profile($action, $user_data, $current_user_data)
{
    try {
        Tygh::$app['kwtsms.profile_hooks']->onUpdateProfile(
            (string) $action,
            is_array($user_data) ? $user_data : [],
            is_array($current_user_data) ? $current_user_data : []
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('update_profile', $e);
    }
}

/**
 * 2026-10-22 21:27 +03: Task A7 (SafatMall F5 part, P2-10), task 596. Hook update_product_amount
 * (app/functions/fn.cart.php:1196 in 4.21.2, fn_update_product_amount(), after core mailed its own low-stock e-mail
 * and before the products row is written). The ten arguments are those of the fn_set_hook() call there. A product
 * that crosses the low-stock threshold downwards is handed to the collector; the SMS goes out once per receiver
 * after the order status change (OrderHooks) or at shutdown. Costs two integer comparisons for a product that does
 * not cross. Never throws.
 *
 * @param int    $new_amount      New product quantity
 * @param int    $product_id      Product ID
 * @param int    $cart_id         Product cart ID
 * @param string $tracking        Inventory tracking mode
 * @param bool   $notify          Whether stock notifications are sent
 * @param array  $order_info      Order information
 * @param int    $amount_delta    Quantity change
 * @param int    $current_amount  Quantity before the change
 * @param int    $original_amount Original quantity
 * @param string $sign            - or +
 */
function fn_kwtsms_update_product_amount(
    $new_amount,
    $product_id,
    $cart_id,
    $tracking,
    $notify,
    $order_info,
    $amount_delta,
    $current_amount,
    $original_amount,
    $sign
) {
    try {
        if ((string) $sign !== '-') {
            return;
        }
        $threshold = (int) Registry::get('settings.General.low_stock_threshold');
        // The cheap test first (no service is built for the common case): fn.cart.php:1146-1148 mirrored.
        if ((int) $current_amount <= $threshold || (int) $new_amount > $threshold || defined('ORDER_MANAGEMENT')) {
            return;
        }
        $collector = Tygh::$app['kwtsms.low_stock'];
        $collector->record(
            (int) $product_id,
            (int) fn_get_company_id('products', 'product_id', (int) $product_id),
            (int) $current_amount,
            (int) $new_amount,
            (string) $sign,
            $threshold
        );
    } catch (\Throwable $e) {
        fn_kwtsms_log_hook_error('update_product_amount', $e);
    }
}

/**
 * One line in the PHP error log for a failed order hook: class, message, file:line, no trace.
 * 2026-10-21 01:58 +03: added, Task 1.9, task 596.
 */
function fn_kwtsms_log_hook_error(string $hook, \Throwable $e): void
{
    error_log('kwtsms: hook ' . $hook . ' failed: ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
}

/**
 * Public send API for other add-ons (PRD 5.2 extension contract, stable from 1.0.0). The text goes through the
 * whole send pipeline with source "api": its own per-number rate bucket, at most 1000 recipients per call, and
 * the calling add-on recorded (event_id "api.<add-on>" unless the caller gives one, and the debug log).
 * 2026-10-21 00:20 +03: added, Task 1.6, task 596.
 *
 * @param array<int,mixed>    $phones  Numbers as typed (at most 1000)
 * @param string              $message The final text; it is cleaned once more and must have no {placeholder}
 * @param array<string,mixed> $context Optional: event_id, receiver, order_id, user_id, lang_code, country_iso,
 *                                     secret_values (masked in every log copy), interactive, audience
 *
 * @return array<string,mixed> SendResult::toArray(): status, accepted, outcomes, recipients, message_ids, msg_id,
 *                             points_charged, balance_after, test, error_code, error_message
 */
function fn_kwtsms_send(array $phones, string $message, array $context = []): array
{
    $caller = 'unknown';
    try {
        $frame = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        if (isset($frame['file']) && preg_match('#/addons/([a-z0-9_]{1,64})/#', (string) $frame['file'], $m) === 1) {
            $caller = $m[1];
        } elseif (isset($context['caller']) && is_string($context['caller'])) {
            $caller = preg_match('/^[a-z0-9_]{1,64}$/D', $context['caller']) === 1 ? $context['caller'] : 'unknown';
        }

        if (count($phones) > SmsService::MAX_API_RECIPIENTS) {
            Tygh::$app['kwtsms.logs.debug']->log('warning', 'sms.api_refused', 'too many recipients', [
                'caller' => $caller,
                'recipients' => count($phones),
                'max' => SmsService::MAX_API_RECIPIENTS,
            ]);

            return SendResult::refused('LOCAL_BAD_INPUT', ApiErrors::describe('LOCAL_BAD_INPUT'))->toArray();
        }

        $context['source'] = 'api';
        $context['caller'] = $caller;
        if (!isset($context['event_id']) || !is_string($context['event_id']) || $context['event_id'] === '') {
            $context['event_id'] = 'api.' . $caller;
        }
        unset($context['outbox_id']);

        return Tygh::$app['kwtsms.sms']->send($phones, $message, $context)->toArray();
    } catch (\Throwable $e) {
        // An SMS failure never breaks the caller (PRD conventions): no trace, class, message and file:line only.
        error_log('kwtsms: fn_kwtsms_send failed for ' . $caller . ': ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . basename($e->getFile()) . ':' . $e->getLine());

        return SendResult::refused('LOCAL_ERROR')->toArray();
    }
}

/**
 * Runs the cron jobs (drain_outbox, sync, prune_logs, health) under the core lock kwtsms.cron (600 s). Called by the
 * controller kwtsms_cron.run, in the CLI and over the URL mode. A failed job sets an error notification, so the CLI
 * exits non-zero; a run that finds the lock held returns locked = true and is not a failure.
 * 2026-10-21 01:13 +03: added, Task 1.7, task 596.
 *
 * @param string[] $jobs Names to run; empty = all of Tygh\Addons\Kwtsms\Cron\CronRunner::JOBS
 *
 * @return array{ok: bool, locked: bool, jobs: array<string,array<string,mixed>>, errors: string[], ms: int}
 */
function fn_kwtsms_cron_run(array $jobs = []): array
{
    try {
        $result = Tygh::$app['kwtsms.cron']->run($jobs);
    } catch (\Throwable $e) {
        error_log('kwtsms: cron run failed: ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        $result = [
            'ok' => false,
            'locked' => false,
            'jobs' => [],
            'errors' => [get_class($e) . ': ' . $e->getMessage()],
            'ms' => 0,
        ];
    }
    if (!$result['ok']) {
        fn_set_notification('E', __('error'), __('kwtsms.cron.failed', [
            '[details]' => fn_html_escape(implode('; ', $result['errors'])),
        ]));
    }

    return $result;
}

/**
 * Why a web request to kwtsms_cron.run must be refused, or null when it may run. EVERY web request needs the cron key,
 * a logged-in administrator included (the permission schema lets administrators reach this controller, and a request
 * from another site must not start a run): URL mode on, a stored key of 32+ characters, the same key in the header
 * X-Kwtsms-Cron-Key or the POST field kwtsms_cron_key, hash_equals(). The command line does not come here.
 * 2026-10-21 01:13 +03: added, Task 1.7, task 596.
 *
 * 2026-10-21 01:58 +03: POST only (PRD 10: no state change on GET). The key is checked first, so a caller without it
 * learns nothing about the method rule; a caller WITH the key who sends another method is told so (405), Task 1.7
 * fix round 1 item 6, task 596.
 *
 * @return string|null url_mode_off | no_stored_key | no_key | bad_key | method (a valid key, a method other than
 *                     POST: answer 405) | error
 */
function fn_kwtsms_cron_denial(): ?string
{
    try {
        $config = Tygh::$app['kwtsms.config'];
        $denied = CronKey::deny(
            $config->cronUrlEnabled(),
            $config->cronKey(),
            CronKey::supplied($_SERVER, $_POST)
        );
        if ($denied !== null) {
            return $denied;
        }

        return (isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '') === 'POST'
            ? null
            : 'method';
    } catch (\Throwable $e) {
        error_log('kwtsms: cron key check failed: ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . basename($e->getFile()) . ':' . $e->getLine());

        return 'error';
    }
}

/**
 * A refused cron request goes to the debug log as a warning, at most once a minute (anyone can knock, and a row per
 * knock would be a way to fill the table). URL mode off is not logged at all. A refusal for the method means the
 * caller HAS the key, so a misconfigured scheduler (GET instead of POST) shows up in the log.
 * 2026-10-21 01:13 +03: added, Task 1.7, task 596.
 */
function fn_kwtsms_cron_log_denial(string $reason): void
{
    if ($reason === 'url_mode_off') {
        return;
    }
    try {
        $store = Tygh::$app['kwtsms.storage'];
        $last = $store->getFresh('kwtsms_cron_denied');
        if ($last !== null && ctype_digit($last) && time() - (int) $last < 60) {
            return;
        }
        $store->set('kwtsms_cron_denied', (string) time());
        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ip = substr((string) preg_replace('/[^0-9a-fA-F:.]/', '', $remote), 0, 45);
        Tygh::$app['kwtsms.logs.debug']->log('warning', 'cron.denied', $reason, ['ip' => $ip]);
    } catch (\Throwable $e) {
        error_log('kwtsms: cron denial log failed: ' . get_class($e) . ': ' . $e->getMessage());
    }
}

/**
 * Answers a web request to kwtsms_cron.run and ends it: the status, and a JSON body that says ok or not and nothing
 * else. 2026-10-21 01:13 +03: added, Task 1.7, task 596. A 405 carries "Allow: POST" (2026-10-21 01:58 +03, fix round
 * 1 item 6).
 *
 * @return void Never returns
 */
function fn_kwtsms_cron_reply(int $status, bool $ok)
{
    if (!headers_sent()) {
        http_response_code($status);
        if ($status === 405) {
            header('Allow: POST');
        }
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo $ok ? '{"ok":true}' : '{"ok":false}';
    exit;
}

/**
 * Permission schema condition (schemas/permissions/vendor_ultimate.post.php): true only for a GET kwtSMS page opened
 * by a root administrator (company 0) who has the page privilege while a storefront is selected, and only when the
 * request does not itself select a storefront. The controller then refuses it with the notice "Select All stores to
 * manage kwtSMS" and a redirect to the same page with switch_company_id=0 (RequestGate::switchToAllStores()). Every
 * other request in a storefront context stays refused by core. Never throws.
 * 2026-10-21 17:08 +03: added, final review M2, task 596.
 */
function fn_kwtsms_scope_switch_allowed(): bool
{
    try {
        if (isset($_GET['switch_company_id'])) {
            return false;
        }
        $auth = isset(Tygh::$app['session']['auth']) && is_array(Tygh::$app['session']['auth'])
            ? Tygh::$app['session']['auth'] : [];

        return \Tygh\Addons\Kwtsms\Admin\RequestGate::switchToAllStores(
            isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '',
            \Tygh\Registry::get('runtime.mode'),
            $auth,
            \Tygh\Registry::get('runtime.company_id'),
            static function (string $privilege): bool {
                return fn_check_current_user_access($privilege) === true;
            }
        );
    } catch (\Throwable $e) {
        return false;
    }
}
