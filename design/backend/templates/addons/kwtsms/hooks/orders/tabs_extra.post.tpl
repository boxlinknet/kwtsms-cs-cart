{*
FILE:      design/backend/templates/addons/kwtsms/hooks/orders/tabs_extra.post.tpl
CREATED:   2026-10-21 05:59 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      Content of the read-only "SMS" tab of the admin order page: every SMS attempt of the order and of its parent
           (and of the child orders of a checkout), newest first, with the outcome, the reason and a link to the
           attempt in the kwtSMS Logs.
WHY:       PRD 6.8 and 4.3. Hook orders:tabs_extra (views/orders/details.tpl of CS-Cart 4.21.2, after the order form and
           inside the tabsbox capture), so nothing here is part of the order's own form; the tab header comes from
           controllers/backend/orders.post.php, which assigns $kwtsms_order_sms only to an administrator allowed to open
           the kwtSMS Logs. Read only: no form, no button that writes. Auto-escaped throughout.
USAGE:     Rendered by core on orders.details.
DEPENDS:   $kwtsms_order_sms (Admin\OrderSmsTab::viewData()), components/log_outcome.tpl, logs.less.
*}
{if $kwtsms_order_sms}
    {$o = $kwtsms_order_sms}
    {$date_time = "`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}
    <div class="{if $selected_section !== "kwtsms_sms"}hidden {/if}kwtsms-page kwtsms-order-sms" id="content_kwtsms_sms" data-kwtsms-order="{$o.order_id}" data-kwtsms-parent="{$o.parent_id}" data-kwtsms-rows="{$o.rows|count}">
        <p class="muted">{if $o.parent_id}{__("kwtsms.order_tab.intro_parent", ["[parent]" => $o.parent_id])}{else}{__("kwtsms.order_tab.intro")}{/if}</p>
        {if !$o.rows}
            <p class="kwtsms-logs-empty" id="kwtsms_order_sms_none">{__("kwtsms.order_tab.none")}</p>
        {else}
            <div class="kwtsms-table-wrap">
                <table class="table table-middle kwtsms-table kwtsms-logs-table" id="kwtsms_order_sms_table">
                    <thead>
                        <tr>
                            <th scope="col">{__("kwtsms.logs.col.time")}</th>
                            <th scope="col">{__("kwtsms.logs.col.outcome")}</th>
                            <th scope="col">{__("kwtsms.logs.col.to")}</th>
                            <th scope="col">{__("kwtsms.logs.col.event")}</th>
                            <th scope="col">{__("kwtsms.logs.col.order")}</th>
                            <th scope="col">{__("kwtsms.logs.col.reason")}</th>
                            <th scope="col"><span class="hidden">{__("kwtsms.logs.col.details")}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach $o.rows as $r}
                            <tr data-kwtsms-attempt="{$r.id}" data-kwtsms-order="{$r.order_id}">
                                <td class="kwtsms-logs-time" data-label="{__("kwtsms.logs.col.time")}">
                                    <bdi>{$r.created_at|date_format:$date_time}</bdi>
                                    <span class="kwtsms-logs-time__kwt">{__("kwtsms.logs.kwtsms_time")} <bdi dir="ltr">{$r.kwt_time}</bdi></span>
                                </td>
                                <td class="kwtsms-logs-outcome" data-label="{__("kwtsms.logs.col.outcome")}">{include file="addons/kwtsms/views/kwtsms/components/log_outcome.tpl" row=$r}</td>
                                <td data-label="{__("kwtsms.logs.col.to")}"><span class="kwtsms-logs-to__who">{$r.receiver_label}</span> <bdi dir="ltr" class="kwtsms-logs-number">{if $r.phone}{$r.phone}{else}{$r.phone_raw}{/if}</bdi></td>
                                <td class="kwtsms-logs-event" data-label="{__("kwtsms.logs.col.event")}">{if $r.event_label}{$r.event_label}{else}<bdi dir="ltr">{$r.event_id}</bdi>{/if}</td>
                                <td data-label="{__("kwtsms.logs.col.order")}"><bdi dir="ltr">{$r.order_id}</bdi>{if $r.of_parent} <span class="muted">{__("kwtsms.order_tab.parent")}</span>{elseif $r.of_child} <span class="muted">{__("kwtsms.order_tab.child")}</span>{/if}</td>
                                {* 2026-10-21 12:56 +03: the plain-language text of a reason that has one (review M2), task 596 *}
                                {* 2026-10-21 14:11 +03: the text, then the code in brackets (cleanup 2 item 5), task 596 *}
                                <td class="kwtsms-logs-text" data-label="{__("kwtsms.logs.col.reason")}"><bdi>{include file="addons/kwtsms/views/kwtsms/components/log_reason.tpl" row=$r}</bdi></td>
                                <td class="kwtsms-logs-view"><a href="{"kwtsms.log_details?tab=attempts&id=`$r.id`"|fn_url}">{__("kwtsms.logs.view")}</a></td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
            {if $o.more}<p class="muted" id="kwtsms_order_sms_more"><a href="{"kwtsms.logs?tab=attempts&order=`$o.order_id`"|fn_url}">{__("kwtsms.order_tab.more")}</a></p>{/if}
        {/if}
    <!--content_kwtsms_sms--></div>
{/if}
