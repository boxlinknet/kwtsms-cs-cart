{*
 * FILE:      design/backend/templates/addons/kwtsms/views/kwtsms_twofa/profile.tpl
 * CREATED:   2026-11-04 22:48 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The admin-panel page "SMS code at sign-in" of the signed-in administrator or vendor administrator: the
 *            state, a form that sends a code to the mobile number of the account (turning on) or to the current 2FA
 *            phone (turning off), and a form that takes the code.
 * WHY:       Task M2.2a (SafatMall B3, P3-49). The number is the one an administrator set on the account (Phone field of
 *            the profile); it is never typed here, so nobody can enrol a number they do not hold the account for.
 * USAGE:     Rendered by controllers/backend/kwtsms_twofa.php (GET profile). Variable kwtsms_twofa: enabled, masked,
 *            usable_phone, can_disable.
 * DEPENDS:   Language variables kwtsms.twofa.*, common/mainbox.tpl.
 *}
{$kwtsms_action = "enable"}{if $kwtsms_twofa.enabled}{$kwtsms_action = "disable"}{/if}
{capture name="mainbox"}
<div class="kwtsms-twofa-profile" id="kwtsms_twofa_profile">
    <p id="kwtsms_twofa_state" role="status">{if $kwtsms_twofa.enabled}{__("kwtsms.twofa.state_on", ["[phone]" => $kwtsms_twofa.masked_ltr|fn_html_escape])}{else}{__("kwtsms.twofa.state_off")|fn_html_escape}{/if}</p>
    {if $kwtsms_action == "enable" && !$kwtsms_twofa.usable_phone}
        <p id="kwtsms_twofa_no_phone">{__("kwtsms.twofa.no_phone_admin")|fn_html_escape}</p>
    {elseif $kwtsms_action == "disable" && !$kwtsms_twofa.can_disable}
        <p id="kwtsms_twofa_required_note">{__("kwtsms.twofa.required")|fn_html_escape}</p>
    {else}
        <p>{if $kwtsms_action == "enable"}{__("kwtsms.twofa.profile_enable_hint", ["[phone]" => $kwtsms_twofa.masked_ltr|fn_html_escape])}{else}{__("kwtsms.twofa.profile_disable_hint")|fn_html_escape}{/if}</p>
        <form action="{""|fn_url}" method="post" name="kwtsms_twofa_profile_send" id="kwtsms_twofa_profile_send" class="form-horizontal">
            <input type="hidden" name="profile_action" value="{$kwtsms_action}">
            <input type="submit" class="btn" name="dispatch[kwtsms_twofa.profile_send]" value="{__("kwtsms.twofa.profile_send")|fn_html_escape}">
        </form>
        <form action="{""|fn_url}" method="post" name="kwtsms_twofa_profile_form" id="kwtsms_twofa_profile_form" class="form-horizontal form-edit">
            <input type="hidden" name="profile_action" value="{$kwtsms_action}">
            <div class="control-group">
                <label for="kwtsms_twofa_profile_code" class="control-label cm-required">{__("kwtsms.twofa.code_label")|fn_html_escape}:</label>
                <div class="controls">
                    <input type="text" id="kwtsms_twofa_profile_code" name="kwtsms_code" size="12" maxlength="16" value="" autocomplete="one-time-code" inputmode="numeric" dir="ltr">
                </div>
            </div>
            <div class="control-group"><div class="controls">
                {if $kwtsms_action == "enable"}{$kwtsms_apply = __("kwtsms.twofa.turn_on")}{else}{$kwtsms_apply = __("kwtsms.twofa.turn_off")}{/if}
                <input type="submit" class="btn btn-primary" name="dispatch[kwtsms_twofa.profile]" value="{$kwtsms_apply|fn_html_escape}">
            </div></div>
        </form>
    {/if}
    <p><a href="{"profiles.update?user_id=`$auth.user_id`"|fn_url}">{__("kwtsms.twofa.back_to_profile")|fn_html_escape}</a></p>
</div>
{/capture}
{include file="common/mainbox.tpl" title=__("kwtsms.twofa.profile_title") content=$smarty.capture.mainbox}
