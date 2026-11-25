#!/usr/bin/env bash
#
# R6-B6 F3: the drill image's own restore environment, printed INSIDE the image while it is built
# (scripts/restore-drill-vm-image.sh runs it with virt-customize and reads the result back with virt-cat). The build
# adds the finished image's sha256, and the drill host installs the result root-owned as the image properties the
# Restore Environment Compatibility Contract is checked against. Never run on a drill host or in a drill.
set -euo pipefail
v="$(/usr/lib/postgresql/16/bin/postgres --version)"
[[ "$v" =~ ([0-9]+)\.([0-9]+) ]] || { echo "image-facts: cannot read the PostgreSQL version" >&2; exit 1; }
echo "DMS_IMAGE_PG_MAJOR=${BASH_REMATCH[1]}"
echo "DMS_IMAGE_PG_MINOR=${BASH_REMATCH[2]}"
g="$(getconf GNU_LIBC_VERSION)"
echo "DMS_IMAGE_GLIBC_VERSION=${g#glibc }"
icu=none
for f in /usr/lib/x86_64-linux-gnu/libicuuc.so.*.*; do
  [ -e "$f" ] || continue
  [[ "$f" =~ libicuuc\.so\.([0-9]+(\.[0-9]+){1,2})$ ]] && icu="${BASH_REMATCH[1]}"
done
echo "DMS_IMAGE_ICU_VERSION=${icu}"
tz="$(sed -n 's/^# version \([0-9]\{4\}[a-z]\)$/\1/p' /usr/share/zoneinfo/tzdata.zi | head -n 1)"
[ -n "$tz" ] || { echo "image-facts: cannot read the tzdata version" >&2; exit 1; }
echo "DMS_IMAGE_TZDATA_VERSION=${tz}"
echo "DMS_IMAGE_LOCALES=$(locale -a | grep -E '^[A-Za-z0-9_.@-]{1,32}$' | sort | tr '\n' ' ' | sed 's/ $//')"
ext=""
for c in /usr/share/postgresql/16/extension/*.control; do
  n="$(basename "$c" .control)"
  [ "$n" = plpgsql ] && continue
  d="$(sed -n "s/^default_version *= *'\([0-9A-Za-z.]*\)'.*/\1/p" "$c" | head -n 1)"
  [ -n "$d" ] && ext="${ext}${ext:+,}${n}:${d}"
done
echo "DMS_IMAGE_EXTENSIONS=${ext:-none}"
