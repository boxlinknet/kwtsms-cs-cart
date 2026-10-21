{*
FILE:      design/backend/templates/addons/kwtsms/views/kwtsms/components/log_reason.tpl
CREATED:   2026-10-21 14:11 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      The reason of one attempt as the Logs show it: the plain-language text, then the kwtSMS or add-on code in brackets
           when the reason carries one ("Your kwtSMS balance is zero. Recharge credits at kwtsms.com. (ERR010)"). When no text
           could be made (an outcome this add-on does not know) the reason itself is shown, escaped.
WHY:       Cleanup 2 item 5: the Attempts tab, the attempts of a message, the order SMS tab and the attempt detail showed raw
           internal ids such as gateway_unreachable. One component, so the four places read alike.
USAGE:     {include file="addons/kwtsms/views/kwtsms/components/log_reason.tpl" row=$r}
           $row: reason, reason_label, reason_code (Admin\LogsPage::decorate()). Auto-escaped, no nofilter.
DEPENDS:   logs.less (.kwtsms-code-tag).
*}
{if $row.reason_label}{$row.reason_label}{if $row.reason_code} <bdi dir="ltr" class="kwtsms-code-tag">({$row.reason_code})</bdi>{/if}{else}{$row.reason|default:""}{/if}
