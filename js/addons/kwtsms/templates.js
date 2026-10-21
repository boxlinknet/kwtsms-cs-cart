/*
FILE:      js/addons/kwtsms/templates.js
CREATED:   2026-10-21 03:26 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      Script of the kwtSMS Templates page editor: the live counter and SMS meter (counter.js, a mirror of
           MessageCleaner), the worst-case estimate, the advice list, the placeholder chips, and the preview (a POST to
           kwtsms.preview_template, which sends nothing).
WHY:       PRD 6.4 and Task 1.12. Display only: the server checks and cleans again on save, and the exact preview comes
           from the server. Everything the server sends back is shown with textContent (never innerHTML), so a template
           body that holds an image tag with an onerror handler is only ever text. Reads its data from data- attributes of the
           editor root (.kwtsms-editor) and the page (.kwtsms-templates); holds no secret and no credential.
USAGE:     Loaded by hooks/index/scripts.post.tpl after counter.js. Runs on load and after CS-Cart's ajax re-render
           (ce.commoninit).
DEPENDS:   js/addons/kwtsms/counter.js (window.kwtsmsCounter), fetch, FormData.
*/
(function () {
    'use strict';

    var counter = window.kwtsmsCounter;
    if (!counter) {
        return;
    }

    // 2026-10-21 04:14 +03: the separator between the parts of a count follows the page direction (an Arabic comma in
    // Arabic), Task 1.12, task 596.
    var SEPARATOR = document.documentElement.dir === 'rtl' ? String.fromCharCode(0x60C) + ' ' : ', ';

    var INVISIBLE = /[\u{0}-\u{8}\u{B}-\u{1F}\u{7F}-\u{9F}\u{AD}\u{34F}\u{61C}\u{180E}\u{200B}-\u{200F}\u{2028}-\u{202F}\u{2060}-\u{206F}\u{3164}\u{FEFF}\u{FFA0}\u{FFFC}]/u;

    function parse(el, attr) {
        try {
            return JSON.parse(el.getAttribute(attr) || 'null');
        } catch (e) {
            return null;
        }
    }

    function fill(template, values) {
        return String(template).replace(/\[(\w+)\]/g, function (whole, key) {
            return Object.prototype.hasOwnProperty.call(values, key) ? String(values[key]) : whole;
        });
    }

    function show(el, visible) {
        if (el) {
            el.classList.toggle('hidden', !visible);
        }
    }

    /** Invisible characters are written as U+XXXX so the admin can see what was removed. */
    function printable(text) {
        return Array.from(String(text)).map(function (ch) {
            if (INVISIBLE.test(ch)) {
                var hex = ch.codePointAt(0).toString(16).toUpperCase();
                return 'U+' + ('0000' + hex).slice(-Math.max(4, hex.length));
            }
            return ch;
        }).join('');
    }

    /** Share of each of n meter segments that is filled for a counted text. */
    function fractions(c, n) {
        var single = c.unicode ? 70 : 160;
        var multi = c.unicode ? 67 : 153;
        var out = [];
        var i;
        var f;
        for (i = 0; i < n; i++) {
            if (c.pages === 0) {
                f = 0;
            } else if (c.pages === 1) {
                f = i === 0 ? c.units / single : 0;
            } else if (i < c.pages - 1) {
                f = 1;
            } else if (i === c.pages - 1) {
                f = (c.units - multi * (c.pages - 1)) / multi;
            } else {
                f = 0;
            }
            out.push(Math.max(0, Math.min(1, f)));
        }
        return out;
    }

    function initEditor(root, page) {
        if (root.getAttribute('data-kwtsms-ready') === '1') {
            return;
        }
        root.setAttribute('data-kwtsms-ready', '1');

        var config = parse(page, 'data-kwtsms-config') || { placeholders: [], promo_en: [], promo_ar: [], tlds: [] };
        var maxPages = parseInt(page.getAttribute('data-kwtsms-max-pages'), 10) || 7;
        var receiver = root.getAttribute('data-kwtsms-receiver') || 'C';
        var reference = parse(root, 'data-kwtsms-reference') || {};
        var worst = parse(root, 'data-kwtsms-worst') || {};
        var allowed = parse(root, 'data-kwtsms-allowed') || [];
        var body = root.querySelector('#kwtsms_body');
        var form = root.querySelector('#kwtsms_template_form');
        if (!body || !form) {
            return;
        }
        var segments = root.querySelectorAll('.kwtsms-meter__seg');
        var meter = root.querySelector('#kwtsms_meter');
        var outUnits = root.querySelector('#kwtsms_count_units');
        var outPages = root.querySelector('#kwtsms_count_pages');
        var outEnc = root.querySelector('#kwtsms_count_enc');
        var outWorst = root.querySelector('#kwtsms_worst_text');
        var noteWorst = root.querySelector('#kwtsms_worst_unicode');
        var over = root.querySelector('#kwtsms_over');
        var advice = root.querySelectorAll('#kwtsms_advice [data-code]');
        var encAscii = root.getAttribute('data-kwtsms-enc-ascii') || '';
        var encUnicode = root.getAttribute('data-kwtsms-enc-unicode') || '';
        var msgLength = root.getAttribute('data-kwtsms-msg-length') || '[units] / [pages]';

        function paint(counts, worstCounts) {
            var cur = fractions(counts, segments.length);
            var wor = fractions(worstCounts, segments.length);
            var i;
            for (i = 0; i < segments.length; i++) {
                segments[i].querySelector('.kwtsms-meter__fill').style.width = Math.round(cur[i] * 100) + '%';
                segments[i].querySelector('.kwtsms-meter__worst').style.width = Math.round(wor[i] * 100) + '%';
                segments[i].classList.toggle('is-over', counts.over || worstCounts.over);
            }
        }

        function update() {
            var text = body.value;
            var counts = counter.count(counter.clean(counter.render(text, reference)));
            var worstCounts = counter.count(counter.clean(counter.render(text, worst)));
            var found = counter.analyze(text, receiver, config);
            var unknown = counter.unknownPlaceholders(text, allowed);
            var i;
            var code;
            var detail;
            var present;

            outUnits.textContent = String(counts.units);
            outPages.textContent = String(counts.pages);
            outEnc.textContent = counts.units === 0 ? '-' : (counts.unicode ? encUnicode : encAscii);
            outWorst.textContent = fill(msgLength, { units: worstCounts.units, pages: worstCounts.pages }) +
                (worstCounts.units === 0 ? '' : SEPARATOR + (worstCounts.unicode ? encUnicode : encAscii));
            show(noteWorst, worstCounts.unicode && !counts.unicode && counts.units > 0);
            paint(counts, worstCounts);
            if (meter) {
                meter.setAttribute('aria-label', fill(msgLength, { units: counts.units, pages: counts.pages }));
            }
            if (over) {
                var tooLong = counts.over || worstCounts.over;
                over.textContent = tooLong ? (root.getAttribute('data-kwtsms-msg-over') || '') : '';
                show(over, tooLong);
            }

            for (i = 0; i < advice.length; i++) {
                code = advice[i].getAttribute('data-code');
                present = false;
                detail = '';
                if (code === 'unknown') {
                    present = unknown.length > 0;
                    detail = unknown.join(' ');
                } else if (Object.prototype.hasOwnProperty.call(found, code)) {
                    present = true;
                    detail = found[code].join(' ');
                }
                if (present) {
                    advice[i].querySelector('.kwtsms-advice__text').textContent = fill(advice[i].getAttribute('data-text') || '', { found: detail });
                }
                show(advice[i], present);
            }
        }

        body.addEventListener('input', update);
        update();

        // ---- placeholder chips: insert at the cursor ------------------------------------------------------------
        root.querySelectorAll('.kwtsms-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var token = chip.getAttribute('data-insert') || '';
                var start = body.selectionStart;
                var end = body.selectionEnd;
                if (typeof start !== 'number') {
                    start = end = body.value.length;
                }
                body.setRangeText(token, start, end, 'end');
                body.focus();
                body.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });

        // ---- preview ----------------------------------------------------------------------------------------------
        var sampleRadio = root.querySelector('#kwtsms_preview_sample');
        var orderRadio = root.querySelector('#kwtsms_preview_use_order');
        var orderInput = root.querySelector('#kwtsms_preview_order');
        var go = root.querySelector('#kwtsms_preview_go');
        var out = root.querySelector('#kwtsms_preview_out');
        var outText = root.querySelector('#kwtsms_preview_text');
        var outStats = root.querySelector('#kwtsms_preview_stats');
        var outNotes = root.querySelector('#kwtsms_preview_notes');

        function syncSource() {
            orderInput.disabled = !(orderRadio && orderRadio.checked);
            if (!orderInput.disabled) {
                orderInput.focus();
            }
        }
        if (sampleRadio && orderRadio && orderInput) {
            sampleRadio.addEventListener('change', syncSource);
            orderRadio.addEventListener('change', syncSource);
        }

        function note(text) {
            var li = document.createElement('li');
            li.textContent = text;
            outNotes.appendChild(li);
        }

        function showAnswer(answer) {
            outNotes.textContent = '';
            show(out, true);
            if (!answer || answer.ok !== true) {
                outText.textContent = '';
                outStats.textContent = '';
                note(answer && typeof answer.error === 'string' && answer.error !== '' ? answer.error : (root.getAttribute('data-kwtsms-msg-failed') || ''));
                return;
            }
            outText.textContent = String(answer.text);
            outStats.textContent = fill(msgLength, { units: answer.units, pages: answer.pages }) +
                (answer.units === 0 ? '' : SEPARATOR + (answer.unicode ? encUnicode : encAscii));
            if (answer.empty) {
                note(root.getAttribute('data-kwtsms-msg-empty') || '');
            }
            if (answer.leftover) {
                note(root.getAttribute('data-kwtsms-msg-leftover') || '');
            }
            if (answer.removed && answer.removed.length) {
                note(fill(root.getAttribute('data-kwtsms-msg-removed') || '[found]', { found: answer.removed.map(printable).join(' ') }));
            }
        }

        function preview() {
            var data = new FormData();
            var hash = form.elements.security_hash;
            var useOrder = !!orderRadio && orderRadio.checked;
            data.append('dispatch[kwtsms.preview_template]', '1');
            if (hash) {
                data.append('security_hash', hash.value);
            }
            data.append('kwtsms_tpl[event_id]', root.getAttribute('data-kwtsms-event') || '');
            data.append('kwtsms_tpl[receiver]', receiver);
            data.append('kwtsms_tpl[lang]', root.getAttribute('data-kwtsms-lang') || '');
            data.append('kwtsms_tpl[body]', body.value);
            data.append('kwtsms_tpl[source]', useOrder ? 'order' : 'sample');
            data.append('kwtsms_tpl[order_id]', useOrder ? orderInput.value : '');
            go.disabled = true;
            fetch(form.getAttribute('action') || window.location.href, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function (response) {
                    return response.json();
                })
                .then(showAnswer)
                .catch(function () {
                    showAnswer(null);
                })
                .then(function () {
                    go.disabled = false;
                });
        }
        if (go) {
            go.addEventListener('click', preview);
        }
        if (orderInput) {
            orderInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    preview();
                }
            });
        }
    }

    function init(context) {
        var root = context && context.jquery ? context.get(0) : (context || document);
        if (!root || !root.querySelector) {
            return;
        }
        var page = root.querySelector('.kwtsms-templates');
        if (!page) {
            return;
        }
        page.querySelectorAll('[data-kwtsms-editor]').forEach(function (editor) {
            initEditor(editor, page);
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
            init(context);
        });
    }
}());
