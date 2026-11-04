{*
 * FILE:      design/backend/templates/addons/kwtsms/hooks/profiles/general_content.post.tpl
 * CREATED:   2026-11-04 22:48 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      On the administrator's OWN profile page (and a vendor administrator's own): the state of the two-step
 *            sign-in and a link to the page that turns it on or off with a code ("Ask for an SMS code when I sign in").
 * WHY:       Task M2.2a (SafatMall B3, P3-49). The hook sits inside the core profile form, so this is a link, not a
 *            form. Shown only for the signed-in account's own profile and only while the switch of its type is not off.
 * USAGE:     Loaded by core as a hook template (profiles:general_content) while the add-on is active.
 * DEPENDS:   func.php fn_kwtsms_twofa_box, language variables kwtsms.twofa.*.
 *}
{if $auth.user_id && $id == $auth.user_id}
    {$kwtsms_tf = $auth.user_id|fn_kwtsms_twofa_box}
    {if $kwtsms_tf.available}
        <div class="control-group kwtsms-twofa-box" id="kwtsms_twofa_box">
            <label class="control-label">{__("kwtsms.twofa.optin_label")|fn_html_escape}:</label>
            <div class="controls">
                <span class="kwtsms-twofa-box__state">{if $kwtsms_tf.enabled}{__("kwtsms.twofa.box_on")|fn_html_escape}{else}{__("kwtsms.twofa.box_off")|fn_html_escape}{/if}</span>
                <a class="kwtsms-twofa-box__link" style="color: #0b4a9e; text-decoration: underline;" href="{"kwtsms_twofa.profile"|fn_url}">{__("kwtsms.twofa.box_manage")|fn_html_escape}</a>
            </div>
        </div>
    {/if}
{/if}
