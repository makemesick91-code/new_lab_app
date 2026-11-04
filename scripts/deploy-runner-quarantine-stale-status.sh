#!/usr/bin/env bash
set -euo pipefail

# DMS-DEV-INFRA-REMEDIATION-2 — quarantine pre-binding deploy-runner evidence
# (owner-selected B-R2 Option A, run ONCE on the production host BEFORE the
# first authorized Biznet deploy).
#
# The 2026-11-03 migration copied the frozen Hostinger host's
# storage/logs/deploy-runner/ to Biznet, including `latest.status: exit=0`. The
# runner already on Biznet predates the binding record and reads that file raw,
# so its `status` reports DEPLOY RUNNER OK for a deploy that never ran there.
# This tool moves every piece of evidence that is not bound to THIS host by a
# contract-2 record — and every latest.* link that does not point at a bound run
# — into storage/logs/deploy-runner/quarantine/pre-binding-<UTC stamp>/.
#
# It NEVER deletes or rewrites a file: same-filesystem `mv` keeps content,
# inode and timestamps; a SHA-256 manifest is written before the move and
# verified after it; a completion marker (QUARANTINE.complete) is written last.
# After it, the old runner reports "status pending" instead of OK, and
# `deploy-vps-runner.sh verify-transition` can prove the first deploy.
#
# Gates, all before anything is touched: positive production host identity
# (deploy/production-host.conf from the SAME trusted bundle as this program),
# refusal of the frozen host, root, and no running deploy or rollback (the
# DEPLOY-HARDEN-1 host lock). Dry run is the default.
#
# Usage, from a trusted copy taken from the merged release object (the tool is
# not in the production checkout until the first deploy) — see
# docs/runbooks/production-host-identity.md §6a:
#   sudo -n bash <bundle>/scripts/deploy-runner-quarantine-stale-status.sh            # dry run
#   sudo -n bash <bundle>/scripts/deploy-runner-quarantine-stale-status.sh --apply    # move (never delete)

SCRIPT_DIR="$(cd -- "$(dirname -- "$(readlink -f -- "${BASH_SOURCE[0]}")")" && pwd)"
BUNDLE_DIR="$(dirname -- "$SCRIPT_DIR")"
# shellcheck source=lib/production-host-preflight.sh
source "${SCRIPT_DIR}/lib/production-host-preflight.sh"
# shellcheck source=lib/deploy-runner-evidence.sh
source "${SCRIPT_DIR}/lib/deploy-runner-evidence.sh"

usage() {
  echo "usage: sudo -n bash scripts/deploy-runner-quarantine-stale-status.sh [--apply]" >&2
  exit 2
}

apply=0
case "${1:-}" in
  "") ;;
  --apply) apply=1 ;;
  *) usage ;;
esac
[ "$#" -le 1 ] || usage

APP_DIR="${APP_DIR:-/var/www/asia-dental-lab-v2}"
AUTHORITY="${BUNDLE_DIR}/deploy/production-host.conf"
RUN_DIR="${APP_DIR}/storage/logs/deploy-runner"

# 1. Where are we, who are we, and can we do the job? The tool list is the one
#    this program calls; it does not need the deploy's build toolchain.
if ! dms_production_host_preflight "$AUTHORITY" "$(id -u)" "$(hostname)" "$(hostname -I 2>/dev/null || true)" \
     mv sha256sum stat date readlink flock; then
  echo "QUARANTINE REFUSED: production host preflight failed — nothing was moved" >&2
  exit 1
fi

# 2. Never race a deploy or a rollback: the same kernel-backed lock the
#    immutable-execution bootstrap holds for the whole run.
DEFAULT_LOCK_DIR="/run/daengtisiams-deploy"
if [ ! -d /run ] || [ ! -w /run ]; then
  DEFAULT_LOCK_DIR="/var/lock/daengtisiams-deploy"
fi
LOCK_DIR="${DMS_DEPLOY_LOCK_DIR:-$DEFAULT_LOCK_DIR}"
if [ -L "$LOCK_DIR" ]; then
  echo "QUARANTINE REFUSED: lock root ${LOCK_DIR} is a symlink" >&2
  exit 1
fi
if [ "$apply" = "1" ]; then
  mkdir -p "$LOCK_DIR"
  chmod 0700 "$LOCK_DIR"
  if [ "$(stat -c '%u' "$LOCK_DIR")" != "$(id -u)" ]; then
    echo "QUARANTINE REFUSED: lock root ${LOCK_DIR} is not owned by this identity" >&2
    exit 1
  fi
  exec 8>"${LOCK_DIR}/deploy.lock"
  exec 7>"${LOCK_DIR}/rollback.lock"
  if ! flock -n 8 || ! flock -n 7; then
    echo "QUARANTINE BUSY: a deploy or rollback is running — nothing was moved" >&2
    exit 75
  fi
elif [ -d "$LOCK_DIR" ]; then
  for role in deploy rollback; do
    if [ -e "${LOCK_DIR}/${role}.lock" ] && ! flock -n "${LOCK_DIR}/${role}.lock" true; then
      echo "QUARANTINE BUSY: a ${role} is running" >&2
      exit 75
    fi
  done
fi

# 3. Plan (dry run) or move (--apply). Nothing is deleted in either mode.
dms_runner_quarantine "$RUN_DIR" "$(hostname)" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$apply"
