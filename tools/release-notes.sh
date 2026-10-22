#!/usr/bin/env bash
# FILE:      tools/release-notes.sh
# CREATED:   2026-10-22 12:43 +03
# TASK:      596
# AUTHOR:    kwtSMS
# WHAT:      Prints the GitHub release notes for one release: title, a test notice for a pre-release, the README's
#            Features, Requirements, Installation and Configuration sections (HTML comments dropped, wrapped lines
#            joined) and a link to the CHANGELOG.
# WHY:       The first release used the whole CHANGELOG section (200+ hard-wrapped lines); the other kwtSMS
#            repositories publish short notes (features, install, requirements, CHANGELOG link). GitHub renders every
#            newline of a release body as a line break, so wrapped README lines are joined.
# USAGE:     bash tools/release-notes.sh <release>   e.g. 1.0.0 or 1.0.0-rc.1; called by .github/workflows/release.yml.
# DEPENDS:   bash, awk, README.md with "## Features", "## Requirements", "## Installation", "## Configuration".

set -euo pipefail

release="${1:?usage: tools/release-notes.sh <release, e.g. 1.0.0 or 1.0.0-rc.1>}"
version="${release%%-*}"
readme="$(cd "$(dirname "$0")/.." && pwd)/README.md"

# section <heading>: the lines under "## <heading>" up to the next "## ", without HTML comments, with each
# paragraph or list item on one line and no leading or trailing blank line.
section() {
  awk -v h="## $1" '$0 == h {on = 1; next} on && /^## / {exit} on' "$readme" | awk '
    skip { if (/-->/) skip = 0; next }
    /^[[:space:]]*<!--/ { if (!/-->/) skip = 1; next }
    /^[[:space:]]*$/ { if (buf != "") print buf; buf = ""; print ""; next }
    /^(- |[0-9]+\. |#)/ { if (buf != "") print buf; buf = $0; next }
    { sub(/^[[:space:]]+/, ""); buf = (buf == "" ? $0 : buf " " $0) }
    END { if (buf != "") print buf }' | awk 'NF {seen = 1} seen' | cat -s | awk '{a[NR] = $0} END {n = NR; while (n > 0 && a[n] == "") n--; for (i = 1; i <= n; i++) print a[i]}'
}

printf '## kwtSMS for CS-Cart v%s\n\n' "$release"
if [ "$release" != "$version" ]; then
  printf '> **Release candidate for testing.** Install it on a staging copy of the store first. The add-on starts in test mode: messages reach kwtSMS but are never delivered until you turn test mode off.\n\n'
fi
printf 'Send automated SMS notifications to your CS-Cart and Multi-Vendor customers and administrators through kwtSMS. Free and open source.\n\n'
for s in Features Requirements Installation Configuration; do
  printf '### %s\n\n' "$s"
  section "$s"
  printf '\n'
done
printf 'Check the download against `SHA256SUMS`. All changes: [CHANGELOG.md](https://github.com/boxlinknet/kwtsms-cs-cart/blob/v%s/CHANGELOG.md).\n' "$release"
