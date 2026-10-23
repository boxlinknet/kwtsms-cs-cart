{*
FILE:      design/backend/templates/addons/kwtsms/views/kwtsms/help.tpl
CREATED:   2026-10-21 04:43 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      The kwtSMS Help page (English and Arabic through language variables): what the add-on does, the kwtSMS account and
           Sender ID, the setup steps, the go-live checklist, SMS switches and Notify boxes, placeholders, what an SMS costs,
           cron (the exact line for this install, the URL mode, the 504 note, the lock note), test mode and held credits, IP
           lockdown and proxies, "message not received", what to send support, and the links.
WHY:       PRD 6.10 and 6.11. Plain documentation under the .kwtsms-page root: a topic list beside the text at 1280 px, above
           it at 768 px. Only what depends on the install is computed (HelpPage::viewData()): the crontab line, the URL-mode
           line with a placeholder instead of the real key, the lock backend, the SMS lengths, the placeholders and which
           add-on pages the administrator may open (a link to a page they can't open would end on "Access denied"). Every
           value is auto-escaped (no nofilter); the lines of code sit in <pre dir="ltr">; external links open in a new tab
           with rel="noopener noreferrer".
USAGE:     Rendered by kwtsms.help (controllers/backend/kwtsms.php) with $kwtsms_help = Admin\HelpPage::viewData() and
           $kwtsms_nav = Admin\Navigation::sections().
DEPENDS:   components/{navigation,help_page_link,help_ext_link}.tpl, help.less, kwtsms.help.* and kwtsms.nav.* language
           variables.
*}
{* 2026-10-23 13:16 +03: final review A, Important 2, task 596. Every value in a __() placeholder array now passes |fn_html_escape (values that never had a modifier too); tests/Unit/TemplateEscapeTest enforces it. *}
{* 2026-10-23 10:00 +03: Task A14 fix, task 596. The core Smarty runs with escape_html on and DROPS |escape:"html" inside an expression (a __() placeholder
   array, a backtick interpolation): the value stayed raw. A storefront name printed raw in the Settings aria-label and the Templates hint
   (stored XSS, tests/Store/A14XssStoreTest). Every value placed into a __() placeholder now goes through |fn_html_escape, which escapes
   < > & and both quotes (tests/Unit/TemplateEscapeTest keeps the dropped modifier out). *}
{$h = $kwtsms_help}
{* 2026-10-23 04:23 +03: Task A13, task 596: the topic list comes from HelpPage::viewData() (vendors only in Multi-Vendor). *}
{$topics = $h.topics}
{capture name="mainbox"}
<div class="kwtsms-page kwtsms-help" id="kwtsms_help_page">

    {include file="addons/kwtsms/views/kwtsms/components/navigation.tpl" sections=$kwtsms_nav}

    <p class="kwtsms-intro">{__("kwtsms.help.intro")}</p>

    <div class="kwtsms-help__layout">
        <nav class="kwtsms-help__toc" id="kwtsms_help_toc" aria-labelledby="kwtsms_help_toc_title">
            <h5 class="kwtsms-help__toc-title" id="kwtsms_help_toc_title">{__("kwtsms.help.toc")}</h5>
            <ol>
                {foreach $topics as $topic}
                    <li><a href="#kwtsms_help_{$topic}">{__("kwtsms.help.`$topic`.title")}</a></li>
                {/foreach}
            </ol>
        </nav>

        <div class="kwtsms-help__body">

            {* ---- what it does ---------------------------------------------------------------------------------- *}
            <section class="kwtsms-section kwtsms-help__section" id="kwtsms_help_overview" aria-labelledby="kwtsms_help_overview_title">
                <h4 class="subheader" id="kwtsms_help_overview_title">{__("kwtsms.help.overview.title")}</h4>
                <p>{__("kwtsms.help.overview.p1")}</p>
                <ul>
                    <li>{__("kwtsms.help.overview.li1")}</li>
                    <li>{__("kwtsms.help.overview.li2")}</li>
                    <li>{__("kwtsms.help.overview.li3")}</li>
                    <li>{__("kwtsms.help.overview.li4")}</li>
                </ul>
                <p>{__("kwtsms.help.overview.p2")}</p>
                {* 2026-10-22 23:56 +03: Task A8 fix round 1 (M8), task 596 *}
                <p>{__("kwtsms.help.overview.p3")}</p>
            </section>

            {* ---- the kwtSMS account ---------------------------------------------------------------------------- *}
            <section class="kwtsms-section kwtsms-help__section" id="kwtsms_help_account" aria-labelledby="kwtsms_help_account_title">
                <h4 class="subheader" id="kwtsms_help_account_title">{__("kwtsms.help.account.title")}</h4>
                <p>{__("kwtsms.help.account.p1")} {include file="addons/kwtsms/views/kwtsms/components/help_ext_link.tpl" key="home" links=$h.links}</p>
                <p>{__("kwtsms.help.account.p2")} {include file="addons/kwtsms/views/kwtsms/components/help_ext_link.tpl" key="api_docs" links=$h.links}</p>

                <h5 class="kwtsms-subtitle">{__("kwtsms.help.account.sender_title")}</h5>
                <p>{__("kwtsms.help.account.sender_p1")}</p>
                <ul>
                    <li>{__("kwtsms.help.account.sender_transactional")}</li>
                    <li>{__("kwtsms.help.account.sender_promotional")}</li>
                </ul>
                <p>{__("kwtsms.help.account.sender_p2")} {include file="addons/kwtsms/views/kwtsms/components/help_ext_link.tpl" key="sender_id" links=$h.links}</p>

                <h5 class="kwtsms-subtitle">{__("kwtsms.help.account.intl_title")}</h5>
                <p>{__("kwtsms.help.account.intl_p1")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="integrations" pages=$h.pages}</p>
                <p>{__("kwtsms.help.account.intl_p2")}</p>
                <p>{__("kwtsms.help.account.intl_p3")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="settings" anchor="kwtsms_general" pages=$h.pages}</p>
            </section>

            {* ---- setup ------------------------------------------------------------------------------------------ *}
            <section class="kwtsms-section kwtsms-help__section" id="kwtsms_help_setup" aria-labelledby="kwtsms_help_setup_title">
                <h4 class="subheader" id="kwtsms_help_setup_title">{__("kwtsms.help.setup.title")}</h4>
                <p>{__("kwtsms.help.setup.intro")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="dashboard" pages=$h.pages}</p>
                <ol class="kwtsms-steps">
                    <li>{__("kwtsms.help.setup.s1")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="settings" anchor="kwtsms_account" pages=$h.pages}</li>
                    <li>{__("kwtsms.help.setup.s2")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="settings" anchor="kwtsms_sender" pages=$h.pages}</li>
                    <li>{__("kwtsms.help.setup.s3")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="settings" anchor="kwtsms_default_country" pages=$h.pages}</li>
                    <li>{__("kwtsms.help.setup.s4")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="templates" pages=$h.pages}</li>
                    <li>{__("kwtsms.help.setup.s5")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="settings" anchor="kwtsms_test_sms" pages=$h.pages}</li>
                    <li>{__("kwtsms.help.setup.s6")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="templates" pages=$h.pages}</li>
                    <li>{__("kwtsms.help.setup.s7")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="settings" anchor="kwtsms_general" pages=$h.pages}</li>
                    <li>{__("kwtsms.help.setup.s8")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="settings" anchor="kwtsms_general" pages=$h.pages}</li>
                </ol>
            </section>

            {* ---- go-live checklist ------------------------------------------------------------------------------ *}
            <section class="kwtsms-section kwtsms-help__section" id="kwtsms_help_golive" aria-labelledby="kwtsms_help_golive_title">
                <h4 class="subheader" id="kwtsms_help_golive_title">{__("kwtsms.help.golive.title")}</h4>
                <p>{__("kwtsms.help.golive.intro")}</p>
                <ul class="kwtsms-checklist-plain">
                    <li>{__("kwtsms.help.golive.g1")} {include file="addons/kwtsms/views/kwtsms/components/help_ext_link.tpl" key="sender_id" links=$h.links}</li>
                    <li>{__("kwtsms.help.golive.g2")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="templates" pages=$h.pages}</li>
                    <li>{__("kwtsms.help.golive.g3")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="admin_alerts" pages=$h.pages}</li>
                    <li>{__("kwtsms.help.golive.g4")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="settings" anchor="kwtsms_general" pages=$h.pages}</li>
                    <li>{__("kwtsms.help.golive.g5")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="dashboard" pages=$h.pages}</li>
                    <li>{__("kwtsms.help.golive.g6")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="integrations" pages=$h.pages}</li>
                </ul>
            </section>

            {* ---- switches and Notify boxes ---------------------------------------------------------------------- *}
            <section class="kwtsms-section kwtsms-help__section" id="kwtsms_help_switches" aria-labelledby="kwtsms_help_switches_title">
                <h4 class="subheader" id="kwtsms_help_switches_title">{__("kwtsms.help.switches.title")}</h4>
                <p>{__("kwtsms.help.switches.intro")}</p>
                <ol class="kwtsms-steps">
                    <li>{__("kwtsms.help.switches.s1")}</li>
                    <li>{__("kwtsms.help.switches.s2")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="templates" pages=$h.pages}</li>
                    <li>{__("kwtsms.help.switches.s3")}</li>
                </ol>
                <p>{__("kwtsms.help.switches.p1")}</p>
                <p>{__("kwtsms.help.switches.p2")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="admin_alerts" pages=$h.pages}</p>
                {* 2026-10-22 20:28 +03: Task A6 fix round 1 (P2-R12), task 596. *}
                <p>{__("kwtsms.help.switches.p3")}</p>
                {* 2026-10-23 04:23 +03: Task A13, task 596 *}
                <p>{__("kwtsms.help.switches.p4")}</p>
            </section>

            {* ---- storefronts (2026-10-23 04:23 +03: Task A13, task 596) -------------------------------------- *}
            <section class="kwtsms-section kwtsms-help__section" id="kwtsms_help_storefronts" aria-labelledby="kwtsms_help_storefronts_title">
                <h4 class="subheader" id="kwtsms_help_storefronts_title">{__("kwtsms.help.storefronts.title")}</h4>
                <p>{__("kwtsms.help.storefronts.p1")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="settings" pages=$h.pages} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="templates" pages=$h.pages}</p>
                <p>{__("kwtsms.help.storefronts.p2")}</p>
                <p>{__("kwtsms.help.storefronts.p3")}</p>
                <p>{__("kwtsms.help.storefronts.p4")}</p>
            </section>

            {if $h.is_multivendor}
            {* ---- vendor SMS: Multi-Vendor only *}
            <section class="kwtsms-section kwtsms-help__section" id="kwtsms_help_vendors" aria-labelledby="kwtsms_help_vendors_title">
                <h4 class="subheader" id="kwtsms_help_vendors_title">{__("kwtsms.help.vendors.title")}</h4>
                <p>{__("kwtsms.help.vendors.p1")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="vendors" pages=$h.pages}</p>
                <p>{__("kwtsms.help.vendors.p2")}</p>
                <p>{__("kwtsms.help.vendors.p3")}</p>
                <p>{__("kwtsms.help.vendors.p4")}</p>
            </section>
            {/if}

            {* ---- customer opt-out *}
            <section class="kwtsms-section kwtsms-help__section" id="kwtsms_help_optout" aria-labelledby="kwtsms_help_optout_title">
                <h4 class="subheader" id="kwtsms_help_optout_title">{__("kwtsms.help.optout.title")}</h4>
                <p>{__("kwtsms.help.optout.p1")}</p>
                <p>{__("kwtsms.help.optout.p2")}</p>
                <p>{__("kwtsms.help.optout.p3")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="logs" pages=$h.pages}</p>
            </section>

            {* ---- password recovery *}
            <section class="kwtsms-section kwtsms-help__section" id="kwtsms_help_recovery" aria-labelledby="kwtsms_help_recovery_title">
                <h4 class="subheader" id="kwtsms_help_recovery_title">{__("kwtsms.help.recovery.title")}</h4>
                <p>{__("kwtsms.help.recovery.p1")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="templates" pages=$h.pages}</p>
                <p>{__("kwtsms.help.recovery.p2")}</p>
                <p>{__("kwtsms.help.recovery.p3")}</p>
            </section>

            {* ---- manual SMS from an order *}
            <section class="kwtsms-section kwtsms-help__section" id="kwtsms_help_manual" aria-labelledby="kwtsms_help_manual_title">
                <h4 class="subheader" id="kwtsms_help_manual_title">{__("kwtsms.help.manual.title")}</h4>
                <p>{__("kwtsms.help.manual.p1")}</p>
                <p>{__("kwtsms.help.manual.p2")}</p>
                <p>{__("kwtsms.help.manual.p3")}</p>
            </section>

            {* ---- placeholders ----------------------------------------------------------------------------------- *}
            <section class="kwtsms-section kwtsms-help__section" id="kwtsms_help_ph" aria-labelledby="kwtsms_help_ph_title">
                <h4 class="subheader" id="kwtsms_help_ph_title">{__("kwtsms.help.ph.title")}</h4>
                <p>{__("kwtsms.help.ph.p1")}</p>
                <div class="table-responsive kwtsms-table-wrap">
                    <table class="table table-middle kwtsms-table" id="kwtsms_help_ph_table">
                        <thead>
                            <tr><th>{__("kwtsms.help.ph.col_name")}</th><th>{__("kwtsms.help.ph.col_meaning")}</th></tr>
                        </thead>
                        <tbody>
                            {foreach $h.placeholders as $name}
                                <tr><td data-th="{__("kwtsms.help.ph.col_name")}"><bdi dir="ltr" class="kwtsms-token">{ldelim}{$name}{rdelim}</bdi></td><td data-th="{__("kwtsms.help.ph.col_meaning")}">{__("kwtsms.help.ph.`$name`")}</td></tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
            </section>

            {* ---- costs ------------------------------------------------------------------------------------------ *}
            <section class="kwtsms-section kwtsms-help__section" id="kwtsms_help_costs" aria-labelledby="kwtsms_help_costs_title">
                <h4 class="subheader" id="kwtsms_help_costs_title">{__("kwtsms.help.costs.title")}</h4>
                <p>{__("kwtsms.help.costs.p1", ["[gsm_single]" => $h.sms.gsm_single|fn_html_escape, "[gsm_multi]" => $h.sms.gsm_multi|fn_html_escape])}</p>
                <p>{__("kwtsms.help.costs.p2", ["[unicode_single]" => $h.sms.unicode_single|fn_html_escape, "[unicode_multi]" => $h.sms.unicode_multi|fn_html_escape])}</p>
                <p>{__("kwtsms.help.costs.p3")}</p>
                <p>{__("kwtsms.help.costs.p4", ["[max_pages]" => $h.sms.max_pages|fn_html_escape])} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="templates" pages=$h.pages}</p>

                <h5 class="kwtsms-subtitle">{__("kwtsms.help.costs.low_title")}</h5>
                <p>{__("kwtsms.help.costs.low_p1")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="dashboard" pages=$h.pages}</p>
                {* 2026-10-21 17:25 +03: clearing the Attempts log also resets the sending limits counted from it (final review M4), task 596 *}
                <p id="kwtsms_help_limits_clear">{__("kwtsms.help.costs.limits_clear")}</p>
            </section>

            {* ---- cron ------------------------------------------------------------------------------------------- *}
            <section class="kwtsms-section kwtsms-help__section" id="kwtsms_help_cron" aria-labelledby="kwtsms_help_cron_title">
                <h4 class="subheader" id="kwtsms_help_cron_title">{__("kwtsms.help.cron.title")}</h4>
                <p>{__("kwtsms.help.cron.p1")}</p>
                <p>{__("kwtsms.help.cron.p2")}</p>
                <pre class="kwtsms-pre" id="kwtsms_help_cron_line" dir="ltr" tabindex="0">{$h.cron.line}</pre>
                <p>{__("kwtsms.help.cron.p3", ["[script]" => $h.cron.admin_index|fn_html_escape])} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="settings" anchor="kwtsms_cron" pages=$h.pages}</p>
                <p>{__("kwtsms.help.cron.p4")}</p>
                {* 2026-10-21 16:56 +03: when order SMS go out on a host without PHP-FPM or LiteSpeed (final review I2), task 596 *}
                <p id="kwtsms_help_cron_host">{__("kwtsms.help.cron.host_p1")}</p>

                <h5 class="kwtsms-subtitle">{__("kwtsms.help.cron.url_title")}</h5>
                <p>{__("kwtsms.help.cron.url_p1")}</p>
                <pre class="kwtsms-pre" id="kwtsms_help_cron_curl" dir="ltr" tabindex="0">{$h.cron.curl}</pre>
                <p>{__("kwtsms.help.cron.url_p2")}</p>
                <p class="muted" id="kwtsms_help_cron_url_state">{if $h.cron.url_enabled}{__("kwtsms.help.cron.url_on")}{else}{__("kwtsms.help.cron.url_off")}{/if}</p>

                <h5 class="kwtsms-subtitle">{__("kwtsms.help.cron.timeout_title")}</h5>
                <p id="kwtsms_help_cron_504">{__("kwtsms.help.cron.timeout_p1")}</p>

                <h5 class="kwtsms-subtitle">{__("kwtsms.help.cron.lock_title")}</h5>
                <p>{__("kwtsms.help.cron.lock_p1")}</p>
                {if $h.cron.lock_ok}
                    <p class="muted" id="kwtsms_help_lock" data-kwtsms-lock="ok">{__("kwtsms.help.cron.lock_state", ["[backend]" => $h.cron.lock_backend|fn_html_escape])} {__("kwtsms.help.cron.lock_ok")}</p>
                {else}
                    <div class="alert alert-warning kwtsms-inline-alert" id="kwtsms_help_lock" data-kwtsms-lock="none" role="status">{__("kwtsms.help.cron.lock_state", ["[backend]" => $h.cron.lock_backend|fn_html_escape])} {__("kwtsms.help.cron.lock_bad")}</div>
                {/if}
            </section>

            {* ---- test mode -------------------------------------------------------------------------------------- *}
            <section class="kwtsms-section kwtsms-help__section" id="kwtsms_help_test" aria-labelledby="kwtsms_help_test_title">
                <h4 class="subheader" id="kwtsms_help_test_title">{__("kwtsms.help.test.title")}</h4>
                <p>{__("kwtsms.help.test.p1")}</p>
                <p>{__("kwtsms.help.test.p2")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="dashboard" pages=$h.pages}</p>
                <p>{__("kwtsms.help.test.p3")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="settings" anchor="kwtsms_general" pages=$h.pages}</p>
            </section>

            {* ---- IP lockdown and proxies ------------------------------------------------------------------------ *}
            <section class="kwtsms-section kwtsms-help__section" id="kwtsms_help_ip" aria-labelledby="kwtsms_help_ip_title">
                <h4 class="subheader" id="kwtsms_help_ip_title">{__("kwtsms.help.ip.title")}</h4>
                <p>{__("kwtsms.help.ip.p1")}</p>
                <p>{__("kwtsms.help.ip.p2")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="settings" pages=$h.pages}</p>
                <p>{__("kwtsms.help.ip.p3")}</p>
            </section>

            {* ---- message not received --------------------------------------------------------------------------- *}
            <section class="kwtsms-section kwtsms-help__section" id="kwtsms_help_missing" aria-labelledby="kwtsms_help_missing_title">
                <h4 class="subheader" id="kwtsms_help_missing_title">{__("kwtsms.help.missing.title")}</h4>
                <p>{__("kwtsms.help.missing.intro")}</p>
                <ol class="kwtsms-steps">
                    <li>{__("kwtsms.help.missing.s1")}</li>
                    <li>{__("kwtsms.help.missing.s2")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="logs" pages=$h.pages}</li>
                    <li>{__("kwtsms.help.missing.s3")}</li>
                    <li>{__("kwtsms.help.missing.s4")}</li>
                    <li>{__("kwtsms.help.missing.s5")}</li>
                    <li>{__("kwtsms.help.missing.s6")}</li>
                    <li>{__("kwtsms.help.missing.s7")} {include file="addons/kwtsms/views/kwtsms/components/help_ext_link.tpl" key="faq" links=$h.links}</li>
                </ol>
            </section>

            {* ---- support ---------------------------------------------------------------------------------------- *}
            <section class="kwtsms-section kwtsms-help__section" id="kwtsms_help_support" aria-labelledby="kwtsms_help_support_title">
                <h4 class="subheader" id="kwtsms_help_support_title">{__("kwtsms.help.support.title")}</h4>
                <p>{__("kwtsms.help.support.p1")} {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="logs" pages=$h.pages}</p>
                <p>{__("kwtsms.help.support.p2")}</p>
                <p>{include file="addons/kwtsms/views/kwtsms/components/help_ext_link.tpl" key="support" links=$h.links}</p>
            </section>

            {* ---- links ------------------------------------------------------------------------------------------ *}
            <section class="kwtsms-section kwtsms-help__section" id="kwtsms_help_links" aria-labelledby="kwtsms_help_links_title">
                <h4 class="subheader" id="kwtsms_help_links_title">{__("kwtsms.help.links.title")}</h4>
                <p class="muted">{__("kwtsms.help.links.intro")}</p>
                <ul class="kwtsms-help__links" id="kwtsms_help_links_list">
                    {foreach $h.links as $key => $url}
                        <li>
                            <a href="{$url}" target="_blank" rel="noopener noreferrer" data-kwtsms-help-url="{$key}">{__("kwtsms.help.links.`$key`")}</a>
                            <bdi dir="ltr" class="kwtsms-help__url">{$url}</bdi>
                        </li>
                    {/foreach}
                </ul>
            </section>
        </div>
    </div>
</div>
{/capture}

{include file="common/mainbox.tpl"
    title=__("kwtsms.help.page_title")
    content=$smarty.capture.mainbox
    select_storefront=false
}
