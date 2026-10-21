/*
FILE:      js/addons/kwtsms/logs.js
CREATED:   2026-10-21 06:00 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      Script of the kwtSMS Logs pages: the "Copy details for kwtSMS support" button (copies the read-only text box,
           says "Copied") and the rows-per-page select that applies the filter form at once.
WHY:       PRD 6.8. Display and convenience only: the copied text is the server's (no credential is ever in it), nothing
           is written, no dialog is opened. Works on load and after CS-Cart's ajax re-render (ce.commoninit).
USAGE:     Loaded by hooks/index/scripts.post.tpl; wires elements with the kwtsms-js-copy and kwtsms-js-autosubmit classes.
DEPENDS:   Nothing (navigator.clipboard when the page is a secure context, else the textarea selection and
           document.execCommand('copy')).
*/
(function () {
    'use strict';

    function copied(button) {
        var root = button.parentNode;
        var note = root ? root.querySelector('.kwtsms-js-copied') : null;
        if (note) {
            note.classList.remove('hidden');
        }
    }

    function copyFrom(button) {
        var target = document.getElementById(button.getAttribute('data-kwtsms-copy-target') || '');
        if (!target) {
            return;
        }
        var text = target.value !== undefined ? target.value : target.textContent;
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () {
                copied(button);
            }, function () {
                selectAndCopy(target, button);
            });
        } else {
            selectAndCopy(target, button);
        }
    }

    function selectAndCopy(target, button) {
        if (typeof target.select === 'function') {
            target.select();
        }
        try {
            if (document.execCommand('copy')) {
                copied(button);
            }
        } catch (e) {
            // The text stays selected: the administrator can copy it by hand.
        }
    }

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;
        Array.prototype.forEach.call(scope.querySelectorAll('.kwtsms-js-copy'), function (button) {
            if (button.getAttribute('data-kwtsms-ready') === '1') {
                return;
            }
            button.setAttribute('data-kwtsms-ready', '1');
            button.addEventListener('click', function () {
                copyFrom(button);
            });
        });
        Array.prototype.forEach.call(scope.querySelectorAll('.kwtsms-js-autosubmit'), function (select) {
            if (select.getAttribute('data-kwtsms-ready') === '1') {
                return;
            }
            select.setAttribute('data-kwtsms-ready', '1');
            select.addEventListener('change', function () {
                if (select.form) {
                    select.form.submit();
                }
            });
        });
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
            init(context && context[0] ? context[0] : document);
        });
    }
}());
