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
 *            2026-10-28 11:08 +03, Task M1.10a (SafatMall B1, P3-28), task 596: on the registration page (not for a signed-in customer)
 *            the SMS code field (code_field.tpl, purpose register) while register_code is ON, and a phone input of our own
 *            named user_data[phone] when the form has no phone field and a phone is needed (core saves a posted
 *            user_data[phone] with the account). fn_kwtsms_register_form() decides, from the settings and the profile
 *            fields.
 *            2026-10-29 00:04 +03, Task M1.11s item 2 (SafatMall note 2), task 596: fn_kwtsms_order_sms_choice().show is also false
 *            while no customer order SMS the opt-out covers (status change, order updated, shipment created) is ON for this
 *            storefront, so the box is hidden on registration and profile; a stored choice stays. This is the only theme
 *            copy of the box (design/themes/responsive).
 * DEPENDS:   func.php fn_kwtsms_order_sms_choice, fn_kwtsms_register_form, components/code_field.tpl, language
 *            variables kwtsms.profile.order_sms_label and kwtsms.profile.order_sms_help.
 *}
{$kwtsms_choice = $auth.user_id|fn_kwtsms_order_sms_choice}
{if $kwtsms_choice.show}
    <div class="ty-control-group kwtsms-order-sms">
        <input type="hidden" name="user_data[kwtsms_order_sms]" value="N" />
        <label for="kwtsms_order_sms" class="ty-login__remember-me-label"><input class="checkbox" type="checkbox" name="user_data[kwtsms_order_sms]" id="kwtsms_order_sms" value="Y"{if $kwtsms_choice.allowed} checked="checked"{/if} />{__("kwtsms.profile.order_sms_label")}</label>
        <div class="ty-control-group__help kwtsms-order-sms__help" style="margin-top: 4px; font-size: 13px; opacity: 0.75;">{__("kwtsms.profile.order_sms_help")}</div>
    </div>
{/if}
{* 2026-10-28 11:08 +03: Task M1.10a (B1, P3-28), task 596. The registration page only: mode add, nobody signed in. *}
{* 2026-10-28 20:19 +03: Task M1.10a fix round 1 (review I5, M1, ruling P3-R12), task 596: the own phone input posts to the store account phone field (user_data[b_phone] when that is the account phone, else user_data[phone]), named by fn_kwtsms_register_form(); the quick registration form is covered. *}
{if $runtime.mode == "add" && !$auth.user_id}
    {$kwtsms_reg = ""|fn_kwtsms_register_form}
    {if $kwtsms_reg.phone_input}
        <div class="ty-control-group kwtsms-register-phone">
            <label for="kwtsms_register_phone" class="ty-control-group__title cm-required">{__("phone")}</label>
            <input type="tel" id="kwtsms_register_phone" name="{$kwtsms_reg.phone_name|fn_html_escape}" class="ty-input-text cm-required" size="32" maxlength="32" value="{$user_data.phone|default:$user_data.b_phone|default:""|fn_html_escape}" autocomplete="tel" dir="ltr" />
        </div>
    {/if}
    {if $kwtsms_reg.code}
        {include file="addons/kwtsms/components/code_field.tpl" purpose="register" phone_input_name=$kwtsms_reg.phone_name}
    {/if}
{/if}
{* 2026-10-28 20:26 +03: Task M1.10b (SafatMall B5, P3-17), task 596. The profile page of a signed-in customer: the SMS code field (purpose phone_change) while phone_change_code is ON and the form shows a phone field; it stays hidden until the phone input changes (code.js). fn_kwtsms_phone_change_form() names the phone input. *}
{if $runtime.mode == "update" && $auth.user_id}
    {$kwtsms_pc = ""|fn_kwtsms_phone_change_form}
    {if $kwtsms_pc.show}
        {include file="addons/kwtsms/components/code_field.tpl" purpose="phone_change" phone_input_name=$kwtsms_pc.phone_name show_on_change=true}
    {/if}
{/if}
