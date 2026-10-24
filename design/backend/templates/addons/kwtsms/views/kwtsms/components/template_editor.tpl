{*
FILE:      design/backend/templates/addons/kwtsms/views/kwtsms/components/template_editor.tpl
CREATED:   2026-10-21 03:25 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      The editor of one template text (one row, one language): the text area in the language's direction, the
           placeholder chips, the advice list, the SMS meter with the counter and the worst-case estimate, the preview,
           and Save / Reset / Close, and "Send a test of this template" (2026-10-21 05:18 +03, Task 1.12 fix round 1 item 2).
WHY:       PRD 6.4. The left column is the only form (Save, Reset); the right column (meter, preview) sits outside it so
           that pressing Enter in the order number box can never submit a save. The script (templates.js) reads the
           data- attributes of the root, counts with counter.js (a mirror of MessageCleaner) and asks the server for the
           exact preview; it shows server text with textContent only. The text of a template is printed only inside the
           text area, auto-escaped. The test send is its own small form (outside the main one, like the preview, so Enter
           in the number box never saves); the script copies the typed text into its hidden field on submit.
USAGE:     {include file="addons/kwtsms/views/kwtsms/components/template_editor.tpl" e=$editor t=$page row_label=.. receiver_label=..}
           $e: Admin\TemplatesPage::viewData()['editor']; $t: the page data.
DEPENDS:   templates.less (.kwtsms-editor), js/addons/kwtsms/{counter,templates}.js, kwtsms.templates.* language variables.
*}
{* 2026-10-23 13:16 +03: final review A, Important 2, task 596. Every value in a __() placeholder array now passes |fn_html_escape (values that never had a modifier too); tests/Unit/TemplateEscapeTest enforces it. *}
<section class="kwtsms-editor" id="kwtsms_editor" aria-labelledby="kwtsms_editor_title"
         data-kwtsms-editor="1"
         data-kwtsms-receiver="{$e.receiver}"
         data-kwtsms-lang="{$e.lang}"
         data-kwtsms-storefront="{$e.storefront_id}"
         data-kwtsms-event="{$e.event_id}"
         data-kwtsms-reference="{$e.reference|json_encode}"
         data-kwtsms-worst="{$e.worst|json_encode}"
         data-kwtsms-allowed="{$e.allowed|json_encode}"
         data-kwtsms-enc-ascii="{__("kwtsms.templates.enc_ascii")}"
         data-kwtsms-enc-unicode="{__("kwtsms.templates.enc_unicode")}"
         data-kwtsms-msg-failed="{__("kwtsms.templates.preview_failed")}"
         data-kwtsms-msg-empty="{__("kwtsms.templates.preview_empty")}"
         data-kwtsms-msg-leftover="{__("kwtsms.templates.preview_leftover")}"
         data-kwtsms-msg-removed="{__("kwtsms.templates.preview_removed")}"
         data-kwtsms-msg-length="{__("kwtsms.templates.length_summary")}"
         data-kwtsms-msg-over="{__("kwtsms.templates.counter_over", ["[max]" => $t.max_pages|fn_html_escape])}">

    <h4 class="subheader" id="kwtsms_editor_title">{__("kwtsms.templates.editor_title", ["[row]" => "`$row_label|fn_html_escape` / `$receiver_label|fn_html_escape`", "[language]" => $e.lang_name|fn_html_escape])}</h4>
    <p class="kwtsms-editor__ids muted"><bdi dir="ltr">{$e.event_id}</bdi> <bdi dir="ltr">{$e.receiver}</bdi></p>

    <div class="kwtsms-editor__grid">
        <div class="kwtsms-editor__main">
            <form action="{""|fn_url}" method="post" name="kwtsms_template_form" id="kwtsms_template_form" autocomplete="off">
                <input type="hidden" name="kwtsms_tpl[event_id]" value="{$e.event_id}">
                <input type="hidden" name="kwtsms_tpl[receiver]" value="{$e.receiver}">
                <input type="hidden" name="kwtsms_tpl[lang]" value="{$e.lang}">
                {* 2026-10-22 16:16 +03: Task A3, task 596 *}
                {if $e.storefront_id}<input type="hidden" name="kwtsms_tpl[storefront_id]" value="{$e.storefront_id}">{/if}
                {if $e.unknown}<input type="hidden" name="kwtsms_tpl[confirm]" value="{$e.confirm}">{/if}

                <label class="kwtsms-editor__label" for="kwtsms_body">{__("kwtsms.templates.editor_label", ["[language]" => $e.lang_name|fn_html_escape])}</label>
                {if $e.origin == "generic"}
                    <p class="muted kwtsms-editor__help" id="kwtsms_editor_help">{__("kwtsms.templates.editor_help_generic")}</p>
                {elseif $e.origin == "placed"}
                    <p class="muted kwtsms-editor__help" id="kwtsms_editor_help">{__("kwtsms.templates.editor_help_placed")}</p>
                {elseif $e.origin == "all_storefronts"}
                    <p class="muted kwtsms-editor__help" id="kwtsms_editor_help">{__("kwtsms.templates.editor_help_all_storefronts")}</p>
                {elseif $e.origin == "default_lang"}
                    <p class="muted kwtsms-editor__help" id="kwtsms_editor_help">{__("kwtsms.templates.editor_help_default_lang", ["[language]" => $t.default_lang_name|fn_html_escape])}</p>
                {elseif $e.origin == "none"}
                    <p class="muted kwtsms-editor__help" id="kwtsms_editor_help">{__("kwtsms.templates.editor_help_none")}</p>
                {/if}
                {* 2026-10-24 12:35 +03: Task M1.4 (P3-9), task 596. The Web OTP line is added by the add-on, never edited. *}
                {if $e.kind == "code"}
                    <p class="muted kwtsms-editor__help" id="kwtsms_editor_help_code">{if $e.event_id == "otp.code"}{__("kwtsms.templates.editor_help_code")}{else}{__("kwtsms.templates.editor_help_phone_changed")}{/if}</p>
                {/if}
                <textarea id="kwtsms_body" name="kwtsms_tpl[body]" class="kwtsms-body" rows="5" maxlength="{$t.max_chars}"
                          dir="{$e.direction}" lang="{$e.lang}" spellcheck="false"{if $e.origin != "own"} data-kwtsms-origin="{$e.origin}"{/if}>{$e.body}</textarea>

                <div class="kwtsms-chips" id="kwtsms_chips" role="group" aria-label="{__("kwtsms.templates.chips_title")}">
                    <span class="kwtsms-chips__title">{__("kwtsms.templates.chips_title")}</span>
                    {foreach $e.allowed as $name}
                        <button type="button" class="kwtsms-chip" data-insert="{ldelim}{$name}{rdelim}"
                                title="{__("kwtsms.templates.ph_`$name`")}: {$e.reference.$name}"><bdi dir="ltr">{ldelim}{$name}{rdelim}</bdi></button>
                    {/foreach}
                    <span class="muted kwtsms-chips__help">{__("kwtsms.templates.chips_help")}</span>
                </div>

                <ul class="kwtsms-advice" id="kwtsms_advice" aria-live="polite">
                    {* 2026-10-24 12:35 +03: Task M1.4 (P3-9, P3-37), task 596. code_template and code_store_name are errors of a code text: the save is refused. *}
                    {foreach ["store_name_missing", "code_template", "code_store_name", "unknown", "html_span", "adjacent", "entity_fragment", "promotional"] as $code}
                        {$show = isset($e.advice[$code])}
                        <li class="kwtsms-advice__item kwtsms-advice__item--{if $code == "store_name_missing" || $code == "unknown" || $code == "code_template" || $code == "code_store_name"}stop{else}warn{/if}{if !$show} hidden{/if}"
                            data-code="{$code}" data-text="{__("kwtsms.templates.warn_`$code`")}"><span class="kwtsms-advice__text">{if $show}{__("kwtsms.templates.warn_`$code`", ["[found]" => $e.advice[$code]|fn_html_escape])}{/if}</span></li>
                    {/foreach}
                </ul>

                <div class="kwtsms-actions kwtsms-editor__actions">
                    <input type="submit" class="btn btn-primary" id="kwtsms_save" name="dispatch[kwtsms.update_template]"
                           value="{if $e.unknown}{__("kwtsms.templates.save_anyway")}{else}{__("kwtsms.templates.save")}{/if}">
                    {if $e.storefront_id}
                        <input type="submit" class="btn cm-confirm" id="kwtsms_reset" name="dispatch[kwtsms.reset_template]"
                               value="{__("kwtsms.templates.reset_storefront_button")}" data-ca-confirm-text="{__("kwtsms.templates.reset_storefront_confirm")}">
                    {else}
                    <input type="submit" class="btn cm-confirm" id="kwtsms_reset" name="dispatch[kwtsms.reset_template]"
                           value="{__("kwtsms.templates.reset")}" data-ca-confirm-text="{__("kwtsms.templates.reset_confirm")}">
                    {/if}
                    <a class="btn" id="kwtsms_close" href="{"kwtsms.templates?lang=`$e.lang`"|fn_url}{if $e.storefront_id}&storefront_id={$e.storefront_id}{/if}#{$e.dom_id}">{__("kwtsms.templates.close")}</a>
                </div>
            </form>
        </div>

        <aside class="kwtsms-editor__side">
            <div class="kwtsms-counter" id="kwtsms_counter">
                <h5 class="kwtsms-counter__title">{__("kwtsms.templates.counter_title")}</h5>
                <div class="kwtsms-meter" id="kwtsms_meter" role="img">
                    {for $i = 1 to $t.max_pages}
                        <span class="kwtsms-meter__seg"><i class="kwtsms-meter__worst"></i><i class="kwtsms-meter__fill"></i></span>
                    {/for}
                </div>
                <dl class="kwtsms-counts">
                    <div><dt>{__("kwtsms.templates.counter_chars")}</dt><dd id="kwtsms_count_units">0</dd></div>
                    <div><dt>{__("kwtsms.templates.counter_sms")}</dt><dd id="kwtsms_count_pages">0</dd></div>
                    <div><dt>{__("kwtsms.templates.counter_encoding")}</dt><dd id="kwtsms_count_enc"></dd></div>
                </dl>
                <p class="kwtsms-worst" id="kwtsms_worst">
                    <strong>{__("kwtsms.templates.worst_title")}:</strong>
                    <span id="kwtsms_worst_text"></span>
                </p>
                <p class="muted kwtsms-worst__help">{__("kwtsms.templates.worst_help")}</p>
                <p class="kwtsms-note hidden" id="kwtsms_worst_unicode">{__("kwtsms.templates.worst_unicode_note")}</p>
                <p class="kwtsms-over hidden" id="kwtsms_over" role="alert"></p>
            </div>

            <fieldset class="kwtsms-preview" id="kwtsms_preview">
                <legend>{__("kwtsms.templates.preview_title")}</legend>
                <label class="radio kwtsms-preview__choice"><input type="radio" name="kwtsms_preview_source" id="kwtsms_preview_sample" value="sample" checked="checked"> {__("kwtsms.templates.preview_sample")}</label>
                <label class="radio kwtsms-preview__choice"><input type="radio" name="kwtsms_preview_source" id="kwtsms_preview_use_order" value="order"> {__("kwtsms.templates.preview_order")}</label>
                <input type="text" id="kwtsms_preview_order" class="input-small kwtsms-preview__order" inputmode="numeric" autocomplete="off"
                       dir="ltr" maxlength="12" disabled="disabled" placeholder="10234" aria-label="{__("kwtsms.templates.preview_order_label")}">
                <div class="kwtsms-actions">
                    <button type="button" class="btn" id="kwtsms_preview_go">{__("kwtsms.templates.preview_button")}</button>
                    <span class="muted">{__("kwtsms.templates.preview_note")}</span>
                </div>
                <div class="kwtsms-preview__out hidden" id="kwtsms_preview_out" aria-live="polite">
                    <p class="kwtsms-preview__label">{__("kwtsms.templates.preview_result")}</p>
                    <pre class="kwtsms-preview__text" id="kwtsms_preview_text" dir="{$e.direction}" lang="{$e.lang}"></pre>
                    <p class="muted kwtsms-preview__stats" id="kwtsms_preview_stats"></p>
                    <ul class="kwtsms-preview__notes" id="kwtsms_preview_notes"></ul>
                </div>
            </fieldset>

            {* ---- Send a test of this template (needs manage_kwtsms_gateway; its own form) ------------------------------ *}
            {if $t.can_test}
                <fieldset class="kwtsms-test" id="kwtsms_template_test">
                    <legend>{__("kwtsms.templates.test_title")}</legend>
                    <p class="muted kwtsms-test__help">{__("kwtsms.templates.test_help", ["[max]" => $t.test_per_hour|fn_html_escape])}</p>
                    {if $t.test_result}
                        {$r = $t.test_result}
                        <div class="kwtsms-result kwtsms-result--{$r.status}" id="kwtsms_template_test_result" role="status">
                            <h5 class="kwtsms-result__title">{__("kwtsms.test_sms.result")}</h5>
                            <dl class="kwtsms-result__list">
                                <dt>{__("kwtsms.test_sms.to")}</dt><dd><bdi dir="ltr" id="kwtsms_tpl_result_phone">{$r.phone}</bdi></dd>
                                <dt>{__("kwtsms.test_sms.outcome")}</dt><dd id="kwtsms_tpl_result_outcome" data-kwtsms-outcome="{$r.outcome}">{$r.outcome_text}</dd>
                                {if $r.error}<dt>{__("kwtsms.test_sms.details")}</dt><dd id="kwtsms_tpl_result_error">{$r.error}</dd>{/if}
                                {if $r.msg_id}<dt>{__("kwtsms.test_sms.msg_id")}</dt><dd><bdi dir="ltr" id="kwtsms_tpl_result_msg_id">{$r.msg_id}</bdi></dd>{/if}
                                {if $r.points !== ""}<dt>{__("kwtsms.test_sms.points")}</dt><dd><bdi id="kwtsms_tpl_result_points">{$r.points}</bdi></dd>{/if}
                                {if $r.balance_after !== ""}<dt>{__("kwtsms.test_sms.balance_after")}</dt><dd><bdi id="kwtsms_tpl_result_balance">{$r.balance_after}</bdi></dd>{/if}
                            </dl>
                            {if $r.test}
                                <p class="kwtsms-result__note" id="kwtsms_tpl_result_test_note">{__("kwtsms.test_sms.test_note")}</p>
                            {/if}
                        </div>
                    {/if}
                    <form action="{""|fn_url}" method="post" name="kwtsms_template_test_form" id="kwtsms_test_form" class="kwtsms-test__form" autocomplete="off">
                        <input type="hidden" name="kwtsms_tpl[event_id]" value="{$e.event_id}">
                        <input type="hidden" name="kwtsms_tpl[receiver]" value="{$e.receiver}">
                        <input type="hidden" name="kwtsms_tpl[lang]" value="{$e.lang}">
                        {if $e.storefront_id}<input type="hidden" name="kwtsms_tpl[storefront_id]" value="{$e.storefront_id}">{/if}
                        <input type="hidden" name="kwtsms_tpl[body]" id="kwtsms_test_body" value="{$e.body}">
                        <label class="control-label" for="kwtsms_test_phone">{__("kwtsms.test_sms.phone")}</label>
                        <input type="text" id="kwtsms_test_phone" name="kwtsms_tpl[phone]" value="" class="input-medium" dir="ltr"
                               inputmode="tel" autocomplete="off" maxlength="32" placeholder="9876 5432" required="required">
                        {* 2026-10-21 06:00 +03: an own flex line instead of the core .checkbox float, which left the box outside the panel in Arabic *}
                        <label class="kwtsms-checkline"><input type="checkbox" id="kwtsms_test_intl" name="kwtsms_tpl[intl]" value="Y"> <span>{__("kwtsms.test_sms.intl")}</span></label>
                        <p class="muted kwtsms-test__country">{__("kwtsms.test_sms.intl_help", ["[country]" => $t.test_country|fn_html_escape])}</p>
                        <div class="kwtsms-actions">
                            <input type="submit" class="btn" id="kwtsms_test_send" name="dispatch[kwtsms.test_template]" value="{__("kwtsms.templates.test_send")}">
                        </div>
                    </form>
                </fieldset>
            {else}
                <p class="muted kwtsms-test-locked" id="kwtsms_test_locked">{__("kwtsms.templates.test_locked")}</p>
            {/if}
        </aside>
    </div>
</section>
