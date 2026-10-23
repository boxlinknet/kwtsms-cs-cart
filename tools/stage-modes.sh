#!/bin/sh
# FILE:      tools/stage-modes.sh
# CREATED:   2026-10-23 03:36 +03
# TASK:      596
# AUTHOR:    kwtSMS
# WHAT:      Builds a stage of the shipped files in a throwaway directory /tmp/kwtsms-stage.XXXX on a filesystem that
#            keeps modes (the php container's ext4), gives every file the mode the caller measured, prints every
#            group- or world-writable entry of the stage, then "CHECKED <n>", and removes only its own directory.
# WHY:       Task A12 item 3 (R53). On a drive that keeps no modes (a Windows drive under WSL) every file reads as 777,
#            so the 1.0.0 gate could only look at the git index. The stage is now checked where modes are real, and
#            the git-index gate stays as the second gate.
# USAGE:     tar -cf - -C <src> -T <files> + a member ".modes" ("<octal mode> <path>" per line) | sh -c "$(cat tools/stage-modes.sh)"
#            (the tar archive is the standard input; tools/package-gates.sh builds and pipes it).
# DEPENDS:   POSIX sh, GNU tar and find, mktemp.
st="$(mktemp -d /tmp/kwtsms-stage.XXXX)" || { echo "cannot create the stage"; exit 3; }
case "$st" in /tmp/kwtsms-stage.*) ;; *) echo "unexpected stage path"; exit 3 ;; esac
tar -xf - -C "$st" --no-same-permissions || { rm -rf "$st"; echo "cannot unpack the stage"; exit 3; }
n=0
while read -r mode path; do
  [ -n "$path" ] || continue
  if [ -e "$st/$path" ]; then chmod "$mode" "$st/$path" || echo "cannot set mode $mode on $path"; n=$((n + 1)); fi
done < "$st/.modes"
rm -f "$st/.modes"
find "$st" -perm /022 \( -type f -o -type d \) | sed "s|^$st/*|group/world-writable in the stage: |"
echo "CHECKED $n"
rm -rf "$st"
