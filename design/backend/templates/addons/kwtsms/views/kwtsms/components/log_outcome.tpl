{*
FILE:      design/backend/templates/addons/kwtsms/views/kwtsms/components/log_outcome.tpl
CREATED:   2026-10-21 05:58 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      One outcome as a chip: the canonical label (PRD Appendix B), coloured by its Dashboard group (accepted, failed,
           deferred, skipped), the plain-language reason as its tooltip ("Delivery is not reported back..." for
           accepted), and the TEST badge for a row of test mode.
WHY:       PRD 6.8: one canonical list with EN and AR labels; "accepted" reads "Accepted by kwtSMS" and the word
           "Delivered" never appears. Shared by the Logs list, the detail view and the order SMS tab.
USAGE:     {include file="addons/kwtsms/views/kwtsms/components/log_outcome.tpl" row=$r}
           $row: outcome, outcome_label, outcome_tip, outcome_kind, is_test (Admin\LogsPage::decorate()).
DEPENDS:   logs.less (.kwtsms-outcome--*), kwtsms.logs.test_badge.
*}
<span class="kwtsms-outcome kwtsms-outcome--{$row.outcome_kind|default:"other"}" data-kwtsms-outcome="{$row.outcome}"{if $row.outcome_tip} title="{$row.outcome_tip}"{/if}>{$row.outcome_label}</span>{if $row.is_test} <span class="kwtsms-badge kwtsms-badge--test kwtsms-test-badge">{__("kwtsms.logs.test_badge")}</span>{/if}
