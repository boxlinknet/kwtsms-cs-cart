{*
FILE:      design/backend/templates/addons/kwtsms/views/kwtsms/vendors.tpl
CREATED:   2026-10-22 22:27 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      The kwtSMS Vendor SMS page (Multi-Vendor only): whether vendor SMS are switched on, the monthly quota (the
           default and one limit per vendor plan when the Vendor plans add-on is active), and one row per vendor with its
           profile phone, the number used, the approval state, the quota used this month and the Approve / Revoke buttons.
WHY:       Phase 2 Task A8 (SafatMall F4, P2-5, P2-6). A vendor's SMS go to one number that a root administrator approved,
           and each vendor has a monthly number of recipients. Native backend components under the .kwtsms-page root. Every
           value is auto-escaped (no nofilter); numbers are wrapped in <bdi dir="ltr">; the approval state is a word, not
           only a colour. The security hash is added to the forms by the core output filter.
USAGE:     Rendered by kwtsms.vendors (controllers/backend/kwtsms.php) with $kwtsms_vendors =
           Admin\VendorsPage::viewData() and $kwtsms_nav = Admin\Navigation::sections().
DEPENDS:   components/navigation.tpl, vendors.less, kwtsms.vendors.* and kwtsms.nav.* language variables.
*}
{* 2026-10-23 10:00 +03: Task A14 fix, task 596. The core Smarty runs with escape_html on and DROPS |escape:"html" inside an expression (a __() placeholder
   array, a backtick interpolation): the value stayed raw. A storefront name printed raw in the Settings aria-label and the Templates hint
   (stored XSS, tests/Store/A14XssStoreTest). Every value placed into a __() placeholder now goes through |fn_html_escape, which escapes
   < > & and both quotes (tests/Unit/TemplateEscapeTest keeps the dropped modifier out). *}
{$v = $kwtsms_vendors}
{capture name="mainbox"}
<div class="kwtsms-page kwtsms-vendors" id="kwtsms_vendors_page">

    {include file="addons/kwtsms/views/kwtsms/components/navigation.tpl" sections=$kwtsms_nav}

    <p class="kwtsms-intro">{__("kwtsms.vendors.intro")}</p>

    {if !$v.sms_enabled}
        <div class="alert alert-info kwtsms-alert" id="kwtsms_vendors_sms_off"><p>{__("kwtsms.vendors.sms_off")}</p></div>
    {/if}

    {* ---- the switches (read-only here, they live on the Templates page) ----------------------------------------- *}
    <section class="kwtsms-section" id="kwtsms_vendors_switches" aria-labelledby="kwtsms_vendors_switches_title">
        <h4 class="subheader" id="kwtsms_vendors_switches_title">{__("kwtsms.vendors.switches.title")}</h4>
        <dl class="kwtsms-facts">
            <dt>{__("kwtsms.vendors.switches.placed")}</dt>
            <dd id="kwtsms_vendors_switch_placed">
                {if $v.switches.placed === true}<span class="kwtsms-badge kwtsms-badge--ok">{__("kwtsms.status.on")}</span>{else}<span class="kwtsms-badge">{__("kwtsms.status.off")}</span>{/if}
            </dd>
            <dt>{__("kwtsms.vendors.switches.low_stock")}</dt>
            <dd id="kwtsms_vendors_switch_low_stock">
                {if $v.switches.low_stock === true}<span class="kwtsms-badge kwtsms-badge--ok">{__("kwtsms.status.on")}</span>{else}<span class="kwtsms-badge">{__("kwtsms.status.off")}</span>{/if}
            </dd>
        </dl>
        {* 2026-10-23 08:34 +0300: cleanup CA2-4 (A8 copy), task 596. "Both are off" is only true while both are off. *}
        {if $v.switches_all_off}<p class="muted description" id="kwtsms_vendors_switches_help">{__("kwtsms.vendors.switches.help")}</p>{/if}
        {if $v.can_open_templates}<p><a class="btn" id="kwtsms_vendors_open_templates" href="{"kwtsms.templates"|fn_url}">{__("kwtsms.vendors.switches.open")}</a></p>{/if}
    </section>

    {* ---- monthly quota ---------------------------------------------------------------------------------------------- *}
    <form action="{""|fn_url}" method="post" name="kwtsms_quota_form" id="kwtsms_quota_form" class="form-horizontal form-edit" autocomplete="off">
        <input type="hidden" name="kwtsms_page" value="{$v.search.page}">
        <section class="kwtsms-section" id="kwtsms_vendors_quota" aria-labelledby="kwtsms_vendors_quota_title">
            <h4 class="subheader" id="kwtsms_vendors_quota_title">{__("kwtsms.vendors.quota.title")}</h4>
            <p class="muted description">{__("kwtsms.vendors.quota.help")}</p>

            <div class="control-group">
                <label class="control-label" for="kwtsms_quota_default">{__("kwtsms.vendors.quota.default")}:</label>
                <div class="controls">
                    <input type="text" id="kwtsms_quota_default" name="kwtsms_quota[default]" value="{$v.default_quota}" class="input-small kwtsms-number" dir="ltr"
                           inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off">
                    <p class="muted description">{__("kwtsms.vendors.quota.default_help", ["[max]" => $v.max_quota])}</p>
                </div>
            </div>

            {if $v.plans_active && $v.plans}
                <h5 class="kwtsms-subtitle" id="kwtsms_vendors_plans_title">{__("kwtsms.vendors.plans.title")}</h5>
                <p class="muted description">{__("kwtsms.vendors.plans.help")}</p>
                {foreach $v.plans as $plan}
                    <div class="control-group">
                        {* 2026-10-22 23:56 +03: Task A8 fix round 1 (I1), task 596. The plan name is isolated (bdi) so the colon stays on the label side in RTL. *}
                        <label class="control-label" for="kwtsms_quota_plan_{$plan.plan_id}"><bdi>{$plan.name}</bdi>:</label>
                        <div class="controls">
                            <input type="text" id="kwtsms_quota_plan_{$plan.plan_id}" name="kwtsms_quota[plans][{$plan.plan_id}]" value="{if $plan.limit !== null}{$plan.limit}{/if}"
                                   class="input-small kwtsms-number" dir="ltr" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off"
                                   placeholder="{$v.default_quota}">
                        </div>
                    </div>
                {/foreach}
            {/if}

            <div class="control-group">
                <div class="controls kwtsms-actions">
                    <button type="submit" class="btn btn-primary" id="kwtsms_quota_save" name="dispatch[kwtsms.update_vendor_quotas]" value="1">{__("kwtsms.vendors.quota.save")}</button>
                </div>
            </div>
        </section>
    </form>

    {* ---- the vendors ------------------------------------------------------------------------------------------------- *}
    <section class="kwtsms-section" id="kwtsms_vendors_list" aria-labelledby="kwtsms_vendors_list_title">
        <h4 class="subheader" id="kwtsms_vendors_list_title">{__("kwtsms.vendors.list.title")}</h4>
        <p class="muted description">{__("kwtsms.vendors.list.help")}</p>
        {if $v.rows}
            <div class="table-responsive kwtsms-table-wrap">
                <table class="table table-middle kwtsms-table" id="kwtsms_vendors_table">
                    <thead>
                        <tr>
                            <th>{__("kwtsms.vendors.col.vendor")}</th>
                            <th>{__("kwtsms.vendors.col.phone")}</th>
                            <th>{__("kwtsms.vendors.col.number")}</th>
                            <th>{__("kwtsms.vendors.col.status")}</th>
                            <th>{__("kwtsms.vendors.col.used")}</th>
                            <th>{__("kwtsms.vendors.col.action")}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach $v.rows as $row}
                            <tr class="kwtsms-row" id="kwtsms_vendor_{$row.company_id}" data-kwtsms-company="{$row.company_id}" data-kwtsms-status="{$row.status}">
                                <td data-th="{__("kwtsms.vendors.col.vendor")}">
                                    <span class="kwtsms-row__name">{$row.name}</span>
                                    {if !$row.active}<span class="kwtsms-badge">{__("kwtsms.vendors.not_active")}</span>{/if}
                                </td>
                                <td data-th="{__("kwtsms.vendors.col.phone")}">{if $row.phone}<bdi dir="ltr">{$row.phone}</bdi>{else}-{/if}</td>
                                <td data-th="{__("kwtsms.vendors.col.number")}">{* 2026-10-23 08:34 +0300: cleanup CA2-4 (A8 copy), task 596. A changed row shows the approved number and says the new one is not used. *}
                                    {if $row.number}<bdi dir="ltr">+{$row.number}</bdi>{else}-{/if}
                                    {if $row.number_changed}<span class="kwtsms-reason muted kwtsms-number-changed">{__("kwtsms.vendors.number_changed")}</span>{/if}</td>
                                <td data-th="{__("kwtsms.vendors.col.status")}">
                                    {if $row.status == "approved"}
                                        <span class="kwtsms-badge kwtsms-badge--ok">{__("kwtsms.vendors.status.approved")}</span>
                                    {elseif $row.status == "changed"}
                                        <span class="kwtsms-badge kwtsms-badge--danger">{__("kwtsms.vendors.status.changed")}</span>
                                    {elseif $row.status == "no_phone"}
                                        <span class="kwtsms-badge">{__("kwtsms.vendors.status.no_phone")}</span>
                                    {elseif $row.status == "invalid"}
                                        <span class="kwtsms-badge kwtsms-badge--danger">{__("kwtsms.vendors.status.invalid")}</span>
                                        {if $row.reason}<span class="kwtsms-reason muted">{__("kwtsms.alerts.reason.`$row.reason`", ["[max]" => 0])}</span>{/if}
                                    {else}
                                        <span class="kwtsms-badge">{__("kwtsms.vendors.status.none")}</span>
                                    {/if}
                                </td>
                                <td data-th="{__("kwtsms.vendors.col.used")}">
                                    {if $row.limit > 0}
                                        <span class="kwtsms-quota{if $row.used >= $row.limit} is-full{/if}">{__("kwtsms.vendors.used_of", ["[used]" => $row.used, "[limit]" => $row.limit])}</span>
                                    {else}
                                        <span class="kwtsms-badge">{__("kwtsms.vendors.quota_off")}</span>
                                    {/if}
                                </td>
                                <td data-th="{__("kwtsms.vendors.col.action")}">
                                    <form action="{""|fn_url}" method="post" name="kwtsms_vendor_form_{$row.company_id}" class="kwtsms-inline-form">
                                        <input type="hidden" name="kwtsms_vendor[company_id]" value="{$row.company_id}">
                                        <input type="hidden" name="kwtsms_page" value="{$v.search.page}">
                                        {if $row.can_approve}
                                            <button type="submit" class="btn" id="kwtsms_approve_{$row.company_id}" name="dispatch[kwtsms.update_vendor_phone.approve]" value="1"
                                                    aria-label="{__("kwtsms.vendors.approve_aria", ["[vendor]" => $row.name|fn_html_escape])}">{if $row.status == "approved"}{__("kwtsms.vendors.approve_again")}{else}{__("kwtsms.vendors.approve")}{/if}</button>
                                        {/if}
                                        {if $row.can_revoke}
                                            <button type="submit" class="btn" id="kwtsms_revoke_{$row.company_id}" name="dispatch[kwtsms.update_vendor_phone.revoke]" value="1"
                                                    aria-label="{__("kwtsms.vendors.revoke_aria", ["[vendor]" => $row.name|fn_html_escape])}">{__("kwtsms.vendors.revoke")}</button>
                                        {/if}
                                    </form>
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
            {if $v.search.pages > 1}
                <nav class="kwtsms-pager" aria-label="{__("kwtsms.logs.pager")}" id="kwtsms_vendors_pager">
                    {if $v.prev_url}<a class="btn" id="kwtsms_vendors_prev" href="{$v.prev_url|fn_url}">{__("kwtsms.logs.page_prev")}</a>{/if}
                    <span class="kwtsms-pager__where">{__("kwtsms.logs.page_of", ["[page]" => $v.search.page, "[pages]" => $v.search.pages])}</span>
                    {if $v.next_url}<a class="btn" id="kwtsms_vendors_next" href="{$v.next_url|fn_url}">{__("kwtsms.logs.page_next")}</a>{/if}
                </nav>
            {/if}
        {else}
            <p class="muted" id="kwtsms_vendors_empty">{__("kwtsms.vendors.empty")}</p>
        {/if}
    </section>
</div>
{/capture}

{include file="common/mainbox.tpl"
    title=__("kwtsms.vendors.title")
    content=$smarty.capture.mainbox
    select_storefront=false
}
