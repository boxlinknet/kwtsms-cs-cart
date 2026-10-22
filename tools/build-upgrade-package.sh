#!/usr/bin/env bash
# FILE:      tools/build-upgrade-package.sh
# CREATED:   2026-10-22 13:34 +03
# TASK:      596
# AUTHOR:    kwtSMS
# WHAT:      Builds the CS-Cart Upgrade Center package that takes an installed kwtSMS add-on from one version to the
#            next: dist/kwtsms-upgrade-<from>-<to>.zip, and prints its sha256. It runs the same gates as
#            tools/build-package.sh (tools/package-gates.sh) on the new tree, gates upgrades/<to> the same way, then
#            lays the package out like a CS-Cart add-on upgrade:
#              schema.json                       id, type "addon", from_version, to_version, name, description, timestamp, size, file
#              kwtsms-upgrade-<from>-<to>.txt    the file named by schema.json "file" (without it the Upgrade Center shows no Install button)
#              content/package.json              files {path: {status new|changed|deleted, hash md5 of the OLD file}},
#                                                migrations [...], scripts {"post": "post.php"}
#              content/package/<store paths>     only the new and changed shipped files (app/addons/kwtsms/..,
#                                                design/backend/..., js/..., var/langs/...)
#              content/migrations/*.php          upgrades/<to>/migrations/*.php
#              content/scripts/post.php          upgrades/<to>/scripts/post.php (when it exists)
#            It then checks the zip with the rules of Tygh\UpgradeCenter\App (validateSchema, checkPackagePermissions)
#            and applies it to the old tree to prove the result equals the new tree byte for byte.
# WHY:       Task A1: 1.1.0 changes tables, so an upgrade is not a file copy. Migrations run through the Upgrade
#            Center, which also runs the validators and the backup. The package must only change what changed.
# USAGE:     bash tools/build-upgrade-package.sh --from 1.0.0 --to 1.1.0 [--src DIR] [--out DIR] [--from-ref REF] [--no-lint74]
#              --from VER      installed version this package upgrades (must equal the installed add-on version)
#              --to VER        version of the tree being packaged (must equal its addon.xml <version>)
#              --src DIR       tree to package (default: the repo root)
#              --out DIR       where the zip goes (default: <src>/dist)
#              --from-ref REF  git ref of the old release (default: tag v<from>, else the newest v<from>-rc.N)
#              --no-lint74     skip the PHP 7.4 syntax pass (needs Docker image php:7.4-cli)
#            Upload it in Administration > Upgrade Center > Upload upgrade package. The Upgrade Center takes the
#            package id from the FILE NAME (kwtsms.zip), so upload a copy named kwtsms.zip, not the long name.
#            Reproducible: sorted entries, fixed modes, mtime of the last commit on the shipped paths, TZ pinned.
# DEPENDS:   bash, git, python3, php, zip, unzip, sha256sum; docker for the 7.4 pass; docs/private-patterns.txt.
set -uo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
src="$here"; out=""; lint74=1; from=""; to=""; from_ref=""
while [ $# -gt 0 ]; do
  case "$1" in
    --from) from="${2:-}"; shift 2 ;;
    --to) to="${2:-}"; shift 2 ;;
    --src) src="$(cd "$2" && pwd)" || exit 2; shift 2 ;;
    --out) mkdir -p "$2" && out="$(cd "$2" && pwd)" || exit 2; shift 2 ;;
    --from-ref) from_ref="${2:-}"; shift 2 ;;
    --no-lint74) lint74=0; shift ;;
    *) echo "unknown option $1" >&2; exit 2 ;;
  esac
done
case "$from" in [0-9]*.[0-9]*.[0-9]*) ;; *) echo "usage: --from <x.y.z> --to <x.y.z>" >&2; exit 2 ;; esac
case "$to" in [0-9]*.[0-9]*.[0-9]*) ;; *) echo "usage: --from <x.y.z> --to <x.y.z>" >&2; exit 2 ;; esac
[ -n "$out" ] || out="$src/dist"
patterns="$here/docs/private-patterns.txt"

# The same gates, the same stage as the add-on zip (the staged tree is $pkg).
. "$here/tools/package-gates.sh"

echo "-- upgrade gates"
version_check() {
  [ "$version" = "$to" ] || echo "the tree's addon.xml version is $version, not --to $to"
  [ "$from" != "$to" ] || echo "--from and --to are both $from"
  [ "$(printf '%s\n%s\n' "$from" "$to" | sort -V | head -1)" = "$from" ] || echo "--from $from is newer than --to $to"
  echo "CHECKED 3"
}
gate "addon.xml version equals --to, and --from is older" version_check

updir="$src/upgrades/$to"
upgrades_check() { # upgrades/<to>: migrations are named <14 digits>_<name>.php, the scripts are post.php only
  local f n=0
  [ -d "$updir" ] || { echo "CHECKED 1"; return 0; }
  for f in "$updir"/migrations/*; do
    [ -e "$f" ] || continue
    n=$((n + 1))
    case "$(basename "$f")" in [0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]_*.php) ;; *) echo "bad migration file name: $f" ;; esac
  done
  for f in "$updir"/scripts/*; do
    [ -e "$f" ] || continue
    n=$((n + 1))
    [ "$(basename "$f")" = "post.php" ] || echo "unexpected script (only post.php is packaged): $f"
  done
  echo "CHECKED $((n + 1))"
}
gate "upgrades/$to holds only migrations and post.php" upgrades_check
if [ -d "$updir" ]; then
  up_private_check() {
    local res rc
    res="$(grep -rnIf "$patterns" "$updir")"; rc=$?
    [ "$rc" -le 1 ] || return "$rc"
    [ -z "$res" ] || printf '%s\n' "$res" | sed 's/^\([^:]*:[0-9]*\):.*/\1: matches a private pattern/'
    echo "CHECKED $(find "$updir" -type f | wc -l)"
  }
  gate "upgrades/$to: no private patterns" up_private_check
  gate "upgrades/$to: no debug calls" scan "$updir" ':\d+:\s*(\*|//|/\*|\{\*)' -nE '(^|[^A-Za-z_>:$])(var_dump|print_r|dd)\('
  gate "upgrades/$to: error_log( only as a whole 'kwtsms last-resort statement" error_log_check "$updir"
  gate "upgrades/$to: no banned characters" scan "$updir" '' -nIP '[\x{2013}\x{2014}\x{2018}\x{2019}\x{201C}\x{201D}\x{2026}]'
  up_ascii_check() {
    local res rc
    res="$(grep -rnIP '[^\x00-\x7F]' "$updir")"; rc=$?
    [ "$rc" -le 1 ] || return 2
    [ -z "$res" ] || printf '%s\n' "$res"
    echo "CHECKED $(find "$updir" -type f | wc -l)"
  }
  gate "upgrades/$to: ASCII only" up_ascii_check
  gate "upgrades/$to: no attribution words" scan "$updir" '' -niIE 'cl[a]ude|anthrop[i]c'
  gate "upgrades/$to: no Tygh\\Http, VERIFYPEER 0, OVERRIDE or API_BASE" scan "$updir" '' -nE 'use[[:space:]]+Tygh\\Http\b|Tygh\\Http::|SSL_VERIFYPEER, ?(0|false)|OVERRIDE|API_BASE'
  gate "upgrades/$to: php -l (host)" phpl_host "$updir"
  up_modes_check() { # no symlink, no group/world-writable entry (git index modes where the filesystem keeps none)
    local res n probe keeps=0
    n="$(find "$updir" | wc -l)" || return 3
    res="$(find "$updir" -type l)" || return 3
    [ -z "$res" ] || printf 'symlink: %s\n' $res
    probe="$(mktemp "$src/.kwtsms-modeprobe.XXXXXX")" || return 3
    chmod 644 "$probe" && [ "$(stat -c %a "$probe")" = "644" ] && keeps=1
    rm -f "$probe"
    if [ "$keeps" = 1 ]; then
      res="$(find "$updir" -perm /022 \( -type f -o -type d \))" || return 3
      [ -z "$res" ] || printf 'group/world-writable: %s\n' $res
    elif git -C "$src" rev-parse --git-dir >/dev/null 2>&1; then
      git -C "$src" ls-files -s -- "upgrades/$to" | awk '$1 != "100644" {print "index mode " $1 ": " $4}' || return 3
    else
      echo "this filesystem does not keep modes and $src is not a git tree: modes cannot be checked"
    fi
    echo "CHECKED $n"
  }
  gate "upgrades/$to: no symlink, no group/world-writable entry" up_modes_check
  if git -C "$src" rev-parse --git-dir >/dev/null 2>&1 && [ "$src" = "$here" ]; then
    up_clean_check() { git status --porcelain -- "upgrades/$to" || return 3; echo "CHECKED $(git ls-files -- "upgrades/$to" | wc -l)"; }
    gate "upgrades/$to has no uncommitted change" up_clean_check
  fi
  if [ "$lint74" = 1 ]; then
    up_phpl_74() {
      command -v docker >/dev/null 2>&1 || { echo "docker not found for the PHP 7.4 pass (use --no-lint74)"; echo "CHECKED 1"; return 0; }
      docker run --rm --quiet -v "$updir:/pkg:ro" php:7.4-cli sh -c 'n=0; for f in $(find /pkg -name "*.php"); do php -l "$f" >/dev/null 2>&1 || php -l "$f"; n=$((n + 1)); done; echo "CHECKED $n"'
    }
    gate "upgrades/$to: php -l (PHP 7.4)" up_phpl_74
  fi
fi

# The old release, from git: tag v<from>, else the newest release candidate of it.
oldtree="$stage/from"; mkdir -p "$oldtree"
resolve_ref() {
  if [ -n "$from_ref" ]; then printf '%s' "$from_ref"; return; fi
  if git -C "$here" rev-parse -q --verify "refs/tags/v$from" >/dev/null 2>&1; then printf 'v%s' "$from"; return; fi
  git -C "$here" tag -l "v$from-rc.*" --sort=-v:refname | head -1
}
ref="$(resolve_ref)"
old_tree_check() {
  [ -n "$ref" ] || { echo "no git tag v$from or v$from-rc.N: pass --from-ref"; echo "CHECKED 1"; return 0; }
  git -C "$here" archive "$ref" -- $SHIP | tar -x -C "$oldtree" || { echo "git archive $ref failed"; echo "CHECKED 1"; return 0; }
  local v
  v="$(sed -n 's|.*<version>\(.*\)</version>.*|\1|p' "$oldtree/app/addons/kwtsms/addon.xml" | head -1)"
  [ "$v" = "$from" ] || echo "ref $ref holds version $v, not --from $from"
  echo "CHECKED $(find "$oldtree" -type f | wc -l)"
}
gate "the old release ($ref) holds version $from" old_tree_check

if [ "$fail" != 0 ]; then echo "== GATES FAILED ($failed failed, $passed passed): no upgrade package written =="; exit 1; fi

# ---------------------------------------------------------------------------------------------------------------
echo "-- upgrade package"
up_epoch="$(git -C "$src" log -1 --format=%ct -- $SHIP "upgrades/$to" 2>/dev/null || true)"
[ -n "$up_epoch" ] || up_epoch="$(date +%s)"
up="$stage/up"; mkdir -p "$up/content/package" "$up/content/migrations" "$up/content/scripts" || exit 2
zipname="kwtsms-upgrade-$from-$to.zip"
# 2026-10-22 14:07 +03: Upgrade::getPackages() lists an uploaded package as installable only when the file named by schema.json "file"
# exists next to schema.json (App.php: is_file($pack_path . $package_id . '/' . $schema['file'])); the Upgrade Center
# unpacks nothing from it for an uploaded package (the files are in content/), so it is a one-line marker, Task A1, task 596.
markername="kwtsms-upgrade-$from-$to.txt"
build_layout() { py "$pkg" "$oldtree" "$updir" "$up" "$from" "$to" "$markername" "$up_epoch" <<'PY'
import hashlib, json, os, shutil, sys
new, old, updir, up, frm, to, markername, epoch = sys.argv[1:9]
def tree(root):
    out = {}
    for d, _, fs in os.walk(root):
        for f in fs:
            p = os.path.join(d, f)
            out[os.path.relpath(p, root).replace(os.sep, '/')] = p
    return out
def md5(p):
    return hashlib.md5(open(p, 'rb').read()).hexdigest()
n, o = tree(new), tree(old)
files, size = {}, 0
for rel in sorted(n):
    if rel not in o:
        files[rel] = {'status': 'new'}
    elif md5(n[rel]) != md5(o[rel]):
        files[rel] = {'status': 'changed', 'hash': md5(o[rel])}
    else:
        continue
    dst = os.path.join(up, 'content', 'package', rel)
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    shutil.copyfile(n[rel], dst)
    size += os.path.getsize(n[rel])
for rel in sorted(o):
    if rel not in n:
        files[rel] = {'status': 'deleted', 'hash': md5(o[rel])}
package = {'files': files}
mig_src = os.path.join(updir, 'migrations')
mig = sorted(os.listdir(mig_src)) if os.path.isdir(mig_src) else []
for f in mig:
    shutil.copyfile(os.path.join(mig_src, f), os.path.join(up, 'content', 'migrations', f))
    size += os.path.getsize(os.path.join(mig_src, f))
if mig:
    package['migrations'] = mig
post = os.path.join(updir, 'scripts', 'post.php')
if os.path.isfile(post):
    shutil.copyfile(post, os.path.join(up, 'content', 'scripts', 'post.php'))
    size += os.path.getsize(post)
    package['scripts'] = {'post': 'post.php'}
for sub in ('migrations', 'scripts'):          # empty directories are not part of the package
    p = os.path.join(up, 'content', sub)
    if not os.listdir(p):
        os.rmdir(p)
json.dump(package, open(os.path.join(up, 'content', 'package.json'), 'w'), indent=1, sort_keys=True)
schema = {
    'id': 'kwtsms', 'type': 'addon', 'file': markername, 'name': 'kwtSMS ' + to, 'addon_name': 'kwtSMS',
    'description': 'Upgrade of the kwtSMS add-on from ' + frm + ' to ' + to + '. Edited SMS templates, notification switches, settings and the saved login are kept.',
    'from_version': frm, 'to_version': to, 'timestamp': int(epoch), 'size': size,
}
json.dump(schema, open(os.path.join(up, 'schema.json'), 'w'), indent=1, sort_keys=True)
open(os.path.join(up, markername), 'w').write('kwtSMS upgrade package ' + frm + ' to ' + to + '. The files are in content/.\n')
print('CHECKED', len(files) + len(mig))
PY
}
must "lay out the upgrade package" build_layout
must "chmod 755 on the package directories" find "$up" -type d -exec chmod 755 {} +
must "chmod 644 on the package files" find "$up" -type f -exec chmod 644 {} +
must "package timestamps" find "$up" -exec touch -h -d "@$up_epoch" {} +
up_list="$(cd "$up" && find . -type f | sed 's|^\./||' | LC_ALL=C sort)"
zipf="$out/$zipname"
must "clear the old upgrade zip" rm -f "$zipf"
( cd "$up" && printf '%s\n' "$up_list" | TZ=Asia/Kuwait zip -X -q "$zipf" -@ ) || { echo "FAIL  zip (exit $?)"; fail=1; }
check="$(mktemp -d "${TMPDIR:-/tmp}/kwtsms-upcheck.XXXXXX")" || exit 2
mkdir "$check/z" || exit 2
must "unzip" unzip -q "$zipf" -d "$check/z"
verify_package() { py "$check/z" "$oldtree" "$pkg" "$from" "$to" <<'PY'
import hashlib, json, os, re, shutil, sys, tempfile
z, old, new, frm, to = sys.argv[1:6]
bad = []
def md5(p):
    return hashlib.md5(open(p, 'rb').read()).hexdigest()
# Tygh\UpgradeCenter\App::validateSchema()
schema = json.load(open(os.path.join(z, 'schema.json')))
for k in ('file', 'name', 'description', 'from_version', 'to_version', 'timestamp', 'size', 'type'):
    if not schema.get(k):
        bad.append('schema.json: %s is empty' % k)
if schema.get('file') and not os.path.isfile(os.path.join(z, schema['file'])):
    bad.append('schema.json: the file named in "file" is not in the package root (the Upgrade Center then shows no Install button)')
if schema.get('type') != 'addon' or schema.get('id') != 'kwtsms':
    bad.append('schema.json: type/id must be addon/kwtsms')
if schema.get('from_version') != frm or schema.get('to_version') != to:
    bad.append('schema.json: versions differ from --from/--to')
# Tygh\UpgradeCenter\App::checkPackagePermissions() for an add-on package
pj = json.load(open(os.path.join(z, 'content', 'package.json')))
valid = ['app/addons/kwtsms', 'js/addons/kwtsms', 'images/', 'design/backend/css/addons/kwtsms',
         'design/backend/mail/templates/addons/kwtsms', 'design/backend/media/fonts/addons/kwtsms',
         'design/backend/media/images/addons/kwtsms', 'design/backend/templates/addons/kwtsms', 'var/langs/']
files = pj.get('files', {})
for path in files:
    if not any(re.match('^' + v, path) for v in valid):
        bad.append('forbidden path for an add-on package: ' + path)
mig_dir = os.path.join(z, 'content', 'migrations')
mig = sorted(os.listdir(mig_dir)) if os.path.isdir(mig_dir) else []
if mig != sorted(pj.get('migrations', [])):
    bad.append('migrations in the directory and in package.json differ')
scr_dir = os.path.join(z, 'content', 'scripts')
scr = sorted(os.listdir(scr_dir)) if os.path.isdir(scr_dir) else []
want = [v for v in (pj.get('scripts') or {}).values()]
if scr != sorted(want):
    bad.append('scripts in the directory and in package.json differ')
if os.path.isdir(os.path.join(z, 'content', 'languages')):
    bad.append('unexpected languages directory')
pdir = os.path.join(z, 'content', 'package')
have = set()
for d, _, fs in os.walk(pdir):
    for f in fs:
        have.add(os.path.relpath(os.path.join(d, f), pdir).replace(os.sep, '/'))
for path, data in files.items():
    if data['status'] != 'deleted' and path not in have:
        bad.append('listed but not in the package: ' + path)
for path in have - set(files):
    bad.append('in the package but not listed: ' + path)
# Hashes the Collisions validator compares with the installed (old) files
for path, data in files.items():
    o = os.path.join(old, path)
    if data['status'] == 'changed' and (not os.path.isfile(o) or md5(o) != data['hash']):
        bad.append('changed: hash is not the old file: ' + path)
    if data['status'] == 'deleted' and (not os.path.isfile(o) or md5(o) != data['hash']):
        bad.append('deleted: hash is not the old file: ' + path)
    if data['status'] == 'new' and os.path.exists(o):
        bad.append('new, but the old release has it: ' + path)
# Apply the package to the old tree: the result must be the new tree
work = tempfile.mkdtemp()
shutil.copytree(old, os.path.join(work, 'r'))
for path in have:
    dst = os.path.join(work, 'r', path)
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    shutil.copyfile(os.path.join(pdir, path), dst)
for path, data in files.items():
    if data['status'] == 'deleted':
        os.remove(os.path.join(work, 'r', path))
def snap(root):
    out = {}
    for d, _, fs in os.walk(root):
        for f in fs:
            p = os.path.join(d, f)
            out[os.path.relpath(p, root)] = md5(p)
    return out
a, b = snap(os.path.join(work, 'r')), snap(new)
for k in sorted(set(a) | set(b)):
    if a.get(k) != b.get(k):
        bad.append('old + package differs from the new tree: ' + k)
shutil.rmtree(work)
print('\n'.join(bad)) if bad else None
print('CHECKED', len(files) + len(mig) + 1)
PY
}
gate "the package passes the Upgrade Center rules and old + package == new tree" verify_package
zip_modes() { local res; res="$(unzip -Z "$zipf")" || return 3; printf '%s\n' "$res" | awk 'NR>2 && NF>=9 && $1 !~ /^-rw-r--r--|^Archive|^Zip|files?,/ {print}' | grep -E '^[-d]r'; echo "CHECKED $(printf '%s\n' "$up_list" | grep -c .)"; }
gate "upgrade zip entry modes" zip_modes
rm -rf "$check"
if [ "$fail" != 0 ]; then rm -f "$zipf"; echo "== UPGRADE PACKAGE CHECK FAILED ($failed failed): no zip kept =="; exit 1; fi
echo "files in the zip: $(printf '%s\n' "$up_list" | wc -l); old release: $ref"
ls -l "$zipf"
sha256sum "$zipf"
echo "== OK kwtsms upgrade $from -> $to ($passed gates passed). Upload a copy named kwtsms.zip =="
