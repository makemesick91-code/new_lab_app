#!/usr/bin/env bash
#
# R6-B6 isolated restore drill — PostgreSQL extension allowlist for the drill VM image (review finding PR-8).
#
# Run once by scripts/restore-drill-vm-image.sh INSIDE the image being built (never on a host). Every extension
# control file and script outside plpgsql and the allowlist is deleted, so CREATE EXTENSION of anything else cannot
# even be attempted in the VM. With --dry-run it only prints what it would delete (used by the tests).
#
# Usage: prune-extensions.sh [--dry-run] <extension directory> <allowlist, comma-separated, or none>
set -euo pipefail
DRY=0
if [ "${1:-}" = --dry-run ]; then DRY=1; shift; fi
DIR="${1:?extension directory}"
ALLOW="${2:?allowlist or none}"
[[ "$ALLOW" =~ ^(none|[a-z_][a-z0-9_]{0,62}(,[a-z_][a-z0-9_]{0,62}){0,15})$ ]] || { echo "bad allowlist" >&2; exit 2; }
[ -d "$DIR" ] && [ ! -L "$DIR" ] || { echo "not a directory: ${DIR}" >&2; exit 2; }
keep=" plpgsql "
[ "$ALLOW" = none ] || keep+="${ALLOW//,/ } "
shopt -s nullglob
for control in "$DIR"/*.control; do
  name="${control##*/}"
  name="${name%.control}"
  case "$keep" in *" ${name} "*) continue ;; esac
  for f in "$control" "$DIR/${name}"--*.sql; do
    if [ "$DRY" = 1 ]; then printf 'would-delete %s\n' "${f##*/}"; else rm -f -- "$f"; fi
  done
done
left=()
for control in "$DIR"/*.control; do
  name="${control##*/}"
  name="${name%.control}"
  case "$keep" in *" ${name} "*) ;; *) left+=("$name") ;; esac
done
if [ "$DRY" = 0 ] && [ "${#left[@]}" -ne 0 ]; then
  echo "extensions outside the allowlist remain: ${left[*]}" >&2
  exit 1
fi
