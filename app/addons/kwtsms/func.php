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
 * DEPENDS:   CS-Cart 4.18.1 to 4.21.2 core (Tygh\Settings, fn_url, fn_html_escape), Tygh::$app['kwtsms.sms'].
 */

use Tygh\Addons\Kwtsms\Sms\ApiErrors;
use Tygh\Addons\Kwtsms\Sms\SendResult;
use Tygh\Addons\Kwtsms\Sms\SmsService;
use Tygh\Addons\Kwtsms\Templates\TemplateRepository;
use Tygh\Languages\Languages;
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
