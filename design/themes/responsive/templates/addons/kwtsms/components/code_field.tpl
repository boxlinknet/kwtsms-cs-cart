{*
 * FILE:      design/themes/responsive/templates/addons/kwtsms/components/code_field.tpl
 * CREATED:   2026-10-25 02:11 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The storefront SMS code field: a "Send code" button next to the code input (6 digits, Arabic and Persian
 *            digits accepted, normalized on the server), the visible resend countdown, a polite live status line, the
 *            core CAPTCHA widget of the scenario kwtsms_sms_code (any active provider, "always" mode) and the slot where
 *            js/addons/kwtsms/code.js renders Cloudflare Turnstile when the server answers "captcha" ("only when
 *            suspicious" mode). All texts come from the language files and travel to the script as data-t-* attributes.
 * WHY:       Task M1.8b (SafatMall B1, P3-25, P3-39). One component for the registration page (Task M1.10a) and the
 *            phone change (Task M1.10b); the script posts to kwtsms_code.send and shows the answer.
 * USAGE:     {include file="addons/kwtsms/components/code_field.tpl" purpose="register" phone_input_name="user_data[phone]"}
 *            purpose: register | phone_change.  phone_input_name: the name attribute of the phone input of the same form.
 *            The code box is name="kwtsms_code"; events on the root: kwtsms:sent, kwtsms:bypass.
 * DEPENDS:   js/addons/kwtsms/code.js (loaded by hooks/index/scripts.post.tpl), func.php fn_kwtsms_turnstile_widget,
 *            common/image_verification.tpl, kwtsms_code.send (controllers/frontend/kwtsms_code.php).
 *}
{$kwtsms_cf_id = "kwtsms_code_`$purpose`"}
{$kwtsms_cf_ts = "kwtsms_sms_code"|fn_kwtsms_turnstile_widget}
{* 2026-10-28 18:53 +03: Task M1.10a fix round 1 (review I4), task 596: maxlength is 16 (was 6) so a pasted "123 456" or a spaced Arabic-Indic code keeps its last digit; code.js cleans the value to 6 digits on input. *}
{* 2026-10-25 03:10 +03: Task M1.8b fix round 1 (review minor 1), task 596: the label has a class so the bypass can hide it.
   2026-10-25 02:16 +03: the button is the theme primary button (the secondary one fails AA contrast, 2.96:1), Task M1.8b, task 596.
   2026-10-25 02:11 +03: styles of the field (Task M1.8b, task 596). Layout only; the theme keeps colours of buttons and inputs. *}
{if !$kwtsms_code_css_done}
{assign var="kwtsms_code_css_done" value=true scope="root"}
{literal}
<style>
.kwtsms-code { margin-bottom: 16px; }
.kwtsms-code__row { display: flex; flex-wrap: wrap; align-items: stretch; gap: 8px; max-width: 28em; }
.kwtsms-code__row[hidden], .kwtsms-code__slot[hidden], .kwtsms-code__label[hidden] { display: none; }
.kwtsms-code__input { flex: 1 1 9em; min-width: 0; box-sizing: border-box; min-height: 44px; font-size: 1.25em; letter-spacing: .3em; text-indent: .3em; text-align: center; font-variant-numeric: tabular-nums; }
.kwtsms-code__send { flex: 0 1 auto; min-height: 44px; white-space: nowrap; }
.kwtsms-code__send[disabled] { cursor: not-allowed; }
.kwtsms-code__slot { margin-bottom: 8px; }
.kwtsms-code__count { min-height: 1.4em; margin-top: 6px; font-size: 13px; color: #4f555c; font-variant-numeric: tabular-nums; }
.kwtsms-code__status { min-height: 1.4em; margin-top: 2px; font-size: 14px; line-height: 1.4; color: #4f555c; }
.kwtsms-code[data-state="sent"] .kwtsms-code__status { color: #1b6a3b; }
.kwtsms-code[data-state="error"] .kwtsms-code__status, .kwtsms-code[data-state="wait"] .kwtsms-code__status { color: #b3261e; }
.kwtsms-code[data-state="captcha"] .kwtsms-code__status { color: #8a4b00; }
.kwtsms-code__input:focus-visible, .kwtsms-code__send:focus-visible { outline: 2px solid #1a56c4; outline-offset: 2px; }
</style>
{/literal}
{/if}
{* 2026-10-25 03:06 +03: Task M1.8b fix round 1 (review Important 1, stored XSS), task 596. Every value printed in an attribute or as text goes through |fn_html_escape (|escape:"html" is dropped by core's Smarty in some expressions; the other add-on templates use fn_html_escape for __() values). Proved by tests/Store/CodeFieldEscapingStoreTest. *}
<div class="kwtsms-code ty-control-group" id="{$kwtsms_cf_id|fn_html_escape}" data-ca-kwtsms-code="{$purpose|fn_html_escape}" data-purpose="{$purpose|fn_html_escape}" data-phone-name="{$phone_input_name|fn_html_escape}" data-state="idle"
     data-url="{"kwtsms_code.send"|fn_url|fn_html_escape}"
     data-sitekey="{$kwtsms_cf_ts.site_key|default:""|fn_html_escape}" data-action="{$kwtsms_cf_ts.action|default:"kwtsms_sms_code"|fn_html_escape}" data-language="{$kwtsms_cf_ts.language|default:$smarty.const.CART_LANGUAGE|fn_html_escape}"
     data-t-send="{__("kwtsms.code.send")|fn_html_escape}" data-t-resend="{__("kwtsms.code.resend")|fn_html_escape}" data-t-resend-in="{__("kwtsms.code.resend_in")|fn_html_escape}"
     data-t-sending="{__("kwtsms.code.sending")|fn_html_escape}" data-t-phone-required="{__("kwtsms.code.phone_required")|fn_html_escape}"
     data-t-offline="{__("kwtsms.code.offline")|fn_html_escape}" data-t-server-error="{__("kwtsms.code.server_error")|fn_html_escape}"
     data-t-bypass="{__("kwtsms.code.bypass")|fn_html_escape}">
    <label class="ty-control-group__title kwtsms-code__label" for="{$kwtsms_cf_id|fn_html_escape}_input">{__("kwtsms.code.field_label")|fn_html_escape}</label>
    {include file="common/image_verification.tpl" option="kwtsms_sms_code"}
    <div class="kwtsms-code__slot" hidden></div>
    <div class="kwtsms-code__row">
        <input type="text" id="{$kwtsms_cf_id|fn_html_escape}_input" name="kwtsms_code" class="ty-input-text kwtsms-code__input" inputmode="numeric" autocomplete="one-time-code" dir="ltr" maxlength="16" aria-describedby="{$kwtsms_cf_id|fn_html_escape}_status" />
        <button type="button" class="ty-btn ty-btn__primary kwtsms-code__send">{__("kwtsms.code.send")|fn_html_escape}</button>
    </div>
    <div class="kwtsms-code__count" aria-hidden="true"></div>
    <div class="kwtsms-code__status" id="{$kwtsms_cf_id|fn_html_escape}_status" role="status" aria-live="polite"></div>
</div>
