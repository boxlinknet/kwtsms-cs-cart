{*
FILE:      design/backend/templates/addons/kwtsms/views/kwtsms/log_details.tpl
CREATED:   2026-10-21 05:58 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      The detail view of one log row: a message (every number, the text, the codes, the raw request and reply, its
           attempts with Resend), an attempt (number, outcome and reason, the message behind it, the outbox row, its
           resends, Resend), or a debug row (its context). "Copy details for kwtSMS support" on messages and attempts.
WHY:       PRD 6.8. The raw request (redacted) and reply sit in <pre dir="ltr"> with the truncated flag of the 64 KB
           cap; Resend is a POST of the gateway privilege, offered only when Admin\LogResender says the attempt may be
           resent, otherwise the reason is shown. The support text never holds the password. Auto-escaped throughout.
USAGE:     Rendered by kwtsms.log_details?tab=<messages|attempts|debug>&id=<n> with $kwtsms_logs =
           Admin\LogsPage::details().
DEPENDS:   components/navigation.tpl, components/log_outcome.tpl, logs.less, logs.js (copy button).
*}
{$d = $kwtsms_logs}
{$r = $d.row}
{$date_time = "`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}
{capture name="mainbox"}
<div class="kwtsms-page kwtsms-logs kwtsms-log-details" id="kwtsms_log_details" data-kwtsms-tab="{$d.tab}" data-kwtsms-id="{$d.id}">

    {include file="addons/kwtsms/views/kwtsms/components/navigation.tpl" sections=$kwtsms_nav}

    <p class="kwtsms-logs-back"><a href="{"kwtsms.logs?tab=`$d.tab`"|fn_url}" id="kwtsms_back_to_list">{__("kwtsms.logs.back", ["[log]" => $d.tabs[$d.tab].title])}</a></p>

    <h4 class="kwtsms-log-details__title" id="kwtsms_detail_title">{__("kwtsms.logs.detail_title.`$d.tab`", ["[id]" => $d.id])}</h4>

    <dl class="kwtsms-details" id="kwtsms_detail_fields">
        <div><dt>{__("kwtsms.logs.col.time")}</dt><dd><bdi>{$r.created_at|date_format:$date_time}</bdi> <span class="kwtsms-logs-time__kwt">{__("kwtsms.logs.kwtsms_time")} <bdi dir="ltr">{$r.kwt_time}</bdi></span></dd></div>
        {if $d.tab == "debug"}
            <div><dt>{__("kwtsms.logs.col.level")}</dt><dd><span class="kwtsms-level kwtsms-level--{$r.level}">{__("kwtsms.logs.level.`$r.level`")}</span></dd></div>
            <div><dt>{__("kwtsms.logs.col.action")}</dt><dd><bdi dir="ltr" class="kwtsms-code-tag">{$r.action}</bdi>{if $r.audit} <span class="kwtsms-badge kwtsms-badge--ok">{__("kwtsms.logs.audit")}</span>{/if}</dd></div>
            <div><dt>{__("kwtsms.logs.col.message")}</dt><dd class="kwtsms-logs-text"><bdi>{$r.message}</bdi></dd></div>
            <div><dt>{__("kwtsms.logs.col.ms")}</dt><dd><bdi dir="ltr">{$r.duration_ms}</bdi></dd></div>
            <div><dt>{__("kwtsms.logs.f.request")}</dt><dd>{if $d.request_url}<a href="{$d.request_url|fn_url}" id="kwtsms_request_rows"><bdi dir="ltr" class="kwtsms-code-tag">{$r.request_id}</bdi></a>{/if}</dd></div>
            <div><dt>{__("kwtsms.logs.user")}</dt><dd><bdi dir="ltr">{$r.user_id}</bdi></dd></div>
        {else}
            <div><dt>{__("kwtsms.logs.col.event")}</dt><dd>{if $r.event_label}{$r.event_label} {/if}<bdi dir="ltr" class="kwtsms-code-tag">{$r.event_id}</bdi></dd></div>
            <div><dt>{__("kwtsms.logs.f.receiver")}</dt><dd>{$r.receiver_label}</dd></div>
            <div><dt>{if $d.tab == "messages"}{__("kwtsms.logs.col.status")}{else}{__("kwtsms.logs.col.outcome")}{/if}</dt><dd>{include file="addons/kwtsms/views/kwtsms/components/log_outcome.tpl" row=$r}{if $r.outcome_tip} <span class="muted kwtsms-outcome-reason">{$r.outcome_tip}</span>{/if}</dd></div>
            {if $r.order_id}
                <div><dt>{__("kwtsms.logs.col.order")}</dt><dd><a href="{"orders.details?order_id=`$r.order_id`"|fn_url}"><bdi dir="ltr">{$r.order_id}</bdi></a>{if $r.parent_order_id && $r.parent_order_id != $r.order_id} <span class="muted">{__("kwtsms.logs.parent_order", ["[id]" => $r.parent_order_id])}</span>{/if}</dd></div>
            {/if}
            {if $d.tab == "messages"}
                <div><dt>{__("kwtsms.logs.sender")}</dt><dd><bdi dir="ltr">{$r.sender}</bdi></dd></div>
                <div><dt>{__("kwtsms.logs.numbers")}</dt><dd class="kwtsms-logs-numbers">{foreach $r.numbers as $number}<bdi dir="ltr" class="kwtsms-logs-number">{$number}</bdi> {/foreach}<span class="muted">{__("kwtsms.logs.numbers_accepted", ["[accepted]" => $r.numbers_accepted, "[count]" => $r.recipient_count])}</span></dd></div>
                <div><dt>{__("kwtsms.logs.col.msg_id")}</dt><dd>{if $r.api_msg_id}<bdi dir="ltr" class="kwtsms-code-tag">{$r.api_msg_id}</bdi>{else}-{/if}</dd></div>
                <div><dt>{__("kwtsms.logs.f.code")}</dt><dd>{if $r.api_code}<bdi dir="ltr" class="kwtsms-code-tag">{$r.api_code}</bdi> <span class="muted">{__("kwtsms.logs.code_source.`$r.code_source`")}</span>{else}-{/if}</dd></div>
                {* 2026-10-21 14:11 +03: "1 page, GSM (English)" instead of "1 gsm en" (Admin\LogsPage::pagesText), cleanup 2 item 6, task 596 *}
                <div><dt>{__("kwtsms.logs.pages")}</dt><dd><bdi>{$r.pages_text}</bdi>{if $r.batch_total > 1} <span class="muted">{__("kwtsms.logs.batch", ["[no]" => $r.batch_no, "[total]" => $r.batch_total])}</span>{/if}</dd></div>
                <div><dt>{__("kwtsms.logs.col.credits")}</dt><dd><bdi dir="ltr">{$r.points_charged}</bdi> <span class="muted">{__("kwtsms.logs.balance_after", ["[balance]" => $r.balance_after])}</span></dd></div>
                <div><dt>{__("kwtsms.logs.col.ms")}</dt><dd><bdi dir="ltr">{$r.duration_ms}</bdi></dd></div>
            {else}
                <div><dt>{__("kwtsms.logs.f.phone")}</dt><dd><bdi dir="ltr" class="kwtsms-logs-number">{$r.phone}</bdi>{if $r.phone_raw && $r.phone_raw != $r.phone} <span class="muted">{__("kwtsms.logs.phone_typed")} <bdi dir="ltr">{$r.phone_raw}</bdi></span>{/if}</dd></div>
                {* 2026-10-21 12:56 +03: the plain-language text of a reason that has one, the reason id kept for support (review M2), task 596 *}
                {* 2026-10-21 14:11 +03: the text and the code, then the stored reason for support when it says more (cleanup 2 item 5), task 596 *}
                {* 2026-10-21 14:25 +03: the stored reason is left out when it is the code alone, which is already shown in brackets *}
                <div><dt>{__("kwtsms.logs.col.reason")}</dt><dd class="kwtsms-logs-text">{if $r.reason_label}<bdi>{include file="addons/kwtsms/views/kwtsms/components/log_reason.tpl" row=$r}</bdi>{if $r.reason != $r.reason_code} <span class="muted"><bdi dir="ltr">{$r.reason}</bdi></span>{/if}{else}<bdi>{$r.reason|default:"-"}</bdi>{/if}</dd></div>
                {if $r.message_id}<div><dt>{__("kwtsms.logs.message")}</dt><dd><a href="{"kwtsms.log_details?tab=messages&id=`$r.message_id`"|fn_url}" id="kwtsms_attempt_message">#{$r.message_id}</a></dd></div>{/if}
                {if $r.retry_of}<div><dt>{__("kwtsms.logs.resend_of")}</dt><dd><a href="{"kwtsms.log_details?tab=attempts&id=`$r.retry_of`"|fn_url}" id="kwtsms_retry_of">#{$r.retry_of}</a></dd></div>{/if}
                {if $d.outbox}<div><dt>{__("kwtsms.logs.outbox")}</dt><dd>{__("kwtsms.logs.outbox_state.`$d.outbox.status`")} <span class="muted">{__("kwtsms.logs.outbox_tries", ["[n]" => $d.outbox.attempts])}</span></dd></div>{/if}
            {/if}
        {/if}
    </dl>

    {if $d.tab == "messages" && $r.masked}
        <p class="muted" id="kwtsms_masked_note">{__("kwtsms.logs.masked_note")}</p>
    {/if}

    {* ---- the SMS text ------------------------------------------------------------------------------------------- *}
    {if $d.tab == "messages" || $d.message}
        {if $d.tab == "messages"}{$m = $r}{else}{$m = $d.message}{/if}
        <section class="kwtsms-section" aria-labelledby="kwtsms_detail_text_title">
            <h4 class="subheader" id="kwtsms_detail_text_title">{__("kwtsms.logs.text")}</h4>
            <div class="kwtsms-sms-text" dir="auto" id="kwtsms_detail_text">{$m.message}</div>
        </section>
    {/if}

    {* ---- the raw request and reply (redacted), 64 KB cap ------------------------------------------------------- *}
    {foreach ["request", "response"] as $raw_key}
        {if $d.raw[$raw_key]}
            {$raw = $d.raw[$raw_key]}
            <section class="kwtsms-section kwtsms-raw" aria-labelledby="kwtsms_raw_{$raw_key}_title">
                <h4 class="subheader" id="kwtsms_raw_{$raw_key}_title">{__("kwtsms.logs.raw_`$raw_key`")}</h4>
                {if $raw.truncated}
                    <p class="kwtsms-hint" id="kwtsms_raw_{$raw_key}_truncated">{__("kwtsms.logs.truncated", ["[bytes]" => $raw.bytes|number_format])}</p>
                {/if}
                <pre dir="ltr" class="kwtsms-pre" id="kwtsms_raw_{$raw_key}">{if $raw.text}{$raw.text}{else}-{/if}</pre>
            </section>
        {/if}
    {/foreach}

    {if $d.tab == "debug"}
        <section class="kwtsms-section kwtsms-raw" aria-labelledby="kwtsms_context_title">
            <h4 class="subheader" id="kwtsms_context_title">{__("kwtsms.logs.context")}</h4>
            <pre dir="ltr" class="kwtsms-pre" id="kwtsms_context">{if $d.context}{$d.context}{else}-{/if}</pre>
        </section>
    {/if}

    {* ---- resend ----------------------------------------------------------------------------------------------- *}
    {if $d.tab == "attempts"}
        {if $d.resends}
            <p id="kwtsms_resends">{__("kwtsms.logs.resent_as")} {foreach $d.resends as $re}<a href="{"kwtsms.log_details?tab=attempts&id=`$re.id`"|fn_url}">#{$re.id}</a> {include file="addons/kwtsms/views/kwtsms/components/log_outcome.tpl" row=$re} {/foreach}</p>
        {/if}
        {if $d.resend}
            {if $d.resend.ok}
                <form action="{""|fn_url}" method="post" name="kwtsms_resend_form" class="kwtsms-inline-form kwtsms-resend">
                    <input type="hidden" name="attempt_id" value="{$d.id}">
                    {* 2026-10-21 16:58 +03: confirm first; an unknown outcome says to check the kwtSMS archive (final review M1), task 596 *}
                    <input type="submit" class="btn btn-primary cm-confirm" id="kwtsms_resend" name="dispatch[kwtsms.resend]" value="{__("kwtsms.logs.resend.button")}" data-ca-confirm-text="{if $r.outcome == "unknown"}{__("kwtsms.logs.resend.confirm_unknown")}{else}{__("kwtsms.logs.resend.confirm")}{/if}">
                    <span class="muted">{__("kwtsms.logs.resend.help")}</span>
                </form>
            {elseif $r.resendable}
                <p class="muted" id="kwtsms_resend_why">{__($d.resend.reason)}</p>
            {/if}
        {/if}
    {/if}

    {* ---- attempts of a message ---------------------------------------------------------------------------------- *}
    {if $d.tab == "messages"}
        <section class="kwtsms-section" aria-labelledby="kwtsms_detail_attempts_title">
            <h4 class="subheader" id="kwtsms_detail_attempts_title">{__("kwtsms.logs.attempts_of_message")}</h4>
            {if !$d.attempts}
                <p class="muted" id="kwtsms_detail_attempts_none">{__("kwtsms.logs.no_attempts")}</p>
            {else}
                <div class="kwtsms-table-wrap">
                    <table class="table table-middle kwtsms-table" id="kwtsms_detail_attempts">
                        <thead><tr>
                            <th scope="col">{__("kwtsms.logs.f.phone")}</th>
                            <th scope="col">{__("kwtsms.logs.col.outcome")}</th>
                            <th scope="col">{__("kwtsms.logs.col.reason")}</th>
                            <th scope="col"><span class="hidden">{__("kwtsms.logs.col.details")}</span></th>
                        </tr></thead>
                        <tbody>
                            {foreach $d.attempts as $a}
                                <tr data-kwtsms-attempt="{$a.id}">
                                    <td><bdi dir="ltr" class="kwtsms-logs-number">{if $a.phone}{$a.phone}{else}{$a.phone_raw}{/if}</bdi></td>
                                    <td>{include file="addons/kwtsms/views/kwtsms/components/log_outcome.tpl" row=$a}</td>
                                    {* 2026-10-21 12:56 +03: the plain-language text of a reason that has one (review M2), task 596 *}
                                    {* 2026-10-21 14:11 +03: the text, then the code in brackets (cleanup 2 item 5), task 596 *}
                                    <td class="kwtsms-logs-text"><bdi>{include file="addons/kwtsms/views/kwtsms/components/log_reason.tpl" row=$a}</bdi></td>
                                    <td class="kwtsms-logs-view">
                                        <a href="{"kwtsms.log_details?tab=attempts&id=`$a.id`"|fn_url}">{__("kwtsms.logs.view")}</a>
                                        {if $a.resend && $a.resend.ok}
                                            <form action="{""|fn_url}" method="post" name="kwtsms_resend_{$a.id}" class="kwtsms-inline-form">
                                                <input type="hidden" name="attempt_id" value="{$a.id}">
                                                {* 2026-10-21 16:58 +03: confirm first, unknown wording for an unknown attempt (final review M1), task 596 *}
                                                <input type="submit" class="btn btn-small cm-confirm" name="dispatch[kwtsms.resend]" value="{__("kwtsms.logs.resend.button")}" data-ca-confirm-text="{if $a.outcome == "unknown"}{__("kwtsms.logs.resend.confirm_unknown")}{else}{__("kwtsms.logs.resend.confirm")}{/if}">
                                            </form>
                                        {/if}
                                    </td>
                                </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
            {/if}
        </section>
    {/if}

    {* ---- copy details for kwtSMS support ------------------------------------------------------------------------ *}
    {if $d.tab != "debug"}
        <section class="kwtsms-section kwtsms-support" aria-labelledby="kwtsms_support_title">
            <h4 class="subheader" id="kwtsms_support_title">{__("kwtsms.logs.support.heading")}</h4>
            <p class="muted">{__("kwtsms.logs.support.help")}</p>
            <textarea class="kwtsms-support__text" id="kwtsms_support_text" readonly="readonly" rows="10" dir="ltr" aria-labelledby="kwtsms_support_title">{$d.support}</textarea>
            <div class="kwtsms-actions">
                <button type="button" class="btn kwtsms-js-copy" id="kwtsms_copy_support" data-kwtsms-copy-target="kwtsms_support_text">{__("kwtsms.logs.support.copy")}</button>
                <span class="kwtsms-js-copied hidden" id="kwtsms_copied" role="status">{__("kwtsms.logs.support.copied")}</span>
            </div>
        </section>
    {/if}
</div>
{/capture}

{include file="common/mainbox.tpl"
    title=__("kwtsms.logs.title")
    content=$smarty.capture.mainbox
    select_storefront=false
}
