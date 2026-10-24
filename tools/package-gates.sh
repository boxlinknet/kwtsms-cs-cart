#!/usr/bin/env bash
# FILE:      tools/package-gates.sh
# CREATED:   2026-10-22 13:32 +03
# TASK:      596
# AUTHOR:    kwtSMS
# WHAT:      The release gates and the staging step of the kwtSMS CS-Cart add-on, moved out of tools/build-package.sh
#            unchanged: the gate()/must()/scan() helpers, the static security gates, the manifest and language
#            gates, the source-tree gates, the copy of the shipped paths into a stage, and the package gates on the
#            staged tree. Not run by itself: it is sourced.
# WHY:       Task A1: tools/build-upgrade-package.sh must run exactly the same gates as tools/build-package.sh. One
#            sourced file means the two builders cannot drift apart. Behaviour of build-package.sh is unchanged (same
#            gate names, output lines and exit codes; checked by diffing its output before and after the move).
# USAGE:     . "$here/tools/package-gates.sh" after the caller has set (and parsed its options into) these variables:
#              here      repo root that holds this script
#              src       tree to package        out       output directory (audit listings go to $out/audit)
#              lint74    1 to run the PHP 7.4 syntax pass, 0 to skip it
#            It then sets, for the caller: fail, passed, failed (counters), version (from the add-on's addon.xml),
#            stage, pkg (the staged tree <stage>/kwtsms), list (the staged file list), epoch, SHIP, A, T, J, and the
#            helpers gate, must, scan, py, listed. It installs the EXIT trap that removes the stage. The caller
#            decides what to do when fail is not 0.
# DEPENDS:   bash, git (optional), find, grep -P, python3, php; docker for the 7.4 pass. Everything the original
#            build-package.sh header lists.
# 2026-10-21 16:07 +03 .. 2026-10-22 12:37 +03: the history of every gate is in the header of tools/build-package.sh
#            (the comments below are the ones that were next to the code).
# 2026-10-22 13:32 +03: extracted from build-package.sh lines 62-652 without a change to any gate, Task A1, task 596.

fail=0; passed=0; failed=0
# gate <name> <command> [args...]: the command prints its findings (one per line), then "CHECKED <n>" as its last line,
# and exits 0. PASS only when it exited 0, printed the CHECKED line with n > 0 and no finding.
gate() {
  local name="$1"; shift
  local out rc last body why="" n=""
  out="$("$@" 2>&1)"; rc=$?
  last="${out##*$'\n'}"
  if [ "$out" = "$last" ]; then body=""; else body="${out%$'\n'*}"; fi
  if [ "$rc" -ne 0 ]; then why="the check exited $rc"; body="$out"
  elif [[ "$last" =~ ^CHECKED\ ([0-9]+)$ ]]; then
    n="${BASH_REMATCH[1]}"
    if [ "$n" -eq 0 ]; then why="the check had nothing to check"
    elif [ -n "$body" ]; then why="findings"; fi
  else why="no CHECKED line (the check crashed or printed nothing)"; body="$out"; fi
  if [ -z "$why" ]; then echo "PASS  $name ($n checked)"; passed=$((passed + 1)); return 0; fi
  echo "FAIL  $name: $why"
  [ -z "$body" ] || printf '%s\n' "$body" | head -20 | sed 's/^/        /'
  fail=1; failed=$((failed + 1)); return 1
}
# must <what> <command...>: a build step that must succeed
must() {
  local what="$1"; shift
  "$@" || { echo "FAIL  $what (exit $?)"; fail=1; failed=$((failed + 1)); return 1; }
}
# scan <paths> <drop PCRE or ''> <grep args...>: grep -r over the paths, drop the lines matching the PCRE, print the
# rest, then CHECKED <number of files under the paths>. A missing path or a grep error is an error.
scan() {
  local paths="$1" drop="$2"; shift 2
  local files res rc
  files="$(find $paths -type f | wc -l)" || return 3
  res="$(grep -r "$@" $paths)"; rc=$?
  [ "$rc" -le 1 ] || return "$rc"
  if [ -n "$res" ] && [ -n "$drop" ]; then
    res="$(printf '%s\n' "$res" | grep -vP -- "$drop")"; rc=$?
    [ "$rc" -le 1 ] || return 4
  fi
  [ -z "$res" ] || printf '%s\n' "$res"
  echo "CHECKED $files"
}
# py <args...>: python3 -I reading its program from stdin (a heredoc)
py() { python3 -I - "$@"; }

A="$src/app/addons/kwtsms"
T="$src/design/backend/templates/addons/kwtsms"
J="$src/js/addons/kwtsms"
# 2026-10-23 00:55 +03: Task A10, task 596. design/themes/responsive/templates/addons/kwtsms is the storefront hook template of the opt-out.
SHIP="app/addons/kwtsms design/backend/templates/addons/kwtsms design/themes/responsive/templates/addons/kwtsms var/themes_repository/responsive/templates/addons/kwtsms design/backend/css/addons/kwtsms design/backend/media/images/addons/kwtsms js/addons/kwtsms var/langs/en/addons/kwtsms.po var/langs/ar/addons/kwtsms.po"
cd "$src" || exit 2

[ -s "$patterns" ] || { echo "FAIL  private patterns file missing: $patterns"; exit 1; }
[ -d "$A" ] || { echo "FAIL  no add-on at $A"; exit 1; }
version="$(sed -n 's|.*<version>\(.*\)</version>.*|\1|p' "$A/addon.xml" | head -1)"
case "$version" in [0-9]*.[0-9]*.[0-9]*) ;; *) echo "FAIL  bad version '$version'"; exit 1 ;; esac
echo "== kwtsms $version, src $src =="
audit="$out/audit"; mkdir -p "$audit" || exit 2

# ---------------------------------------------------------------------------------------------------------------
echo "-- source gates"
nofilter_check() { py "$1" <<'PY'
import os, re, sys
n = 0; bad = []
for d, _, fs in os.walk(sys.argv[1]):
    for f in fs:
        if not f.endswith('.tpl'): continue
        p = os.path.join(d, f); n += 1
        t = open(p, encoding='utf-8').read()
        t = re.sub(r'\{\*.*?\*\}', lambda m: '\n' * m.group(0).count('\n'), t, flags=re.S)   # comments, lines kept
        for m in re.finditer(r'\bnofilter\b', t):
            bad.append('%s:%d: nofilter' % (p, t.count('\n', 0, m.start()) + 1))
print('\n'.join(bad)) if bad else None
print('CHECKED', n)
PY
}
gate "nofilter in a Smarty tag (any spacing, any line break)" nofilter_check "$T"
gate "Tygh\\Http (use or call)" scan "$A" '' -nE 'use[[:space:]]+Tygh\\Http\b|Tygh\\Http::|[^A-Za-z\\]Http::(get|post|put|request)'
# 2026-10-24 19:42 +03: Task M1.7a, task 596. The outbound host list: the only hosts a PHP file of the add-on names in a
# URL are the kwtSMS API and Cloudflare Turnstile (siteverify), plus documentation example domains.
gate "outbound hosts: only www.kwtsms.com and challenges.cloudflare.com" scan "$A" ':\d+:https?://(www\.kwtsms\.com|challenges\.cloudflare\.com|([a-z0-9-]+\.)?example\.(com|org|test))$' -noE --include=*.php 'https?://[A-Za-z0-9.-]+'
# 2026-10-24 21:28 +03: Task M1.7b (review minor M4), task 596. The same host list for the storefront and admin templates
# and the scripts (the Turnstile api.js URL lives in a .tpl): a literal http(s):// host, or a protocol-relative //host, must
# be Cloudflare's challenge host (or an example domain). Concatenated hosts stay out of reach of any text gate.
gate "outbound hosts in templates and scripts: only challenges.cloudflare.com" scan "$src/design/themes/responsive/templates/addons/kwtsms $src/var/themes_repository/responsive/templates/addons/kwtsms $T $J" ':\d+:(https?:)?//(challenges\.cloudflare\.com|www\.kwtsms\.com|([a-z0-9-]+\.)?example\.(com|org|test))$' -noE --include=*.tpl --include=*.js '(https?:)?//[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+'
gate "CURLOPT_SSL_VERIFYPEER 0 / VERIFYHOST 0/1" scan "$A" '' -nE 'SSL_VERIFYPEER, ?(0|false)|SSL_VERIFYHOST, ?(0|1|false)'
gate "OVERRIDE / API_BASE constant" scan "$A" '' -nE "define\(['\"][A-Z_]*(OVERRIDE|API_BASE)|const[[:space:]]+[A-Z_]*(OVERRIDE|API_BASE)\b"
gate "secret-like variable compared with ==/=== to a variable" scan "$A" ':\d+:\s*(\*|//)' -nPi --include=*.php '\$\w*(hash|secret|cron_?key|token|passw\w*)\w*\s*[!=]==?\s*\$|[!=]==?\s*\$\w*(hash|secret|cron_?key|token|passw\w*)\b'
hash_equals_check() {
  local f n=0
  for f in Cron/CronKey.php Admin/RequestGate.php; do
    [ -f "$A/Tygh/Addons/Kwtsms/$f" ] || { echo "$f missing"; continue; }
    grep -q 'hash_equals(' "$A/Tygh/Addons/Kwtsms/$f" || echo "$f lacks hash_equals"
    n=$((n + 1))
  done
  echo "CHECKED $n"
}
gate "hash_equals in CronKey and RequestGate" hash_equals_check
# 2026-10-25 00:05 +03: Task M1.8a (SafatMall E1, PRD 10), task 596. The storefront code request controller ships, answers
# 405 to any verb but POST before it does anything else, and checks the session security hash itself with hash_equals().
code_controller_check() {
  local f="$A/controllers/frontend/kwtsms_code.php" post hash
  [ -f "$f" ] || { echo "controllers/frontend/kwtsms_code.php missing"; return 0; }
  # Code lines only: the header comment names both.
  post="$(grep -nv '^[[:space:]]*\(\*\|//\|/\*\)' "$f" | grep "REQUEST_METHOD" | head -1 | cut -d: -f1)"
  hash="$(grep -nv '^[[:space:]]*\(\*\|//\|/\*\)' "$f" | grep 'hash_equals(' | head -1 | cut -d: -f1)"
  [ -n "$post" ] || echo "no REQUEST_METHOD check"
  [ -n "$hash" ] || echo "no hash_equals"
  if [ -n "$post" ] && [ -n "$hash" ] && [ "$post" -gt "$hash" ]; then echo "the POST check comes after the hash check"; fi
  grep -q "fn_kwtsms_code_json(405" "$f" || echo "no 405 answer"
  echo "CHECKED 1"
}
gate "kwtsms_code controller: POST first, then hash_equals" code_controller_check
sensitive_check() { py "$1" <<'PY'
import os, re, sys
# Parameter names that hold pass, secret or key but are not secrets (setting, storage, dispatch and template keys,
# counters of cleaning passes). 2026-10-21 17:33 +03, final fix item 7, task 596.
ALLOW = {'key', 'keys', 'edit_key', 'prune_keys', 'maxPasses', 'max_passes'}
n = 0; bad = []
for d, _, fs in os.walk(sys.argv[1]):
    for f in fs:
        if not f.endswith('.php'): continue
        p = os.path.join(d, f)
        s = open(p, encoding='utf-8').read()
        for i, line in enumerate(s.split('\n'), 1):
            if ('#[\\SensitiveParameter]' in line and line.strip() != '#[\\SensitiveParameter]'
                    and not line.strip().startswith(('*', '//', '/*'))):
                bad.append('%s:%d: #[\\SensitiveParameter] must be alone on its line' % (p, i))
        for m in re.finditer(r'\b(?:function\b\s*&?\s*\w*|fn)\s*\(', s):
            i, depth = m.end(), 1
            while depth and i < len(s):
                depth += {'(': 1, ')': -1}.get(s[i], 0); i += 1
            params = s[m.end():i - 1]
            for part in re.split(r',(?=\s*(?:#\[|[\w\\?]+\s+&?\.{0,3}\$|&?\.{0,3}\$))', params):
                v = re.search(r'\$(\w+)', part)
                if not v: continue
                n += 1
                name = v.group(1)
                if re.search(r'pass|secret|key', name, re.I) and name not in ALLOW and '#[\\SensitiveParameter]' not in part:
                    bad.append('%s:%d: $%s' % (p, s.count('\n', 0, m.start()) + 1, name))
print('\n'.join(bad)) if bad else None
print('CHECKED', n)
PY
}
gate "#[\\SensitiveParameter] on every pass/secret/key parameter (functions, methods, closures, fn)" sensitive_check "$A"
error_log_check() { py "$@" <<'PY'
import os, re, sys
n = 0; bad = []
for root in sys.argv[1:]:
    for d, _, fs in os.walk(root):
        for f in fs:
            if not f.endswith('.php'): continue
            p = os.path.join(d, f)
            s = open(p, encoding='utf-8').read()
            for m in re.finditer(r'(?<![\w>:$])error_log\s*\(', s):
                n += 1
                at = '%s:%d' % (p, s.count('\n', 0, m.start()) + 1)
                line_start = s.rfind('\n', 0, m.start()) + 1
                if s[line_start:m.start()].strip() != '':
                    bad.append(at + ': error_log( does not start its line'); continue
                i, depth, q = m.end(), 1, None
                while depth and i < len(s):
                    c = s[i]
                    if q:
                        if c == '\\': i += 1
                        elif c == q: q = None
                    elif c in '\'"': q = c
                    else: depth += {'(': 1, ')': -1}.get(c, 0)
                    i += 1
                args = s[m.end():i - 1]
                rest_end = s.find('\n', i); rest_end = len(s) if rest_end < 0 else rest_end
                if s[i:rest_end].strip() != ';':
                    bad.append(at + ': something else follows the error_log statement on its line'); continue
                if not re.match(r"\s*'kwtsms[ :]", args):
                    bad.append(at + ': message does not start with \'kwtsms'); continue
                if re.search(r'error_log\s*\(', args):
                    bad.append(at + ': error_log inside error_log')
print('\n'.join(bad)) if bad else None
print('CHECKED', n)
PY
}
gate "error_log( only as a whole 'kwtsms last-resort statement, one per line" error_log_check "$A"
gate "var_dump / print_r( / dd(" scan "$A $T $J" ':\d+:\s*(\*|//|/\*|\{\*)' -nE '(^|[^A-Za-z_>:$])(var_dump|print_r|dd)\('
kwt_sms_check() {
  local res rc files
  files="$(find "$A" "$T" "$J" -type f | wc -l)" || return 3
  res="$( (grep -rn 'KWT-SMS' "$A" "$T"; grep -rnE "['\"]KWT-SMS['\"]" "$J") )"; rc=$?
  [ "$rc" -le 1 ] || return "$rc"
  res="$(printf '%s\n' "$res" | grep -vP ':\d+:\s*(\*|//|/\*|\{\*|#)' | grep -v "TEST_SENDER = 'KWT-SMS'")"
  [ -z "$res" ] || printf '%s\n' "$res"
  echo "CHECKED $files"
}
gate "KWT-SMS outside comments and the TEST_SENDER constant" kwt_sms_check
gate "KWT-SMS as a default in addon.xml" scan "$A/addon.xml" '<!--|^[0-9]*:\s*[A-Za-z(`]' -n 'KWT-SMS'
phpl_host() { # phpl_host <dir>: php -l every PHP file
  local f n=0 o
  while IFS= read -r -d '' f; do
    o="$(php -l "$f" 2>&1)" || printf '%s\n' "$o"
    n=$((n + 1))
  done < <(find "$1" -name '*.php' -print0)
  echo "CHECKED $n"
}
gate "php -l (host $(php -r 'echo PHP_VERSION;'))" phpl_host "$A"
placeholder_audit() { py "$1" "$2" "$3" <<'PY'
import os, re, sys
# Placeholder values of PHP __() calls end up in notices (raw HTML) or in template variables. Each value must be
# escaped here, built by an escaping helper, a number, or a name allowlisted as data the add-on itself makes
# (language variables, codes it chose) or admin data rendered through an auto-escaped template.
# 2026-10-21 17:33 +03, final fix item 8, task 596.
ESCAPED_VARS = {'$escaped', '$safe'}          # filled by a loop that runs fn_html_escape / htmlspecialchars
# 2026-10-23 03:34 +03: Task A12 item 2 (R53), task 596. An allowance is a line <path>:<function>:<$variable> of
# tools/audit-allowlist.txt (path relative to the add-on, as in the audit listing); the same variable name in another
# function or file is NOT allowed.
def load_allow(path):
    out = set()
    for raw in open(path, encoding='utf-8'):
        line = raw.rstrip('\n')
        if not line.strip() or line.lstrip().startswith('#'): continue
        parts = line.split(':', 2)
        if len(parts) == 3: out.add(tuple(x.strip() for x in parts))
    return out
# 2026-10-23 08:40 +0300, cleanup CA2-7 (A12), task 596: `function x(` inside a comment or a string is not a declaration. The source is
# masked (comments and string bodies become spaces, same length, so every position stays valid) before the search.
_MASKS = {}
def mask_php(s):
    out = list(s); i = 0; n = len(s)
    while i < n:
        c = s[i]; two = s[i:i + 2]
        if two == '/*':
            j = s.find('*/', i + 2); j = n if j < 0 else j + 2
        elif two == '//' or (c == '#' and s[i + 1:i + 2] != '['):
            j = i
            while j < n and s[j] != '\n': j += 1
        elif c == "'" or c == '"':
            j = i + 1
            while j < n and s[j] != c: j += 2 if s[j] == '\\' else 1
            j = min(j + 1, n)
        else:
            i += 1; continue
        for k in range(i, j):
            if out[k] != '\n': out[k] = ' '
        i = j
    return ''.join(out)
def func_at(s, pos):
    key = (len(s), hash(s))
    if key not in _MASKS: _MASKS[key] = mask_php(s)
    last = '{main}'
    for m in re.finditer(r'\bfunction\s+&?(\w+)\s*\(', _MASKS[key][:pos]): last = m.group(1)
    return last
root, listing, allowfile = sys.argv[1], sys.argv[2], sys.argv[3]
ALLOW = load_allow(allowfile)
n = 0; bad = []; rows = []
def args_of(s, start):
    i, depth, q = start, 1, None
    while depth and i < len(s):
        c = s[i]
        if q:
            if c == '\\': i += 1
            elif c == q: q = None
        elif c in '\'"': q = c
        else: depth += {'(': 1, ')': -1, '[': 1, ']': -1}.get(c, 0)
        i += 1
    return s[start:i - 1]
def split_top(t):
    out, depth, q, cur = [], 0, None, ''
    i = 0
    while i < len(t):
        c = t[i]
        if q:
            cur += c
            if c == '\\' and i + 1 < len(t): cur += t[i + 1]; i += 1
            elif c == q: q = None
        elif c in '\'"': q = c; cur += c
        elif c in '([': depth += 1; cur += c
        elif c in ')]': depth -= 1; cur += c
        elif c == ',' and depth == 0: out.append(cur); cur = ''
        else: cur += c
        i += 1
    if cur.strip(): out.append(cur)
    return [x.strip() for x in out]
SAFE_VALUE = re.compile(r"^(?:\(int\)\s*.+|\(float\)\s*.+|\d+|number_format\(.+\)|self::number\(.+\)|\$this->number\(.+\)|"
                        r"(?:fn_html_escape|htmlspecialchars)\(.+\)|\(string\)\s*__\(.+\)|__\(.+\))$", re.S)
for d, _, fs in os.walk(root):
    for f in fs:
        if not f.endswith('.php'): continue
        p = os.path.join(d, f)
        s = open(p, encoding='utf-8').read()
        for m in re.finditer(r'(?<![\w>:$])__\s*\(', s):
            line = s.count('\n', 0, m.start()) + 1
            ls = s.rfind('\n', 0, m.start()) + 1
            if s[ls:m.start()].lstrip().startswith(('*', '//')): continue
            a = split_top(args_of(s, m.end()))
            n += 1
            at = '%s:%d' % (os.path.relpath(p, root), line)
            if len(a) < 2:
                rows.append(at + '  no params'); continue
            arg = a[1]
            if arg in ('[]', 'array()'):
                rows.append(at + '  []'); continue
            if arg in ESCAPED_VARS:
                body = s[max(0, m.start() - 1500):m.start()]
                if re.search(re.escape(arg) + r"\[[^\]]+\]\s*=\s*(?:function_exists\('fn_html_escape'\)\s*\?\s*)?(?:fn_html_escape|htmlspecialchars)\(", body):
                    rows.append(at + '  ' + arg + ' (escaped by the loop above)'); continue
                bad.append(at + ': ' + arg + ' is not filled by an escaping loop'); continue
            fn = func_at(s, m.start()); rel = os.path.relpath(p, root)
            if (rel, fn, arg) in ALLOW:
                rows.append(at + '  ' + arg + ' allowlisted for ' + fn); continue
            if arg.startswith('['):
                for item in split_top(arg[1:-1]):
                    val = item.split('=>', 1)[1].strip() if '=>' in item else item
                    if SAFE_VALUE.match(val):
                        rows.append(at + '  ' + val + ' (escaped or a number)')
                    elif (rel, fn, val) in ALLOW:
                        rows.append(at + '  ' + val + ' allowlisted for ' + fn)
                    else:
                        bad.append(at + ': placeholder value ' + val + ' is not escaped (allowlist line ' + rel + ':' + fn + ':' + val + ')')
                continue
            bad.append(at + ': params ' + arg + ' unknown (allowlist line ' + rel + ':' + fn + ':' + arg + ')')
open(listing, 'w', encoding='utf-8').write('\n'.join(rows + ['FINDINGS:'] + bad) + '\n')
print('\n'.join(bad)) if bad else None
print('CHECKED', n)
PY
}
gate "placeholders of __() are escaped, numbers or allowlisted non-user data (audit: $audit/placeholders.txt)" placeholder_audit "$A" "$audit/placeholders.txt" "$here/tools/audit-allowlist.txt"
notification_check() { py "$1" <<'PY'
import os, re, sys
# fn_set_notification() renders its message as HTML: the message (third argument) is a language variable without
# parameters, a __() call whose parameters go through fn_html_escape, a notify() helper that escapes unless told the
# text is already escaped, or a notice built by LogsPage::clearNotices() / resendNotice() (text() escapes every
# parameter). 2026-10-21 17:33 +03, final fix item 8, task 596.
ALLOW = {'$kwtsms_notice[1]': 'LogsPage::clearNotices() / resendNotice(): built by text(), which escapes every parameter'}
def third(call):
    depth, q, parts, cur = 0, None, [], ''
    for c in call:
        if q:
            cur += c
            if c == q: q = None
            continue
        if c in '\'"': q = c
        elif c in '([': depth += 1
        elif c in ')]': depth -= 1
        elif c == ',' and depth == 0:
            parts.append(cur); cur = ''; continue
        cur += c
    parts.append(cur)
    return parts[2].strip() if len(parts) > 2 else ''
n = 0; bad = []
for d, _, fs in os.walk(sys.argv[1]):
    for f in fs:
        if not f.endswith('.php'): continue
        p = os.path.join(d, f)
        s = open(p, encoding='utf-8').read()
        for m in re.finditer(r'(?<![\w>:$])fn_set_notification\s*\(', s):
            ls = s.rfind('\n', 0, m.start()) + 1
            if s[ls:m.start()].lstrip().startswith(('*', '//')): continue
            i, depth, q = m.end(), 1, None
            while depth and i < len(s):
                c = s[i]
                if q:
                    if c == '\\': i += 1
                    elif c == q: q = None
                elif c in '\'"': q = c
                else: depth += {'(': 1, ')': -1}.get(c, 0)
                i += 1
            call = s[m.end():i - 1]
            n += 1
            at = '%s:%d' % (p, s.count('\n', 0, m.start()) + 1)
            msg = third(call)
            ok = (msg == '$escape ? fn_html_escape($message) : $message'
                  or re.fullmatch(r"(?:\(string\)\s*)?__\('[\w.]+'\)", msg)
                  or re.fullmatch(r"fn_html_escape\(.*\)", msg, re.S)
                  or (re.match(r"__\('[\w.]+',\s*\[", msg) and all('fn_html_escape(' in v for v in re.findall(r"=>\s*([^,\]]+(?:\([^)]*\))?)", msg)))
                  or msg in ALLOW)
            if not ok:
                bad.append(at + ': ' + ' '.join(msg.split())[:160])
print('\n'.join(bad)) if bad else None
print('CHECKED', n)
PY
}
gate "fn_set_notification messages are escaped or plain language variables" notification_check "$A"
sql_audit() { py "$1" "$2" "$3" <<'PY'
import os, re, sys
# Every ?w, ?u or ?e placeholder (CS-Cart builds column names from the array KEYS) gets an array the add-on builds
# from fixed keys or from a Schema whitelist, never request data. 2026-10-21 17:33 +03, final fix item 8, task 596.
REQ = re.compile(r'\$_(REQUEST|POST|GET|COOKIE|SERVER)\b|\$params\b')
# 2026-10-23 03:34 +03: Task A12 item 2 (R53), task 596. An allowance is a line <path>:<function>:<$variable> of
# tools/audit-allowlist.txt (path relative to the add-on, as in the audit listing); the same variable name in another
# function or file is NOT allowed.
def load_allow(path):
    out = set()
    for raw in open(path, encoding='utf-8'):
        line = raw.rstrip('\n')
        if not line.strip() or line.lstrip().startswith('#'): continue
        parts = line.split(':', 2)
        if len(parts) == 3: out.add(tuple(x.strip() for x in parts))
    return out
# 2026-10-23 08:40 +0300, cleanup CA2-7 (A12), task 596: `function x(` inside a comment or a string is not a declaration. The source is
# masked (comments and string bodies become spaces, same length, so every position stays valid) before the search.
_MASKS = {}
def mask_php(s):
    out = list(s); i = 0; n = len(s)
    while i < n:
        c = s[i]; two = s[i:i + 2]
        if two == '/*':
            j = s.find('*/', i + 2); j = n if j < 0 else j + 2
        elif two == '//' or (c == '#' and s[i + 1:i + 2] != '['):
            j = i
            while j < n and s[j] != '\n': j += 1
        elif c == "'" or c == '"':
            j = i + 1
            while j < n and s[j] != c: j += 2 if s[j] == '\\' else 1
            j = min(j + 1, n)
        else:
            i += 1; continue
        for k in range(i, j):
            if out[k] != '\n': out[k] = ' '
        i = j
    return ''.join(out)
def func_at(s, pos):
    key = (len(s), hash(s))
    if key not in _MASKS: _MASKS[key] = mask_php(s)
    last = '{main}'
    for m in re.finditer(r'\bfunction\s+&?(\w+)\s*\(', _MASKS[key][:pos]): last = m.group(1)
    return last
root, listing, allowfile = sys.argv[1], sys.argv[2], sys.argv[3]
ALLOW = load_allow(allowfile)
n = 0; bad = []; rows = []
for d, _, fs in os.walk(root):
    for f in fs:
        if not f.endswith('.php'): continue
        p = os.path.join(d, f)
        s = open(p, encoding='utf-8').read()
        for m in re.finditer(r"db_\w+\s*\(", s):
            i, depth, q = m.end(), 1, None
            while depth and i < len(s):
                c = s[i]
                if q:
                    if c == '\\': i += 1
                    elif c == q: q = None
                elif c in '\'"': q = c
                else: depth += {'(': 1, ')': -1}.get(c, 0)
                i += 1
            call = s[m.start():i]
            if not re.search(r"\?[wue]\b", call): continue
            n += 1
            at = '%s:%d' % (os.path.relpath(p, root), s.count('\n', 0, m.start()) + 1)
            if REQ.search(call):
                bad.append(at + ': request data in the call'); continue
            args = [a.strip() for a in re.split(r',(?![^\[]*\])', call[call.find('(') + 1:-1])]
            vals = [a for a in args[1:] if a.startswith('$')]
            lits = [a for a in args[1:] if a.startswith('[')]
            if lits and not vals:
                rows.append(at + '  array literal with fixed keys'); continue
            ok = True
            for v in vals:
                name = re.match(r'\$\w+', v).group(0)
                fn = func_at(s, m.start()); rel = os.path.relpath(p, root)
                if (rel, fn, name) in ALLOW:
                    rows.append(at + '  ' + name + ' allowlisted for ' + fn)
                else:
                    ok = False; bad.append(at + ': ' + name + ' not audited (allowlist line ' + rel + ':' + fn + ':' + name + ')')
            if lits and ok:
                rows.append(at + '  plus an array literal with fixed keys')
open(listing, 'w', encoding='utf-8').write('\n'.join(rows + ['FINDINGS:'] + bad) + '\n')
print('\n'.join(bad)) if bad else None
print('CHECKED', n)
PY
}
gate "no request value reaches a ?w/?u/?e placeholder (audit: $audit/sql-placeholders.txt)" sql_audit "$A" "$audit/sql-placeholders.txt" "$here/tools/audit-allowlist.txt"

echo "-- manifest gates"
min_check() {
  local min
  min="$(sed -n 's|.*<min>\(.*\)</min>.*|\1|p' "$A/addon.xml" | head -1)" || return 3
  [ "$min" = "4.20.1" ] || echo "min is '$min'"
  echo "CHECKED 1"
}
gate "compatibility min is 4.20.1" min_check
install_sql_check() { py "$A/addon.xml" <<'PY'
import re, sys
s = open(sys.argv[1], encoding='utf-8').read()
s = re.sub(r'<!--.*?-->', '', s, flags=re.S)
m = re.search(r'CREATE TABLE[^;]*?kwtsms_attempts\b(.*?)ENGINE', s, re.S | re.I)
if not m:
    print('no CREATE TABLE kwtsms_attempts in the install queries')
else:
    if '`is_test`' not in m.group(1): print('kwtsms_attempts has no is_test column')
    if not re.search(r'KEY\s+`idx_created_outcome_test`\s*\(`created_at`,\s*`outcome`,\s*`is_test`\)', m.group(1)): print('no idx_created_outcome_test (created_at, outcome, is_test)')
print('CHECKED 1')
PY
}
gate "install SQL creates kwtsms_attempts.is_test and idx_created_outcome_test" install_sql_check
language_check() { py "$src" <<'PY'
import os, re, sys
root = sys.argv[1]
def ctx(p):
    return set(re.findall(r'^msgctxt "([^"]+)"', open(p, encoding='utf-8').read(), re.M))
en = ctx(os.path.join(root, 'var/langs/en/addons/kwtsms.po'))
ar = ctx(os.path.join(root, 'var/langs/ar/addons/kwtsms.po'))
for k in sorted(en - ar): print('only in en:', k)
for k in sorted(ar - en): print('only in ar:', k)
keys = {c.split('::', 1)[1] for c in en if c.startswith('Languages::')}
used, prefixes = {}, {}
nonlang = {'kwtsms.dashboard', 'kwtsms.admin_alerts', 'kwtsms.order_placed.multi'}  # dispatch targets and an event id, not language variables
for d, _, fs in os.walk(os.path.join(root, 'app/addons/kwtsms')):
    for f in fs:
        if f.endswith('.php'):
            t = open(os.path.join(d, f), encoding='utf-8').read()
            nonlang |= set(re.findall(r"\['(kwtsms\.[\w.]+)'\]", t))
ev = os.path.join(root, 'app/addons/kwtsms/schemas/kwtsms/events.php')
nonlang |= set(re.findall(r"^\s*'(kwtsms\.[\w.]+)'\s*=>", open(ev, encoding='utf-8').read(), re.M))
nonlang |= set(re.findall(r"\$schema\['(kwtsms\.[\w.]+)'\]", open(os.path.join(root, 'app/addons/kwtsms/schemas/notifications/events.post.php'), encoding='utf-8').read()))
n = 0
for base in ('app/addons/kwtsms', 'design/backend/templates/addons/kwtsms', 'design/themes/responsive/templates/addons/kwtsms', 'var/themes_repository/responsive/templates/addons/kwtsms', 'js/addons/kwtsms'):
    for d, _, fs in os.walk(os.path.join(root, base)):
        for f in fs:
            if not f.endswith(('.php', '.tpl', '.js', '.xml')): continue
            p = os.path.join(d, f); n += 1
            txt = open(p, encoding='utf-8').read()
            txt = re.sub(r'/\*.*?\*/', '', txt, flags=re.S)
            txt = re.sub(r'\{\*.*?\*\}', '', txt, flags=re.S)
            txt = re.sub(r'<!--.*?-->', '', txt, flags=re.S)
            txt = re.sub(r'(?m)^\s*//.*$', '', txt)
            for m in re.finditer(r'''['"](kwtsms\.[A-Za-z0-9_.]*)(['"])''', txt):
                k = m.group(1)
                if k in nonlang or k.startswith('kwtsms.order_placed.'):
                    continue
                if k.endswith('.') or re.search(r'''\s*\.\s*\$''', txt[m.end()-1:m.end()+6]):
                    prefixes[k] = p
                else:
                    used[k] = p
for k, p in sorted(used.items()):
    if k not in keys and not any(x.startswith(k + '.') for x in keys):
        print('used but missing:', k, os.path.relpath(p, root))
for k, p in sorted(prefixes.items()):
    if not any(x.startswith(k) for x in keys):
        print('prefix without any key:', k, os.path.relpath(p, root))
print('CHECKED', n + len(en))
PY
}
gate "language parity (msgctxt sets equal, every used kwtsms.* key exists)" language_check

# ---------------------------------------------------------------------------------------------------------------
echo "-- source tree gates (before the stage)"
src_files=""
paths_check() {
  local p n=0
  for p in $SHIP; do [ -e "$p" ] || echo "shipped path missing: $p"; n=$((n + 1)); done
  echo "CHECKED $n"
}
gate "every shipped path exists" paths_check
symlink_check() {
  local res n
  n="$(find $SHIP | wc -l)" || return 3
  res="$(find $SHIP -type l)" || return 3
  [ -z "$res" ] || printf 'symlink: %s\n' $res
  if git -C "$src" rev-parse --git-dir >/dev/null 2>&1; then
    git -C "$src" ls-files -s -- $SHIP | awk '$1 == "120000" {print "tracked symlink: " $4}' || return 3
  fi
  echo "CHECKED $n"
}
gate "no symlink in the source shipped paths" symlink_check
modes_check() {
  local probe keeps=0 res n
  n="$(find $SHIP | wc -l)" || return 3
  probe="$(mktemp "$src/.kwtsms-modeprobe.XXXXXX")" || return 3
  chmod 644 "$probe" && [ "$(stat -c %a "$probe")" = "644" ] && keeps=1
  rm -f "$probe"
  if [ "$keeps" = 1 ]; then
    res="$(find $SHIP -perm /022 \( -type f -o -type d \))" || return 3
    [ -z "$res" ] || printf 'group/world-writable: %s\n' $res
  elif git -C "$src" rev-parse --git-dir >/dev/null 2>&1; then
    git -C "$src" ls-files -s -- $SHIP | awk '$1 != "100644" {print "index mode " $1 ": " $4}' || return 3
  else
    echo "this filesystem does not keep modes and $src is not a git tree: modes cannot be checked"
  fi
  echo "CHECKED $n"
}
gate "no group/world-writable entry in the source shipped paths (the git index modes, 100644 only, where the filesystem keeps no modes)" modes_check
# 2026-10-23 03:37 +03: Task A12 item 3 (R53), task 596. The second modes gate: the shipped files are staged by
# tools/stage-modes.sh in a throwaway /tmp/kwtsms-stage.XXXX inside the php container (native ext4 modes; the host's
# /tmp when that container is not running) with the modes measured here (the real file modes where this filesystem
# keeps them, else the git index modes; an untracked file on a no-mode filesystem cannot be measured and fails), and
# every group/world-writable entry of that stage fails the gate. The git-index gate above stays.
# 2026-10-23 04:14 +03: Fix round 1 item 1, task 596. Decided in the main shell so the PASS line says where the stage was
# built (php container or host /tmp) and where the modes came from (the filesystem, or the git index on a drive that
# keeps no modes: then the gate cannot see a group/world-writable file, and the line says so).
modes_keeps=0; modes_host="host /tmp"
modes_probe="$(mktemp "$src/.kwtsms-modeprobe.XXXXXX")" && { chmod 644 "$modes_probe" && [ "$(stat -c %a "$modes_probe")" = "644" ] && modes_keeps=1; rm -f "$modes_probe"; }
if docker compose -f "$here/docker/compose.yml" ps --status running --services 2>/dev/null | grep -qx php; then modes_host="php container"; fi
if [ "$modes_keeps" = 1 ]; then modes_source="modes read from the filesystem"; else modes_source="NO FILESYSTEM MODES HERE: modes applied from the git index, a PASS is not a filesystem check"; fi
modes_stage_check() {
  local keeps="$modes_keeps" tmp files rc out
  tmp="$(mktemp -d "${TMPDIR:-/tmp}/kwtsms-modes.XXXXXX")" || return 3
  files="$tmp/files"; : > "$tmp/.modes"
  find $SHIP -type f ! -name .gitkeep | sort > "$files" || { rm -rf "$tmp"; return 3; }
  if [ "$keeps" = 1 ]; then
    while IFS= read -r f; do printf '%s %s\n' "$(stat -c %a "$f")" "$f"; done < "$files" >> "$tmp/.modes"
  elif git -C "$src" rev-parse --git-dir >/dev/null 2>&1; then
    git -C "$src" ls-files -s -- $SHIP | awk '{m = ($1 == "100755") ? "755" : (($1 == "100644") ? "644" : $1); print m, $4}' | sort -k2 > "$tmp/index.modes"
    while IFS= read -r f; do
      awk -v f="$f" '$2 == f {found = 1} END {exit !found}' "$tmp/index.modes" || printf 'untracked file on a filesystem without modes, cannot be measured: %s\n' "$f"
    done < "$files"
    cat "$tmp/index.modes" >> "$tmp/.modes"
  else
    echo "this filesystem does not keep modes and $src is not a git tree: modes cannot be measured"
  fi
  if ! { tar -cf "$tmp/in.tar" -C "$src" -T "$files" && tar -rf "$tmp/in.tar" -C "$tmp" .modes; }; then rm -rf "$tmp"; return 3; fi
  if [ "$modes_host" = "php container" ]; then
    out="$(docker compose -f "$here/docker/compose.yml" exec -T php sh -c "$(cat "$here/tools/stage-modes.sh")" < "$tmp/in.tar")"; rc=$?
  else
    out="$(sh -c "$(cat "$here/tools/stage-modes.sh")" < "$tmp/in.tar")"; rc=$?
  fi
  rm -rf "$tmp"
  [ "$rc" -eq 0 ] || { printf '%s\n' "$out"; return "$rc"; }
  printf '%s\n' "$out"
}
gate "no group/world-writable entry in the stage (stage on: $modes_host; $modes_source)" modes_stage_check

# ---------------------------------------------------------------------------------------------------------------
echo "-- stage"
stage="$(mktemp -d "${TMPDIR:-/tmp}/kwtsms-stage.XXXXXX")" || exit 2
check=""
trap 'rm -rf "$stage" "${check:-}"' EXIT
pkg="$stage/kwtsms"; mkdir -p "$pkg" || exit 2
src_list="$stage/src.list"; : > "$src_list"
for p in $SHIP; do
  [ -e "$p" ] || continue
  while IFS= read -r -d '' f; do
    case "$f" in */.gitkeep) continue ;; esac
    printf '%s\n' "$f" >> "$src_list"
    cp -P --parents "$f" "$pkg/" || { echo "FAIL  cannot copy $f"; fail=1; failed=$((failed + 1)); }
  done < <(find "$p" \( -type f -o -type l \) -print0 | sort -z)
done
staged_check() {
  local want have
  want="$(sort "$src_list")" || return 3
  have="$(cd "$pkg" && find . \( -type f -o -type l \) | sed 's|^\./||' | sort)" || return 3
  diff <(printf '%s\n' "$want") <(printf '%s\n' "$have")
  echo "CHECKED $(printf '%s\n' "$want" | grep -c .)"
}
gate "the stage holds exactly the source files of the shipped paths" staged_check
if git -C "$src" rev-parse --git-dir >/dev/null 2>&1 && [ "$src" = "$here" ]; then
  tracked_check() {
    local want have
    want="$(git ls-files -- $SHIP | sort)" || return 3
    have="$(cd "$pkg" && find . -type f | sed 's|^\./||' | sort)" || return 3
    diff <(printf '%s\n' "$want") <(printf '%s\n' "$have")
    echo "CHECKED $(printf '%s\n' "$want" | grep -c .)"
  }
  gate "package files == git-tracked files of the shipped paths" tracked_check
  clean_check() { git status --porcelain -- $SHIP || return 3; echo "CHECKED $(git ls-files -- $SHIP | wc -l)"; }
  gate "shipped paths have no uncommitted change" clean_check
fi
# 2026-10-21 16:12 +03: last commit touching a shipped path, so a docs-only commit leaves the archive hash unchanged,
# Task 1.17, task 596. 2026-10-21 17:33 +03: the fallback is its own statement (it was inside the comment and never
# ran), Task 1.17 review Minor 1, task 596.
epoch="$(git -C "$src" log -1 --format=%ct -- $SHIP 2>/dev/null || true)"
[ -n "$epoch" ] || epoch="$(date +%s)"
must "chmod 755 on the staged directories" find "$pkg" -type d -exec chmod 755 {} +
must "chmod 644 on the staged files" find "$pkg" -type f -exec chmod 644 {} +
must "staged timestamps" find "$pkg" -exec touch -h -d "@$epoch" {} +

# ---------------------------------------------------------------------------------------------------------------
echo "-- package gates (on the staged tree)"
list="$(cd "$pkg" && find . -type f | sed 's|^\./||' | sort)"
listed() { # listed <PCRE to report> [-v]: prints the staged paths that match (or, with -v, do not match), then CHECKED
  local res rc
  if [ "${2:-}" = "-v" ]; then res="$(printf '%s\n' "$list" | grep -vP -- "$1")"; else res="$(printf '%s\n' "$list" | grep -P -- "$1")"; fi
  rc=$?; [ "$rc" -le 1 ] || return "$rc"
  [ -z "$res" ] || printf '%s\n' "$res"
  echo "CHECKED $(printf '%s\n' "$list" | grep -c .)"
}
stage_links() { local res; res="$(find "$pkg" -type l)" || return 3; [ -z "$res" ] || printf '%s\n' "$res"; echo "CHECKED $(find "$pkg" | wc -l)"; }
gate "no symlinks in the stage" stage_links
# 2026-10-23 01:36 +03: Task A10 fix round 1, task 596. Core installs storefront templates from var/themes_repository into design/themes, so the
# package carries both trees and they must be byte-identical.
theme_trees_identical() {
  diff -r "$pkg/design/themes/responsive/templates/addons/kwtsms" "$pkg/var/themes_repository/responsive/templates/addons/kwtsms" || return 1
  echo "CHECKED $(find "$pkg/var/themes_repository" -type f | wc -l)"
}
gate "design/themes and var/themes_repository templates are byte-identical" theme_trees_identical
gate "only the allowed top-level paths" listed '^(app/addons/kwtsms/|design/backend/templates/addons/kwtsms/|design/themes/responsive/templates/addons/kwtsms/|var/themes_repository/responsive/templates/addons/kwtsms/|design/backend/css/addons/kwtsms/|design/backend/media/images/addons/kwtsms/|js/addons/kwtsms/|var/langs/(en|ar)/addons/kwtsms\.po$)' -v
gate "no test, doc, dev or vendor paths or files" listed '(^|/)(tests|docs|docker|\.git|\.cl[a]ude|vendor|node_modules|devguard)/|\.env|composer\.(json|lock)|phpunit|\.log$|\.sent-msgids|kwtsms_devguard|\.(orig|rej|bak|swp|zip|tgz)$|~$'
private_check() {
  local res rc
  res="$(grep -rnIf "$patterns" "$pkg")"; rc=$?
  [ "$rc" -le 1 ] || return "$rc"
  [ -z "$res" ] || printf '%s\n' "$res" | sed 's/^\([^:]*:[0-9]*\):.*/\1: matches a private pattern/'
  echo "CHECKED $(find "$pkg" -type f | wc -l)"
}
gate "no private patterns (docs/private-patterns.txt)" private_check
gate "no debug calls (var_dump, print_r(, dd()" scan "$pkg" ':\d+:\s*(\*|//|/\*|\{\*)' -nE '(^|[^A-Za-z_>:$])(var_dump|print_r|dd)\('
gate "error_log( only as a whole 'kwtsms last-resort statement (package)" error_log_check "$pkg"
gate "no KWTSMS_TESTSUITE, devguard or dev markers" scan "$pkg" '' -nEI 'KWTSMS_TESTSUITE|devguard|kwtsms_dev_anti_csrf_off|KWTSMS_NO_SEND|dev_anti_csrf|\.sent-msgids|/tests/|run-live'
gate "no banned characters (en dash, em dash, smart quotes, ellipsis)" scan "$pkg" '' -nIP '[\x{2013}\x{2014}\x{2018}\x{2019}\x{201C}\x{201D}\x{2026}]'
ascii_check() {
  local res rc res2 rc2
  res="$(grep -rnIP '[^\x00-\x7F]' "$pkg" --include=*.php --include=*.tpl --include=*.js --include=*.xml --include=*.less)"; rc=$?
  res2="$(grep -nP '[^\x00-\x7F]' "$pkg/var/langs/en/addons/kwtsms.po")"; rc2=$?
  [ "$rc" -le 1 ] && [ "$rc2" -le 1 ] || return 2
  [ -z "$res$res2" ] || printf '%s\n' "$res" "$res2" | grep .
  echo "CHECKED $(find "$pkg" -type f | wc -l)"
}
gate "English .po and all code/templates are ASCII only" ascii_check
gate "no attribution words (assistant or vendor names)" scan "$pkg" '' -niIE 'cl[a]ude|anthrop[i]c'
phone_check() {
  local res rc
  res="$(grep -rhoIE '(^|[^0-9])965[0-9]{8}([^0-9]|$)' "$pkg")"; rc=$?
  [ "$rc" -le 1 ] || return "$rc"
  printf '%s\n' "$res" | grep -oE '965[0-9]{8}' | sort -u | grep -vxE '96598765432|96550000001|96550123401|96522334455'
  echo "CHECKED $(find "$pkg" -type f | wc -l)"
}
gate "no phone number other than the documented fictional ones" phone_check
gate "no Tygh\\Http, VERIFYPEER 0, OVERRIDE or API_BASE in the package" scan "$pkg/app" 'HttpTransport.php:[0-9]*: \* WHY' -nE 'use[[:space:]]+Tygh\\Http\b|Tygh\\Http::|SSL_VERIFYPEER, ?(0|false)|OVERRIDE|API_BASE'
gate "no nofilter in package templates" nofilter_check "$pkg/design"
gate "php -l on the package (host)" phpl_host "$pkg"
if [ "$lint74" = 1 ]; then
  phpl_74() {
    command -v docker >/dev/null 2>&1 || { echo "docker not found for the PHP 7.4 pass (use --no-lint74)"; echo "CHECKED 1"; return 0; }
    # 2026-10-22 12:26 +03: --quiet, because on a runner without the cached image the pull progress was read as
    # lint findings and failed the gate (first GitHub CI run 39754991503); a failed pull still fails it, task 596.
    docker run --rm --quiet -v "$pkg:/pkg:ro" php:7.4-cli sh -c 'n=0; for f in $(find /pkg -name "*.php"); do php -l "$f" >/dev/null 2>&1 || php -l "$f"; n=$((n + 1)); done; echo "CHECKED $n"'
  }
  gate "php -l on the package (PHP 7.4)" phpl_74
fi

