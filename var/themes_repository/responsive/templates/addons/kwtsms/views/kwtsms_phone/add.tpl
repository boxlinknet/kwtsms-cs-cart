{*
 * FILE:      design/themes/responsive/templates/addons/kwtsms/views/kwtsms_phone/add.tpl
 * CREATED:   2026-11-16 23:59 +03
 * TASK:      596 (M3.21, G9, P3-79)
 * AUTHOR:    kwtSMS
 * WHAT:      The page "Add your mobile number": a phone field, the SMS code field (components/code_field.tpl, purpose
 *            phone_change: "Send code", the resend countdown, the CAPTCHA slot), a "Save number" button that posts the
 *            phone and the code to kwtsms_phone.add, and a "Later" button that posts to kwtsms_phone.later.
 * WHY:       Task M3.21 (SafatMall G9). A customer without a phone gets no order SMS; the page is shown once per session
 *            after sign-in (func.php fn_kwtsms_missing_phone_redirect) and is the same flow as a phone change.
 * USAGE:     Rendered by controllers/frontend/kwtsms_phone.php (mode add, GET and a refused POST). Variables:
 *            kwtsms_phone (the number as typed), kwtsms_return (the safe return address or ''). Both forms carry the
 *            session security hash automatically (core adds it to every POST form); the controller checks it with
 *            hash_equals(). Right to left: the number and the code are dir="ltr" inputs, the rest follows the page.
 * DEPENDS:   components/code_field.tpl, js/addons/kwtsms/code.js (loaded while a code feature is ON), language
 *            variables kwtsms.phone_prompt.* and kwtsms.login.phone_label.
 *}
<div class="ty-login kwtsms-login kwtsms-phone-prompt" style="max-width: 28em;">
    <p class="kwtsms-login__intro">{__("kwtsms.phone_prompt.intro")|fn_html_escape}</p>
    <form name="kwtsms_phone_form" id="kwtsms_phone_form" action="{""|fn_url}" method="post" class="kwtsms-login__form">
        <input type="hidden" name="return_url" value="{$kwtsms_return|fn_html_escape}" />
        <div class="ty-control-group">
            <label for="kwtsms_phone_input" class="ty-login__filed-label ty-control-group__label cm-required">{__("kwtsms.login.phone_label")|fn_html_escape}</label>
            <input type="tel" id="kwtsms_phone_input" name="phone" size="30" maxlength="32" value="{$kwtsms_phone|fn_html_escape}" class="ty-login__input cm-focus" autocomplete="tel" inputmode="tel" dir="ltr" />
        </div>
        {include file="addons/kwtsms/components/code_field.tpl" purpose="phone_change" phone_input_name="phone"}
        <div class="buttons-container clearfix">
            {include file="buttons/button.tpl" but_text=__("kwtsms.phone_prompt.submit") but_name="dispatch[kwtsms_phone.add]" but_role="submit" but_meta="ty-btn__primary ty-btn__login"}
        </div>
    </form>
    <form name="kwtsms_phone_later_form" id="kwtsms_phone_later_form" action="{""|fn_url}" method="post" class="kwtsms-login__other">
        <input type="hidden" name="return_url" value="{$kwtsms_return|fn_html_escape}" />
        <div class="buttons-container clearfix">
            {* 2026-11-17 10:59 +03: tester fix, task 596 (M3.21 e2e AA contrast, 2.96:1 for the theme's secondary button): own colours, 7:1 on white. The submit branch of buttons/button.tpl ignores but_extra, so a plain button. *}
            <button class="ty-btn ty-btn__secondary" type="submit" name="dispatch[kwtsms_phone.later]" style="background-color: #595959; border-color: #595959; color: #ffffff;">{__("kwtsms.phone_prompt.later")|fn_html_escape}</button>
        </div>
    </form>
</div>
{capture name="mainbox_title"}{__("kwtsms.phone_prompt.title")}{/capture}
