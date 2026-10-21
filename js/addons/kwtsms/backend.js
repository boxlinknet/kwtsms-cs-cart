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
