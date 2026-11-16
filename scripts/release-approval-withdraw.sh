#!/usr/bin/env bash
#
# DMS-RELEASE-APPROVAL-DIRECT-1 — WITHDRAW one stored owner-signed release
# approval (approval contract 3). An ADMINISTRATOR action: run as root by the
# administrator account (sudo with the owner's password, rule 235). It is NOT
# reachable through the deploy operator's entrypoint or its sudo rule.
#
#   sudo bash scripts/release-approval-withdraw.sh --release-id <id> --approval-id <32-hex>
#
# Under the release's store lock it spends the approval id in the trusted
# evidence directory (spent/approval-<id>.spent, withdrawn=yes) and stores a
# root 0400 marker <store>/<release-id>/<approval-id>.withdrawn (withdrawn_at,
# withdrawn_by from SUDO_USER). The approval can never deploy afterwards, and
# it no longer blocks a corrected submission for the release. Nothing is
# deleted: the record, its signature and receipt stay as the audit trail.
#
# The library and the authority come from this program's own tree and are read
# only after this program proves that they and their directories belong to the
# trusted identity and are not group/other-writable.
#
# Exit codes: 0 withdrawn; 77 DENIED (nothing changed); 64 usage; 2 not root or
# an untrusted tree.
set -euo pipefail
umask 077

dms_approval_withdraw_usage() {
  echo "usage: sudo bash scripts/release-approval-withdraw.sh --release-id <release-id> --approval-id <32 lowercase hex>" >&2
}

# dms_approval_withdraw_trusted <trusted uid> <path> — the path and its
# directory are not symlinks, belong to <trusted uid> and are not
# group/other-writable.
dms_approval_withdraw_trusted() {
  local uid="$1" path out
  for path in "$2" "$(dirname -- "$2")"; do
    if [ -L "$path" ] || [ ! -e "$path" ]; then
      echo "${path} is missing or a symlink"
      return 1
    fi
    out="$(stat -c '%u %a' -- "$path" 2>/dev/null)" || { echo "${path} cannot be inspected"; return 1; }
    if [ "${out% *}" != "$uid" ] || [ $(( 8#${out#* } & 8#022 )) -ne 0 ]; then
      echo "${path} is uid ${out% *} mode ${out#* }; it must be uid ${uid} and not writable by group or others"
      return 1
    fi
  done
}

# dms_approval_withdraw_main <trusted uid> --release-id <id> --approval-id <hex>
# <trusted uid> is 0 when executed; the tests pass their own uid.
dms_approval_withdraw_main() {
  local uid="$1" self_dir app_dir authority lib file why by rc=0
  shift
  if [ "$#" -ne 4 ] || [ "$1" != "--release-id" ] || [[ ! "$2" =~ ^[a-z0-9][a-z0-9-]{2,79}$ ]] \
     || [ "$3" != "--approval-id" ] || [[ ! "$4" =~ ^[0-9a-f]{32}$ ]]; then
    dms_approval_withdraw_usage
    return 64
  fi
  self_dir="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
  app_dir="$(cd -P -- "${self_dir}/.." && pwd -P)"
  authority="${app_dir}/deploy/production-host.conf"
  lib="${self_dir}/lib/release-approval.sh"
  for file in "$lib" "${self_dir}/lib/trusted-path.sh" "$authority"; do
    if ! why="$(dms_approval_withdraw_trusted "$uid" "$file")"; then
      echo "release-approval-withdraw: refusing to read ${file}: ${why}" >&2
      return 2
    fi
  done
  # shellcheck source=lib/release-approval.sh
  source "$lib"
  by="${SUDO_USER:-}"
  [[ "$by" =~ ^[a-z][a-z0-9-]*$ ]] || by="unknown"
  dms_release_approval_withdraw "$authority" "$app_dir" "$uid" "$2" "$4" "$(date -u +%s)" "$by" || rc=$?
  return "$rc"
}

# Sourced by the tests for its functions; executed as root by the administrator.
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  if [ "$(id -u)" != "0" ]; then
    echo "release-approval-withdraw: run as root (the administrator, with sudo)" >&2
    exit 2
  fi
  rc=0
  dms_approval_withdraw_main 0 "$@" || rc=$?
  exit "$rc"
fi
