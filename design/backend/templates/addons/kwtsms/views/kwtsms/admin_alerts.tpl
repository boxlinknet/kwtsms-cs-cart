{*
FILE:      design/backend/templates/addons/kwtsms/views/kwtsms/admin_alerts.tpl
CREATED:   2026-10-21 04:06 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      The kwtSMS Admin SMS alerts page: the list of administrator numbers (up to 10) with the alert language, the
           result table of the last save or check (each typed number with its local check and the kwtSMS /validate/
           verdict), the saved numbers, and the read-only admin switches with a link to the Templates page.
WHY:       PRD 6.3, 6.6 and 7. Native backend components under the .kwtsms-page root. The page says plainly that SMS uses
           this list and that the Receivers setting of Administration > Notifications applies to e-mail. Every value is
           auto-escaped (no nofilter); numbers are wrapped in <bdi dir="ltr">; the verdict and the local check are words,
           not only colors. Save is the top button (as on Settings); "Check numbers" posts the same form to
           dispatch[kwtsms.update_admin_alerts.check] and saves nothing. The security hash is added to the form by the
           core output filter.
USAGE:     Rendered by kwtsms.admin_alerts (controllers/backend/kwtsms.php) with $kwtsms_alerts =
           Admin\AlertsPage::viewData() and $kwtsms_nav = Admin\Navigation::sections().
DEPENDS:   components/navigation.tpl, alerts.less, kwtsms.alerts.* and kwtsms.nav.* language variables.
*}
{* 2026-10-23 10:00 +03: Task A14 fix, task 596. The core Smarty runs with escape_html on and DROPS |escape:"html" inside an expression (a __() placeholder
   array, a backtick interpolation): the value stayed raw. A storefront name printed raw in the Settings aria-label and the Templates hint
   (stored XSS, tests/Store/A14XssStoreTest). Every value placed into a __() placeholder now goes through |fn_html_escape, which escapes
   < > & and both quotes (tests/Unit/TemplateEscapeTest keeps the dropped modifier out). *}
{$a = $kwtsms_alerts}
{$country_label = "+`$a.default_country_code`"}
{$r = $a.result}
{capture name="mainbox"}
<div class="kwtsms-page kwtsms-alerts" id="kwtsms_alerts_page">

    {include file="addons/kwtsms/views/kwtsms/components/navigation.tpl" sections=$kwtsms_nav}

    <p class="kwtsms-intro">{__("kwtsms.alerts.intro", ["[max]" => $a.max|fn_html_escape])}</p>
    <p class="kwtsms-note" id="kwtsms_alerts_email_note">{__("kwtsms.alerts.email_note")}</p>

    {if !$a.sms_enabled}
        <div class="alert alert-info kwtsms-alert" id="kwtsms_alerts_sms_off"><p>{__("kwtsms.alerts.sms_off")}</p></div>
    {/if}

    {* ---- recipients form ------------------------------------------------------------------------------------------ *}
    <form action="{""|fn_url}" method="post" name="kwtsms_alerts_form" id="kwtsms_alerts_form" class="form-horizontal form-edit" autocomplete="off">
        <section class="kwtsms-section" id="kwtsms_recipients" aria-labelledby="kwtsms_recipients_title">
            <h4 class="subheader" id="kwtsms_recipients_title">{__("kwtsms.alerts.recipients")}</h4>

            <div class="control-group">
                <label class="control-label" for="kwtsms_admin_phones">{__("kwtsms.alerts.numbers")}:</label>
                <div class="controls">
                    <textarea id="kwtsms_admin_phones" name="kwtsms_alerts[admin_phones]" rows="6" class="kwtsms-code kwtsms-phones" dir="ltr"
                              autocomplete="off" spellcheck="false" inputmode="tel"
                              placeholder="{__("kwtsms.alerts.numbers_placeholder")}">{$a.phones_text}</textarea>
                    <p class="muted description">{__("kwtsms.alerts.numbers_help", ["[code]" => $country_label|fn_html_escape])}</p>
                    <p class="kwtsms-count" id="kwtsms_alerts_count" dir="auto">{__("kwtsms.alerts.count", ["[count]" => $a.phones|count, "[max]" => $a.max])}</p>
                </div>
            </div>

            <div class="control-group">
                <label class="control-label" for="kwtsms_admin_alert_lang">{__("kwtsms.alerts.lang")}:</label>
                <div class="controls">
                    <select id="kwtsms_admin_alert_lang" name="kwtsms_alerts[admin_alert_lang]" class="kwtsms-select-wide">
                        <option value=""{if $a.lang == ""} selected="selected"{/if}>{__("kwtsms.alerts.lang_default", ["[name]" => $a.default_lang_name|fn_html_escape])}</option>
                        {foreach $a.languages as $code => $language}
                            <option value="{$code}"{if $a.lang == $code} selected="selected"{/if}>{$language.name}</option>
                        {/foreach}
                    </select>
                    <p class="muted description">{__("kwtsms.alerts.lang_help")}</p>
                </div>
            </div>

            <div class="control-group">
                <div class="controls kwtsms-actions">
                    <input type="submit" class="btn" id="kwtsms_alerts_check" name="dispatch[kwtsms.update_admin_alerts.check]" value="{__("kwtsms.alerts.check")}">
                    <span class="muted">{__("kwtsms.alerts.check_help")}</span>
                </div>
                {if !$a.connected}
                    <div class="controls"><p class="kwtsms-hint-plain muted" id="kwtsms_alerts_check_account">{__("kwtsms.alerts.check_needs_account")}</p></div>
                {/if}
            </div>
        </section>
    </form>

    {* ---- result of the last save or check, or the saved numbers --------------------------------------------------- *}
    <section class="kwtsms-section" id="kwtsms_alerts_result" aria-labelledby="kwtsms_numbers_title">
        <h4 class="subheader" id="kwtsms_numbers_title">
            {if $r}{if $r.mode == "check"}{__("kwtsms.alerts.table.result_check")}{else}{__("kwtsms.alerts.table.result_save")}{/if}{else}{__("kwtsms.alerts.table.saved")}{/if}
        </h4>

        {if $r && $r.ignored > 0}
            <div class="alert alert-warning kwtsms-inline-alert" id="kwtsms_alerts_ignored" role="status">
                {__("kwtsms.alerts.ignored", ["[count]" => $r.ignored, "[max]" => $a.max_entries])}
            </div>
        {/if}

        {if $r && $r.rows}
            <div class="table-responsive kwtsms-table-wrap">
                <table class="table table-middle kwtsms-table" id="kwtsms_alerts_table" data-kwtsms-mode="{$r.mode}">
                    <thead>
                        <tr>
                            <th>{__("kwtsms.alerts.table.typed")}</th>
                            <th>{__("kwtsms.alerts.table.number")}</th>
                            <th>{__("kwtsms.alerts.table.status")}</th>
                            <th>{__("kwtsms.alerts.table.verdict")}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach $r.rows as $row}
                            <tr class="kwtsms-row" data-kwtsms-ok="{if $row.ok}Y{else}N{/if}" data-kwtsms-reason="{$row.reason}" data-kwtsms-verdict="{$row.verdict}">
                                <td data-th="{__("kwtsms.alerts.table.typed")}"><bdi dir="ltr">{$row.raw}</bdi></td>
                                <td data-th="{__("kwtsms.alerts.table.number")}">{if $row.ok}<bdi dir="ltr">+{$row.phone}</bdi>{else}-{/if}</td>
                                <td data-th="{__("kwtsms.alerts.table.status")}">
                                    {if $row.ok && $row.repeat}
                                        <span class="kwtsms-badge">{__("kwtsms.alerts.row.repeat")}</span>
                                    {elseif $row.ok}
                                        <span class="kwtsms-badge kwtsms-badge--ok">{if $r.mode == "check" || !$r.saved_count}{__("kwtsms.alerts.row.valid")}{else}{__("kwtsms.alerts.row.saved")}{/if}</span>
                                    {else}
                                        <span class="kwtsms-badge kwtsms-badge--danger">{if $r.mode == "check"}{__("kwtsms.alerts.row.not_valid")}{else}{__("kwtsms.alerts.row.not_saved")}{/if}</span>
                                        {* 2026-10-21 07:05 +03: a check saves nothing, so the limit reason doesn't say "wasn't saved" (fix round 1, task 596) *}
                                        {if $r.mode == "check" && $row.reason == "limit"}
                                            <span class="kwtsms-reason">{__("kwtsms.alerts.reason.limit_check", ["[max]" => $a.max])}</span>
                                        {else}
                                            <span class="kwtsms-reason">{__("kwtsms.alerts.reason.`$row.reason`", ["[max]" => $a.max])}</span>
                                        {/if}
                                    {/if}
                                </td>
                                <td data-th="{__("kwtsms.alerts.table.verdict")}">
                                    {if $row.verdict == "ok"}
                                        <span class="kwtsms-badge kwtsms-badge--ok">{__("kwtsms.alerts.verdict.ok")}</span>
                                    {elseif $row.verdict == "cannot_route"}
                                        <span class="kwtsms-badge kwtsms-badge--danger">{__("kwtsms.alerts.verdict.cannot_route")}</span>
                                    {elseif $row.verdict == "unchecked"}
                                        <span class="kwtsms-badge">{__("kwtsms.alerts.verdict.unchecked")}</span>
                                        {if $row.verdict_code == "not_connected"}
                                            <span class="kwtsms-reason muted">{__("kwtsms.alerts.verdict.not_connected")}</span>
                                        {elseif $row.verdict_code}
                                            <span class="kwtsms-reason muted">{__("kwtsms.alerts.verdict_why", ["[reason]" => $row.verdict_text|fn_html_escape])}</span>
                                        {/if}
                                    {else}
                                        <span class="muted">-</span>
                                    {/if}
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        {elseif $a.phones}
            <div class="table-responsive kwtsms-table-wrap">
                <table class="table table-middle kwtsms-table" id="kwtsms_alerts_table" data-kwtsms-mode="saved">
                    <thead>
                        <tr>
                            <th>{__("kwtsms.alerts.table.number")}</th>
                            <th>{__("kwtsms.alerts.table.verdict")}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach $a.phones as $phone}
                            <tr class="kwtsms-row" data-kwtsms-ok="Y">
                                <td data-th="{__("kwtsms.alerts.table.number")}"><bdi dir="ltr">+{$phone}</bdi></td>
                                <td data-th="{__("kwtsms.alerts.table.verdict")}"><span class="muted">{__("kwtsms.alerts.not_checked")}</span></td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        {else}
            <p class="muted" id="kwtsms_alerts_empty">{__("kwtsms.alerts.empty")}</p>
        {/if}
    </section>

    {* ---- the admin switches, read-only ----------------------------------------------------------------------------- *}
    <section class="kwtsms-section" id="kwtsms_alerts_switches" aria-labelledby="kwtsms_switches_title">
        <h4 class="subheader" id="kwtsms_switches_title">{__("kwtsms.alerts.switches.title")}</h4>
        <p class="muted description">{__("kwtsms.alerts.switches.help")}</p>
        <dl class="kwtsms-facts">
            <dt>{__("kwtsms.alerts.switches.placed")}</dt>
            <dd id="kwtsms_switch_placed">
                {if $a.switches.placed === true}
                    <span class="kwtsms-badge kwtsms-badge--ok">{__("kwtsms.alerts.switches.on")}</span>
                {elseif $a.switches.placed === false}
                    <span class="kwtsms-badge">{__("kwtsms.alerts.switches.off")}</span>
                {else}
                    <span class="kwtsms-badge">{__("kwtsms.alerts.switches.unavailable")}</span>
                {/if}
            </dd>
            <dt>{__("kwtsms.alerts.switches.status")}</dt>
            <dd id="kwtsms_switch_status">
                {if $a.switches.status_total == 0}
                    <span class="kwtsms-badge">{__("kwtsms.alerts.switches.unavailable")}</span>
                {elseif $a.switches.status_on}
                    <span class="kwtsms-badge kwtsms-badge--ok">{__("kwtsms.alerts.switches.summary", ["[on]" => $a.switches.status_on|count, "[total]" => $a.switches.status_total])}</span>
                    <span class="kwtsms-reason muted">{foreach $a.switches.status_on as $status_name}{$status_name}{if !$status_name@last}, {/if}{/foreach}</span>
                {else}
                    <span class="kwtsms-badge">{__("kwtsms.alerts.switches.none_on")}</span>
                {/if}
            </dd>
        </dl>
        <p><a class="btn" id="kwtsms_alerts_open_templates" href="{"kwtsms.templates"|fn_url}">{__("kwtsms.alerts.switches.open")}</a></p>
    </section>
</div>
{/capture}

{capture name="buttons"}
    {include file="buttons/save.tpl" but_name="dispatch[kwtsms.update_admin_alerts]" but_role="submit-link" but_target_form="kwtsms_alerts_form"}
{/capture}

{include file="common/mainbox.tpl"
    title=__("kwtsms.alerts.title")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
    select_storefront=false
}
