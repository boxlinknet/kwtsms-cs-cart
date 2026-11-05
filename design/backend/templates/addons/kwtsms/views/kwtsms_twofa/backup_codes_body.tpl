{*
 * FILE:      design/backend/templates/addons/kwtsms/views/kwtsms_twofa/backup_codes_body.tpl
 * CREATED:   2026-11-05 08:12 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The body of the backup codes page: the warning, the ten codes in a monospace, left-to-right list and the way on.
 * WHY:       Task M2.2b. Shared by backup_codes.tpl for its two shells (the sign-in card right after the sign-in, the
 *            admin main box otherwise) without printing captured HTML raw (the package gate refuses raw output in templates).
 * USAGE:     {include file="addons/kwtsms/views/kwtsms_twofa/backup_codes_body.tpl"}; variables kwtsms_backup_codes
 *            (string[10]) and kwtsms_backup_after_login (bool).
 * DEPENDS:   Language variables kwtsms.twofa.backup_codes_*.
 *}
<div class="kwtsms-backup-codes" id="kwtsms_backup_codes">
    <p id="kwtsms_backup_codes_intro">{__("kwtsms.twofa.backup_codes_intro")|fn_html_escape}</p>
    {* 2026-11-05 08:12 +03: e2e finding (task 596): a code must not wrap at its hyphen inside the narrow sign-in card; 2026-11-05 10:46 +03: one column (two columns overflowed the card). *}
    <ol class="kwtsms-backup-codes__list" id="kwtsms_backup_codes_list" dir="ltr" style="font-family: monospace; font-size: 1em; letter-spacing: 0.04em; white-space: nowrap; list-style-position: inside; padding: 0; margin: 1em 0;">
        {foreach $kwtsms_backup_codes as $kwtsms_bc}<li>{$kwtsms_bc|fn_html_escape}</li>{/foreach}
    </ol>
    <p>{if $kwtsms_backup_after_login}<a class="btn btn-primary" id="kwtsms_backup_codes_continue" href="{"index.index"|fn_url}">{__("kwtsms.twofa.backup_codes_continue")|fn_html_escape}</a>{elseif $kwtsms_backup_next}<a class="btn btn-primary" id="kwtsms_backup_codes_next" href="{$kwtsms_backup_next|fn_url}">{__("kwtsms.twofa.backup_codes_continue")|fn_html_escape}</a>{else}<a class="btn btn-primary" id="kwtsms_backup_codes_back" href="{"kwtsms_twofa.profile"|fn_url}">{__("kwtsms.twofa.backup_codes_back")|fn_html_escape}</a>{/if}</p>
</div>
