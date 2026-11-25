#!/usr/bin/env bash
#
# R6-B6 isolated restore drill — the guest agent, INSIDE the disposable VM (owner directives D1, D2).
#
# Installed in the drill VM image and started once at boot by deploy/restore-drill-vm/dms-restore-drill-guest.service
# as root, with the input and output disks the host attached (by serial) and the mode `system`. It:
#   1. parses the input header strictly and re-verifies the payload's sha256 (the host computed it);
#   2. runs the replay in a transient systemd SYSTEM unit under DynamicUser (scripts/lib/restore-sandbox.sh) with the
#      payload on stdin. This agent only decrypts and streams the bytes (AES-256-CTR under the per-run key in the
#      header, so nothing on the disk is attacker-shaped, VR-6) and never interprets them; only the unit runs them;
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
for lib in "$HERE/lib/restore-sandbox.sh" "$HERE/lib/restore-vm.sh"; do
  [ -f "$lib" ] && [ ! -L "$lib" ] || exit 2
  # shellcheck source=/dev/null
  . "$lib"
done
INNER="$HERE/restore-drill-sandbox-inner.sh"
WORK="$(mktemp -d)" || exit 2
LINES="$WORK/lines"
: > "$LINES"
fact() { printf '%s=%s\n' "$1" "$2" >> "$LINES"; }
finish() {
  dms_vm_output_write "$OUTPUT" "$LINES" || true
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
mapfile -t PROPS < <(dms_sandbox_properties "$MODE" "$RUN_ID" "${DMS_VM_INPUT[tmpfs_mib]}" "${DMS_VM_INPUT[memory_mib]}" "${DMS_VM_INPUT[tasks]}" "${DMS_VM_INPUT[timeout_seconds]}" "$INNER")
if [ "${#PROPS[@]}" -lt 40 ]; then
  fact guest_stage profile_build_failed
  exit 1
fi
trap 'dms_sandbox_stop "$MODE" "$RUN_ID"; finish' EXIT
trap 'exit 143' TERM HUP
dms_vm_input_payload "$INPUT" "$BYTES" "$KEY" "$IV" \
  | dms_sandbox_run "$MODE" "$RUN_ID" "$WORK/raw" "$WORK/err" "${DMS_VM_INPUT[roles]}" "${DMS_VM_INPUT[locale]}" "${DMS_VM_INPUT[runtime_role]}" "${PROPS[@]}"
ST=("${PIPESTATUS[@]}")
# The feeder fails (SIGPIPE) when the unit stops reading early: a short stream is never a GO.
fact unit_exit "${ST[1]:-1}"
fact feed_exit "${ST[0]:-1}"
dms_sandbox_parse "$WORK/raw"
fact result_duplicates "$DMS_SANDBOX_DUPLICATES"
fact result_rejected "$DMS_SANDBOX_REJECTED"
for k in profile bootstrap_superuser roles_created replay replay_exit replay_sqlstate stage table_count migrations_table base_table_count rows_total fidelity_trg_con_fn_idx user_code_reachable runtime_owned_objects result; do
  [ -n "${DMS_SANDBOX_RESULT[$k]+x}" ] && fact "sandbox_${k}" "${DMS_SANDBOX_RESULT[$k]}"
done
fact guest_stage "done"
exit 0
