#!/usr/bin/env bash
# FILE:      tools/write-checksums.sh
# CREATED:   2026-11-04 12:47 +03
# TASK:      596
# AUTHOR:    kwtSMS
# WHAT:      Writes <dist>/SHA256SUMS for a release: the full package (kwtsms-<version>.zip and .tgz) AND every upgrade
#            package built for that version (kwtsms-upgrade-<from>-<version>.zip), one "<sha256>  <file name>" line per
#            file, sorted by file name. Files that are not there are skipped; nothing else in the directory is listed.
# WHY:       Task RC2 item 4 (1.2.0-rc.2): SHA256SUMS listed only the full package, so a downloader could not check the
#            upgrade zip they were told to use. It is regenerated from what is in dist/, so the order in which the
#            full package and the upgrade packages were built does not matter.
# USAGE:     bash tools/write-checksums.sh [DIST_DIR] VERSION          (DIST_DIR default: <repo>/dist)
#            Called by tools/build-package.sh and tools/build-upgrade-package.sh. Release procedure: build the full
#            package, then each upgrade package (the checksums file is refreshed each time), then attach the
#            upgrade zips and the final SHA256SUMS to the release (CONTRIBUTING.md, "Releasing").
# DEPENDS:   bash, sha256sum, sort.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [ $# -eq 1 ]; then dist="$here/dist"; ver="$1"; elif [ $# -eq 2 ]; then dist="$1"; ver="$2"; else
  echo "usage: write-checksums.sh [DIST_DIR] VERSION" >&2; exit 2
fi
case "$ver" in [0-9]*.[0-9]*.[0-9]*) ;; *) echo "write-checksums: bad version '$ver'" >&2; exit 2 ;; esac
cd "$dist" || exit 2

files=()
for f in "kwtsms-$ver.zip" "kwtsms-$ver.tgz" kwtsms-upgrade-*-"$ver".zip; do
  [ -f "$f" ] && files+=("$f")
done
[ "${#files[@]}" -gt 0 ] || { echo "write-checksums: no package for $ver in $dist" >&2; exit 1; }

tmp="$(mktemp "SHA256SUMS.XXXXXX")"
if ! sha256sum "${files[@]}" | LC_ALL=C sort -k2 > "$tmp" || [ ! -s "$tmp" ]; then
  rm -f "$tmp"; echo "write-checksums: sha256sum failed" >&2; exit 1
fi
mv "$tmp" SHA256SUMS
chmod 644 SHA256SUMS
cat SHA256SUMS
