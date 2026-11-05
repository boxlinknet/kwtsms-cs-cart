{*
 * FILE:      design/backend/templates/addons/kwtsms/views/kwtsms_twofa/backup_codes.tpl
 * CREATED:   2026-11-05 07:40 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Shows a new set of ten backup codes ONCE: a list in a monospace, left-to-right font, a short warning, and the
 *            way on (a link to the dashboard right after sign-in, a link back to the two-step sign-in page otherwise).
 * WHY:       Task M2.2b (SafatMall B3, P3-52 (3)). The plain codes exist only in this response (Cache-Control: no-store,
 *            nothing in the session or the database); a reload shows nothing. Right after the sign-in that enrolled the
 *            account the page uses the same shell as the code page (the request began anonymous); otherwise the admin
 *            main box.
 * USAGE:     Rendered by controllers/backend/kwtsms_twofa.php (POST verify / backup / profile / backup_regenerate) through
 *            the view variables kwtsms_backup_codes (string[10]) and kwtsms_backup_after_login (bool).
 * DEPENDS:   Language variables kwtsms.twofa.backup_codes_*, common/mainbox.tpl.
 *}
{if $kwtsms_backup_after_login}
<div class="modal signin-modal kwtsms-twofa">
    <div class="modal-header"><h4 class="signin-modal__form-header">{__("kwtsms.twofa.backup_codes_title")|fn_html_escape}</h4></div>
    <div class="modal-body">{include file="addons/kwtsms/views/kwtsms_twofa/backup_codes_body.tpl"}</div>
</div>
{else}
{capture name="mainbox"}{include file="addons/kwtsms/views/kwtsms_twofa/backup_codes_body.tpl"}{/capture}
{include file="common/mainbox.tpl" title=__("kwtsms.twofa.backup_codes_title") content=$smarty.capture.mainbox}
{/if}
