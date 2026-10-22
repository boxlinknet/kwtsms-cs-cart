{*
 * FILE:      design/themes/responsive/templates/addons/kwtsms/hooks/profiles/account_update.post.tpl
 * CREATED:   2026-10-23 00:55 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The customer's choice "Send me SMS about my order status" on the storefront registration form and on
 *            the profile page (hook profiles:account_update of views/profiles/update.tpl, responsive theme).
 * WHY:       Phase 2 Task A10 (SafatMall F6, P2-4). A customer can stop order-status SMS (status changes, order
 *            updates, shipments); the order receipt and security codes are always sent, which the help line says.
 *            The hidden N before the checkbox makes an unticked box post N (fn_kwtsms_update_profile saves it for
 *            the customer's own storefront request only).
 * USAGE:     Loaded by core as a hook template while the add-on is active. fn_kwtsms_order_sms_choice() returns
 *            show (SMS sending is switched on) and allowed (ticked unless the account opted out).
 *            2026-10-23 01:31 +03, Task A10, task 596: the help line is smaller and muted, set apart from the box by a small gap
 *            (inline style: the add-on ships no storefront stylesheet, and the line must read as help, not as a second option).
 * DEPENDS:   func.php fn_kwtsms_order_sms_choice, language variables kwtsms.profile.order_sms_label and
 *            kwtsms.profile.order_sms_help.
 *}
{$kwtsms_choice = $auth.user_id|fn_kwtsms_order_sms_choice}
{if $kwtsms_choice.show}
    <div class="ty-control-group kwtsms-order-sms">
        <input type="hidden" name="user_data[kwtsms_order_sms]" value="N" />
        <label for="kwtsms_order_sms" class="ty-login__remember-me-label"><input class="checkbox" type="checkbox" name="user_data[kwtsms_order_sms]" id="kwtsms_order_sms" value="Y"{if $kwtsms_choice.allowed} checked="checked"{/if} />{__("kwtsms.profile.order_sms_label")}</label>
        <div class="ty-control-group__help kwtsms-order-sms__help" style="margin-top: 4px; font-size: 13px; opacity: 0.75;">{__("kwtsms.profile.order_sms_help")}</div>
    </div>
{/if}
