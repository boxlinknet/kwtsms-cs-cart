#!/usr/bin/env bash
# FILE:      tools/build-package.sh
# CREATED:   2026-10-21 16:07 +03
# TASK:      596
# AUTHOR:    kwtSMS
# WHAT:      Release gate and packager of the kwtSMS CS-Cart add-on: runs the static security gates, language
#            parity, php -l (host PHP and, when Docker is there, PHP 7.4), checks the source tree (no symlink, no
#            group/world-writable entry), copies the shipped paths into a stage, checks that tree, and writes
#            dist/kwtsms-<version>.zip and .tgz plus a SHA256SUMS file.
# WHY:       Task 1.17: a marketplace package must carry only the shipped paths and must never carry test
#            code, dev markers, private data, debug calls, symlinks or world-writable files. Every check is a
#            hard gate: the script exits non-zero at the end if any gate failed and then writes no archive.
# USAGE:     bash tools/build-package.sh [--src DIR] [--out DIR] [--no-lint74]
#              --src DIR     tree to package (default: the repo root). A throwaway copy may be given for a dry run.
#              --out DIR     where dist/ goes (default: <src>/dist)
#              --no-lint74   skip the PHP 7.4 syntax pass (needs Docker image php:7.4-cli)
#            Private patterns are read from <repo>/docs/private-patterns.txt (gitignored); the script fails
#            without it. Run vendor/bin/phpcs and composer audit separately (docs/devenv or the report).
#            Audit listings of the two scripted audits (placeholders of __() and the SQL ?w/?u/?e arguments) are
#            written to <out>/audit/.
# DEPENDS:   bash, git (only for the tracked-files and index-mode checks in a git tree), find, grep -P, zip, unzip,
#            tar, sha256sum, python3, php; docker for the 7.4 pass.
# 2026-10-21 16:07 +03: error_log( is allowed only as the add-on's last-resort fallback whose message starts with
#            'kwtsms (catch blocks where the add-on's own logger may have failed); any other error_log( fails.
# 2026-10-21 16:10 +03: attribution-word gate pattern written with bracket classes so this file itself stays clean, Task 1.17, task 596.
# 2026-10-21 17:33 +03: fail closed (Task 1.17 review Important 1 and Minor 1-6, final fix item 8, task 596):
#            - every gate runs through gate(): the check's exit status is read, and its last output line must be
#              "CHECKED <n>" with n > 0; a check that crashes, exits non-zero, prints nothing or checked nothing FAILS;
#            - the symlink and group/world-writable gates run on the SOURCE tree before the stage is built (a
#              filesystem that does not keep modes, such as a Windows drive under WSL, is checked through the git
#              index modes; outside git it fails closed);
#            - the stage is built without a pipeline subshell, so a failed cp sets the failure flag, and the staged
#              list must equal the source list;
#            - the epoch fallback is its own statement (outside a git tree the archives get the build time);
#            - nofilter is caught anywhere in a template tag ({$x nofilter }, a line break before "}");
#            - error_log( must be a whole statement of its own, starting its line, its message starting 'kwtsms,
#              one per statement, nothing after it on the line;
#            - #[\SensitiveParameter] on every parameter whose name holds pass, secret or key (any case) of a
#              function, method, closure or arrow function, names allowlisted below when they are not secrets, and
#              the attribute alone on its line (PHP 7.4 reads "#" as a comment);
#            - two scripted audits the brief listed: every placeholder value of a PHP __() call is escaped
#              (fn_html_escape / htmlspecialchars, an escaping helper, a number) or allowlisted by name as non-user
#              data, and no $_REQUEST/$_POST/$_GET/$params value reaches a ?w, ?u or ?e placeholder.
# Why "set -uo pipefail" and not "-e": every gate must run and report even after an earlier one failed, so one run
#            shows every failure; instead of -e, gate() and must() read every exit status and set fail=1, and no
#            archive is written unless fail is still 0.
set -uo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
src="$here"; out=""; lint74=1
while [ $# -gt 0 ]; do
  case "$1" in
    --src) src="$(cd "$2" && pwd)" || exit 2; shift 2 ;;
    --out) mkdir -p "$2" && out="$(cd "$2" && pwd)" || exit 2; shift 2 ;;
    --no-lint74) lint74=0; shift ;;
    *) echo "unknown option $1" >&2; exit 2 ;;
  esac
done
[ -n "$out" ] || out="$src/dist"
patterns="$here/docs/private-patterns.txt"

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
SHIP="app/addons/kwtsms design/backend/templates/addons/kwtsms design/backend/css/addons/kwtsms design/backend/media/images/addons/kwtsms js/addons/kwtsms var/langs/en/addons/kwtsms.po var/langs/ar/addons/kwtsms.po"
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
placeholder_audit() { py "$1" "$2" <<'PY'
import os, re, sys
# Placeholder values of PHP __() calls end up in notices (raw HTML) or in template variables. Each value must be
# escaped here, built by an escaping helper, a number, or a name allowlisted as data the add-on itself makes
# (language variables, codes it chose) or admin data rendered through an auto-escaped template.
# 2026-10-21 17:33 +03, final fix item 8, task 596.
ESCAPED_VARS = {'$escaped', '$safe'}          # filled by a loop that runs fn_html_escape / htmlspecialchars
ALLOW = {
    "$names[$code] ?? $code": 'order status name (core status descriptions) or its code; returned to an auto-escaped template',
    "$name": 'order status name (core status descriptions); returned to an auto-escaped template',
    "$count": 'pages_n of an integer; returned to an auto-escaped template',
    "$label": 'kwtsms.logs.enc.* language variable; returned to an auto-escaped template',
    "$language": 'language label from a language variable or the core language name; auto-escaped template',
    "$pages": 'integer page count',
    "$params": 'TemplatesPage::plain(): text for the preview script, which shows it with textContent (never HTML)',
}
root, listing = sys.argv[1], sys.argv[2]
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
            if arg in ALLOW:
                rows.append(at + '  ' + arg + ' allowlisted: ' + ALLOW[arg]); continue
            if arg.startswith('['):
                for item in split_top(arg[1:-1]):
                    val = item.split('=>', 1)[1].strip() if '=>' in item else item
                    if SAFE_VALUE.match(val):
                        rows.append(at + '  ' + val + ' (escaped or a number)')
                    elif val in ALLOW:
                        rows.append(at + '  ' + val + ' allowlisted: ' + ALLOW[val])
                    else:
                        bad.append(at + ': placeholder value ' + val + ' is not escaped')
                continue
            bad.append(at + ': params ' + arg + ' unknown')
open(listing, 'w', encoding='utf-8').write('\n'.join(rows + ['FINDINGS:'] + bad) + '\n')
print('\n'.join(bad)) if bad else None
print('CHECKED', n)
PY
}
gate "placeholders of __() are escaped, numbers or allowlisted non-user data (audit: $audit/placeholders.txt)" placeholder_audit "$A" "$audit/placeholders.txt"
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
sql_audit() { py "$1" "$2" <<'PY'
import os, re, sys
# Every ?w, ?u or ?e placeholder (CS-Cart builds column names from the array KEYS) gets an array the add-on builds
# from fixed keys or from a Schema whitelist, never request data. 2026-10-21 17:33 +03, final fix item 8, task 596.
ALLOW = {
    '$clean': 'DbLogStore::insert: array_intersect_key($row, Schema::columns($table)), only the Schema column names',
    '$row': 'Outbox::enqueue: an array literal with fixed keys',
    '$set': 'GdprUserData / Outbox: arrays with fixed keys built in the same method',
    '$changes': 'Outbox::updateRow: every caller in Outbox passes an array with fixed keys (status, retry_at, attempts, reason, phones, message, context, expires_at)',
}
REQ = re.compile(r'\$_(REQUEST|POST|GET|COOKIE|SERVER)\b|\$params\b')
root, listing = sys.argv[1], sys.argv[2]
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
                if name in ALLOW:
                    rows.append(at + '  ' + name + ' allowlisted: ' + ALLOW[name])
                elif name in ('$id', '$ids', '$outbox_id', '$extra', '$statuses', '$status', '$name', '$from_statuses'):
                    rows.append(at + '  ' + name + ' (an id or a fixed SQL fragment, not a ?w/?u/?e array)')
                else:
                    ok = False; bad.append(at + ': ' + name + ' not audited')
            if lits and ok:
                rows.append(at + '  plus an array literal with fixed keys')
open(listing, 'w', encoding='utf-8').write('\n'.join(rows + ['FINDINGS:'] + bad) + '\n')
print('\n'.join(bad)) if bad else None
print('CHECKED', n)
PY
}
gate "no request value reaches a ?w/?u/?e placeholder (audit: $audit/sql-placeholders.txt)" sql_audit "$A" "$audit/sql-placeholders.txt"

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
for base in ('app/addons/kwtsms', 'design/backend/templates/addons/kwtsms', 'js/addons/kwtsms'):
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
gate "only the allowed top-level paths" listed '^(app/addons/kwtsms/|design/backend/templates/addons/kwtsms/|design/backend/css/addons/kwtsms/|design/backend/media/images/addons/kwtsms/|js/addons/kwtsms/|var/langs/(en|ar)/addons/kwtsms\.po$)' -v
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

if [ "$fail" != 0 ]; then echo "== GATES FAILED ($failed failed, $passed passed): no archive written =="; exit 1; fi

# ---------------------------------------------------------------------------------------------------------------
echo "-- archives"
zipf="$out/kwtsms-$version.zip"; tgzf="$out/kwtsms-$version.tgz"
must "clear the old dist files" rm -rf "$out/kwtsms" "$zipf" "$tgzf" "$out/SHA256SUMS"
must "copy the stage to dist/kwtsms" cp -r "$pkg" "$out/kwtsms"
# 2026-10-22 12:37 +03: zip writes each entry time as zone-less local time, so a UTC runner gave the same files with
# other times and another hash; pinned to Asia/Kuwait (no DST), the zone the 1.0.0 RC zip was built in, task 596.
( cd "$pkg" && printf '%s\n' "$list" | TZ=Asia/Kuwait zip -X -q "$zipf" -@ ) || { echo "FAIL  zip (exit $?)"; fail=1; }
( cd "$pkg" && tar --sort=name --mtime="@$epoch" --owner=0 --group=0 --numeric-owner --mode='u=rwX,go=rX' -czf "$tgzf" $(printf '%s\n' "$list") ) || { echo "FAIL  tar (exit $?)"; fail=1; }
# the archives must unpack to exactly the staged tree, with no group/world-writable entry
check="$(mktemp -d "${TMPDIR:-/tmp}/kwtsms-check.XXXXXX")" || exit 2
mkdir "$check/z" "$check/t" || exit 2
must "unzip" unzip -q "$zipf" -d "$check/z"
must "untar" tar -xzf "$tgzf" -C "$check/t"
unpack_check() { diff -r "$pkg" "$1"; echo "CHECKED $(find "$1" -type f | wc -l)"; }
gate "zip unpacks to the staged tree" unpack_check "$check/z"
gate "tgz unpacks to the staged tree" unpack_check "$check/t"
zip_modes() { local res; res="$(unzip -Z "$zipf")" || return 3; printf '%s\n' "$res" | awk 'NR>2 && NF>=9 && $1 !~ /^-rw-r--r--|^Archive|^Zip|files?,/ {print}' | grep -E '^[-d]r'; echo "CHECKED $(printf '%s\n' "$list" | grep -c .)"; }
tgz_modes() { local res; res="$(tar -tvzf "$tgzf")" || return 3; printf '%s\n' "$res" | awk '$1 !~ /^-rw-r--r--$/ {print}'; echo "CHECKED $(printf '%s\n' "$res" | grep -c .)"; }
gate "zip entry modes" zip_modes
gate "tgz entry modes" tgz_modes
if [ "$fail" != 0 ]; then rm -rf "$zipf" "$tgzf" "$out/kwtsms"; echo "== ARCHIVE CHECK FAILED ($failed failed): no archive kept =="; exit 1; fi
( cd "$out" && sha256sum "kwtsms-$version.zip" "kwtsms-$version.tgz" > SHA256SUMS ) || { rm -f "$zipf" "$tgzf"; echo "FAIL  sha256sum"; exit 1; }
echo "files: $(printf '%s\n' "$list" | wc -l)"
ls -l "$zipf" "$tgzf"; cat "$out/SHA256SUMS"
echo "== OK kwtsms $version ($passed gates passed) =="
