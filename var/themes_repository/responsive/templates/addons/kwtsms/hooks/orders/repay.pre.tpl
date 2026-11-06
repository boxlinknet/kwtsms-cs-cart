{*
 * FILE:      design/themes/responsive/templates/addons/kwtsms/hooks/orders/repay.pre.tpl
 * CREATED:   2026-11-06 02:54 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      The SMS code box of the "pay again" section of an order page (checkout_code, SafatMall B6): the sentence and
 *            the code field (component code_field.tpl, purpose checkout) above the payment choices. It carries the order
 *            id, so the code goes to the phone OF THE ORDER; code.js copies the typed code into the repay form when the
 *            customer presses the repay button.
 * WHY:       Task M2.4 fix 1 (security review C1). orders.repay changes the payment of an order without the checkout, so
 *            the code that the checkout asks for must be asked here too (controllers/frontend/orders.pre.php decides).
 *            In mode cod the box shows only while one of the listed payments is chosen (code.js watches the choice).
 * USAGE:     Loaded by core as a hook template (orders:repay, pre) in views/orders/details.tpl while the add-on is active.
 *            Prints nothing while checkout_code is off, when the order is already verified for this visitor, or when the
 *            repay section itself is not shown.
 * DEPENDS:   func.php fn_kwtsms_repay_code_form, components/code_field.tpl, js/addons/kwtsms/code.js, language variables
 *            kwtsms.checkout.*.
 *}
{if $status_settings.repay == "YesNo::YES"|enum && $payment_methods && $order_info.total|floatval}
    {$kwtsms_rp = $order_info|fn_kwtsms_repay_code_form}
    {if $kwtsms_rp.show}
        <div class="kwtsms-checkout-code" id="kwtsms_repay_code_block" data-kwtsms-repay="1" data-kwtsms-payments="{$kwtsms_rp.payments|fn_html_escape}" style="margin: 16px 0;">
            <p class="kwtsms-checkout-code__intro" style="margin: 0 0 8px;">{__("kwtsms.checkout.code_required")|fn_html_escape}</p>
            {include file="addons/kwtsms/components/code_field.tpl" purpose="checkout" phone_input_name="kwtsms_repay_none" field_label=__("kwtsms.checkout.field_label") kwtsms_order_id=$kwtsms_rp.order_id}
        </div>
    {/if}
{/if}
