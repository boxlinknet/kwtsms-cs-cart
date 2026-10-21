{*
FILE:      design/backend/templates/addons/kwtsms/views/kwtsms/components/help_page_link.tpl
CREATED:   2026-10-21 04:42 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      A link from the Help text to another page of the add-on ("Open Settings"), shown only when the administrator may
           open that page and the page exists.
WHY:       PRD 6.10 and 7: the Help page is visible with the view privilege alone, so a link to a page the administrator
           can't open would end on "Access denied". HelpPage::viewData() decides per page (the privilege of the page, and
           whether Navigation lists it).
USAGE:     {include file="addons/kwtsms/views/kwtsms/components/help_page_link.tpl" mode="settings" anchor="kwtsms_cron" pages=$h.pages}
           $mode: dashboard|settings|templates|integrations|admin_alerts|logs; $anchor: optional element id on that page.
DEPENDS:   kwtsms.help.open_page, kwtsms.nav.<mode> language variables.
*}
{if $pages.$mode}
    <a class="kwtsms-help__link" href="{"kwtsms.`$mode`"|fn_url}{if $anchor}#{$anchor}{/if}" data-kwtsms-help-page="{$mode}">{__("kwtsms.help.open_page", ["[page]" => __("kwtsms.nav.`$mode`")|escape:"html"])}</a>
{/if}
