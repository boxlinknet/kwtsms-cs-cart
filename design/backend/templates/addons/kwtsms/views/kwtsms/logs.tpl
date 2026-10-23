{*
FILE:      design/backend/templates/addons/kwtsms/views/kwtsms/logs.tpl
CREATED:   2026-10-21 05:57 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      The kwtSMS Logs page: tabs Messages, Attempts and Debug, the filter form of the tab, the rows (store time and
           kwtSMS time, TEST badge, canonical outcome labels with their tooltip), paging, CSV export of Messages, and
           Clear (all, older than N days, the current filter) with the row count shown before anything is deleted.
WHY:       PRD 6.8. Native backend components under the .kwtsms-page root; every value is auto-escaped (no nofilter);
           links and hidden fields come ready from Admin\LogsPage. Forms never nest: the filter form and each Clear
           form are separate; only the confirmed Clear posts (with the core security hash).
USAGE:     Rendered by kwtsms.logs (controllers/backend/kwtsms.php) with $kwtsms_logs = Admin\LogsPage::viewData() and
           $kwtsms_nav = Admin\Navigation::sections().
DEPENDS:   components/navigation.tpl, components/log_outcome.tpl, logs.less, logs.js, kwtsms.logs.* language variables.
*}
{* 2026-10-23 13:16 +03: final review A, Important 2, task 596. Every value in a __() placeholder array now passes |fn_html_escape (values that never had a modifier too); tests/Unit/TemplateEscapeTest enforces it. *}
{$l = $kwtsms_logs}
{$date_time = "`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}
{capture name="mainbox"}
<div class="kwtsms-page kwtsms-logs" id="kwtsms_logs_page" data-kwtsms-tab="{$l.tab}" data-kwtsms-per-page="{$l.search.items_per_page}" data-kwtsms-total="{$l.search.total_items}" data-kwtsms-rows="{$l.rows|count}">

    {include file="addons/kwtsms/views/kwtsms/components/navigation.tpl" sections=$kwtsms_nav}

    <nav class="kwtsms-logs-tabs" aria-label="{__("kwtsms.logs.tabs_label")}">
        <ul class="nav nav-pills">
            {foreach $l.tabs as $tab_id => $tab}
                <li class="{if $tab.active}active{/if}" id="kwtsms_logs_tab_{$tab_id}">
                    <a href="{$tab.href|fn_url}"{if $tab.active} aria-current="page"{/if}>{$tab.title}</a>
                </li>
            {/foreach}
        </ul>
    </nav>

    {foreach $l.errors as $error}
        <div class="alert alert-warning kwtsms-inline-alert kwtsms-logs__error" role="alert">{$error}</div>
    {/foreach}

    {* ---- clear: the count first, then the confirmation ------------------------------------------------------------ *}
    {if $l.clear}
        {$c = $l.clear}
        <section class="kwtsms-logs-confirm" id="kwtsms_clear_confirm" aria-labelledby="kwtsms_clear_confirm_title" data-kwtsms-count="{$c.count}" data-kwtsms-scope="{$c.scope}">
            {if $c.error}
                <p class="kwtsms-hint" id="kwtsms_clear_error" role="alert">{__($c.error)}</p>
            {elseif $c.count == 0}
                <h4 id="kwtsms_clear_confirm_title">{__("kwtsms.logs.clear.nothing_title")}</h4>
                <p>{__("kwtsms.logs.clear.nothing", ["[log]" => $c.log|fn_html_escape])}</p>
                <a class="btn" href="{$l.list_url|fn_url}">{__("kwtsms.logs.clear.back")}</a>
            {else}
                {* 2026-10-21 17:28 +03: plural forms, the number first (final review M5), task 596 *}
                <h4 id="kwtsms_clear_confirm_title">{__("kwtsms.logs.clear.confirm_title", [0 => $c.count|fn_html_escape, "[count]" => $c.count|number_format|fn_html_escape])}</h4>
                <p>
                    {if $c.scope == "all"}{__("kwtsms.logs.clear.confirm_all", ["[log]" => $c.log|fn_html_escape])}
                    {elseif $c.scope == "older"}{__("kwtsms.logs.clear.confirm_older", ["[log]" => $c.log|fn_html_escape, "[days]" => $c.days|fn_html_escape])}
                    {else}{__("kwtsms.logs.clear.confirm_filter", ["[log]" => $c.log|fn_html_escape])}{/if}
                    {__("kwtsms.logs.clear.cannot_undo")}
                </p>
                {if $l.tab == "debug"}<p class="muted">{__("kwtsms.logs.clear.audit_kept")}</p>{/if}
                {* 2026-10-21 17:25 +03: the sending limits count the attempt rows, so clearing them resets the limits (final review M4), task 596 *}
                {if $l.tab == "attempts" && $c.scope != "older"}<p class="muted" id="kwtsms_clear_caps">{__("kwtsms.logs.clear.caps_reset")}</p>{/if}
                <div class="kwtsms-actions">
                    <form action="{""|fn_url}" method="post" name="kwtsms_clear_form" class="kwtsms-inline-form">
                        {foreach $c.hidden as $field}
                            <input type="hidden" name="{$field.name}" value="{$field.value}">
                        {/foreach}
                        <input type="submit" class="btn btn-danger" id="kwtsms_clear_submit" name="dispatch[kwtsms.clear_logs]" value="{__("kwtsms.logs.clear.submit", [0 => $c.count|fn_html_escape, "[count]" => $c.count|number_format|fn_html_escape])}">
                    </form>
                    <a class="btn" id="kwtsms_clear_cancel" href="{$l.list_url|fn_url}">{__("cancel")}</a>
                </div>
            {/if}
        </section>
    {/if}

    {* ---- filters ---------------------------------------------------------------------------------------------- *}
    {$f = $l.form}
    <form action="{""|fn_url}" method="get" name="kwtsms_logs_filter" id="kwtsms_logs_filter" class="kwtsms-logs-filter">
        <input type="hidden" name="dispatch" value="kwtsms.logs">
        <input type="hidden" name="tab" value="{$l.tab}">
        <div class="kwtsms-logs-filter__grid">
            <div class="kwtsms-field">
                <label for="kwtsms_f_from">{__("kwtsms.logs.f.from")}</label>
                <input type="date" id="kwtsms_f_from" name="period_from" value="{$f.period_from|default:""}" dir="ltr">
            </div>
            <div class="kwtsms-field">
                <label for="kwtsms_f_to">{__("kwtsms.logs.f.to")}</label>
                <input type="date" id="kwtsms_f_to" name="period_to" value="{$f.period_to|default:""}" dir="ltr">
            </div>
            {if $l.tab == "messages" || $l.tab == "attempts"}
                {if $l.tab == "messages"}{$outcome_key = "status"}{else}{$outcome_key = "outcome"}{/if}
                <div class="kwtsms-field">
                    <label for="kwtsms_f_outcome">{__("kwtsms.logs.f.outcome")}</label>
                    <select id="kwtsms_f_outcome" name="{$outcome_key}">
                        <option value="">{__("kwtsms.logs.f.any")}</option>
                        {foreach $l.options.outcomes as $code => $label}
                            <option value="{$code}"{if $f.$outcome_key|default:"" == $code} selected="selected"{/if}>{$label}</option>
                        {/foreach}
                    </select>
                </div>
                <div class="kwtsms-field">
                    <label for="kwtsms_f_event">{__("kwtsms.logs.f.event")}</label>
                    <select id="kwtsms_f_event" name="event_id">
                        <option value="">{__("kwtsms.logs.f.any")}</option>
                        {foreach $l.options.events as $event => $label}
                            <option value="{$event}"{if $f.event_id|default:"" == $event} selected="selected"{/if}>{$label}</option>
                        {/foreach}
                        {if $f.event_id|default:"" && !isset($l.options.events[$f.event_id])}
                            <option value="{$f.event_id}" selected="selected">{$f.event_id}</option>
                        {/if}
                    </select>
                </div>
                <div class="kwtsms-field">
                    <label for="kwtsms_f_receiver">{__("kwtsms.logs.f.receiver")}</label>
                    <select id="kwtsms_f_receiver" name="receiver">
                        <option value="">{__("kwtsms.logs.f.any")}</option>
                        {foreach $l.options.receivers as $code => $label}
                            <option value="{$code}"{if $f.receiver|default:"" == $code} selected="selected"{/if}>{$label}</option>
                        {/foreach}
                    </select>
                </div>
                <div class="kwtsms-field">
                    <label for="kwtsms_f_phone">{__("kwtsms.logs.f.phone")}</label>
                    <input type="text" id="kwtsms_f_phone" name="phone" value="{$f.phone|default:""}" dir="ltr" inputmode="tel" autocomplete="off" placeholder="98765432">
                </div>
                <div class="kwtsms-field">
                    <label for="kwtsms_f_order">{__("kwtsms.logs.f.order")}</label>
                    <input type="text" id="kwtsms_f_order" name="order" value="{$f.order|default:""}" dir="ltr" inputmode="numeric" autocomplete="off">
                </div>
                {if $l.options.storefronts|count > 1}
                    <div class="kwtsms-field">
                        <label for="kwtsms_f_storefront">{__("kwtsms.logs.f.storefront")}</label>
                        <select id="kwtsms_f_storefront" name="storefront_id">
                            <option value="">{__("kwtsms.logs.f.any")}</option>
                            {foreach $l.options.storefronts as $sid => $sname}
                                <option value="{$sid}"{if $f.storefront_id|default:"" == $sid} selected="selected"{/if}>{$sname}</option>
                            {/foreach}
                        </select>
                    </div>
                {/if}
                {if $l.options.multivendor}
                    <div class="kwtsms-field">
                        <label for="kwtsms_f_vendor">{__("kwtsms.logs.f.vendor")}</label>
                        <input type="text" id="kwtsms_f_vendor" name="company_id" value="{$f.company_id|default:""}" dir="ltr" inputmode="numeric" autocomplete="off">
                    </div>
                {/if}
                <div class="kwtsms-field">
                    <label for="kwtsms_f_test">{__("kwtsms.logs.f.mode")}</label>
                    <select id="kwtsms_f_test" name="is_test">
                        <option value="">{__("kwtsms.logs.f.any")}</option>
                        <option value="N"{if $f.is_test|default:"" == "N"} selected="selected"{/if}>{__("kwtsms.logs.f.live")}</option>
                        <option value="Y"{if $f.is_test|default:"" == "Y"} selected="selected"{/if}>{__("kwtsms.logs.f.test")}</option>
                    </select>
                </div>
                {if $l.tab == "messages"}
                    <div class="kwtsms-field">
                        <label for="kwtsms_f_code">{__("kwtsms.logs.f.code")}</label>
                        <input type="text" id="kwtsms_f_code" name="api_code" value="{$f.api_code|default:""}" dir="ltr" autocomplete="off" placeholder="ERR010">
                    </div>
                    <div class="kwtsms-field">
                        <label for="kwtsms_f_msgid">{__("kwtsms.logs.f.msg_id")}</label>
                        <input type="text" id="kwtsms_f_msgid" name="api_msg_id" value="{$f.api_msg_id|default:""}" dir="ltr" autocomplete="off">
                    </div>
                {else}
                    {if $f.message_id|default:""}<input type="hidden" name="message_id" value="{$f.message_id}">{/if}
                {/if}
            {else}
                <div class="kwtsms-field">
                    <label for="kwtsms_f_level">{__("kwtsms.logs.f.level")}</label>
                    <select id="kwtsms_f_level" name="level">
                        <option value="">{__("kwtsms.logs.f.any")}</option>
                        {foreach $l.options.levels as $level}
                            <option value="{$level}"{if $f.level|default:"" == $level} selected="selected"{/if}>{__("kwtsms.logs.level.`$level`")}</option>
                        {/foreach}
                    </select>
                </div>
                <div class="kwtsms-field">
                    <label for="kwtsms_f_action">{__("kwtsms.logs.f.action")}</label>
                    <input type="text" id="kwtsms_f_action" name="action" value="{$f.action|default:""}" dir="ltr" autocomplete="off" placeholder="logs.cleared">
                </div>
                <div class="kwtsms-field">
                    <label for="kwtsms_f_request">{__("kwtsms.logs.f.request")}</label>
                    <input type="text" id="kwtsms_f_request" name="request_id" value="{$f.request_id|default:""}" dir="ltr" autocomplete="off">
                </div>
            {/if}
            <div class="kwtsms-field kwtsms-field--wide">
                <label for="kwtsms_f_q">{__("kwtsms.logs.f.text")}</label>
                <input type="search" id="kwtsms_f_q" name="q" value="{$f.q|default:""}" autocomplete="off" aria-describedby="kwtsms_f_q_help">
                <p class="muted description" id="kwtsms_f_q_help">{__("kwtsms.logs.f.text_help")}</p>
            </div>
            <div class="kwtsms-field">
                <label for="kwtsms_f_per_page">{__("kwtsms.logs.f.per_page")}</label>
                <select id="kwtsms_f_per_page" name="items_per_page" class="kwtsms-js-autosubmit">
                    {foreach $l.per_page_options as $n}
                        <option value="{$n}"{if $l.search.items_per_page == $n} selected="selected"{/if}>{$n}</option>
                    {/foreach}
                </select>
            </div>
        </div>
        <input type="hidden" name="sort_order" value="{$l.search.sort_order}">
        <div class="kwtsms-actions">
            <input type="submit" class="btn btn-primary" id="kwtsms_logs_apply" value="{__("kwtsms.logs.f.apply")}">
            {if $l.active}<a class="btn" id="kwtsms_logs_reset" href="{$l.reset_url|fn_url}">{__("kwtsms.logs.f.reset")}</a>{/if}
        </div>
    </form>

    {foreach $l.notices as $notice}
        <p class="kwtsms-logs__notice" role="status">{$notice}</p>
    {/foreach}

    {* ---- summary and export ------------------------------------------------------------------------------------- *}
    <div class="kwtsms-logs-bar">
        {* 2026-10-21 17:28 +03: plural forms, the number first (final review M5), task 596 *}
        <p class="kwtsms-logs-bar__count" id="kwtsms_logs_count">{__("kwtsms.logs.count", [0 => $l.search.total_items|fn_html_escape, "[count]" => $l.search.total_items|number_format|fn_html_escape])}</p>
        {if $l.tab == "messages" && $l.can.gateway}
            <a class="btn" id="kwtsms_logs_export" href="{$l.export_url|fn_url}" download>{__("kwtsms.logs.export")}</a>
        {/if}
    </div>

    {* ---- rows --------------------------------------------------------------------------------------------------- *}
    {if !$l.rows}
        <p class="kwtsms-logs-empty" id="kwtsms_logs_empty">{if $l.active}{__("kwtsms.logs.empty_filtered")}{else}{__("kwtsms.logs.empty.`$l.tab`")}{/if}</p>
    {else}
        <div class="kwtsms-table-wrap">
            {* The outcome comes right after the time; under 980 px each row is a card and data-label names its cells. *}
            <table class="table table-middle kwtsms-table kwtsms-logs-table" id="kwtsms_logs_table">
                <thead>
                    <tr>
                        <th scope="col">
                            <a href="{$l.sort_url|fn_url}" id="kwtsms_logs_sort">{__("kwtsms.logs.col.time")} <span aria-hidden="true">{if $l.search.sort_order == "asc"}&#9650;{else}&#9660;{/if}</span><span class="hidden">{if $l.search.sort_order == "asc"}{__("kwtsms.logs.sort_asc")}{else}{__("kwtsms.logs.sort_desc")}{/if}</span></a>
                        </th>
                        {if $l.tab == "debug"}
                            <th scope="col">{__("kwtsms.logs.col.level")}</th>
                            <th scope="col">{__("kwtsms.logs.col.action")}</th>
                            <th scope="col">{__("kwtsms.logs.col.message")}</th>
                            <th scope="col" class="kwtsms-table__num">{__("kwtsms.logs.col.ms")}</th>
                        {else}
                            <th scope="col">{if $l.tab == "messages"}{__("kwtsms.logs.col.status")}{else}{__("kwtsms.logs.col.outcome")}{/if}</th>
                            <th scope="col">{__("kwtsms.logs.col.to")}</th>
                            <th scope="col">{__("kwtsms.logs.col.event")}</th>
                            {if $l.tab == "messages"}
                                <th scope="col" class="kwtsms-table__num">{__("kwtsms.logs.col.credits")}</th>
                                <th scope="col">{__("kwtsms.logs.col.msg_id")}</th>
                            {else}
                                <th scope="col">{__("kwtsms.logs.col.reason")}</th>
                                <th scope="col" class="kwtsms-table__num">{__("kwtsms.logs.col.order")}</th>
                            {/if}
                        {/if}
                        <th scope="col"><span class="hidden">{__("kwtsms.logs.col.details")}</span></th>
                    </tr>
                </thead>
                <tbody>
                    {foreach $l.rows as $r}
                        <tr data-kwtsms-row="{$r.id}"{if $r.is_test} class="kwtsms-logs-row--test"{/if}>
                            <td class="kwtsms-logs-time" data-label="{__("kwtsms.logs.col.time")}">
                                <bdi>{$r.created_at|date_format:$date_time}</bdi>
                                <span class="kwtsms-logs-time__kwt" title="{__("kwtsms.logs.kwtsms_time_tip")}">{__("kwtsms.logs.kwtsms_time")} <bdi dir="ltr">{$r.kwt_time}</bdi></span>
                            </td>
                            {if $l.tab == "debug"}
                                <td data-label="{__("kwtsms.logs.col.level")}"><span class="kwtsms-level kwtsms-level--{$r.level}">{__("kwtsms.logs.level.`$r.level`")}</span></td>
                                <td data-label="{__("kwtsms.logs.col.action")}"><bdi dir="ltr" class="kwtsms-code-tag">{$r.action}</bdi>{if $r.audit} <span class="kwtsms-badge kwtsms-badge--ok">{__("kwtsms.logs.audit")}</span>{/if}</td>
                                <td class="kwtsms-logs-text kwtsms-logs-cell--wide" data-label="{__("kwtsms.logs.col.message")}"><bdi>{$r.message}</bdi></td>
                                <td class="kwtsms-table__num" data-label="{__("kwtsms.logs.col.ms")}"><bdi dir="ltr">{$r.duration_ms}</bdi></td>
                            {else}
                                <td class="kwtsms-logs-outcome" data-label="{if $l.tab == "messages"}{__("kwtsms.logs.col.status")}{else}{__("kwtsms.logs.col.outcome")}{/if}">{include file="addons/kwtsms/views/kwtsms/components/log_outcome.tpl" row=$r}</td>
                                <td data-label="{__("kwtsms.logs.col.to")}">
                                    <span class="kwtsms-logs-to__who">{$r.receiver_label}</span>
                                    {if $l.tab == "messages"}
                                        {foreach $r.numbers as $number}<bdi dir="ltr" class="kwtsms-logs-number">{$number}</bdi> {/foreach}
                                        {if $r.numbers_more}<span class="muted">{__("kwtsms.logs.more_numbers", ["[count]" => $r.numbers_more|fn_html_escape])}</span>{/if}
                                    {else}
                                        <bdi dir="ltr" class="kwtsms-logs-number">{if $r.phone}{$r.phone}{else}{$r.phone_raw}{/if}</bdi>
                                    {/if}
                                </td>
                                <td class="kwtsms-logs-event" data-label="{__("kwtsms.logs.col.event")}">{if $r.event_label}{$r.event_label}{else}<bdi dir="ltr">{$r.event_id}</bdi>{/if}</td>
                                {if $l.tab == "messages"}
                                    <td class="kwtsms-table__num" data-label="{__("kwtsms.logs.col.credits")}"><bdi dir="ltr">{$r.points_charged}</bdi></td>
                                    {* 2026-10-21 14:00 +03: title = the full message id for the clipped cell (cleanup 2 item 7), task 596 *}
                                    <td data-label="{__("kwtsms.logs.col.msg_id")}">{if $r.api_msg_id}<bdi dir="ltr" class="kwtsms-code-tag kwtsms-logs-msgid" title="{$r.api_msg_id}">{$r.api_msg_id}</bdi>{/if}{if $r.api_code} <bdi dir="ltr" class="kwtsms-code-tag">{$r.api_code}</bdi>{/if}</td>
                                {else}
                                    {* 2026-10-21 12:56 +03: the plain-language text of a reason that has one (review M2), task 596 *}
                                    {* 2026-10-21 14:11 +03: the text, then the code in brackets (cleanup 2 item 5), task 596 *}
                                    <td class="kwtsms-logs-text" data-label="{__("kwtsms.logs.col.reason")}"><bdi>{include file="addons/kwtsms/views/kwtsms/components/log_reason.tpl" row=$r}</bdi></td>
                                    <td class="kwtsms-table__num" data-label="{__("kwtsms.logs.col.order")}">{if $r.order_id}<bdi dir="ltr">{$r.order_id}</bdi>{/if}</td>
                                {/if}
                            {/if}
                            <td class="kwtsms-logs-view"><a href="{"kwtsms.log_details?tab=`$l.tab`&id=`$r.id`"|fn_url}" id="kwtsms_view_{$r.id}">{__("kwtsms.logs.view")}</a></td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    {/if}

    {* ---- paging ------------------------------------------------------------------------------------------------- *}
    {if $l.search.pages > 1}
        <nav class="kwtsms-pager" aria-label="{__("kwtsms.logs.pager")}" id="kwtsms_logs_pager">
            {if $l.prev_url}
                <a class="btn" id="kwtsms_page_prev" href="{$l.prev_url|fn_url}">{__("kwtsms.logs.page_prev")}</a>
            {/if}
            <span class="kwtsms-pager__where">{__("kwtsms.logs.page_of", ["[page]" => $l.search.page|fn_html_escape, "[pages]" => $l.search.pages|fn_html_escape])}</span>
            {if $l.next_url}
                <a class="btn" id="kwtsms_page_next" href="{$l.next_url|fn_url}">{__("kwtsms.logs.page_next")}</a>
            {/if}
        </nav>
    {/if}

    {* ---- clear (gateway privilege) and retention ---------------------------------------------------------------- *}
    <section class="kwtsms-section kwtsms-logs-maint" aria-labelledby="kwtsms_logs_maint_title">
        <h4 class="subheader" id="kwtsms_logs_maint_title">{__("kwtsms.logs.keep_title")}</h4>
        <p class="muted" id="kwtsms_logs_retention">
            {if $l.retention.messages > 0}{__("kwtsms.logs.keep.messages_days", ["[days]" => $l.retention.messages|fn_html_escape])}{else}{__("kwtsms.logs.keep.messages_forever")}{/if}
            {__("kwtsms.logs.keep.attempts_debug", ["[attempts]" => $l.retention.attempts|fn_html_escape, "[debug]" => $l.retention.debug|fn_html_escape])}
        </p>
        {if $l.can.gateway}
            <div class="kwtsms-logs-clear" id="kwtsms_logs_clear">
                <form action="{""|fn_url}" method="get" class="kwtsms-inline-form" name="kwtsms_clear_all_form">
                    <input type="hidden" name="dispatch" value="kwtsms.logs">
                    <input type="hidden" name="tab" value="{$l.tab}">
                    <input type="hidden" name="clear" value="all">
                    <input type="submit" class="btn" id="kwtsms_clear_all" value="{__("kwtsms.logs.clear.all", ["[log]" => $l.tabs[$l.tab].title|fn_html_escape])}">
                </form>
                <form action="{""|fn_url}" method="get" class="kwtsms-inline-form kwtsms-logs-clear__older" name="kwtsms_clear_older_form">
                    <input type="hidden" name="dispatch" value="kwtsms.logs">
                    <input type="hidden" name="tab" value="{$l.tab}">
                    <input type="hidden" name="clear" value="older">
                    <label for="kwtsms_clear_days">{__("kwtsms.logs.clear.older_label")}</label>
                    <span class="kwtsms-with-unit">
                        <input type="number" id="kwtsms_clear_days" name="days" value="30" min="1" max="3650" step="1" class="input-mini" dir="ltr">
                        <span class="kwtsms-unit">{__("kwtsms.settings.days")}</span>
                    </span>
                    <input type="submit" class="btn" id="kwtsms_clear_older" value="{__("kwtsms.logs.clear.older")}">
                </form>
                {if $l.active}
                    <form action="{""|fn_url}" method="get" class="kwtsms-inline-form" name="kwtsms_clear_filter_form">
                        <input type="hidden" name="dispatch" value="kwtsms.logs">
                        {foreach $l.query_fields as $field}
                            <input type="hidden" name="{$field.name}" value="{$field.value}">
                        {/foreach}
                        <input type="hidden" name="clear" value="filter">
                        <input type="submit" class="btn" id="kwtsms_clear_filter" value="{__("kwtsms.logs.clear.filter")}">
                    </form>
                {/if}
                <p class="muted description">{__("kwtsms.logs.clear.help")}</p>
            </div>
        {/if}
    </section>
</div>
{/capture}

{include file="common/mainbox.tpl"
    title=__("kwtsms.logs.title")
    content=$smarty.capture.mainbox
    select_storefront=false
}
