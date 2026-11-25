#!/usr/bin/env bash
#
# R6-B6 isolated restore drill — the guest agent, INSIDE the disposable VM (owner directives D1, D2).
#
# Installed in the drill VM image and started once at boot by deploy/restore-drill-vm/dms-restore-drill-guest.service
# as root, with the input and output disks the host attached (by serial) and the mode `system`. It:
#   1. parses the input header strictly and re-verifies the payload's sha256 (the host computed it);
#   2. decrypts the custom-format archive (AES-256-CTR under the per-run key in the header, so nothing on the disk is
#      attacker-shaped, VR-6) into its private directory, re-verifies its sha256, writes the load guard, and runs the
#      restore in a transient systemd SYSTEM unit under DynamicUser (scripts/lib/restore-sandbox.sh) with both bound
#      read-only. This agent never interprets the archive; only the unit restores it;
#   3. writes its own facts and the strictly parsed sandbox result to the output disk; the service then powers off.
# The agent never prints backup content and never forwards the unit's stderr.
#
# Usage: restore-drill-vm-guest.sh <input device> <output device> <system|user>
#   `user` exists only so the tests can exercise this agent with image files and the per-user manager; the VM
#   service always passes `system`.
set -uo pipefail
umask 077
INPUT="${1:?input device}"
OUTPUT="${2:?output device}"
MODE="${3:?mode}"
case "$MODE" in system | user) : ;; *) exit 2 ;; esac
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for lib in "$HERE/lib/restore-integrity.sh" "$HERE/lib/restore-sandbox.sh" "$HERE/lib/restore-vm.sh"; do
  [ -f "$lib" ] && [ ! -L "$lib" ] || exit 2
  # shellcheck source=/dev/null
  . "$lib"
done
INNER="$HERE/restore-drill-sandbox-inner.sh"
WORK="$(mktemp -d)" || exit 2
LINES="$WORK/lines"
: > "$LINES"
fact() { printf '%s=%s\n' "$1" "$2" >> "$LINES"; }
ARCH=""
finish() {
  dms_vm_output_write "$OUTPUT" "$LINES" || true
  if [ -n "$ARCH" ]; then umount -- "$ARCH" 2> /dev/null; rmdir -- "$ARCH" 2> /dev/null; fi
  rm -rf -- "$WORK"
}
trap finish EXIT

if ! dms_vm_input_parse "$INPUT"; then
  fact guest_stage input_header_invalid
  exit 1
fi
RUN_ID="${DMS_VM_INPUT[run_id]}"
# The VM's own network interfaces, as the guest kernel sees them (the host requires `lo` only: no NIC, D1).
shopt -s nullglob
ifaces=(/sys/class/net/*)
shopt -u nullglob
ifaces=("${ifaces[@]##*/}")
fact vm_net_ifaces "$(IFS=:; printf '%s' "${ifaces[*]:-none}" | tr -cd 'A-Za-z0-9_.:-' | head -c 80)"
BYTES="${DMS_VM_INPUT[payload_bytes]}"
fact run_id "$RUN_ID"
KEY="${DMS_VM_INPUT[payload_key]}"
IV="${DMS_VM_INPUT[payload_iv]}"
GOT_SHA="$(dms_vm_input_payload "$INPUT" "$BYTES" "$KEY" "$IV" | sha256sum | cut -d' ' -f1)"
fact payload_sha256 "$GOT_SHA"
if [ "$GOT_SHA" != "${DMS_VM_INPUT[payload_sha256]}" ]; then
  fact guest_stage payload_digest_mismatch
  exit 1
fi
if [ "$MODE" = system ] && getent passwd "$RUN_ID" > /dev/null 2>&1; then
  # DR-11: DynamicUser would silently reuse an existing account of that name.
  fact guest_stage run_id_names_existing_user
  exit 1
fi
# The archive, decrypted once into this agent's private directory, and the load guard under a fresh tag (D5). Both are
# bound read-only into the unit; the unit reads nothing else from this agent.
# Phase 3 (plaintext at rest): in the VM (system mode) the plaintext archive lives only on a size-capped RAM tmpfs that
# this agent mounts; it never reaches the root overlay, which is a file on the drill host's disk. (User mode, used only
# by the local test harness with synthetic archives, has no mount right and keeps it in its private directory.)
if [ "$MODE" = system ]; then
  ARCH="$(mktemp -d -p /run dmsarchive.XXXXXX)" || { fact guest_stage archive_tmpfs_failed; exit 1; }
  mount -t tmpfs -o "size=$(( BYTES / 1048576 + 16 ))m,mode=0700,noexec,nosuid,nodev" tmpfs "$ARCH" \
    || { fact guest_stage archive_tmpfs_failed; exit 1; }
  ADIR="$ARCH"
else
  ADIR="$WORK"
fi
dms_vm_input_payload "$INPUT" "$BYTES" "$KEY" "$IV" > "$ADIR/archive.dump"
[ "$(sha256sum < "$ADIR/archive.dump" | cut -d' ' -f1)" = "${DMS_VM_INPUT[payload_sha256]}" ] || { fact guest_stage archive_digest_mismatch; exit 1; }
TAG="g$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"
dms_restore_load_guard_sql "$TAG" > "$WORK/guard.sql" && printf 'SELECT pg_temp.dms_restore_guard_%s();\n' "$TAG" >> "$WORK/guard.sql" || { fact guest_stage guard_build_failed; exit 1; }
# systemd binds these as root, so the directory stays 0700; the unit's identity only needs the files readable.
chmod 0644 "$ADIR/archive.dump" "$WORK/guard.sql"
mapfile -t PROPS < <(dms_sandbox_properties "$MODE" "$RUN_ID" "${DMS_VM_INPUT[tmpfs_mib]}" "${DMS_VM_INPUT[memory_mib]}" "${DMS_VM_INPUT[tasks]}" "${DMS_VM_INPUT[timeout_seconds]}" "$INNER" "$ADIR/archive.dump" "$WORK/guard.sql")
if [ "${#PROPS[@]}" -lt 40 ]; then
  fact guest_stage profile_build_failed
  exit 1
fi
trap 'dms_sandbox_stop "$MODE" "$RUN_ID"; finish' EXIT
trap 'exit 143' TERM HUP
# F3: production's restore-session settings, from the host's verified contract.
# shellcheck disable=SC2034  # read by dms_sandbox_run (scripts/lib/restore-sandbox.sh)
DMS_SANDBOX_ENV_PINS="${DMS_VM_INPUT[env_pins]}"
dms_sandbox_run "$MODE" "$RUN_ID" "$WORK/raw" "$WORK/err" "${DMS_VM_INPUT[roles]}" "${DMS_VM_INPUT[locale]}" "${DMS_VM_INPUT[runtime_role]}" "${DMS_VM_INPUT[extension_allowlist]}" "${PROPS[@]}"
fact unit_exit "$?"
dms_sandbox_parse "$WORK/raw"
fact result_duplicates "$DMS_SANDBOX_DUPLICATES"
fact result_rejected "$DMS_SANDBOX_REJECTED"
for k in profile bootstrap_superuser roles_created replay replay_section replay_exit replay_sqlstate stage table_count migrations_table base_table_count rows_total table_digest fidelity_trg_con_fn_idx user_code_reachable user_code_executed catalog_shape extensions_outside_allowlist runtime_owned_objects env_digest result; do
  [ -n "${DMS_SANDBOX_RESULT[$k]+x}" ] && fact "sandbox_${k}" "${DMS_SANDBOX_RESULT[$k]}"
done
fact guest_stage "done"
exit 0
