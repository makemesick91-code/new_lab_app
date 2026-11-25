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
cleanup_all() {
  [ -n "$ABS_PID" ] && kill "$ABS_PID" 2> /dev/null
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
mapfile -t PROPS < <(dms_sandbox_properties "$MODE" "$NAME" 256 768 64 300 "$REPO/scripts/restore-drill-sandbox-inner.sh")
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

# --- S: the guest agent's replay, through the host decision -----------------------------------------------------------
DUMP_OK="--
-- PostgreSQL database dump
--

\\restrict K1synthetic

SET statement_timeout = 0;
SELECT pg_catalog.set_config('search_path', '', false);
CREATE TABLE public.migrations (id integer NOT NULL, migration character varying(255) NOT NULL);
ALTER TABLE public.migrations OWNER TO app_runtime;
CREATE TABLE public.items (id integer NOT NULL, label text NOT NULL);
ALTER TABLE public.items OWNER TO app_runtime;
COPY public.migrations (id, migration) FROM stdin;
1	synthetic_one
\\.

COPY public.items (id, label) FROM stdin;
1	synthetic item
2	synthetic item
\\.

--
-- PostgreSQL database dump complete
--

\\unrestrict K1synthetic
"
drill() { # <label> <dump text> <restricted 1|0> [tmpfs MiB] [timeout s] -> prints decision; facts in $OUT/<label>.facts
  local label="$1" dump="$2" restricted="$3" tmpfs="${4:-256}" timeout="${5:-300}" d et er run ps pb
  d="$(mktemp -d)"; chmod 0755 "$d"
  printf '%s' "$dump" > "$d/dump.sql"
  read -r et er < <(dms_sandbox_dump_counts "$d/dump.sql")
  run="$(dms_sandbox_unit_name)"
  if [ "$restricted" = 1 ]; then
    dms_restore_restricted_stream "$d/dump.sql" "$(dms_restore_restrict_key)" "g$(dms_restore_restrict_key | cut -c1-32)" > "$d/payload"
  else
    cp "$d/dump.sql" "$d/payload"
  fi
  pb="$(stat -c %s "$d/payload")"; ps="$(sha256sum "$d/payload" | cut -d' ' -f1)"
  # shellcheck disable=SC2034
  declare -A IN=([run_id]="$run" [payload_bytes]="$pb" [payload_sha256]="$ps" [roles]=app_runtime [locale]=C.UTF-8
    [tmpfs_mib]="$tmpfs" [memory_mib]=768 [tasks]=64 [timeout_seconds]="$timeout")
  dms_vm_input_build "$d/input.raw" "$d/payload" IN
  head -c "$DMS_VM_OUTPUT_BYTES" /dev/zero > "$d/output.raw"
  chmod 0644 "$d/input.raw" "$d/output.raw"
  "${RUNNER[@]}" bash "$REPO/scripts/restore-drill-vm-guest.sh" "$d/input.raw" "$d/output.raw" "$MODE" > /dev/null 2>&1
  dms_vm_output_parse "$d/output.raw" || true
  for k in "${!DMS_VM_OUTPUT[@]}"; do echo "$k=${DMS_VM_OUTPUT[$k]}"; done | sort > "$OUT/${label}.facts"
  dms_vm_decide 0 "$run" "$ps" "$pb" "$et" "$er"
  "${RUNNER[@]}" rm -rf -- "$d"
}
fact() { sed -n "s/^$2=//p" "$OUT/$1.facts" | head -n 1; }
d="$(drill S-ok "$DUMP_OK" 1)"
check S-ok "a synthetic runtime-owned dump restores: GO from host-counted completeness" "$(is "$d" GO)" "$d tables=$(fact S-ok sandbox_base_table_count) rows=$(fact S-ok sandbox_rows_total)"
d="$(drill S-super "${DUMP_OK}\\c - drill_super
SELECT 1;
" 0)"
check S-super "a superuser reconnect after the dump's own \\unrestrict fails (bootstrap superuser NOLOGIN)" "$([ "$(fact S-super sandbox_stage)" = replay ] && [ "${d%%:*}" = FAIL ]; echo $?)" "$d"
d="$(drill S-fd3 "${DUMP_OK}\\t on
\\a
\\o /proc/self/fd/3
SELECT 'DRILL result=GO';
" 0)"
check S-fd3 "nothing in the dump reaches the result channel (fd 3 closed)" "$([ "$(fact S-fd3 result_duplicates)" = 0 ] && [ "$(fact S-fd3 result_rejected)" = 0 ] && [ "${d%%:*}" = FAIL ]; echo $?)" "$d"
i=0
for stmt in "COPY (SELECT 1) TO PROGRAM 'id';" "SELECT pg_catalog.pg_read_file('/etc/passwd');" "CREATE ROLE intruder SUPERUSER;" "SELECT pg_catalog.lo_import('/etc/passwd');" "CREATE EXTENSION dblink SCHEMA public;"; do
  i=$((i + 1))
  d="$(drill "S-su$i" "${DUMP_OK/--
-- PostgreSQL database dump complete/$stmt
--
-- PostgreSQL database dump complete}" 1)"
  check "S-su$i" "superuser-only statement refused to the restore role: $stmt" "$([ "$(fact "S-su$i" sandbox_replay_sqlstate)" = 42501 ] && [ "${d%%:*}" = FAIL ]; echo $?)" "$d sqlstate=$(fact "S-su$i" sandbox_replay_sqlstate)"
done

# --- X: resource exhaustion stays inside the unit --------------------------------------------------------------------
s=$(date +%s)
d="$(drill X-disk "${DUMP_OK/--
-- PostgreSQL database dump complete/CREATE TABLE public.fill (t text);
INSERT INTO public.fill SELECT md5(random()::text) || md5(random()::text) FROM generate_series(1, 3000000);
--
-- PostgreSQL database dump complete}" 1 96)"
check X-disk "a disk fill stops at the tmpfs cap, quickly, as FAIL" "$([ "${d%%:*}" = FAIL ] && [ $(( $(date +%s) - s )) -lt 120 ]; echo $?)" "$d secs=$(( $(date +%s) - s ))"
s=$(date +%s)
d="$(drill X-time "${DUMP_OK/--
-- PostgreSQL database dump complete/SELECT pg_catalog.pg_sleep(600);
--
-- PostgreSQL database dump complete}" 1 256 20)"
check X-time "an endless statement ends at RuntimeMaxSec as FAIL" "$([ "${d%%:*}" = FAIL ] && [ $(( $(date +%s) - s )) -lt 60 ]; echo $?)" "$d secs=$(( $(date +%s) - s ))"

# --- C: nothing left behind --------------------------------------------------------------------------------------------
nap 1
check C-units "no drill unit is left behind" "$(is "$(units_left)" 0)" "units=$(units_left)"
check C-processes "no drill PostgreSQL process is left behind" "$(is "$(pgrep -f 'postgres -D /srv/data' | wc -l)" 0)" "processes=$(pgrep -f 'postgres -D /srv/data' | wc -l)"

echo "TOTAL mode=${MODE} pass=${PASS} fail=${FAIL} limitation=${LIMIT}" >> "$RESULTS"
cat "$RESULTS"
[ "$FAIL" = 0 ]
