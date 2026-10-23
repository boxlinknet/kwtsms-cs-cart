{*
FILE:      design/backend/templates/addons/kwtsms/hooks/orders/details_tools.post.tpl
CREATED:   2026-10-23 02:18 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      The "Send SMS" entry of the Tools menu on the admin order page and the dialog it opens: one phone (the
           customer's, editable), one text with the live character, page and credit counter, the test-mode note, and the
           Send button.
WHY:       Phase 2 Task A11. An administrator contacts the customer from the order. Hook orders:details_tools (CS-Cart
           4.21.2 views/orders/details.tpl, inside the tools_list capture; post position appends after the core items).
           $kwtsms_order_send is assigned by controllers/backend/orders.post.php only to an administrator the kwtSMS
           gate would let send (root on All storefronts, gateway privilege, view_orders for this order). The form posts
           dispatch[kwtsms.send_order_sms]; the server checks everything again. The counter is js/addons/kwtsms/counter.js
           (the same cleaning as the Templates page), wired by backend.js. Auto-escaped throughout.
USAGE:     Rendered by core on orders.details.
DEPENDS:   $kwtsms_order_send (Admin\OrderSmsSender::dialogData()), counter.js, backend.js, ordersms.less, kwtsms.order_sms.*
           and kwtsms.templates.* language variables.
*}
{* 2026-10-23 13:16 +03: final review A, Important 2, task 596. Every value in a __() placeholder array now passes |fn_html_escape (values that never had a modifier too); tests/Unit/TemplateEscapeTest enforces it. *}
{if $kwtsms_order_send}
    {$d = $kwtsms_order_send}
    <li><a class="cm-dialog-opener cm-dialog-auto-size" id="kwtsms_order_sms_open" data-ca-target-id="kwtsms_order_sms_dialog" data-ca-dialog-title="{__("kwtsms.order_sms.title")}">{__("kwtsms.order_sms.button")}</a>
    <div class="hidden kwtsms-order-sms-dialog" id="kwtsms_order_sms_dialog" title="{__("kwtsms.order_sms.title")}"
         data-kwtsms-max-pages="{$d.max_pages}"
         data-kwtsms-enc-ascii="{__("kwtsms.templates.enc_ascii")}"
         data-kwtsms-enc-unicode="{__("kwtsms.templates.enc_unicode")}"
         data-kwtsms-msg-over="{__("kwtsms.order_sms.over", ["[max]" => $d.max_pages|fn_html_escape])}">
        <form action="{""|fn_url}" method="post" name="kwtsms_order_sms_form" id="kwtsms_order_sms_form" class="kwtsms-order-sms-form" autocomplete="off">
            <input type="hidden" name="kwtsms_order_id" value="{$d.order_id}">
            <p class="muted">{__("kwtsms.order_sms.intro")}</p>
            <div class="kwtsms-order-sms-field">
                <label for="kwtsms_order_phone">{__("kwtsms.order_sms.phone")}</label>
                <input type="text" id="kwtsms_order_phone" name="kwtsms_order_phone" value="{$d.phone}" class="input-large" dir="ltr" maxlength="32" autocomplete="off" inputmode="tel">
                <p class="muted kwtsms-order-sms-help">{__("kwtsms.order_sms.phone_help")}</p>
            </div>
            <div class="kwtsms-order-sms-field">
                <label for="kwtsms_order_text">{__("kwtsms.order_sms.text")}</label>
                <textarea id="kwtsms_order_text" name="kwtsms_order_text" class="kwtsms-js-order-text" rows="5" dir="auto" maxlength="{$d.text_max}"></textarea>
                <p class="muted kwtsms-order-sms-help">{__("kwtsms.order_sms.text_help")}</p>
            </div>
            <dl class="kwtsms-order-sms-counts" id="kwtsms_order_sms_counts" aria-live="polite">
                <div><dt>{__("kwtsms.templates.counter_chars")}</dt><dd id="kwtsms_order_count_units">0</dd></div>
                <div><dt>{__("kwtsms.templates.counter_sms")}</dt><dd id="kwtsms_order_count_pages">0</dd></div>
                <div><dt>{__("kwtsms.order_sms.credits")}</dt><dd id="kwtsms_order_count_credits">0</dd></div>
                <div><dt>{__("kwtsms.templates.counter_encoding")}</dt><dd id="kwtsms_order_count_enc">-</dd></div>
            </dl>
            <p class="kwtsms-order-sms-over hidden" id="kwtsms_order_sms_over" role="alert"></p>
            {* 2026-10-23 08:36 +0300: cleanup CA2-6 (A11 minor 5), task 596. Shown by backend.js while the text holds a {...} token. *}
            <p class="kwtsms-order-sms-braces hidden" id="kwtsms_order_sms_braces" role="status">{__("kwtsms.order_sms.braces")}</p>
            {if $d.test_mode}
                <p class="kwtsms-order-sms-test" id="kwtsms_order_sms_test_note"><strong>{__("kwtsms.order_sms.test_mode")}</strong> {__("kwtsms.test_sms.test_note")}</p>
            {/if}
            <div class="kwtsms-order-sms-actions">
                <input type="submit" class="btn btn-primary" id="kwtsms_order_sms_send" disabled="disabled" name="dispatch[kwtsms.send_order_sms]" value="{__("kwtsms.order_sms.send")}">
                <a class="btn cm-dialog-closer" id="kwtsms_order_sms_cancel">{__("cancel")}</a>
            </div>
        </form>
    <!--kwtsms_order_sms_dialog--></div>
    </li>
{/if}
