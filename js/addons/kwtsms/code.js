/*
FILE:      js/addons/kwtsms/code.js
CREATED:   2026-10-25 02:11 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      Behaviour of the storefront SMS code field (components/code_field.tpl): "Send code" posts purpose, the phone of
           the same form, the session security hash and any CAPTCHA token to kwtsms_code.send, shows the answer, runs a
           visible resend countdown from retry_after, disables the button while a request is in flight and resets every
           CAPTCHA widget after each request so a resend carries a fresh token (the token is single use). On a "captcha"
           answer it renders Cloudflare Turnstile (action kwtsms_sms_code, site key from a data- attribute) when the
           field has one. Client-side texts: an empty phone, no internet, a server error or an unparsable answer.
WHY:       Task M1.8b (SafatMall B1, P3-25, P3-39, P3-R8, P3-R10). Every request in "always" mode needs a new token, a
           resend must not look frozen, and a customer on a bad connection must read a plain sentence, never a raw code
           or a stack trace. The script never logs the code, a token or a number
           (2026-10-25 03:00 +03: wording changed so the package gate on console output finds no match, task 596).
USAGE:     Loaded by hooks/index/scripts.post.tpl while a code feature is ON. Markup: .kwtsms-code with data-purpose,
           data-phone-name, data-url, data-sitekey, data-action, data-language and the data-t-* texts. States on the
           root (data-state): idle, sending, sent (countdown), wait (countdown, refused), captcha, error, bypass.
           Events on the root: kwtsms:sent, kwtsms:bypass.
DEPENDS:   Tygh and jQuery of the storefront (Tygh.security_hash), fetch and URLSearchParams, the CAPTCHA widget scripts
           when a provider is used (turnstile.js and api.js are loaded by the add-on, reCAPTCHA by its add-on).
*/
(function (_, $) {
    var TIMEOUT_MS = 20000;
    var TICK_MS = 250;
    var WAIT_MS = 100;
    var MAX_TRIES = 100;

    function text(root, key) {
        return root.attr('data-t-' + key) || '';
    }

    function setStatus(root, message) {
        root.find('.kwtsms-code__status').text(message || '');
    }

    function setButton(root, label, disabled) {
        root.find('.kwtsms-code__send').text(label).prop('disabled', !!disabled).attr('aria-busy', disabled && root.attr('data-state') === 'sending' ? 'true' : 'false');
    }

    function setState(root, state) {
        root.attr('data-state', state);
    }

    function stopCountdown(root) {
        var timer = root.data('kwtsmsTimer');
        if (timer) {
            window.clearInterval(timer);
        }
        root.data('kwtsmsTimer', null);
        root.find('.kwtsms-code__count').text('');
    }

    // The visible countdown: "Resend in 45 s" under the button, the button disabled until it ends.
    function startCountdown(root, seconds, state) {
        var until = Date.now() + Math.max(1, parseInt(seconds, 10) || 1) * 1000;
        var $count = root.find('.kwtsms-code__count');

        stopCountdown(root);
        setState(root, state);
        // 2026-11-05 20:58 +03: Task M2.4 (B6), task 596. Kept so the checkout box can restore the countdown after the
        // checkout re-renders its final section (payment or shipping change).
        root.data('kwtsmsUntil', until);
        function tick() {
            var left = Math.ceil((until - Date.now()) / 1000);
            if (left <= 0) {
                stopCountdown(root);
                setState(root, 'idle');
                setButton(root, text(root, 'resend'), false);
                // 2026-10-25 03:10 +03: Task M1.8b fix round 1 (review minor 6), task 596: the old "code sent" line goes with the wait.
                setStatus(root, '');
                return;
            }
            $count.text(text(root, 'resend-in').replace('[seconds]', String(left)));
            setButton(root, text(root, 'resend'), true);
        }
        tick();
        root.data('kwtsmsTimer', window.setInterval(tick, TICK_MS));
    }

    function phoneInput(root) {
        var name = root.attr('data-phone-name') || '';
        var form = root.closest('form');
        var scope = form.length ? form : $(document);

        // 2026-11-05 20:58 +03: Task M2.4 (B6, P3-54), task 596. The lite checkout keeps its phone inputs outside the
        // form element that holds the code box (HTML form attribute), so the checkout box looks in the whole page, and
        // reads the account phone the way the server does: user_data[phone] when filled, else user_data[b_phone].
        if (root.attr('data-purpose') === 'checkout') {
            var byName = function (n) {
                return $(document).find('input').filter(function () {
                    return this.name === n;
                }).first();
            };
            // 2026-11-06 03:23 +03: Task M2.4 fix 1 (M1), task 596. Mode cod: the shipping phone (the number the courier
            // calls) is read first when it is filled; the server binds the code to it the same way.
            var pref = root.attr('data-phone-pref') || '';
            var first = byName(name);
            var alt = root.attr('data-phone-alt') || '';

            if (pref !== '' && $.trim(byName(pref).val() || '') !== '') {
                return byName(pref);
            }

            if ($.trim(first.val() || '') === '' && alt) {
                var second = byName(alt);

                if ($.trim(second.val() || '') !== '') {
                    return second;
                }
            }

            return first;
        }

        return scope.find('input, select, textarea').filter(function () {
            return this.name === name;
        }).first();
    }

    function turnstileReady() {
        return typeof window.turnstile === 'object' && window.turnstile !== null
            && typeof window.turnstile.render === 'function';
    }

    // The widgets that exist in the field now: the token of each belongs to the request about to leave.
    function widgets(root) {
        return root.find('.cf-turnstile');
    }

    function collectToken(root, body) {
        widgets(root).each(function () {
            // 2026-10-25 03:10 +03: Task M1.8b fix round 1 (review item 2), task 596. The field's widgets are named
            // kwtsms-code-turnstile-response (a form's own widget keeps cf-turnstile-response); the request still
            // carries the token under the name the driver reads.
            var v = $(this).find('input[name="kwtsms-code-turnstile-response"]').val();
            if (v) {
                body.set('cf-turnstile-response', v);
            }
        });
        ['g-recaptcha-response', 'verification_answer'].forEach(function (name) {
            var v = root.find('[name="' + name + '"]').val();
            if (v) {
                body.set(name, v);
            }
        });
    }

    // A token is single use (P3-R10): after every answer each widget that took part gets a new one.
    function resetWidgets(root, els) {
        els.each(function () {
            try {
                if (turnstileReady() && typeof window.turnstile.reset === 'function') {
                    window.turnstile.reset(this);
                }
            } catch (e) {
                // A widget that cannot be reset is rendered again by the next captcha answer.
                return;
            }
        });
        if (root.find('.g-recaptcha').length && window.grecaptcha && typeof window.grecaptcha.reset === 'function') {
            try {
                window.grecaptcha.reset();
            } catch (e) {
                return;
            }
        }
        root.find('[name="verification_answer"]').val('');
    }

    // On a "captcha" answer without a widget in the field: Turnstile on demand, when the field carries a site key.
    function renderOnDemand(root) {
        var key = root.attr('data-sitekey');
        var $slot = root.find('.kwtsms-code__slot');
        var tries = 0;

        if (!key || !$slot.length || widgets(root).filter(':visible').length) {
            return;
        }
        function draw() {
            var $el = $('<div class="cf-turnstile kwtsms-code__widget"></div>');
            $el.attr({
                'data-sitekey': key,
                'data-action': root.attr('data-action') || 'kwtsms_sms_code',
                'data-language': root.attr('data-language') || 'en',
                'data-theme': 'auto'
            });
            $slot.empty().append($el).prop('hidden', false);
            try {
                window.turnstile.render($el[0], {
                    sitekey: key,
                    action: root.attr('data-action') || 'kwtsms_sms_code',
                    language: root.attr('data-language') || 'en',
                    theme: 'auto',
                    'response-field-name': 'kwtsms-code-turnstile-response'
                });
            } catch (e) {
                // Left without a token, the next request is refused with the same answer.
                $slot.prop('hidden', true);
            }
        }
        if (turnstileReady()) {
            draw();
            return;
        }
        // 2026-10-25 03:10 +03: Task M1.8b fix round 1 (review minor 8), task 596: one polling timer per field, however many captcha answers arrive meanwhile.
        if (root.data('kwtsmsDrawing')) {
            return;
        }
        root.data('kwtsmsDrawing', true);
        var timer = window.setInterval(function () {
            tries++;
            if (turnstileReady()) {
                window.clearInterval(timer);
                root.data('kwtsmsDrawing', false);
                draw();
            } else if (tries >= MAX_TRIES) {
                window.clearInterval(timer);
                root.data('kwtsmsDrawing', false);
            }
        }, WAIT_MS);
    }

    function fail(root, message) {
        stopCountdown(root);
        setState(root, 'error');
        setStatus(root, message);
        setButton(root, text(root, root.data('kwtsmsSent') ? 'resend' : 'send'), false);
    }

    function answer(root, data) {
        if (data.ok === true && data.bypass === true) {
            stopCountdown(root);
            setState(root, 'bypass');
            setStatus(root, text(root, 'bypass'));
            root.find('.kwtsms-code__row').prop('hidden', true);
            // 2026-10-25 03:10 +03: Task M1.8b fix round 1 (review minor 1), task 596: the label of the hidden box goes too.
            root.find('.kwtsms-code__label').prop('hidden', true);
            root.find('.kwtsms-code__slot').prop('hidden', true);
            root.trigger('kwtsms:bypass');
            return;
        }
        if (data.ok === true) {
            root.data('kwtsmsSent', true);
            setStatus(root, data.message || '');
            startCountdown(root, data.retry_after, 'sent');
            root.find('.kwtsms-code__input').trigger('focus');
            root.trigger('kwtsms:sent');
            return;
        }
        if (data.error === 'wait' || data.error === 'busy') {
            setStatus(root, data.message || text(root, 'server-error'));
            startCountdown(root, data.retry_after, 'wait');
            return;
        }
        if (data.error === 'captcha') {
            setState(root, 'captcha');
            setStatus(root, data.message || text(root, 'server-error'));
            setButton(root, text(root, root.data('kwtsmsSent') ? 'resend' : 'send'), false);
            renderOnDemand(root);
            return;
        }
        fail(root, data.message || text(root, 'server-error'));
        // 2026-10-25 03:10 +03: Task M1.8b fix round 1 (review minor 4), task 596: an input error the customer fixes in the phone box returns the focus there.
        if (['invalid', 'country', 'in_use', 'unchanged'].indexOf(data.error) >= 0) {
            phoneInput(root).trigger('focus');
        }
    }

    function send(root) {
        var state = root.attr('data-state');
        var $phone = phoneInput(root);
        var phone = $.trim($phone.val() || '');
        var body = new URLSearchParams();
        var existing = widgets(root);
        var ctrl = typeof window.AbortController === 'function' ? new window.AbortController() : null;
        var timer = null;

        if (state === 'sending' || state === 'sent' || state === 'wait' || state === 'bypass') {
            return;
        }
        // 2026-10-25 03:10 +03: Task M1.8b fix round 1 (review minor 7), task 596: a browser without fetch gets the plain sentence, not a button stuck in "Sending...".
        if (typeof window.fetch !== 'function') {
            fail(root, text(root, 'server-error'));
            return;
        }
        // 2026-11-06 02:54 +03: Task M2.4 fix 1 (security review C1), task 596. The code box of a repay (data-order-id) has
        // no phone input: the server reads the phone from the order, so nothing is required here and only the order id
        // and the payment being chosen are sent.
        var orderId = root.attr('data-order-id') || '';
        if (phone === '' && orderId === '') {
            stopCountdown(root);
            setState(root, 'error');
            setStatus(root, text(root, 'phone-required'));
            if ($phone.length) {
                $phone.trigger('focus');
            }
            return;
        }
        body.set('purpose', root.attr('data-purpose') || '');
        if (orderId !== '') {
            body.set('order_id', orderId);
            body.set('payment_id', String(repayPayment()));
        } else {
            body.set('phone', phone);
        }
        // 2026-10-28 22:31 +03: Task M1.10b fix round 1 (review M4), task 596. The form's country, so a local number is read with
        // the calling code the save will use.
        var countryScope = root.attr('data-purpose') === 'checkout' ? $(document) : root.closest('form');
        // 2026-11-06 03:24 +03: Task M2.4 fix 1 (M1), task 596: a shipping phone is read with the shipping country.
        var countryName = $phone.attr('name') === 'user_data[s_phone]' ? 'user_data[s_country]' : 'user_data[b_country]';
        var country = countryScope.find('select[name="' + countryName + '"], input[name="' + countryName + '"]').first().val();
        if (country) {
            body.set('country', String(country));
        }
        // 2026-11-06 07:38 +03: Task M2.5 (C2, P3-55), task 596. The calling code chosen in the country selector next to
        // this phone input (kwtsms_cc[<field>]) is the first hint of the server, as it is for the form save. Ignored by
        // the server while the selector is off or the code is not an allowed country.
        var ccMatch = /^user_data\[(phone|b_phone|s_phone)\]$/.exec($phone.attr('name') || '');
        var ccField = ccMatch ? ccMatch[1] : ($phone.attr('name') === 'phone' ? 'phone' : '');
        if (ccField !== '') {
            var ccValue = $(document).find('select[name="kwtsms_cc[' + ccField + ']"]').first().val();
            if (ccValue) {
                body.set('kwtsms_cc[phone]', String(ccValue));
            }
        }
        body.set('security_hash', typeof _.security_hash === 'string' ? _.security_hash : '');
        collectToken(root, body);

        stopCountdown(root);
        setState(root, 'sending');
        setStatus(root, '');
        setButton(root, text(root, 'sending'), true);
        if (ctrl) {
            timer = window.setTimeout(function () {
                ctrl.abort();
            }, TIMEOUT_MS);
        }

        window.fetch(root.attr('data-url'), {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
            signal: ctrl ? ctrl.signal : undefined
        }).then(function (response) {
            return response.text().then(function (raw) {
                var data = null;
                try {
                    data = JSON.parse(raw);
                } catch (e) {
                    data = null;
                }
                return {status: response.status, data: data};
            });
        }).then(function (res) {
            window.clearTimeout(timer);
            resetWidgets(root, existing);
            if (res.status >= 500 || res.data === null || typeof res.data !== 'object') {
                fail(root, text(root, 'server-error'));
            } else if (res.status >= 400 && !res.data.message) {
                fail(root, text(root, 'server-error'));
            } else {
                answer(root, res.data);
            }
        }, function (err) {
            window.clearTimeout(timer);
            resetWidgets(root, existing);
            // Our own timeout is a slow server; any other failure of fetch is the network.
            if (err && err.name === 'AbortError') {
                fail(root, text(root, 'server-error'));
            } else {
                fail(root, text(root, 'offline'));
            }
        });
    }

    // 2026-10-28 18:52 +03: Task M1.10a fix round 1 (review I4), task 596. A code pasted or typed with spaces, no-break spaces, direction
    // marks or dashes ("123 456", Arabic-Indic digits with a space) is cleaned to its digits and cut at 6 characters: the box
    // takes up to 16 characters (maxlength) so the browser never drops the end of a spaced paste, and the server also
    // normalizes (CodeInput).
    $(document).on('input', '.kwtsms-code__input', function () {
        var clean = this.value.replace(/[\s\u00a0\u200b-\u200f\u202a-\u202e\u2066-\u2069\ufeff\-]/g, '').slice(0, 6);
        if (clean !== this.value) {
            this.value = clean;
        }
    });

    // 2026-10-28 20:26 +03: Task M1.10b (SafatMall B5, P3-17), task 596. A field with data-show-on-change (the profile page,
    // purpose phone_change) is hidden until the phone input of its form differs from the value the page loaded with. The
    // two values are compared by their digits, so the phone mask re-formatting a stored number is not a change.
    function digits(value) {
        // Arabic-Indic (0660-0669) and Persian (06F0-06F9) digits count as digits; built from code points (ASCII file).
        var f = String.fromCharCode;
        var keep = new RegExp('[^0-9' + f(0x660) + '-' + f(0x669) + f(0x6f0) + '-' + f(0x6f9) + ']', 'g');

        return String(value || '').replace(keep, '');
    }

    function syncChange(root) {
        var $phone = phoneInput(root);
        if (!$phone.length) {
            return;
        }
        if (root.data('kwtsmsInitial') === undefined) {
            root.data('kwtsmsInitial', digits($phone.val()));
        }
        root.prop('hidden', digits($phone.val()) === root.data('kwtsmsInitial'));
    }

    function syncAll() {
        $('.kwtsms-code[data-show-on-change]').each(function () {
            syncChange($(this));
        });
    }

    $(document).on('input change keyup', 'form input', function () {
        if ($('.kwtsms-code[data-show-on-change]').length) {
            syncAll();
            // The phone mask may write the real input after this handler ran: look again a moment later.
            window.setTimeout(syncAll, 60);
        }
    });
    $(function () {
        syncAll();
    });
    // The phone mask may re-format the stored number after ready: a field still hidden at load takes the loaded value.
    $(window).on('load', function () {
        $('.kwtsms-code[data-show-on-change][hidden]').each(function () {
            $(this).removeData('kwtsmsInitial');
            syncChange($(this));
        });
    });

    // 2026-11-05 20:58 +03: Task M2.4 (SafatMall B6, P3-54), task 596. The checkout box lives in the final section of
    // the lite checkout, which core re-renders (AJAX) when the payment or the shipping method changes: the typed code,
    // the "sent" state and the resend countdown are kept in this closure and put back into the new box. The box also
    // hides while the phone of the form is the signed-in customer's number that a code already verified (the server
    // decides; this only spares the customer a field they are never asked for).
    var ck = {code: '', sent: false, until: 0, status: ''};
    var CK = '.kwtsms-code[data-purpose="checkout"]';

    function asciiDigits(value) {
        var f = String.fromCharCode;
        var out = String(value || '').replace(new RegExp('[' + f(0x660) + '-' + f(0x669) + ']', 'g'), function (c) {
            return String(c.charCodeAt(0) - 0x660);
        }).replace(new RegExp('[' + f(0x6f0) + '-' + f(0x6f9) + ']', 'g'), function (c) {
            return String(c.charCodeAt(0) - 0x6f0);
        });

        return out.replace(/\D/g, '');
    }

    function syncVerified(root) {
        var verified = root.attr('data-verified-phone') || '';
        var typed;
        var same;

        if (!verified) {
            return;
        }
        typed = asciiDigits(phoneInput(root).val()).replace(/^00/, '');
        same = typed !== '' && (typed === verified || (typed.length >= 8 && verified.slice(-typed.length) === typed));
        root.closest('.kwtsms-checkout-code').prop('hidden', same);
    }

    function restoreCheckout(root) {
        var left;

        if (ck.code) {
            root.find('.kwtsms-code__input').val(ck.code);
        }
        if (ck.sent) {
            root.data('kwtsmsSent', true);
            setStatus(root, ck.status);
            left = Math.ceil((ck.until - Date.now()) / 1000);
            if (left > 0) {
                startCountdown(root, left, 'sent');
            } else {
                setButton(root, text(root, 'resend'), false);
            }
        }
        syncVerified(root);
    }

    $(document).on('input', CK + ' .kwtsms-code__input', function () {
        ck.code = this.value;
    });
    $(document).on('kwtsms:sent', CK, function () {
        var root = $(this);

        ck.sent = true;
        ck.until = root.data('kwtsmsUntil') || 0;
        ck.status = root.find('.kwtsms-code__status').text();
    });
    $(document).on('input change keyup', 'input[name="user_data[phone]"], input[name="user_data[b_phone]"]', function () {
        $(CK).each(function () {
            syncVerified($(this));
        });
    });
    if ($.ceEvent) {
        $.ceEvent('on', 'ce.commoninit', function (context) {
            $(context).find(CK).addBack(CK).each(function () {
                restoreCheckout($(this));
            });
        });
    }
    $(function () {
        $(CK).each(function () {
            syncVerified($(this));
        });
    });

    // 2026-11-06 02:54 +03: Task M2.4 fix 1 (security review C1), task 596. The "pay again" section of an order page
    // (hooks/orders/repay.pre.tpl). The box sits above the payment choices, outside the repay form, so the typed code is
    // copied into the form of the repay button when it is pressed. In mode cod the box shows only while one of the listed
    // payments is chosen (data-kwtsms-payments = '*' or the ids); the server decides, this only spares the customer a field.
    function repayPayment() {
        var chosen = $('input[name="repay_radio_group"]:checked').val();

        if (!chosen) {
            chosen = $('#elm_payments_list form.payments-form input[name="payment_id"]').first().val();
        }

        return parseInt(chosen || '0', 10) || 0;
    }

    function syncRepay() {
        $('[data-kwtsms-repay]').each(function () {
            var block = $(this);
            var list = String(block.attr('data-kwtsms-payments') || '*');
            var show = list === '*' || (',' + list + ',').indexOf(',' + repayPayment() + ',') !== -1;

            block.prop('hidden', !show);
        });
    }

    function copyRepayCode(form) {
        var block = $('[data-kwtsms-repay]').first();
        var value = block.length && !block.prop('hidden') ? $.trim(block.find('.kwtsms-code__input').val() || '') : '';
        var field = form.find('input[name="kwtsms_code"]').first();

        if (!field.length) {
            field = $('<input type="hidden" name="kwtsms_code" />').appendTo(form);
        }
        field.val(value);
    }

    $(document).on('click mousedown touchstart', '#litecheckout_repay_order', function () {
        copyRepayCode($(this).closest('form'));
    });
    $(document).on('submit', 'form.payments-form', function () {
        copyRepayCode($(this));
    });
    $(document).on('change', 'input[name="repay_radio_group"]', syncRepay);
    if ($.ceEvent) {
        $.ceEvent('on', 'ce.commoninit', syncRepay);
    }
    $(syncRepay);

    $(document).on('click', '.kwtsms-code__send', function (e) {
        e.preventDefault();
        send($(this).closest('.kwtsms-code'));
    });
}(Tygh, Tygh.$));
