#!/usr/bin/env bash
set -euo pipefail

# POST-ENT Enterprise Foundation Runtime Hardening — deploy evidence timeout runner.
#
# PROBLEM: the ENT-16 deploy hit an SSH broken pipe during the slow VPS
# evidence-capture phase of scripts/deploy-vps.sh. When the SSH pipe dies, a
# foreground `ssh ... 'bash -s' < scripts/deploy-vps.sh` can be torn down
# mid-run, leaving an ambiguous deploy state.
#
# FIX: run the long deploy + evidence phase DETACHED on the server (setsid +
# nohup) so it outlives the SSH connection, streaming to a log file and
# recording the REAL final exit code in a status file. A deploy is NEVER treated
# as GO before scripts/deploy-vps.sh actually finishes with exit=0 (it is
# `set -euo pipefail`, so the mandatory governance gates must pass first).
#
# EVIDENCE (DMS-DEV-INFRA-REMEDIATION-2): a bare `exit=0` is never proof. Every
# run writes a binding record (deploy-<id>.host) holding the host, the deploy
# identifier, the UTC start and finish, the runner's evidence contract and the
# SHA-256 of this program, and the recorded exit code. `status` and `verify`
# accept a success only when the record, the status, the run's OWN log
# (production preflight GO, one pinned target, HEAD == target, DEPLOY OK) and
# the live checkout all agree — see scripts/lib/deploy-runner-evidence.sh.
# Evidence copied from another host (the 2026-11-03 Hostinger → Biznet
# migration copied Hostinger's `latest.status: exit=0`) is UNBOUND or FOREIGN
# and exits 4, never OK.
#
# USAGE (run FROM the app dir on the VPS):
#   bash scripts/deploy-vps-runner.sh start                 # detach and return immediately; prints the deploy id
#   bash scripts/deploy-vps-runner.sh follow                # tail the running log until done, then verify it
#   bash scripts/deploy-vps-runner.sh status                # verify the run latest.status points at
#   bash scripts/deploy-vps-runner.sh verify <deploy-id>    # verify one named run (the id `start` printed)
#   bash scripts/deploy-vps-runner.sh verify-transition <deploy-id> <target-sha>
#                                                           # ONE-TIME check of the first Biznet deploy, launched by
#                                                           # the pre-binding runner (production-host-identity.md §6a)
#   bash scripts/deploy-vps-runner.sh run                   # start + follow + verify (SSH-safe: log survives a dropped pipe)
#
# Recommended remote invocation (survives a dropped SSH pipe). Production is the
# Biznet host reached as the admin account, so the runner runs as root through
# `sudo -n`. Prove the target first (scripts/production-target-check.sh --remote)
# and see docs/runbooks/production-host-identity.md — the frozen Hostinger host and
# its old alias are never a deploy target:
#   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh start'
#   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh follow'
#   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh verify <deploy-id>'

RUNNER_PROGRAM="$(readlink -f -- "${BASH_SOURCE[0]}")"
# The evidence contract ships beside this program; a missing library fails the
# runner loudly (set -e), it never falls back to a weaker check.
# shellcheck source=lib/deploy-runner-evidence.sh
source "$(dirname -- "$RUNNER_PROGRAM")/lib/deploy-runner-evidence.sh"

APP_DIR="${APP_DIR:-/var/www/asia-dental-lab-v2}"
# DEPLOY-HARDEN-1: overridable so the one-time transition (and any future
# recovery) can point the runner at a trusted copy of the deploy program taken
# from a release object, without editing anything inside the checkout. The
# default remains the in-tree canonical path; that script hands itself over to
# the immutable-execution bootstrap before it mutates anything.
DEPLOY_SCRIPT="${DEPLOY_SCRIPT:-scripts/deploy-vps.sh}"
STAMP="$(date +%Y%m%d-%H%M%S)"
RUN_DIR="storage/logs/deploy-runner"
# The run's files are named by its identifier only. They are deliberately NOT
# overridable: the verifier finds a run's status, record and log by identifier,
# so a run written anywhere else could never be proven.
DEPLOY_LOG_FILE="${RUN_DIR}/deploy-${STAMP}.log"
DEPLOY_STATUS_FILE="${RUN_DIR}/deploy-${STAMP}.status"
DEPLOY_HOST_FILE="${RUN_DIR}/deploy-${STAMP}.host"
LATEST_LOG_LINK="${RUN_DIR}/latest.log"
LATEST_STATUS_LINK="${RUN_DIR}/latest.status"
LATEST_HOST_LINK="${RUN_DIR}/latest.host"
AUTHORITY_FILE="${APP_DIR}/deploy/production-host.conf"

cd "$APP_DIR"
mkdir -p "$RUN_DIR"

action="${1:-run}"
if [ "$#" -gt 0 ]; then shift; fi

start_detached() {
  local runner_sha
  read -r runner_sha _ < <(sha256sum -- "$RUNNER_PROGRAM")

  : > "$DEPLOY_LOG_FILE"
  rm -f "$DEPLOY_STATUS_FILE"
  # The binding record. Written BEFORE the deploy starts; the finish lines are
  # appended by the detached subshell BEFORE the status file appears, so a
  # status never exists without its finish evidence.
  {
    printf 'host=%s\n' "$(hostname)"
    printf 'deploy_id=%s\n' "$STAMP"
    printf 'started_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'runner_contract=%s\n' "$DMS_RUNNER_EVIDENCE_CONTRACT"
    printf 'runner_sha256=%s\n' "$runner_sha"
    printf 'program=%s\n' "$DEPLOY_SCRIPT"
  } > "$DEPLOY_HOST_FILE"
  ln -sf "$(basename "$DEPLOY_LOG_FILE")" "$LATEST_LOG_LINK"
  ln -sf "$(basename "$DEPLOY_STATUS_FILE")" "$LATEST_STATUS_LINK"
  ln -sf "$(basename "$DEPLOY_HOST_FILE")" "$LATEST_HOST_LINK"

  # Detach fully: setsid + nohup so a dropped SSH pipe cannot signal the deploy.
  # The subshell records the REAL exit code of scripts/deploy-vps.sh so a broken
  # pipe can never be mistaken for success. Paths are passed as arguments, never
  # spliced into the command string.
  # shellcheck disable=SC2016
  setsid nohup bash -c '
    program="$1"; record="$2"; status="$3"; log="$4"
    {
      bash "$program"; ec=$?
      printf "finished_at=%s\nresult_exit=%s\n" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$ec" >> "$record"
      echo "exit=${ec}" > "${status}.partial" && mv -f -- "${status}.partial" "$status"
    } >> "$log" 2>&1
  ' dms-deploy-runner "$DEPLOY_SCRIPT" "$DEPLOY_HOST_FILE" "$DEPLOY_STATUS_FILE" "$DEPLOY_LOG_FILE" >/dev/null 2>&1 &
  BG_PID=$!
  echo "deploy-runner: started detached pid=${BG_PID}"
  echo "deploy-runner: deploy_id=${STAMP}"
  echo "deploy-runner: program=${DEPLOY_SCRIPT}"
  echo "deploy-runner: log=${DEPLOY_LOG_FILE}"
  echo "deploy-runner: status=${DEPLOY_STATUS_FILE}"
  # DEPLOY-HARDEN-1: starting the launcher is NOT a completed deployment. The
  # authority is a verified run: exit=0, DEPLOY OK and every evidence check.
  echo "deploy-runner: launcher accepted the request — deployment is NOT complete; do NOT treat as GO until 'verify ${STAMP}' reports DEPLOY RUNNER OK"
}

verify_latest() {
  dms_runner_verify_latest "${APP_DIR}/${RUN_DIR}" "$(hostname)" "$AUTHORITY_FILE" "$APP_DIR" "$(date -u +%s)"
}

verify_id() {
  dms_runner_verify_run "${APP_DIR}/${RUN_DIR}" "$1" "$(hostname)" "$AUTHORITY_FILE" "$APP_DIR" "$(date -u +%s)"
}

follow_until_done() {
  local log="${1:-$LATEST_LOG_LINK}"
  local status="${2:-$LATEST_STATUS_LINK}"
  local id="${3:-}"
  echo "deploy-runner: following ${log} (status ${status})"
  # Poll the status file; tail the log so the operator sees progress even if the
  # original SSH pipe already dropped.
  local waited=0
  while [ ! -s "$status" ]; do
    tail -n 5 "$log" 2>/dev/null || true
    sleep 5
    waited=$((waited + 5))
    if [ "$waited" -ge 3600 ]; then
      echo "deploy-runner: WARNING no status after ${waited}s; inspect ${log} manually"
      return 3
    fi
  done
  wait 2>/dev/null || true
  if [ -n "$id" ]; then
    verify_id "$id"
  else
    verify_latest
  fi
}

rc=0
case "$action" in
  start)  start_detached ;;
  follow) follow_until_done || rc=$? ;;
  status) verify_latest || rc=$? ;;
  verify)
    [ "$#" -eq 1 ] || { echo "usage: bash scripts/deploy-vps-runner.sh verify <deploy-id>" >&2; exit 2; }
    verify_id "$1" || rc=$?
    ;;
  verify-transition)
    [ "$#" -eq 2 ] || { echo "usage: bash scripts/deploy-vps-runner.sh verify-transition <deploy-id> <target-sha>" >&2; exit 2; }
    dms_runner_verify_transition "${APP_DIR}/${RUN_DIR}" "$1" "$2" "$(hostname)" "$AUTHORITY_FILE" "$APP_DIR" "$(date -u +%s)" || rc=$?
    ;;
  run)
    start_detached
    follow_until_done "$DEPLOY_LOG_FILE" "$DEPLOY_STATUS_FILE" "$STAMP" || rc=$?
    ;;
  *)
    echo "usage: bash scripts/deploy-vps-runner.sh {start|follow|status|verify <deploy-id>|verify-transition <deploy-id> <target-sha>|run}" >&2
    exit 2
    ;;
esac
exit "$rc"
