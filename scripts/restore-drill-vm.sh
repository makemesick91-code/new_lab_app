#!/usr/bin/env bash
#
# R6-B6 isolated restore drill (finding A-1) — the host-side orchestrator of the disposable drill VM.
#
# Owner directive "OWNER DESIGN DIRECTIVE — R6-B6 ISOLATED RESTORE DRILL" (2026-11-25):
#   D1 a separate disposable VM, never a sandbox on the production kernel: this script REFUSES to run on the production
#      host, and boots one fresh VM per drill from a pinned, digest-verified image, with NO network device;
#   D2 inside the VM, the guest agent runs the replay in a systemd system unit under DynamicUser;
#   D3 role names come from a signed role manifest; nothing connects to production PostgreSQL;
#   D4 the replay is a non-superuser role; D5 restricted mode (and the load guard) are always on;
#   D6 pilot limits 1 vCPU, 2 GiB, 10 GiB, 30 min, 64 tasks, one drill at a time, fail closed;
#   D10 the result is a signed, hash-chained evidence record (the readiness service is not wired to it).
# Phase 3: QEMU runs under its OWN identity through scripts/restore-drill-qemu-launcher.sh (this operator holds no
# image, no QEMU and no KVM right); every drill that gets past the trusted configuration ends in a signed record,
# refused and aborted ones included; the signer records the verified install itself.
#
# Usage: restore-drill-vm.sh --expected-sha256 <hex> --manifest <signed manifest> <backup.dump (pg_dump -Fc)>
# Configuration: a trusted key=value file (DMS_RESTORE_DRILL_CONF, default /etc/dms-restore-drill/drill.conf), owned
# by root or the operator, not writable by others, outside the checkout. Keys: see DRILL_CONF_KEYS below.
# Exit: 0 GO, 1 FAIL or aborted, 2 refused before any VM ran (recorded once the configuration is trusted).
set -uo pipefail
umask 077
# VR-11: run with a fixed, minimal environment (no LD_PRELOAD, QEMU_* or PATH from the caller). Only the
# configuration path is carried over.
# A development install inspects the archive in the caller's per-user manager (XDG_RUNTIME_DIR and the session bus;
# P4V-8: only its OWN manager, /run/user/<uid> and its bus, is accepted). On a drill host (root-owned install) this
# script runs as the drill RUNNER identity, started by the operator through sudo (review round 6, R6C-1): the
# operator's name (SUDO_USER) is kept for the record and for the backup's source check.
if [ "${DMS_DRILL_CLEAN_ENV:-}" != 1 ]; then
  xdg="${XDG_RUNTIME_DIR:-}"; [ "$xdg" = "/run/user/$(id -u)" ] || xdg=""
  bus="${DBUS_SESSION_BUS_ADDRESS:-}"; [ -n "$xdg" ] && [ "$bus" = "unix:path=${xdg}/bus" ] || bus=""
  op="${SUDO_USER:-}"; [[ "$op" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || op=""
  exec env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C.UTF-8 HOME="${HOME:-/nonexistent}" DMS_DRILL_CLEAN_ENV=1 \
    XDG_RUNTIME_DIR="$xdg" DBUS_SESSION_BUS_ADDRESS="$bus" DMS_DRILL_OPERATOR="$op" \
    DMS_RESTORE_DRILL_CONF="${DMS_RESTORE_DRILL_CONF:-/etc/dms-restore-drill/drill.conf}" bash "$0" "$@"
fi
ulimit -c 0
# VR-4: QEMU runs as the identity of this script. It must never be root: a device-model escape would be host root.
if [ "$(id -u)" = 0 ]; then echo "REFUSED: run the drill as the unprivileged drill operator, never as root (VR-4). Nothing ran."; exit 2; fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd -P)"
# P4V-4, P4P-5: the TRUST OWNER is the owner of this script: root for an installed drill host (the operator, and so an
# escaped QEMU, can then change neither the code nor any trusted input), or the operator for a development checkout
# (which already lets the operator change everything). Every library, every directory above them and every trusted
# input (configuration, image, manifest anchor, revocation list, signer entrypoint, QEMU, PostgreSQL client) must
# belong to root or to that owner and be writable by nobody else. The SIGNER verifies and records the install itself
# (P5-4, Phase 3); this script never states it.
TRUST_UID="$(stat -c '%u' -- "${SCRIPT_DIR}/$(basename "${BASH_SOURCE[0]}")")"
if [ "$TRUST_UID" != 0 ] && [ "$TRUST_UID" != "$(id -u)" ]; then
  echo "FATAL: this script is owned by uid ${TRUST_UID}, neither root nor the operator. Nothing ran."; exit 2
fi
# P5-6: this script itself, and its own directory and the install root above it, never rely on the sticky exception.
for node in "${SCRIPT_DIR}/$(basename "${BASH_SOURCE[0]}")" "$SCRIPT_DIR" "$REPO_DIR" "${REPO_DIR}/deploy" "${REPO_DIR}/deploy/production-host.conf"; do
  st="$(stat -c '%u %a' -- "$node" 2> /dev/null)" && [ ! -L "$node" ] && { [ "${st% *}" = 0 ] || [ "${st% *}" = "$TRUST_UID" ]; } && [ $(( 8#${st#* } & 8#022 )) -eq 0 ] \
    || { echo "FATAL: ${node} is missing, a symlink, not owned by root or uid ${TRUST_UID}, or writable by another identity. Nothing ran."; exit 2; }
done
d="${SCRIPT_DIR}/lib"
while :; do
  st="$(stat -c '%u %a' -- "$d")"
  # A root-owned sticky directory (/tmp) is accepted: only an entry's owner may rename or remove it.
  { { [ "${st% *}" = 0 ] || [ "${st% *}" = "$TRUST_UID" ]; } && [ $(( 8#${st#* } & 8#022 )) -eq 0 ]; } \
    || { [ "${st% *}" = 0 ] && [ $(( 8#${st#* } & 8#1000 )) -ne 0 ]; } \
    || { echo "FATAL: ${d} is not owned by root or uid ${TRUST_UID}, or is writable by another identity. Nothing ran."; exit 2; }
  [ "$d" = / ] && break
  d="$(dirname -- "$d")"
done
for lib in trusted-path.sh restore-integrity.sh restore-sandbox.sh restore-manifest.sh restore-evidence.sh restore-vm.sh restore-archive.sh; do
  node="${SCRIPT_DIR}/lib/${lib}"
  if [ -L "$node" ] || [ ! -f "$node" ] || { [ "$(stat -c '%u' -- "$node")" != "$TRUST_UID" ] && [ "$(stat -c '%u' -- "$node")" != 0 ]; } \
    || [ $(( 8#$(stat -c '%a' -- "$node") & 8#022 )) -ne 0 ]; then
    echo "FATAL: ${node} is missing, a symlink, not owned by root or uid ${TRUST_UID}, or writable by another identity. Nothing ran."
    exit 2
  fi
  # shellcheck source=/dev/null
  . "$node"
done
RECORDABLE=0; RECORDED=0; RUN_ID=""; STAGE=arguments
STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
EXPECTED_SHA256=""; MANIFEST=""; BACKUP_FILE=""; BACKUP_BYTES=0; MANIFEST_SHA=""; IMAGE_SHA=""
EXPECT_TABLES=""; EXPECT_ROWS=""; EXPECT_DIGEST=""; EXPECT_SHAPE=""; VM_RAN=0
declare -A DMS_VM_OUTPUT=()
# The request for the separate signer (VR-4, D10). VR-1: what the host verified is kept apart from what the guest
# asserted. A refused or aborted drill carries `none` wherever a fact was never established.
evidence_request() { # <decision>
  local decision="$1" kind="${1%%:*}" hv exp guest dg
  [ "$kind" = GO ] || [ "$kind" = FAIL ] && hv="$DMS_DRILL_EVIDENCE_HOST_VERIFIED_FACTS" || hv=none
  exp=none
  [ -n "$EXPECT_SHAPE" ] && exp="tables=${EXPECT_TABLES},rows=${EXPECT_ROWS},per_table_digest=${EXPECT_DIGEST},catalog_shape=${EXPECT_SHAPE}"
  guest=none
  if [ "$VM_RAN" = 1 ]; then
    dg="${DMS_VM_OUTPUT[sandbox_table_digest]:-none}"
    [[ "$dg" =~ ^([0-9a-f]{64}|none)$ ]] || dg=invalid
    guest="$(printf 'restore=%s,tables=%s,per_table_digest=%s,catalog_shape=%s,user_code=%s,user_code_executed=%s,fidelity=%s,vm_ifaces=%s' \
      "$(printf '%s' "${DMS_VM_OUTPUT[sandbox_replay]:-none}" | tr -cd 'a-z_' | head -c 20)" \
      "$(printf '%s' "${DMS_VM_OUTPUT[sandbox_base_table_count]:-none}" | tr -cd '0-9a-z' | head -c 12)" "$dg" \
      "$(printf '%s' "${DMS_VM_OUTPUT[sandbox_catalog_shape]:-none}" | tr -cd '0-9:a-z' | head -c 80)" \
      "$(printf '%s' "${DMS_VM_OUTPUT[sandbox_user_code_reachable]:-none}" | tr -cd '0-9a-z' | head -c 12)" \
      "$(printf '%s' "${DMS_VM_OUTPUT[sandbox_user_code_executed]:-none}" | tr -cd '0-9a-z' | head -c 12)" \
      "$(printf '%s' "${DMS_VM_OUTPUT[sandbox_fidelity_trg_con_fn_idx]:-none}" | tr -cd '0-9:a-z' | head -c 40)" \
      "$(printf '%s' "${DMS_VM_OUTPUT[vm_net_ifaces]:-none}" | tr -cd 'a-z0-9:' | head -c 40)")"
    for f in restore tables catalog_shape user_code user_code_executed fidelity vm_ifaces; do guest="${guest/${f}=,/${f}=none,}"; done
    guest="${guest%vm_ifaces=}"; [[ "$guest" == *vm_ifaces=* ]] || guest="${guest}vm_ifaces=none"
  fi
  printf 'DMS_DRILL_EVIDENCE_RUN_ID=%s\n' "$RUN_ID"
  printf 'DMS_DRILL_EVIDENCE_OPERATOR=%s\n' "${DMS_DRILL_OPERATOR:-$(id -un)}"
  printf 'DMS_DRILL_EVIDENCE_DRILL_HOST=%s\n' "$(hostname | tr -cd 'A-Za-z0-9._-' | head -c 64)"
  printf 'DMS_DRILL_EVIDENCE_STARTED_AT=%s\nDMS_DRILL_EVIDENCE_COMPLETED_AT=%s\n' "$STARTED_AT" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  if [[ "$EXPECTED_SHA256" =~ ^[0-9a-f]{64}$ ]]; then
    printf 'DMS_DRILL_EVIDENCE_BACKUP_SHA256=%s\nDMS_DRILL_EVIDENCE_BACKUP_BYTES=%s\nDMS_DRILL_EVIDENCE_DIGEST_SOURCE=expected-sha256\n' "$EXPECTED_SHA256" "$BACKUP_BYTES"
  else
    printf 'DMS_DRILL_EVIDENCE_BACKUP_SHA256=none\nDMS_DRILL_EVIDENCE_BACKUP_BYTES=0\nDMS_DRILL_EVIDENCE_DIGEST_SOURCE=none\n'
  fi
  printf 'DMS_DRILL_EVIDENCE_MANIFEST_ID=%s\nDMS_DRILL_EVIDENCE_MANIFEST_SHA256=%s\n' "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ID]:-none}" "${MANIFEST_SHA:-none}"
  printf 'DMS_DRILL_EVIDENCE_IMAGE_SHA256=%s\n' "${IMAGE_SHA:-none}"
  printf 'DMS_DRILL_EVIDENCE_LIMITS=%s\n' "$DMS_DRILL_EVIDENCE_LIMITS_D6"
  printf 'DMS_DRILL_EVIDENCE_HOST_VERIFIED=%s\nDMS_DRILL_EVIDENCE_EXPECTED=%s\nDMS_DRILL_EVIDENCE_GUEST_ASSERTED=%s\n' "$hv" "$exp" "$guest"
  printf 'DMS_DRILL_EVIDENCE_DECISION=%s\n' "$decision"
}
# record <decision>: one signed record per drill, through the separate signer. Prints the outcome; returns 1 when
# the signer refused or failed (the decision is then unrecorded, and says so).
RECORD=""
sign_request() { evidence_request "$1" | sudo -n -u "${C[DMS_DRILL_SIGNER_USER]}" -- "${C[DMS_DRILL_SIGNER_COMMAND]}" sign 2>&1 | head -c 400 | tr -cd '[:print:]'; }
record() {
  [ "$RECORDABLE" = 1 ] && [ "$RECORDED" = 0 ] || return 1
  RECORDED=1
  # R6F-E1: a store held locked by other callers delays the record, it never suppresses it: retried for up to about
  # ten minutes (inside the D6 budget). The failure line names no decision, so nobody learns an unrecorded outcome.
  local try
  for ((try = 0; try < 20; try++)); do
    RECORD="$(sign_request "$1")"
    case "$RECORD" in *"the store is locked"*) sleep 1; continue ;; esac
    break
  done
  case "$RECORD" in
    "RECORD "*) echo "Evidence: ${RECORD} (signed by ${C[DMS_DRILL_SIGNER_USER]}; keep this head digest outside the drill host)"; return 0 ;;
    *) echo "Evidence: NOT RECORDED: the signer refused or failed: ${RECORD}"; return 1 ;;
  esac
}
# R6C-3: an outcome is recorded only for an open start; a refusal opens its own start when none is open yet.
STARTED_REC=0
ensure_started() {
  [ "$RECORDABLE" = 1 ] || return 1
  [ "$STARTED_REC" = 1 ] && return 0
  START="$(sign_request STARTED)"
  case "$START" in
    "RECORD "*) STARTED_REC=1; echo "Evidence: started ${START}"; return 0 ;;
    *) echo "Evidence: NOT RECORDED: the signer did not open a record for this drill: ${START}"; RECORDABLE=0; return 1 ;;
  esac
}
refuse() { echo "REFUSED: $*. Nothing ran."; ensure_started && { record "REFUSED:${STAGE}" || true; }; exit 2; }

# --- Arguments -------------------------------------------------------------------------------------------------------
while [ "$#" -gt 0 ]; do
  case "$1" in
    --expected-sha256) EXPECTED_SHA256="${2:-}"; shift; [ "$#" -gt 0 ] && shift ;;
    --manifest) MANIFEST="${2:-}"; shift; [ "$#" -gt 0 ] && shift ;;
    -*) refuse "usage: restore-drill-vm.sh --expected-sha256 <hex> --manifest <file> <backup.dump (pg_dump -Fc)>" ;;
    *) [ -z "$BACKUP_FILE" ] || refuse "one backup file at most"; BACKUP_FILE="$1"; shift ;;
  esac
done

# --- D1: never on the production host --------------------------------------------------------------------------------
STAGE=production_host
PROD_CONF="${REPO_DIR}/deploy/production-host.conf"
PROD_HOSTNAME="$(sed -n 's/^DMS_PRODUCTION_HOSTNAME=//p' "$PROD_CONF" 2> /dev/null | head -n 1)"
PROD_APP_DIR="$(sed -n 's/^DMS_PRODUCTION_APP_DIR=//p' "$PROD_CONF" 2> /dev/null | head -n 1)"
[ -n "$PROD_HOSTNAME" ] && [ -n "$PROD_APP_DIR" ] || refuse "cannot read the production identity from ${PROD_CONF}"
[ "$(hostname)" != "$PROD_HOSTNAME" ] || refuse "this is the production host: the drill runs only in a disposable VM on an isolated drill host (D1)"
[ ! -e "$PROD_APP_DIR" ] || refuse "the production application directory exists on this host: the drill runs only on an isolated drill host (D1)"

# --- Trusted configuration (parsed, never sourced) -------------------------------------------------------------------
STAGE=configuration
CONF="${DMS_RESTORE_DRILL_CONF:-/etc/dms-restore-drill/drill.conf}"
why="$(dms_trusted_file "drill configuration" "$CONF" "$TRUST_UID" "$REPO_DIR")" || refuse "$why"
# VR-4: the signing key, the evidence store and its head log belong to the SIGNER identity; the drill image, QEMU and
# KVM belong to the QEMU identity (Phase 3). This operator holds none of them.
DRILL_CONF_KEYS="DMS_DRILL_MANIFEST_ANCHOR DMS_DRILL_MANIFEST_REVOCATIONS DMS_DRILL_EXTENSION_ALLOWLIST DMS_DRILL_SIGNER_USER DMS_DRILL_SIGNER_COMMAND DMS_DRILL_SUPERVISOR_COMMAND DMS_DRILL_PG_BINDIR DMS_DRILL_WORK_DIR DMS_DRILL_LOCALE"
declare -A C=()
while IFS= read -r line || [ -n "$line" ]; do
  case "$line" in '' | '#'*) continue ;; esac
  [[ "$line" =~ ^(DMS_DRILL_[A-Z0-9_]+)=([A-Za-z0-9_./@:-]{1,255})$ ]] || refuse "unexpected line in ${CONF}"
  [[ " ${DRILL_CONF_KEYS} " == *" ${BASH_REMATCH[1]} "* ]] || refuse "unknown key ${BASH_REMATCH[1]} in ${CONF}"
  [ -z "${C[${BASH_REMATCH[1]}]:-}" ] || refuse "${BASH_REMATCH[1]} appears twice in ${CONF}"
  C[${BASH_REMATCH[1]}]="${BASH_REMATCH[2]}"
done < "$CONF"
for k in $DRILL_CONF_KEYS; do [ -n "${C[$k]:-}" ] || refuse "${k} is missing from ${CONF}"; done
[[ "${C[DMS_DRILL_SIGNER_USER]}" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || refuse "DMS_DRILL_SIGNER_USER is not a user name"
SIGNER_UID="$(id -u -- "${C[DMS_DRILL_SIGNER_USER]}" 2> /dev/null)" || refuse "the signer account ${C[DMS_DRILL_SIGNER_USER]} does not exist"
[ "$SIGNER_UID" != "$(id -u)" ] && [ "$SIGNER_UID" != 0 ] || refuse "the signer must be a separate, unprivileged identity (VR-4)"
# Phase 3, review round 6: QEMU runs under its own account in a system unit of the root supervisor, which checks that
# the runner, QEMU and the signer are three different unprivileged accounts (scripts/restore-drill-supervisor.sh).
for k in DMS_DRILL_MANIFEST_ANCHOR DMS_DRILL_MANIFEST_REVOCATIONS DMS_DRILL_SIGNER_COMMAND DMS_DRILL_SUPERVISOR_COMMAND; do
  why="$(dms_trusted_file "$k" "${C[$k]}" "$TRUST_UID" "$REPO_DIR")" || refuse "$why"
done
why="$(dms_trusted_dir DMS_DRILL_WORK_DIR "${C[DMS_DRILL_WORK_DIR]}" "$(id -u)" "$REPO_DIR")" || refuse "$why"
# --- One drill at a time (D6), taken BEFORE the start is recorded (R6F-E1): a second drill can never close a live one.
STAGE=lock
exec {lock_fd}>> "${C[DMS_DRILL_WORK_DIR]}/drill.lock"
flock -n "$lock_fd" || { echo "REFUSED: another drill holds ${C[DMS_DRILL_WORK_DIR]}/drill.lock (one drill at a time, D6). Nothing ran; not recorded, as a start would close the live drill's record."; exit 2; }
# From here on every outcome is recorded through the signer: refusals and aborts included (Phase 3).
RUN_ID="$(dms_sandbox_unit_name)" || refuse "cannot draw a run id"
RECORDABLE=1
[[ "$EXPECTED_SHA256" =~ ^[0-9a-f]{64}$ ]] || refuse "--expected-sha256 must be the 64-hex digest from an independent record"
[ -n "$MANIFEST" ] || refuse "--manifest <signed role manifest> is required (D3)"
[ -n "$BACKUP_FILE" ] || refuse "the backup file is required"
WORK=""; VM_PID=""; INSPECT_UNIT=""
# vm_idle: the supervisor's QEMU unit is not running (a status query; any account may ask).
vm_idle() { case "$(systemctl show -P ActiveState dmsqemu.service 2> /dev/null)" in inactive | failed) return 0 ;; *) return 1 ;; esac; }
# stop_vm: TERM first, which timeout forwards to sudo and sudo relays to the supervisor; the supervisor stops the QEMU
# unit at once (R6F-L1). KILL only if that did not end it within 30 s; then wait until the unit itself has stopped.
stop_vm() {
  [ -n "$VM_PID" ] || return 0
  kill -TERM -- "-${VM_PID}" 2> /dev/null
  local i
  for ((i = 0; i < 60; i++)); do kill -0 "$VM_PID" 2> /dev/null || break; sleep 0.5; done
  dms_vm_kill_group "$VM_PID"
  VM_PID=""
  for ((i = 0; i < 90; i++)); do vm_idle && return 0; sleep 1; done
  echo "WARNING: the QEMU unit is still running; its own time limit ends it, and the next drill refuses until then"
}
cleanup() {
  stop_vm
  dms_archive_inspect_stop "$INSPECT_UNIT"
  [ -z "$WORK" ] || rm -rf -- "$WORK"
}
trap cleanup EXIT
# Phase 3: an interrupted drill is stopped and RECORDED as aborted, never left unrecorded; installed before the start
# is recorded (R6F-E2), and the drill cannot be suspended (a relayed TSTP would hold the lock and the run directory).
on_abort() {
  trap '' TERM HUP INT PIPE USR1 USR2 ALRM XCPU XFSZ VTALRM PROF
  # R6F-E7: a VM that has already ended (its status line is written) is not aborted: its outcome is decided and
  # recorded as usual, so watching the unit end and signalling cannot replace an outcome with ABORTED.
  if [ "$STAGE" = vm ] && [ -n "$WORK" ] && grep -aqE '^QEMU_LAUNCH exit=[0-9]{1,3} image_sha256=[0-9a-f]{64}$' -- "$WORK/vm.status" 2> /dev/null; then
    echo "NOTICE: a signal arrived after the VM had ended; its outcome is recorded"
    return 0
  fi
  echo "ABORTED: signal at stage ${STAGE}"; stop_vm; ensure_started && { record "ABORTED:${STAGE}" || true; }; exit 143
}
# R6A-5: every catchable terminating signal is an abort, recorded (SIGKILL is closed at the next start: R6C-3).
trap on_abort TERM HUP INT PIPE USR1 USR2 ALRM XCPU XFSZ VTALRM PROF
trap '' TSTP TTIN TTOU

# R6C-3: every attempt is opened by a signed STARTED record before any further check, and the supervisor boots a VM
# only for the run whose STARTED record is the newest; a run that never closes is closed by the signer later.
STAGE=start
ensure_started || { echo "REFUSED: the drill cannot be recorded, so it does not run. Nothing ran."; exit 2; }
STAGE=configuration_values
[[ "${C[DMS_DRILL_EXTENSION_ALLOWLIST]}" =~ ^(none|[a-z_][a-z0-9_]{0,62}(,[a-z_][a-z0-9_]{0,62}){0,15})$ ]] || refuse "DMS_DRILL_EXTENSION_ALLOWLIST must be none or a comma-separated list"
EXTENSIONS="${C[DMS_DRILL_EXTENSION_ALLOWLIST]//,/ }"

# --- D6: the pilot limits, fixed ------------------------------------------------------------------------------------
STAGE=limits
VCPU=1; MEMORY_MIB=2048; DISK_GIB=10; MINUTES=30; TASKS=64
why="$(dms_sandbox_limits_check "$VCPU" "$MEMORY_MIB" "$DISK_GIB" "$MINUTES" "$TASKS")" || refuse "$why"
# Inside the 2 GiB VM: the unit gets MemoryMax 1536 MiB; its tmpfs (PGDATA, WAL) at most 1024 MiB.
UNIT_MEMORY_MIB=1536; TMPFS_MAX_MIB=1024; UNIT_TIMEOUT=$(( (MINUTES - 5) * 60 ))
# R6F-L8: on a drill host the run directory (staged copy, input disk) must never reach a swap device.
if [ "$TRUST_UID" = 0 ] && [ "$(tail -n +2 /proc/swaps | grep -c .)" != 0 ]; then refuse "swap is active on the drill host (the run directory could be swapped out)"; fi
# R6F-E10: on a drill host no caller limit on processes, CPU time or address space reaches the drill (sudo pins them;
# this is the orchestrator's own check): a lowered limit could stop it at an arbitrary point.
if [ "$TRUST_UID" = 0 ]; then
  for o in u t v; do
    ulimit -S "-$o" unlimited 2> /dev/null
    [ "$(ulimit -H "-$o")" = unlimited ] && [ "$(ulimit -S "-$o")" = unlimited ] || refuse "a process, CPU or memory limit is set (ulimit -${o})"
  done
fi

# --- The pinned PostgreSQL client ----------------------------------------------------------------------------------
# The pinned PostgreSQL 16.15+ client that inspects the archive (pg_restore, never through PATH).
STAGE=pg_client
why="$(dms_restore_pg_client_check "${C[DMS_DRILL_PG_BINDIR]}" "$TRUST_UID" "$REPO_DIR" 2>&1)" || refuse "the PostgreSQL client directory failed its checks: ${why}"
[ -x "${C[DMS_DRILL_PG_BINDIR]}/pg_restore" ] && [ ! -L "${C[DMS_DRILL_PG_BINDIR]}/pg_restore" ] || refuse "pg_restore is missing from the pinned client directory"
why="$(dms_restore_psql_version_check "$("${C[DMS_DRILL_PG_BINDIR]}/psql" --version 2> /dev/null | head -n 1)")" || refuse "the pinned client is not PostgreSQL 16.15 or later"

# --- The run directory; stale runs; free space (VR-9) -----------------------------------------------------------
# Under the lock, any earlier run directory is a leftover of an interrupted drill and may hold a plaintext dump.
STAGE=work_dir
shopt -s nullglob
for stale in "${C[DMS_DRILL_WORK_DIR]}"/dmsdrill-*; do
  [ -d "$stale" ] && [ ! -L "$stale" ] && [ "$(stat -c '%u %a' -- "$stale")" = "$(id -u) 700" ] && rm -rf -- "$stale"
done
shopt -u nullglob
BACKUP_GUESS="$(stat -c %s -- "$BACKUP_FILE" 2> /dev/null || echo 0)"
FREE_KIB="$(df -Pk -- "${C[DMS_DRILL_WORK_DIR]}" | awk 'NR == 2 { print $4 }')"
# This operator keeps the staged copy and the encrypted input disk; the VM's overlay lives with the QEMU identity.
NEED_KIB=$(( (BACKUP_GUESS * 3 / 1024) + 1048576 ))
[[ "$FREE_KIB" =~ ^[0-9]+$ ]] && [ "$FREE_KIB" -ge "$NEED_KIB" ] || refuse "the work directory has ${FREE_KIB:-?} KiB free; a drill needs ${NEED_KIB} KiB (staged copy, input disk, margin)"
WORK="${C[DMS_DRILL_WORK_DIR]}/${RUN_ID}"
mkdir -m 0700 -- "$WORK" || { WORK=""; refuse "cannot create the run directory"; }

# --- D3: the signed role manifest, read once into the run directory (VR-10) ----------------------------------------
STAGE=manifest
[ ! -L "$MANIFEST" ] && [ -f "$MANIFEST" ] && [ ! -L "${MANIFEST}.sig" ] && [ -f "${MANIFEST}.sig" ] || refuse "the manifest or its .sig is missing or a symlink"
cat -- "$MANIFEST" > "$WORK/manifest" && cat -- "${MANIFEST}.sig" > "$WORK/manifest.sig" || refuse "cannot copy the manifest"
# PR-7: verified in THIS shell (a $(…) subshell would lose DMS_ROLE_MANIFEST); its first output line is the sha256.
dms_restore_manifest_verify "$WORK/manifest" "${C[DMS_DRILL_MANIFEST_ANCHOR]}" > "$WORK/manifest.verify" || refuse "$(head -c 300 "$WORK/manifest.verify")"
MANIFEST_SHA="$(head -n 1 "$WORK/manifest.verify")"
[[ "$MANIFEST_SHA" =~ ^[0-9a-f]{64}$ ]] && [ -n "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ROLES]:-}" ] || { MANIFEST_SHA=""; refuse "the manifest did not verify in this shell"; }
# PR-9: a revoked manifest, or one issued before the list's not-before, is never used.
STAGE=manifest_revoked
why="$(dms_restore_manifest_revocation_check "${C[DMS_DRILL_MANIFEST_REVOCATIONS]}")" || refuse "$why"

# --- Integrity chain from #695: source checks, private staged copy, trusted digest ---------------------------------
STAGE=backup_source
dms_restore_check_source "$BACKUP_FILE" "$(id -un)" ${DMS_DRILL_OPERATOR:+"$DMS_DRILL_OPERATOR"} || refuse "the backup file failed its source checks"
STAGED="$(dms_restore_stage_copy "$BACKUP_FILE" "$WORK")" || refuse "could not stage a private copy"
# P4V-2: the digest, then the custom-format magic (a plain-SQL dump is refused here, PR-6).
STAGE=backup_integrity
INTEGRITY="$(dms_archive_verify_copy "$STAGED" "$EXPECTED_SHA256")" || refuse "$INTEGRITY"
BACKUP_BYTES="$(stat -c %s -- "$STAGED")"
# --- PR-6: the structured archive, inspected in a sandbox; PR-5/PR-8/D-Q6-a/P5 from its structure ------------------
STAGE=archive_inspection
# A root-owned install (a drill host) inspects through the root supervisor's system unit; a development install in the
# caller's own per-user manager.
SUP=""
if [ "$TRUST_UID" = 0 ]; then SUP="${C[DMS_DRILL_SUPERVISOR_COMMAND]}"
elif [ -z "${XDG_RUNTIME_DIR:-}" ] || [ "$(stat -c '%u %a' -- "$XDG_RUNTIME_DIR" 2> /dev/null)" != "$(id -u) 700" ]; then
  refuse "a development install needs the caller's own per-user service manager (/run/user/$(id -u)) for the sandboxed inspection (P4V-8)"
fi
mkdir -m 0700 -- "$WORK/inspect" || refuse "cannot create the inspection directory"
INSPECT_UNIT="dmsinspect-$(od -An -N6 -tx1 /dev/urandom | tr -d ' \n')"
why="$(dms_archive_inspect "$STAGED" "$WORK/inspect" "${C[DMS_DRILL_PG_BINDIR]}" "$INSPECT_UNIT" "$SUP")" || refuse "${why} (a pg_dump 16 custom-format archive is required, PR-6)"
INSPECT_UNIT=""
STAGE=archive_toc
why="$(dms_archive_toc_validate "$WORK/inspect/toc.txt" "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ROLES]}" "$EXTENSIONS")" || refuse "$why"
dms_archive_toc_validate "$WORK/inspect/toc.txt" "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ROLES]}" "$EXTENSIONS" > /dev/null
STAGE=archive_counts
why="$(dms_archive_counts_check "$WORK/inspect/counts.txt")" || refuse "$why"
STAGE=archive_p5
if [ "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_R6_OWNER_ROLE]}" != none ] && [ -n "${DMS_ARCHIVE_OWNERS[${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_RUNTIME_ROLE]}]:-}" ]; then
  refuse "the archive makes the runtime role an owner after R6 (P5)"
fi
STAGE=archive_size
DATA_BYTES="$(cat -- "$WORK/inspect/bytes.txt" 2> /dev/null)"
[[ "$DATA_BYTES" =~ ^[0-9]{1,15}$ ]] || refuse "the archive's uncompressed data size is unknown"
# Phase 3 (decompression limit): the working space is sized from the larger of the compressed archive and the
# UNCOMPRESSED data the host counted, so a small, very compressible archive is refused here, never run out of space.
SIZE_BASIS=$(( BACKUP_BYTES * 8 > DATA_BYTES * 4 ? BACKUP_BYTES * 8 : DATA_BYTES * 4 ))
TMPFS_MIB=$(( SIZE_BASIS / 1048576 + 256 ))
[ "$TMPFS_MIB" -le "$TMPFS_MAX_MIB" ] || refuse "the archive needs about ${TMPFS_MIB} MiB of working space (${DATA_BYTES} bytes of data), above the pilot limit ${TMPFS_MAX_MIB} MiB (D6)"
EXPECT_TABLES="${#DMS_ARCHIVE_DATA_TABLES[@]}"
EXPECT_DIGEST="$(dms_archive_digest "$WORK/inspect/counts.txt")"
EXPECT_ROWS="$(awk -F'\t' '{ s += $2 } END { print s + 0 }' "$WORK/inspect/counts.txt")"
EXPECT_SHAPE="$DMS_ARCHIVE_SHAPE"
[[ "$EXPECT_SHAPE" =~ ^[0-9]{1,9}(:[0-9]{1,9}){6}$ ]] || { EXPECT_SHAPE=""; refuse "the archive's catalog shape could not be computed"; }

# --- The input disk (encrypted), then the VM under the QEMU identity (D1, Phase 3) ----------------------------------
STAGE=input_disk
mv -- "$STAGED" "$WORK/payload" || refuse "cannot move the staged archive"
PAYLOAD_BYTES="$(stat -c %s -- "$WORK/payload")"
PAYLOAD_SHA="$(sha256sum -- "$WORK/payload" | cut -d' ' -f1)"
[ "$PAYLOAD_SHA" = "$EXPECTED_SHA256" ] || refuse "the staged archive changed"
read -r PAYLOAD_KEY PAYLOAD_IV < <(dms_vm_input_key_iv) || refuse "cannot draw the payload key"
# shellcheck disable=SC2034  # passed by name to dms_vm_input_build
declare -A IN=([run_id]="$RUN_ID" [payload_bytes]="$PAYLOAD_BYTES" [payload_sha256]="$PAYLOAD_SHA"
  [payload_key]="$PAYLOAD_KEY" [payload_iv]="$PAYLOAD_IV"
  [roles]="${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ROLES]}" [runtime_role]="${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_RUNTIME_ROLE]}"
  [extension_allowlist]="${EXTENSIONS:-none}"
  [locale]="${C[DMS_DRILL_LOCALE]}" [tmpfs_mib]="$TMPFS_MIB"
  [memory_mib]="$UNIT_MEMORY_MIB" [tasks]="$TASKS" [timeout_seconds]="$UNIT_TIMEOUT")
dms_vm_input_build "$WORK/input.raw" "$WORK/payload" IN || refuse "cannot build the input disk"
rm -f -- "$WORK/payload"
STAGE=vm
echo "== drill ${RUN_ID}: VM boot in the supervised QEMU unit (1 vCPU, ${MEMORY_MIB} MiB, no network, ${MINUTES} min) =="
# The only channel to the QEMU identity: the encrypted input disk on stdin, the output disk on stdout, one status line,
# through the root supervisor, which runs QEMU in a system unit under its own account (review round 6).
timeout --kill-after=30 "$((MINUTES * 60 + 300))" sudo -n -- "${C[DMS_DRILL_SUPERVISOR_COMMAND]}" vm "$RUN_ID" \
  < "$WORK/input.raw" > "$WORK/output.raw" 2> "$WORK/vm.status" &
VM_PID=$!
# A returning trap (the VM had already ended, R6F-E7) interrupts `wait`; the VM's process is then waited for again.
while :; do
  wait "$VM_PID"; vmw=$?
  [ "$vmw" -gt 128 ] && kill -0 "$VM_PID" 2> /dev/null && continue
  break
done
VM_PID=""
# R6F-E7: once the VM has ended, its outcome is decided and recorded without interruption (an abort here would let an
# operator who watches the unit replace an outcome with ABORTED).
trap '' TERM HUP INT PIPE USR1 USR2 ALRM XCPU XFSZ VTALRM PROF
rm -f -- "$WORK/input.raw"
LAUNCH="$(grep -a -E '^(QEMU_LAUNCH|SUPERVISOR) ' "$WORK/vm.status" | tail -n 1 | head -c 200 | tr -cd '[:print:]')"
QEMU_EXIT=x
if [[ "$LAUNCH" =~ ^QEMU_LAUNCH\ exit=([0-9]{1,3})\ image_sha256=([0-9a-f]{64})$ ]]; then
  QEMU_EXIT="${BASH_REMATCH[1]}"; IMAGE_SHA="${BASH_REMATCH[2]}"
elif [[ "$LAUNCH" =~ ^(QEMU_LAUNCH|SUPERVISOR)\ refused=([a-z0-9_]{1,64})$ ]]; then
  STAGE=vm_launch
  refuse "the VM was not started: ${BASH_REMATCH[1]} refused ${BASH_REMATCH[2]}"
else
  # R6A I1: no status after the launch: a VM may have run; recorded as aborted, never as "nothing ran".
  echo "ABORTED: the QEMU unit ended without a status line"
  STAGE=vm_no_status
  ensure_started && { record "ABORTED:vm_no_status" || true; }
  exit 1
fi

# --- Decision from host-checkable facts, then signed evidence (D10) -------------------------------------------------
STAGE=decision
DECISION="FAIL:vm_output_unreadable"
if [ "$(stat -c %s -- "$WORK/output.raw" 2> /dev/null)" = "$DMS_VM_OUTPUT_BYTES" ] && dms_vm_output_parse "$WORK/output.raw"; then
  VM_RAN=1
  DECISION="$(dms_vm_decide "$QEMU_EXIT" "$RUN_ID" "$PAYLOAD_SHA" "$EXPECT_DIGEST" "$EXPECT_TABLES" "$EXPECT_SHAPE")"
  # D1: the VM had no network interface but loopback (the guest kernel's own view).
  if [ "$DECISION" = GO ] && [ "${DMS_VM_OUTPUT[vm_net_ifaces]:-}" != lo ]; then DECISION="FAIL:vm_has_network_interface"; fi
  # P5 from the restored catalog (PR-3): after R6, no object may be owned by the runtime role.
  if [ "$DECISION" = GO ] && [ "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_R6_OWNER_ROLE]}" != none ] \
    && [ "${DMS_VM_OUTPUT[sandbox_runtime_owned_objects]:-x}" != 0 ]; then DECISION="FAIL:p5_runtime_owned_objects"; fi
fi
# VR-8: a reason built from guest text must still fit the evidence schema, or the FAIL would go unrecorded.
DECISION="$(dms_vm_decision_safe "$DECISION")"
# R6F-E1: the decision is shown only once it is recorded; an unrecorded outcome is never revealed.
if ! record "$DECISION"; then
  echo "FATAL: the drill's outcome is unrecorded"
  exit 1
fi
echo "${INTEGRITY}"
echo "VM: QEMU exited ${QEMU_EXIT}"
echo "Decision: ${DECISION} (host-verified inputs; restore result asserted by the disposable guest, VR-1)"
[ "$DECISION" = GO ]
