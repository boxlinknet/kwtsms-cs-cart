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
# 2026-10-23 03:39 +03: Task A12 item 2 (R53), task 596. The two scripted audits read tools/audit-allowlist.txt, lines
#            <path>:<function>:<$variable>; a variable allowed in one function is not allowed under the same name
#            elsewhere (they were allowed by bare name before).
# 2026-10-23 03:39 +03: Task A12 item 3 (R53), task 596. A second modes gate: the shipped files are staged by
#            tools/stage-modes.sh inside the php container (/tmp/kwtsms-stage.XXXX, native ext4 modes) with the modes
#            measured on this tree, and a group/world-writable entry fails; the git-index gate stays as the other one.
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

# 2026-10-22 13:32 +03: the gates, the stage and the package gates live in tools/package-gates.sh (sourced), shared with
# tools/build-upgrade-package.sh; this script keeps its options and the archive step, Task A1, task 596.
# upgrades/ (the migrations of the upgrade packages) is not in SHIP, so the add-on zip and tgz never carry it; the
# gate "only the allowed top-level paths" fails the build if it ever does.
. "$here/tools/package-gates.sh"

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
