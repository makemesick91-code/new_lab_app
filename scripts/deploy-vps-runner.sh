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
# TRUSTED EVIDENCE AND SIGNED APPROVALS (rule 231,
# DMS-RELEASE-GUARD-TRUST-HARDENING-1): the evidence lives in the deploy
# identity's own private directory OUTSIDE the checkout
# (DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR, root 0700), never in storage/logs: the
# deploy hands storage/ to the runtime user, who could otherwise plant a symlink
# at a predictable evidence path and have root write through it, or rewrite a
# run's log. Every run claims its identifier with an exclusive create, so two
# starts in the same second never share (or truncate) one run's files. The
# approval must be signed by the approver's key and verify against the host's
# release trust anchor; the record names that key and this host's SSH host key.
#
# EXACT APPROVED SHA (rule 229, DMS-RME-REVISION-PROGRAM-8D-APPROVED-SHA-GUARD-1):
# `start` and `run` deploy ONE named release at ONE exact commit, and only when
# the release approval record merged on the base branch approves exactly that
# commit (scripts/lib/release-approval.sh). The check runs BEFORE this runner
# writes a log, a record or a link and before anything is detached; a refusal
# prints DEPLOYMENT=DENIED and exits 77. The deploy program then re-verifies the
# approval inside the deploy lock before the snapshot, the backup or the
# checkout, and the evidence contract (3) binds the record, the log and the
# checkout to the approved commit.
#
# USAGE (run FROM the app dir on the VPS):
#   bash scripts/deploy-vps-runner.sh start --release-id <id> --approved-sha <40-hex>
#                                                           # detach and return immediately; prints the deploy id
#   bash scripts/deploy-vps-runner.sh follow                # tail the running log until done, then verify it
#   bash scripts/deploy-vps-runner.sh status                # verify the run latest.status points at
#   bash scripts/deploy-vps-runner.sh verify <deploy-id>    # verify one named run (the id `start` printed)
#   bash scripts/deploy-vps-runner.sh verify-transition <deploy-id> <target-sha>
#                                                           # ONE-TIME check of the first Biznet deploy, launched by
#                                                           # the pre-binding runner (production-host-identity.md §6a);
#                                                           # reads the legacy storage/logs/deploy-runner only
#   bash scripts/deploy-vps-runner.sh trust-check           # read-only: trust anchor, host key, evidence directory
#   bash scripts/deploy-vps-runner.sh run --release-id <id> --approved-sha <40-hex>
#                                                           # start + follow + verify (SSH-safe: log survives a dropped pipe)
#
# Recommended remote invocation (survives a dropped SSH pipe). Production is the
# Biznet host reached as the admin account, so the runner runs as root through
# `sudo -n`. Prove the target first (scripts/production-target-check.sh --remote)
# and see docs/runbooks/production-host-identity.md — the frozen Hostinger host and
# its old alias are never a deploy target:
#   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh start --release-id <id> --approved-sha <40-hex>'
#   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh follow'
#   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh verify <deploy-id>'

RUNNER_PROGRAM="$(readlink -f -- "${BASH_SOURCE[0]}")"
# The evidence contract ships beside this program; a missing library fails the
# runner loudly (set -e), it never falls back to a weaker check.
# shellcheck source=lib/deploy-runner-evidence.sh
RUNNER_DIR="$(dirname -- "$RUNNER_PROGRAM")"
source "${RUNNER_DIR}/lib/deploy-runner-evidence.sh"
# shellcheck source=lib/release-approval.sh
source "${RUNNER_DIR}/lib/release-approval.sh"

APP_DIR="${APP_DIR:-/var/www/asia-dental-lab-v2}"
# The deploy program is the one shipped BESIDE this runner, never a path from
# the environment: in the checkout that is scripts/deploy-vps.sh, and for the
# one-time rule 229 bootstrap it is the copy in the bundle exported from the
# approved release object (production-host-identity.md §6c), so the whole
# pre-mutation chain comes from one trusted source. That program hands itself
# over to the immutable-execution bootstrap before it mutates anything.
DEPLOY_SCRIPT="${RUNNER_DIR}/deploy-vps.sh"
# The authority the approval guard reads comes from the same trusted source.
APPROVAL_AUTHORITY_FILE="${RUNNER_DIR}/../deploy/production-host.conf"
RELEASE_ID=""
APPROVED_SHA=""
STAMP=""
# Rule 231: the evidence directory is declared by the SAME trusted authority
# (beside this program), never by the environment and never under the
# checkout. It is validated (owner, 0700, canonical, trusted parents) before
# anything is read from or written to it.
RUN_DIR="$(dms_runner_authority_value "$APPROVAL_AUTHORITY_FILE" DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR)"
# The legacy, runtime-writable location of contract 1-3 evidence; read only by
# the one-time verify-transition of the first Biznet deploy.
LEGACY_RUN_DIR="${APP_DIR}/storage/logs/deploy-runner"
DEPLOY_UID="$(id -u)"
# The run's files are named by its identifier only. They are deliberately NOT
# overridable: the verifier finds a run's status, record and log by identifier,
# so a run written anywhere else could never be proven.
DEPLOY_LOG_FILE=""
DEPLOY_STATUS_FILE=""
DEPLOY_HOST_FILE=""
LATEST_LOG_LINK="${RUN_DIR}/latest.log"
LATEST_STATUS_LINK="${RUN_DIR}/latest.status"
LATEST_HOST_LINK="${RUN_DIR}/latest.host"
AUTHORITY_FILE="${APP_DIR}/deploy/production-host.conf"

cd "$APP_DIR"

action="${1:-run}"
if [ "$#" -gt 0 ]; then shift; fi

parse_release_args() {
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --release-id)   RELEASE_ID="${2:-}"; shift 2 || shift ;;
      --approved-sha) APPROVED_SHA="${2:-}"; shift 2 || shift ;;
      *) echo "deploy-runner: unknown argument: $1" >&2; echo "DEPLOYMENT=DENIED"; exit 77 ;;
    esac
  done
}

# Rule 229 — verify the merged release approval BEFORE any launch artifact
# exists. Nothing is written, linked or detached for an unapproved target.
approve_or_deny() {
  local branch rc=0
  if [ -z "$RELEASE_ID" ] || [ -z "$APPROVED_SHA" ]; then
    echo "deploy-runner: a deploy needs --release-id <id> and --approved-sha <full 40-character commit SHA> (rule 229)" >&2
    echo "DEPLOYMENT=DENIED"
    exit 77
  fi
  branch="$(dms_release_approval_authority_value "$APPROVAL_AUTHORITY_FILE" DMS_PRODUCTION_DEPLOY_BRANCH)"
  dms_release_approval_verify "$APP_DIR" "$branch" "$RELEASE_ID" "$APPROVED_SHA" \
    "$APPROVAL_AUTHORITY_FILE" "$(hostname)" "$(date -u +%s)" \
    "$(dms_release_approval_observed_host_key "$APPROVAL_AUTHORITY_FILE" || true)" "$DEPLOY_UID" || rc=$?
  if [ "$rc" -ne 0 ] || [ "${DMS_RELEASE_APPROVED_SHA:-}" != "$APPROVED_SHA" ]; then
    echo "deploy-runner: refused before launch — no log, record or process was created"
    exit 77
  fi
}

# Rule 231 — the evidence directory must be the deploy identity's own private
# directory; it is created (0700) on first use and never chmod-ed into trust.
trusted_run_dir_or_deny() {
  local why
  if [ -z "$RUN_DIR" ]; then
    echo "deploy-runner: the authority ${APPROVAL_AUTHORITY_FILE} declares no DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR — refusing to guess where evidence goes (rule 231)" >&2
    echo "DEPLOYMENT=DENIED"
    exit 77
  fi
  if ! why="$(dms_trusted_dir_prepare "deploy evidence directory" "$RUN_DIR" "$DEPLOY_UID" "$APP_DIR")"; then
    echo "deploy-runner: ${why} (rule 231)" >&2
    echo "deploy-runner: refused before launch — no log, record or process was created"
    echo "DEPLOYMENT=DENIED"
    exit 77
  fi
}

# Rule 231 — never start a second run while the launcher of another one is
# alive. The deploy lock remains the authority (the bootstrap still answers
# BUSY, exit 75); this refusal just keeps a doomed run from being recorded.
refuse_live_run() {
  local rec id pid
  for rec in "$RUN_DIR"/deploy-*.host; do
    [ -f "$rec" ] && [ ! -L "$rec" ] || continue
    id="$(dms_runner_kv "$rec" deploy_id)"
    dms_runner_valid_id "$id" || continue
    [ -s "${RUN_DIR}/deploy-${id}.status" ] && continue
    pid="$(dms_runner_kv "$rec" launcher_pid)"
    if dms_runner_launcher_alive "$pid" "$(dms_runner_kv "$rec" launcher_start)" "$DEPLOY_UID"; then
      echo "deploy-runner: BUSY deploy ${id} is still running (launcher pid ${pid}) — follow it with 'follow' or 'verify ${id}'; nothing was started" >&2
      exit 75
    fi
  done
}

# Rule 231 — claim a run identifier with an exclusive create. The record is the
# claim: `set -C` refuses an existing name, a dangling symlink included, so a
# second start in the same second takes the next identifier instead of
# truncating this run's files.
claim_run_id() {
  local tries=0
  while :; do
    STAMP="$(date +%Y%m%d-%H%M%S)"
    DEPLOY_LOG_FILE="${RUN_DIR}/deploy-${STAMP}.log"
    DEPLOY_STATUS_FILE="${RUN_DIR}/deploy-${STAMP}.status"
    DEPLOY_HOST_FILE="${RUN_DIR}/deploy-${STAMP}.host"
    # Any existing entry under this identifier — file, symlink (dangling too),
    # FIFO — makes the identifier taken: `set -C` alone would still open an
    # existing non-regular file.
    if [ ! -e "$DEPLOY_HOST_FILE" ] && [ ! -L "$DEPLOY_HOST_FILE" ] \
       && [ ! -e "$DEPLOY_LOG_FILE" ] && [ ! -L "$DEPLOY_LOG_FILE" ] \
       && [ ! -e "$DEPLOY_STATUS_FILE" ] && [ ! -L "$DEPLOY_STATUS_FILE" ] \
       && (umask 077 && set -C && : > "$DEPLOY_HOST_FILE") 2>/dev/null; then
      if ! (umask 077 && set -C && : > "$DEPLOY_LOG_FILE") 2>/dev/null; then
        # Our own, still empty claim: never leave a record that reads as "pending".
        rm -f -- "$DEPLOY_HOST_FILE"
        echo "deploy-runner: evidence for deploy ${STAMP} already exists in ${RUN_DIR} — refusing to overwrite it" >&2
        echo "DEPLOYMENT=DENIED"
        exit 77
      fi
      return 0
    fi
    tries=$((tries + 1))
    if [ "$tries" -ge 5 ]; then
      echo "deploy-runner: could not claim a free deploy identifier in ${RUN_DIR}" >&2
      echo "DEPLOYMENT=DENIED"
      exit 77
    fi
    sleep 1
  done
}

start_detached() {
  local runner_sha boot_id host_key
  approve_or_deny
  trusted_run_dir_or_deny
  refuse_live_run
  read -r runner_sha _ < <(sha256sum -- "$RUNNER_PROGRAM")
  boot_id="$(dms_trusted_boot_id || true)"
  host_key="${DMS_RELEASE_APPROVAL_HOST_KEY:-}"

  claim_run_id
  # The binding record. Written BEFORE the deploy starts; the finish lines are
  # appended by the detached subshell BEFORE the status file appears, so a
  # status never exists without its finish evidence. Appended to the record
  # claim_run_id just created, never truncating anything.
  {
    printf 'host=%s\n' "$(hostname)"
    printf 'deploy_id=%s\n' "$STAMP"
    printf 'started_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'runner_contract=%s\n' "$DMS_RUNNER_EVIDENCE_CONTRACT"
    printf 'runner_sha256=%s\n' "$runner_sha"
    printf 'program=%s\n' "$DEPLOY_SCRIPT"
    printf 'evidence_dir=%s\n' "$RUN_DIR"
    printf 'host_key_sha256=%s\n' "$host_key"
    printf 'boot_id=%s\n' "$boot_id"
    printf 'release_id=%s\n' "$RELEASE_ID"
    printf 'approved_sha=%s\n' "$APPROVED_SHA"
    printf 'approval_record=%s:%s\n' "$DMS_RELEASE_APPROVAL_TIP" "$DMS_RELEASE_APPROVAL_PATH"
    printf 'approval_blob=%s\n' "$DMS_RELEASE_APPROVAL_BLOB"
    printf 'approval_signature_blob=%s\n' "$DMS_RELEASE_APPROVAL_SIGNATURE_BLOB"
    printf 'approval_signer_key=%s\n' "$DMS_RELEASE_APPROVAL_SIGNER_KEY"
  } >> "$DEPLOY_HOST_FILE"
  ln -sfn "$(basename "$DEPLOY_LOG_FILE")" "$LATEST_LOG_LINK"
  ln -sfn "$(basename "$DEPLOY_STATUS_FILE")" "$LATEST_STATUS_LINK"
  ln -sfn "$(basename "$DEPLOY_HOST_FILE")" "$LATEST_HOST_LINK"

  # Detach fully: setsid + nohup so a dropped SSH pipe cannot signal the deploy.
  # The subshell records the REAL exit code of scripts/deploy-vps.sh so a broken
  # pipe can never be mistaken for success. Paths are passed as arguments, never
  # spliced into the command string.
  # shellcheck disable=SC2016
  setsid nohup bash -c '
    program="$1"; record="$2"; status="$3"; log="$4"; release="$5"; approved="$6"
    {
      bash "$program" --release-id "$release" --approved-sha "$approved"; ec=$?
      printf "finished_at=%s\nresult_exit=%s\n" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$ec" >> "$record"
      echo "exit=${ec}" > "${status}.partial" && mv -f -- "${status}.partial" "$status"
    } >> "$log" 2>&1
  ' dms-deploy-runner "$DEPLOY_SCRIPT" "$DEPLOY_HOST_FILE" "$DEPLOY_STATUS_FILE" "$DEPLOY_LOG_FILE" "$RELEASE_ID" "$APPROVED_SHA" >/dev/null 2>&1 &
  BG_PID=$!
  # The launcher's pid lets `verify`/`follow` tell a run that is still going
  # from one whose launcher died (INTERRUPTED) without ever writing a status.
  printf 'launcher_pid=%s\nlauncher_start=%s\n' "$BG_PID" "$(dms_runner_proc_start "$BG_PID" || true)" >> "$DEPLOY_HOST_FILE"
  echo "deploy-runner: started detached pid=${BG_PID}"
  echo "deploy-runner: deploy_id=${STAMP}"
  echo "deploy-runner: program=${DEPLOY_SCRIPT}"
  echo "deploy-runner: release_id=${RELEASE_ID} approved_sha=${APPROVED_SHA}"
  echo "deploy-runner: log=${DEPLOY_LOG_FILE}"
  echo "deploy-runner: status=${DEPLOY_STATUS_FILE}"
  # DEPLOY-HARDEN-1: starting the launcher is NOT a completed deployment. The
  # authority is a verified run: exit=0, DEPLOY OK and every evidence check.
  echo "deploy-runner: launcher accepted the request — deployment is NOT complete; do NOT treat as GO until 'verify ${STAMP}' reports DEPLOY RUNNER OK"
}

require_run_dir() {
  if [ -z "$RUN_DIR" ]; then
    echo "deploy-runner: the authority ${APPROVAL_AUTHORITY_FILE} declares no DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR (rule 231)" >&2
    echo "DEPLOY RUNNER STATUS UNVERIFIED — do NOT treat as GO"
    exit 4
  fi
}

verify_latest() {
  require_run_dir
  dms_runner_verify_latest "$RUN_DIR" "$(hostname)" "$AUTHORITY_FILE" "$APP_DIR" "$(date -u +%s)" \
    "$(dms_release_approval_observed_host_key "$AUTHORITY_FILE" || true)" "" "$DEPLOY_UID"
}

verify_id() {
  require_run_dir
  dms_runner_verify_run "$RUN_DIR" "$1" "$(hostname)" "$AUTHORITY_FILE" "$APP_DIR" "$(date -u +%s)" \
    "$(dms_release_approval_observed_host_key "$AUTHORITY_FILE" || true)" "" "$DEPLOY_UID"
}

# Read-only report of what rule 231 needs on this host. Changes nothing.
trust_check() {
  local anchor fp declared why rc=0
  echo "== Release guard trust check (rule 231, read-only) =="
  anchor="$(dms_runner_authority_value "$APPROVAL_AUTHORITY_FILE" DMS_PRODUCTION_RELEASE_TRUST_ANCHOR)"
  if why="$(dms_trusted_file "release trust anchor" "$anchor" "$DEPLOY_UID" "$APP_DIR")"; then
    echo "TRUST_ANCHOR=OK (${anchor})"
    # An allowed-signers line is `principal [options] keytype key [comment]`;
    # ssh-keygen -l reads keys, so hand it the key part of each line.
    awk '!/^[[:space:]]*(#|$)/ { for (i = 2; i <= NF; i++) if ($i ~ /^(ssh-|ecdsa-|sk-)/) { print $i, $(i + 1), $1; break } }' "$anchor" \
      | ssh-keygen -l -E sha256 -f - 2>/dev/null | sed 's/^/  signer key: /' || true
  else
    echo "TRUST_ANCHOR=NOT-READY — ${why}"
    rc=1
  fi
  declared="$(dms_runner_authority_value "$APPROVAL_AUTHORITY_FILE" DMS_PRODUCTION_HOST_KEY_SHA256)"
  fp="$(dms_release_approval_observed_host_key "$APPROVAL_AUTHORITY_FILE" || true)"
  if [ -n "$fp" ] && [ "$fp" = "$declared" ]; then
    echo "HOST_KEY=OK (${fp})"
  else
    echo "HOST_KEY=MISMATCH — this host presents '${fp:-<unreadable>}', the authority pins '${declared:-<none>}'"
    rc=1
  fi
  if [ -z "$RUN_DIR" ]; then
    echo "EVIDENCE_DIR=NOT-DECLARED"
    rc=1
  elif [ ! -e "$RUN_DIR" ] && [ ! -L "$RUN_DIR" ]; then
    echo "EVIDENCE_DIR=ABSENT (${RUN_DIR}; the first start creates it, 0700)"
  elif why="$(dms_trusted_dir "deploy evidence directory" "$RUN_DIR" "$DEPLOY_UID" "$APP_DIR")"; then
    echo "EVIDENCE_DIR=OK (${RUN_DIR})"
  else
    echo "EVIDENCE_DIR=UNTRUSTED — ${why}"
    rc=1
  fi
  if [ "$rc" -eq 0 ]; then echo "RELEASE GUARD TRUST: READY"; else echo "RELEASE GUARD TRUST: NOT READY"; fi
  return "$rc"
}

follow_until_done() {
  local log="${1:-$LATEST_LOG_LINK}"
  local status="${2:-$LATEST_STATUS_LINK}"
  local id="${3:-}" target pid
  require_run_dir
  if [ -z "$id" ] && [ -L "$LATEST_STATUS_LINK" ]; then
    target="$(readlink -- "$LATEST_STATUS_LINK")"
    [[ "$target" =~ ^deploy-([0-9]{8}-[0-9]{6})\.status$ ]] && id="${BASH_REMATCH[1]}"
  fi
  echo "deploy-runner: following ${log} (status ${status})"
  # Poll the status file; tail the log so the operator sees progress even if the
  # original SSH pipe already dropped. A launcher that died without a status
  # ends the wait at once (verify reports INTERRUPTED).
  local waited=0
  while [ ! -s "$status" ]; do
    tail -n 5 "$log" 2>/dev/null || true
    if [ -n "$id" ]; then
      pid="$(dms_runner_kv "${RUN_DIR}/deploy-${id}.host" launcher_pid)"
      if [ -n "$pid" ] && ! dms_runner_launcher_alive "$pid" "$(dms_runner_kv "${RUN_DIR}/deploy-${id}.host" launcher_start)" "$DEPLOY_UID" \
         && [ ! -s "$status" ]; then
        echo "deploy-runner: the launcher of deploy ${id} is gone and no status was written"
        break
      fi
    fi
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
  start)  parse_release_args "$@"; start_detached ;;
  follow) follow_until_done || rc=$? ;;
  status) verify_latest || rc=$? ;;
  trust-check) trust_check || rc=$? ;;
  verify)
    [ "$#" -eq 1 ] || { echo "usage: bash scripts/deploy-vps-runner.sh verify <deploy-id>" >&2; exit 2; }
    verify_id "$1" || rc=$?
    ;;
  verify-transition)
    [ "$#" -eq 2 ] || { echo "usage: bash scripts/deploy-vps-runner.sh verify-transition <deploy-id> <target-sha>" >&2; exit 2; }
    dms_runner_verify_transition "$LEGACY_RUN_DIR" "$1" "$2" "$(hostname)" "$AUTHORITY_FILE" "$APP_DIR" "$(date -u +%s)" || rc=$?
    ;;
  run)
    parse_release_args "$@"
    start_detached
    follow_until_done "$DEPLOY_LOG_FILE" "$DEPLOY_STATUS_FILE" "$STAMP" || rc=$?
    ;;
  *)
    echo "usage: bash scripts/deploy-vps-runner.sh {start --release-id <id> --approved-sha <sha>|follow|status|verify <deploy-id>|trust-check|verify-transition <deploy-id> <target-sha>|run --release-id <id> --approved-sha <sha>}" >&2
    exit 2
    ;;
esac
exit "$rc"
