{*
 * FILE:      design/themes/responsive/templates/addons/kwtsms/hooks/index/head_scripts.post.tpl
 * CREATED:   2026-10-28 18:57 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      While register_code is ON, hides the "create account" block of the order confirmation page.
 * WHY:       Task M1.10a fix round 1 (review M3, ruling P3-R12). That form (checkout.create_profile) has no hook inside
 *            it and no code field, so with B1 ON the server refuses every submit (P3-28); the customer can register on
 *            the registration page. The hook index:scripts cannot carry this (its content is collected by {scripts}
 *            and only script tags survive), so it uses index:head_scripts.
 * USAGE:     Loaded by core as a hook template (index:head_scripts) while the add-on is active.
 * DEPENDS:   func.php fn_kwtsms_register_code_on.
 *}
{if ""|fn_kwtsms_register_code_on}
<style>.ty-checkout-complete__create-account { display: none; }</style>
{/if}
