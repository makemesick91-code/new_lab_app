# shellcheck shell=bash
#
# DMS-DEV-INFRA-REMEDIATION-2 — deploy runner evidence contract.
#
# Sourced (never executed) by scripts/deploy-vps-runner.sh and by
# scripts/deploy-runner-quarantine-stale-status.sh.
#
# WHY THIS EXISTS. The storage tree was copied from the frozen Hostinger host to
# Biznet in the 2026-11-03 migration, so Biznet's storage/logs/deploy-runner/
# holds Hostinger's last `latest.status: exit=0`. A bare `exit=0` says nothing
# about WHERE, WHEN, WHAT or WHICH PROGRAM ran, so it must never be read as proof
# of a Biznet deploy. This library decides whether a recorded run is evidence:
#
#   1. production host identity — the run was recorded on THIS host, and this
#      host is the production host declared in deploy/production-host.conf
#      (never a frozen legacy host);
#   2. execution timestamp      — a canonical UTC start and finish, in order, not
#      in the future;
#   3. deployment identifier    — the record names the same run as the status file;
#   4. target commit            — exactly one pinned target, the deploy confirmed
#      HEAD == target, and the checkout is still at that target;
#   5. runner version           — the evidence contract version of the runner that
#      launched the run, plus the SHA-256 of that runner program;
#   6. actual execution result  — the recorded exit code agrees with the status,
#      and a success also carries the production preflight GO and DEPLOY OK in
#      the run's OWN log (resolved by identifier, never through latest.log);
#   7. approved release (contract 3, rule 229) — the record names the release
#      and the approved commit the runner verified before launch, and the run's
#      own log shows the bootstrap's approval GO, exactly one approved commit
#      equal to the record's, a pinned target equal to it and HEAD == it.
#
# Anything else is refused with a named verdict and return code 4. Nothing here
# ever reports success for a run it could not prove.
#
# Every observed value (this host, the authority, the checkout, the clock) is a
# PARAMETER, the same convention as scripts/lib/production-host-preflight.sh, so
# the contract is testable without root, a server or a real deploy. There is no
# environment variable that can talk a status past these checks.
#
# Return codes: 0 proven success, 1 proven failure, 3 pending (no status yet),
# 4 not evidence (unbound, foreign, frozen, mismatched, incomplete, stale ...).

# The evidence contract this runner writes and accepts. Contract 1 is the
# pre-binding runner (status file only, no record); contract 2 bound the run to
# the host (DMS-DEV-INFRA-REMEDIATION-2); contract 3 also binds it to the
# approved release (rule 229). A contract-2 record is INCOMPATIBLE: it predates
# the approval and cannot prove the deployed commit was the approved one. Bump
# only together with the writer, the verifier and the tests.
DMS_RUNNER_EVIDENCE_CONTRACT=3

# Clock skew tolerated between a recorded finish time and "now", in seconds.
DMS_RUNNER_CLOCK_SKEW_SECONDS=300

dms_runner_kv() {
  # First value of KEY= in FILE, without a pipe (a pipeline under pipefail can
  # turn SIGPIPE into a silent exit, and a verifier must never die quietly).
  # Keys are fixed identifiers chosen by this library, never caller input.
  local file="$1" key="$2"
  [ -r "$file" ] || return 0
  sed -n "/^${key}=/{s/^${key}=//p;q;}" "$file"
}

dms_runner_authority_value() {
  # The LAST KEY= line of the authority, quotes stripped — the same semantics as
  # dms_preflight_authority_value in scripts/lib/production-host-preflight.sh.
  local file="$1" key="$2" line value
  line="$(grep -E "^${key}=" "$file" 2>/dev/null | tail -n 1)" || true
  value="${line#*=}"
  value="${value%\"}"
  value="${value#\"}"
  printf '%s' "$value"
}

dms_runner_valid_id() {
  [[ "${1:-}" =~ ^[0-9]{8}-[0-9]{6}$ ]]
}

dms_runner_valid_sha() {
  [[ "${1:-}" =~ ^[0-9a-f]{40}$ ]]
}

dms_runner_iso_epoch() {
  # Canonical `YYYY-MM-DDTHH:MM:SSZ` only, and it must round-trip: GNU date
  # normalises impossible dates (2026-02-30) instead of refusing them, so an
  # instant is accepted only when formatting it back reproduces the literal.
  local iso="${1:-}" epoch back
  [[ "$iso" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || return 1
  epoch="$(date -u -d "$iso" +%s 2>/dev/null)" || return 1
  back="$(date -u -d "@${epoch}" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null)" || return 1
  [ "$back" = "$iso" ] || return 1
  printf '%s' "$epoch"
}

dms_runner_in_words() {
  local needle="$1" item
  shift
  # shellcheck disable=SC2048,SC2086
  for item in $*; do
    [ "$item" = "$needle" ] && return 0
  done
  return 1
}

dms_runner_reject() {
  # dms_runner_reject <VERDICT> <reason...>
  local verdict="$1"
  shift
  echo "deploy-runner: $*"
  echo "DEPLOY RUNNER STATUS ${verdict} — do NOT treat as GO"
  return 4
}

dms_runner_count_lines() {
  # Exact count of lines in FILE matching the extended regex PATTERN (0 if none).
  local pattern="$1" file="$2" n
  n="$(grep -c -E -- "$pattern" "$file" 2>/dev/null)" || true
  printf '%s' "${n:-0}"
}

dms_runner_read_status() {
  # Prints the integer exit code of a status file that holds exactly one line
  # `exit=N`. Returns 3 when absent/empty, 4 when malformed.
  local status="$1" line
  [ -s "$status" ] || return 3
  {
    IFS= read -r line || true
    if IFS= read -r _; then
      return 4
    fi
  } < "$status"
  [[ "$line" =~ ^exit=([0-9]{1,3})$ ]] || return 4
  printf '%s' "${BASH_REMATCH[1]}"
}

dms_runner_assert_production_host() {
  # dms_runner_assert_production_host <authority> <this_host>
  local authority="$1" this_host="$2" prod_host frozen_hosts
  if [ -z "$authority" ] || [ ! -r "$authority" ]; then
    dms_runner_reject UNVERIFIED "production host authority ${authority:-<none>} is missing or unreadable — refusing to guess"
    return 4
  fi
  prod_host="$(dms_runner_authority_value "$authority" DMS_PRODUCTION_HOSTNAME)"
  frozen_hosts="$(dms_runner_authority_value "$authority" DMS_FROZEN_LEGACY_HOSTNAMES)"
  if [ -z "$prod_host" ]; then
    dms_runner_reject UNVERIFIED "authority ${authority} declares no production hostname — refusing to guess"
    return 4
  fi
  if dms_runner_in_words "$this_host" "$frozen_hosts"; then
    dms_runner_reject FROZEN "this host '${this_host}' is FROZEN legacy infrastructure — nothing recorded here is production evidence"
    return 4
  fi
  if [ "$this_host" != "$prod_host" ]; then
    dms_runner_reject NOT-PRODUCTION "this host is '${this_host:-<empty>}', the declared production host is '${prod_host}'"
    return 4
  fi
  return 0
}

dms_runner_assert_success_log() {
  # dms_runner_assert_success_log <log> <app_dir> [<expected_target>]
  # Prints the proven target commit on success.
  local log="$1" app_dir="$2" expected="${3:-}" pinned target head
  if [ ! -s "$log" ]; then
    dms_runner_reject UNVERIFIED "the run's own log ${log} is missing or empty — a success cannot be proven without it"
    return 4
  fi
  if [ "$(dms_runner_count_lines '^PRODUCTION HOST PREFLIGHT: GO$' "$log")" -lt 1 ]; then
    dms_runner_reject UNVERIFIED "the run's log carries no 'PRODUCTION HOST PREFLIGHT: GO' — the program that ran never proved this was the production host"
    return 4
  fi
  pinned="$(dms_runner_count_lines '^DEPLOY_TARGET_PINNED=' "$log")"
  if [ "$pinned" != "1" ]; then
    dms_runner_reject UNVERIFIED "the run's log pins ${pinned} target commit(s); exactly one is required"
    return 4
  fi
  target="$(sed -n '/^DEPLOY_TARGET_PINNED=/{s/^DEPLOY_TARGET_PINNED=//p;q;}' "$log")"
  if ! dms_runner_valid_sha "$target"; then
    dms_runner_reject UNVERIFIED "the pinned target '${target}' is not a full commit SHA"
    return 4
  fi
  if [ -n "$expected" ] && [ "$target" != "$expected" ]; then
    dms_runner_reject MISMATCHED "the run deployed ${target}, the expected target is ${expected}"
    return 4
  fi
  if ! grep -qFx -- "DEPLOY_HEAD_TARGET_MATCH=YES (${target})" "$log"; then
    dms_runner_reject UNVERIFIED "the run's log never confirmed HEAD == pinned target ${target}"
    return 4
  fi
  if [ "$(dms_runner_count_lines '^DEPLOY OK: ' "$log")" -lt 1 ]; then
    dms_runner_reject UNVERIFIED "the run's log carries no 'DEPLOY OK' marker"
    return 4
  fi
  # The checkout must still be at the deployed target. Trust for this one
  # repository is scoped to this invocation; nothing persistent is configured.
  if ! head="$(git -c safe.directory="$app_dir" -C "$app_dir" rev-parse --verify --quiet 'HEAD^{commit}' 2>/dev/null)"; then
    dms_runner_reject UNVERIFIED "the checkout HEAD of ${app_dir} could not be read"
    return 4
  fi
  if [ "$head" != "$target" ]; then
    dms_runner_reject STALE "this run deployed ${target}, but the checkout is now at ${head} (rolled back or changed since) — the status no longer describes the running code"
    return 4
  fi
  printf '%s' "$target"
}

dms_runner_assert_approval_blob() {
  # dms_runner_assert_approval_blob <app_dir> <blob> <release_id> <approved_sha>
  # Rule 229: an approval named by evidence must exist as a git object in the
  # (root-owned) object store and approve exactly this release and commit. The
  # run directory is writable by the runtime user; the object store is not.
  local app_dir="$1" blob="$2" release="$3" approved="$4" content
  if ! dms_runner_valid_sha "$blob" \
     || [ "$(git -c safe.directory="$app_dir" -C "$app_dir" cat-file -t "$blob" 2>/dev/null)" != "blob" ]; then
    dms_runner_reject UNVERIFIED "approval blob '${blob}' is not an object in this repository — the evidence cites an approval git never held"
    return 4
  fi
  content="$(git -c safe.directory="$app_dir" -C "$app_dir" cat-file blob "$blob" 2>/dev/null)" || content=""
  if ! grep -qxF -- "DMS_APPROVAL_RELEASE_ID=${release}" <<< "$content" \
     || ! grep -qxF -- "DMS_APPROVAL_TARGET_SHA=${approved}" <<< "$content"; then
    dms_runner_reject MISMATCHED "approval blob ${blob} does not approve release ${release} at ${approved}"
    return 4
  fi
  return 0
}

dms_runner_assert_approval_log() {
  # dms_runner_assert_approval_log <log> <approved_sha> <release_id> <approval_path> <app_dir>
  # Rule 229: the run's own log must show the bootstrap approving exactly the
  # commit the record names, from the same approval file, and that approval must
  # be a real git object approving it. The runner's pre-launch check printed to
  # the operator, not to the log, so these lines come from inside the deploy
  # lock. (The tip may differ from the record's: approvals or docs may land
  # between the runner's check and the bootstrap's.)
  local log="$1" approved="$2" release="$3" approval_path="$4" app_dir="$5" count line log_path log_blob
  if [ "$(dms_runner_count_lines '^RELEASE APPROVAL: GO$' "$log")" -lt 1 ]; then
    dms_runner_reject UNVERIFIED "the run's log carries no 'RELEASE APPROVAL: GO' — the deploy never proved its target was approved"
    return 4
  fi
  count="$(dms_runner_count_lines '^DEPLOY_APPROVED_SHA=' "$log")"
  if [ "$count" != "1" ]; then
    dms_runner_reject UNVERIFIED "the run's log names ${count} approved commit(s); exactly one is required"
    return 4
  fi
  line="$(sed -n '/^DEPLOY_APPROVED_SHA=/{s/^DEPLOY_APPROVED_SHA=//p;q;}' "$log")"
  if [ "$line" != "$approved" ]; then
    dms_runner_reject MISMATCHED "the run's log approved ${line:-<none>}, the record approved ${approved}"
    return 4
  fi
  count="$(dms_runner_count_lines '^DEPLOY_APPROVAL_RECORD=' "$log")"
  line="$(sed -n '/^DEPLOY_APPROVAL_RECORD=/{s/^DEPLOY_APPROVAL_RECORD=//p;q;}' "$log")"
  if [ "$count" != "1" ] || [[ ! "$line" =~ ^[0-9a-f]{40}:([^\ ]+)\ blob=([0-9a-f]{40})$ ]]; then
    dms_runner_reject UNVERIFIED "the run's log does not name exactly one approval record it verified"
    return 4
  fi
  log_path="${BASH_REMATCH[1]}"
  log_blob="${BASH_REMATCH[2]}"
  if [ "$log_path" != "$approval_path" ]; then
    dms_runner_reject MISMATCHED "the run's log verified ${log_path}, the record names ${approval_path}"
    return 4
  fi
  dms_runner_assert_approval_blob "$app_dir" "$log_blob" "$release" "$approved" || return 4
  return 0
}

dms_runner_verify_run() {
  # dms_runner_verify_run <run_dir> <deploy_id> <this_host> <authority> <app_dir> <now_epoch>
  local run_dir="$1" id="$2" this_host="$3" authority="$4" app_dir="$5" now="$6"
  local status record log status_exit rc=0
  local rec_contract rec_host rec_id rec_started rec_finished rec_exit rec_runner_sha
  local started_epoch finished_epoch target rec_release rec_approved rec_record rec_blob rec_path

  if ! dms_runner_valid_id "$id"; then
    dms_runner_reject MALFORMED "deployment identifier '${id}' is not a runner identifier (YYYYmmdd-HHMMSS)"
    return 4
  fi
  status="${run_dir}/deploy-${id}.status"
  record="${run_dir}/deploy-${id}.host"
  log="${run_dir}/deploy-${id}.log"

  status_exit="$(dms_runner_read_status "$status")" || rc=$?
  if [ "$rc" -eq 3 ]; then
    echo "deploy-runner: status pending for deploy ${id} (deploy still running or never started)"
    return 3
  fi
  if [ "$rc" -ne 0 ]; then
    dms_runner_reject MALFORMED "${status} is not a single 'exit=N' line"
    return 4
  fi

  if [ ! -s "$record" ]; then
    echo "deploy-runner: ${status} carries no binding record — it predates host binding, was copied from another host, or was launched by a pre-binding runner; it is NOT evidence of a deploy on ${this_host}"
    echo "DEPLOY RUNNER STATUS UNBOUND — do NOT treat as GO"
    return 4
  fi

  rec_contract="$(dms_runner_kv "$record" runner_contract)"
  rec_host="$(dms_runner_kv "$record" host)"
  rec_id="$(dms_runner_kv "$record" deploy_id)"
  rec_started="$(dms_runner_kv "$record" started_at)"
  rec_finished="$(dms_runner_kv "$record" finished_at)"
  rec_exit="$(dms_runner_kv "$record" result_exit)"
  rec_runner_sha="$(dms_runner_kv "$record" runner_sha256)"
  rec_release="$(dms_runner_kv "$record" release_id)"
  rec_approved="$(dms_runner_kv "$record" approved_sha)"
  rec_record="$(dms_runner_kv "$record" approval_record)"
  rec_blob="$(dms_runner_kv "$record" approval_blob)"

  # 1. Production host identity.
  if [ "$rec_host" != "$this_host" ]; then
    echo "deploy-runner: ${status} was recorded on '${rec_host:-<unknown>}', this host is '${this_host}'"
    echo "DEPLOY RUNNER STATUS FOREIGN — do NOT treat as GO"
    return 4
  fi
  dms_runner_assert_production_host "$authority" "$this_host" || return 4

  # 5. Runner version.
  if [ "$rec_contract" != "$DMS_RUNNER_EVIDENCE_CONTRACT" ]; then
    dms_runner_reject INCOMPATIBLE "the run was recorded under evidence contract '${rec_contract:-<none>}', this verifier requires ${DMS_RUNNER_EVIDENCE_CONTRACT}"
    return 4
  fi
  if [[ ! "$rec_runner_sha" =~ ^[0-9a-f]{64}$ ]]; then
    dms_runner_reject INCOMPLETE "the record does not identify the runner program (runner_sha256)"
    return 4
  fi
  # 7. Approved release (rule 229).
  if [[ ! "$rec_release" =~ ^[a-z0-9][a-z0-9-]{2,79}$ ]] || ! dms_runner_valid_sha "$rec_approved"; then
    dms_runner_reject INCOMPLETE "the record does not name the approved release and commit (release_id, approved_sha)"
    return 4
  fi
  rec_path="deploy/release-approvals/${rec_release}.approval"
  if [[ ! "$rec_record" =~ ^[0-9a-f]{40}:(.+)$ ]] || [ "${BASH_REMATCH[1]}" != "$rec_path" ] || ! dms_runner_valid_sha "$rec_blob"; then
    dms_runner_reject INCOMPLETE "the record does not name the approval it verified for ${rec_release} (approval_record, approval_blob)"
    return 4
  fi

  # 3. Deployment identifier.
  if [ "$rec_id" != "$id" ]; then
    dms_runner_reject MISMATCHED "the record names deploy '${rec_id:-<none>}', the status file is deploy '${id}'"
    return 4
  fi

  # 2. Execution timestamp.
  if ! started_epoch="$(dms_runner_iso_epoch "$rec_started")"; then
    dms_runner_reject INCOMPLETE "the record carries no canonical UTC start time (started_at='${rec_started}')"
    return 4
  fi
  if [ -z "$rec_finished" ] || [ -z "$rec_exit" ]; then
    dms_runner_reject INCOMPLETE "the record carries no finish — the run was interrupted, or the status was written by something other than the runner"
    return 4
  fi
  if ! finished_epoch="$(dms_runner_iso_epoch "$rec_finished")"; then
    dms_runner_reject INCOMPLETE "the record's finish time '${rec_finished}' is not a canonical UTC instant"
    return 4
  fi
  if [ "$finished_epoch" -lt "$started_epoch" ]; then
    dms_runner_reject INCOMPLETE "the record finishes (${rec_finished}) before it starts (${rec_started})"
    return 4
  fi
  if [ "$finished_epoch" -gt $((now + DMS_RUNNER_CLOCK_SKEW_SECONDS)) ]; then
    dms_runner_reject INCOMPLETE "the record finishes in the future (${rec_finished})"
    return 4
  fi

  # 6. Actual execution result — the record and the status must agree.
  if [ "$rec_exit" != "$status_exit" ]; then
    dms_runner_reject MISMATCHED "the record says exit=${rec_exit}, the status file says exit=${status_exit}"
    return 4
  fi

  echo "deploy-runner: deploy_id=${id}"
  echo "deploy-runner: recorded on ${rec_host} (declared production host)"
  echo "deploy-runner: runner_contract=${rec_contract} runner_sha256=${rec_runner_sha}"
  echo "deploy-runner: started_at=${rec_started} finished_at=${rec_finished}"
  echo "deploy-runner: release_id=${rec_release} approved_sha=${rec_approved}"

  if [ "$status_exit" != "0" ]; then
    echo "deploy-runner: final exit=${status_exit}"
    echo "DEPLOY RUNNER FAILED (exit=${status_exit}) — inspect the log; do NOT treat as GO"
    return 1
  fi

  # 4. Target commit, proven from the run's own log and the live checkout. The
  # expected target is the approved commit: a run that pinned anything else is
  # MISMATCHED, whatever else it proves.
  target="$(dms_runner_assert_success_log "$log" "$app_dir" "$rec_approved")" || {
    printf '%s\n' "$target"
    return 4
  }

  # 7. The approval, from the run's own log and from git (after the host and
  # target facts, so a copied foreign log is reported for what it fails first).
  dms_runner_assert_approval_log "$log" "$rec_approved" "$rec_release" "$rec_path" "$app_dir" || return 4
  dms_runner_assert_approval_blob "$app_dir" "$rec_blob" "$rec_release" "$rec_approved" || return 4
  echo "deploy-runner: target_commit=${target} (checkout HEAD matches)"
  echo "DEPLOY_APPROVED_TARGET_MATCH=YES (${target})"
  echo "deploy-runner: final exit=0"
  echo "DEPLOY RUNNER OK"
  return 0
}

dms_runner_verify_latest() {
  # dms_runner_verify_latest <run_dir> <this_host> <authority> <app_dir> <now_epoch>
  local run_dir="$1" link="$1/latest.status" target base id
  if [ ! -e "$link" ] && [ ! -L "$link" ]; then
    echo "deploy-runner: status pending (deploy still running or never started on this host)"
    return 3
  fi
  if [ ! -L "$link" ]; then
    dms_runner_reject MALFORMED "${link} is not a runner link — it was not written by the runner"
    return 4
  fi
  target="$(readlink -- "$link")"
  base="${target##*/}"
  if [ "$target" != "$base" ] || [[ ! "$base" =~ ^deploy-([0-9]{8}-[0-9]{6})\.status$ ]]; then
    dms_runner_reject MALFORMED "${link} points at '${target}', which is not a run in ${run_dir}"
    return 4
  fi
  id="${BASH_REMATCH[1]}"
  dms_runner_verify_run "$run_dir" "$id" "$2" "$3" "$4" "$5"
}

dms_runner_latest_quarantine() {
  # Prints the path of the newest COMPLETE quarantine recorded on this host.
  local run_dir="$1" this_host="$2" marker best="" best_epoch=-1 at epoch on
  for marker in "$run_dir"/quarantine/*/QUARANTINE.complete; do
    [ -f "$marker" ] || continue
    on="$(dms_runner_kv "$marker" quarantined_on)"
    at="$(dms_runner_kv "$marker" quarantined_at)"
    [ "$on" = "$this_host" ] || continue
    epoch="$(dms_runner_iso_epoch "$at")" || continue
    if [ "$epoch" -gt "$best_epoch" ]; then
      best_epoch="$epoch"
      best="$marker"
    fi
  done
  [ -n "$best" ] || return 1
  printf '%s %s' "$best_epoch" "$best"
}

dms_runner_verify_transition() {
  # dms_runner_verify_transition <run_dir> <deploy_id> <expected_target> <this_host> <authority> <app_dir> <now_epoch>
  #
  # The ONE-TIME verification of the first Biznet deploy (owner-selected Option
  # A). That deploy is launched by the runner already in the checkout, which
  # predates the binding record, so its status is UNBOUND by design. It is
  # accepted only when every fact can still be proven: this is the production
  # host, the stale Hostinger evidence was quarantined BEFORE the run finished,
  # the run's own log shows the production preflight GO and the expected pinned
  # target, and the checkout is at that target.
  local run_dir="$1" id="$2" expected="$3" this_host="$4" authority="$5" app_dir="$6" now="$7"
  local status record log status_exit rc=0 q q_epoch q_marker status_epoch target

  if ! dms_runner_valid_id "$id"; then
    dms_runner_reject MALFORMED "deployment identifier '${id}' is not a runner identifier (YYYYmmdd-HHMMSS)"
    return 4
  fi
  if ! dms_runner_valid_sha "$expected"; then
    dms_runner_reject MALFORMED "the expected target '${expected}' is not a full lowercase commit SHA"
    return 4
  fi
  status="${run_dir}/deploy-${id}.status"
  record="${run_dir}/deploy-${id}.host"
  log="${run_dir}/deploy-${id}.log"

  status_exit="$(dms_runner_read_status "$status")" || rc=$?
  if [ "$rc" -eq 3 ]; then
    echo "deploy-runner: status pending for deploy ${id} (deploy still running or never started)"
    return 3
  fi
  if [ "$rc" -ne 0 ]; then
    dms_runner_reject MALFORMED "${status} is not a single 'exit=N' line"
    return 4
  fi
  if [ -e "$record" ]; then
    dms_runner_reject NOT-TRANSITION "deploy ${id} carries a binding record — verify it with 'deploy-vps-runner.sh verify ${id}' instead"
    return 4
  fi

  dms_runner_assert_production_host "$authority" "$this_host" || return 4

  if ! q="$(dms_runner_latest_quarantine "$run_dir" "$this_host")"; then
    dms_runner_reject UNVERIFIED "no completed quarantine of the stale pre-binding evidence is recorded on ${this_host} — the transition is verifiable only after deploy-runner-quarantine-stale-status.sh --apply"
    return 4
  fi
  q_epoch="${q%% *}"
  q_marker="${q#* }"
  status_epoch="$(stat -c %Y -- "$status" 2>/dev/null)" || status_epoch=0
  if [ "$status_epoch" -le "$q_epoch" ]; then
    dms_runner_reject STALE "deploy ${id} finished at or before the quarantine (${q_marker}) — it is pre-migration evidence, not the first Biznet deploy"
    return 4
  fi
  if [ "$status_epoch" -gt $((now + DMS_RUNNER_CLOCK_SKEW_SECONDS)) ]; then
    dms_runner_reject INCOMPLETE "the status of deploy ${id} is dated in the future"
    return 4
  fi

  if [ "$status_exit" != "0" ]; then
    echo "deploy-runner: final exit=${status_exit}"
    echo "DEPLOY RUNNER FAILED (exit=${status_exit}) — inspect the log; do NOT treat as GO"
    return 1
  fi

  if ! grep -qFx -- "PASS: hostname is the production host '${this_host}'" "$log" 2>/dev/null; then
    dms_runner_reject UNVERIFIED "the run's log does not show the production preflight passing on '${this_host}'"
    return 4
  fi
  target="$(dms_runner_assert_success_log "$log" "$app_dir" "$expected")" || {
    printf '%s\n' "$target"
    return 4
  }

  echo "deploy-runner: deploy_id=${id}"
  echo "deploy-runner: host=${this_host} (declared production host, proven by the run's own preflight)"
  echo "deploy-runner: runner_contract=1 (pre-binding runner from the tree before this deploy; this is the one transition run)"
  echo "deploy-runner: finished after quarantine ${q_marker}"
  echo "deploy-runner: target_commit=${target} (pinned, confirmed by the deploy, checkout HEAD matches)"
  echo "deploy-runner: final exit=0"
  echo "TRANSITION DEPLOY VERIFIED — one-time first-deploy evidence; every later deploy must report DEPLOY RUNNER OK"
  return 0
}

dms_runner_run_is_bound() {
  # dms_runner_run_is_bound <run_dir> <deploy_id> <this_host>
  local record="$1/deploy-$2.host"
  [ -s "$record" ] || return 1
  [ "$(dms_runner_kv "$record" runner_contract)" = "$DMS_RUNNER_EVIDENCE_CONTRACT" ] || return 1
  [ "$(dms_runner_kv "$record" host)" = "$3" ] || return 1
  [ "$(dms_runner_kv "$record" deploy_id)" = "$2" ] || return 1
  return 0
}

dms_runner_quarantine() {
  # dms_runner_quarantine <run_dir> <this_host> <now_iso> <apply 0|1>
  #
  # Moves every piece of pre-binding runner evidence (status, log and record of
  # any run without a valid contract-2 record from THIS host, plus latest.*
  # links that do not point at a bound run) into a new
  # quarantine/pre-binding-<stamp>/ directory. Nothing is deleted or rewritten:
  # `mv` on the same filesystem keeps content, inode and timestamps, and a
  # manifest of every file's SHA-256 is written before the move and verified
  # after it. A completion marker (QUARANTINE.complete) is written last, so an
  # interrupted quarantine never counts as done.
  local run_dir="$1" this_host="$2" now_iso="$3" apply="$4"
  local entry name id kind dest stamp manifest sum size mtime link n=0 failures=0
  local -a moves=()

  if [ -L "$run_dir" ]; then
    echo "QUARANTINE REFUSED: ${run_dir} is a symlink — refusing to follow it"
    return 1
  fi
  if [ ! -d "$run_dir" ]; then
    echo "QUARANTINE: ${run_dir} does not exist — nothing to quarantine"
    return 0
  fi
  if ! dms_runner_iso_epoch "$now_iso" >/dev/null; then
    echo "QUARANTINE REFUSED: '${now_iso}' is not a canonical UTC instant"
    return 1
  fi

  for entry in "$run_dir"/deploy-* "$run_dir"/latest.*; do
    [ -e "$entry" ] || [ -L "$entry" ] || continue
    # Runner evidence is files and links only; a directory is never moved.
    if [ -d "$entry" ] && [ ! -L "$entry" ]; then
      continue
    fi
    name="${entry##*/}"
    case "$name" in
      deploy-*.status|deploy-*.log|deploy-*.host)
        id="${name#deploy-}"
        id="${id%.*}"
        if dms_runner_valid_id "$id" && dms_runner_run_is_bound "$run_dir" "$id" "$this_host"; then
          continue
        fi
        ;;
      latest.status|latest.log|latest.host)
        if [ -L "$entry" ]; then
          link="$(readlink -- "$entry")"
          if [[ "$link" =~ ^deploy-([0-9]{8}-[0-9]{6})\.(status|log|host)$ ]] \
            && dms_runner_run_is_bound "$run_dir" "${BASH_REMATCH[1]}" "$this_host"; then
            continue
          fi
        fi
        ;;
      *)
        continue
        ;;
    esac
    moves+=("$name")
  done

  if [ "${#moves[@]}" -eq 0 ]; then
    echo "QUARANTINE: no pre-binding evidence in ${run_dir} — nothing to do"
    return 0
  fi

  stamp="${now_iso//[-:]/}"
  dest="${run_dir}/quarantine/pre-binding-${stamp}"
  for name in "${moves[@]}"; do
    entry="${run_dir}/${name}"
    if [ -L "$entry" ]; then
      echo "would move: ${name} (symlink -> $(readlink -- "$entry"))"
    else
      echo "would move: ${name} ($(stat -c %s -- "$entry") bytes)"
    fi
  done
  echo "QUARANTINE PLAN: ${#moves[@]} entr(y/ies) -> ${dest}"

  if [ "$apply" != "1" ]; then
    echo "QUARANTINE DRY RUN — nothing was moved (re-run with --apply)"
    return 0
  fi

  if [ -e "$dest" ] || [ -L "$dest" ]; then
    echo "QUARANTINE REFUSED: ${dest} already exists"
    return 1
  fi
  if [ -L "${run_dir}/quarantine" ]; then
    echo "QUARANTINE REFUSED: ${run_dir}/quarantine is a symlink"
    return 1
  fi
  [ -d "${run_dir}/quarantine" ] || mkdir -m 0700 "${run_dir}/quarantine"
  mkdir -m 0700 "$dest"
  manifest="${dest}/MANIFEST.tsv"
  printf 'name\tkind\tsize\tmtime_utc\tsha256\tlink_target\n' > "$manifest"
  for name in "${moves[@]}"; do
    entry="${run_dir}/${name}"
    if [ -L "$entry" ]; then
      printf '%s\tsymlink\t-\t-\t-\t%s\n' "$name" "$(readlink -- "$entry")" >> "$manifest"
    else
      read -r sum _ < <(sha256sum -- "$entry")
      size="$(stat -c %s -- "$entry")"
      mtime="$(date -u -d "@$(stat -c %Y -- "$entry")" +%Y-%m-%dT%H:%M:%SZ)"
      printf '%s\tfile\t%s\t%s\t%s\t-\n' "$name" "$size" "$mtime" "$sum" >> "$manifest"
    fi
  done

  for name in "${moves[@]}"; do
    if ! mv -n -- "${run_dir}/${name}" "${dest}/${name}"; then
      echo "QUARANTINE FAILED: could not move ${name}"
      failures=$((failures + 1))
    fi
  done

  # Verify after the move: every file present with its pre-move SHA-256, every
  # link present with its pre-move target, and nothing left behind.
  while IFS=$'\t' read -r name kind size mtime sum link; do
    [ "$name" = "name" ] && continue
    if [ -e "${run_dir}/${name}" ] || [ -L "${run_dir}/${name}" ]; then
      echo "QUARANTINE FAILED: ${name} is still in ${run_dir}"
      failures=$((failures + 1))
      continue
    fi
    if [ "$kind" = "symlink" ]; then
      if [ "$(readlink -- "${dest}/${name}" 2>/dev/null)" != "$link" ]; then
        echo "QUARANTINE FAILED: link ${name} changed in transit"
        failures=$((failures + 1))
      fi
    else
      local moved_sum
      read -r moved_sum _ < <(sha256sum -- "${dest}/${name}" 2>/dev/null || echo missing)
      if [ "$moved_sum" != "$sum" ]; then
        echo "QUARANTINE FAILED: ${name} changed in transit"
        failures=$((failures + 1))
      fi
    fi
    n=$((n + 1))
  done < "$manifest"

  if [ "$failures" -ne 0 ]; then
    echo "QUARANTINE NOT COMPLETE: ${failures} problem(s); no completion marker written — nothing was deleted, inspect ${dest}"
    return 1
  fi

  {
    echo "quarantined_at=${now_iso}"
    echo "quarantined_on=${this_host}"
    echo "evidence_contract=${DMS_RUNNER_EVIDENCE_CONTRACT}"
    echo "entries=${n}"
    echo "reason=pre-binding deploy-runner evidence (copied from the frozen Hostinger host in the 2026-11-03 migration, or written by a pre-binding runner); historical record only, never evidence of a deploy on this host"
  } > "${dest}/QUARANTINE.complete"
  chmod 0600 "$manifest" "${dest}/QUARANTINE.complete"
  echo "QUARANTINE COMPLETE: ${n} entr(y/ies) preserved in ${dest} (manifest verified, nothing deleted)"
  return 0
}
