{*
 * FILE:      design/themes/responsive/templates/addons/kwtsms/views/kwtsms_auth/recover_continue.tpl
 * CREATED:   2026-11-05 16:38 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The "press continue" step of the recovery by code: core's own text and a Continue button in a POST form to
 *            auth.recover_password with the recovery key in a hidden field (core's recovery then signs the customer in
 *            and opens the profile to set a new password).
 * WHY:       Task M2.3 (SafatMall B4, P3-53). The key is handed over by POST only: it is never in a URL, a redirect, the
 *            session, a log or a JSON answer. This page is the only place it is written, on a no-store answer.
 * USAGE:     Rendered by controllers/frontend/kwtsms_auth.php (mode recover, POST, right code) as the content template.
 *            Variable: kwtsms_ekey (the key).
 * DEPENDS:   core language variables press_continue_to_recover_password and continue, buttons/button.tpl.
 *}
<div class="ty-recover-password kwtsms-recover-continue" style="max-width: 28em;">
    <form name="kwtsms_recover_continue_form" id="kwtsms_recover_continue_form" action="{""|fn_url}" method="post">
        <input type="hidden" name="ekey" value="{$kwtsms_ekey|fn_html_escape}" />
        <div class="ty-control-group">
            <p>{__("press_continue_to_recover_password")|strip_tags|fn_html_escape}</p>
        </div>
        <div class="buttons-container login-recovery">
            {include file="buttons/button.tpl" but_text=__("continue") but_meta="ty-btn__primary" but_name="dispatch[auth.recover_password]"}
        </div>
    </form>
</div>
{capture name="mainbox_title"}{__("kwtsms.recover.title")}{/capture}
