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
  local label="$1" archive="$2" exts="${3:-none}" tmpfs="${4:-256}" timeout="${5:-300}" d et ed run ps pb pk piv
  d="$(mktemp -d)"; chmod 0755 "$d"; DRILL_DIRS+=("$d")
  dms_archive_count_program > "$d/count.awk"
  "$PGBIN/pg_restore" --section=data --restrict-key=gatek -f - "$archive" | awk -f "$d/count.awk" > "$d/counts" || { echo "FAIL:host_count"; return 0; }
  et="$(wc -l < "$d/counts")"; ed="$(dms_archive_digest "$d/counts")"
  run="$(dms_sandbox_unit_name)"
  cp -- "$archive" "$d/payload"
  pb="$(stat -c %s "$d/payload")"; ps="$(sha256sum "$d/payload" | cut -d' ' -f1)"
  read -r pk piv < <(dms_vm_input_key_iv)
  # shellcheck disable=SC2034
  declare -A IN=([run_id]="$run" [payload_bytes]="$pb" [payload_sha256]="$ps" [payload_key]="$pk" [payload_iv]="$piv"
    [roles]="app_runtime dms_owner" [runtime_role]=app_runtime [extension_allowlist]="$exts" [locale]=C.UTF-8
    [tmpfs_mib]="$tmpfs" [memory_mib]=768 [tasks]=64 [timeout_seconds]="$timeout")
  dms_vm_input_build "$d/input.raw" "$d/payload" IN
  head -c "$DMS_VM_OUTPUT_BYTES" /dev/zero > "$d/output.raw"
  chmod 0644 "$d/input.raw" "$d/output.raw"
  "${RUNNER[@]}" bash "$REPO/scripts/restore-drill-vm-guest.sh" "$d/input.raw" "$d/output.raw" "$MODE" > /dev/null 2>&1
  dms_vm_output_parse "$d/output.raw" || true
  for k in "${!DMS_VM_OUTPUT[@]}"; do echo "$k=${DMS_VM_OUTPUT[$k]}"; done | sort > "$OUT/${label}.facts"
  dms_vm_decision_safe "$(dms_vm_decide 0 "$run" "$ps" "$ed" "$et")"
  "${RUNNER[@]}" rm -rf -- "$d"
}
fact() { sed -n "s/^$2=//p" "$OUT/$1.facts" | head -n 1; }
d="$(drill S-ok "$FX/clean.dump")"
check S-ok "a synthetic runtime-owned archive restores: GO from the host's per-table digest" "$(is "$d" GO)" "$d tables=$(fact S-ok sandbox_base_table_count)"
d="$(drill S-bomb "$FX/bomb.dump")"
check S-bomb "an index-expression restore bomb is refused: user code on a restore path (PR-2)" "$(is "$d" FAIL:user_code_in_restore_path)" "$d"
d="$(drill S-rls "$FX/rls.dump")"
check S-rls "row-security policy code never runs during verification; verification fails (PR-5)" "$([ "$(fact S-rls sandbox_stage)" = verify ] && [ "${d%%:*}" = FAIL ]; echo $?)" "$d"
d="$(drill S-meta "$FX/metabody.dump")"
check S-meta "a backslash line inside a function body stays text (D5)" "$(is "$d" GO)" "$d"
d="$(drill S-ext-no "$FX/ext.dump")"
check S-ext "an extension outside the allowlist fails the drill after the restore too (PR-8)" "$(is "$d" FAIL:extension_outside_allowlist)" "$d"
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
-- Environment-aware, as an attacker would write it: it stalls only inside the drill's database.
CREATE FUNCTION slow(x int) RETURNS int LANGUAGE plpgsql IMMUTABLE AS $$ BEGIN IF current_database() = 'dms_restore_drill' THEN PERFORM pg_catalog.pg_sleep(600); END IF; RETURN x; END $$;
CREATE INDEX migrations_slow ON migrations (slow(id));
SQL
"$PGBIN/pg_dump" -h "$DSHM" -p "$DPORT" -U decoy_super -d xfill -Fc -f "$DWORK/xfill.dump"
"$PGBIN/pg_dump" -h "$DSHM" -p "$DPORT" -U decoy_super -d xtime -Fc -f "$DWORK/xtime.dump"
s=$(date +%s)
d="$(drill X-disk "$DWORK/xfill.dump" none 64)"
check X-disk "a restore that outgrows the capped tmpfs ends as FAIL, quickly" "$([ "${d%%:*}" = FAIL ] && [ $(( $(date +%s) - s )) -lt 180 ]; echo $?)" "$d secs=$(( $(date +%s) - s ))"
s=$(date +%s)
d="$(drill X-time "$DWORK/xtime.dump" none 256 20)"
check X-time "an endless restore step ends at the unit time limit (TimeoutStartSec, oneshot) as FAIL" "$([ "${d%%:*}" = FAIL ] && [ $(( $(date +%s) - s )) -lt 90 ]; echo $?)" "$d secs=$(( $(date +%s) - s ))"

# --- G: the evidence signer is a separate identity (VR-4); only in system mode, provisioned by the CI job ---------------
if [ "$MODE" = system ] && [ -n "${DMS_GATE_SIGNER_USER:-}" ]; then
  # shellcheck source=/dev/null
  . "$REPO/scripts/lib/restore-evidence.sh"
  req() { printf 'DMS_DRILL_EVIDENCE_RUN_ID=%s\nDMS_DRILL_EVIDENCE_DRILL_HOST=gate\nDMS_DRILL_EVIDENCE_STARTED_AT=%s\nDMS_DRILL_EVIDENCE_COMPLETED_AT=%s\nDMS_DRILL_EVIDENCE_BACKUP_SHA256=%s\nDMS_DRILL_EVIDENCE_BACKUP_BYTES=1\nDMS_DRILL_EVIDENCE_DIGEST_SOURCE=expected-sha256\nDMS_DRILL_EVIDENCE_MANIFEST_ID=gate-manifest-1\nDMS_DRILL_EVIDENCE_MANIFEST_SHA256=%s\nDMS_DRILL_EVIDENCE_IMAGE_SHA256=%s\nDMS_DRILL_EVIDENCE_LIMITS=vcpu=1\nDMS_DRILL_EVIDENCE_HOST_VERIFIED=gate\nDMS_DRILL_EVIDENCE_EXPECTED=gate\nDMS_DRILL_EVIDENCE_GUEST_ASSERTED=gate\nDMS_DRILL_EVIDENCE_DECISION=GO\n' \
    "$(dms_sandbox_unit_name)" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(printf 'a%.0s' {1..64})" "$(printf 'b%.0s' {1..64})" "$(printf 'c%.0s' {1..64})"; }
  r="$(req | sudo -n -u "$DMS_GATE_SIGNER_USER" -- "$DMS_GATE_SIGNER_COMMAND" sign 2>&1)"
  check G1 "the operator obtains a record only through sudo to the signer identity" "$(starts "$r" "RECORD ")" "$(printf '%s' "$r" | head -c 80)"
  r="$(req | DMS_SIGNER_CONF="$DMS_GATE_SIGNER_CONF" bash "$DMS_GATE_SIGNER_COMMAND" sign 2>&1)"
  check G2 "calling the signer directly as the operator is refused" "$([ "${r#*refused}" != "$r" ] || [ "${r#*SIGNER: }" != "$r" ]; echo $?)" "$(printf '%s' "$r" | head -c 80)"
  check G3 "the operator cannot read the signer's key" "$([ ! -r "$DMS_GATE_SIGNER_KEY" ]; echo $?)" "$(stat -c '%U %a' "$DMS_GATE_SIGNER_KEY" 2> /dev/null)"
  r="$(sudo -n -u "$DMS_GATE_SIGNER_USER" -- "$DMS_GATE_SIGNER_COMMAND" verify 2>&1)"
  check G4 "the signer's store verifies against its head log" "$(starts "$r" "VERIFIED ")" "$(printf '%s' "$r" | head -c 80)"
fi

# --- C: nothing left behind --------------------------------------------------------------------------------------------
nap 1
check C-units "no drill unit is left behind" "$(is "$(units_left)" 0)" "units=$(units_left)"
check C-processes "no drill PostgreSQL process is left behind" "$(is "$(pgrep -f 'postgres -D /srv/data' | wc -l)" 0)" "processes=$(pgrep -f 'postgres -D /srv/data' | wc -l)"

echo "TOTAL mode=${MODE} pass=${PASS} fail=${FAIL} limitation=${LIMIT}" >> "$RESULTS"
cat "$RESULTS"
[ "$FAIL" = 0 ]
