#!/usr/bin/env bash
# FILE:      tools/clean-stale-upgrade-zips.sh
# CREATED:   2026-11-04 17:09 +03
# TASK:      596
# AUTHOR:    kwtSMS
# WHAT:      Deletes <dist>/kwtsms-upgrade-*-<version>.zip: the upgrade packages of the version about to be built that an
#            earlier build (an older release candidate) left in the directory. Zips of other versions stay.
# WHY:       Task RC2 (coordinator item): tools/write-checksums.sh lists kwtsms-upgrade-*-<version>.zip and the release step
#            uploads dist/kwtsms-upgrade-*, so a stale zip would ship. Kept as its own script so a unit test can run it
#            without zip or python3 (build-package.sh needs both).
# USAGE:     bash tools/clean-stale-upgrade-zips.sh DIST_DIR VERSION   (called by tools/build-package.sh)
# DEPENDS:   bash, rm.
set -euo pipefail

[ $# -eq 2 ] || { echo "usage: clean-stale-upgrade-zips.sh DIST_DIR VERSION" >&2; exit 2; }
dist="$1"; ver="$2"
case "$ver" in [0-9]*.[0-9]*.[0-9]*) ;; *) echo "clean-stale-upgrade-zips: bad version '$ver'" >&2; exit 2 ;; esac
[ -d "$dist" ] || exit 0
rm -f "$dist"/kwtsms-upgrade-*-"$ver".zip
