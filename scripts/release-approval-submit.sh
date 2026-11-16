#!/usr/bin/env bash
#
# DMS-RELEASE-APPROVAL-DIRECT-1 — store ONE owner-signed release approval
# (approval contract 3) on the production host. It never deploys anything.
#
# Reached only through the deploy operator's entrypoint, as root:
#
#   ssh <deploy operator alias> submit-approval --release-id <id> < <bundle>
#
# The bundle (made on the owner's workstation by scripts/release-approval-sign.sh)
# is read from stdin: the canonical record followed by the owner's armored SSH
# signature, at most 8192 bytes. Root verifies all of it — canonical record,
# release id, contract 3 accepted by this host, host, host key, repository,
# branch, scope, approver, validity window, the signature against the release
# trust anchor, release and approval id unspent, no other active approval of
# the release — and only then stores it in the root-only approval store
# (DMS_PRODUCTION_RELEASE_APPROVAL_STORE/<release-id>/<approval-id>.approval,
# .sig, .receipt; 0700 directories, 0400 files, written to temporary names and
# renamed), refusing while a deploy or rollback holds the release lock. The
# deploy itself stays `start --release-id <id> --approved-sha <sha>`,
# which verifies the stored approval again (scripts/lib/release-approval.sh)
# and the required CI of the exact commit.
#
# The library and the authority come from this program's own tree, and are
# read only after this program proves that they and their directories belong
# to the trusted identity and are not group/other-writable (the pattern of
# scripts/sudo-policy-check.sh). Nothing is taken from the environment but
# SUDO_USER, which only names the submitter in the receipt.
#
# Exit codes: 0 stored; 77 DENIED (nothing stored); 64 usage; 2 not root or an
# untrusted tree.
set -euo pipefail
umask 077

dms_approval_submit_usage() {
  echo "usage: submit-approval --release-id <release-id>   (through the deploy operator entrypoint; the signed bundle on stdin)" >&2
}

# dms_approval_submit_trusted <trusted uid> <path> — the path and its directory
# are not symlinks, belong to <trusted uid> and are not group/other-writable.
dms_approval_submit_trusted() {
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

# dms_approval_submit_main <trusted uid> --release-id <id>   (bundle on stdin)
# <trusted uid> is 0 when executed; the tests pass their own uid.
dms_approval_submit_main() {
  local uid="$1" self_dir app_dir authority lib file why submitted_by rc=0
  shift
  if [ "$#" -ne 2 ] || [ "$1" != "--release-id" ] || [[ ! "$2" =~ ^[a-z0-9][a-z0-9-]{2,79}$ ]]; then
    dms_approval_submit_usage
    return 64
  fi
  self_dir="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
  app_dir="$(cd -P -- "${self_dir}/.." && pwd -P)"
  authority="${app_dir}/deploy/production-host.conf"
  lib="${self_dir}/lib/release-approval.sh"
  for file in "$lib" "${self_dir}/lib/trusted-path.sh" "$authority"; do
    if ! why="$(dms_approval_submit_trusted "$uid" "$file")"; then
      echo "release-approval-submit: refusing to read ${file}: ${why}" >&2
      return 2
    fi
  done
  # shellcheck source=lib/release-approval.sh
  source "$lib"
  submitted_by="${SUDO_USER:-}"
  [[ "$submitted_by" =~ ^[a-z][a-z0-9-]*$ ]] || submitted_by="unknown"
  dms_release_approval_submit "$authority" "$app_dir" "$uid" "$2" "$(hostname)" \
    "$(dms_release_approval_observed_host_key "$authority" || true)" "$(date -u +%s)" "$submitted_by" \
    "$(dms_approval_submit_release_lock)" || rc=$?
  return "$rc"
}

# The release lock every deploy and rollback holds for its whole critical
# section — the same root scripts/deploy-immutable-exec.sh picks on this host.
# A submission is refused while it is held, and holds it while it stores.
dms_approval_submit_release_lock() {
  if [ -d /run ] && [ -w /run ]; then
    echo /run/daengtisiams-deploy/release.lock
  else
    echo /var/lock/daengtisiams-deploy/release.lock
  fi
}

# Sourced by the tests for its functions; executed (as root) by the entrypoint.
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  if [ "$(id -u)" != "0" ]; then
    echo "release-approval-submit: run as root, through the deploy operator entrypoint (submit-approval --release-id <id>)" >&2
    exit 2
  fi
  rc=0
  dms_approval_submit_main 0 "$@" || rc=$?
  exit "$rc"
fi
