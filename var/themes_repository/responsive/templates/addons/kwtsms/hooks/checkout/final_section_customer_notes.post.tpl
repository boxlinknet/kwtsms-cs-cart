{*
 * FILE:      design/themes/responsive/templates/addons/kwtsms/hooks/checkout/final_section_customer_notes.post.tpl
 * CREATED:   2026-11-05 20:56 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The SMS code box of the checkout (checkout_code, SafatMall B6): the sentence "Verify your mobile number with
 *            the SMS code to place your order." and the code field (component code_field.tpl, purpose checkout) above the
 *            place-order button, inside the checkout form, so the code posts as kwtsms_code with the order.
 * WHY:       Task M2.4 (P3-54). The server refuses an order without a verified code (pre_place_order); this is where the
 *            customer asks for it and types it. fn_kwtsms_checkout_code_form() decides whether it shows: mode all always,
 *            guest only while signed out, cod only while the chosen payment is a listed one. The final section is
 *            re-rendered when the payment or the shipping changes, so the decision is taken again each time (and code.js
 *            keeps what was typed).
 * USAGE:     Loaded by core as a hook template (checkout:final_section_customer_notes) while the add-on is active, in
 *            views/checkout/components/final_section.tpl (lite checkout). Prints nothing while checkout_code is off.
 * DEPENDS:   func.php fn_kwtsms_checkout_code_form, components/code_field.tpl, js/addons/kwtsms/code.js (loaded by
 *            hooks/index/scripts.post.tpl while a code feature is on), language variables kwtsms.checkout.*.
 *}
{$kwtsms_co = $cart|fn_kwtsms_checkout_code_form}
{if $kwtsms_co.show}
    {* 2026-11-05 22:10 +03: Task M2.4, task 596. A plain block, not a litecheckout__item: inside the grid of the final section the sentence and the field sat side by side. *}
    <div class="kwtsms-checkout-code" id="kwtsms_checkout_code_block" style="margin: 16px 0;">
        <p class="kwtsms-checkout-code__intro" style="margin: 0 0 8px;">{__("kwtsms.checkout.code_required")|fn_html_escape}</p>
        {include file="addons/kwtsms/components/code_field.tpl" purpose="checkout" phone_input_name="user_data[phone]" phone_alt_name="user_data[b_phone]" field_label=__("kwtsms.checkout.field_label") verified_phone=$kwtsms_co.verified_phone default_cc=$kwtsms_co.default_cc}
    </div>
{/if}
