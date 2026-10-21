/*
FILE:      js/addons/kwtsms/counter.js
CREATED:   2026-10-21 03:12 +03
TASK:      596
AUTHOR:    kwtSMS
WHAT:      The SMS counter of the Templates page: a mirror of Sms\MessageCleaner (clean, isUnicode, units, pages) and of
           the template rendering of the page (placeholder values from the sample data), plus the same advice as
           Templates\TemplateChecks (store_name missing, HTML-like span, touching placeholders, entity fragments,
           promotional wording).
WHY:       PRD 6.4 and Task 1.12: the live counter must give the number the gateway will bill. Rules mirrored exactly:
           a message is Unicode when any character is above U+007F; length is UTF-16 units (a character above U+FFFF
           counts 2, which is what a JavaScript string length is); pages are 160 / 153 for ASCII and 70 / 67 for Unicode,
           the gateway refuses more than 7. Cleaning runs first, in the order of PRD 6.2.1 (entities, comments and real
           tags, literal escapes, line endings, digits, hidden characters, special spaces, emoji, smart quotes, trim), up
           to 8 passes, and a text that does not settle counts as empty (fail closed, like clean()).
           Known limit: named HTML entities are decoded from a short table (amp, lt, gt, quot, apos, nbsp and the common
           typographic ones); PHP knows the full HTML5 table. The page's Preview button asks the server for the exact
           answer. tests/fixtures/templates-counter-parity.json proves the two agree on 30 bodies.
USAGE:     Browser: window.kwtsmsCounter. Node: require('./counter.js').
             clean(text)                     -> cleaned text ('' when blocked)
             isUnicode(text), units(text), pages(text)      on cleaned text
             count(cleaned)                  -> {units, pages, unicode, over}  (over: more than 7 pages)
             render(body, values)            -> body with {name} replaced (known names only)
             analyze(body, receiver, config) -> {code: [found, ...]} for the codes of TemplateChecks, in its order;
                                                config = {placeholders, promo_en, promo_ar, tlds}
             unknownPlaceholders(body, allowed) -> ['{typo}', ...]
DEPENDS:   Nothing (plain JavaScript, no DOM, no library). Display text is the caller's job and uses textContent.
*/
(function (root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.kwtsmsCounter = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var MAX_PAGES = 7;
    var MAX_PASSES = 8;

    var ENTITIES = {
        amp: '&', lt: '<', gt: '>', quot: '"', apos: "'",
        nbsp: '\u{A0}', copy: '\u{A9}', reg: '\u{AE}', hellip: '\u{2026}', mdash: '\u{2014}',
        ndash: '\u{2013}', lsquo: '\u{2018}', rsquo: '\u{2019}', ldquo: '\u{201C}', rdquo: '\u{201D}',
        euro: '\u{20AC}', pound: '\u{A3}', yen: '\u{A5}', cent: '\u{A2}', trade: '\u{2122}',
        laquo: '\u{AB}', raquo: '\u{BB}', times: '\u{D7}', deg: '\u{B0}', Tab: '\t',
        NewLine: '\n'
    };

    var TYPOGRAPHY = {
        '\u{2010}': '-', '\u{2011}': '-', '\u{FF0D}': '-', '\u{2012}': '-',
        '\u{2013}': '-', '\u{2014}': '-', '\u{2015}': '-', '\u{2212}': '-',
        '\u{2032}': "'", '\u{2033}': '"', '\u{2018}': "'", '\u{2019}': "'",
        '\u{201A}': "'", '\u{201B}': "'", '\u{201C}': '"', '\u{201D}': '"',
        '\u{201E}': '"', '\u{201F}': '"', '\u{2026}': '...'
    };

    var TYPO_CHARS = /[\u{2010}\u{2011}\u{FF0D}\u{2012}\u{2013}\u{2014}\u{2015}\u{2212}\u{2032}\u{2033}\u{2018}\u{2019}\u{201A}\u{201B}\u{201C}\u{201D}\u{201E}\u{201F}\u{2026}]/gu;

    var HIDDEN = /[\u{0}-\u{8}\u{B}-\u{1F}\u{7F}-\u{9F}\u{AD}\u{34F}\u{61C}\u{180E}\u{200B}-\u{200F}\u{202A}-\u{202E}\u{2060}-\u{2064}\u{2066}-\u{2069}\u{206A}-\u{206F}\u{3164}\u{FEFF}\u{FFA0}\u{FFFC}]/gu;
    var SPECIAL_SPACES = /[\u{2000}-\u{200A}\u{202F}\u{205F}\u{3000}\u{A0}]/gu;
    var SIGN_WITH_SELECTOR = /[\u{A9}\u{AE}]\u{FE0F}/gu;
    var EMOJI = /[\u{1F000}-\u{1FAFF}\u{20E3}\u{E0000}-\u{E007F}\u{2600}-\u{27BF}\u{FE00}-\u{FE0F}\u{2190}-\u{21FF}\u{2300}-\u{23FF}\u{24C2}\u{25A0}-\u{25FF}\u{2900}-\u{297F}\u{2B00}-\u{2BFF}\u{3030}\u{303D}\u{3297}\u{3299}]/gu;
    var TAG = /<\/?[A-Za-z][A-Za-z0-9-]*(?:[ \t\n\u000B\f\r][^<>]*)?\/?>/g;
    var COMMENT = /<!--[\s\S]*?-->/g;
    var ENTITY = /&(?:#[xX]([0-9A-Fa-f]{1,8})|#([0-9]{1,10})|([A-Za-z][A-Za-z0-9]*));/g;

    function trim(text) {
        return text.replace(/^[ \t\n\r\u0000\u000B]+|[ \t\n\r\u0000\u000B]+$/g, '');
    }

    /** A code point PHP's html_entity_decode (ENT_HTML5) accepts from a numeric entity. */
    function allowedCodePoint(cp) {
        return (cp >= 0x20 && cp <= 0x7E) ||
            (cp >= 0x09 && cp <= 0x0D && cp !== 0x0B) ||
            (cp >= 0xA0 && cp <= 0xD7FF) ||
            (cp >= 0xE000 && cp <= 0x10FFFF && (cp & 0xFFFF) < 0xFFFE && (cp < 0xFDD0 || cp > 0xFDEF));
    }

    function decodeEntities(text) {
        return text.replace(ENTITY, function (whole, hex, dec, name) {
            var cp;
            if (hex !== undefined || dec !== undefined) {
                cp = hex !== undefined ? parseInt(hex, 16) : parseInt(dec, 10);
                return allowedCodePoint(cp) ? String.fromCodePoint(cp) : whole;
            }
            return Object.prototype.hasOwnProperty.call(ENTITIES, name) ? ENTITIES[name] : whole;
        });
    }

    /** Step 1 of PRD 6.2.1 for a JavaScript string: lone surrogates are the invalid sequences. */
    function dropInvalid(text) {
        var out = '';
        var i;
        var c;
        for (i = 0; i < text.length; i++) {
            c = text.charCodeAt(i);
            if (c >= 0xD800 && c <= 0xDBFF) {
                if (i + 1 < text.length && text.charCodeAt(i + 1) >= 0xDC00 && text.charCodeAt(i + 1) <= 0xDFFF) {
                    out += text.charAt(i) + text.charAt(i + 1);
                    i++;
                }
            } else if (c < 0xDC00 || c > 0xDFFF) {
                out += text.charAt(i);
            }
        }
        return out;
    }

    function settleMarkup(text) {
        var pass;
        var before;
        for (pass = 0; pass < MAX_PASSES; pass++) {
            before = text;
            text = decodeEntities(text);
            text = text.replace(COMMENT, '').replace(TAG, '');
            if (text === before) {
                return text;
            }
        }
        throw new Error('entity nesting is too deep');
    }

    function decodeEscapes(text) {
        if (text.indexOf('\\') === -1) {
            return text;
        }
        return text.replace(/\\r\\n|\\\\|\\n|\\t/g, function (m) {
            if (m === '\\r\\n') {
                return '\r\n';
            }
            if (m === '\\\\') {
                return '\\';
            }
            return m === '\\n' ? '\n' : '\t';
        });
    }

    function normalize(text) {
        text = text.replace(/\r\n?/g, '\n').replace(/[\u{2028}\u{2029}]/gu, '\n');
        text = text.replace(/[\u{660}-\u{669}]/gu, function (d) {
            return String(d.codePointAt(0) - 0x0660);
        }).replace(/[\u{6F0}-\u{6F9}]/gu, function (d) {
            return String(d.codePointAt(0) - 0x06F0);
        });
        text = text.replace(HIDDEN, '').replace(SPECIAL_SPACES, ' ').replace(SIGN_WITH_SELECTOR, '').replace(EMOJI, '');
        text = text.replace(TYPO_CHARS, function (c) {
            return TYPOGRAPHY[c];
        });
        return trim(text);
    }

    /** MessageCleaner::clean(): '' when the text does not settle. */
    function clean(text) {
        var decode = true;
        var pass;
        var next;
        try {
            text = dropInvalid(String(text));
            for (pass = 0; pass < MAX_PASSES; pass++) {
                next = settleMarkup(text);
                if (decode) {
                    next = decodeEscapes(next);
                    decode = false;
                }
                next = normalize(next);
                if (next === text) {
                    return text;
                }
                text = next;
            }
        } catch (e) {
            return '';
        }
        return '';
    }

    function isUnicode(text) {
        return /[^\u0000-\u007F]/.test(text);
    }

    /** UTF-16 code units: a JavaScript string length already counts a character above U+FFFF as 2. */
    function units(text) {
        return text.length;
    }

    function pages(text) {
        var n = units(text);
        var unicode = isUnicode(text);
        var single = unicode ? 70 : 160;
        var multi = unicode ? 67 : 153;
        if (n === 0) {
            return 0;
        }
        return n <= single ? 1 : Math.floor((n + multi - 1) / multi);
    }

    function count(cleaned) {
        var p = pages(cleaned);
        return { units: units(cleaned), pages: p, unicode: isUnicode(cleaned), over: p > MAX_PAGES };
    }

    /** The body with every known {name} replaced by its value (the sample values need no cleaning). */
    function render(body, values) {
        return String(body).replace(/\{[a-z_]+\}/g, function (token) {
            var name = token.slice(1, -1);
            return Object.prototype.hasOwnProperty.call(values, name) ? String(values[name]) : token;
        });
    }

    function unknownPlaceholders(body, allowed) {
        var known = {};
        var out = [];
        var work;
        var found;
        allowed.forEach(function (name) {
            known[String(name).replace(/^\{+|\}+$/g, '')] = true;
        });
        work = String(body).replace(/[\u0001\u0002]/g, '').replace(/\{([^{}\r\n]*)\}/g, function (whole, inner) {
            return known[inner] === true ? '\u0001' + inner + '\u0002' : whole;
        });
        found = work.match(/\{[^{}\r\n]*\}/g) || [];
        found.forEach(function (token) {
            token = token.replace(/\u0001/g, '{').replace(/\u0002/g, '}');
            if (out.indexOf(token) === -1) {
                out.push(token);
            }
        });
        return out;
    }

    function pushOnce(list, item) {
        if (list.indexOf(item) === -1) {
            list.push(item);
        }
    }

    /** TemplateChecks::analyze(). */
    function analyze(body, receiver, config) {
        var found = {};
        var known = {};
        var list;
        var m;
        var re;
        var text;
        var cleaned;

        body = String(body);
        if (trim(body) === '') {
            return found;
        }
        config.placeholders.forEach(function (name) {
            known[name] = true;
        });

        if (receiver === 'C' && body.indexOf('{store_name}') === -1) {
            found.store_name_missing = [];
        }

        cleaned = clean(body);
        if (/<[^>]*>/.test(cleaned)) {
            found.html_span = (cleaned.match(/<[^>]*>/g) || []).filter(function (s, i, all) {
                return all.indexOf(s) === i;
            });
        }

        list = [];
        re = /(\{[a-z_]+\})(?=(\{[a-z_]+\}))/g;
        while ((m = re.exec(body)) !== null) {
            if (known[m[1].slice(1, -1)] === true && known[m[2].slice(1, -1)] === true) {
                pushOnce(list, m[1] + m[2]);
            }
        }
        if (list.length) {
            found.adjacent = list;
        }

        list = [];
        re = /([&#]+\{[a-z_]+\}|\{[a-z_]+\}[;&#]+)/g;
        while ((m = re.exec(body)) !== null) {
            pushOnce(list, m[1]);
        }
        if (list.length) {
            found.entity_fragment = list;
        }

        list = [];
        text = body.replace(/\{[^{}\r\n]*\}/g, ' ');
        if (text.indexOf('%') !== -1) {
            list.push('%');
        }
        m = /[A-Za-z][A-Za-z0-9+.-]*:\/\/\S+|\bwww\.\S+/i.exec(text);
        if (m !== null) {
            list.push(m[0].replace(/[.,;:)]+$/, ''));
        } else {
            m = new RegExp('(^|[^\\p{L}\\p{N}-])([\\p{L}\\p{N}][\\p{L}\\p{N}-]*(?:\\.[\\p{L}\\p{N}][\\p{L}\\p{N}-]*)*\\.(?:' +
                config.tlds.join('|') + '))(?![\\p{L}\\p{N}-])', 'iu').exec(text);
            if (m !== null) {
                list.push(m[2]);
            }
        }
        re = new RegExp('\\b(' + config.promo_en.join('|') + ')\\b', 'gi');
        while ((m = re.exec(text)) !== null) {
            pushOnce(list, m[1].toLowerCase());
        }
        config.promo_ar.forEach(function (word) {
            if (text.indexOf(word) !== -1) {
                pushOnce(list, word);
            }
        });
        if (list.length) {
            found.promotional = list;
        }

        return found;
    }

    return {
        MAX_PAGES: MAX_PAGES,
        clean: clean,
        isUnicode: isUnicode,
        units: units,
        pages: pages,
        count: count,
        render: render,
        analyze: analyze,
        unknownPlaceholders: unknownPlaceholders
    };
}));
