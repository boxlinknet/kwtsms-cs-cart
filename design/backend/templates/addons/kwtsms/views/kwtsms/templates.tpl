{*
FILE:      design/backend/templates/addons/kwtsms/views/kwtsms/templates.tpl
CREATED:   2026-10-21 03:24 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      The kwtSMS Templates page: language tabs, the SMS brand-name editor of the tab language (2026-10-21 05:18 +03, Task 1.12 fix
           round 1 item 3, ruling R33), the matrix (one row per order status and receiver, with the SMS switch,
           the text, a badge and the length of each row) and, under the row being edited, the editor with the live counter
           and SMS meter, the worst-case estimate, placeholder chips, the advice, the preview and Save / Reset.
WHY:       PRD 6.4 and 7. Native backend components under the .kwtsms-page root. Every value is auto-escaped (no nofilter);
           text of a template is only ever printed inside <textarea> or as an escaped snippet, and the script shows the
           preview with textContent. Data for the script sits in data- attributes. The switch of a row is its own small
           form (forms never nest); the security hash is added to every form by the core output filter. The text area
           takes its direction and language from the language of the tab, not from the admin language; ids, order numbers
           and placeholders are wrapped in <bdi>.
USAGE:     Rendered by kwtsms.templates (controllers/backend/kwtsms.php) with $kwtsms_templates =
           Admin\TemplatesPage::viewData() and $kwtsms_nav = Admin\Navigation::sections().
DEPENDS:   components/navigation.tpl, templates.less, js/addons/kwtsms/{counter,templates}.js (loaded by the hooks),
           kwtsms.templates.* and kwtsms.status.* language variables.
*}
{$t = $kwtsms_templates}
{$e = $t.editor}
{capture name="mainbox"}
<div class="kwtsms-page kwtsms-templates" id="kwtsms_templates_page"
     data-kwtsms-config="{$t.script_config|json_encode}"
     data-kwtsms-max-pages="{$t.max_pages}">

    {include file="addons/kwtsms/views/kwtsms/components/navigation.tpl" sections=$kwtsms_nav}

    <p class="kwtsms-intro muted">{__("kwtsms.templates.intro")}</p>

    {if !$t.sms_enabled}
        <div class="alert alert-info kwtsms-alert" id="kwtsms_sms_off">
            <p>{__("kwtsms.templates.sms_off")}</p>
            {if $t.can_open_settings}
                <a class="btn" href="{"kwtsms.settings"|fn_url}">{__("kwtsms.templates.open_settings")}</a>
            {/if}
        </div>
    {/if}

    {* ---- language tabs and "Load default texts" ------------------------------------------------------------------- *}
    <div class="kwtsms-langbar">
        <ul class="kwtsms-langs" aria-label="{__("kwtsms.templates.languages")}">
            {foreach $t.languages as $code => $language}
                <li class="{if $code == $t.lang}is-active{/if}" id="kwtsms_lang_{$code}">
                    <a href="{"kwtsms.templates?lang=`$code`"|fn_url}" lang="{$code}" dir="{$language.direction}"{if $code == $t.lang} aria-current="page"{/if}>{$language.name}</a>
                </li>
            {/foreach}
        </ul>
        <form action="{""|fn_url}" method="post" name="kwtsms_load_defaults_form" class="kwtsms-inline-form">
            <input type="hidden" name="kwtsms_tpl[lang]" value="{$t.lang}">
            <input type="submit" class="btn" id="kwtsms_load_defaults" name="dispatch[kwtsms.load_template_defaults]"
                   value="{__("kwtsms.templates.load_defaults", ["[language]" => $t.lang_name])}"
                   {if !$t.has_shipped}disabled="disabled"{/if}>
        </form>
    </div>
    <p class="kwtsms-hint-line muted">
        {if $t.has_shipped}{__("kwtsms.templates.load_defaults_help")}{else}{__("kwtsms.templates.no_shipped", ["[language]" => $t.default_lang_name])}{/if}
    </p>

    {* ---- SMS brand name: what the store_name placeholder is, one entry per storefront, for the language of the tab ------------ *}
    <section class="kwtsms-brand" id="kwtsms_brand" aria-labelledby="kwtsms_brand_title">
        <h4 class="subheader" id="kwtsms_brand_title">{__("kwtsms.templates.brand_title")}</h4>
        <p class="muted kwtsms-brand__help">{__("kwtsms.templates.brand_help", ["[language]" => $t.lang_name, "[max]" => $t.brand.max])}</p>
        {if $t.brand.storefronts}
            <form action="{""|fn_url}" method="post" name="kwtsms_brand_form" id="kwtsms_brand_form" class="kwtsms-brand__form" autocomplete="off"
                  data-kwtsms-brand-max="{$t.brand.max}" data-kwtsms-brand-unicode="{if $t.brand.warn_unicode}1{else}0{/if}">
                <input type="hidden" name="kwtsms_brand[lang]" value="{$t.lang}">
                {foreach $t.brand.storefronts as $sf}
                    <div class="kwtsms-brand__row">
                        <label class="kwtsms-brand__label" for="kwtsms_brand_{$sf.id}">{$sf.name}</label>
                        <input type="text" id="kwtsms_brand_{$sf.id}" name="kwtsms_brand[names][{$sf.id}]" value="{$sf.value}"
                               class="kwtsms-brand__input" maxlength="{$t.brand.max}" dir="{$t.direction}" lang="{$t.lang}"
                               placeholder="{$sf.fallback}" autocomplete="off" spellcheck="false" data-kwtsms-brand-input="1"
                               aria-describedby="kwtsms_brand_count_{$sf.id} kwtsms_brand_fallback_{$sf.id}">
                        <span class="kwtsms-brand__count" id="kwtsms_brand_count_{$sf.id}" aria-live="polite"><bdi dir="ltr"><span data-kwtsms-brand-n>{$sf.length}</span>/{$t.brand.max}</bdi></span>
                        {* 2026-10-21 14:06 +03: the fallback (storefront name, else company name) as visible text under the field, not
                           only as the placeholder, which disappears as soon as something is typed and is not read by every screen
                           reader. Cleanup 2 item 3, task 596. *}
                        <p class="muted kwtsms-brand__fallback" id="kwtsms_brand_fallback_{$sf.id}">{if $sf.fallback !== ""}{__("kwtsms.templates.brand_fallback")} <bdi dir="auto" class="kwtsms-brand__fallback-name">{$sf.fallback}</bdi>{else}{__("kwtsms.templates.brand_fallback_none")}{/if}</p>
                        <p class="kwtsms-brand__warn{if !$sf.unicode} hidden{/if}" id="kwtsms_brand_warn_{$sf.id}" role="status">{__("kwtsms.templates.brand_unicode_warn")}</p>
                    </div>
                {/foreach}
                <div class="kwtsms-actions">
                    <input type="submit" class="btn" id="kwtsms_brand_save" name="dispatch[kwtsms.update_brand_names]" value="{__("kwtsms.templates.brand_save")}">
                </div>
            </form>
        {else}
            <p class="muted" id="kwtsms_brand_none">{__("kwtsms.templates.brand_none")}</p>
        {/if}
    </section>

    {* ---- the matrix ------------------------------------------------------------------------------------------------ *}
    <table class="table table-middle kwtsms-matrix" id="kwtsms_matrix">
        <thead>
            <tr>
                <th>{__("kwtsms.templates.col_status")}</th>
                <th>{__("kwtsms.templates.col_receiver")}</th>
                <th>{__("kwtsms.templates.col_switch")}</th>
                <th>{__("kwtsms.templates.col_text")}</th>
                <th><span class="kwtsms-sr">{__("kwtsms.templates.col_actions")}</span></th>
            </tr>
        </thead>
        {$group = ""}
        {foreach $t.rows as $row}
            {if $row.kind == "placed" || $row.kind == "placed_multi"}{$row_group = "placed"}{elseif $row.kind == "status"}{$row_group = "statuses"}{else}{$row_group = "generic"}{/if}
            {if $row_group != $group}
                {if $group != ""}</tbody>{/if}
                <tbody>
                <tr class="kwtsms-group"><th colspan="5" scope="rowgroup">{__("kwtsms.templates.group_`$row_group`")}</th></tr>
                {$group = $row_group}
            {/if}
            {$edit_href = "kwtsms.templates?lang=`$t.lang`&edit="|cat:($row.key|escape:"url")}
            {if $row.kind == "placed"}{$row_label = __("kwtsms.templates.row_placed")}
            {elseif $row.kind == "placed_multi"}{$row_label = __("kwtsms.templates.row_placed_multi")}
            {elseif $row.kind == "generic"}{$row_label = __("kwtsms.templates.row_generic")}
            {else}{$row_label = $row.status_name}{/if}
            {if $row.receiver == "A"}{$receiver_label = __("kwtsms.templates.admin")}{else}{$receiver_label = __("kwtsms.templates.customer")}{/if}
            <tr class="kwtsms-row{if $row.editing} is-editing{/if}" id="{$row.dom_id}" data-kwtsms-key="{$row.key}">
                <td class="kwtsms-row__label">
                    <span class="kwtsms-row__name">{$row_label}</span>
                    {if $row.kind == "status"}<bdi class="kwtsms-id" dir="ltr">{$row.status}</bdi>{/if}
                </td>
                <td class="kwtsms-row__receiver">{$receiver_label}</td>
                <td class="kwtsms-row__switch">
                    {if $row.switch}
                        <form action="{""|fn_url}" method="post" name="kwtsms_switch_{$row.dom_id}" class="kwtsms-switch-form">
                            <input type="hidden" name="kwtsms_tpl[event_id]" value="{$row.event_id}">
                            <input type="hidden" name="kwtsms_tpl[receiver]" value="{$row.receiver}">
                            <input type="hidden" name="kwtsms_tpl[lang]" value="{$t.lang}">
                            <input type="hidden" name="kwtsms_tpl[enable]" value="{if $row.switch.on}N{else}Y{/if}">
                            <button type="submit" class="kwtsms-switch{if $row.switch.on} is-on{/if}" role="switch"
                                    aria-checked="{if $row.switch.on}true{else}false{/if}"
                                    aria-label="{__("kwtsms.templates.switch_aria", ["[row]" => "`$row_label` / `$receiver_label`"])}"
                                    name="dispatch[kwtsms.toggle_event]" value="1"
                                    {if !$t.can_toggle}disabled="disabled" title="{__("kwtsms.templates.switch_locked")}"{/if}>
                                <span class="kwtsms-switch__knob"></span>
                            </button>
                            <span class="kwtsms-switch__text">{if $row.switch.on}{__("kwtsms.status.on")}{else}{__("kwtsms.status.off")}{/if}</span>
                        </form>
                    {elseif $row.switch_note == "follows_placed"}
                        <span class="muted kwtsms-switch-note">{__("kwtsms.templates.switch_follows")}</span>
                    {elseif $row.switch_note == "generic"}
                        <span class="muted kwtsms-switch-note">{__("kwtsms.templates.switch_generic")}</span>
                    {else}
                        <span class="muted kwtsms-switch-note">{__("kwtsms.templates.switch_none")}</span>
                    {/if}
                </td>
                <td class="kwtsms-row__text">
                    {if $row.has_text}
                        <p class="kwtsms-snippet" lang="{$t.lang}" dir="{$t.direction}">{$row.snippet}</p>
                    {/if}
                    <p class="kwtsms-row__meta">
                        <span class="kwtsms-badge kwtsms-badge--{$row.badge}">
                            {if $row.badge == "default_lang"}{__("kwtsms.templates.badge_default_lang", ["[language]" => $t.default_lang_name])}
                            {else}{__("kwtsms.templates.badge_`$row.badge`")}{/if}
                        </span>
                        {if $row.has_text}
                            <span class="kwtsms-length{if $row.unicode} is-unicode{/if}">{__("kwtsms.templates.length_summary", ["[units]" => $row.units, "[pages]" => $row.pages])}</span>
                        {/if}
                    </p>
                </td>
                <td class="kwtsms-row__actions">
                    {if $row.editing}
                        <a class="btn" href="{"kwtsms.templates?lang=`$t.lang`"|fn_url}#{$row.dom_id}">{__("kwtsms.templates.close")}</a>
                    {else}
                        <a class="btn kwtsms-edit" href="{$edit_href|fn_url}#kwtsms_editor"
                           aria-label="{__("kwtsms.templates.edit_aria", ["[row]" => "`$row_label` / `$receiver_label`"])}">{__("kwtsms.templates.edit")}</a>
                    {/if}
                </td>
            </tr>
            {if $row.editing && $e}
                <tr class="kwtsms-editor-row">
                    <td colspan="5">
                        {include file="addons/kwtsms/views/kwtsms/components/template_editor.tpl" e=$e t=$t row_label=$row_label receiver_label=$receiver_label}
                    </td>
                </tr>
            {/if}
        {/foreach}
        {if $group != ""}</tbody>{/if}
    </table>
</div>
{/capture}

{include file="common/mainbox.tpl"
    title=__("kwtsms.templates.title")
    content=$smarty.capture.mainbox
    select_storefront=false
}
