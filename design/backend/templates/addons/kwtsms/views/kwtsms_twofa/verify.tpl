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
 *
 * 2026-11-05 07:34 +03: Task M2.2b (P3-52 (3)), task 596. With kwtsms_backup_mode the same page asks for a backup code (field, title and
 * Sign in post to kwtsms_twofa.backup) and links back to the SMS code; otherwise a link "Use a backup code instead" shows
 * when the account has backup codes (kwtsms_twofa.backup_available).
 *}
<div class="modal signin-modal kwtsms-twofa">
    <form action="{""|fn_url}" method="post" name="kwtsms_twofa_form" id="kwtsms_twofa_form" class="signin-modal__form cm-skip-check-items">
        <div class="modal-header">
            <h4 class="signin-modal__form-header">{if $kwtsms_backup_mode}{__("kwtsms.twofa.backup_title")|fn_html_escape}{else}{__("kwtsms.twofa.title")|fn_html_escape}{/if}</h4>
        </div>
        <div class="modal-body">
            {if $kwtsms_backup_mode}
                <p class="kwtsms-twofa__intro" id="kwtsms_twofa_backup_intro">{__("kwtsms.twofa.backup_intro")|fn_html_escape}</p>
            {else}
                {* 2026-11-07 02:40 +03: Task M2.7 (ruling 22:04), task 596. The masked number is its own bdi dir=ltr element: the isolate characters of masked_ltr did not survive and +965****1234 read reversed in Arabic. *}
                <p class="kwtsms-twofa__intro">{__("kwtsms.twofa.intro")|fn_html_escape} <bdi dir="ltr" class="kwtsms-twofa__number">{$kwtsms_twofa.masked}</bdi></p>
                {if !$kwtsms_twofa.sent}<p class="kwtsms-twofa__note" id="kwtsms_twofa_not_sent" role="status">{__("kwtsms.twofa.not_sent")|fn_html_escape}</p>{/if}
            {/if}
            <div class="control-group">
                <label for="kwtsms_twofa_code" class="signin-modal__form-label cm-required">{if $kwtsms_backup_mode}{__("kwtsms.twofa.backup_label")|fn_html_escape}{else}{__("kwtsms.twofa.code_label")|fn_html_escape}{/if}:</label>
                <input class="signin-modal__form-field cm-focus" id="kwtsms_twofa_code" type="text" name="kwtsms_code" size="14" maxlength="24" value="" autocomplete="off" {if !$kwtsms_backup_mode}inputmode="numeric" {/if}dir="ltr" tabindex="1">
            </div>
        </div>
        <div class="modal-footer signin-modal__footer">
            <input type="submit" class="btn btn-primary" name="dispatch[kwtsms_twofa.{if $kwtsms_backup_mode}backup{else}verify{/if}]" value="{__("kwtsms.twofa.submit")|fn_html_escape}" tabindex="2">
        </div>
    </form>
    {if !$kwtsms_backup_mode}
    <form action="{""|fn_url}" method="post" name="kwtsms_twofa_send_form" id="kwtsms_twofa_send_form" class="kwtsms-twofa__side">
        <input type="submit" class="btn btn-link" name="dispatch[kwtsms_twofa.send]" value="{__("kwtsms.twofa.resend")|fn_html_escape}" tabindex="3">
    </form>
    {* 2026-11-05 08:11 +03: e2e finding (task 596): the links are btn-link anchors so they line up with the other two links of the card. *}
    {if $kwtsms_twofa.backup_available}<div class="kwtsms-twofa__side"><a class="btn btn-link" id="kwtsms_twofa_backup_link" href="{"kwtsms_twofa.backup"|fn_url}" tabindex="5">{__("kwtsms.twofa.backup_link")|fn_html_escape}</a></div>{/if}
    {else}
    <div class="kwtsms-twofa__side"><a class="btn btn-link" id="kwtsms_twofa_sms_link" href="{"kwtsms_twofa.verify"|fn_url}" tabindex="3">{__("kwtsms.twofa.sms_link")|fn_html_escape}</a></div>
    {/if}
    <form action="{""|fn_url}" method="post" name="kwtsms_twofa_cancel_form" id="kwtsms_twofa_cancel_form" class="kwtsms-twofa__side">
        <input type="submit" class="btn btn-link" name="dispatch[kwtsms_twofa.cancel]" value="{__("kwtsms.twofa.cancel")|fn_html_escape}" tabindex="4">
    </form>
</div>
