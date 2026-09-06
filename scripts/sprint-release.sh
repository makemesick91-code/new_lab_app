#!/usr/bin/env bash
#
# DEVFLOW-1 — Sprint release wrapper.
#
# A THIN, fail-closed orchestration layer over the existing, hardened runners:
#   - scripts/deploy-vps-runner.sh  (SSH-safe detached deploy + backup + smoke)
#   - scripts/rollback-vps.sh       (rollback to a previous GO tag)
# It does NOT reimplement deploy/backup/smoke. It adds: a single-writer release
# lock, a dry-run default, an explicit --apply gate, pre-release verification
# via `php artisan sprint:release-check`, and evidence capture via
# `php artisan sprint:evidence`.
#
# SAFETY:
#   * Dry-run is the DEFAULT (pass --dry-run to be explicit). Mutation requires --apply.
#   * A GO tag is created ONLY after a successful deploy + smoke, and ONLY with
#     an explicit --tag.
#   * No destructive DB reset command is ever issued here (see the forbidden
#     marker registry in config/devflow.php; the literals are intentionally
#     kept out of this script).
#   * No git force operation.
#   * Credentials are never printed.
#
# Usage:
#   scripts/sprint-release.sh [--dry-run|--apply] [--tag] [--manifest <path>] \
#       [--go-tag <tag>] [--force-lock] [--rollback <tag>]
#
set -euo pipefail

APPLY="false"
DO_TAG="false"
FORCE_LOCK="false"
MANIFEST=".sprint/current.yml"
GO_TAG=""
ROLLBACK_TARGET=""

LOCK_FILE="${DEVFLOW_LOCK_FILE:-storage/framework/devflow-release.lock}"
STALE_AFTER="${DEVFLOW_LOCK_STALE_SECONDS:-3600}"

log()  { printf '[sprint-release] %s\n' "$*"; }
die()  { printf '[sprint-release][FATAL] %s\n' "$*" >&2; exit 1; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run)     APPLY="false"; shift ;;
    --apply)       APPLY="true"; shift ;;
    --tag)         DO_TAG="true"; shift ;;
    --force-lock)  FORCE_LOCK="true"; shift ;;
    --manifest)    MANIFEST="${2:?}"; shift 2 ;;
    --go-tag)      GO_TAG="${2:?}"; shift 2 ;;
    --rollback)    ROLLBACK_TARGET="${2:?}"; shift 2 ;;
    -h|--help)
      grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "unknown argument: $1" ;;
  esac
done

# --- Release lock -----------------------------------------------------------
LOCK_ACQUIRED="false"
cleanup() {
  if [[ "${LOCK_ACQUIRED}" == "true" && -f "${LOCK_FILE}" ]]; then
    rm -f "${LOCK_FILE}" && log "release lock released"
  fi
}
trap cleanup EXIT INT TERM

acquire_lock() {
  mkdir -p "$(dirname "${LOCK_FILE}")"
  if [[ -f "${LOCK_FILE}" ]]; then
    local now age
    now="$(date +%s)"
    age=$(( now - $(stat -c %Y "${LOCK_FILE}" 2>/dev/null || echo "${now}") ))
    if [[ "${FORCE_LOCK}" == "true" ]]; then
      log "OVERRIDE: removing existing release lock (held: $(cat "${LOCK_FILE}" 2>/dev/null || echo unknown))"
    elif (( age > STALE_AFTER )); then
      die "stale release lock present (${age}s old): ${LOCK_FILE}. Inspect it, then re-run with --force-lock."
    else
      die "another release holds the lock: $(cat "${LOCK_FILE}" 2>/dev/null || echo unknown). Wait or use --force-lock."
    fi
  fi
  printf 'sprint=%s pid=%s time=%s\n' "${MANIFEST}" "$$" "$(date -u +%FT%TZ)" > "${LOCK_FILE}"
  LOCK_ACQUIRED="true"
  log "release lock acquired: ${LOCK_FILE}"
}

# --- Rollback path ----------------------------------------------------------
if [[ -n "${ROLLBACK_TARGET}" ]]; then
  [[ -f scripts/rollback-vps.sh ]] || die "rollback runner not found"
  if [[ "${APPLY}" != "true" ]]; then
    log "DRY-RUN rollback to '${ROLLBACK_TARGET}'. Re-run with --apply to execute."
    exit 0
  fi
  acquire_lock
  log "rolling back to '${ROLLBACK_TARGET}' via scripts/rollback-vps.sh"
  bash scripts/rollback-vps.sh "${ROLLBACK_TARGET}"
  exit $?
fi

# --- Release path -----------------------------------------------------------
log "manifest: ${MANIFEST}"

# 1. Pre-release verification (read-only). NO-GO exits non-zero.
log "verifying release readiness (sprint:release-check)"
php artisan sprint:release-check --manifest "${MANIFEST}" || die "sprint:release-check reported NO-GO — aborting."

# Refuse an interactive-REPL invocation BEFORE the deploy runner opens a
# connection to production. Placed here, not in the deploy script, because a
# guard that fires on the far side of the SSH has already lost.
log "checking deploy/release scripts for forbidden production commands"
php artisan deploy:forbidden-command-check || die "forbidden production command found — aborting before anything reaches production."

if [[ "${APPLY}" != "true" ]]; then
  log "DRY-RUN complete. Nothing was mutated. Re-run with --apply to deploy."
  exit 0
fi

# 2. Acquire lock only when actually applying.
acquire_lock

# 3. Deploy via the existing SSH-safe detached runner (backup + deploy + smoke).
[[ -f scripts/deploy-vps-runner.sh ]] || die "deploy runner not found"
log "deploying via scripts/deploy-vps-runner.sh (backup + deploy + smoke)"
if ! bash scripts/deploy-vps-runner.sh run; then
  die "deploy runner did NOT report success — release aborted, NO tag created."
fi
log "deploy runner reported success (deploy + smoke OK)"

# 4. GO tag — ONLY after a successful deploy + smoke, ONLY with --tag.
if [[ "${DO_TAG}" == "true" ]]; then
  TAG="${GO_TAG}"
  if [[ -z "${TAG}" ]]; then
    # Read straight out of the manifest. Booting the interactive REPL to
    # read one YAML key is the forbidden command on the release path.
    TAG="$(sed -n 's/^go_tag:[[:space:]]*//p' "${MANIFEST}" | tail -n1 | tr -d '[:space:]')"
  fi
  [[ -n "${TAG}" ]] || die "no go_tag resolved; pass --go-tag <tag>"
  if git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null; then
    die "GO tag '${TAG}' already exists — refusing to move it."
  fi
  log "creating annotated GO tag '${TAG}' at HEAD"
  git tag -a "${TAG}" -m "DEVFLOW release ${TAG}"
  log "GO tag created. Push it explicitly: git push origin ${TAG}"
else
  log "deploy done. Tag was NOT created (pass --tag to create the GO tag after verifying smoke)."
fi

# 5. Evidence.
log "capturing evidence (sprint:evidence --write)"
php artisan sprint:evidence --manifest "${MANIFEST}" --decision=GO --write || log "evidence capture reported a problem (non-fatal)"

log "release wrapper finished."
