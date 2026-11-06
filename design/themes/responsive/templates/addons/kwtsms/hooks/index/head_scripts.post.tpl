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
{* 2026-11-05 20:58 +03: Task M2.4 (SafatMall B6, P3-54), task 596. The block is shown again for the order whose phone a checkout code verified in this session (that account is then created with the verified phone); every other order keeps it hidden while register_code is ON. *}
{* 2026-11-06 07:43 +03: Task M2.5 (SafatMall C2, P3-55), task 596. The JSON data block of the phone country selector (allowed countries, default calling code, core phone mode), printed only while the selector and the country check are ON; js/addons/kwtsms/phone_country.js (loaded by scripts.post.tpl) reads it. The JSON is hex-escaped for a script element. *}
{$kwtsms_cc_json = ""|fn_kwtsms_phone_country_data}
{if $kwtsms_cc_json}
<script type="application/json" id="kwtsms-cc-data" data-label="{__("kwtsms.phone.country_select_label")|fn_html_escape}">{$kwtsms_cc_json nofilter}</script>
{/if}
{if $smarty.request.order_id|default:0|fn_kwtsms_hide_create_account}
<style>.ty-checkout-complete__create-account { display: none; }</style>
{/if}
