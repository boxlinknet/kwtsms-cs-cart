/*
FILE:      js/addons/kwtsms/phone_country.js
CREATED:   2026-11-06 07:37 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      The storefront phone country selector (C2). In the core mode "phone number with country selection" it
           filters the core country list to the allowed countries and preselects the default country on an empty field.
           In "international format" and "any digits" (and on plain phone inputs such as the code sign-in form) it adds a
           compact select of the allowed countries before the input, named kwtsms_cc[<field>] (the calling code). The
           code field script (code.js) sends the same value with a code request.
WHY:       Task M2.5 (SafatMall C2, P3-55). Customers pick one of the countries the store really sends to. The browser
           only helps: the server reads kwtsms_cc[<field>] as the first country hint when it is allowed, then checks
           the number (C3) and saves the canonical form; a value forced into the DOM is refused there.
USAGE:     Loaded by hooks/index/scripts.post.tpl while the switch and the country check are ON, after the JSON block
           <meta name="kwtsms-cc" id="kwtsms-cc-data" content="{json}" data-label="..."> (countries, default, mode). Node
           (tests/Js/phone_country.test.js) loads the pure helpers through module.exports.
DEPENDS:   Tygh and jQuery of the storefront (ce.commoninit), the core phone mask (js/tygh/phone_mask.js) markup.
*/
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else if (root.Tygh && root.Tygh.$) {
        api.boot(root.Tygh, root.Tygh.$, root.document);
    }
// 2026-11-06 07:45 +03: Task M2.5, task 596. window, not this: the storefront script bundle does not run files at top level.
}(typeof window !== 'undefined' ? window : this, function () {
    'use strict';

    var MODES = ['phone_number_with_country_selection', 'international_format', 'any_digits'];
    var FIELD_RE = /^user_data\[(phone|b_phone|s_phone)\]$/;
    var STYLE_ID = 'kwtsms-cc-style';

    // ---- pure helpers (tested in Node) -------------------------------------------------------------
    function allowedSymbols(data) {
        return ((data && data.countries) || []).map(function (c) {
            return '+' + c.cc;
        });
    }

    // A core list item (its "+code" symbol and lower or upper case ISO2) stays visible when its symbol is an allowed one.
    function keepItem(symbol, iso, data) {
        var s = String(symbol || '').replace(/\s+/g, '');
        if (s !== '' && allowedSymbols(data).indexOf(s) >= 0) {
            return true;
        }
        var code = String(iso || '').toUpperCase();
        return code !== '' && ((data && data.countries) || []).some(function (c) {
            return c.iso2 === code && ('+' + c.cc) === s;
        });
    }

    // The country preselected on an empty field: the default calling code, else the first allowed country.
    function defaultEntry(data) {
        var list = (data && data.countries) || [];
        for (var i = 0; i < list.length; i++) {
            if (list[i].cc === data.default) {
                return list[i];
            }
        }
        return list.length ? list[0] : null;
    }

    // The user_data field a phone input name belongs to ('' for any other name).
    function fieldOf(name) {
        var m = FIELD_RE.exec(String(name || ''));
        return m ? m[1] : '';
    }

    function ccName(field) {
        return 'kwtsms_cc[' + field + ']';
    }

    function modeOf(data) {
        return data && MODES.indexOf(data.mode) >= 0 ? data.mode : MODES[0];
    }

    // The calling code at the start of a typed international value ('' when none of the allowed ones).
    function codeOf(value, data) {
        var v = String(value || '').replace(/[\s()-]/g, '');
        var list = ((data && data.countries) || []).map(function (c) {
            return c.cc;
        }).sort(function (a, b) {
            return b.length - a.length;
        });
        for (var i = 0; i < list.length; i++) {
            if (v.indexOf('+' + list[i]) === 0 || v.indexOf('00' + list[i]) === 0) {
                return list[i];
            }
        }
        return '';
    }

    // ---- browser part ------------------------------------------------------------------------------
    function boot(_, $, doc) {
        // 2026-11-06 07:44 +03: Task M2.5, task 596. The data block is read when the page is ready, not when this script
        // runs: the script bundle can run before the block (printed in the head hook) is parsed.
        var data = null;
        var label = '';
        var mode = MODES[0];
        var choice = {};
        var loaded = false;

        function load() {
            if (loaded) {
                return data !== null;
            }
            var el = doc.getElementById('kwtsms-cc-data');
            if (!el) {
                return false;
            }
            loaded = true;
            try {
                // 2026-11-06 09:31 +03: Task M2.5, task 596. The JSON is the content attribute of a meta element.
                data = JSON.parse(el.getAttribute('content') || '');
            } catch (e) {
                data = null;
            }
            if (!data || !data.countries || !data.countries.length) {
                data = null;
                return false;
            }
            label = el.getAttribute('data-label') || '';
            mode = modeOf(data);

            return true;
        }

        function css() {
            if (doc.getElementById(STYLE_ID)) {
                return;
            }
            var st = doc.createElement('style');
            st.id = STYLE_ID;
            st.appendChild(doc.createTextNode(
                '.kwtsms-cc{display:block;box-sizing:border-box;width:100%;max-width:100%;margin:0 0 .4em;padding:4px 8px;' +
                'min-height:34px;font:inherit;color:inherit;background-color:#fff;border:1px solid #e1e5ec;border-radius:8px}' +
                '.kwtsms-cc:focus{outline:2px solid #1f6fb2;outline-offset:1px}'
            ));
            doc.head.appendChild(st);
        }

        // ---- the core country list (mode phone_number_with_country_selection) ----
        function filterCore($group) {
            $group.find('a.cm-phone-number-with-country-selection-li-link').each(function () {
                var $a = $(this);
                if (!keepItem($a.attr('data-ca-list-item-symbol'), $a.attr('data-ca-country-code'), data)) {
                    $a.closest('li').hide();
                }
            });
        }

        // The default country on an empty field (or one showing a country that is not allowed): the flag, the "+code" the
        // core script writes on focus and the placeholder. The field value is not touched.
        function preselectCore($input, $group) {
            var entry = defaultEntry(data);
            if (!entry || $.trim($input.val() || '') !== '' || $input.data('kwtsmsPre')) {
                return;
            }
            var symbol = '+' + entry.cc;
            var iso = String(entry.iso2 || '').toUpperCase();
            if ($input.attr('data-ca-phone-mask-symbol') === symbol && $input.attr('data-ca-phone-mask-country') === iso) {
                $input.data('kwtsmsPre', 1);
                return;
            }
            $input.attr('data-ca-phone-mask-symbol', symbol).data('caPhoneMaskSymbol', symbol);
            $input.attr('data-ca-phone-mask-country', iso).data('caPhoneMaskCountry', iso);
            if ($input.attr('placeholder') && $.trim($input.attr('placeholder')) !== '') {
                $input.attr('placeholder', symbol);
            }
            var $icon = $group.find('[data-ca-toggle="dropdown"] .ty-flag, [data-toggle="dropdown"] .flag').first();
            if ($icon.length) {
                var prefix = $icon.hasClass('ty-flag') ? 'ty-flag' : 'flag';
                $.each(($icon.attr('class') || '').split(/\s+/), function (i, c) {
                    if (c.indexOf(prefix + '-') === 0) {
                        $icon.removeClass(c);
                    }
                });
                $icon.addClass(prefix + '-' + iso.toLowerCase());
            }
            $input.data('kwtsmsPre', 1);
        }

        // ---- own select (the other modes, and inputs without the core list) ----
        function buildSelect(field, $input) {
            var $sel = $('<select class="kwtsms-cc" dir="auto"></select>')
                .attr('name', ccName(field))
                .attr('aria-label', label)
                .attr('data-kwtsms-cc-for', field);
            $.each(data.countries, function (i, c) {
                $('<option></option>').attr('value', c.cc).text(c.name + ' (+' + c.cc + ')').appendTo($sel);
            });
            var typed = codeOf($input.val(), data);
            var entry = defaultEntry(data);
            var start = choice[field] || typed || (entry ? entry.cc : data.countries[0].cc);
            $sel.val(start);
            if ($sel.val() !== start) {
                $sel.prop('selectedIndex', 0);
            }
            return $sel;
        }

        function addSelect($input, field, masked) {
            if ($input.data('kwtsmsCc') || !field) {
                return;
            }
            var $sel = buildSelect(field, $input);
            $input.data('kwtsmsCc', 1);
            var $box = $input.closest('.cm-field-container');
            // 2026-11-06 09:59 +03: Task M2.5, task 596. The lite checkout field draws its floating label over the first
            // row of its box (the screenshot showed the select under the label text): there the select goes after the
            // input, inside the same box; everywhere else it goes before the input.
            // 2026-11-06 11:28 +03: the test is on the input's ancestors (the inner box is not the litecheckout__field).
            if ($input.closest('.litecheckout__field').length) {
                $input.after($sel);
                $sel.css('margin', '.4em 0 0');
            } else {
                ($box.length ? $box : $input).before($sel);
            }
            $sel.on('change', function () {
                choice[field] = $sel.val();
                // The masked international format field is typed with its own country code: the choice only changes the
                // hint shown in the empty field (the mask owns the value).
                if (masked && mode === 'international_format' && $.trim($input.val() || '') === '') {
                    $input.attr('placeholder', '+' + $sel.val());
                }
            });
            // A number typed with one of the allowed codes moves the select to that country (the field is the truth).
            if (masked) {
                $input.on('input change', function () {
                    var typed = codeOf($input.val(), data);
                    if (typed !== '' && $sel.val() !== typed) {
                        $sel.val(typed);
                        choice[field] = typed;
                    }
                });
            }
        }

        function apply($context) {
            if (!load()) {
                return;
            }
            css();
            $context.find('input[type="tel"], input.cm-mask-phone, input[name]').filter(function () {
                return fieldOf($(this).attr('name')) !== '';
            }).each(function () {
                var $input = $(this);
                var field = fieldOf($input.attr('name'));
                var $group = $input.closest('.cm-field-container');
                if (mode === 'phone_number_with_country_selection' && $group.find('.cm-phone-number-with-country-selection-btn-group').length) {
                    filterCore($group);
                    preselectCore($input, $group);
                    return;
                }
                addSelect($input, field, $input.hasClass('cm-mask-phone'));
            });
            // The code sign-in and recovery forms: a plain input named "phone" next to the code field.
            $context.find('input[name="phone"]').filter(function () {
                return $(this).closest('form').find('.kwtsms-code').length > 0;
            }).each(function () {
                addSelect($(this), 'phone', false);
            });
        }

        $.ceEvent('on', 'ce.commoninit', function (context) {
            apply($(context));
        });
        $(function () {
            apply($(doc));
        });
    }

    return {
        allowedSymbols: allowedSymbols,
        keepItem: keepItem,
        defaultEntry: defaultEntry,
        fieldOf: fieldOf,
        ccName: ccName,
        modeOf: modeOf,
        codeOf: codeOf,
        boot: boot
    };
}));
