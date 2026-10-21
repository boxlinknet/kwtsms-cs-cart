#!/usr/bin/env bash
# FILE:      tools/build-package.sh
# CREATED:   2026-10-21 16:07 +03
# TASK:      596
# AUTHOR:    kwtSMS
# WHAT:      Release gate and packager of the kwtSMS CS-Cart add-on: runs the static security gates, language
#            parity, php -l (host PHP and, when Docker is there, PHP 7.4), copies the shipped paths with
#            dereferenced symlinks into dist/kwtsms/, checks that tree, and writes dist/kwtsms-<version>.zip
#            and .tgz plus a SHA256SUMS file.
# WHY:       Task 1.17: a marketplace package must carry only the shipped paths and must never carry test
#            code, dev markers, private data, debug calls, symlinks or world-writable files. Every check is a
#            hard gate: the script exits non-zero at the end if any gate failed and then writes no archive.
# USAGE:     bash tools/build-package.sh [--src DIR] [--out DIR] [--no-lint74]
#              --src DIR     tree to package (default: the repo root). A throwaway copy may be given for a dry run.
#              --out DIR     where dist/ goes (default: <src>/dist)
#              --no-lint74   skip the PHP 7.4 syntax pass (needs Docker image php:7.4-cli)
#            Private patterns are read from <repo>/docs/private-patterns.txt (gitignored); the script fails
#            without it. Run vendor/bin/phpcs and composer audit separately (docs/devenv or the report).
# DEPENDS:   bash, git (only for the tracked-files check in a git tree), find, grep -P, zip, unzip, tar,
#            sha256sum, python3, php; docker for the 7.4 pass.
# 2026-10-21 16:07 +03: error_log( is allowed only as the add-on's last-resort fallback whose message starts with
#            'kwtsms (catch blocks where the add-on's own logger may have failed); any other error_log( fails.
# 2026-10-21 16:10 +03: attribution-word gate pattern written with bracket classes so this file itself stays clean, Task 1.17, task 596.
set -uo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
src="$here"; out=""; lint74=1
while [ $# -gt 0 ]; do
  case "$1" in
    --src) src="$(cd "$2" && pwd)"; shift 2 ;;
    --out) mkdir -p "$2"; out="$(cd "$2" && pwd)"; shift 2 ;;
    --no-lint74) lint74=0; shift ;;
    *) echo "unknown option $1" >&2; exit 2 ;;
  esac
done
[ -n "$out" ] || out="$src/dist"
patterns="$here/docs/private-patterns.txt"

fail=0
gate() { # gate <name> <cmd output expected empty...>: prints PASS/FAIL
  local name="$1" got="$2"
  if [ -z "$got" ]; then echo "PASS  $name"; else echo "FAIL  $name"; printf '%s\n' "$got" | head -20 | sed 's/^/        /'; fail=1; fi
}

A="$src/app/addons/kwtsms"
T="$src/design/backend/templates/addons/kwtsms"
SHIP="app/addons/kwtsms design/backend/templates/addons/kwtsms design/backend/css/addons/kwtsms design/backend/media/images/addons/kwtsms js/addons/kwtsms var/langs/en/addons/kwtsms.po var/langs/ar/addons/kwtsms.po"
cd "$src" || exit 2

[ -s "$patterns" ] || { echo "FAIL  private patterns file missing: $patterns"; exit 1; }
[ -d "$A" ] || { echo "FAIL  no add-on at $A"; exit 1; }
version="$(sed -n 's|.*<version>\(.*\)</version>.*|\1|p' "$A/addon.xml" | head -1)"
case "$version" in [0-9]*.[0-9]*.[0-9]*) ;; *) echo "FAIL  bad version '$version'"; exit 1 ;; esac
echo "== kwtsms $version, src $src =="

echo "-- source gates"
gate "nofilter in Smarty markup (anchored)" "$(grep -rnE '\|[[:space:]]*nofilter| nofilter\}' "$T" 2>/dev/null)"
gate "Tygh\\Http (use or call)" "$(grep -rnE 'use[[:space:]]+Tygh\\Http\b|Tygh\\Http::|[^A-Za-z\\]Http::(get|post|put|request)' "$A" 2>/dev/null)"
gate "CURLOPT_SSL_VERIFYPEER 0 / VERIFYHOST 0/1" "$(grep -rnE 'SSL_VERIFYPEER, ?(0|false)|SSL_VERIFYHOST, ?(0|1|false)' "$A" 2>/dev/null)"
gate "OVERRIDE / API_BASE constant" "$(grep -rnE "define\(['\"][A-Z_]*(OVERRIDE|API_BASE)|const[[:space:]]+[A-Z_]*(OVERRIDE|API_BASE)\b" "$A" 2>/dev/null)"
gate "secret-like variable compared with ==/=== to a variable" "$(grep -rnPi '\$\w*(hash|secret|cron_?key|token|passw\w*)\w*\s*[!=]==?\s*\$|[!=]==?\s*\$\w*(hash|secret|cron_?key|token|passw\w*)\b' "$A" --include=*.php 2>/dev/null | grep -vP ':\d+:\s*(\*|//)')"
need=""; for f in Cron/CronKey.php Admin/RequestGate.php; do grep -q 'hash_equals(' "$A/Tygh/Addons/Kwtsms/$f" || need="$need $f lacks hash_equals"; done
gate "hash_equals in CronKey and RequestGate" "$need"
gate "password parameters carry #[\\SensitiveParameter]" "$(python3 -I - "$A" <<'PY'
import re, sys, os
bad = []
for d, _, fs in os.walk(sys.argv[1]):
    for f in fs:
        if not f.endswith('.php'):
            continue
        p = os.path.join(d, f)
        s = open(p, encoding='utf-8').read()
        for m in re.finditer(r'function\s+\w+\s*\((.*?)\)\s*(?::\s*[\w\\?|]+\s*)?[{;]', s, re.S):
            for part in re.split(r',(?=\s*(?:#\[|[\w\\?]+\s+\$|\$))', m.group(1)):
                v = re.search(r'\$(password|pass|pw|passwd)\b', part)
                if v and '#[\\SensitiveParameter]' not in part:
                    bad.append('%s: $%s' % (p, v.group(1)))
print('\n'.join(bad))
PY
)"
gate "error_log( other than the kwtsms last-resort fallback" "$(grep -rnE '(^|[^A-Za-z_>:])error_log\(' "$A" 2>/dev/null | grep -vE "error_log\('kwtsms[ :]")"
gate "var_dump / print_r( / dd(" "$(grep -rnE '(^|[^A-Za-z_>:$])(var_dump|print_r|dd)\(' "$A" "$T" "$src/js/addons/kwtsms" 2>/dev/null | grep -vP ':\d+:\s*(\*|//|/\*|\{\*)')"
gate "KWT-SMS outside comments and the TEST_SENDER constant" "$( (grep -rn 'KWT-SMS' "$A" "$T" 2>/dev/null; grep -rnE "['\"]KWT-SMS['\"]" "$src/js/addons/kwtsms" 2>/dev/null) | grep -vP ':\d+:\s*(\*|//|/\*|\{\*|#)' | grep -v "TEST_SENDER = 'KWT-SMS'")"
gate "KWT-SMS as a default in addon.xml or the .po files" "$(grep -n 'KWT-SMS' "$A/addon.xml" | grep -v '<!--\|^[0-9]*:[[:space:]]*[A-Za-z(`]' )"
gate "php -l (host $(php -r 'echo PHP_VERSION;'))" "$(find "$A" -name '*.php' -print0 | xargs -0 -n1 php -l 2>&1 | grep -v 'No syntax errors')"

echo "-- manifest gates"
min="$(sed -n 's|.*<min>\(.*\)</min>.*|\1|p' "$A/addon.xml" | head -1)"
gate "compatibility min is 4.20.1" "$([ "$min" = "4.20.1" ] || echo "min is '$min'")"
gate "install SQL creates kwtsms_attempts.is_test and idx_created_outcome_test" "$(python3 -I - "$A/addon.xml" <<'PY'
import re, sys
s = open(sys.argv[1], encoding='utf-8').read()
s = re.sub(r'<!--.*?-->', '', s, flags=re.S)
m = re.search(r'CREATE TABLE[^;]*?kwtsms_attempts\b(.*?)ENGINE', s, re.S | re.I)
if not m:
    print('no CREATE TABLE kwtsms_attempts in the install queries')
else:
    if '`is_test`' not in m.group(1): print('kwtsms_attempts has no is_test column')
    if not re.search(r'KEY\s+`idx_created_outcome_test`\s*\(`created_at`,\s*`outcome`,\s*`is_test`\)', m.group(1)): print('no idx_created_outcome_test (created_at, outcome, is_test)')
PY
)"
gate "language parity (msgctxt sets equal, every used kwtsms.* key exists)" "$(python3 -I - "$src" <<'PY'
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
for base in ('app/addons/kwtsms', 'design/backend/templates/addons/kwtsms', 'js/addons/kwtsms'):
    for d, _, fs in os.walk(os.path.join(root, base)):
        for f in fs:
            if not f.endswith(('.php', '.tpl', '.js', '.xml')): continue
            p = os.path.join(d, f)
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
PY
)"

echo "-- stage"
stage="$(mktemp -d "${TMPDIR:-/tmp}/kwtsms-stage.XXXXXX")"
trap 'rm -rf "$stage" "${check:-}"' EXIT
pkg="$stage/kwtsms"; mkdir -p "$pkg"
for p in $SHIP; do
  [ -e "$p" ] || { echo "FAIL  shipped path missing: $p"; fail=1; continue; }
  find "$p" \( -type f -o -type l \) -print0 | sort -z | while IFS= read -r -d '' f; do
    case "$f" in */.gitkeep) continue ;; esac
    cp -L --parents "$f" "$pkg/" || echo "FAIL  cannot copy $f"
  done
done
if git -C "$src" rev-parse --git-dir >/dev/null 2>&1 && [ "$src" = "$here" ]; then
  want="$(git ls-files -- $SHIP | sort)"
  have="$(cd "$pkg" && find . -type f | sed 's|^\./||' | sort)"
  gate "package files == git-tracked files of the shipped paths" "$(diff <(printf '%s\n' "$want") <(printf '%s\n' "$have"))"
  gate "shipped paths have no uncommitted change" "$(git status --porcelain -- $SHIP)"
fi
epoch="$(git -C "$src" log -1 --format=%ct 2>/dev/null || true)"; [ -n "$epoch" ] || epoch="$(date +%s)"
find "$pkg" -type d -exec chmod 755 {} +
find "$pkg" -type f -exec chmod 644 {} +
find "$pkg" -exec touch -h -d "@$epoch" {} +

echo "-- package gates (on the staged tree)"
list="$(cd "$pkg" && find . -type f | sed 's|^\./||' | sort)"
gate "no symlinks" "$(find "$pkg" -type l)"
gate "no world-writable or group-writable entries" "$(find "$pkg" -perm /022 \( -type f -o -type d \))"
gate "only the allowed top-level paths" "$(printf '%s\n' "$list" | grep -vE '^(app/addons/kwtsms/|design/backend/templates/addons/kwtsms/|design/backend/css/addons/kwtsms/|design/backend/media/images/addons/kwtsms/|js/addons/kwtsms/|var/langs/(en|ar)/addons/kwtsms\.po$)')"
gate "no test, doc, dev or vendor paths or files" "$(printf '%s\n' "$list" | grep -E '(^|/)(tests|docs|docker|\.git|\.claude|vendor|node_modules|devguard)/|\.env|composer\.(json|lock)|phpunit|\.log$|\.sent-msgids|kwtsms_devguard|\.(orig|rej|bak|swp|zip|tgz)$|~$')"
gate "no private patterns (docs/private-patterns.txt)" "$(grep -rnIf "$patterns" "$pkg" | sed 's/:.*/: matches a private pattern/')"
gate "no debug calls (var_dump, print_r(, dd()" "$(grep -rnE '(^|[^A-Za-z_>:$])(var_dump|print_r|dd)\(' "$pkg" 2>/dev/null | grep -vP ':\d+:\s*(\*|//|/\*|\{\*)')"
gate "error_log( only as the kwtsms last-resort fallback" "$(grep -rnE '(^|[^A-Za-z_>:])error_log\(' "$pkg" 2>/dev/null | grep -vE "error_log\('kwtsms[ :]")"
gate "no KWTSMS_TESTSUITE, devguard or dev markers" "$(grep -rnEI 'KWTSMS_TESTSUITE|devguard|kwtsms_dev_anti_csrf_off|KWTSMS_NO_SEND|dev_anti_csrf|\.sent-msgids|/tests/|run-live' "$pkg" 2>/dev/null)"
gate "no banned characters (en dash, em dash, smart quotes, ellipsis)" "$(grep -rnIP '[\x{2013}\x{2014}\x{2018}\x{2019}\x{201C}\x{201D}\x{2026}]' "$pkg" 2>/dev/null)"
gate "English .po and all code/templates are ASCII only" "$(grep -rnIP '[^\x00-\x7F]' "$pkg" --include=*.php --include=*.tpl --include=*.js --include=*.xml --include=*.less 2>/dev/null; grep -nP '[^\x00-\x7F]' "$pkg/var/langs/en/addons/kwtsms.po" 2>/dev/null)"
gate "no attribution words (assistant or vendor names)" "$(grep -rniIE 'cl[a]ude|anthrop[i]c' "$pkg" 2>/dev/null)"
gate "no phone number other than the documented fictional ones" "$(grep -rhoIE '(^|[^0-9])965[0-9]{8}([^0-9]|$)' "$pkg" | grep -oE '965[0-9]{8}' | sort -u | grep -vxE '96598765432|96550000001|96550123401|96522334455')"
gate "no Tygh\\Http, VERIFYPEER 0, OVERRIDE or API_BASE in the package" "$(grep -rnE 'use[[:space:]]+Tygh\\Http\b|Tygh\\Http::|SSL_VERIFYPEER, ?(0|false)|OVERRIDE|API_BASE' "$pkg/app" 2>/dev/null | grep -v 'HttpTransport.php:[0-9]*: \* WHY')"
gate "no nofilter in package templates (anchored)" "$(grep -rnE '\|[[:space:]]*nofilter| nofilter\}' "$pkg/design" 2>/dev/null)"
gate "php -l on the package (host)" "$(find "$pkg" -name '*.php' -print0 | xargs -0 -n1 php -l 2>&1 | grep -v 'No syntax errors')"
if [ "$lint74" = 1 ]; then
  if command -v docker >/dev/null 2>&1; then
    gate "php -l on the package (PHP 7.4)" "$(docker run --rm -v "$pkg:/pkg:ro" php:7.4-cli sh -c 'find /pkg -name "*.php" -print0 | xargs -0 -n1 php -l 2>&1 | grep -v "No syntax errors"' 2>&1)"
  else echo "FAIL  docker not found for the PHP 7.4 pass (use --no-lint74)"; fail=1; fi
fi

if [ "$fail" != 0 ]; then echo "== GATES FAILED: no archive written =="; exit 1; fi

echo "-- archives"
mkdir -p "$out"; rm -rf "$out/kwtsms"; cp -r "$pkg" "$out/kwtsms"
zipf="$out/kwtsms-$version.zip"; tgzf="$out/kwtsms-$version.tgz"; rm -f "$zipf" "$tgzf"
( cd "$pkg" && printf '%s\n' "$list" | zip -X -q "$zipf" -@ )
( cd "$pkg" && tar --sort=name --mtime="@$epoch" --owner=0 --group=0 --numeric-owner --mode='u=rwX,go=rX' -czf "$tgzf" $(printf '%s\n' "$list") )
# the archives must unpack to exactly the staged tree, with no group/world-writable entry
check="$(mktemp -d "${TMPDIR:-/tmp}/kwtsms-check.XXXXXX")"
mkdir "$check/z" "$check/t"; unzip -q "$zipf" -d "$check/z"; tar -xzf "$tgzf" -C "$check/t"
gate "zip unpacks to the staged tree" "$(diff -r "$pkg" "$check/z")"
gate "tgz unpacks to the staged tree" "$(diff -r "$pkg" "$check/t")"
gate "zip entry modes" "$(unzip -Z "$zipf" | awk 'NR>2 && NF>=9 && $1 !~ /^-rw-r--r--|^Archive|^Zip|files?,/ {print}' | grep -E '^[-d]r' )"
gate "tgz entry modes" "$(tar -tvzf "$tgzf" | awk '$1 !~ /^-rw-r--r--$/ {print}')"
[ "$fail" = 0 ] || { rm -f "$zipf" "$tgzf"; echo "== ARCHIVE CHECK FAILED =="; exit 1; }
( cd "$out" && sha256sum "kwtsms-$version.zip" "kwtsms-$version.tgz" > SHA256SUMS )
echo "files: $(printf '%s\n' "$list" | wc -l)"
ls -l "$zipf" "$tgzf"; cat "$out/SHA256SUMS"
echo "== OK kwtsms $version =="
