# shellcheck shell=bash
#
# R6-B6 isolated restore drill (finding A-1) — the sandbox that replays a backup.
#
# Owner directive "OWNER DESIGN DIRECTIVE — R6-B6 ISOLATED RESTORE DRILL" (2026-11-25):
#   D1 the drill runs in a separate, disposable VM (scripts/restore-drill-vm.sh boots it);
#   D2 inside that VM the database drill runs under an ephemeral, unprivileged OS identity in a systemd SYSTEM unit;
#   D4 the replay uses a non-superuser PostgreSQL role; D5 psql restricted mode is mandatory;
#   D6 pilot limits, fail closed when exceeded.
#
# Sourced (never executed) by scripts/restore-drill-vm-guest.sh inside the VM, and by the tests. This file is the
# single source of truth for the unit's isolation profile: every property below is required, and several exist
# because a test proved the weaker form unsafe (F-ISO-1/2, DR-1/2/4/10/12/14/16 in
# docs/architecture/r6-b6-isolated-restore-drill-design.md).
#
# The backup is untrusted CONTENT even when its digest is trusted: before R6-D the runtime role owns the schema and can
# plant code that pg_dump writes faithfully. Everything that runs in the unit is assumed hostile; the caller decides GO
# only from facts it checks itself (dms_sandbox_decide).

if [ -n "${DMS_RESTORE_SANDBOX_LOADED:-}" ]; then
  return 0
fi
DMS_RESTORE_SANDBOX_LOADED=1

DMS_SANDBOX_PG_BINDIR="${DMS_SANDBOX_PG_BINDIR:-/usr/lib/postgresql/16/bin}"
# D6 pilot ceilings. A request above any of them is refused (fail closed), never trimmed.
DMS_SANDBOX_MAX_VCPU=1
DMS_SANDBOX_MAX_MEMORY_MIB=2048
DMS_SANDBOX_MAX_DISK_GIB=10
DMS_SANDBOX_MAX_MINUTES=30
DMS_SANDBOX_MAX_TASKS=64
# Result channel bounds.
DMS_SANDBOX_MAX_RESULT_BYTES=8192
DMS_SANDBOX_MAX_RESULT_LINES=64
DMS_SANDBOX_MAX_STDERR_BYTES=65536
# The in-unit path of the read-only inner script.
DMS_SANDBOX_INNER_TARGET=/opt/dmsdrill/inner.sh

_dms_sandbox_err() { echo "restore-sandbox: $*" >&2; }

# dms_sandbox_limits_check <vcpu> <memory MiB> <disk GiB> <minutes> <tasks>
# D6: returns 0 when every value is a positive integer at or below its pilot ceiling; prints the first violation and
# returns 1 otherwise.
dms_sandbox_limits_check() {
  local names=(vcpu memory_mib disk_gib minutes tasks)
  local maxes=("$DMS_SANDBOX_MAX_VCPU" "$DMS_SANDBOX_MAX_MEMORY_MIB" "$DMS_SANDBOX_MAX_DISK_GIB" "$DMS_SANDBOX_MAX_MINUTES" "$DMS_SANDBOX_MAX_TASKS")
  local i v
  [ "$#" -eq 5 ] || { echo "limits: expected 5 values"; return 1; }
  for i in 0 1 2 3 4; do
    v="$1"
    shift
    if [[ ! "$v" =~ ^[1-9][0-9]{0,6}$ ]]; then echo "limits: ${names[$i]} '${v}' is not a positive integer"; return 1; fi
    if [ "$v" -gt "${maxes[$i]}" ]; then echo "limits: ${names[$i]} ${v} exceeds the pilot ceiling ${maxes[$i]} (D6)"; return 1; fi
  done
  return 0
}

# dms_sandbox_unit_name
# A fresh per-run name, used for the unit AND for DynamicUser's User=: at most 31 characters, so it is a valid user
# name, and random, so it never names an existing account (DR-11; the caller also checks getent).
dms_sandbox_unit_name() {
  local hex
  hex="$(od -An -N6 -tx1 /dev/urandom | tr -d ' \n')" || return 1
  [[ "$hex" =~ ^[0-9a-f]{12}$ ]] || return 1
  printf 'dmsdrill-%s\n' "$hex"
}

# dms_sandbox_role_name_ok <name>
dms_sandbox_role_name_ok() { [[ "${1:-}" =~ ^[a-z_][a-z0-9_]{0,62}$ ]]; }

# dms_sandbox_properties <mode: system|user> <unit/user name> <tmpfs MiB> <memory MiB> <tasks> <timeout seconds> <inner script> <archive> <guard sql>
# Prints the systemd-run arguments, one per line. `system` is the only mode the drill uses (D2: DynamicUser in a
# system unit inside the VM). `user` exists for the local test harness (systemd-run --user, which implies
# PrivateUsers and keeps the caller's uid); it cannot provide identity separation and is never used by the drill.
dms_sandbox_properties() {
  local mode="${1:?mode}" name="${2:?name}" tmpfs="${3:?tmpfs}" mem="${4:?memory}" tasks="${5:?tasks}" timeout="${6:?timeout}" inner="${7:?inner}" archive="${8:?archive}" guard="${9:?guard}"
  case "$mode" in system | user) : ;; *) _dms_sandbox_err "unknown mode '${mode}'"; return 2 ;; esac
  [[ "$name" =~ ^dmsdrill-[0-9a-f]{12}$ ]] || { _dms_sandbox_err "bad unit name"; return 2; }
  local n
  for n in "$tmpfs" "$mem" "$tasks" "$timeout"; do [[ "$n" =~ ^[1-9][0-9]{0,6}$ ]] || { _dms_sandbox_err "bad limit '${n}'"; return 2; }; done
  [ "$tasks" -le "$DMS_SANDBOX_MAX_TASKS" ] || { _dms_sandbox_err "tasks above ${DMS_SANDBOX_MAX_TASKS}"; return 2; }
  [ "$timeout" -le $((DMS_SANDBOX_MAX_MINUTES * 60)) ] || { _dms_sandbox_err "timeout above ${DMS_SANDBOX_MAX_MINUTES} min"; return 2; }
  [ "$mem" -le "$DMS_SANDBOX_MAX_MEMORY_MIB" ] || { _dms_sandbox_err "memory above ${DMS_SANDBOX_MAX_MEMORY_MIB} MiB"; return 2; }
  [ "$tmpfs" -lt "$mem" ] || { _dms_sandbox_err "the tmpfs must be smaller than MemoryMax (its pages are charged to the unit)"; return 2; }
  if [ "$mode" = system ]; then
    printf '%s\n' -p DynamicUser=yes -p "User=${name}" \
      -p "BindReadOnlyPaths=-/run/systemd/userdb/io.systemd.DynamicUser"
  fi
  printf '%s\n' \
    -p NoNewPrivileges=yes -p CapabilityBoundingSet= -p AmbientCapabilities= -p RestrictSUIDSGID=yes \
    -p KeyringMode=private -p UMask=0077 -p LimitCORE=0 -p CoredumpFilter=0 -p LimitNOFILE=1024 \
    -p ProtectSystem=strict -p ProtectHome=yes -p PrivateTmp=yes -p PrivateDevices=yes -p PrivateIPC=yes \
    -p "TemporaryFileSystem=/run:ro" -p "TemporaryFileSystem=/var:ro" -p "TemporaryFileSystem=/opt:ro" \
    -p "TemporaryFileSystem=/mnt:ro" -p "TemporaryFileSystem=/media:ro" \
    -p "TemporaryFileSystem=/etc:ro" \
    -p "BindReadOnlyPaths=/etc/passwd /etc/group /etc/nsswitch.conf /etc/ld.so.cache -/etc/localtime" \
    -p "TemporaryFileSystem=/srv:size=${tmpfs}M,mode=1777,noexec,nosuid,nodev" \
    -p "TemporaryFileSystem=/dev/shm:size=64M,mode=1777,noexec,nosuid,nodev" \
    -p "ReadOnlyPaths=/tmp /var/tmp" \
    -p "InaccessiblePaths=/sys/fs/cgroup /dev/pts" \
    -p "BindReadOnlyPaths=${inner}:${DMS_SANDBOX_INNER_TARGET}" \
    -p "BindReadOnlyPaths=${archive}:/opt/dmsdrill/archive.dump ${guard}:/opt/dmsdrill/guard.sql" \
    -p ProtectKernelTunables=yes -p ProtectKernelModules=yes -p ProtectKernelLogs=yes -p ProtectControlGroups=yes \
    -p ProtectClock=yes -p ProtectHostname=yes -p ProtectProc=invisible -p ProcSubset=pid \
    -p RestrictNamespaces=yes -p LockPersonality=yes -p RestrictRealtime=yes -p MemoryDenyWriteExecute=yes \
    -p SystemCallArchitectures=native -p SystemCallFilter=@system-service \
    -p "SystemCallFilter=~@privileged @resources @mount @swap @reboot @raw-io @module @debug @obsolete @cpu-emulation @clock" \
    -p "SystemCallFilter=~memfd_create io_uring_setup io_uring_enter io_uring_register @keyring" \
    -p RestrictAddressFamilies=AF_UNIX -p PrivateNetwork=yes -p IPAddressDeny=any \
    -p "NoExecPaths=/ /tmp /var/tmp" \
    -p "ExecPaths=${DMS_SANDBOX_PG_BINDIR} ${DMS_SANDBOX_PG_BINDIR%/bin}/lib /usr/lib/x86_64-linux-gnu /usr/lib64 /usr/bin/bash /usr/bin/dash" \
    -p "MemoryMax=${mem}M" -p MemorySwapMax=0 -p "TasksMax=${tasks}" -p CPUQuota=100% -p Nice=10 \
    -p OOMPolicy=stop -p OOMScoreAdjust=800 -p KillMode=control-group -p TimeoutStopSec=10 \
    -p Type=oneshot -p "TimeoutStartSec=${timeout}" \
    --setenv=TMPDIR=/srv "--setenv=DRILL_PG_BINDIR=${DMS_SANDBOX_PG_BINDIR}"
  if [ "$mode" = system ]; then
    printf '%s\n' --setenv=DRILL_EXPECT_DYNAMIC_UID=1
  fi
}

# PR-1: Type=oneshot, because a Type=simple main process killed by SIGTERM counts as success (exit 0); under oneshot
# every terminating signal is a failure (exit 255, proven). RuntimeMaxSec does not apply to oneshot units (proven), so
# the wall-clock limit is TimeoutStartSec.
#
# dms_sandbox_run <mode> <unit name> <raw result file> <stderr file> <roles> <locale> <runtime role> <extensions> <property args…>
# Runs the inner script in a transient unit; stdin is the restricted replay stream. The unit's stdout (the result
# channel) is read up to DMS_SANDBOX_MAX_RESULT_BYTES + 1 into <raw result file> (one byte more than allowed, so an
# overflow is visible to dms_sandbox_parse: VR-5), its stderr up to
# DMS_SANDBOX_MAX_STDERR_BYTES into <stderr file> (DR-14). Prints nothing; returns the unit's exit status.
dms_sandbox_run() {
  local mode="${1:?mode}" unit="${2:?unit}" raw="${3:?raw}" err="${4:?err}" roles="${5-}" locale="${6:?locale}" runtime="${7-}" exts="${8:-none}"
  shift 8
  local -a scope=()
  [ "$mode" = user ] && scope=(--user)
  local r
  for r in $roles; do dms_sandbox_role_name_ok "$r" || { _dms_sandbox_err "bad role name"; return 2; }; done
  [[ "$locale" =~ ^[A-Za-z0-9_.@-]{1,32}$ ]] || { _dms_sandbox_err "bad locale"; return 2; }
  [ -z "$runtime" ] || dms_sandbox_role_name_ok "$runtime" || { _dms_sandbox_err "bad runtime role"; return 2; }
  [[ "$exts" =~ ^(none|[a-z_][a-z0-9_]{0,62}(\ [a-z_][a-z0-9_]{0,62}){0,15})$ ]] || { _dms_sandbox_err "bad extension allowlist"; return 2; }
  # F3: the pinned restore-session settings of the signed environment contract (the inner script validates them again).
  local pins="${DMS_SANDBOX_ENV_PINS:-none}"
  [[ "$pins" =~ ^(none|[A-Za-z_]{1,40}=[A-Za-z0-9_.,/@\ :+-]{1,64}(\;[A-Za-z_]{1,40}=[A-Za-z0-9_.,/@\ :+-]{1,64}){15})$ ]] || { _dms_sandbox_err "bad environment pins"; return 2; }
  local status_file
  status_file="$(mktemp)" || return 2
  {
    systemd-run "${scope[@]}" --wait --pipe --collect --quiet --unit="${unit}" "$@" \
      "--setenv=DRILL_ROLES=${roles}" "--setenv=DRILL_LOCALE=${locale}" "--setenv=DRILL_RUNTIME_ROLE=${runtime}" \
      "--setenv=DRILL_EXTENSIONS=${exts}" "--setenv=DRILL_ENV_PINS=${pins}" \
      -- /usr/bin/bash "$DMS_SANDBOX_INNER_TARGET" < /dev/null 2> >(head -c "$DMS_SANDBOX_MAX_STDERR_BYTES" > "$err")
    echo $? > "$status_file"
  } | head -c $((DMS_SANDBOX_MAX_RESULT_BYTES + 1)) > "$raw"
  local st
  st="$(cat "$status_file" 2> /dev/null)"
  rm -f -- "$status_file"
  [[ "$st" =~ ^[0-9]+$ ]] || st=1
  return "$st"
}

# dms_sandbox_stop <mode> <unit name>
# Stops the unit if it still runs (cleanup on EXIT/TERM/HUP; F-ISO-3: never rely on SIGINT).
dms_sandbox_stop() {
  local -a scope=()
  [ "${1:-}" = user ] && scope=(--user)
  if systemctl "${scope[@]}" is-active --quiet "${2:?unit}.service" 2> /dev/null; then
    systemctl "${scope[@]}" stop "${2}.service" 2> /dev/null || true
  fi
  systemctl "${scope[@]}" reset-failed "${2}.service" 2> /dev/null || true
}

# dms_sandbox_parse <raw result file>
# Strict parse of the result channel: no more than DMS_SANDBOX_MAX_RESULT_BYTES and no NUL byte; only `DRILL
# <key>=<value>` lines with a bounded key and charset, at most
# DMS_SANDBOX_MAX_RESULT_LINES, each key at most once. Fills DMS_SANDBOX_RESULT[key]=value and sets
# DMS_SANDBOX_DUPLICATES and DMS_SANDBOX_REJECTED. Nothing from the raw file is ever printed.
declare -gA DMS_SANDBOX_RESULT=()
DMS_SANDBOX_DUPLICATES=0
DMS_SANDBOX_REJECTED=0
dms_sandbox_parse() {
  local raw="${1:?raw}" line n=0 size clean
  DMS_SANDBOX_RESULT=()
  DMS_SANDBOX_DUPLICATES=0
  DMS_SANDBOX_REJECTED=0
  # VR-5: a result larger than the window, or carrying NUL bytes (which `read` would silently drop), is a forgery
  # attempt (padding that pushes the honest lines out of the window): it counts as rejected, so the decision FAILs.
  size="$(stat -c %s -- "$raw" 2> /dev/null || echo 0)"
  clean="$(tr -d '\000' < "$raw" | wc -c)"
  [ "$size" -le "$DMS_SANDBOX_MAX_RESULT_BYTES" ] || DMS_SANDBOX_REJECTED=$((DMS_SANDBOX_REJECTED + 1))
  [ "$clean" = "$size" ] || DMS_SANDBOX_REJECTED=$((DMS_SANDBOX_REJECTED + 1))
  while IFS= read -r line || [ -n "$line" ]; do
    if [[ "$line" =~ ^DRILL\ ([a-z0-9_]{1,40})=([A-Za-z0-9_.:-]{0,80})$ ]]; then
      if [ -n "${DMS_SANDBOX_RESULT[${BASH_REMATCH[1]}]+x}" ]; then
        DMS_SANDBOX_DUPLICATES=$((DMS_SANDBOX_DUPLICATES + 1))
        continue
      fi
      n=$((n + 1))
      if [ "$n" -gt "$DMS_SANDBOX_MAX_RESULT_LINES" ]; then
        DMS_SANDBOX_REJECTED=$((DMS_SANDBOX_REJECTED + 1))
        continue
      fi
      DMS_SANDBOX_RESULT[${BASH_REMATCH[1]}]="${BASH_REMATCH[2]}"
    else
      DMS_SANDBOX_REJECTED=$((DMS_SANDBOX_REJECTED + 1))
    fi
  done < "$raw"
}

# dms_sandbox_decide <unit exit> <expected per-table digest> <expected table count> <expected catalog shape>
# Prints GO, or FAIL:<reason>. GO only when every condition holds. The per-table digest (PR-5) must equal the digest of
# the row counts the HOST took from the archive's data section before the drill: a forged or partial result cannot
# match it unless it knows and reproduces every table's count, and even then it remains a guest assertion (VR-1).
dms_sandbox_decide() {
  local unit_exit="${1:-x}" exp_digest="${2:-x}" exp_tables="${3:-x}" exp_shape="${4:-x}"
  [ "$unit_exit" = 0 ] || { echo "FAIL:unit_exit_${unit_exit}"; return 0; }
  [ "$DMS_SANDBOX_DUPLICATES" = 0 ] || { echo "FAIL:duplicate_result_keys"; return 0; }
  [ "$DMS_SANDBOX_REJECTED" = 0 ] || { echo "FAIL:rejected_result_lines"; return 0; }
  [ "${DMS_SANDBOX_RESULT[profile]:-}" = attested ] || { echo "FAIL:profile_not_attested"; return 0; }
  [ "${DMS_SANDBOX_RESULT[bootstrap_superuser]:-}" = nologin ] || { echo "FAIL:superuser_not_locked"; return 0; }
  [ "${DMS_SANDBOX_RESULT[result]:-}" = GO ] || { echo "FAIL:sandbox_${DMS_SANDBOX_RESULT[stage]:-no_result}"; return 0; }
  [ "${DMS_SANDBOX_RESULT[migrations_table]:-}" = 1 ] || { echo "FAIL:no_migrations_table"; return 0; }
  # PR-2: a GO means no user code sits on a restore path (index expressions or predicates, materialized views, user
  # operators in constraints), where it would run during a real restore and could behave differently there.
  [ "${DMS_SANDBOX_RESULT[user_code_reachable]:-}" = 0 ] || { echo "FAIL:user_code_in_restore_path"; return 0; }
  [ "${DMS_SANDBOX_RESULT[extensions_outside_allowlist]:-}" = 0 ] || { echo "FAIL:extension_outside_allowlist"; return 0; }
  # P4P-1: no user function ran at all during the restore (track_functions), and the restored catalog has exactly the
  # functions, tables, indexes, constraints, triggers and sequences the archive's TOC creates (nothing dropped, added or
  # swapped by code that ran while the schema was created).
  [ "${DMS_SANDBOX_RESULT[user_code_executed]:-}" = 0 ] || { echo "FAIL:user_code_executed_in_restore"; return 0; }
  [[ "$exp_shape" =~ ^[0-9]{1,9}(:[0-9]{1,9}){6}$ ]] && [ "${DMS_SANDBOX_RESULT[catalog_shape]:-}" = "$exp_shape" ] || { echo "FAIL:catalog_shape_mismatch"; return 0; }
  [ "${DMS_SANDBOX_RESULT[base_table_count]:-}" = "$exp_tables" ] || { echo "FAIL:table_count_mismatch"; return 0; }
  [[ "$exp_digest" =~ ^[0-9a-f]{64}$ ]] && [ "${DMS_SANDBOX_RESULT[table_digest]:-}" = "$exp_digest" ] || { echo "FAIL:per_table_rows_mismatch"; return 0; }
  echo GO
}
