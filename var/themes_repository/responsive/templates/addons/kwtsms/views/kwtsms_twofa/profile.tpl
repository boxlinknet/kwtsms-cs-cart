{*
 * FILE:      design/themes/responsive/templates/addons/kwtsms/views/kwtsms_twofa/profile.tpl
 * CREATED:   2026-11-04 22:48 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The storefront page "SMS code at sign-in": the state (on or off), a form that sends a code to the account
 *            phone (turning on) or to the current 2FA phone (turning off), and a form that takes the code.
 * WHY:       Task M2.2a (SafatMall B3, P3-49). Turning the second step on or off needs a code: the proof that the person
 *            holds the phone. The number is never typed here.
 * USAGE:     Rendered by controllers/frontend/kwtsms_twofa.php (GET profile). Variable kwtsms_twofa: enabled, masked,
 *            usable_phone, can_disable.
 * DEPENDS:   Language variables kwtsms.twofa.*.
 *}
{$kwtsms_action = "enable"}{if $kwtsms_twofa.enabled}{$kwtsms_action = "disable"}{/if}
<div class="ty-login kwtsms-twofa-profile" style="max-width: 28em;">
    <p id="kwtsms_twofa_state" role="status">{if $kwtsms_twofa.enabled}{__("kwtsms.twofa.state_on")|fn_html_escape} <bdi dir="ltr" class="kwtsms-twofa__number">{$kwtsms_twofa.masked}</bdi>{else}{__("kwtsms.twofa.state_off")|fn_html_escape}{/if}</p>
    {if $kwtsms_action == "enable" && !$kwtsms_twofa.usable_phone}
        <p id="kwtsms_twofa_no_phone">{__("kwtsms.twofa.no_phone")|fn_html_escape}</p>
    {elseif $kwtsms_action == "disable" && !$kwtsms_twofa.can_disable}
        <p id="kwtsms_twofa_required_note">{__("kwtsms.twofa.required")|fn_html_escape}</p>
    {else}
        <p>{if $kwtsms_action == "enable"}{* 2026-11-07 02:41 +03: Task M2.7 (ruling 22:04), task 596. The masked number (here and in the state line) is a bdi dir=ltr element, not a placeholder with isolate characters. *}{__("kwtsms.twofa.profile_enable_hint")|fn_html_escape} <bdi dir="ltr" class="kwtsms-twofa__number">{$kwtsms_twofa.masked}</bdi>{else}{__("kwtsms.twofa.profile_disable_hint")|fn_html_escape}{/if}</p>
        <form name="kwtsms_twofa_profile_send" id="kwtsms_twofa_profile_send" action="{""|fn_url}" method="post">
            <input type="hidden" name="profile_action" value="{$kwtsms_action}" />
            {include file="buttons/button.tpl" but_text=__("kwtsms.twofa.profile_send") but_name="dispatch[kwtsms_twofa.profile_send]" but_role="submit" but_meta="ty-btn__primary"}
        </form>
        <form name="kwtsms_twofa_profile_form" id="kwtsms_twofa_profile_form" action="{""|fn_url}" method="post">
            <input type="hidden" name="profile_action" value="{$kwtsms_action}" />
            <div class="ty-control-group">
                <label for="kwtsms_twofa_profile_code" class="ty-login__filed-label ty-control-group__label cm-required">{__("kwtsms.twofa.code_label")|fn_html_escape}</label>
                <input type="text" id="kwtsms_twofa_profile_code" name="kwtsms_code" size="12" maxlength="16" value="" class="ty-login__input" autocomplete="one-time-code" inputmode="numeric" dir="ltr" />
            </div>
            <div class="buttons-container clearfix">
                {if $kwtsms_action == "enable"}{$kwtsms_apply = __("kwtsms.twofa.turn_on")}{else}{$kwtsms_apply = __("kwtsms.twofa.turn_off")}{/if}
                {include file="buttons/button.tpl" but_text=$kwtsms_apply but_name="dispatch[kwtsms_twofa.profile]" but_role="submit" but_meta="ty-btn__primary"}
            </div>
        </form>
    {/if}
    <div class="ty-login-reglink ty-center"><a class="ty-login-reglink__a" style="color: #1a56c4; text-decoration: underline;" href="{"profiles.update"|fn_url}">{__("kwtsms.twofa.back_to_profile")|fn_html_escape}</a></div>
</div>
{capture name="mainbox_title"}{__("kwtsms.twofa.profile_title")}{/capture}
