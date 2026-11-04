{*
 * FILE:      design/backend/templates/addons/kwtsms/views/kwtsms_twofa/verify.tpl
 * CREATED:   2026-11-04 22:48 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The admin-panel code page of the second step (administrators and vendor administrators): "Enter the code
 *            we sent to +965****3402.", the code field, Sign in, "Send a new code", "Cancel and sign in again". Styled
 *            like core's sign-in modal.
 * WHY:       Task M2.2a (SafatMall B3). An anonymous page (the administrator has not signed in yet); the controller
 *            allows only verify, send and cancel to an anonymous request (trusted_controllers) and checks the session
 *            security hash of every POST itself.
 * USAGE:     Rendered by controllers/backend/kwtsms_twofa.php (GET verify). Variable kwtsms_twofa: masked, sent,
 *            seconds_left, requirement.
 * DEPENDS:   Language variables kwtsms.twofa.*.
 *}
<div class="modal signin-modal kwtsms-twofa">
    <form action="{""|fn_url}" method="post" name="kwtsms_twofa_form" id="kwtsms_twofa_form" class="signin-modal__form cm-skip-check-items">
        <div class="modal-header">
            <h4 class="signin-modal__form-header">{__("kwtsms.twofa.title")|fn_html_escape}</h4>
        </div>
        <div class="modal-body">
            <p class="kwtsms-twofa__intro">{__("kwtsms.twofa.intro", ["[phone]" => $kwtsms_twofa.masked_ltr|fn_html_escape])}</p>
            {if !$kwtsms_twofa.sent}<p class="kwtsms-twofa__note" id="kwtsms_twofa_not_sent" role="status">{__("kwtsms.twofa.not_sent")|fn_html_escape}</p>{/if}
            <div class="control-group">
                <label for="kwtsms_twofa_code" class="signin-modal__form-label cm-required">{__("kwtsms.twofa.code_label")|fn_html_escape}:</label>
                <input class="signin-modal__form-field cm-focus" id="kwtsms_twofa_code" type="text" name="kwtsms_code" size="12" maxlength="16" value="" autocomplete="one-time-code" inputmode="numeric" dir="ltr" tabindex="1">
            </div>
        </div>
        <div class="modal-footer signin-modal__footer">
            <input type="submit" class="btn btn-primary" name="dispatch[kwtsms_twofa.verify]" value="{__("kwtsms.twofa.submit")|fn_html_escape}" tabindex="2">
        </div>
    </form>
    <form action="{""|fn_url}" method="post" name="kwtsms_twofa_send_form" id="kwtsms_twofa_send_form" class="kwtsms-twofa__side">
        <input type="submit" class="btn btn-link" name="dispatch[kwtsms_twofa.send]" value="{__("kwtsms.twofa.resend")|fn_html_escape}" tabindex="3">
    </form>
    <form action="{""|fn_url}" method="post" name="kwtsms_twofa_cancel_form" id="kwtsms_twofa_cancel_form" class="kwtsms-twofa__side">
        <input type="submit" class="btn btn-link" name="dispatch[kwtsms_twofa.cancel]" value="{__("kwtsms.twofa.cancel")|fn_html_escape}" tabindex="4">
    </form>
</div>
