/*
FILE:      js/addons/kwtsms/backend.js
CREATED:   2026-10-20 19:55 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      Script of the kwtSMS admin pages (empty in Task 1.1).
WHY:       Pages arrive in Tasks 1.10 to 1.15 (character counter, copy buttons, confirmations).
USAGE:     Loaded by hooks/index/scripts.post.tpl.
DEPENDS:   Nothing.
*/

/*
2026-10-21 02:07 +03: Task 1.10, task 596. Settings page: shows the KWT-SMS warning while KWT-SMS is the chosen
Sender ID, the acknowledgement checkbox while test mode is being turned off with KWT-SMS (PRD 6.1), and the DND warning
for a promotional Sender ID. Display only: the server checks the acknowledgement again. Reads its data from data-
attributes of .kwtsms-page; never reads or writes a credential. Runs on load and after CS-Cart's ajax re-render
(ce.commoninit).
*/
(function () {
    'use strict';

    function toggle(el, show) {
        if (el) {
            el.classList.toggle('hidden', !show);
        }
    }

    function initSettings(root) {
        var page = root.querySelector ? root.querySelector('.kwtsms-settings') : null;
        if (!page || page.getAttribute('data-kwtsms-ready') === '1') {
            return;
        }
        page.setAttribute('data-kwtsms-ready', '1');

        var testSender = page.getAttribute('data-kwtsms-test-sender') || '';
        var testModeSaved = page.getAttribute('data-kwtsms-test-mode') === 'Y';
        var savedSender = page.getAttribute('data-kwtsms-saved-sender') || '';
        var sender = page.querySelector('.kwtsms-js-sender');
        var senderType = page.querySelector('.kwtsms-js-sender-type');
        var testMode = page.querySelector('.kwtsms-js-test-mode');
        var warning = page.querySelector('.kwtsms-js-test-sender-warning');
        var promo = page.querySelector('.kwtsms-js-promotional-warning');
        var ack = page.querySelector('.kwtsms-js-ack');

        function refresh() {
            var isTestSender = !!sender && sender.value === testSender && testSender !== '';
            toggle(warning, isTestSender);
            // The confirmation matters only while test mode is on now and the box is being cleared.
            // 2026-10-21 03:19 +03: also when KWT-SMS is chosen while test mode is already off (the saved state was
            // not yet "off with KWT-SMS"), Task 1.10 fix round 1 item 4, task 596.
            var alreadyAcknowledged = !testModeSaved && savedSender === testSender;
            toggle(ack, isTestSender && !!testMode && !testMode.checked && !alreadyAcknowledged);
            if (senderType) {
                toggle(promo, senderType.value === 'promotional');
            }
        }

        [sender, senderType, testMode].forEach(function (el) {
            if (el) {
                el.addEventListener('change', refresh);
            }
        });
        refresh();

        // 2026-10-22 16:17 +03, Task A3, task 596: the Sender ID per storefront. Same UX as the main Sender ID: the KWT-SMS warning
        // under the dropdown that holds it, and the acknowledgement while test mode is off and a storefront is being
        // changed to KWT-SMS. Display only: the server checks the acknowledgement again.
        var sfSelects = page.querySelectorAll('.kwtsms-js-sf-sender');
        var sfAck = page.querySelector('.kwtsms-js-sf-ack');

        function refreshStorefronts() {
            var needAck = false;
            Array.prototype.forEach.call(sfSelects, function (select) {
                var isTest = testSender !== '' && select.value === testSender;
                var warning = select.parentNode.querySelector('.kwtsms-js-sf-warning');
                toggle(warning, isTest);
                if (isTest && !testModeSaved && (select.getAttribute('data-kwtsms-saved') || '') !== testSender) {
                    needAck = true;
                }
            });
            toggle(sfAck, needAck);
        }

        Array.prototype.forEach.call(sfSelects, function (select) {
            select.addEventListener('change', refreshStorefronts);
        });
        refreshStorefronts();
    }

    /*
    2026-10-23 02:18 +03, Task A11, task 596. The manual SMS dialog of the admin order page: the live character, page and
    credit counter. The count is what the gateway bills: counter.js (a mirror of MessageCleaner) cleans the stored form of
    the typed text. One recipient, so the credits equal the pages. Display only; the server validates and counts again.
    The dialog is moved to the page body by the core dialog opener, so the listener is delegated on the document and finds
    its dialog from the textarea.
    */
    /*
    2026-10-23 06:06 +03: Cleanup CA1-10 (A11 minor 6), task 596. One post per dialog: after the first submit the Send
    button is disabled (a tick later: a disabled submit button is left out of the form data) and a second submit before
    the response is cancelled. The page is reloaded by the response, which resets the state.
    */
    var orderSmsBusy = false;

    function updateOrderSms(box) {
        var dialog = box.closest ? box.closest('.kwtsms-order-sms-dialog') : null;
        var counter = window.kwtsmsCounter;
        if (!dialog || !counter) {
            return;
        }
        var counts = counter.count(counter.clean(counter.storedForm(box.value)));
        var out = function (id) {
            return dialog.querySelector(id);
        };
        var over = out('#kwtsms_order_sms_over');
        var send = out('#kwtsms_order_sms_send');
        out('#kwtsms_order_count_units').textContent = String(counts.units);
        out('#kwtsms_order_count_pages').textContent = String(counts.pages);
        out('#kwtsms_order_count_credits').textContent = String(counts.pages);
        out('#kwtsms_order_count_enc').textContent = counts.units === 0 ? '-' : (counts.unicode
            ? (dialog.getAttribute('data-kwtsms-enc-unicode') || '') : (dialog.getAttribute('data-kwtsms-enc-ascii') || ''));
        if (over) {
            over.textContent = counts.over ? (dialog.getAttribute('data-kwtsms-msg-over') || '') : '';
            toggle(over, counts.over);
        }
        // 2026-10-23 08:36 +03: Cleanup CA2-6 (A11 minor 5), task 596. A {...} token is blocked by the pipeline
        // (blocked_placeholder): say so before the send. A warning only; the server decides.
        toggle(out('#kwtsms_order_sms_braces'), /\{[^{}]*\}/.test(box.value));
        if (send) {
            send.disabled = counts.units === 0 || counts.over || orderSmsBusy;
        }
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.id !== 'kwtsms_order_sms_form') {
            return;
        }
        if (orderSmsBusy) {
            event.preventDefault();
            return;
        }
        orderSmsBusy = true;
        var send = form.querySelector ? form.querySelector('#kwtsms_order_sms_send') : null;
        if (send) {
            window.setTimeout(function () {
                send.disabled = true;
            }, 0);
        }
    });

    document.addEventListener('input', function (event) {
        if (event.target && event.target.classList && event.target.classList.contains('kwtsms-js-order-text')) {
            updateOrderSms(event.target);
        }
    });

    function init(context) {
        var root = context && context.jquery ? context.get(0) : (context || document);
        if (root) {
            initSettings(root);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
    if (window.jQuery && window.jQuery.ceEvent) {
        window.jQuery.ceEvent('on', 'ce.commoninit', function (context) {
            init(context);
        });
    }
}());
