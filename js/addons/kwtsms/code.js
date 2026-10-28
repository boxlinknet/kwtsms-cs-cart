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
        if (phone === '') {
            stopCountdown(root);
            setState(root, 'error');
            setStatus(root, text(root, 'phone-required'));
            if ($phone.length) {
                $phone.trigger('focus');
            }
            return;
        }
        body.set('purpose', root.attr('data-purpose') || '');
        body.set('phone', phone);
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

    $(document).on('click', '.kwtsms-code__send', function (e) {
        e.preventDefault();
        send($(this).closest('.kwtsms-code'));
    });
}(Tygh, Tygh.$));
