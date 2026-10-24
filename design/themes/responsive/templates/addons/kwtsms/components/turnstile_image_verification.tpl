{*
 * FILE:      design/themes/responsive/templates/addons/kwtsms/components/turnstile_image_verification.tpl
 * CREATED:   2026-10-24 21:14 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Stands in for common/image_verification.tpl while the reCAPTCHA add-on is active (without keys) and
 *            Turnstile is the active antibot driver: Turnstile's widget when the scenario in $option needs a check;
 *            for any other driver the reCAPTCHA add-on's own template, unchanged.
 * WHY:       Task M1.7b (ruling P3-R7). The reCAPTCHA add-on replaces the whole core template and shows a native image
 *            CAPTCHA for every driver that is not its own, and core takes its override before ours. func.php
 *            fn_kwtsms_init_templater_post() points the override map here, only while Turnstile is the active driver.
 * USAGE:     Included by core as common/image_verification.tpl with $option (scenario) and $full_width.
 * DEPENDS:   func.php fn_kwtsms_turnstile_widget, components/turnstile_widget.tpl, the reCAPTCHA add-on's
 *            addons/recaptcha/overrides/common/image_verification.tpl.
 *}
{if $app.antibot->getDriver()|get_class == "Tygh\Addons\Kwtsms\Antibot\TurnstileDriver"}
    {if $option|fn_needs_image_verification}
        {$kwtsms_ts = $option|fn_kwtsms_turnstile_widget}
        {if $kwtsms_ts}
            {include file="addons/kwtsms/components/turnstile_widget.tpl" kwtsms_ts=$kwtsms_ts}
        {/if}
    {/if}
{else}
    {include file="addons/recaptcha/overrides/common/image_verification.tpl"}
{/if}
