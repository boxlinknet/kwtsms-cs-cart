{*
FILE:      design/backend/templates/addons/kwtsms/views/kwtsms/settings.tpl
CREATED:   2026-10-21 02:02 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      The kwtSMS Settings page: status strip, kwtSMS account (login form or the connected view), Sender ID, sending
           switches and default country, limits, quiet hours, logs, cron, and the test SMS form with its result.
WHY:       PRD 6.1, 6.3, 6.14, 7 and 9. Native backend components only, under the .kwtsms-page root. Credentials are
           never rendered back: the password field is always empty (autocomplete new-password), the username field of
           the login form too (autocomplete off), both with non-standard names; the username shows read-only only in the
           connected view. Every value is auto-escaped (no nofilter); data for the script sits in data- attributes.
           Each action button is its own small form (forms never nest); the security hash is added to every form by
           the core output filter.
           2026-10-21 03:18 +03, Task 1.10 fix round 1 item 7, task 596: every value placed into a __() placeholder is
           escaped here (|escape:"html"), because the core prints __() output as it is.
           2026-10-22 16:17 +03, Task A3, task 596: the Sender ID per storefront block (one dropdown per storefront, only when the store has two
           or more storefronts). Its controls sit under the Sender ID section but belong to their own small form through the
           form attribute (forms never nest), so the page Save button does not touch them.
USAGE:     Rendered by kwtsms.settings (controllers/backend/kwtsms.php) with $kwtsms = Admin\SettingsPage::viewData()
           plus test_result, order_statuses, cron_command, cron_url; $kwtsms_nav = Admin\Navigation::sections().
DEPENDS:   components/navigation.tpl, styles.less, js/addons/kwtsms/backend.js, kwtsms.* language variables.
*}
{* 2026-10-23 10:00 +03: Task A14 fix, task 596. The core Smarty runs with escape_html on and DROPS |escape:"html" inside an expression (a __() placeholder
   array, a backtick interpolation): the value stayed raw. A storefront name printed raw in the Settings aria-label and the Templates hint
   (stored XSS, tests/Store/A14XssStoreTest). Every value placed into a __() placeholder now goes through |fn_html_escape, which escapes
   < > & and both quotes (tests/Unit/TemplateEscapeTest keeps the dropped modifier out). *}
{$s = $kwtsms.settings}
{$is_test_sender = ($s.sender_id == $kwtsms.test_sender)}
{capture name="mainbox"}
<div class="kwtsms-page kwtsms-settings" id="kwtsms_settings_page"
     data-kwtsms-test-sender="{$kwtsms.test_sender}"
     data-kwtsms-test-mode="{$s.test_mode}"
     data-kwtsms-saved-sender="{$s.sender_id}">

    {include file="addons/kwtsms/views/kwtsms/components/navigation.tpl" sections=$kwtsms_nav}

    {* ---- status strip ---------------------------------------------------------------------------------------- *}
    <dl class="kwtsms-status" aria-label="{__("kwtsms.status.label")}">
        <div class="kwtsms-status__item">
            <dt>{__("kwtsms.status.account")}</dt>
            <dd>
                {if $kwtsms.suspended}
                    <span class="kwtsms-badge kwtsms-badge--danger">{__("kwtsms.status.paused")}</span>
                {elseif $kwtsms.connected}
                    <span class="kwtsms-badge kwtsms-badge--ok">{__("kwtsms.status.connected")}</span>
                {else}
                    <span class="kwtsms-badge">{__("kwtsms.status.not_connected")}</span>
                {/if}
            </dd>
        </div>
        <div class="kwtsms-status__item">
            <dt>{__("kwtsms.status.sms")}</dt>
            <dd>
                {if $s.enabled == "Y"}
                    <span class="kwtsms-badge kwtsms-badge--ok" id="kwtsms_status_enabled">{__("kwtsms.status.on")}</span>
                {else}
                    <span class="kwtsms-badge" id="kwtsms_status_enabled">{__("kwtsms.status.off")}</span>
                {/if}
            </dd>
        </div>
        <div class="kwtsms-status__item">
            <dt>{__("kwtsms.status.test_mode")}</dt>
            <dd>
                {if $s.test_mode == "Y"}
                    <span class="kwtsms-badge kwtsms-badge--test" id="kwtsms_status_test_mode">{__("kwtsms.status.on")}</span>
                {else}
                    <span class="kwtsms-badge" id="kwtsms_status_test_mode">{__("kwtsms.status.off")}</span>
                {/if}
            </dd>
        </div>
        <div class="kwtsms-status__item">
            <dt>{__("kwtsms.status.balance")}</dt>
            <dd><bdi>{if $kwtsms.balance}{$kwtsms.balance.available}{else}-{/if}</bdi></dd>
        </div>
    </dl>
    {if !$kwtsms.configured}
        <p class="kwtsms-status__note muted" id="kwtsms_not_ready">{__("kwtsms.status.not_ready", ["[reason]" => $kwtsms.configured_reason|fn_html_escape])}</p>
    {/if}

    {* ---- alerts with their own action ------------------------------------------------------------------------- *}
    {if $kwtsms.suspended}
        <div class="alert alert-error kwtsms-alert" id="kwtsms_suspended">
            <p>{__("kwtsms.settings.suspended", ["[reason]" => $kwtsms.suspended_text|fn_html_escape])}</p>
            <form action="{""|fn_url}" method="post" name="kwtsms_retry_form" class="kwtsms-inline-form">
                <input type="submit" class="btn" name="dispatch[kwtsms.retry_gateway]" value="{__("kwtsms.settings.retry_now")}">
            </form>
        </div>
    {/if}
    {if $kwtsms.relogin}
        <div class="alert alert-error kwtsms-alert" id="kwtsms_relogin"><p>{__("kwtsms.settings.relogin")}</p></div>
    {/if}
    {if $kwtsms.daily_cap_tripped}
        <div class="alert alert-warning kwtsms-alert" id="kwtsms_daily_cap">
            <p>{__("kwtsms.settings.daily_cap_tripped")}</p>
            <form action="{""|fn_url}" method="post" name="kwtsms_resume_form" class="kwtsms-inline-form">
                <input type="submit" class="btn" name="dispatch[kwtsms.resume_daily_cap]" value="{__("kwtsms.settings.resume_now")}">
            </form>
        </div>
    {/if}

    {* ---- kwtSMS account ------------------------------------------------------------------------------------------ *}
    <section class="kwtsms-section" id="kwtsms_account" aria-labelledby="kwtsms_account_title">
        <h4 class="subheader" id="kwtsms_account_title">{__("kwtsms.settings.account")}</h4>

        {if $kwtsms.connected && !$kwtsms.change_account}
            <div class="form-horizontal form-edit">
                <div class="control-group">
                    <label class="control-label" for="kwtsms_connected_user">{__("kwtsms.settings.logged_in_as")}:</label>
                    <div class="controls">
                        <input type="text" id="kwtsms_connected_user" class="input-large kwtsms-ltr" value="{$kwtsms.username}"
                               readonly="readonly" autocomplete="off" dir="ltr" aria-readonly="true">
                    </div>
                </div>
                {* 2026-10-21 02:17 +03: the saved password is never rendered back; an empty, read-only field without a
                   name says so (brief: the connected view shows an empty password field), Task 1.10, task 596. *}
                <div class="control-group">
                    <label class="control-label" for="kwtsms_connected_password">{__("kwtsms.settings.api_password")}:</label>
                    <div class="controls">
                        <input type="password" id="kwtsms_connected_password" class="input-large kwtsms-ltr" value=""
                               readonly="readonly" autocomplete="new-password" dir="ltr" aria-readonly="true"
                               placeholder="{__("kwtsms.settings.password_saved")}">
                    </div>
                </div>
                <div class="control-group">
                    <span class="control-label">{__("kwtsms.settings.balance")}:</span>
                    <div class="controls">
                        <p class="kwtsms-balance" id="kwtsms_balance">
                            {if $kwtsms.balance}
                                <span>{__("kwtsms.settings.balance_available")}: <strong><bdi>{$kwtsms.balance.available}</bdi></strong></span>
                                {if $kwtsms.balance.purchased !== ""}
                                    <span>{__("kwtsms.settings.balance_purchased")}: <bdi>{$kwtsms.balance.purchased}</bdi></span>
                                {/if}
                                <span class="muted">{__("kwtsms.settings.checked_at")}: <bdi>{$kwtsms.balance.synced_at|date_format:"`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}</bdi></span>
                            {else}
                                -
                            {/if}
                        </p>
                        <p class="muted">{__("kwtsms.settings.last_sync")}: <bdi>{if $kwtsms.last_sync}{$kwtsms.last_sync|date_format:"`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}{else}{__("kwtsms.settings.never")}{/if}</bdi></p>
                    </div>
                </div>
                <div class="control-group">
                    <div class="controls kwtsms-actions">
                        <form action="{""|fn_url}" method="post" name="kwtsms_refresh_form" class="kwtsms-inline-form">
                            <input type="submit" class="btn" name="dispatch[kwtsms.refresh_balance]" value="{__("kwtsms.settings.refresh_balance")}">
                        </form>
                        <form action="{""|fn_url}" method="post" name="kwtsms_sync_form" class="kwtsms-inline-form">
                            <input type="submit" class="btn" name="dispatch[kwtsms.sync]" value="{__("kwtsms.settings.sync")}">
                        </form>
                        <a class="btn" id="kwtsms_change_account" href="{"kwtsms.settings?change_account=1"|fn_url}#kwtsms_account">{__("kwtsms.settings.change_account")}</a>
                        <form action="{""|fn_url}" method="post" name="kwtsms_logout_form" class="kwtsms-inline-form">
                            <input type="submit" class="btn cm-confirm" id="kwtsms_disconnect" name="dispatch[kwtsms.logout]"
                                   value="{__("kwtsms.settings.disconnect")}" data-ca-confirm-text="{__("kwtsms.settings.disconnect_confirm")}">
                        </form>
                    </div>
                </div>
            </div>
        {/if}

        {if $kwtsms.show_login}
            {* Non-standard names and autocomplete hints: a browser that saved the admin login must not fill these. *}
            <form action="{""|fn_url}" method="post" name="kwtsms_login_form" id="kwtsms_login_form" class="form-horizontal form-edit" autocomplete="off">
                {if $kwtsms.change_account}
                    <p class="muted">{__("kwtsms.settings.change_account_help")}</p>
                {/if}
                <div class="control-group">
                    <label class="control-label cm-required" for="kwtsms_gw_u">{__("kwtsms.settings.api_username")}:</label>
                    <div class="controls">
                        <input type="text" id="kwtsms_gw_u" name="kwtsms_gw_u" value="" class="input-large kwtsms-ltr" dir="ltr"
                               autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="100">
                    </div>
                </div>
                <div class="control-group">
                    <label class="control-label cm-required" for="kwtsms_gw_p">{__("kwtsms.settings.api_password")}:</label>
                    <div class="controls">
                        <input type="password" id="kwtsms_gw_p" name="kwtsms_gw_p" value="" class="input-large kwtsms-ltr" dir="ltr"
                               autocomplete="new-password" autocapitalize="off" spellcheck="false" maxlength="200">
                        <p class="muted description">{__("kwtsms.settings.api_user_help")}</p>
                    </div>
                </div>
                <div class="control-group">
                    <div class="controls kwtsms-actions">
                        <input type="submit" class="btn btn-primary" id="kwtsms_login_submit" name="dispatch[kwtsms.login]" value="{__("kwtsms.settings.login")}">
                        {if $kwtsms.change_account}
                            <a class="btn" href="{"kwtsms.settings"|fn_url}">{__("cancel")}</a>
                        {/if}
                    </div>
                </div>
            </form>
        {/if}
    </section>

    {* ---- settings form ------------------------------------------------------------------------------------------- *}
    <form action="{""|fn_url}" method="post" name="kwtsms_settings_form" id="kwtsms_settings_form" class="form-horizontal form-edit">

        <section class="kwtsms-section" id="kwtsms_sender" aria-labelledby="kwtsms_sender_title">
            <h4 class="subheader" id="kwtsms_sender_title">{__("kwtsms.settings.sender")}</h4>
            {if !$kwtsms.connected}
                <p class="muted">{__("kwtsms.settings.sender_none")}</p>
            {elseif !$kwtsms.senders}
                <p class="muted">{__("kwtsms.settings.sender_empty_list")}</p>
            {else}
                <div class="control-group">
                    <label class="control-label" for="kwtsms_sender_id">{__("kwtsms.settings.field.sender_id")}:</label>
                    <div class="controls">
                        <select name="kwtsms_settings[sender_id]" id="kwtsms_sender_id" class="kwtsms-js-sender">
                            <option value=""{if !$kwtsms.sender_in_list} selected="selected"{/if}>{__("kwtsms.settings.sender_choose")}</option>
                            {foreach $kwtsms.senders as $sender}
                                <option value="{$sender}"{if $kwtsms.sender_in_list && $sender == $s.sender_id} selected="selected"{/if}>{$sender}</option>
                            {/foreach}
                        </select>
                        <div class="alert alert-warning kwtsms-inline-alert kwtsms-js-test-sender-warning{if !$is_test_sender} hidden{/if}" id="kwtsms_test_sender_warning" role="status">
                            {__("kwtsms.settings.sender_test_warning")}
                        </div>
                    </div>
                </div>
                <div class="control-group">
                    <label class="control-label" for="kwtsms_sender_type">{__("kwtsms.settings.field.sender_type")}:</label>
                    <div class="controls">
                        <select name="kwtsms_settings[sender_type]" id="kwtsms_sender_type" class="kwtsms-js-sender-type">
                            <option value="transactional"{if $s.sender_type == "transactional"} selected="selected"{/if}>{__("kwtsms.settings.sender_type_transactional")}</option>
                            <option value="promotional"{if $s.sender_type == "promotional"} selected="selected"{/if}>{__("kwtsms.settings.sender_type_promotional")}</option>
                        </select>
                        <p class="muted description">{__("kwtsms.settings.sender_type_help")}</p>
                        <div class="alert alert-warning kwtsms-inline-alert kwtsms-js-promotional-warning{if $s.sender_type != "promotional"} hidden{/if}" role="status">
                            {__("kwtsms.settings.promotional_warning")}
                        </div>
                    </div>
                </div>
            {/if}
        </section>

        {* 2026-10-22 16:17 +03: Task A3, task 596. Sender ID per storefront. The controls use form="kwtsms_sf_senders_form" (the empty form
           after this one), their own Save button too. The test sender is never preselected; picking it shows the same warning as the
           main Sender ID, and the acknowledgement while test mode is off. *}
        {if $kwtsms.connected && $kwtsms.senders && $kwtsms.storefront_senders}
        <section class="kwtsms-section kwtsms-sf-senders" id="kwtsms_storefront_senders" aria-labelledby="kwtsms_sf_senders_title">
            <h4 class="subheader" id="kwtsms_sf_senders_title">{__("kwtsms.settings.sf_senders_title")}</h4>
            <p class="muted description">{__("kwtsms.settings.sf_senders_help")}</p>
            {if $kwtsms.main_sender}<p class="muted description kwtsms-sf-main" id="kwtsms_sf_main">{__("kwtsms.settings.sf_sender_main", ["[sender]" => $kwtsms.main_sender|fn_html_escape])}</p>{/if}
            {foreach $kwtsms.storefront_senders as $sf}
                <div class="control-group">
                    <label class="control-label" for="kwtsms_sf_sender_{$sf.id}">{$sf.name}:</label>
                    <div class="controls">
                        <select name="kwtsms_sf_senders[{$sf.id}]" id="kwtsms_sf_sender_{$sf.id}" form="kwtsms_sf_senders_form"
                                class="kwtsms-js-sf-sender" data-kwtsms-saved="{$sf.value}"
                                aria-label="{__("kwtsms.settings.sf_sender_aria", ["[storefront]" => $sf.name|fn_html_escape])}">
                            <option value=""{if $sf.value == ""} selected="selected"{/if}>{__("kwtsms.settings.sf_sender_same")}</option>
                            {foreach $kwtsms.senders as $sender}
                                <option value="{$sender}"{if $sender == $sf.value} selected="selected"{/if}>{$sender}</option>
                            {/foreach}
                            {if $sf.stale}
                                <option value="{$sf.value}" selected="selected">{__("kwtsms.settings.sf_sender_stale", ["[sender]" => $sf.value|fn_html_escape])}</option>
                            {/if}
                        </select>
                        <div class="alert alert-warning kwtsms-inline-alert kwtsms-js-sf-warning{if $sf.value != $kwtsms.test_sender} hidden{/if}" id="kwtsms_sf_warning_{$sf.id}" role="status">
                            {__("kwtsms.settings.sender_test_warning")}
                        </div>
                    </div>
                </div>
            {/foreach}
            <div class="control-group">
                <div class="controls">
                    <div class="kwtsms-ack kwtsms-js-sf-ack hidden" id="kwtsms_sf_ack_row">
                        <label class="checkbox"><input type="checkbox" id="kwtsms_sf_ack" name="kwtsms_sf_ack" value="Y" form="kwtsms_sf_senders_form"> {__("kwtsms.settings.ack_test_sender")}</label>
                    </div>
                    <input type="submit" class="btn" id="kwtsms_sf_senders_save" form="kwtsms_sf_senders_form" name="dispatch[kwtsms.update_storefront_senders]" value="{__("kwtsms.settings.sf_senders_save")}">
                </div>
            </div>
        </section>
        {elseif $kwtsms.storefront_senders}
        {* 2026-10-23 08:30 +0300: cleanup CA2-2 (A3 M5), task 596. Not connected, or no Sender ID listed yet: say why the block is not usable
           instead of hiding it (storefront_senders is only filled when the store has two or more storefronts). *}
        <section class="kwtsms-section kwtsms-sf-senders" id="kwtsms_storefront_senders" aria-labelledby="kwtsms_sf_senders_title">
            <h4 class="subheader" id="kwtsms_sf_senders_title">{__("kwtsms.settings.sf_senders_title")}</h4>
            <p class="muted description" id="kwtsms_sf_senders_connect">{__("kwtsms.settings.sf_senders_connect")}</p>
        </section>
        {/if}

        <section class="kwtsms-section" id="kwtsms_general" aria-labelledby="kwtsms_general_title">
            <h4 class="subheader" id="kwtsms_general_title">{__("kwtsms.settings.general")}</h4>

            <div class="control-group">
                <label class="control-label" for="kwtsms_enabled">{__("kwtsms.settings.field.enabled")}:</label>
                <div class="controls">
                    <input type="hidden" name="kwtsms_settings[enabled]" value="N">
                    <label class="checkbox"><input type="checkbox" id="kwtsms_enabled" name="kwtsms_settings[enabled]" value="Y"{if $s.enabled == "Y"} checked="checked"{/if}> {__("kwtsms.settings.enabled_label")}</label>
                    <p class="muted description">{__("kwtsms.settings.enabled_help")}</p>
                    {if !$kwtsms.configured}
                        <p class="kwtsms-hint" id="kwtsms_enable_reason">{__("kwtsms.settings.enable_blocked", ["[reason]" => $kwtsms.configured_reason|fn_html_escape])}</p>
                    {/if}
                </div>
            </div>

            <div class="control-group">
                <label class="control-label" for="kwtsms_test_mode">{__("kwtsms.settings.field.test_mode")}:</label>
                <div class="controls">
                    <input type="hidden" name="kwtsms_settings[test_mode]" value="N">
                    <label class="checkbox"><input type="checkbox" id="kwtsms_test_mode" class="kwtsms-js-test-mode" name="kwtsms_settings[test_mode]" value="Y"{if $s.test_mode == "Y"} checked="checked"{/if}> {__("kwtsms.settings.test_mode_label")}</label>
                    <p class="muted description">{__("kwtsms.settings.test_mode_help")}</p>
                    <div class="kwtsms-ack kwtsms-js-ack{if !($is_test_sender && $s.test_mode == "Y")} hidden{/if}" id="kwtsms_ack_row">
                        <label class="checkbox"><input type="checkbox" id="kwtsms_ack_test_sender" name="kwtsms_ack_test_sender" value="Y"> {__("kwtsms.settings.ack_test_sender")}</label>
                    </div>
                </div>
            </div>

            <div class="control-group">
                <label class="control-label" for="kwtsms_pilot_mode">{__("kwtsms.settings.field.pilot_mode")}:</label>
                <div class="controls">
                    <input type="hidden" name="kwtsms_settings[pilot_mode]" value="N">
                    <label class="checkbox"><input type="checkbox" id="kwtsms_pilot_mode" name="kwtsms_settings[pilot_mode]" value="Y"{if $s.pilot_mode == "Y"} checked="checked"{/if}> {__("kwtsms.settings.pilot_mode_label")}</label>
                    <p class="muted description">{__("kwtsms.settings.pilot_mode_help")}</p>
                </div>
            </div>

            <div class="control-group">
                <label class="control-label" for="kwtsms_default_country">{__("kwtsms.settings.field.default_country_code")}:</label>
                <div class="controls">
                    <select name="kwtsms_settings[default_country_code]" id="kwtsms_default_country" class="kwtsms-select-wide">
                        {foreach $kwtsms.countries as $code => $label}
                            <option value="{$code}"{if $code == $s.default_country_code} selected="selected"{/if}>{$label}</option>
                        {/foreach}
                    </select>
                    <p class="muted description">{__("kwtsms.settings.default_country_help")}</p>
                </div>
            </div>

            <div class="control-group">
                <label class="control-label" for="kwtsms_customer_countries">{__("kwtsms.settings.field.customer_sms_countries")}:</label>
                <div class="controls">
                    <input type="hidden" name="kwtsms_settings[customer_sms_countries][]" value="">
                    <select name="kwtsms_settings[customer_sms_countries][]" id="kwtsms_customer_countries" class="kwtsms-select-wide" multiple="multiple" size="6">
                        {foreach $kwtsms.countries as $code => $label}
                            <option value="{$code}"{if $code|in_array:$s.customer_sms_countries} selected="selected"{/if}>{$label}</option>
                        {/foreach}
                    </select>
                    {* 2026-10-24 15:29 +03: Task M1.5 (SafatMall C1, P3-15), task 596. Ticks the six GCC calling codes in the list above (backend.js); saved by the page's own Save. *}
                    <p><button type="button" class="btn kwtsms-js-add-gcc" id="kwtsms_add_gcc" data-kwtsms-target="kwtsms_customer_countries" data-kwtsms-gcc="{$kwtsms.codes.gcc}" aria-describedby="kwtsms_add_gcc_help">{__("kwtsms.settings.add_gcc")}</button></p>
                    <p class="muted description" id="kwtsms_add_gcc_help">{__("kwtsms.settings.add_gcc_help")}</p>
                    <p class="muted description">{__("kwtsms.settings.customer_countries_help")}</p>
                </div>
            </div>

            <div class="control-group">
                <span class="control-label">{__("kwtsms.settings.field.placed_status_extra")}:</span>
                <div class="controls">
                    <input type="hidden" name="kwtsms_settings[placed_status_extra][]" value="">
                    <div class="kwtsms-checklist">
                        {foreach $kwtsms.order_statuses as $status_code => $status_name}
                            <label class="checkbox"><input type="checkbox" name="kwtsms_settings[placed_status_extra][]" value="{$status_code}"{if $status_code|in_array:$s.placed_status_extra} checked="checked"{/if}> {$status_name}</label>
                        {/foreach}
                    </div>
                    <p class="muted description">{__("kwtsms.settings.placed_statuses_help")}</p>
                </div>
            </div>

            <div class="control-group">
                <label class="control-label" for="kwtsms_max_pages">{__("kwtsms.settings.field.max_pages_notification")}:</label>
                <div class="controls">
                    <select name="kwtsms_settings[max_pages_notification]" id="kwtsms_max_pages" class="input-mini">
                        {foreach $kwtsms.max_pages as $pages}
                            <option value="{$pages}"{if $pages == $s.max_pages_notification} selected="selected"{/if}>{$pages}</option>
                        {/foreach}
                    </select>
                    <p class="muted description">{__("kwtsms.settings.max_pages_help")}</p>
                </div>
            </div>
        </section>

        <section class="kwtsms-section" id="kwtsms_limits" aria-labelledby="kwtsms_limits_title">
            <h4 class="subheader" id="kwtsms_limits_title">{__("kwtsms.settings.limits")}</h4>
            {foreach ["cap_recipient_hour" => 1000, "cap_recipient_day" => 10000, "cap_admin_day" => 100000, "cap_store_day" => 1000000] as $cap => $cap_max}
                <div class="control-group">
                    <label class="control-label" for="kwtsms_{$cap}">{__("kwtsms.settings.field.`$cap`")}:</label>
                    <div class="controls">
                        <input type="number" id="kwtsms_{$cap}" name="kwtsms_settings[{$cap}]" value="{$s.$cap}" min="1" max="{$cap_max}" step="1" class="input-small" dir="ltr">
                        {if $cap == "cap_store_day"}<p class="muted description">{__("kwtsms.settings.cap_store_day_help")}</p>{/if}
                    </div>
                </div>
            {/foreach}
            <div class="control-group">
                <label class="control-label" for="kwtsms_low_balance">{__("kwtsms.settings.field.low_balance_threshold")}:</label>
                <div class="controls">
                    <input type="number" id="kwtsms_low_balance" name="kwtsms_settings[low_balance_threshold]" value="{$s.low_balance_threshold}" min="0" max="10000000" step="1" class="input-small" dir="ltr">
                    <p class="muted description">{__("kwtsms.settings.low_balance_help")}</p>
                </div>
            </div>
        </section>

        <section class="kwtsms-section" id="kwtsms_quiet" aria-labelledby="kwtsms_quiet_title">
            <h4 class="subheader" id="kwtsms_quiet_title">{__("kwtsms.settings.quiet_hours")}</h4>
            <div class="control-group">
                <label class="control-label" for="kwtsms_quiet_enabled">{__("kwtsms.settings.field.quiet_hours_enabled")}:</label>
                <div class="controls">
                    <input type="hidden" name="kwtsms_settings[quiet_hours_enabled]" value="N">
                    <label class="checkbox"><input type="checkbox" id="kwtsms_quiet_enabled" name="kwtsms_settings[quiet_hours_enabled]" value="Y"{if $s.quiet_hours_enabled == "Y"} checked="checked"{/if}> {__("kwtsms.settings.quiet_hours_label")}</label>
                    <p class="muted description">{__("kwtsms.settings.quiet_hours_help")}</p>
                </div>
            </div>
            <div class="control-group">
                <label class="control-label" for="kwtsms_quiet_from">{__("kwtsms.settings.field.quiet_hours_from")}:</label>
                <div class="controls">
                    <input type="time" id="kwtsms_quiet_from" name="kwtsms_settings[quiet_hours_from]" value="{$s.quiet_hours_from}" class="input-small" dir="ltr">
                </div>
            </div>
            <div class="control-group">
                <label class="control-label" for="kwtsms_quiet_to">{__("kwtsms.settings.field.quiet_hours_to")}:</label>
                <div class="controls">
                    <input type="time" id="kwtsms_quiet_to" name="kwtsms_settings[quiet_hours_to]" value="{$s.quiet_hours_to}" class="input-small" dir="ltr">
                </div>
            </div>
        </section>

        <section class="kwtsms-section" id="kwtsms_logs" aria-labelledby="kwtsms_logs_title">
            <h4 class="subheader" id="kwtsms_logs_title">{__("kwtsms.settings.logs")}</h4>
            <div class="control-group">
                <label class="control-label" for="kwtsms_log_level">{__("kwtsms.settings.field.log_level")}:</label>
                <div class="controls">
                    <select name="kwtsms_settings[log_level]" id="kwtsms_log_level">
                        <option value="info"{if $s.log_level == "info"} selected="selected"{/if}>{__("kwtsms.settings.log_level_info")}</option>
                        <option value="debug"{if $s.log_level == "debug"} selected="selected"{/if}>{__("kwtsms.settings.log_level_debug")}</option>
                    </select>
                </div>
            </div>
            {foreach ["retention_messages_days", "retention_attempts_days", "retention_debug_days"] as $retention}
                <div class="control-group">
                    <label class="control-label" for="kwtsms_{$retention}">{__("kwtsms.settings.field.`$retention`")}:</label>
                    <div class="controls">
                        {* 2026-10-21 02:36 +03: field and unit on one line (the unit sat above the baseline), Task 1.10, task 596. *}
                        <span class="kwtsms-with-unit">
                            <input type="number" id="kwtsms_{$retention}" name="kwtsms_settings[{$retention}]" value="{$s.$retention}" min="0" max="3650" step="1" class="input-mini" dir="ltr">
                            <span class="kwtsms-unit">{__("kwtsms.settings.days")}</span>
                        </span>
                    </div>
                </div>
            {/foreach}
            <p class="muted kwtsms-section__note">{__("kwtsms.settings.retention_help")}</p>
        </section>

        <section class="kwtsms-section" id="kwtsms_cron" aria-labelledby="kwtsms_cron_title">
            <h4 class="subheader" id="kwtsms_cron_title">{__("kwtsms.settings.cron")}</h4>
            <div class="control-group">
                <label class="control-label" for="kwtsms_cron_command">{__("kwtsms.settings.cron_command")}:</label>
                <div class="controls">
                    <input type="text" id="kwtsms_cron_command" class="kwtsms-code" value="* * * * * {$kwtsms.cron_command}" readonly="readonly" dir="ltr">
                    <p class="muted description">{__("kwtsms.settings.cron_help")}</p>
                </div>
            </div>
            <div class="control-group">
                <span class="control-label">{__("kwtsms.settings.cron_last_run")}:</span>
                <div class="controls">
                    <p class="kwtsms-static" id="kwtsms_cron_last_run"><bdi>{if $kwtsms.cron_last_run}{$kwtsms.cron_last_run|date_format:"`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}{else}{__("kwtsms.settings.cron_never")}{/if}</bdi></p>
                </div>
            </div>
            <div class="control-group">
                <label class="control-label" for="kwtsms_cron_url_enabled">{__("kwtsms.settings.field.cron_url_enabled")}:</label>
                <div class="controls">
                    <input type="hidden" name="kwtsms_settings[cron_url_enabled]" value="N">
                    <label class="checkbox"><input type="checkbox" id="kwtsms_cron_url_enabled" name="kwtsms_settings[cron_url_enabled]" value="Y"{if $s.cron_url_enabled == "Y"} checked="checked"{/if}> {__("kwtsms.settings.cron_url_label")}</label>
                    <p class="muted description">{__("kwtsms.settings.cron_url_help")}</p>
                </div>
            </div>
        </section>
    </form>
    {* 2026-10-22 16:17 +03: Task A3, task 596. The form of the Sender ID per storefront controls above (form attribute). *}
    {if $kwtsms.connected && $kwtsms.senders && $kwtsms.storefront_senders}
        <form action="{""|fn_url}" method="post" name="kwtsms_sf_senders_form" id="kwtsms_sf_senders_form" class="kwtsms-inline-form"></form>
    {/if}

    {* Cron key: outside the settings form, so its button is its own form. *}
    <div class="form-horizontal form-edit kwtsms-section kwtsms-section--follow" id="kwtsms_cron_key_block">
        <div class="control-group">
            <label class="control-label" for="kwtsms_cron_key">{__("kwtsms.settings.cron_key")}:</label>
            <div class="controls">
                <input type="text" id="kwtsms_cron_key" class="kwtsms-code" value="{$kwtsms.cron_key}" readonly="readonly" dir="ltr" autocomplete="off">
                <p class="muted description"><bdi dir="ltr">curl -fsS -X POST -H 'X-Kwtsms-Cron-Key: {$kwtsms.cron_key}' '{$kwtsms.cron_url}'</bdi></p>
                <form action="{""|fn_url}" method="post" name="kwtsms_cron_key_form" class="kwtsms-inline-form">
                    <input type="submit" class="btn cm-confirm" id="kwtsms_regenerate_key" name="dispatch[kwtsms.regenerate_cron_key]"
                           value="{__("kwtsms.settings.regenerate_key")}" data-ca-confirm-text="{__("kwtsms.settings.regenerate_confirm")}">
                </form>
            </div>
        </div>
    </div>

    {* 2026-10-23 15:15 +03: Task M1.1a (P3-23), task 596. Shell of the "Phone and codes" section: its own form (Config mode
       update_codes, POST, redirect back to #kwtsms_codes). Each later task of Milestone 1 adds its own fields inside the form
       as kwtsms_codes[<setting id>]. The Turnstile secret key is never rendered back: its field is empty and an empty post
       keeps the stored key. *}
    <section class="kwtsms-section" id="kwtsms_codes" aria-labelledby="kwtsms_codes_title">
        <h4 class="subheader" id="kwtsms_codes_title">{__("kwtsms.settings.codes_title")}</h4>
        <p class="muted description" id="kwtsms_codes_help">{__("kwtsms.settings.codes_help")}</p>
        <form action="{""|fn_url}" method="post" name="kwtsms_codes_form" id="kwtsms_codes_form" class="form-horizontal form-edit" autocomplete="off">
            {* 2026-10-23 19:58 +03: Task M1.2 (P3-6, P3-7, P3-26), task 596. Per-IP request limits, the store hourly cap and
               the trusted proxy list. Values come from $kwtsms.codes (SettingsPage::codesViewData()). *}
            {foreach ["cap_ip_hour" => 10000, "cap_ip_day" => 10000] as $cap => $cap_max}
                <div class="control-group">
                    <label class="control-label" for="kwtsms_{$cap}">{__("kwtsms.settings.field.`$cap`")}:</label>
                    <div class="controls">
                        <input type="number" id="kwtsms_{$cap}" name="kwtsms_codes[{$cap}]" value="{$kwtsms.codes.$cap}" min="1" max="{$cap_max}" step="1" class="input-small" dir="ltr">
                        {if $cap == "cap_ip_day"}<p class="muted description">{__("kwtsms.settings.cap_ip_help")}</p>{/if}
                    </div>
                </div>
            {/foreach}
            {* 2026-10-24 23:51 +03: Task M1.8a (P3-34, SafatMall E2), task 596. The per-phone request cap and the three
               thresholds after which a code request needs a CAPTCHA token (Turnstile "only when suspicious"). id =>
               [min, max, help key]. *}
            {foreach [
                "cap_phone_hour" => [1, 100, "cap_phone_help"],
                "captcha_after_ip" => [1, 1000, ""],
                "captcha_after_subject" => [1, 1000, ""],
                "captcha_after_store" => [1, 100000, "captcha_after_help"]
            ] as $thr_key => $thr_spec}
                <div class="control-group">
                    <label class="control-label" for="kwtsms_{$thr_key}">{__("kwtsms.settings.field.`$thr_key`")}:</label>
                    <div class="controls">
                        <input type="number" id="kwtsms_{$thr_key}" name="kwtsms_codes[{$thr_key}]" value="{$kwtsms.codes.$thr_key}" min="{$thr_spec[0]}" max="{$thr_spec[1]}" step="1" class="input-small" dir="ltr">
                        {if $thr_spec[2]}<p class="muted description">{__("kwtsms.settings.`$thr_spec[2]`")}</p>{/if}
                    </div>
                </div>
            {/foreach}
            <div class="control-group">
                <label class="control-label" for="kwtsms_cap_store_hour">{__("kwtsms.settings.field.cap_store_hour")}:</label>
                <div class="controls">
                    <input type="number" id="kwtsms_cap_store_hour" name="kwtsms_codes[cap_store_hour]" value="{$kwtsms.codes.cap_store_hour}" min="0" max="100000" step="1" class="input-small" dir="ltr">
                    <p class="muted description">{__("kwtsms.settings.cap_store_hour_help")}</p>
                </div>
            </div>
            <div class="control-group">
                <label class="control-label" for="kwtsms_trusted_proxies">{__("kwtsms.settings.field.trusted_proxies")}:</label>
                <div class="controls">
                    <textarea id="kwtsms_trusted_proxies" name="kwtsms_codes[trusted_proxies]" rows="4" class="input-large kwtsms-code" dir="ltr" spellcheck="false" placeholder="10.0.0.0/8">{$kwtsms.codes.trusted_proxies}</textarea>
                    <p class="muted description">{__("kwtsms.settings.trusted_proxies_help")}</p>
                </div>
            </div>
            {* 2026-10-23 21:33 +03: Task M1.3 (P3-2, P3-3, P3-29), task 596. Code lifetime, wrong-attempt limits, resend
               waits and the per-IP verify limit. Numbers: id => [min, max, help key]; the waits are one text field. *}
            {foreach [
                "otp_expiry_seconds" => [60, 900, "otp_expiry_help"],
                "otp_max_attempts" => [3, 10, "otp_attempts_help"],
                "otp_lockout_seconds" => [60, 86400, "otp_lockout_help"],
                "otp_phone_fail_ceiling" => [5, 1000, "otp_ceiling_help"]
            ] as $otp_key => $otp_spec}
                <div class="control-group">
                    <label class="control-label" for="kwtsms_{$otp_key}">{__("kwtsms.settings.field.`$otp_key`")}:</label>
                    <div class="controls">
                        <input type="number" id="kwtsms_{$otp_key}" name="kwtsms_codes[{$otp_key}]" value="{$kwtsms.codes.$otp_key}" min="{$otp_spec[0]}" max="{$otp_spec[1]}" step="1" class="input-small" dir="ltr">
                        <p class="muted description">{__("kwtsms.settings.`$otp_spec[2]`")}</p>
                    </div>
                </div>
            {/foreach}
            <div class="control-group">
                <label class="control-label" for="kwtsms_otp_resend_waits">{__("kwtsms.settings.field.otp_resend_waits")}:</label>
                <div class="controls">
                    <input type="text" id="kwtsms_otp_resend_waits" name="kwtsms_codes[otp_resend_waits]" value="{$kwtsms.codes.otp_resend_waits}" inputmode="numeric" class="input-medium kwtsms-code" dir="ltr" spellcheck="false" placeholder="60,120,300">
                    <p class="muted description">{__("kwtsms.settings.otp_resend_help")}</p>
                </div>
            </div>
            <div class="control-group">
                <label class="control-label" for="kwtsms_cap_ip_verify_hour">{__("kwtsms.settings.field.cap_ip_verify_hour")}:</label>
                <div class="controls">
                    <input type="number" id="kwtsms_cap_ip_verify_hour" name="kwtsms_codes[cap_ip_verify_hour]" value="{$kwtsms.codes.cap_ip_verify_hour}" min="5" max="10000" step="1" class="input-small" dir="ltr">
                    <p class="muted description">{__("kwtsms.settings.cap_ip_verify_help")}</p>
                </div>
            </div>
            {* 2026-10-24 12:38 +03: Task M1.4 (SafatMall I3; P3-13, P3-24), task 596. What a code feature does when SMS codes cannot be sent (gateway down, balance, pilot mode, sender): pause (refuse) or continue (let the form through, unverified, and alert). *}
            <div class="control-group">
                <label class="control-label" for="kwtsms_otp_unreachable">{__("kwtsms.settings.field.otp_unreachable")}:</label>
                <div class="controls">
                    <select name="kwtsms_codes[otp_unreachable]" id="kwtsms_otp_unreachable" class="input-xlarge" style="min-width: 22em; max-width: 100%;" aria-describedby="kwtsms_otp_unreachable_help">
                        <option value="pause"{if $kwtsms.codes.otp_unreachable != "continue"} selected="selected"{/if}>{__("kwtsms.settings.otp_unreachable_pause")}</option>
                        <option value="continue"{if $kwtsms.codes.otp_unreachable == "continue"} selected="selected"{/if}>{__("kwtsms.settings.otp_unreachable_continue")}</option>
                    </select>
                    <p class="muted description" id="kwtsms_otp_unreachable_help">{__("kwtsms.settings.otp_unreachable_help")}</p>
                </div>
            </div>
            {* 2026-10-24 15:29 +03: Task M1.5 (SafatMall C3, P3-15), task 596. The server-side phone check switch (default OFF). The hidden N is posted when the box is cleared. *}
            <div class="control-group">
                <span class="control-label">{__("kwtsms.settings.field.phone_country_check")}:</span>
                <div class="controls">
                    <input type="hidden" name="kwtsms_codes[phone_country_check]" value="N">
                    <label class="checkbox"><input type="checkbox" id="kwtsms_phone_country_check" name="kwtsms_codes[phone_country_check]" value="Y"{if $kwtsms.codes.phone_country_check == "Y"} checked="checked"{/if} aria-describedby="kwtsms_phone_country_check_help"> {__("kwtsms.settings.phone_country_check_label")}</label>
                    <p class="muted description" id="kwtsms_phone_country_check_help">{__("kwtsms.settings.phone_country_check_help")}</p>
                </div>
            </div>
            {* 2026-10-24 17:15 +03: Task M1.6 (SafatMall C4, P3-31), task 596. One account per phone (default OFF). While the phone index is being built the state line reads "Starts after indexing: n of m customers". *}
            <div class="control-group">
                <span class="control-label">{__("kwtsms.settings.field.phone_unique")}:</span>
                <div class="controls">
                    <input type="hidden" name="kwtsms_codes[phone_unique]" value="N">
                    <label class="checkbox"><input type="checkbox" id="kwtsms_phone_unique" name="kwtsms_codes[phone_unique]" value="Y"{if $kwtsms.codes.phone_unique == "Y"} checked="checked"{/if} aria-describedby="kwtsms_phone_unique_help{if $kwtsms.codes.phone_unique_note} kwtsms_phone_unique_state{/if}"> {__("kwtsms.settings.phone_unique_label")}</label>
                    <p class="muted description" id="kwtsms_phone_unique_help">{__("kwtsms.settings.phone_unique_help")}</p>
                    {if $kwtsms.codes.phone_unique_note}<p class="description" id="kwtsms_phone_unique_state" role="status">{$kwtsms.codes.phone_unique_note}</p>{/if}
                </div>
            </div>
            <div class="control-group">
                <div class="controls kwtsms-actions">
                    <input type="submit" class="btn btn-primary" id="kwtsms_codes_save" name="dispatch[kwtsms.update_codes]" value="{__("kwtsms.settings.codes_save")}">
                </div>
            </div>
        </form>
    </section>

    {* 2026-10-24 21:11 +03: Task M1.7b (PRD 6.15, P3-R7, P3-32), task 596. The "CAPTCHA (Cloudflare Turnstile)" section: its own
       form (Config mode update_codes; Check keys is the POST mode turnstile_check), redirect back to #kwtsms_turnstile. The
       secret key is never rendered back: its field is empty, autocomplete="new-password", and an empty post keeps the saved
       key. *}
    <section class="kwtsms-section" id="kwtsms_turnstile" aria-labelledby="kwtsms_turnstile_title">
        <h4 class="subheader" id="kwtsms_turnstile_title">{__("kwtsms.turnstile.title")}</h4>
        <p class="muted description" id="kwtsms_turnstile_help">{__("kwtsms.turnstile.help")}</p>
        {$ts = $kwtsms.turnstile}
        <p class="description" id="kwtsms_turnstile_status" role="status">
            {if $ts.driver_active}{__("kwtsms.turnstile.status_on")}{else}{__("kwtsms.turnstile.status_off")}{/if}
            {if $ts.recaptcha_blocks}{__("kwtsms.turnstile.recaptcha_blocks")}{elseif $ts.recaptcha_active}{__("kwtsms.turnstile.recaptcha_unconfigured")}{/if}
        </p>
        {if $ts.test_keys}<p class="alert alert-warning" id="kwtsms_turnstile_test_keys" role="alert">{__("kwtsms.turnstile.test_keys")}</p>{/if}
        <form action="{""|fn_url}" method="post" name="kwtsms_turnstile_form" id="kwtsms_turnstile_form" class="form-horizontal form-edit" autocomplete="off">
            <input type="hidden" name="kwtsms_anchor" value="kwtsms_turnstile">
            <div class="control-group">
                <span class="control-label">{__("kwtsms.settings.field.turnstile_enabled")}:</span>
                <div class="controls">
                    <input type="hidden" name="kwtsms_codes[turnstile_enabled]" value="N">
                    {* 2026-10-24 22:31 +03: Task M1.7b, task 596. A plain label with an inline checkbox (not Bootstrap's .checkbox, whose negative margin put the box over the first letters of the right-to-left field label). *}
                    <label for="kwtsms_turnstile_enabled"><input type="checkbox" id="kwtsms_turnstile_enabled" name="kwtsms_codes[turnstile_enabled]" value="Y"{if $ts.enabled == "Y"} checked="checked"{/if} aria-describedby="kwtsms_turnstile_enabled_help" style="margin: 0 0.5em; margin-inline: 0 0.5em;"> {__("kwtsms.turnstile.enabled_label")}</label>
                    <p class="muted description" id="kwtsms_turnstile_enabled_help">{__("kwtsms.turnstile.enabled_help")}</p>
                </div>
            </div>
            <div class="control-group">
                <label class="control-label" for="kwtsms_turnstile_site_key">{__("kwtsms.turnstile.site_key")}:</label>
                <div class="controls">
                    <input type="text" id="kwtsms_turnstile_site_key" name="kwtsms_codes[turnstile_site_key]" value="{$ts.site_key}" maxlength="64" class="input-large kwtsms-code" dir="ltr" spellcheck="false" autocomplete="off" aria-describedby="kwtsms_turnstile_site_key_help">
                    <p class="muted description" id="kwtsms_turnstile_site_key_help">{__("kwtsms.turnstile.site_key_help")}</p>
                </div>
            </div>
            <div class="control-group">
                <label class="control-label" for="kwtsms_turnstile_secret">{__("kwtsms.turnstile.secret")}:</label>
                <div class="controls">
                    <input type="password" name="kwtsms_codes[turnstile_secret]" id="kwtsms_turnstile_secret" autocomplete="new-password" maxlength="128" class="input-large kwtsms-code" dir="ltr" spellcheck="false" aria-describedby="kwtsms_turnstile_secret_help">
                    <p class="muted description" id="kwtsms_turnstile_secret_help">{__("kwtsms.turnstile.secret_help")}{if $ts.secret_saved} <strong>{__("kwtsms.turnstile.secret_saved")}</strong>{/if}</p>
                </div>
            </div>
            <div class="control-group">
                <div class="controls kwtsms-actions">
                    <input type="submit" class="btn btn-primary" id="kwtsms_turnstile_save" name="dispatch[kwtsms.update_codes]" value="{__("kwtsms.turnstile.save")}">
                    <input type="submit" class="btn" id="kwtsms_turnstile_check" name="dispatch[kwtsms.turnstile_check]" value="{__("kwtsms.turnstile.check")}" formnovalidate="formnovalidate">
                    <p class="muted description">{__("kwtsms.turnstile.check_help")}</p>
                </div>
            </div>
        </form>
    </section>

    {* ---- test SMS ----------------------------------------------------------------------------------------------- *}
    <section class="kwtsms-section" id="kwtsms_test_sms" aria-labelledby="kwtsms_test_sms_title">
        <h4 class="subheader" id="kwtsms_test_sms_title">{__("kwtsms.test_sms.title")}</h4>

        {if $kwtsms.test_result}
            {$r = $kwtsms.test_result}
            <div class="kwtsms-result kwtsms-result--{$r.status}" id="kwtsms_test_result" role="status">
                <h5 class="kwtsms-result__title">{__("kwtsms.test_sms.result")}</h5>
                <dl class="kwtsms-result__list">
                    <dt>{__("kwtsms.test_sms.to")}</dt><dd><bdi dir="ltr" id="kwtsms_result_phone">{$r.phone}</bdi></dd>
                    <dt>{__("kwtsms.test_sms.outcome")}</dt><dd id="kwtsms_result_outcome" data-kwtsms-outcome="{$r.outcome}">{$r.outcome_text}</dd>
                    {if $r.error}<dt>{__("kwtsms.test_sms.details")}</dt><dd id="kwtsms_result_error">{$r.error}</dd>{/if}
                    {if $r.msg_id}<dt>{__("kwtsms.test_sms.msg_id")}</dt><dd><bdi dir="ltr" id="kwtsms_result_msg_id">{$r.msg_id}</bdi></dd>{/if}
                    {if $r.points !== ""}<dt>{__("kwtsms.test_sms.points")}</dt><dd><bdi id="kwtsms_result_points">{$r.points}</bdi></dd>{/if}
                    {if $r.balance_after !== ""}<dt>{__("kwtsms.test_sms.balance_after")}</dt><dd><bdi id="kwtsms_result_balance">{$r.balance_after}</bdi></dd>{/if}
                </dl>
                {if $r.test}
                    <p class="kwtsms-result__note" id="kwtsms_result_test_note">{__("kwtsms.test_sms.test_note")}</p>
                {/if}
            </div>
        {/if}

        <form action="{""|fn_url}" method="post" name="kwtsms_test_form" id="kwtsms_test_form" class="form-horizontal form-edit" autocomplete="off">
            <div class="control-group">
                <label class="control-label cm-required" for="kwtsms_test_phone">{__("kwtsms.test_sms.phone")}:</label>
                <div class="controls">
                    <input type="text" id="kwtsms_test_phone" name="kwtsms_test[phone]" value="" class="input-medium" dir="ltr"
                           inputmode="tel" autocomplete="off" maxlength="32" placeholder="9876 5432">
                    <label class="checkbox"><input type="checkbox" id="kwtsms_test_intl" name="kwtsms_test[intl]" value="Y"> {__("kwtsms.test_sms.intl")}</label>
                    <p class="muted description">{__("kwtsms.test_sms.intl_help", ["[country]" => $kwtsms.default_country_label|fn_html_escape])}</p>
                </div>
            </div>
            <div class="control-group">
                <label class="control-label" for="kwtsms_test_suffix">{__("kwtsms.test_sms.suffix")}:</label>
                <div class="controls">
                    <input type="text" id="kwtsms_test_suffix" name="kwtsms_test[suffix]" value="" class="input-large" maxlength="{$kwtsms.test_sms_suffix_max}" autocomplete="off">
                    <p class="muted description">{__("kwtsms.test_sms.suffix_help", ["[max]" => $kwtsms.test_sms_suffix_max|fn_html_escape])}</p>
                </div>
            </div>
            <div class="control-group">
                <span class="control-label">{__("kwtsms.test_sms.text")}:</span>
                <div class="controls">
                    <p class="kwtsms-static kwtsms-message" id="kwtsms_test_text"><bdi>{$kwtsms.test_sms_text}</bdi></p>
                </div>
            </div>
            <div class="control-group">
                <div class="controls kwtsms-actions">
                    <input type="submit" class="btn btn-primary" id="kwtsms_test_send" name="dispatch[kwtsms.test_sms]" value="{__("kwtsms.test_sms.send")}">
                    <span class="muted">{__("kwtsms.test_sms.limit_help", ["[max]" => $kwtsms.test_sms_per_hour|fn_html_escape])}</span>
                </div>
            </div>
        </form>
    </section>
</div>
{/capture}

{capture name="buttons"}
    {include file="buttons/save.tpl" but_name="dispatch[kwtsms.save_settings]" but_role="submit-link" but_target_form="kwtsms_settings_form"}
{/capture}

{include file="common/mainbox.tpl"
    title=__("kwtsms.settings.title")
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
    select_storefront=false
}
