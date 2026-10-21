{*
FILE:      design/backend/templates/addons/kwtsms/views/kwtsms/components/help_ext_link.tpl
CREATED:   2026-10-21 04:42 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      A link from the Help text to a kwtSMS website page, opened in a new tab.
WHY:       PRD 6.10 and 10: external links carry rel="noopener noreferrer"; the address comes from HelpPage::LINKS (one
           list, https only, all checked for HTTP 200), never from request data.
USAGE:     {include file="addons/kwtsms/views/kwtsms/components/help_ext_link.tpl" key="sender_id" links=$h.links}
           $key: a key of HelpPage::LINKS; the link text is kwtsms.help.links.<key>.
DEPENDS:   kwtsms.help.links.* language variables.
*}
<a class="kwtsms-help__link kwtsms-help__link--ext" href="{$links.$key}" target="_blank" rel="noopener noreferrer" data-kwtsms-help-link="{$key}">{__("kwtsms.help.links.`$key`")}</a>
