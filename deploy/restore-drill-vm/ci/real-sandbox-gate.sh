#!/usr/bin/env bash
#
# R6-B6 isolated restore drill — the REAL-SANDBOX GATE (owner directive D8). TEST TOOLING on disposable infrastructure.
#
# Runs the drill's own sandbox profile (scripts/lib/restore-sandbox.sh) for real, with synthetic data only, against
# production STAND-INS it creates itself, never against a real service:
#   - a decoy PostgreSQL 16 cluster (trust auth) on a Unix socket in /dev/shm and on TCP 127.0.0.1, first proven
#     reachable from outside the sandbox;
#   - world-readable decoy secrets in $HOME, /dev/shm and /var/tmp, and a 0600 file private to the operator;
#   - an abstract Unix socket in the gate's network namespace.
# Then: (P) arbitrary-code probes inside the unit; (S) the guest agent's replay of synthetic and hostile dumps through
# the host decision; (X) resource exhaustion; (C) cleanup.
#
# Usage: real-sandbox-gate.sh <user|system> <out dir>
#   user    per-user systemd manager; the unit keeps the operator's uid, so identity separation is a LIMIT, not a pass
#   system  system manager with DynamicUser (needs passwordless sudo: a disposable CI VM); identity separation REQUIRED
# Exit 0 only when no check failed.
set -uo pipefail
MODE="${1:?user|system}"
OUT="$(mkdir -p "${2:?out dir}" && cd "$2" && pwd)"
case "$MODE" in user | system) : ;; *) echo "mode must be user or system"; exit 2 ;; esac
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
# shellcheck source=/dev/null
. "$REPO/scripts/lib/restore-integrity.sh"
# shellcheck source=/dev/null
. "$REPO/scripts/lib/restore-sandbox.sh"
# shellcheck source=/dev/null
. "$REPO/scripts/lib/restore-vm.sh"
PGBIN=/usr/lib/postgresql/16/bin
RESULTS="$OUT/results-real-sandbox-gate-${MODE}.txt"
: > "$RESULTS"
PASS=0; FAIL=0; LIMIT=0
RAND="$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')"
DPORT=55471
DSHM="/dev/shm/dmsgate-decoy-$RAND"; DHOME="$HOME/.dmsgate-decoy-$RAND"; DVAR="/var/tmp/dmsgate-decoy-$RAND"
DWORK="$(mktemp -d)"; ABSTRACT="dmsgate-abstract-$RAND"; ABS_PID=""
if [ "$MODE" = system ]; then RUNNER=(sudo -n); SCOPE=(); else RUNNER=(); SCOPE=(--user); fi
exec {NAPFD}<> <(:)
nap() { read -rt "$1" -u "$NAPFD" || true; }
check() { if [ "$3" -eq 0 ]; then PASS=$((PASS + 1)); echo "PASS   $1  $2  [$4]" >> "$RESULTS"; else FAIL=$((FAIL + 1)); echo "FAIL   $1  $2  [$4]" >> "$RESULTS"; fi; }
limit() { LIMIT=$((LIMIT + 1)); echo "LIMIT  $1  $2  [$3]" >> "$RESULTS"; }
is() { [ "$1" = "$2" ]; echo $?; }
starts() { case "$1" in "$2"*) echo 0 ;; *) echo 1 ;; esac; }
units_left() { systemctl "${SCOPE[@]}" list-units --all --plain --no-legend 'dmsdrill-*' 2> /dev/null | wc -l; }
DRILL_DIRS=()
cleanup_all() {
  [ -n "$ABS_PID" ] && kill "$ABS_PID" 2> /dev/null
  [ "${#DRILL_DIRS[@]}" -eq 0 ] || "${RUNNER[@]}" rm -rf -- "${DRILL_DIRS[@]}"
  "$PGBIN/pg_ctl" -D "$DWORK/data" -m immediate stop > /dev/null 2>&1 || true
  rm -rf -- "$DWORK" "$DSHM" "$DHOME" "$DVAR"
}
trap cleanup_all EXIT

# --- Stand-ins, proven reachable from OUTSIDE the sandbox ------------------------------------------------------------
mkdir -p "$DSHM" "$DHOME" "$DVAR"
chmod 0755 "$DSHM" "$DHOME" "$DVAR"
for f in "$DSHM/secret.txt" "$DHOME/secret.txt" "$DVAR/secret.txt"; do echo "DECOY-SECRET-$RAND" > "$f"; chmod 0644 "$f"; done
echo "PRIVATE-$RAND" > "$DHOME/private.txt"; chmod 0600 "$DHOME/private.txt"
"$PGBIN/initdb" -D "$DWORK/data" -U decoy_super --auth=trust -E UTF8 --locale=C.UTF-8 > "$DWORK/initdb.log" 2>&1
"$PGBIN/pg_ctl" -D "$DWORK/data" -l "$DWORK/pg.log" -w -o "-k $DSHM -c listen_addresses=127.0.0.1 -p $DPORT" start > /dev/null
"$PGBIN/psql" -X -q -h "$DSHM" -p "$DPORT" -U decoy_super -d postgres -c "CREATE TABLE secret (v text); INSERT INTO secret VALUES ('DECOY-SECRET-$RAND')" > /dev/null
python3 -I -c "
import socket
s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM); s.bind('\0$ABSTRACT'); s.listen(4)
while True:
    c, _ = s.accept(); c.close()
" &
ABS_PID=$!
nap 0.5
h1="$("$PGBIN/psql" -X -Atq -h "$DSHM" -p "$DPORT" -U decoy_super -d postgres -c 'SELECT v FROM secret')"
h2="$("$PGBIN/psql" -X -Atq -h 127.0.0.1 -p "$DPORT" -U decoy_super -d postgres -c 'SELECT v FROM secret')"
check H1 "the decoy cluster is reachable from outside the sandbox (socket and TCP)" "$([ "$h1" = "DECOY-SECRET-$RAND" ] && [ "$h2" = "$h1" ]; echo $?)" "socket+tcp"

# --- P: arbitrary native code inside a unit built from the drill's own profile ---------------------------------------
NAME="$(dms_sandbox_unit_name)"
: > "$DWORK/none.dump"; : > "$DWORK/none.sql"; chmod 0644 "$DWORK/none.dump" "$DWORK/none.sql"
mapfile -t PROPS < <(dms_sandbox_properties "$MODE" "$NAME" 256 768 64 300 "$REPO/scripts/restore-drill-sandbox-inner.sh" "$DWORK/none.dump" "$DWORK/none.sql")
# Test-only additions for the probe itself: python may execute, the probe file is bound read-only.
PROPS+=(-p "ExecPaths=/usr/bin/python3.12 /usr/lib/python3.12/lib-dynload" -p "BindReadOnlyPaths=$HERE/probe.py:/opt/dmsdrill/probe.py")
"${RUNNER[@]}" systemd-run "${SCOPE[@]}" --wait --pipe --collect --quiet --unit="$NAME" "${PROPS[@]}" \
  -- /usr/bin/python3.12 -I /opt/dmsdrill/probe.py "$DHOME/secret.txt" "$DSHM" "$DVAR/secret.txt" "$DPORT" "$ABSTRACT" "$$" "$DHOME/private.txt" \
  < /dev/null 2> "$OUT/probe.err" | head -c 16384 > "$OUT/probe.txt"
pv() { sed -n "s/^PROBE $1=//p" "$OUT/probe.txt" | head -n 1; }
for k in read_home_secret read_shm_secret read_vartmp_secret stat_system_pg_socket stat_run_postgresql stat_dbus_system_bus unix_decoy_pg_socket read_etc_shadow unix_abstract_host write_root write_usr write_etc write_tmp write_tmp_32k exec_usr_bin_id exec_file_written_to_srv exec_file_written_to_dev_shm mmap_exec_file_in_srv mmap_anon_rwx dlopen_file_in_srv unshare_user_ns unshare_mount_ns mount_syscall setuid_root ptrace_attach_parent io_uring_inet_decoy io_uring_vsock memfd_exec_id add_key read_cgroup_root dev_pts_entries write_proc_sys write_sys read_proc_1_environ; do
  check "P-$k" "refused inside the unit" "$(starts "$(pv "$k")" blocked_)" "$(pv "$k")"
done
for k in inet_tcp_loopback_decoy inet6_tcp_loopback_decoy inet_udp_socket netlink_socket packet_socket metadata_service; do
  check "P-$k" "no address family but AF_UNIX" "$(is "$(pv "$k")" blocked_EAFNOSUPPORT)" "$(pv "$k")"
done
check P-write_srv "the drill tmpfs is the writable place" "$(is "$(pv write_srv)" open_written)" "$(pv write_srv)"
check P-capeff "no capabilities, no_new_privs, seccomp" "$([ "$(pv status_capeff)" = open_0000000000000000 ] && [ "$(pv status_nonewprivs)" = open_1 ] && [ "$(pv status_seccomp)" = open_2 ]; echo $?)" "$(pv status_capeff) $(pv status_nonewprivs) $(pv status_seccomp)"
UID_IN="$(pv uid)"
if [ "$MODE" = system ]; then
  check P-uid "DynamicUser: a uid of the dynamic range, not the operator's" "$([[ "${UID_IN#open_}" =~ ^[0-9]+$ ]] && [ "${UID_IN#open_}" -ge 61184 ] && [ "${UID_IN#open_}" -le 65519 ]; echo $?)" "$UID_IN"
  check P-private "the operator's 0600 file is unreadable (identity separation)" "$(is "$(pv read_operator_private_file)" blocked_EACCES)" "$(pv read_operator_private_file)"
  check P-signal "the operator's process cannot be signalled" "$(is "$(pv signal0_harness_pid)" blocked_EPERM)" "$(pv signal0_harness_pid)"
  check P-proc "the operator's process is invisible in /proc" "$(starts "$(pv proc_operator_visible)" blocked_)" "$(pv proc_operator_visible)"
else
  limit P-uid "per-user mode keeps the operator's uid (identity separation needs system mode)" "$UID_IN"
  limit P-private "same uid: the operator's 0600 file" "$(pv read_operator_private_file)"
  limit P-signal "same uid: signal 0 to the operator's process" "$(pv signal0_harness_pid)"
fi

# --- S: the guest agent restores STRUCTURED archives (PR-6), decided by the host from per-table counts (PR-5) ---------
FX="$REPO/tests/Fixtures/restore-drill"
# shellcheck source=/dev/null
. "$REPO/scripts/lib/restore-archive.sh"
drill() { # <label> <archive> [extension allowlist] [tmpfs MiB] [timeout s] -> prints decision; facts in $OUT/<label>.facts
  local label="$1" archive="$2" exts="${3:-none}" tmpfs="${4:-256}" timeout="${5:-300}" d et ed es run ps pb pk piv te
  d="$(mktemp -d)"; chmod 0755 "$d"; DRILL_DIRS+=("$d")
  dms_archive_count_program > "$d/count.awk"
  "$PGBIN/pg_restore" --section=data --restrict-key=gatek -f - "$archive" | awk -f "$d/count.awk" > "$d/counts" || { echo "FAIL:host_count"; return 0; }
  et="$(wc -l < "$d/counts")"; ed="$(dms_archive_digest "$d/counts")"
  # P4P-1: the catalog shape from the TOC (an archive the host would refuse still drives the guest here: inner layer).
  "$PGBIN/pg_restore" -l "$archive" > "$d/toc"
  te=""; [ "$exts" = none ] || te="$exts"
  dms_archive_toc_validate "$d/toc" "app_runtime dms_owner" "$te" > /dev/null; es="$DMS_ARCHIVE_SHAPE"
  run="$(dms_sandbox_unit_name)"
  cp -- "$archive" "$d/payload"
  pb="$(stat -c %s "$d/payload")"; ps="$(sha256sum "$d/payload" | cut -d' ' -f1)"
  read -r pk piv < <(dms_vm_input_key_iv)
  # shellcheck disable=SC2034
  declare -A IN=([run_id]="$run" [payload_bytes]="$pb" [payload_sha256]="$ps" [payload_key]="$pk" [payload_iv]="$piv"
    [roles]="app_runtime dms_owner" [runtime_role]=app_runtime [extension_allowlist]="$exts" [locale]=C.UTF-8 [env_pins]=none
    [tmpfs_mib]="$tmpfs" [memory_mib]=768 [tasks]=64 [timeout_seconds]="$timeout")
  dms_vm_input_build "$d/input.raw" "$d/payload" IN
  head -c "$DMS_VM_OUTPUT_BYTES" /dev/zero > "$d/output.raw"
  chmod 0644 "$d/input.raw" "$d/output.raw"
  "${RUNNER[@]}" bash "$REPO/scripts/restore-drill-vm-guest.sh" "$d/input.raw" "$d/output.raw" "$MODE" > /dev/null 2>&1
  dms_vm_output_parse "$d/output.raw" || true
  for k in "${!DMS_VM_OUTPUT[@]}"; do echo "$k=${DMS_VM_OUTPUT[$k]}"; done | sort > "$OUT/${label}.facts"
  dms_vm_decision_safe "$(dms_vm_decide 0 "$run" "$ps" "$ed" "$et" "$es")"
  "${RUNNER[@]}" rm -rf -- "$d"
}
fact() { sed -n "s/^$2=//p" "$OUT/$1.facts" | head -n 1; }
# P5-1: no dump function exists while the restore evaluates anything, so code-bearing archives fail with 42883.
undef() { [ "$(fact "$1" sandbox_replay_sqlstate)" = 42883 ] && [ "$(fact "$1" sandbox_replay_section)" = "$2" ] && [ "${3%%:*}" = FAIL ]; echo $?; }
d="$(drill S-ok "$FX/clean.dump")"
check S-ok "a synthetic runtime-owned archive restores: GO from the host's per-table digest" "$(is "$d" GO)" "$d tables=$(fact S-ok sandbox_base_table_count)"
d="$(drill S-bomb "$FX/bomb.dump")"
check S-bomb "an index-expression restore bomb fails: its function does not exist when the index is built (PR-2, P5-1)" "$(undef S-bomb post_data "$d")" "$d"
d="$(drill S-rls "$FX/rls.dump")"
check S-rls "row-security policy code never runs: the policy cannot be created before the functions (PR-5, P5-1)" "$(undef S-rls post_data "$d")" "$d"
d="$(drill S-meta "$FX/metabody.dump")"
check S-meta "a backslash line inside a function body stays text (D5)" "$(is "$d" GO)" "$d"
d="$(drill S-ext-no "$FX/ext.dump")"
check S-ext "an extension outside the allowlist is refused inside the unit too (PR-8)" "$([ "$(fact S-ext-no sandbox_stage)" = extension_not_allowlisted ] && [ "${d%%:*}" = FAIL ]; echo $?)" "$d"
d="$(drill S-ext-yes "$FX/ext.dump" pgcrypto)"
check S-ext-ok "an allowlisted extension is created by the bootstrap superuser and the drill is GO (P4P-1(b))" "$(is "$d" GO)" "$d"
# R6-B8: production's extension, allowlisted for the production-compatible image; refused when it is not allowlisted.
d="$(drill S-pgstat-no "$FX/pgstat.dump")"
check S-pgstat-no "pg_stat_statements outside the allowlist is refused inside the unit (R6-B8, PR-8)" "$([ "$(fact S-pgstat-no sandbox_stage)" = extension_not_allowlisted ] && [ "${d%%:*}" = FAIL ]; echo $?)" "$d"
d="$(drill S-pgstat "$FX/pgstat.dump" pg_stat_statements)"
check S-pgstat "pg_stat_statements allowlisted: created by the bootstrap superuser, the drill is GO (R6-B8)" "$(is "$d" GO)" "$d"
d="$(drill S-checkgen "$FX/checkgen.dump")"
check S-checkgen "a CHECK or generated column naming dump code fails before any code runs (P4V-1, P5-1)" "$(undef S-checkgen pre_data "$d")" "$d"
d="$(drill S-dangling "$FX/dangling.dump")"
check S-dangling "pre-data code that would drop its own function never runs (P4P-1, P5-1)" "$(undef S-dangling pre_data "$d")" "$d"
d="$(drill S-launder "$FX/launder.dump" pgcrypto)"
check S-launder "pre-data code that would launder itself into an extension never runs (P4P-1(b), P5-1)" "$(undef S-launder pre_data "$d")" "$d"
d="$(drill S-execbomb "$FX/execbomb.dump")"
check S-execbomb "a PL/pgSQL index expression never runs during the index build (P5-1)" "$(undef S-execbomb post_data "$d")" "$d"
d="$(drill S-vanish "$FX/vanish.dump")"
check S-vanish "self-erasing pre-data code (review round 5) never runs (P5-1)" "$(undef S-vanish pre_data "$d")" "$d"
d="$(drill S-r6 "$FX/r6owner.dump")"
check S-r6 "runtime-owned objects are counted from the restored catalog (P5, PR-3)" "$([ "$(fact S-r6 sandbox_runtime_owned_objects)" -gt 0 ]; echo $?)" "runtime_owned=$(fact S-r6 sandbox_runtime_owned_objects)"

# --- X: resource exhaustion stays inside the unit (hostile archives built here, synthetic data only) ------------------
"$PGBIN/psql" -X -q -h "$DSHM" -p "$DPORT" -U decoy_super -d postgres -c "CREATE ROLE app_runtime" -c "CREATE DATABASE xfill OWNER app_runtime" -c "CREATE DATABASE xtime OWNER app_runtime" > /dev/null
"$PGBIN/psql" -X -q -h "$DSHM" -p "$DPORT" -U decoy_super -d xfill > /dev/null << 'SQL'
SET ROLE app_runtime;
CREATE TABLE migrations (id int PRIMARY KEY);
INSERT INTO migrations VALUES (1);
CREATE TABLE fill (t text);
INSERT INTO fill SELECT md5(random()::text) || md5(random()::text) FROM generate_series(1, 1500000);
SQL
"$PGBIN/psql" -X -q -h "$DSHM" -p "$DPORT" -U decoy_super -d xtime > /dev/null << 'SQL'
SET ROLE app_runtime;
CREATE TABLE migrations (id int PRIMARY KEY);
INSERT INTO migrations VALUES (1);
-- No dump code can run any more (P5-1), so a long restore is plain volume: rows and a builtin expression index.
CREATE TABLE heavy (t text);
INSERT INTO heavy SELECT md5(i::text) FROM generate_series(1, 1500000) AS i;
CREATE INDEX heavy_x ON heavy (md5(md5(md5(t))));
SQL
"$PGBIN/pg_dump" -h "$DSHM" -p "$DPORT" -U decoy_super -d xfill -Fc -f "$DWORK/xfill.dump"
"$PGBIN/pg_dump" -h "$DSHM" -p "$DPORT" -U decoy_super -d xtime -Fc -f "$DWORK/xtime.dump"
s=$(date +%s)
d="$(drill X-disk "$DWORK/xfill.dump" none 64)"
check X-disk "a restore that outgrows the capped tmpfs ends as FAIL, quickly" "$([ "${d%%:*}" = FAIL ] && [ $(( $(date +%s) - s )) -lt 180 ]; echo $?)" "$d secs=$(( $(date +%s) - s ))"
s=$(date +%s)
# A control pair: the same archive is GO with a generous limit and FAIL under a 3 s limit, so the limit is the cause.
d="$(drill X-time-control "$DWORK/xtime.dump" none 512 300)"
check X-time-control "the volume archive restores within a generous time limit (control)" "$(is "$d" GO)" "$d"
s=$(date +%s)
d="$(drill X-time "$DWORK/xtime.dump" none 512 3)"
e=$(( $(date +%s) - s ))
check X-time "the same restore is stopped at a 3 s unit time limit (TimeoutStartSec, oneshot) as FAIL" "$([ "${d%%:*}" = FAIL ] && [ -z "$(fact X-time sandbox_result)" ] && [ "$e" -lt 90 ]; echo $?)" "$d secs=${e}"

# --- G, W, Q: the runner, signer, witness and QEMU identities (VR-4, Phase 3, review round 6); only in system mode, on
# the drill-host layout provision-drill-host.sh installed. LIMIT (CI): the CI operator also has passwordless root
# (GitHub's own sudoers rule), so these checks prove the mechanism (accounts, file modes, sudoers, caller checks,
# unit properties), not a boundary against the operator; that is reported as a LIMIT, never hidden.
if [ "$MODE" = system ] && [ -d /usr/local/lib/dms-restore-drill/signer ]; then
  # shellcheck source=/dev/null
  . "$REPO/scripts/lib/restore-evidence.sh"
  # shellcheck source=/dev/null
  . "$REPO/scripts/lib/restore-vm.sh"
  RUNR=dmsdrill-runner; SIG=dmsdrill-signer; WIT=dmsdrill-witness; QEU=dmsdrill-qemu; L=/usr/local/lib/dms-restore-drill
  SCMD="$L/signer/restore-drill-evidence-signer.sh"; WCMD="$L/witness/restore-drill-evidence-witness.sh"
  KEY=/var/lib/dmsdrill-signer/signing-key
  limit G0 "CI: the operator has passwordless root here, so identity separation is shown as a mechanism only" "github_hosted_sudo"
  # R6F-E3: an outcome carries its start's start time, as the orchestrator's does.
  SAT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  req() { # <decision> <run id> [guest per-table digest]
    local h; h="$(printf 'd%.0s' {1..64})"
    printf 'DMS_DRILL_EVIDENCE_RUN_ID=%s\nDMS_DRILL_EVIDENCE_OPERATOR=%s\nDMS_DRILL_EVIDENCE_DRILL_HOST=%s\nDMS_DRILL_EVIDENCE_STARTED_AT=%s\nDMS_DRILL_EVIDENCE_COMPLETED_AT=%s\n' \
      "$2" "$(id -un)" "$(hostname | tr -cd 'A-Za-z0-9._-' | head -c 64)" "$SAT" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    if [ "$1" = STARTED ]; then
      printf 'DMS_DRILL_EVIDENCE_BACKUP_SHA256=none\nDMS_DRILL_EVIDENCE_BACKUP_BYTES=0\nDMS_DRILL_EVIDENCE_DIGEST_SOURCE=none\nDMS_DRILL_EVIDENCE_MANIFEST_ID=none\nDMS_DRILL_EVIDENCE_MANIFEST_SHA256=none\nDMS_DRILL_EVIDENCE_IMAGE_SHA256=none\n'
      printf 'DMS_DRILL_EVIDENCE_LIMITS=%s\nDMS_DRILL_EVIDENCE_HOST_VERIFIED=none\nDMS_DRILL_EVIDENCE_EXPECTED=none\nDMS_DRILL_EVIDENCE_GUEST_ASSERTED=none\nDMS_DRILL_EVIDENCE_DECISION=STARTED\n' "$DMS_DRILL_EVIDENCE_LIMITS_D6"
      return 0
    fi
    printf 'DMS_DRILL_EVIDENCE_BACKUP_SHA256=%s\nDMS_DRILL_EVIDENCE_BACKUP_BYTES=1\nDMS_DRILL_EVIDENCE_DIGEST_SOURCE=expected-sha256\nDMS_DRILL_EVIDENCE_MANIFEST_ID=gate-manifest-1\nDMS_DRILL_EVIDENCE_MANIFEST_SHA256=%s\nDMS_DRILL_EVIDENCE_IMAGE_SHA256=%s\n' \
      "$(printf 'a%.0s' {1..64})" "$(printf 'b%.0s' {1..64})" "$(printf 'c%.0s' {1..64})"
    printf 'DMS_DRILL_EVIDENCE_LIMITS=%s\nDMS_DRILL_EVIDENCE_HOST_VERIFIED=%s\n' "$DMS_DRILL_EVIDENCE_LIMITS_D6" "$DMS_DRILL_EVIDENCE_HOST_VERIFIED_FACTS"
    # F3 (contract 5): the expectation names the environment contract and its environment digest; the guest's is equal.
    printf 'DMS_DRILL_EVIDENCE_EXPECTED=tables=2,rows=6,per_table_digest=%s,catalog_shape=1:2:0:2:0:0:1,env_contract=%s,env=%s\n' "$h" "$(printf 'e%.0s' {1..64})" "$(printf 'f%.0s' {1..64})"
    printf 'DMS_DRILL_EVIDENCE_GUEST_ASSERTED=restore=ok,tables=2,per_table_digest=%s,catalog_shape=1:2:0:2:0:0:1,user_code=0,user_code_executed=0,fidelity=0:2:1:0,vm_ifaces=lo,env=%s\n' "${3:-$h}" "$(printf 'f%.0s' {1..64})"
    printf 'DMS_DRILL_EVIDENCE_DECISION=%s\n' "$1"
  }
  # The runner identity (which only ever runs the installed orchestrator on a drill host) asks the signer.
  sign() { sudo -n -u "$RUNR" -- sudo -n -u "$SIG" -- "$SCMD" sign 2>&1; }
  as() { local u="$1"; shift; sudo -n -u "$u" -- "$@" 2>&1; }
  # R6F-E1: only the runner may ask the signer to verify; the operator has no route to the signer at all.
  vrf() { sudo -n -u "$RUNR" -- sudo -n -u "$SIG" -- "$SCMD" verify 2>&1; }
  RUN1="$(dms_sandbox_unit_name)"
  r="$(req GO "$RUN1" | sign)"
  check G8 "an outcome without an open STARTED record is refused (contract 5, R6C-3)" "$([ "${r#*no open STARTED}" != "$r" ]; echo $?)" "$(printf '%s' "$r" | head -c 90)"
  r="$(req STARTED "$RUN1" | sign)"; r2="$(req GO "$RUN1" | sign)"
  check G1 "through the runner identity: a STARTED record, then the run's witnessed GO, from a verified root install" "$([ "${r#RECORD }" != "$r" ] && [ "${r2#RECORD }" != "$r2" ] && [ "${r2#* witnessed}" != "$r2" ]; echo $?)" "$(printf '%s | %s' "${r:0:30}" "${r2:0:40}")"
  rec2="$(sudo cat /var/lib/dmsdrill-signer/store/000002/record 2> /dev/null)"
  check G1-install "the signer recorded the install it verified itself: code and configuration digests" "$([[ "$rec2" =~ DMS_DRILL_EVIDENCE_INSTALL=root:[0-9a-f]{64},config:[0-9a-f]{64} ]]; echo $?)" "$(printf '%s' "$rec2" | grep -m1 INSTALL | head -c 60)"
  r="$(req GO "$RUN1" | sign)"
  check G9 "a closed run cannot be recorded again (replay)" "$([ "${r#RECORD }" = "$r" ]; echo $?)" "$(printf '%s' "$r" | head -c 80)"
  RUN2="$(dms_sandbox_unit_name)"; RUN3="$(dms_sandbox_unit_name)"
  r="$(req STARTED "$RUN2" | sign)"; r2="$(req STARTED "$RUN3" | sign)"
  check G10 "a start that never closed is closed by the signer as ABORTED:unclosed before the next start (R6C-3)" "$([ "${r2#*closed ${RUN2}}" != "$r2" ] && sudo grep -rqx 'DMS_DRILL_EVIDENCE_DECISION=ABORTED:unclosed' /var/lib/dmsdrill-signer/store; echo $?)" "$(printf '%s' "$r2" | head -c 100)"
  r="$(req "FAIL:per_table_rows_mismatch" "$RUN3" "$(printf 'e%.0s' {1..64})" | sign)"
  check G5 "a FAIL whose guest digest differs is recorded; a GO like it would be refused (P4V-4)" "$([ "${r#RECORD }" != "$r" ]; echo $?)" "$(printf '%s' "$r" | head -c 80)"
  RUN4="$(dms_sandbox_unit_name)"
  req STARTED "$RUN4" | sign > /dev/null
  r="$(req GO "$RUN4" "$(printf 'e%.0s' {1..64})" | sign)"
  check G5b "the signer refuses a contradictory GO (guest digest differs from the expected one)" "$([ "${r#*refused}" != "$r" ]; echo $?)" "$(printf '%s' "$r" | head -c 80)"
  r="$(req "ABORTED:vm" "$RUN4" | bash -c 'ulimit -f 1; exec sudo -n -u "$0" -- sudo -n -u "$1" -- "$2" sign' "$RUNR" "$SIG" "$SCMD" 2>&1)"
  v="$(vrf)"
  check G6 "a caller's file-size limit never yields a truncated record: refused, or complete and verifying (P4V-5)" "$([ "${v#VERIFIED }" != "$v" ]; echo $?)" "$(printf '%s | %s' "${r:0:40}" "${v:0:40}")"
  RUN5="$(dms_sandbox_unit_name)"
  r="$(req STARTED "$RUN5" | sudo -n -u "$SIG" -- "$SCMD" sign 2>&1)"
  check G2 "the operator itself gets no record: the signer accepts only the runner identity (R6C-1)" "$([ "${r#*caller is not allowed}" != "$r" ] || [ "${r#*different identity}" != "$r" ]; echo $?)" "$(printf '%s' "$r" | head -c 80)"
  check G3 "the operator cannot read the signer's key" "$([ ! -r "$KEY" ]; echo $?)" "$(sudo stat -c '%U %a' "$KEY" 2> /dev/null)"
  r="$(vrf)"
  NREC="$(sudo find /var/lib/dmsdrill-signer/store -mindepth 1 -maxdepth 1 -name '[0-9][0-9][0-9][0-9][0-9][0-9]' | wc -l)"
  check G4 "the signer's store verifies against its head log and EVERY witnessed digest" "$(starts "$r" "VERIFIED ${NREC} ")" "$(printf '%s' "$r" | head -c 80) records=${NREC}"
  r="$(as "$WIT" "$WCMD" show)"
  last="$(sudo sha256sum "/var/lib/dmsdrill-signer/store/$(printf '%06d' "$NREC")/record" | cut -d' ' -f1)"
  check W1 "the operator reads the witness log: one line per record, the newest equal to the store's head" "$([ "$(printf '%s\n' "$r" | wc -l)" = "$NREC" ] && [ "$(printf '%s\n' "$r" | tail -n 1)" = "$(printf '%06d' "$NREC") ${last}" ]; echo $?)" "$(printf '%s' "$r" | tail -n 1 | head -c 80)"
  r="$(as "$WIT" "$WCMD" append "$(printf '%06d' $((NREC + 1)))" "$(printf 'f%.0s' {1..64})")"
  check W2 "the operator cannot append to the witness log (only the signer may)" "$([ "${r#WITNESSED}" = "$r" ]; echo $?)" "$(printf '%s' "$r" | head -c 80)"
  r="$(sudo -n -u "$SIG" -- sudo -n -u "$WIT" -- "$WCMD" append "$(printf '%06d' "$NREC")" "$(printf 'f%.0s' {1..64})" 2>&1)"
  check W3 "even the signer cannot replay or rewrite a witnessed sequence number" "$([ "${r#WITNESSED}" = "$r" ]; echo $?)" "$(printf '%s' "$r" | head -c 80)"
  r="$(as "$QEU" cat "$KEY")"
  check Q1 "the QEMU identity cannot read the signer's key" "$([ "${r#*Permission denied}" != "$r" ]; echo $?)" "$(printf '%s' "$r" | head -c 80)"
  r="$(sudo -n -u "$QEU" -- sudo -n -u "$SIG" -- "$SCMD" verify 2>&1)"
  check Q2 "the QEMU identity cannot reach the signer (no sudoers rule)" "$([ "${r#VERIFIED}" = "$r" ]; echo $?)" "$(printf '%s' "$r" | head -c 80)"
  r="$(as "$QEU" ls /var/lib/dms-restore-drill/runner)"
  check Q3 "the QEMU identity cannot read the runner's work directory" "$([ "${r#*Permission denied}" != "$r" ]; echo $?)" "$(printf '%s' "$r" | head -c 80)"
  r="$(as "$QEU" sh -c 'echo x >> /etc/dms-restore-drill/qemu.conf')"
  check Q4 "the QEMU identity cannot change its own configuration (root-owned)" "$([ "${r#*Permission denied}" != "$r" ]; echo $?)" "$(printf '%s' "$r" | head -c 80)"
  # R6A-1, R6A-2: under the supervisor's exact unit properties, the QEMU account reaches no network, and a process that
  # kills its parent and hides is still gone when the unit ends (the whole cgroup is killed).
  mapfile -t QP < <(dms_vm_qemu_unit_properties "$QEU" /var/lib/dmsdrill-qemu/work 1)
  r="$(sudo systemd-run --system --wait --pipe --collect --quiet --unit=dmsqemu-probe "${QP[@]}" -- /bin/bash -c '{ : 3<> /dev/tcp/1.1.1.1/53; } 2> /dev/null && echo CONNECTED || echo BLOCKED; ls /sys/class/net | tr "\n" " "' 2>&1)"
  check Q5 "the QEMU unit has no network: no outbound connection, loopback only (R6A-2)" "$([ "${r#*BLOCKED}" != "$r" ] && [ "${r#*CONNECTED}" = "$r" ]; echo $?)" "$(printf '%s' "$r" | tr -d '\n' | head -c 80)"
  # R6F-L20: every probe proves it ran inside the unit (RAN), so a unit that never started can never pass.
  ran="$(sudo systemd-run --system --wait --pipe --collect --quiet --unit=dmsqemu-probe "${QP[@]}" -- /bin/bash -c '( setsid sleep 600 & ) ; sleep 1; echo RAN' 2>&1)"
  nap 2
  left="$(pgrep -u "$QEU" -c 2> /dev/null || true)"
  check Q6 "a process that leaves its parent does not outlive the QEMU unit (cgroup kill, R6A-1)" "$([ "${ran#*RAN}" != "$ran" ] && [ "${left:-0}" = 0 ]; echo $?)" "ran=$(printf '%s' "$ran" | tr -d '\n' | head -c 30) qemu_processes_left=${left:-0}"
  # R6F-L2: io_uring (which opens sockets past RestrictAddressFamilies) is refused in the unit, and works outside it.
  iou='import ctypes,os; l=ctypes.CDLL(None,use_errno=True); p=ctypes.create_string_buffer(120); r=l.syscall(425,4,p); print("IOURING_OK" if r>=0 else "IOURING_DENIED errno=%d" % ctypes.get_errno())'
  r="$(sudo systemd-run --system --wait --pipe --collect --quiet --unit=dmsqemu-probe "${QP[@]}" -- /usr/bin/python3 -I -c "$iou" 2>&1)"
  rc="$(sudo -n -u "$QEU" -- /usr/bin/python3 -I -c "$iou" 2>&1)"
  check Q7 "io_uring is refused inside the QEMU unit (it is available outside: the control)" "$([ "${r#*IOURING_DENIED errno=1}" != "$r" ] && [ "${rc#*IOURING_OK}" != "$rc" ]; echo $?)" "unit=$(printf '%s' "$r" | head -c 40) control=$(printf '%s' "$rc" | head -c 20)"
  # R6F-L3: nothing written to /dev/shm survives the unit; the journal and D-Bus sockets are out of reach.
  ran="$(sudo systemd-run --system --wait --pipe --collect --quiet --unit=dmsqemu-probe "${QP[@]}" -- /bin/bash -c 'echo x > /dev/shm/dmsqemu-probe && echo RAN; { echo x > /run/lock/dmsqemu-probe; } 2> /dev/null && echo RUNLOCK_WRITTEN || echo RUNLOCK_REFUSED' 2>&1)"
  check Q8 "a file the QEMU unit writes to /dev/shm is gone with the unit (a private /dev/shm, R6F-L3)" "$([ "${ran#*RAN}" != "$ran" ] && [ ! -e /dev/shm/dmsqemu-probe ]; echo $?)" "ran=$(printf '%s' "$ran" | tr -d '\n' | head -c 40) host_shm_file=$([ -e /dev/shm/dmsqemu-probe ] && echo present || echo absent)"
  check Q11 "the QEMU unit cannot leave a file in the host's /run/lock (/run read-only, R6F-L18)" "$([ "${ran#*RUNLOCK_REFUSED}" != "$ran" ] && [ ! -e /run/lock/dmsqemu-probe ]; echo $?)" "host_run_lock_file=$([ -e /run/lock/dmsqemu-probe ] && echo present || echo absent)"
  r="$(sudo systemd-run --system --wait --pipe --collect --quiet --unit=dmsqemu-probe "${QP[@]}" -- /bin/bash -c 'for s in /run/systemd/journal/socket /run/dbus/system_bus_socket; do [ -e "$s" ] && echo "REACH $s"; done; echo DONE' 2>&1)"
  check Q9 "the QEMU unit reaches neither the journal nor the system D-Bus socket (R6F-L3)" "$([ "${r#*REACH}" = "$r" ] && [ "${r#*DONE}" != "$r" ]; echo $?)" "$(printf '%s' "$r" | tr '\n' ' ' | head -c 80)"
  # R6F-L13: no socket of any kind in the QEMU unit, so no host daemon (systemd-resolved: DNS to the outside) is reachable.
  rsv='import socket
try:
    s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM); s.connect("/run/systemd/resolve/io.systemd.Resolve"); print("RESOLVER_REACHED")
except OSError as e:
    print("RESOLVER_REFUSED errno=%d" % e.errno)'
  r="$(sudo systemd-run --system --wait --pipe --collect --quiet --unit=dmsqemu-probe "${QP[@]}" -- /usr/bin/python3 -I -c "$rsv" 2>&1)"
  rc="$(sudo -n -u "$QEU" -- /usr/bin/python3 -I -c "$rsv" 2>&1)"
  check Q10 "the QEMU unit cannot open any socket: the host resolver (DNS out of the host) is unreachable; reachable outside (control)" "$([ "${r#*RESOLVER_REFUSED}" != "$r" ] && [ "${rc#*RESOLVER_REACHED}" != "$rc" ]; echo $?)" "unit=$(printf '%s' "$r" | head -c 40) control=$(printf '%s' "$rc" | head -c 30)"
  r="$(sudo -n -l -U "$(id -un)" 2>&1 | grep -c "(${SIG})" || true)"
  check G7 "the operator's sudo rules name no signer command (R6F-E1: no route to the signer, not even verify)" "$(is "$r" 0)" "signer_rules=${r}"
  # A compromised SIGNER that drops its newest record and rewrites its own head log to match is caught by the witness.
  sudo -n -u "$SIG" -- sh -c "rm -rf /var/lib/dmsdrill-signer/store/$(printf '%06d' "$NREC") && head -n $((NREC - 1)) /var/lib/dmsdrill-signer/head.log > /var/lib/dmsdrill-signer/head.tmp && mv /var/lib/dmsdrill-signer/head.tmp /var/lib/dmsdrill-signer/head.log"
  r="$(vrf)"
  check W4 "a rollback by the signer's own identity is detected against the witness" "$([ "${r#*rollback or truncation}" != "$r" ]; echo $?)" "$(printf '%s' "$r" | head -c 90)"
  r="$(req STARTED "$(dms_sandbox_unit_name)" | sign)"
  check W5 "and no new record is accepted onto the rolled-back store" "$([ "${r#RECORD }" = "$r" ]; echo $?)" "$(printf '%s' "$r" | head -c 80)"
fi

# --- C: nothing left behind --------------------------------------------------------------------------------------------
nap 1
check C-units "no drill unit is left behind" "$(is "$(units_left)" 0)" "units=$(units_left)"
check C-processes "no drill PostgreSQL process is left behind" "$(is "$(pgrep -f 'postgres -D /srv/data' | wc -l)" 0)" "processes=$(pgrep -f 'postgres -D /srv/data' | wc -l)"

echo "TOTAL mode=${MODE} pass=${PASS} fail=${FAIL} limitation=${LIMIT}" >> "$RESULTS"
cat "$RESULTS"
[ "$FAIL" = 0 ]
