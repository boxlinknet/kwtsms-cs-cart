{*
 * FILE:      design/themes/responsive/templates/addons/kwtsms/components/turnstile_widget.tpl
 * CREATED:   2026-10-24 21:14 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The Cloudflare Turnstile widget container of one storefront form: class cf-turnstile with data-sitekey,
 *            data-action and data-language (ar or en). js/addons/kwtsms/turnstile.js renders it explicitly, so a form
 *            loaded by AJAX works too; Cloudflare adds the hidden field cf-turnstile-response inside the form.
 * WHY:       Task M1.7b (PRD 6.15, P3-R8): data-action comes from the server-side mapping of the scenario (the same
 *            vocabulary TurnstileDriver checks). Only the site key reaches the page, never the secret key.
 * USAGE:     {include file="addons/kwtsms/components/turnstile_widget.tpl" kwtsms_ts=$kwtsms_ts}
 *            where $kwtsms_ts = $option|fn_kwtsms_turnstile_widget (site_key, action, language).
 * DEPENDS:   func.php fn_kwtsms_turnstile_widget, hooks/index/scripts.post.tpl (api.js and turnstile.js).
 *}
<div class="ty-control-group kwtsms-turnstile-group">
    <div class="cf-turnstile kwtsms-turnstile" data-sitekey="{$kwtsms_ts.site_key}" data-action="{$kwtsms_ts.action}" data-language="{$kwtsms_ts.language}" data-theme="auto"></div>
</div>
