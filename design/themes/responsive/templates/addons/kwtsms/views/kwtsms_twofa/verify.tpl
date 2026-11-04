{*
 * FILE:      design/themes/responsive/templates/addons/kwtsms/views/kwtsms_twofa/verify.tpl
 * CREATED:   2026-11-04 22:48 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The storefront code page of the second step: "Enter the code we sent to +965****3402.", the code field,
 *            the Sign in button, "Send a new code" and "Cancel and sign in again".
 * WHY:       Task M2.2a (SafatMall B3). Shown after a correct password when the account needs an SMS code. The page
 *            names only the masked 2FA phone of the account that gave the password (no enumeration concern).
 * USAGE:     Rendered by controllers/frontend/kwtsms_twofa.php (GET verify). Variable kwtsms_twofa: masked, sent,
 *            seconds_left, requirement. Each form carries the session security hash (core adds it to POST forms); the
 *            controller checks it with hash_equals().
 * DEPENDS:   Language variables kwtsms.twofa.*.
 *}
<div class="ty-login kwtsms-twofa" style="max-width: 28em;">
<p class="kwtsms-twofa__intro">{__("kwtsms.twofa.intro", ["[phone]" => $kwtsms_twofa.masked_ltr|fn_html_escape])}</p>
    {if !$kwtsms_twofa.sent}<p class="kwtsms-twofa__note" id="kwtsms_twofa_not_sent" role="status">{__("kwtsms.twofa.not_sent")|fn_html_escape}</p>{/if}
    <form name="kwtsms_twofa_form" id="kwtsms_twofa_form" action="{""|fn_url}" method="post" class="kwtsms-twofa__form">
        <div class="ty-control-group">
            <label for="kwtsms_twofa_code" class="ty-login__filed-label ty-control-group__label cm-required">{__("kwtsms.twofa.code_label")|fn_html_escape}</label>
            <input type="text" id="kwtsms_twofa_code" name="kwtsms_code" size="12" maxlength="16" value="" class="ty-login__input cm-focus" autocomplete="one-time-code" inputmode="numeric" dir="ltr" />
        </div>
        <div class="buttons-container clearfix">
            {include file="buttons/button.tpl" but_text=__("kwtsms.twofa.submit") but_name="dispatch[kwtsms_twofa.verify]" but_role="submit" but_meta="ty-btn__primary ty-btn__login"}
        </div>
    </form>
    <form name="kwtsms_twofa_send_form" id="kwtsms_twofa_send_form" action="{""|fn_url}" method="post" class="kwtsms-twofa__send">
        <button type="submit" name="dispatch[kwtsms_twofa.send]" class="ty-btn ty-btn__text kwtsms-twofa__link">{__("kwtsms.twofa.resend")|fn_html_escape}</button>
    </form>
    <form name="kwtsms_twofa_cancel_form" id="kwtsms_twofa_cancel_form" action="{""|fn_url}" method="post" class="kwtsms-twofa__cancel">
        <button type="submit" name="dispatch[kwtsms_twofa.cancel]" class="ty-btn ty-btn__text kwtsms-twofa__link">{__("kwtsms.twofa.cancel")|fn_html_escape}</button>
    </form>
</div>
{capture name="mainbox_title"}{__("kwtsms.twofa.title")}{/capture}
