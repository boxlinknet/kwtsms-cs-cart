{*
 * FILE:      design/themes/responsive/templates/addons/kwtsms/hooks/common/image_verification.post.tpl
 * CREATED:   2026-10-24 21:14 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The Turnstile widget inside core's common/image_verification.tpl (hook common:image_verification), shown
 *            only while Turnstile is the active antibot driver and the scenario in $option needs a check.
 * WHY:       Task M1.7b (PRD 6.15, P3-25). Every core form (login, registration, checkout, order tracking) and our
 *            SMS code field include common/image_verification.tpl; this is how Turnstile appears there when the
 *            reCAPTCHA add-on does not replace the file (it is off). With the add-on active,
 *            components/turnstile_image_verification.tpl does the same job.
 * USAGE:     Loaded by core as a hook template while the add-on is active; $option is the scenario.
 * DEPENDS:   func.php fn_kwtsms_turnstile_widget, components/turnstile_widget.tpl.
 *}
{$kwtsms_ts = $option|fn_kwtsms_turnstile_widget}
{if $kwtsms_ts}
    {include file="addons/kwtsms/components/turnstile_widget.tpl" kwtsms_ts=$kwtsms_ts}
{/if}
