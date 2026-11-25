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
#
# Usage: restore-drill-vm.sh --expected-sha256 <hex> --manifest <signed manifest> <backup.sql>
# Configuration: a trusted key=value file (DMS_RESTORE_DRILL_CONF, default /etc/dms-restore-drill/drill.conf), owned
# by root or the operator, not writable by others, outside the checkout. Keys: see DRILL_CONF_KEYS below.
# Exit: 0 GO, 1 FAIL (evidence written when the VM ran), 2 refused before anything ran.
set -uo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
for lib in trusted-path.sh restore-integrity.sh restore-sandbox.sh restore-manifest.sh restore-evidence.sh restore-vm.sh; do
  node="${SCRIPT_DIR}/lib/${lib}"
  if [ -L "$node" ] || [ ! -f "$node" ] || [ "$(stat -c '%u' -- "$node")" != "$(id -u)" ] \
    || [ $(( 8#$(stat -c '%a' -- "$node") & 8#022 )) -ne 0 ]; then
    echo "FATAL: ${node} is missing, a symlink, not owned by $(id -un), or writable by another identity. Nothing ran."
    exit 2
  fi
  # shellcheck source=/dev/null
  . "$node"
done
refuse() { echo "REFUSED: $*. Nothing ran."; exit 2; }

# --- Arguments -------------------------------------------------------------------------------------------------------
EXPECTED_SHA256=""; MANIFEST=""; BACKUP_FILE=""
while [ "$#" -gt 0 ]; do
  case "$1" in
    --expected-sha256) EXPECTED_SHA256="${2:-}"; shift; [ "$#" -gt 0 ] && shift ;;
    --manifest) MANIFEST="${2:-}"; shift; [ "$#" -gt 0 ] && shift ;;
    -*) refuse "usage: restore-drill-vm.sh --expected-sha256 <hex> --manifest <file> <backup.sql>" ;;
    *) [ -z "$BACKUP_FILE" ] || refuse "one backup file at most"; BACKUP_FILE="$1"; shift ;;
  esac
done
[[ "$EXPECTED_SHA256" =~ ^[0-9a-f]{64}$ ]] || refuse "--expected-sha256 must be the 64-hex digest from an independent record"
[ -n "$MANIFEST" ] || refuse "--manifest <signed role manifest> is required (D3)"
[ -n "$BACKUP_FILE" ] || refuse "the backup file is required"

# --- D1: never on the production host --------------------------------------------------------------------------------
PROD_CONF="${REPO_DIR}/deploy/production-host.conf"
PROD_HOSTNAME="$(sed -n 's/^DMS_PRODUCTION_HOSTNAME=//p' "$PROD_CONF" 2> /dev/null | head -n 1)"
PROD_APP_DIR="$(sed -n 's/^DMS_PRODUCTION_APP_DIR=//p' "$PROD_CONF" 2> /dev/null | head -n 1)"
[ -n "$PROD_HOSTNAME" ] && [ -n "$PROD_APP_DIR" ] || refuse "cannot read the production identity from ${PROD_CONF}"
[ "$(hostname)" != "$PROD_HOSTNAME" ] || refuse "this is the production host: the drill runs only in a disposable VM on an isolated drill host (D1)"
[ ! -e "$PROD_APP_DIR" ] || refuse "the production application directory exists on this host: the drill runs only on an isolated drill host (D1)"

# --- Trusted configuration (parsed, never sourced) -------------------------------------------------------------------
CONF="${DMS_RESTORE_DRILL_CONF:-/etc/dms-restore-drill/drill.conf}"
why="$(dms_trusted_file "drill configuration" "$CONF" "$(id -u)" "$REPO_DIR")" || refuse "$why"
DRILL_CONF_KEYS="DMS_DRILL_IMAGE DMS_DRILL_IMAGE_SHA256 DMS_DRILL_MANIFEST_ANCHOR DMS_DRILL_EVIDENCE_KEY DMS_DRILL_EVIDENCE_SIGNER DMS_DRILL_EVIDENCE_STORE DMS_DRILL_WORK_DIR DMS_DRILL_LOCALE DMS_DRILL_QEMU DMS_DRILL_QEMU_IMG"
declare -A C=()
while IFS= read -r line || [ -n "$line" ]; do
  case "$line" in '' | '#'*) continue ;; esac
  [[ "$line" =~ ^(DMS_DRILL_[A-Z0-9_]+)=([A-Za-z0-9_./@:-]{1,255})$ ]] || refuse "unexpected line in ${CONF}"
  [[ " ${DRILL_CONF_KEYS} " == *" ${BASH_REMATCH[1]} "* ]] || refuse "unknown key ${BASH_REMATCH[1]} in ${CONF}"
  [ -z "${C[${BASH_REMATCH[1]}]:-}" ] || refuse "${BASH_REMATCH[1]} appears twice in ${CONF}"
  C[${BASH_REMATCH[1]}]="${BASH_REMATCH[2]}"
done < "$CONF"
for k in $DRILL_CONF_KEYS; do [ -n "${C[$k]:-}" ] || refuse "${k} is missing from ${CONF}"; done
[[ "${C[DMS_DRILL_IMAGE_SHA256]}" =~ ^[0-9a-f]{64}$ ]] || refuse "DMS_DRILL_IMAGE_SHA256 is not a sha256"
[[ "${C[DMS_DRILL_EVIDENCE_SIGNER]}" =~ ^[A-Za-z0-9._@-]{1,64}$ ]] || refuse "DMS_DRILL_EVIDENCE_SIGNER is not a principal"
for k in DMS_DRILL_IMAGE DMS_DRILL_MANIFEST_ANCHOR DMS_DRILL_EVIDENCE_KEY; do
  why="$(dms_trusted_file "$k" "${C[$k]}" "$(id -u)" "$REPO_DIR")" || refuse "$why"
done
for k in DMS_DRILL_EVIDENCE_STORE DMS_DRILL_WORK_DIR; do
  why="$(dms_trusted_dir "$k" "${C[$k]}" "$(id -u)" "$REPO_DIR")" || refuse "$why"
done

# --- D6: the pilot limits, fixed ------------------------------------------------------------------------------------
VCPU=1; MEMORY_MIB=2048; DISK_GIB=10; MINUTES=30; TASKS=64
why="$(dms_sandbox_limits_check "$VCPU" "$MEMORY_MIB" "$DISK_GIB" "$MINUTES" "$TASKS")" || refuse "$why"
# Inside the 2 GiB VM: the unit gets MemoryMax 1536 MiB; its tmpfs (PGDATA, WAL) at most 1024 MiB.
UNIT_MEMORY_MIB=1536; TMPFS_MAX_MIB=1024; UNIT_TIMEOUT=$(( (MINUTES - 5) * 60 ))

# --- One drill at a time (D6) ---------------------------------------------------------------------------------------
exec {lock_fd}>> "${C[DMS_DRILL_WORK_DIR]}/drill.lock"
flock -n "$lock_fd" || refuse "another drill holds ${C[DMS_DRILL_WORK_DIR]}/drill.lock (one drill at a time, D6)"

# --- Hypervisor and image -------------------------------------------------------------------------------------------
for k in DMS_DRILL_QEMU DMS_DRILL_QEMU_IMG; do
  [ -x "${C[$k]}" ] && [ ! -L "${C[$k]}" ] || refuse "${k} ${C[$k]} is missing or not an executable regular file"
done
[ -r /dev/kvm ] && [ -w /dev/kvm ] || refuse "/dev/kvm is not usable by $(id -un): the drill needs a hardware-isolated VM (D1)"
IMAGE_SHA="$(sha256sum -- "${C[DMS_DRILL_IMAGE]}" | cut -d' ' -f1)"
[ "$IMAGE_SHA" = "${C[DMS_DRILL_IMAGE_SHA256]}" ] || refuse "the drill image digest ${IMAGE_SHA} is not the pinned ${C[DMS_DRILL_IMAGE_SHA256]}"
IMAGE_INFO="$("${C[DMS_DRILL_QEMU_IMG]}" info --output=json -- "${C[DMS_DRILL_IMAGE]}" 2> /dev/null)" || refuse "cannot inspect the drill image"
IMAGE_VSIZE="$(printf '%s' "$IMAGE_INFO" | sed -n 's/.*"virtual-size": *\([0-9]*\).*/\1/p' | head -n 1)"
IMAGE_FORMAT="$(printf '%s' "$IMAGE_INFO" | sed -n 's/.*"format": *"\([a-z0-9]*\)".*/\1/p' | head -n 1)"
[ "$IMAGE_FORMAT" = qcow2 ] || refuse "the drill image is not qcow2"
[[ "$IMAGE_VSIZE" =~ ^[0-9]+$ ]] && [ "$IMAGE_VSIZE" -le $((DISK_GIB * 1024 * 1024 * 1024)) ] || refuse "the drill image's virtual size exceeds ${DISK_GIB} GiB (D6)"
printf '%s' "$IMAGE_INFO" | grep -q '"backing-filename"' && refuse "the drill image must not have a backing file"

# --- D3: the signed role manifest -----------------------------------------------------------------------------------
MANIFEST_SHA="$(dms_restore_manifest_verify "$MANIFEST" "${C[DMS_DRILL_MANIFEST_ANCHOR]}")" || refuse "$MANIFEST_SHA"

# --- Integrity chain from #695: source checks, private staged copy, trusted digest ---------------------------------
RUN_ID="$(dms_sandbox_unit_name)" || refuse "cannot draw a run id"
WORK="${C[DMS_DRILL_WORK_DIR]}/${RUN_ID}"
mkdir -m 0700 -- "$WORK" || refuse "cannot create ${WORK}"
QEMU_PID=""
cleanup() {
  [ -n "$QEMU_PID" ] && kill -KILL "$QEMU_PID" 2> /dev/null
  rm -rf -- "$WORK"
}
trap cleanup EXIT
trap 'exit 143' TERM HUP INT
dms_restore_check_source "$BACKUP_FILE" "$(id -un)" || refuse "the backup file failed its source checks"
STAGED="$(dms_restore_stage_copy "$BACKUP_FILE" "$WORK")" || refuse "could not stage a private copy"
INTEGRITY="$(dms_restore_verify_copy "$STAGED" "$EXPECTED_SHA256")" || refuse "the backup does not match the expected digest"
dms_sandbox_dump_trailer_ok "$STAGED" || refuse "the backup is not a complete pg_dump 16 plain dump (no trailer or no matching \\unrestrict key, DR-7)"
SPLIT=0; dms_restore_split_check_entries "$STAGED" > /dev/null || SPLIT=$?
[ "$SPLIT" = 0 ] || refuse "the backup holds a CHECK that pg_dump validates after the data (defense in depth, D5)"
why="$(dms_restore_manifest_dump_gate "$STAGED")" || refuse "$why"
read -r EXPECT_TABLES EXPECT_ROWS < <(dms_sandbox_dump_counts "$STAGED") || refuse "cannot count the dump's tables and rows"
BACKUP_BYTES="$(stat -c %s -- "$STAGED")"
TMPFS_MIB=$(( BACKUP_BYTES * 6 / 1048576 + 256 ))
[ "$TMPFS_MIB" -le "$TMPFS_MAX_MIB" ] || refuse "the backup needs about ${TMPFS_MIB} MiB of working space, above the pilot limit ${TMPFS_MAX_MIB} MiB (D6)"

# --- The replay stream (restricted mode + load guard, D5), the disks and the VM ------------------------------------
KEY="$(dms_restore_restrict_key)" && TAG="g$(dms_restore_restrict_key | cut -c1-32)" || refuse "cannot draw the restricted-mode key"
dms_restore_restricted_stream "$STAGED" "$KEY" "$TAG" > "$WORK/payload" || refuse "cannot build the replay stream"
rm -f -- "$STAGED"
PAYLOAD_BYTES="$(stat -c %s -- "$WORK/payload")"
PAYLOAD_SHA="$(sha256sum -- "$WORK/payload" | cut -d' ' -f1)"
# shellcheck disable=SC2034  # passed by name to dms_vm_input_build
declare -A IN=([run_id]="$RUN_ID" [payload_bytes]="$PAYLOAD_BYTES" [payload_sha256]="$PAYLOAD_SHA"
  [roles]="${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ROLES]}" [locale]="${C[DMS_DRILL_LOCALE]}" [tmpfs_mib]="$TMPFS_MIB"
  [memory_mib]="$UNIT_MEMORY_MIB" [tasks]="$TASKS" [timeout_seconds]="$UNIT_TIMEOUT")
dms_vm_input_build "$WORK/input.raw" "$WORK/payload" IN || refuse "cannot build the input disk"
rm -f -- "$WORK/payload"
head -c "$DMS_VM_OUTPUT_BYTES" /dev/zero > "$WORK/output.raw"
"${C[DMS_DRILL_QEMU_IMG]}" create -q -f qcow2 -F qcow2 -b "${C[DMS_DRILL_IMAGE]}" "$WORK/root.qcow2" > /dev/null || refuse "cannot create the root overlay"
mapfile -t QARGS < <(dms_vm_qemu_args "$WORK/root.qcow2" "$WORK/input.raw" "$WORK/output.raw" "$VCPU" "$MEMORY_MIB")
STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "== drill ${RUN_ID}: VM boot (1 vCPU, ${MEMORY_MIB} MiB, no network, ${MINUTES} min) =="
timeout --kill-after=10 "$((MINUTES * 60))" "${C[DMS_DRILL_QEMU]}" "${QARGS[@]}" > /dev/null 2>&1 &
QEMU_PID=$!
wait "$QEMU_PID"
QEMU_EXIT=$?
QEMU_PID=""
COMPLETED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

# --- Decision from host-checkable facts, then signed evidence (D10) -------------------------------------------------
DECISION="FAIL:vm_output_unreadable"
if dms_vm_output_parse "$WORK/output.raw"; then
  DECISION="$(dms_vm_decide "$QEMU_EXIT" "$RUN_ID" "$PAYLOAD_SHA" "$PAYLOAD_BYTES" "$EXPECT_TABLES" "$EXPECT_ROWS")"
  # D1: the VM had no network interface but loopback (the guest kernel's own view).
  if [ "$DECISION" = GO ] && [ "${DMS_VM_OUTPUT[vm_net_ifaces]:-}" != lo ]; then DECISION="FAIL:vm_has_network_interface"; fi
fi
# shellcheck disable=SC2034  # passed by name to dms_evidence_write
declare -A EV=([DMS_DRILL_EVIDENCE_RUN_ID]="$RUN_ID" [DMS_DRILL_EVIDENCE_DRILL_HOST]="$(hostname | tr -cd 'A-Za-z0-9._-')"
  [DMS_DRILL_EVIDENCE_SIGNER]="${C[DMS_DRILL_EVIDENCE_SIGNER]}" [DMS_DRILL_EVIDENCE_STARTED_AT]="$STARTED_AT"
  [DMS_DRILL_EVIDENCE_COMPLETED_AT]="$COMPLETED_AT" [DMS_DRILL_EVIDENCE_BACKUP_SHA256]="$EXPECTED_SHA256"
  [DMS_DRILL_EVIDENCE_BACKUP_BYTES]="$BACKUP_BYTES" [DMS_DRILL_EVIDENCE_DIGEST_SOURCE]=expected-sha256
  [DMS_DRILL_EVIDENCE_MANIFEST_ID]="${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ID]}" [DMS_DRILL_EVIDENCE_MANIFEST_SHA256]="$MANIFEST_SHA"
  [DMS_DRILL_EVIDENCE_IMAGE_SHA256]="$IMAGE_SHA"
  [DMS_DRILL_EVIDENCE_LIMITS]="vcpu=${VCPU},memory_mib=${MEMORY_MIB},disk_gib=${DISK_GIB},minutes=${MINUTES},tasks=${TASKS}"
  [DMS_DRILL_EVIDENCE_EXPECTED]="tables=${EXPECT_TABLES},rows=${EXPECT_ROWS}"
  [DMS_DRILL_EVIDENCE_OBSERVED]="tables=${DMS_VM_OUTPUT[sandbox_base_table_count]:-none},rows=${DMS_VM_OUTPUT[sandbox_rows_total]:-none}"
  [DMS_DRILL_EVIDENCE_FIDELITY]="${DMS_VM_OUTPUT[sandbox_fidelity_trg_con_fn_idx]:-none}" [DMS_DRILL_EVIDENCE_DECISION]="$DECISION")
RECORD="$(dms_evidence_write "${C[DMS_DRILL_EVIDENCE_STORE]}" "${C[DMS_DRILL_EVIDENCE_KEY]}" EV)" || { echo "FATAL: could not write signed evidence; decision ${DECISION} is unrecorded."; exit 1; }
echo "${INTEGRITY}"
echo "Decision: ${DECISION}"
echo "Evidence: ${RECORD} (signed, chained)"
[ "$DECISION" = GO ]
