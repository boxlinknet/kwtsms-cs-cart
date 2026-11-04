{*
 * FILE:      design/themes/responsive/templates/addons/kwtsms/views/kwtsms_auth/login.tpl
 * CREATED:   2026-11-04 19:13 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The page "Sign in with your mobile number": a phone field, the SMS code field (components/code_field.tpl,
 *            purpose login: "Send code", the neutral answer, the resend countdown) and a Sign in button that posts the
 *            phone and the code to kwtsms_auth.login; a link back to the e-mail and password sign-in.
 * WHY:       Task M2.1 (SafatMall B2). The page says nothing about accounts: whatever number is typed, the request gets
 *            the same neutral answer (the server decides who can receive a code after the response).
 * USAGE:     Rendered by controllers/frontend/kwtsms_auth.php (GET and a refused POST). Variables: kwtsms_return_url
 *            (a local address or empty), kwtsms_phone (the number as posted). The form carries the session security hash
 *            automatically (core adds it to every POST form); the controller checks it with hash_equals().
 * DEPENDS:   components/code_field.tpl, js/addons/kwtsms/code.js (loaded while a code feature is ON), language variables
 *            kwtsms.login.*.
 *}
{* 2026-11-04 19:50 +03: Task M2.1 (e2e screenshot review), task 596: the block is as wide as the code field (28em), not the page. *}
<div class="ty-login kwtsms-login" style="max-width: 28em;">
    <p class="kwtsms-login__intro">{__("kwtsms.login.intro")|fn_html_escape}</p>
    <form name="kwtsms_login_form" id="kwtsms_login_form" action="{""|fn_url}" method="post" class="kwtsms-login__form">
        {if $kwtsms_return_url}<input type="hidden" name="return_url" value="{$kwtsms_return_url|fn_html_escape}" />{/if}
        <div class="ty-control-group">
            <label for="kwtsms_login_phone" class="ty-login__filed-label ty-control-group__label cm-required">{__("kwtsms.login.phone_label")|fn_html_escape}</label>
            <input type="tel" id="kwtsms_login_phone" name="phone" size="30" maxlength="32" value="{$kwtsms_phone|fn_html_escape}" class="ty-login__input cm-focus" autocomplete="tel" inputmode="tel" dir="ltr" />
        </div>
        {include file="addons/kwtsms/components/code_field.tpl" purpose="login" phone_input_name="phone"}
        <div class="buttons-container clearfix">
            {include file="buttons/button.tpl" but_text=__("kwtsms.login.submit") but_name="dispatch[kwtsms_auth.login]" but_role="submit" but_meta="ty-btn__primary ty-btn__login"}
        </div>
    </form>
    <div class="ty-login-reglink ty-center kwtsms-login__other">
        {* 2026-11-04 19:43 +03: Task M2.1 (e2e AA contrast, 3.29:1 for the theme's link colour), task 596: own colour (6.4:1 on white) and an underline. *}
        <a class="ty-login-reglink__a" style="color: #1a56c4; text-decoration: underline;" href="{"auth.login_form"|fn_url}" rel="nofollow">{__("kwtsms.login.password_link")|fn_html_escape}</a>
    </div>
</div>
{capture name="mainbox_title"}{__("kwtsms.login.title")}{/capture}
