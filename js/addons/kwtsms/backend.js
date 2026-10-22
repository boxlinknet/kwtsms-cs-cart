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
