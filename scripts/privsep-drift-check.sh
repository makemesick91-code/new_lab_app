#!/usr/bin/env bash
#
# DMS-DEPLOY-PRIVSEP-HARDENING-1 (rule 233) — READ-ONLY privilege-drift check.
#
# Reports what the deploy's privilege-separation gate (dms_privsep_final_gate)
# would find right now, without changing anything:
#   * the kernel link protections the ownership repair relies on;
#   * entries in storage/ and bootstrap/cache not owned by the runtime user and
#     group (left by a root command, an operator, or tampering);
#   * source entries another identity owns or can write (the runtime trees and
#     the environment files excluded).
# Summaries name owner, type and at most three path segments — never a file name.
#
# Run on the host as root, so that every entry is visible:
#   sudo -n bash scripts/privsep-drift-check.sh
#
# Exit 0 and PRIVSEP_DRIFT=NONE when clean; 1 and PRIVSEP_DRIFT=FOUND when not;
# 2 on a usage or authority error. It never creates, chowns, chmods, deletes or
# writes anything, and runs no PHP.
set -euo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="/var/www/asia-dental-lab-v2"
IDENTITY_FILE=""
TRUSTED_UID=0
TRUSTED_GID=0
SYSCTL_DIR="/proc/sys/fs"

usage() {
  echo "usage: bash scripts/privsep-drift-check.sh [--app-dir DIR] [--identity-file FILE] [--trusted-uid N --trusted-gid N] [--sysctl-dir DIR]" >&2
  exit 2
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --app-dir)       APP_DIR="${2:-}"; shift 2 || usage ;;
    --identity-file) IDENTITY_FILE="${2:-}"; shift 2 || usage ;;
    --trusted-uid)   TRUSTED_UID="${2:-}"; shift 2 || usage ;;
    --trusted-gid)   TRUSTED_GID="${2:-}"; shift 2 || usage ;;
    --sysctl-dir)    SYSCTL_DIR="${2:-}"; shift 2 || usage ;;
    *) usage ;;
  esac
done
[[ "$TRUSTED_UID" =~ ^[0-9]+$ && "$TRUSTED_GID" =~ ^[0-9]+$ ]] || usage

# This check sources two files from the tree it inspects. Prove first that only
# the trusted owner can have written them (and their directories): a drift check
# that sources a file another identity planted would run that identity's code.
assert_trusted_source() {
  local file="$1" path mode
  if [ -L "$file" ] || [ ! -f "$file" ]; then
    echo "privsep-drift-check: ${file} is not a regular file — refusing to source it" >&2
    exit 2
  fi
  for path in "$file" "$(dirname -- "$file")"; do
    mode="$(stat -c '%a' -- "$path")"
    if [ "$(stat -c '%u' -- "$path")" != "$TRUSTED_UID" ] || [ $(( 8#${mode} & 8#022 )) -ne 0 ]; then
      echo "privsep-drift-check: ${path} is not owned by uid ${TRUSTED_UID} or is writable by another identity — refusing to source ${file}" >&2
      exit 2
    fi
  done
}

assert_trusted_source "${SELF_DIR}/lib/runtime-privsep.sh"
# shellcheck source=lib/runtime-privsep.sh
. "${SELF_DIR}/lib/runtime-privsep.sh"

IDENTITY_FILE="${IDENTITY_FILE:-${APP_DIR}/deploy/runtime-identity.conf}"
if [ ! -r "$IDENTITY_FILE" ]; then
  echo "privsep-drift-check: runtime identity authority unreadable: ${IDENTITY_FILE}" >&2
  exit 2
fi
assert_trusted_source "$IDENTITY_FILE"
# shellcheck disable=SC1090
. "$IDENTITY_FILE"
RUNTIME_USER="${DMS_RUNTIME_USER:-}"
RUNTIME_GROUP="${DMS_RUNTIME_GROUP:-}"
if [ -z "$RUNTIME_USER" ] || [ -z "$RUNTIME_GROUP" ]; then
  echo "privsep-drift-check: the identity authority declares no runtime user/group" >&2
  exit 2
fi

cd "$APP_DIR"
if [ ! -d storage ] || [ -L storage ] || [ ! -d bootstrap/cache ] || [ -L bootstrap/cache ]; then
  echo "privsep-drift-check: storage/ or bootstrap/cache is missing or a symlink — PRIVSEP_DRIFT=FOUND" >&2
  exit 1
fi

echo "== Privilege drift check (rule 233, read-only) =="
echo "Runtime identity: ${RUNTIME_USER}:${RUNTIME_GROUP}; trusted source owner: ${TRUSTED_UID}:${TRUSTED_GID}"
drift=0
dms_privsep_kernel_guards "$SYSCTL_DIR" || drift=1

runtime_foreign="$(dms_privsep_foreign_count "$RUNTIME_USER" "$RUNTIME_GROUP" storage bootstrap/cache)"
source_foreign="$(dms_privsep_source_foreign_count "$TRUSTED_UID" "$TRUSTED_GID")"
echo "PRIVSEP_RUNTIME_FOREIGN_ENTRIES=${runtime_foreign}"
echo "PRIVSEP_SOURCE_FOREIGN_ENTRIES=${source_foreign}"
if [ "$runtime_foreign" -ne 0 ]; then
  dms_privsep_foreign_summary "$RUNTIME_USER" "$RUNTIME_GROUP" storage bootstrap/cache | sed 's/^/  runtime: /'
  drift=1
fi
if [ "$source_foreign" -ne 0 ]; then
  dms_privsep_source_foreign_summary "$TRUSTED_UID" "$TRUSTED_GID" | sed 's/^/  source: /'
  drift=1
fi

if [ "$drift" -ne 0 ]; then
  echo "PRIVSEP_DRIFT=FOUND"
  exit 1
fi
echo "PRIVSEP_DRIFT=NONE"
