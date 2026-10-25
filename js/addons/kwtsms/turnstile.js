/*
FILE:      js/addons/kwtsms/turnstile.js
CREATED:   2026-10-24 21:15 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      Renders the Cloudflare Turnstile widgets of the storefront: every .kwtsms-turnstile container (class
           cf-turnstile, attributes data-sitekey, data-action, data-language, data-theme) is rendered once with
           turnstile.render(), on page load and on every content that CS-Cart inserts later (AJAX popups, the login
           dialog, a checkout step). Cloudflare adds the hidden field cf-turnstile-response inside the form.
WHY:       Task M1.7b (PRD 6.15). api.js is loaded with render=explicit, so nothing is rendered by Cloudflare's own scan
           and a widget in an AJAX-loaded form is not missed. The script may arrive after the page, so a short wait
           (at most 10 s) polls for window.turnstile; if it never arrives the form is refused by the server with the
           security-check message and no customer is let through.
USAGE:     Loaded by hooks/index/scripts.post.tpl only while Turnstile is the active antibot driver.
DEPENDS:   Tygh and jQuery of the storefront (ce.commoninit), https://challenges.cloudflare.com/turnstile/v0/api.js
           loaded with ?render=explicit.
*/
(function (_, $) {
    var WAIT_MS = 100;
    var MAX_TRIES = 100;
    var pending = [];
    var timer = null;
    var tries = 0;

    function ready() {
        return typeof window.turnstile === 'object' && window.turnstile !== null
            && typeof window.turnstile.render === 'function';
    }

    function renderOne(el) {
        var $el = $(el);
        if ($el.data('kwtsmsRendered') || !$.contains(document.documentElement, el)) {
            return;
        }
        $el.data('kwtsmsRendered', true);
        try {
            var options = {
                sitekey: $el.attr('data-sitekey'),
                action: $el.attr('data-action') || 'core',
                language: $el.attr('data-language') || 'en',
                theme: $el.attr('data-theme') || 'auto'
            };
            // 2026-10-25 03:11 +03: Task M1.8b fix round 1 (review item 2), task 596. Only the widget of the SMS code
            // field sets a field name (data-response-field-name); every other widget keeps cf-turnstile-response.
            if ($el.attr('data-response-field-name')) {
                options['response-field-name'] = $el.attr('data-response-field-name');
            }
            window.turnstile.render(el, options);
        } catch (e) {
            // A widget that cannot render leaves the form without a token: the server refuses it.
            $el.data('kwtsmsRendered', false);
        }
    }

    function flush() {
        var list = pending;
        pending = [];
        for (var i = 0; i < list.length; i++) {
            renderOne(list[i]);
        }
    }

    function wait() {
        if (timer !== null) {
            return;
        }
        tries = 0;
        timer = window.setInterval(function () {
            tries++;
            if (ready()) {
                window.clearInterval(timer);
                timer = null;
                flush();
            } else if (tries >= MAX_TRIES) {
                window.clearInterval(timer);
                timer = null;
                pending = [];
            }
        }, WAIT_MS);
    }

    function renderIn(context) {
        $('.kwtsms-turnstile', context).each(function () {
            if ($(this).data('kwtsmsRendered')) {
                return;
            }
            pending.push(this);
        });
        if (pending.length === 0) {
            return;
        }
        if (ready()) {
            flush();
        } else {
            wait();
        }
    }

    $.ceEvent('on', 'ce.commoninit', function (context) {
        renderIn(context);
    });
}(Tygh, Tygh.$));
