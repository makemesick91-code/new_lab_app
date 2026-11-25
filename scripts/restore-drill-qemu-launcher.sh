#!/bin/bash
#
# R6-B6 isolated restore drill — the QEMU LAUNCHER (owner directive "R6-B6 — Phase 3", mandatory hardening 1).
#
# Runs as a DEDICATED QEMU identity (for example dmsdrill-qemu), inside the system unit `dmsqemu.service` that the
# root supervisor (scripts/restore-drill-supervisor.sh) starts (review round 6, R6A-1/R6A-2): no network, a cgroup
# killed whole at the time limit, no swap, no core dump, KVM only through the kvm group. A device-model escape lands
# in an account that holds no key, reaches no signer and no network, cannot read the runner's files, cannot write
# the drill configuration (root-owned) and cannot outlive the unit.
#
# Least-privilege channel: stdin carries the encrypted input disk (bounded), stdout the fixed-size output disk, stderr
# one status line `QEMU_LAUNCH exit=<n> image_sha256=<hex>`. Everything else (the pinned image, the QEMU binaries, the
# D6 limits, the QEMU command line) comes from this script and its root-owned configuration, never from the caller.
#
# Configuration: /etc/dms-restore-drill/qemu.conf, owned by root and writable by nobody else (the QEMU identity must
# not be able to change its own next run). Keys:
#   DMS_QEMU_BINARY, DMS_QEMU_IMG          absolute paths of qemu-system-x86_64 and qemu-img
#   DMS_QEMU_IMAGE, DMS_QEMU_IMAGE_SHA256  the drill image (built by scripts/restore-drill-vm-image.sh) and its digest
#   DMS_QEMU_WORK_DIR                      a directory owned by the QEMU identity, mode 0700 (the overlay)
#   DMS_QEMU_CONSOLE_DIR                   `none` (default), or a directory owned by the QEMU identity that keeps the
#                                          guest console of each run (disposable CI only: synthetic data)
# Exit: 0 the VM ran (its own exit is in the status line), 1 refused, 2 misconfigured.
set -uo pipefail
umask 077
if [ "${DMS_QEMU_CLEAN_ENV:-}" != 1 ]; then
  exec /usr/bin/env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C.UTF-8 DMS_QEMU_CLEAN_ENV=1 \
    DMS_QEMU_CONF="${DMS_QEMU_CONF:-/etc/dms-restore-drill/qemu.conf}" RUNTIME_DIRECTORY="${RUNTIME_DIRECTORY:-}" \
    SUDO_USER="${SUDO_USER:-}" SUDO_UID="${SUDO_UID:-}" /bin/bash "$0" "$@"
fi
ulimit -c 0
status() { printf 'QEMU_LAUNCH %s\n' "$*" >&2; }
die() { status "refused=$1"; exit "${2:-1}"; }
[ "$(id -u)" != 0 ] || die run_as_root 2

# The launcher's own code: owned by root or by this identity, writable by nobody else, in trusted directories.
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
# R6A-9: root's alone; the QEMU identity must never own the code it runs.
_owned_ok() { local st; st="$(stat -c '%u %a' -- "$1")" || return 1; [ "${st% *}" = 0 ] && [ $(( 8#${st#* } & 8#022 )) -eq 0 ]; }
_sticky_root() { local st; st="$(stat -c '%u %a' -- "$1")" && [ "${st% *}" = 0 ] && [ $(( 8#${st#* } & 8#1000 )) -ne 0 ]; }
_owned_ok "${HERE}/$(basename "${BASH_SOURCE[0]}")" && _owned_ok "$HERE" || die launcher_code_unsafe 2
d="$HERE"
while :; do
  _owned_ok "$d" || _sticky_root "$d" || die launcher_directory_unsafe 2
  [ "$d" = / ] && break
  d="$(dirname -- "$d")"
done
for lib in trusted-path.sh restore-vm.sh; do
  node="${HERE}/lib/${lib}"
  [ ! -L "$node" ] && [ -f "$node" ] && _owned_ok "$node" && _owned_ok "${HERE}/lib" || die launcher_library_unsafe 2
  # shellcheck source=/dev/null
  . "$node"
done

MODE="${1:-}"; RUN_ID="${2:-}"
[ "$MODE" = run ] && [[ "$RUN_ID" =~ ^dmsdrill-[0-9a-f]{12}$ ]] || die usage 2
# Only inside the supervisor's unit, whose RAM runtime directory holds the disks and disappears with it.
grep -qx '0::/system.slice/dmsqemu.service' /proc/self/cgroup || die not_in_the_supervised_unit
[ "${RUNTIME_DIRECTORY:-}" = /run/dmsqemu ] && [ "$(stat -c '%u %a' -- /run/dmsqemu 2> /dev/null)" = "$(id -u) 700" ] || die no_runtime_directory

# --- Root-owned configuration (parsed, never sourced) ---------------------------------------------------------------
CONF="$DMS_QEMU_CONF"
dms_trusted_file "QEMU configuration" "$CONF" 0 "$(cd "$HERE/.." && pwd)" > /dev/null || die config_untrusted 2
KEYS="DMS_QEMU_BINARY DMS_QEMU_IMG DMS_QEMU_IMAGE DMS_QEMU_IMAGE_SHA256 DMS_QEMU_WORK_DIR DMS_QEMU_CONSOLE_DIR"
declare -A C=()
while IFS= read -r line || [ -n "$line" ]; do
  case "$line" in '' | '#'*) continue ;; esac
  [[ "$line" =~ ^(DMS_QEMU_[A-Z0-9_]+)=([A-Za-z0-9_./@:\ -]{1,255})$ ]] || die config_line 2
  [[ " ${KEYS} " == *" ${BASH_REMATCH[1]} "* ]] && [ -z "${C[${BASH_REMATCH[1]}]:-}" ] || die config_key 2
  C[${BASH_REMATCH[1]}]="${BASH_REMATCH[2]}"
done < "$CONF"
for k in $KEYS; do [ -n "${C[$k]:-}" ] || die "config_missing_${k,,}" 2; done
[[ "${C[DMS_QEMU_IMAGE_SHA256]}" =~ ^[0-9a-f]{64}$ ]] || die config_image_sha 2
for k in DMS_QEMU_BINARY DMS_QEMU_IMG DMS_QEMU_IMAGE; do
  dms_trusted_file "$k" "${C[$k]}" 0 "$(cd "$HERE/.." && pwd)" > /dev/null || die "untrusted_${k,,}" 2
done
dms_trusted_dir DMS_QEMU_WORK_DIR "${C[DMS_QEMU_WORK_DIR]}" "$(id -u)" "$(cd "$HERE/.." && pwd)" > /dev/null || die work_dir_untrusted 2
[ "$(stat -c '%u %a' -- "${C[DMS_QEMU_WORK_DIR]}")" = "$(id -u) 700" ] || die work_dir_not_private 2
CONSOLE=""; QERR=/dev/null
if [ "${C[DMS_QEMU_CONSOLE_DIR]}" != none ]; then
  dms_trusted_canonical "${C[DMS_QEMU_CONSOLE_DIR]}" && [ "$(stat -c '%u %a' -- "${C[DMS_QEMU_CONSOLE_DIR]}" 2> /dev/null)" = "$(id -u) 700" ] || die console_dir_not_private 2
  CONSOLE="${C[DMS_QEMU_CONSOLE_DIR]}/${RUN_ID}.log"
  QERR="${C[DMS_QEMU_CONSOLE_DIR]}/${RUN_ID}.stderr"
fi

# --- D6, fixed here: the caller chooses none of it --------------------------------------------------------------------
VCPU=1; MEMORY_MIB=2048; DISK_GIB=10; MINUTES=30
INPUT_MAX_BYTES=$((256 * 1024 * 1024 + 4096))
[ -r /dev/kvm ] && [ -w /dev/kvm ] || die kvm_unavailable
# One VM at a time: the supervisor's fixed unit name; no lock file the QEMU identity could keep or remove.

# --- The pinned image -----------------------------------------------------------------------------------------------
IMAGE_SHA="$(sha256sum -- "${C[DMS_QEMU_IMAGE]}" | cut -d' ' -f1)"
[ "$IMAGE_SHA" = "${C[DMS_QEMU_IMAGE_SHA256]}" ] || die image_digest_mismatch
QWORK="$(mktemp -d "${C[DMS_QEMU_WORK_DIR]}/${RUN_ID}.XXXXXX")" || die work_dir_create 2
QEMU_PID=""
cleanup() {
  dms_vm_kill_group "$QEMU_PID"
  rm -rf -- "$QWORK" /run/dmsqemu/input.raw /run/dmsqemu/output.raw
}
trap cleanup EXIT
# A relayed TERM/INT/HUP (the operator's sudo forwards them) stops the VM and removes every run file (forced termination).
trap 'status "aborted=signal"; exit 143' TERM INT HUP
"${C[DMS_QEMU_IMG]}" info -f qcow2 --output=json -- "${C[DMS_QEMU_IMAGE]}" > "$QWORK/image-info.json" 2> /dev/null || die image_not_qcow2
dms_vm_image_info_ok "$QWORK/image-info.json" $((DISK_GIB * 1024 * 1024 * 1024)) > /dev/null 2>&1 || die image_info_invalid

# --- The input disk, from stdin, bounded and with a deadline, onto the unit's RAM runtime directory (R6A-6) ---------
RT=/run/dmsqemu
timeout 120 head -c $((INPUT_MAX_BYTES + 1)) > "$RT/input.raw" || die input_read
IN_BYTES="$(stat -c %s -- "$RT/input.raw")"
[ "$IN_BYTES" -ge 4096 ] && [ "$IN_BYTES" -le "$INPUT_MAX_BYTES" ] || die input_size
[ "$(head -n 1 -- "$RT/input.raw" | head -c 32)" = "DMSDRILL-INPUT 1" ] || die input_magic
head -c "$DMS_VM_OUTPUT_BYTES" /dev/zero > "$RT/output.raw"
"${C[DMS_QEMU_IMG]}" create -q -f qcow2 -F qcow2 -b "${C[DMS_QEMU_IMAGE]}" "$QWORK/root.qcow2" > /dev/null 2>&1 || die overlay_create
mapfile -t QARGS < <(dms_vm_qemu_args "$QWORK/root.qcow2" "$RT/input.raw" "$RT/output.raw" "$VCPU" "$MEMORY_MIB")
if [ -n "$CONSOLE" ]; then
  # Disposable CI only: the guest console, for boot evidence. Never enabled on a drill host that restores real data.
  for i in "${!QARGS[@]}"; do [ "${QARGS[$i]}" = "-serial" ] && QARGS[$((i + 1))]="file:${CONSOLE}"; done
fi
timeout --kill-after=10 "$((MINUTES * 60))" "${C[DMS_QEMU_BINARY]}" "${QARGS[@]}" < /dev/null > /dev/null 2> "$QERR" &
QEMU_PID=$!
wait "$QEMU_PID"
QEMU_EXIT=$?
QEMU_PID=""
head -c "$DMS_VM_OUTPUT_BYTES" -- "$RT/output.raw"
status "exit=${QEMU_EXIT} image_sha256=${IMAGE_SHA}"
exit 0
