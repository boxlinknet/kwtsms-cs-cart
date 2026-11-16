{*
FILE:      design/backend/templates/addons/kwtsms/views/kwtsms/components/event_conditions.tpl
CREATED:   2026-11-16 13:55 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      The "Conditions" dialog of one Templates row: minimum order total, payment methods, shipping methods and, for
           an admin or vendor row, "hold during quiet hours". A native details element with a form (POST
           kwtsms.update_event_conditions).
WHY:       Task M3.7 (B7, P3-68): send an SMS only for orders that match, or hold a staff alert until the quiet window ends.
USAGE:     {include file="addons/kwtsms/views/kwtsms/components/event_conditions.tpl" row=$row t=$t row_label=$row_label receiver_label=$receiver_label}
           $row.conditions: min_total, payment_ids, shipping_ids, staff_quiet, has, can_quiet; $t.conditions_options:
           payments and shippings (id => name); $t.storefront_id the scope (0 = All storefronts). Auto-escaped.
DEPENDS:   templates.less (.kwtsms-cond).
*}
{$c = $row.conditions}
<details class="kwtsms-cond{if $c.has} is-set{/if}" id="kwtsms_cond_{$row.dom_id}">
    <summary class="kwtsms-cond__summary">{__("kwtsms.conditions.button")}{if $c.has} <span class="kwtsms-badge kwtsms-badge--cond">{__("kwtsms.conditions.on")}</span>{/if}</summary>
    <form action="{""|fn_url}" method="post" name="kwtsms_cond_form_{$row.dom_id}" class="kwtsms-cond__form" autocomplete="off">
        <input type="hidden" name="kwtsms_tpl[event_id]" value="{$row.event_id}">
        <input type="hidden" name="kwtsms_tpl[receiver]" value="{$row.receiver}">
        <input type="hidden" name="kwtsms_tpl[lang]" value="{$t.lang}">
        <input type="hidden" name="kwtsms_tpl[storefront_id]" value="{$t.storefront_id}">
        <p class="muted kwtsms-cond__help">{__("kwtsms.conditions.help")}{if $t.storefront_id} {__("kwtsms.conditions.help_storefront", ["[storefront]" => $t.storefront_name|fn_html_escape])}{/if}</p>
        <div class="kwtsms-cond__field">
            <label for="kwtsms_cond_min_{$row.dom_id}">{__("kwtsms.conditions.min_total")}</label>
            <input type="text" inputmode="decimal" dir="ltr" id="kwtsms_cond_min_{$row.dom_id}" name="kwtsms_tpl[min_total]"
                   value="{$c.min_total}" maxlength="14" class="kwtsms-cond__min" placeholder="0.000"
                   pattern="[0-9]{ldelim}1,10{rdelim}(\.[0-9]{ldelim}1,3{rdelim})?" autocomplete="off"
                   aria-describedby="kwtsms_cond_min_hint_{$row.dom_id}">
            <span class="muted kwtsms-cond__hint" id="kwtsms_cond_min_hint_{$row.dom_id}">{__("kwtsms.conditions.min_total_hint")}</span>
        </div>
        <fieldset class="kwtsms-cond__field">
            <legend>{__("kwtsms.conditions.payments")}</legend>
            {foreach $t.conditions_options.payments as $pid => $pname}
                <label class="kwtsms-cond__check"><input type="checkbox" name="kwtsms_tpl[payment_ids][]" value="{$pid}"{if in_array($pid, $c.payment_ids)} checked="checked"{/if}> <bdi>{$pname}</bdi></label>
            {foreachelse}
                <span class="muted">{__("kwtsms.conditions.none_available")}</span>
            {/foreach}
            <span class="muted kwtsms-cond__hint">{__("kwtsms.conditions.any")}</span>
        </fieldset>
        <fieldset class="kwtsms-cond__field">
            <legend>{__("kwtsms.conditions.shippings")}</legend>
            {foreach $t.conditions_options.shippings as $sid => $sname}
                <label class="kwtsms-cond__check"><input type="checkbox" name="kwtsms_tpl[shipping_ids][]" value="{$sid}"{if in_array($sid, $c.shipping_ids)} checked="checked"{/if}> <bdi>{$sname}</bdi></label>
            {foreachelse}
                <span class="muted">{__("kwtsms.conditions.none_available")}</span>
            {/foreach}
            <span class="muted kwtsms-cond__hint">{__("kwtsms.conditions.any")}</span>
        </fieldset>
        {if $c.can_quiet}
            <div class="kwtsms-cond__field">
                <label class="kwtsms-cond__check"><input type="checkbox" name="kwtsms_tpl[staff_quiet]" value="1"{if $c.staff_quiet} checked="checked"{/if}> {__("kwtsms.conditions.staff_quiet")}</label>
                <span class="muted kwtsms-cond__hint">{__("kwtsms.conditions.staff_quiet_hint")}</span>
            </div>
        {/if}
        <div class="kwtsms-actions">
            <button type="submit" class="btn btn-primary" name="dispatch[kwtsms.update_event_conditions]" value="1"
                    aria-label="{__("kwtsms.conditions.save_aria", ["[row]" => "`$row_label|fn_html_escape` / `$receiver_label|fn_html_escape`"])}">{__("kwtsms.conditions.save")}</button>
        </div>
    </form>
</details>
