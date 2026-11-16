{*
 * FILE:      design/backend/templates/addons/kwtsms/views/kwtsms/components/bulk_resend_confirm.tpl
 * CREATED:   2026-11-16 23:30 +03
 * TASK:      596 (M3.11, B11, P3-72)
 * AUTHOR:    kwtSMS
 * WHAT:      The confirm step of a bulk resend on the Logs page: how many of the selected attempts can still be resent,
 *            the credits they need, how many are skipped, and the confirm / cancel buttons.
 * WHY:       A bulk resend spends credits, so the merchant sees the count and the estimate before anything is sent.
 *            The form carries only the one-time token: the ids are kept in the session by step 1.
 * USAGE:     {include file="addons/kwtsms/views/kwtsms/components/bulk_resend_confirm.tpl" bulk=$l.bulk list_url=$l.list_url}
 * DEPENDS:   LogsPage::bulkPreview() (ids, count, credits, skipped, token), the kwtsms.logs.bulk.* language variables.
 *}
<section class="kwtsms-logs-confirm" id="kwtsms_bulk_confirm" aria-labelledby="kwtsms_bulk_confirm_title" data-kwtsms-count="{$bulk.count}" data-kwtsms-credits="{$bulk.credits}">
    {if $bulk.count == 0}
        <h4 id="kwtsms_bulk_confirm_title">{__("kwtsms.logs.bulk.nothing_title")}</h4>
        <p>{__("kwtsms.logs.bulk.nothing")}</p>
        <a class="btn" href="{$list_url|fn_url}">{__("kwtsms.logs.clear.back")}</a>
    {else}
        <h4 id="kwtsms_bulk_confirm_title">{__("kwtsms.logs.bulk.confirm_title", [0 => $bulk.count|fn_html_escape, "[count]" => $bulk.count|number_format|fn_html_escape])}</h4>
        <p id="kwtsms_bulk_estimate">{__("kwtsms.logs.bulk.confirm", ["[credits]" => $bulk.credits|number_format|fn_html_escape])}</p>
        {if $bulk.skipped > 0}<p class="muted" id="kwtsms_bulk_skipped">{__("kwtsms.logs.bulk.skipped", ["[count]" => $bulk.skipped|fn_html_escape])}</p>{/if}
        <div class="kwtsms-actions">
            <form action="{""|fn_url}" method="post" name="kwtsms_bulk_confirm_form" class="kwtsms-inline-form">
                <input type="hidden" name="confirmed" value="1">
                <input type="hidden" name="bulk_token" value="{$bulk.token}">
                <input type="submit" class="btn btn-primary" id="kwtsms_bulk_confirm_submit" name="dispatch[kwtsms.bulk_resend]" value="{__("kwtsms.logs.bulk.submit", [0 => $bulk.count|fn_html_escape, "[count]" => $bulk.count|number_format|fn_html_escape])}">
            </form>
            <a class="btn" id="kwtsms_bulk_cancel" href="{$list_url|fn_url}">{__("cancel")}</a>
        </div>
    {/if}
</section>
