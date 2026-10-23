{*
FILE:      design/backend/templates/addons/kwtsms/views/kwtsms/integrations.tpl
CREATED:   2026-10-21 04:09 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      The kwtSMS Integrations page: the phone readiness checks of the store (each with a link to the core setting that
           fixes it), the default country (read-only), the placed status set behind the new-order rule, the Multi-Vendor
           settings (Multi-Vendor only) with the Vendor SMS note and its link to the Vendor SMS page (2026-10-22 22:29 +03,
           Task A8, task 596: was the "not included in 1.0, planned for 1.1" note), the CAPTCHA
           recommendation, the core SMS notifications (Clickatell) warning, and the add-ons that come in a later version.
WHY:       PRD 6.3 and 6.7. Native backend components under the .kwtsms-page root. A check is a word and a badge, never only
           a color. Every value is auto-escaped (no nofilter); every value placed into a __() placeholder is escaped here.
           The Multi-Vendor form exists only when the edition is Multi-Vendor. Links appear only for administrators who
           may open the core page (decided in IntegrationsPage::viewData()).
USAGE:     Rendered by kwtsms.integrations (controllers/backend/kwtsms.php) with $kwtsms_integrations =
           Admin\IntegrationsPage::viewData() plus can_open_settings and can_open_templates; $kwtsms_nav = Navigation::sections().
DEPENDS:   components/navigation.tpl, alerts.less, kwtsms.integrations.* and kwtsms.nav.* language variables.
*}
{$i = $kwtsms_integrations}
{capture name="mainbox"}
<div class="kwtsms-page kwtsms-integrations" id="kwtsms_integrations_page">

    {include file="addons/kwtsms/views/kwtsms/components/navigation.tpl" sections=$kwtsms_nav}

    <p class="kwtsms-intro">{__("kwtsms.integrations.intro")}</p>

    {* ---- orders (core) ---------------------------------------------------------------------------------------------- *}
    <section class="kwtsms-section" id="kwtsms_orders" aria-labelledby="kwtsms_orders_title">
        <h4 class="subheader" id="kwtsms_orders_title">{__("kwtsms.integrations.orders.title")}</h4>

        <p class="kwtsms-lead" id="kwtsms_ready" data-kwtsms-ready="{if $i.ready}Y{else}N{/if}">
            {if $i.ready}{__("kwtsms.integrations.orders.ready")}{else}{__("kwtsms.integrations.orders.not_ready")}{/if}
        </p>

        <ul class="kwtsms-checks" id="kwtsms_checks">
            {foreach $i.checks as $check}
                <li class="kwtsms-check{if !$check.ok} kwtsms-check--fix{/if}" id="kwtsms_check_{$check.id}" data-kwtsms-ok="{if $check.ok}Y{else}N{/if}">
                    <div class="kwtsms-check__state">
                        {if $check.ok}
                            <span class="kwtsms-badge kwtsms-badge--ok">{__("kwtsms.integrations.status.ok")}</span>
                        {else}
                            <span class="kwtsms-badge kwtsms-badge--danger">{__("kwtsms.integrations.status.fix")}</span>
                        {/if}
                    </div>
                    <div class="kwtsms-check__body">
                        <h5 class="kwtsms-check__title">{__("kwtsms.integrations.check.`$check.id`.title")}</h5>
                        <p class="kwtsms-check__text">
                            {if $check.id == "phone_required"}
                                {__("kwtsms.integrations.check.phone_required.`$check.detail`")}
                            {elseif $check.id == "validation_mode"}
                                {if $check.ok}
                                    {__("kwtsms.integrations.check.validation_mode.ok")}
                                {else}
                                    {if $check.detail == "any_digits" || $check.detail == "any_symbols" || $check.detail == "international_format"}
                                        {$mode_label = __("kwtsms.integrations.mode.`$check.detail`")}
                                    {elseif $check.detail}
                                        {$mode_label = $check.detail}
                                    {else}
                                        {$mode_label = __("kwtsms.integrations.mode.unset")}
                                    {/if}
                                    {__("kwtsms.integrations.check.validation_mode.bad", ["[mode]" => $mode_label|escape:"html"])}
                                {/if}
                            {else}
                                {if $check.detail == "unknown"}
                                    {__("kwtsms.integrations.check.default_country.unknown")}
                                {elseif $check.ok}
                                    {__("kwtsms.integrations.check.default_country.ok", ["[code]" => $check.store_code|escape:"html"])}
                                {else}
                                    {__("kwtsms.integrations.check.default_country.mismatch", ["[store]" => $check.store_code|escape:"html", "[addon]" => $check.addon_code|escape:"html"])}
                                {/if}
                            {/if}
                        </p>
                    </div>
                    <div class="kwtsms-check__action">
                        {if $check.link}
                            <a class="btn" href="{$check.link|fn_url}" id="kwtsms_check_link_{$check.id}">
                                {if $check.id == "phone_required"}{if $check.detail == "no_field"}{__("kwtsms.integrations.open.profile_fields")}{else}{__("kwtsms.integrations.open.checkout_fields")}{/if}
                                {elseif $check.id == "validation_mode"}{__("kwtsms.integrations.open.appearance")}
                                {else}{__("kwtsms.integrations.open.checkout")}{/if}
                            </a>
                            {* 2026-10-21 07:05 +03: what the checkout fields link opens (fix round 1, Tasks 1.13 and 1.15, task 596) *}
                            {if $check.id == "phone_required" && $check.detail != "no_field"}
                                <p class="muted description kwtsms-check__note" id="kwtsms_check_link_note_phone_required">{__("kwtsms.integrations.open.checkout_fields_note")}</p>
                            {/if}
                        {/if}
                    </div>
                </li>
            {/foreach}
        </ul>

        <dl class="kwtsms-facts">
            <dt>{__("kwtsms.integrations.country.title")}</dt>
            <dd>
                <input type="text" id="kwtsms_integrations_country" class="kwtsms-readonly-field" value="{$i.default_country_label}"
                       readonly="readonly" aria-readonly="true" autocomplete="off">
                {if $i.can_open_settings}
                    <a class="btn" id="kwtsms_open_settings_country" href="{"kwtsms.settings#kwtsms_default_country"|fn_url}">{__("kwtsms.integrations.open.settings")}</a>
                {/if}
                <p class="muted description">{__("kwtsms.integrations.country.help")}</p>
            </dd>
        </dl>

        <h5 class="kwtsms-subtitle">{__("kwtsms.integrations.rule.title")}</h5>
        <p>{__("kwtsms.integrations.rule.text")}</p>
        <ul class="kwtsms-chips" id="kwtsms_placed_statuses">
            {foreach $i.placed_statuses as $code => $name}
                <li class="kwtsms-chip" data-kwtsms-status="{$code}"><bdi>{$name}</bdi></li>
            {/foreach}
        </ul>
        {if $i.can_open_settings}
            <p class="muted description"><a href="{"kwtsms.settings#kwtsms_general"|fn_url}">{__("kwtsms.integrations.rule.more")}</a></p>
        {/if}
    </section>

    {* 2026-10-23 04:21 +03: Task A13, task 596. What sends SMS in 1.1, each row with its live numbers and a link. *}
    <section class="kwtsms-section" id="kwtsms_areas" aria-labelledby="kwtsms_areas_title">
        <h4 class="subheader" id="kwtsms_areas_title">{__("kwtsms.integrations.area.title")}</h4>
        <p class="muted description">{__("kwtsms.integrations.area.help")}</p>
        <ul class="kwtsms-later-list kwtsms-areas" id="kwtsms_areas_list">
            {foreach $i.areas as $area}
                <li data-kwtsms-area="{$area.id}">
                    <div class="kwtsms-area__head">
                        <strong>{__("kwtsms.integrations.area.`$area.id`.title")}</strong>
                        <span class="kwtsms-badge kwtsms-badge--ok">{__("kwtsms.integrations.area.available")}</span>
                    </div>
                    <div class="kwtsms-area__text">
                    {if !empty($area.quota_off)}
                        {__("kwtsms.integrations.area.vendors.text_off", $area.params)}
                    {else}
                        {__("kwtsms.integrations.area.`$area.id`.text", $area.params)}
                    {/if}
                    {* 2026-10-23 08:41 +0300: cleanup CA2-8 (A13 minor 4), task 596. *}
                    {if !empty($area.plans) && empty($area.quota_off)} {__("kwtsms.integrations.area.vendors.plans")}{/if}
                    </div>
                    <div class="kwtsms-area__action">
                    {if $area.link}<a class="btn kwtsms-area__link" id="kwtsms_area_link_{$area.id}" href="{$area.link|fn_url}">{__("kwtsms.integrations.area.open")}</a>{/if}
                    </div>
                </li>
            {/foreach}
        </ul>
    </section>

    {* ---- Multi-Vendor: only in Multi-Vendor ------------------------------------------------------------------------- *}
    {if $i.is_multivendor}
        {$m = $i.mve.values}
        <form action="{""|fn_url}" method="post" name="kwtsms_integrations_form" id="kwtsms_integrations_form" class="form-horizontal form-edit">
            <section class="kwtsms-section" id="kwtsms_mve" aria-labelledby="kwtsms_mve_title">
                <h4 class="subheader" id="kwtsms_mve_title">{__("kwtsms.integrations.mve.title")}</h4>
                <p class="muted description">{__("kwtsms.integrations.mve.help")}</p>

                <div class="control-group">
                    <label class="control-label" for="kwtsms_mve_customer_new_order">{__("kwtsms.integrations.mve.customer_new_order")}:</label>
                    <div class="controls">
                        <select id="kwtsms_mve_customer_new_order" name="kwtsms_integrations[mve_customer_new_order]" class="kwtsms-select-wide">
                            {foreach $i.mve.options.mve_customer_new_order as $option}
                                <option value="{$option}"{if $m.mve_customer_new_order == $option} selected="selected"{/if}>{__("kwtsms.integrations.mve.customer_new_order.`$option`")}</option>
                            {/foreach}
                        </select>
                        <p class="muted description">{__("kwtsms.integrations.mve.customer_new_order.help")}</p>
                    </div>
                </div>

                <div class="control-group">
                    <label class="control-label" for="kwtsms_mve_admin_new_order">{__("kwtsms.integrations.mve.admin_new_order")}:</label>
                    <div class="controls">
                        <select id="kwtsms_mve_admin_new_order" name="kwtsms_integrations[mve_admin_new_order]" class="kwtsms-select-wide">
                            {foreach $i.mve.options.mve_admin_new_order as $option}
                                <option value="{$option}"{if $m.mve_admin_new_order == $option} selected="selected"{/if}>{__("kwtsms.integrations.mve.admin_new_order.`$option`")}</option>
                            {/foreach}
                        </select>
                    </div>
                </div>

                <div class="control-group">
                    <label class="control-label" for="kwtsms_mve_customer_status_scope">{__("kwtsms.integrations.mve.customer_status_scope")}:</label>
                    <div class="controls">
                        <select id="kwtsms_mve_customer_status_scope" name="kwtsms_integrations[mve_customer_status_scope]" class="kwtsms-select-wide">
                            {foreach $i.mve.options.mve_customer_status_scope as $option}
                                <option value="{$option}"{if $m.mve_customer_status_scope == $option} selected="selected"{/if}>{__("kwtsms.integrations.mve.customer_status_scope.`$option`")}</option>
                            {/foreach}
                        </select>
                        <p class="muted description">{__("kwtsms.integrations.mve.customer_status_scope.help")}</p>
                    </div>
                </div>

                <div class="control-group">
                    <label class="control-label" for="kwtsms_mve_vendor_triggered_sms">{__("kwtsms.integrations.mve.vendor_triggered")}:</label>
                    <div class="controls">
                        <input type="hidden" name="kwtsms_integrations[mve_vendor_triggered_sms]" value="N">
                        <label class="checkbox"><input type="checkbox" id="kwtsms_mve_vendor_triggered_sms" name="kwtsms_integrations[mve_vendor_triggered_sms]" value="Y"{if $m.mve_vendor_triggered_sms == "Y"} checked="checked"{/if}> {__("kwtsms.integrations.mve.vendor_triggered.label")}</label>
                        <p class="muted description">{__("kwtsms.integrations.mve.vendor_triggered.help")}</p>
                    </div>
                </div>

                {* 2026-10-22 22:28 +03: Task A8, task 596. Vendor SMS exists in 1.1: the note says where it is set up, with a link. *}
                <p class="kwtsms-later" id="kwtsms_vendor_sms_note">{__("kwtsms.integrations.mve.vendor_sms")}
                    {if $i.vendor_sms_link}<a class="btn" id="kwtsms_vendor_sms_link" href="{$i.vendor_sms_link|fn_url}">{__("kwtsms.integrations.mve.vendor_sms_open")}</a>{/if}</p>
            </section>
        </form>
    {/if}

    {* ---- CAPTCHA ---------------------------------------------------------------------------------------------------- *}
    <section class="kwtsms-section" id="kwtsms_captcha" aria-labelledby="kwtsms_captcha_title">
        <h4 class="subheader" id="kwtsms_captcha_title">{__("kwtsms.integrations.captcha.title")}</h4>
        <div class="kwtsms-check{if !$i.captcha.checkout} kwtsms-check--fix{/if}" data-kwtsms-ok="{if $i.captcha.checkout}Y{else}N{/if}">
            <div class="kwtsms-check__state">
                {if $i.captcha.checkout}
                    <span class="kwtsms-badge kwtsms-badge--ok">{__("kwtsms.integrations.status.ok")}</span>
                {else}
                    <span class="kwtsms-badge kwtsms-badge--danger">{__("kwtsms.integrations.status.fix")}</span>
                {/if}
            </div>
            <div class="kwtsms-check__body">
                <p class="kwtsms-check__text" id="kwtsms_captcha_text">
                    {if $i.captcha.checkout}{__("kwtsms.integrations.captcha.on")}{else}{__("kwtsms.integrations.captcha.off")}{/if}
                </p>
                <p class="muted description" id="kwtsms_recaptcha_state">
                    {if $i.captcha.recaptcha}{__("kwtsms.integrations.captcha.recaptcha_on")}{else}{__("kwtsms.integrations.captcha.recaptcha_off")}{/if}
                </p>
            </div>
            <div class="kwtsms-check__action">
                {if $i.captcha.link}<a class="btn" id="kwtsms_captcha_link" href="{$i.captcha.link|fn_url}">{__("kwtsms.integrations.open.captcha")}</a>{/if}
            </div>
        </div>
    </section>

    {* ---- core SMS notifications (Clickatell) ------------------------------------------------------------------------ *}
    <section class="kwtsms-section" id="kwtsms_clickatell" aria-labelledby="kwtsms_clickatell_title">
        <h4 class="subheader" id="kwtsms_clickatell_title">{__("kwtsms.integrations.clickatell.title")}</h4>
        {if $i.clickatell.active}
            <div class="alert alert-warning kwtsms-alert" id="kwtsms_clickatell_warning" role="alert">
                <p>{__("kwtsms.integrations.clickatell.active")}</p>
                {if $i.clickatell.link}<a class="btn" id="kwtsms_clickatell_link" href="{$i.clickatell.link|fn_url}">{__("kwtsms.integrations.open.addons")}</a>{/if}
            </div>
        {else}
            <p class="muted" id="kwtsms_clickatell_ok">{__("kwtsms.integrations.clickatell.inactive")}</p>
        {/if}
    </section>

    {* ---- other add-ons ----------------------------------------------------------------------------------------------- *}
    <section class="kwtsms-section" id="kwtsms_later" aria-labelledby="kwtsms_later_title">
        <h4 class="subheader" id="kwtsms_later_title">{__("kwtsms.integrations.later.title")}</h4>
        <p class="muted description">{__("kwtsms.integrations.later.help")}</p>
        <ul class="kwtsms-later-list">
            {foreach $i.later as $row}
                <li data-kwtsms-addon="{$row.id}">
                    {__("kwtsms.integrations.later.`$row.id`")}
                    {if $row.active}<span class="kwtsms-badge">{__("kwtsms.integrations.later.installed")}</span>{/if}
                </li>
            {/foreach}
        </ul>
    </section>
</div>
{/capture}

{capture name="buttons"}
    {if $kwtsms_integrations.is_multivendor}
        {include file="buttons/save.tpl" but_name="dispatch[kwtsms.update_integrations]" but_role="submit-link" but_target_form="kwtsms_integrations_form"}
    {/if}
{/capture}

{include file="common/mainbox.tpl"
    title=__("kwtsms.integrations.title")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
    select_storefront=false
}
