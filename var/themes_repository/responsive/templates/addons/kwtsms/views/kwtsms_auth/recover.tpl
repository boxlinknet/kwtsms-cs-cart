{*
 * FILE:      design/themes/responsive/templates/addons/kwtsms/views/kwtsms_auth/recover.tpl
 * CREATED:   2026-11-05 16:38 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The page "Recover your account with your mobile number": a phone field, the SMS code field
 *            (components/code_field.tpl, purpose recover: "Send code", the neutral answer, the resend countdown) and a
 *            "Verify and continue" button that posts the phone and the code to kwtsms_auth.recover; a link to the
 *            e-mail recovery.
 * WHY:       Task M2.3 (SafatMall B4). The page says nothing about accounts: whatever number is typed, the request gets
 *            the same neutral answer (the server decides who can receive a code after the response).
 * USAGE:     Rendered by controllers/frontend/kwtsms_auth.php (mode recover, GET and a refused POST). Variable:
 *            kwtsms_phone (the number as posted). The form carries the session security hash automatically (core adds
 *            it to every POST form); the controller checks it with hash_equals().
 * DEPENDS:   components/code_field.tpl, js/addons/kwtsms/code.js (loaded while a code feature is ON), language
 *            variables kwtsms.recover.* and kwtsms.login.phone_label.
 *}
<div class="ty-login kwtsms-login kwtsms-recover" style="max-width: 28em;">
    <p class="kwtsms-login__intro">{__("kwtsms.recover.intro")|fn_html_escape}</p>
    <form name="kwtsms_recover_form" id="kwtsms_recover_form" action="{""|fn_url}" method="post" class="kwtsms-login__form">
        <div class="ty-control-group">
            <label for="kwtsms_recover_phone" class="ty-login__filed-label ty-control-group__label cm-required">{__("kwtsms.login.phone_label")|fn_html_escape}</label>
            <input type="tel" id="kwtsms_recover_phone" name="phone" size="30" maxlength="32" value="{$kwtsms_phone|fn_html_escape}" class="ty-login__input cm-focus" autocomplete="tel" inputmode="tel" dir="ltr" />
        </div>
        {include file="addons/kwtsms/components/code_field.tpl" purpose="recover" phone_input_name="phone"}
        <div class="buttons-container clearfix">
            {include file="buttons/button.tpl" but_text=__("kwtsms.recover.submit") but_name="dispatch[kwtsms_auth.recover]" but_role="submit" but_meta="ty-btn__primary ty-btn__login"}
        </div>
    </form>
    <div class="ty-login-reglink ty-center kwtsms-login__other">
        <a class="ty-login-reglink__a" style="color: #1a56c4; text-decoration: underline;" href="{"auth.recover_password"|fn_url}" rel="nofollow">{__("kwtsms.recover.email_link")|fn_html_escape}</a>
    </div>
</div>
{capture name="mainbox_title"}{__("kwtsms.recover.title")}{/capture}
