{*
 * FILE:      design/themes/responsive/templates/addons/kwtsms/hooks/index/scripts.post.tpl
 * CREATED:   2026-10-24 21:14 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Loads Cloudflare's Turnstile script (explicit render) and the add-on's js/addons/kwtsms/turnstile.js,
 *            only while Turnstile is the active antibot driver; and js/addons/kwtsms/code.js (the SMS code field)
 *            while a code feature is switched on.
 * WHY:       Task M1.7b (PRD 6.15). No visitor contacts Cloudflare unless the store uses Turnstile. Explicit render
 *            lets turnstile.js render widgets of forms that arrive by AJAX.
 * USAGE:     Loaded by core as a hook template (index:scripts) while the add-on is active.
 * DEPENDS:   Tygh::$app['antibot'], js/addons/kwtsms/turnstile.js.
 *}
{if $app.antibot->getDriver()|get_class == "Tygh\Addons\Kwtsms\Antibot\TurnstileDriver"}
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>
    {script src="js/addons/kwtsms/turnstile.js"}
{/if}
{* 2026-10-25 02:12 +03: Task M1.8b, task 596. The SMS code field script, only for stores that switched a code feature on. *}
{if ""|fn_kwtsms_code_features_on}
    {script src="js/addons/kwtsms/code.js"}
{/if}
