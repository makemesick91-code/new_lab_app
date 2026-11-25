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
# VR-11: run with a fixed, minimal environment (no LD_PRELOAD, QEMU_* or PATH from the caller). Only the
# configuration path is carried over.
# The operator's per-user manager (it runs the sandboxed archive inspection) is reached through XDG_RUNTIME_DIR and the
# session bus. P4V-8: only the operator's OWN manager is accepted (/run/user/<uid> and its bus); anything else is
# dropped, and the inspection then refuses (systemd-run does not authenticate the manager it talks to).
if [ "${DMS_DRILL_CLEAN_ENV:-}" != 1 ]; then
  xdg="${XDG_RUNTIME_DIR:-}"; [ "$xdg" = "/run/user/$(id -u)" ] || xdg=""
  bus="${DBUS_SESSION_BUS_ADDRESS:-}"; [ -n "$xdg" ] && [ "$bus" = "unix:path=${xdg}/bus" ] || bus=""
  exec env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C.UTF-8 HOME="${HOME:-/nonexistent}" DMS_DRILL_CLEAN_ENV=1 \
    XDG_RUNTIME_DIR="$xdg" DBUS_SESSION_BUS_ADDRESS="$bus" \
    DMS_RESTORE_DRILL_CONF="${DMS_RESTORE_DRILL_CONF:-/etc/dms-restore-drill/drill.conf}" bash "$0" "$@"
fi
# VR-4: QEMU runs as the identity of this script. It must never be root: a device-model escape would be host root.
if [ "$(id -u)" = 0 ]; then echo "REFUSED: run the drill as the unprivileged drill operator, never as root (VR-4). Nothing ran."; exit 2; fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd -P)"
# P4V-4, P4P-5: the TRUST OWNER is the owner of this script: root for an installed drill host (the operator, and so an
# escaped QEMU, can then change neither the code nor any trusted input), or the operator for a development checkout
# (which already lets the operator change everything). Every library, every directory above them and every trusted
# input (configuration, image, manifest anchor, revocation list, signer entrypoint, QEMU, PostgreSQL client) must
# belong to root or to that owner and be writable by nobody else. The evidence records which install it was.
TRUST_UID="$(stat -c '%u' -- "${SCRIPT_DIR}/$(basename "${BASH_SOURCE[0]}")")"
if [ "$TRUST_UID" = 0 ]; then INSTALL=root; elif [ "$TRUST_UID" = "$(id -u)" ]; then INSTALL=operator; else
  echo "FATAL: this script is owned by uid ${TRUST_UID}, neither root nor the operator. Nothing ran."; exit 2
fi
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
refuse() { echo "REFUSED: $*. Nothing ran."; exit 2; }

# --- Arguments -------------------------------------------------------------------------------------------------------
EXPECTED_SHA256=""; MANIFEST=""; BACKUP_FILE=""
while [ "$#" -gt 0 ]; do
  case "$1" in
    --expected-sha256) EXPECTED_SHA256="${2:-}"; shift; [ "$#" -gt 0 ] && shift ;;
    --manifest) MANIFEST="${2:-}"; shift; [ "$#" -gt 0 ] && shift ;;
    -*) refuse "usage: restore-drill-vm.sh --expected-sha256 <hex> --manifest <file> <backup.dump (pg_dump -Fc)>" ;;
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
why="$(dms_trusted_file "drill configuration" "$CONF" "$TRUST_UID" "$REPO_DIR")" || refuse "$why"
# VR-4: the signing key, the evidence store and its head log belong to the SIGNER identity, never to this operator.
DRILL_CONF_KEYS="DMS_DRILL_IMAGE DMS_DRILL_IMAGE_SHA256 DMS_DRILL_MANIFEST_ANCHOR DMS_DRILL_MANIFEST_REVOCATIONS DMS_DRILL_EXTENSION_ALLOWLIST DMS_DRILL_SIGNER_USER DMS_DRILL_SIGNER_COMMAND DMS_DRILL_PG_BINDIR DMS_DRILL_WORK_DIR DMS_DRILL_LOCALE DMS_DRILL_QEMU DMS_DRILL_QEMU_IMG"
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
[[ "${C[DMS_DRILL_SIGNER_USER]}" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || refuse "DMS_DRILL_SIGNER_USER is not a user name"
SIGNER_UID="$(id -u -- "${C[DMS_DRILL_SIGNER_USER]}" 2> /dev/null)" || refuse "the signer account ${C[DMS_DRILL_SIGNER_USER]} does not exist"
[ "$SIGNER_UID" != "$(id -u)" ] && [ "$SIGNER_UID" != 0 ] || refuse "the signer must be a separate, unprivileged identity (VR-4)"
[[ "${C[DMS_DRILL_EXTENSION_ALLOWLIST]}" =~ ^(none|[a-z_][a-z0-9_]{0,62}(,[a-z_][a-z0-9_]{0,62}){0,15})$ ]] || refuse "DMS_DRILL_EXTENSION_ALLOWLIST must be none or a comma-separated list"
EXTENSIONS="${C[DMS_DRILL_EXTENSION_ALLOWLIST]//,/ }"
for k in DMS_DRILL_IMAGE DMS_DRILL_MANIFEST_ANCHOR DMS_DRILL_MANIFEST_REVOCATIONS DMS_DRILL_SIGNER_COMMAND DMS_DRILL_QEMU DMS_DRILL_QEMU_IMG; do
  why="$(dms_trusted_file "$k" "${C[$k]}" "$TRUST_UID" "$REPO_DIR")" || refuse "$why"
done
why="$(dms_trusted_dir DMS_DRILL_WORK_DIR "${C[DMS_DRILL_WORK_DIR]}" "$(id -u)" "$REPO_DIR")" || refuse "$why"

# --- D6: the pilot limits, fixed ------------------------------------------------------------------------------------
VCPU=1; MEMORY_MIB=2048; DISK_GIB=10; MINUTES=30; TASKS=64
why="$(dms_sandbox_limits_check "$VCPU" "$MEMORY_MIB" "$DISK_GIB" "$MINUTES" "$TASKS")" || refuse "$why"
# Inside the 2 GiB VM: the unit gets MemoryMax 1536 MiB; its tmpfs (PGDATA, WAL) at most 1024 MiB.
UNIT_MEMORY_MIB=1536; TMPFS_MAX_MIB=1024; UNIT_TIMEOUT=$(( (MINUTES - 5) * 60 ))

# --- One drill at a time (D6) ---------------------------------------------------------------------------------------
exec {lock_fd}>> "${C[DMS_DRILL_WORK_DIR]}/drill.lock"
flock -n "$lock_fd" || refuse "another drill holds ${C[DMS_DRILL_WORK_DIR]}/drill.lock (one drill at a time, D6)"

# --- The pinned PostgreSQL client ----------------------------------------------------------------------------------
# The pinned PostgreSQL 16.15+ client that inspects the archive (pg_restore, never through PATH).
why="$(dms_restore_pg_client_check "${C[DMS_DRILL_PG_BINDIR]}" "$TRUST_UID" "$REPO_DIR" 2>&1)" || refuse "the PostgreSQL client directory failed its checks: ${why}"
[ -x "${C[DMS_DRILL_PG_BINDIR]}/pg_restore" ] && [ ! -L "${C[DMS_DRILL_PG_BINDIR]}/pg_restore" ] || refuse "pg_restore is missing from the pinned client directory"
why="$(dms_restore_psql_version_check "$("${C[DMS_DRILL_PG_BINDIR]}/psql" --version 2> /dev/null | head -n 1)")" || refuse "the pinned client is not PostgreSQL 16.15 or later"

# --- The run directory; stale runs; free space (VR-9) -----------------------------------------------------------
# Under the lock, any earlier run directory is a leftover of an interrupted drill and may hold a plaintext dump.
shopt -s nullglob
for stale in "${C[DMS_DRILL_WORK_DIR]}"/dmsdrill-*; do
  [ -d "$stale" ] && [ ! -L "$stale" ] && [ "$(stat -c '%u %a' -- "$stale")" = "$(id -u) 700" ] && rm -rf -- "$stale"
done
shopt -u nullglob
BACKUP_GUESS="$(stat -c %s -- "$BACKUP_FILE" 2> /dev/null || echo 0)"
FREE_KIB="$(df -Pk -- "${C[DMS_DRILL_WORK_DIR]}" | awk 'NR == 2 { print $4 }')"
NEED_KIB=$(( (DISK_GIB * 1024 * 1024) + (BACKUP_GUESS * 3 / 1024) + 1048576 ))
[[ "$FREE_KIB" =~ ^[0-9]+$ ]] && [ "$FREE_KIB" -ge "$NEED_KIB" ] || refuse "the work directory has ${FREE_KIB:-?} KiB free; a drill needs ${NEED_KIB} KiB (overlay, input disk, margin)"
RUN_ID="$(dms_sandbox_unit_name)" || refuse "cannot draw a run id"
WORK="${C[DMS_DRILL_WORK_DIR]}/${RUN_ID}"
mkdir -m 0700 -- "$WORK" || refuse "cannot create ${WORK}"
QEMU_PID=""
INSPECT_UNIT=""
cleanup() {
  dms_vm_kill_group "$QEMU_PID"
  dms_archive_inspect_stop "$INSPECT_UNIT"
  rm -rf -- "$WORK"
}
trap cleanup EXIT
trap 'exit 143' TERM HUP INT

# --- D3: the signed role manifest, read once into the run directory (VR-10) ----------------------------------------
[ ! -L "$MANIFEST" ] && [ -f "$MANIFEST" ] && [ ! -L "${MANIFEST}.sig" ] && [ -f "${MANIFEST}.sig" ] || refuse "the manifest or its .sig is missing or a symlink"
cat -- "$MANIFEST" > "$WORK/manifest" && cat -- "${MANIFEST}.sig" > "$WORK/manifest.sig" || refuse "cannot copy the manifest"
# PR-7: verified in THIS shell (a $(…) subshell would lose DMS_ROLE_MANIFEST); its first output line is the sha256.
dms_restore_manifest_verify "$WORK/manifest" "${C[DMS_DRILL_MANIFEST_ANCHOR]}" > "$WORK/manifest.verify" || refuse "$(head -c 300 "$WORK/manifest.verify")"
MANIFEST_SHA="$(head -n 1 "$WORK/manifest.verify")"
[[ "$MANIFEST_SHA" =~ ^[0-9a-f]{64}$ ]] && [ -n "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ROLES]:-}" ] || refuse "the manifest did not verify in this shell"
# PR-9: a revoked manifest, or one issued before the list's not-before, is never used.
why="$(dms_restore_manifest_revocation_check "${C[DMS_DRILL_MANIFEST_REVOCATIONS]}")" || refuse "$why"

# --- Integrity chain from #695: source checks, private staged copy, trusted digest ---------------------------------
dms_restore_check_source "$BACKUP_FILE" "$(id -un)" || refuse "the backup file failed its source checks"
STAGED="$(dms_restore_stage_copy "$BACKUP_FILE" "$WORK")" || refuse "could not stage a private copy"
# P4V-2: the digest, then the custom-format magic (a plain-SQL dump is refused here, PR-6).
INTEGRITY="$(dms_archive_verify_copy "$STAGED" "$EXPECTED_SHA256")" || refuse "$INTEGRITY"
# --- PR-6: the structured archive, inspected in a sandbox; PR-5/PR-8/D-Q6-a/P5 from its structure ------------------
[ -n "${XDG_RUNTIME_DIR:-}" ] && [ "$(stat -c '%u %a' -- "$XDG_RUNTIME_DIR" 2> /dev/null)" = "$(id -u) 700" ] \
  || refuse "the operator's own per-user service manager (/run/user/$(id -u), lingering) is required: the archive is inspected in a per-user unit (P4V-8)"
mkdir -m 0700 -- "$WORK/inspect" || refuse "cannot create the inspection directory"
INSPECT_UNIT="dmsinspect-$(od -An -N6 -tx1 /dev/urandom | tr -d ' \n')"
why="$(dms_archive_inspect "$STAGED" "$WORK/inspect" "${C[DMS_DRILL_PG_BINDIR]}" "$INSPECT_UNIT")" || refuse "${why} (a pg_dump 16 custom-format archive is required, PR-6)"
INSPECT_UNIT=""
why="$(dms_archive_toc_validate "$WORK/inspect/toc.txt" "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ROLES]}" "$EXTENSIONS")" || refuse "$why"
dms_archive_toc_validate "$WORK/inspect/toc.txt" "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ROLES]}" "$EXTENSIONS" > /dev/null
why="$(dms_archive_counts_check "$WORK/inspect/counts.txt")" || refuse "$why"
EXPECT_TABLES="${#DMS_ARCHIVE_DATA_TABLES[@]}"
EXPECT_SHAPE="$DMS_ARCHIVE_SHAPE"
[[ "$EXPECT_SHAPE" =~ ^[0-9]{1,9}(:[0-9]{1,9}){6}$ ]] || refuse "the archive's catalog shape could not be computed"
EXPECT_DIGEST="$(dms_archive_digest "$WORK/inspect/counts.txt")"
EXPECT_ROWS="$(awk -F'\t' '{ s += $2 } END { print s + 0 }' "$WORK/inspect/counts.txt")"
if [ "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_R6_OWNER_ROLE]}" != none ] && [ -n "${DMS_ARCHIVE_OWNERS[${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_RUNTIME_ROLE]}]:-}" ]; then
  refuse "the archive makes the runtime role an owner after R6 (P5)"
fi
BACKUP_BYTES="$(stat -c %s -- "$STAGED")"
TMPFS_MIB=$(( BACKUP_BYTES * 8 / 1048576 + 256 ))
[ "$TMPFS_MIB" -le "$TMPFS_MAX_MIB" ] || refuse "the archive needs about ${TMPFS_MIB} MiB of working space, above the pilot limit ${TMPFS_MAX_MIB} MiB (D6)"

# --- Hypervisor and image (only after every input check, P4V-2) ----------------------------------------------
for k in DMS_DRILL_QEMU DMS_DRILL_QEMU_IMG; do
  [ -x "${C[$k]}" ] || refuse "${k} ${C[$k]} is not executable"
done
[ -r /dev/kvm ] && [ -w /dev/kvm ] || refuse "/dev/kvm is not usable by $(id -un): the drill needs a hardware-isolated VM (D1)"
IMAGE_SHA="$(sha256sum -- "${C[DMS_DRILL_IMAGE]}" | cut -d' ' -f1)"
[ "$IMAGE_SHA" = "${C[DMS_DRILL_IMAGE_SHA256]}" ] || refuse "the drill image digest ${IMAGE_SHA} is not the pinned ${C[DMS_DRILL_IMAGE_SHA256]}"
"${C[DMS_DRILL_QEMU_IMG]}" info -f qcow2 --output=json -- "${C[DMS_DRILL_IMAGE]}" > "${C[DMS_DRILL_WORK_DIR]}/.image-info.json" 2> /dev/null || refuse "cannot inspect the drill image as qcow2"
IMAGE_VSIZE="$(dms_vm_image_info_ok "${C[DMS_DRILL_WORK_DIR]}/.image-info.json" $((DISK_GIB * 1024 * 1024 * 1024)) 2>&1)" || refuse "${IMAGE_VSIZE} (D6)"
rm -f -- "${C[DMS_DRILL_WORK_DIR]}/.image-info.json"
# --- The disks and the VM (the restore, with restricted mode and the load guard, happens inside: D5) ------------------
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
  DECISION="$(dms_vm_decide "$QEMU_EXIT" "$RUN_ID" "$PAYLOAD_SHA" "$EXPECT_DIGEST" "$EXPECT_TABLES" "$EXPECT_SHAPE")"
  # D1: the VM had no network interface but loopback (the guest kernel's own view).
  if [ "$DECISION" = GO ] && [ "${DMS_VM_OUTPUT[vm_net_ifaces]:-}" != lo ]; then DECISION="FAIL:vm_has_network_interface"; fi
  # P5 from the restored catalog (PR-3): after R6, no object may be owned by the runtime role.
  if [ "$DECISION" = GO ] && [ "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_R6_OWNER_ROLE]}" != none ] \
    && [ "${DMS_VM_OUTPUT[sandbox_runtime_owned_objects]:-x}" != 0 ]; then DECISION="FAIL:p5_runtime_owned_objects"; fi
fi
# VR-8: a reason built from guest text must still fit the evidence schema, or the FAIL would go unrecorded.
DECISION="$(dms_vm_decision_safe "$DECISION")"
# --- The evidence request, to the separate signer (VR-4, D10). VR-1: what the host verified is kept apart from what
# the guest asserted; a GO is never stronger than the guest's integrity.
GUEST_DIGEST="${DMS_VM_OUTPUT[sandbox_table_digest]:-none}"
[[ "$GUEST_DIGEST" =~ ^([0-9a-f]{64}|none)$ ]] || GUEST_DIGEST=invalid
{
  printf 'DMS_DRILL_EVIDENCE_RUN_ID=%s\n' "$RUN_ID"
  printf 'DMS_DRILL_EVIDENCE_DRILL_HOST=%s\n' "$(hostname | tr -cd 'A-Za-z0-9._-' | head -c 64)"
  printf 'DMS_DRILL_EVIDENCE_STARTED_AT=%s\nDMS_DRILL_EVIDENCE_COMPLETED_AT=%s\n' "$STARTED_AT" "$COMPLETED_AT"
  printf 'DMS_DRILL_EVIDENCE_BACKUP_SHA256=%s\nDMS_DRILL_EVIDENCE_BACKUP_BYTES=%s\n' "$EXPECTED_SHA256" "$BACKUP_BYTES"
  printf 'DMS_DRILL_EVIDENCE_DIGEST_SOURCE=expected-sha256\n'
  printf 'DMS_DRILL_EVIDENCE_MANIFEST_ID=%s\nDMS_DRILL_EVIDENCE_MANIFEST_SHA256=%s\n' "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ID]}" "$MANIFEST_SHA"
  printf 'DMS_DRILL_EVIDENCE_IMAGE_SHA256=%s\n' "$IMAGE_SHA"
  printf 'DMS_DRILL_EVIDENCE_LIMITS=vcpu=%s,memory_mib=%s,disk_gib=%s,minutes=%s,tasks=%s\n' "$VCPU" "$MEMORY_MIB" "$DISK_GIB" "$MINUTES" "$TASKS"
  printf 'DMS_DRILL_EVIDENCE_HOST_VERIFIED=%s,install=%s\n' "$DMS_DRILL_EVIDENCE_HOST_VERIFIED_FACTS" "$INSTALL"
  printf 'DMS_DRILL_EVIDENCE_EXPECTED=tables=%s,rows=%s,per_table_digest=%s,catalog_shape=%s\n' "$EXPECT_TABLES" "$EXPECT_ROWS" "$EXPECT_DIGEST" "$EXPECT_SHAPE"
  printf 'DMS_DRILL_EVIDENCE_GUEST_ASSERTED=restore=%s,tables=%s,per_table_digest=%s,catalog_shape=%s,user_code=%s,user_code_executed=%s,fidelity=%s,vm_ifaces=%s\n' \
    "$(printf '%s' "${DMS_VM_OUTPUT[sandbox_replay]:-none}" | tr -cd 'a-z_' | head -c 20)" \
    "$(printf '%s' "${DMS_VM_OUTPUT[sandbox_base_table_count]:-none}" | tr -cd '0-9a-z' | head -c 12)" "$GUEST_DIGEST" \
    "$(printf '%s' "${DMS_VM_OUTPUT[sandbox_catalog_shape]:-none}" | tr -cd '0-9:a-z' | head -c 80)" \
    "$(printf '%s' "${DMS_VM_OUTPUT[sandbox_user_code_reachable]:-none}" | tr -cd '0-9a-z' | head -c 12)" \
    "$(printf '%s' "${DMS_VM_OUTPUT[sandbox_user_code_executed]:-none}" | tr -cd '0-9a-z' | head -c 12)" \
    "$(printf '%s' "${DMS_VM_OUTPUT[sandbox_fidelity_trg_con_fn_idx]:-none}" | tr -cd '0-9:a-z' | head -c 40)" \
    "$(printf '%s' "${DMS_VM_OUTPUT[vm_net_ifaces]:-none}" | tr -cd 'a-z0-9:' | head -c 40)"
  printf 'DMS_DRILL_EVIDENCE_DECISION=%s\n' "$DECISION"
} > "$WORK/evidence.request"
RECORD="$(sudo -n -u "${C[DMS_DRILL_SIGNER_USER]}" -- "${C[DMS_DRILL_SIGNER_COMMAND]}" sign < "$WORK/evidence.request" 2>&1 | head -c 400)"
case "$RECORD" in
  "RECORD "*) : ;;
  *) echo "FATAL: the signer refused or failed; decision ${DECISION} is unrecorded: $(printf '%s' "$RECORD" | tr -cd '[:print:]')"; exit 1 ;;
esac
echo "${INTEGRITY}"
echo "Decision: ${DECISION} (host-verified inputs; restore result asserted by the disposable guest)"
echo "Evidence: ${RECORD} (signed by ${C[DMS_DRILL_SIGNER_USER]}; keep this head digest outside the drill host)"
[ "$DECISION" = GO ]
