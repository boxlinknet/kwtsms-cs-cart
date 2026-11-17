{*
FILE:      design/backend/templates/addons/kwtsms/views/kwtsms/components/template_import_preview.tpl
CREATED:   2026-11-16 23:50 +03
TASK:      596 (Task M3.10, B10, P3-71)
AUTHOR:    kwtSMS
WHAT:      The Templates import: the export link, the upload form and, when a file was uploaded, its preview (counts and
           the rows that would change or be refused, with the reason) and the confirm form.
WHY:       P3-71. Preview first, then a confirm POST that carries the sha256 of the previewed file; nothing is written
           by the preview.
USAGE:     {include file="addons/kwtsms/views/kwtsms/components/template_import_preview.tpl" import=$kwtsms_import}
           $import: sha, counts (new, changed, unchanged, refused), rows (event_id, receiver, storefront_id, lang,
           status, reason, unknown), errors (file level reason codes) or empty. Every value is auto-escaped; no
           template text is ever printed here.
DEPENDS:   the import and transfer language variables of the Templates page.

           2026-11-17 07:01: M3.10b item 2, task 596. $import_limit (bytes, optional) = the effective upload limit; the form
           carries it with the "too large" text in data attributes, and js/addons/kwtsms/templates.js refuses a larger file
           before submit (no inline script).
*}
<section class="kwtsms-import" id="kwtsms_import" aria-labelledby="kwtsms_import_title">
    <h4 class="subheader" id="kwtsms_import_title">{__("kwtsms.templates.transfer_title")}</h4>
    <p class="muted">{__("kwtsms.templates.transfer_help")}</p>
    <p>
        <a class="btn" id="kwtsms_export_templates" href="{"kwtsms.export_templates"|fn_url}">{__("kwtsms.templates.export_button")}</a>
    </p>
    <form action="{""|fn_url}" method="post" enctype="multipart/form-data" name="kwtsms_import_form" id="kwtsms_import_form" class="kwtsms-inline-form" data-kwtsms-import-limit="{$import_limit|default:1048576|intval}" data-kwtsms-import-too-large="{__("kwtsms.templates.import_reason_too_large")}">
        <label for="kwtsms_import_file">{__("kwtsms.templates.import_file")}</label>
        <input type="file" id="kwtsms_import_file" name="kwtsms_import_file" accept=".json,application/json" required="required">
        <input type="submit" class="btn" id="kwtsms_import_preview_btn" name="dispatch[kwtsms.import_templates]" value="{__("kwtsms.templates.import_preview_button")}">
    </form>

    {if $import}
        <div class="kwtsms-import__preview" id="kwtsms_import_preview" role="region" aria-labelledby="kwtsms_import_preview_title">
            <h5 id="kwtsms_import_preview_title">{__("kwtsms.templates.import_preview_title")}</h5>
            {if $import.errors}
                {foreach $import.errors as $code}
                    <div class="alert alert-error kwtsms-alert" role="alert">{__("kwtsms.templates.import_reason_`$code`")}</div>
                {/foreach}
            {else}
                <p id="kwtsms_import_counts">{__("kwtsms.templates.import_counts", ["[new]" => $import.counts.new|fn_html_escape, "[changed]" => $import.counts.changed|fn_html_escape, "[unchanged]" => $import.counts.unchanged|fn_html_escape, "[refused]" => $import.counts.refused|fn_html_escape])}</p>
                {* 2026-11-17 00:40 +03: Task M3.10 fix round 1 (I2), task 596. One form around the table: each unknown-placeholder row has its own consent box. *}
                <form action="{""|fn_url}" method="post" name="kwtsms_import_confirm_form" id="kwtsms_import_confirm_form" class="kwtsms-inline-form">
                <input type="hidden" name="kwtsms_import[sha]" value="{$import.sha}">
                {if $import.rows}
                <div class="table-responsive-wrapper">
                    <table class="table table-middle kwtsms-import__table" id="kwtsms_import_table">
                        <thead>
                            <tr>
                                <th>{__("kwtsms.templates.import_col_event")}</th>
                                <th>{__("kwtsms.templates.import_col_receiver")}</th>
                                <th>{__("kwtsms.templates.import_col_storefront")}</th>
                                <th>{__("kwtsms.templates.import_col_language")}</th>
                                <th>{__("kwtsms.templates.import_col_status")}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach $import.rows as $r}
                            <tr>
                                <td><bdi dir="ltr">{$r.event_id}</bdi></td>
                                <td><bdi dir="ltr">{$r.receiver}</bdi></td>
                                <td><bdi dir="ltr">{$r.storefront_id}</bdi></td>
                                <td><bdi dir="ltr">{$r.lang}</bdi></td>
                                <td>{__("kwtsms.templates.import_status_`$r.status`")}{if $r.reason} : {__("kwtsms.templates.import_reason_`$r.reason`")}{if $r.unknown} <bdi dir="ltr">{$r.unknown|implode:" "}</bdi>{/if}{/if}
                                    {if $r.reason == "unknown_placeholders"}
                                    <label class="checkbox" for="kwtsms_import_keep_{$r@index}">
                                        <input type="checkbox" id="kwtsms_import_keep_{$r@index}" name="kwtsms_import[keep][]" value="{$r.key}">
                                        {__("kwtsms.templates.import_keep_unknown")}
                                    </label>
                                    {/if}</td>
                            </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
                {/if}
                    <input type="submit" class="btn btn-primary" id="kwtsms_import_confirm" name="dispatch[kwtsms.import_templates_confirm]"
                           value="{__("kwtsms.templates.import_confirm")}"{if $import.counts.new + $import.counts.changed == 0 && !$import.counts.refused} disabled="disabled"{/if}>
                    <input type="submit" class="btn" id="kwtsms_import_discard" name="dispatch[kwtsms.import_templates_confirm.discard]" value="{__("kwtsms.templates.import_discard")}">
                </form>
            {/if}
        </div>
    {/if}
</section>
