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
# USAGE (run FROM the app dir on the VPS):
#   bash scripts/deploy-vps-runner.sh start     # detach and return immediately
#   bash scripts/deploy-vps-runner.sh follow     # tail the running log until done
#   bash scripts/deploy-vps-runner.sh status     # print the final exit status
#   bash scripts/deploy-vps-runner.sh run        # start + follow + report (SSH-safe: log survives a dropped pipe)
#
# Recommended remote invocation (survives a dropped SSH pipe):
#   ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && bash scripts/deploy-vps-runner.sh start'
#   ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && bash scripts/deploy-vps-runner.sh follow'
#   ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && bash scripts/deploy-vps-runner.sh status'

APP_DIR="${APP_DIR:-/var/www/asia-dental-lab-v2}"
# DEPLOY-HARDEN-1: overridable so the one-time transition (and any future
# recovery) can point the runner at a trusted copy of the deploy program taken
# from a release object, without editing anything inside the checkout. The
# default remains the in-tree canonical path; that script hands itself over to
# the immutable-execution bootstrap before it mutates anything.
DEPLOY_SCRIPT="${DEPLOY_SCRIPT:-scripts/deploy-vps.sh}"
STAMP="$(date +%Y%m%d-%H%M%S)"
RUN_DIR="storage/logs/deploy-runner"
DEPLOY_LOG_FILE="${DEPLOY_LOG_FILE:-${RUN_DIR}/deploy-${STAMP}.log}"
DEPLOY_STATUS_FILE="${DEPLOY_STATUS_FILE:-${RUN_DIR}/deploy-${STAMP}.status}"
LATEST_LOG_LINK="${RUN_DIR}/latest.log"
LATEST_STATUS_LINK="${RUN_DIR}/latest.status"

cd "$APP_DIR"
mkdir -p "$RUN_DIR"

action="${1:-run}"

start_detached() {
  : > "$DEPLOY_LOG_FILE"
  rm -f "$DEPLOY_STATUS_FILE"
  ln -sf "$(basename "$DEPLOY_LOG_FILE")" "$LATEST_LOG_LINK"
  ln -sf "$(basename "$DEPLOY_STATUS_FILE")" "$LATEST_STATUS_LINK"

  # Detach fully: setsid + nohup so a dropped SSH pipe cannot signal the deploy.
  # The subshell records the REAL exit code of scripts/deploy-vps.sh so a broken
  # pipe can never be mistaken for success.
  setsid nohup bash -c '
    { bash "'"$DEPLOY_SCRIPT"'"; ec=$?; echo "exit=${ec}" > "'"$DEPLOY_STATUS_FILE"'"; } \
      >> "'"$DEPLOY_LOG_FILE"'" 2>&1
  ' >/dev/null 2>&1 &
  BG_PID=$!
  echo "deploy-runner: started detached pid=${BG_PID}"
  echo "deploy-runner: program=${DEPLOY_SCRIPT}"
  echo "deploy-runner: log=${DEPLOY_LOG_FILE}"
  echo "deploy-runner: status=${DEPLOY_STATUS_FILE}"
  # DEPLOY-HARDEN-1: starting the launcher is NOT a completed deployment. The
  # authority is the recorded exit code plus the DEPLOY OK marker in the log.
  echo "deploy-runner: launcher accepted the request — deployment is NOT complete until exit=0 and DEPLOY OK"
}

follow_until_done() {
  local log="${1:-$LATEST_LOG_LINK}"
  local status="${2:-$LATEST_STATUS_LINK}"
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
  print_status "$status"
}

print_status() {
  local status="${1:-$LATEST_STATUS_LINK}"
  if [ ! -s "$status" ]; then
    echo "deploy-runner: status pending (deploy still running or never started)"
    return 3
  fi
  local line
  line="$(cat "$status")"
  echo "deploy-runner: final ${line}"
  case "$line" in
    "exit=0") echo "DEPLOY RUNNER OK"; return 0 ;;
    *) echo "DEPLOY RUNNER FAILED (${line}) — inspect the log; do NOT treat as GO"; return 1 ;;
  esac
}

case "$action" in
  start)  start_detached ;;
  follow) follow_until_done "$@" ;;
  status) print_status ;;
  run)
    start_detached
    follow_until_done "$DEPLOY_LOG_FILE" "$DEPLOY_STATUS_FILE"
    ;;
  *)
    echo "usage: bash scripts/deploy-vps-runner.sh {start|follow|status|run}" >&2
    exit 2
    ;;
esac
