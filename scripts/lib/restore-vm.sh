# shellcheck shell=bash disable=SC2034  # library globals are read by the scripts that source it
#
# R6-B6 isolated restore drill — the disposable-VM transport (owner directive D1, 2026-11-25).
#
# The drill VM has NO network device. The host hands it one read-only raw disk (the input) and one small raw disk (the
# output); no filesystem is needed on either, so the host builds them without privileges:
#   input   4096-byte header (text, NUL-padded), then the payload (the custom-format archive, PR-6) ENCRYPTED with
#           AES-256-CTR under a fresh per-run key carried in the header, padded to 512 bytes. The encryption is not for
#           secrecy (the key travels with it): it makes every byte the guest kernel, udev or libblkid could probe on
#           the disk unpredictable, so dump content can never be shaped into a superblock or partition table (VR-6)
#             DMSDRILL-INPUT 1
#             <key>=<value>   for every key in DMS_VM_INPUT_KEYS, once
#             END
#   output  written by the guest agent, read by the host up to DMS_VM_OUTPUT_READ_BYTES:
#             DMSDRILL-OUTPUT 1
#             <key>=<value> …  (the guest's own facts, then the sandbox result lines)
#             END
# Everything read back from the output disk is untrusted and strictly parsed.

if [ -n "${DMS_RESTORE_VM_LOADED:-}" ]; then
  return 0
fi
DMS_RESTORE_VM_LOADED=1

DMS_VM_HEADER_BYTES=4096
DMS_VM_OUTPUT_BYTES=65536
DMS_VM_OUTPUT_READ_BYTES=8192
DMS_VM_INPUT_KEYS="run_id payload_bytes payload_sha256 payload_key payload_iv roles runtime_role extension_allowlist locale tmpfs_mib memory_mib tasks timeout_seconds"
DMS_VM_INPUT_SERIAL=dmsdrill-input
DMS_VM_OUTPUT_SERIAL=dmsdrill-output
# shellcheck disable=SC2034  # read by the guest agent
declare -gA DMS_VM_INPUT=()
declare -gA DMS_VM_OUTPUT=()
DMS_VM_OUTPUT_DUPLICATES=0
DMS_VM_OUTPUT_REJECTED=0

# _dms_vm_input_value_ok <key> <value>
_dms_vm_input_value_ok() {
  case "$1" in
    run_id) [[ "$2" =~ ^dmsdrill-[0-9a-f]{12}$ ]] ;;
    payload_bytes) [[ "$2" =~ ^[1-9][0-9]{0,11}$ ]] ;;
    payload_sha256 | payload_key) [[ "$2" =~ ^[0-9a-f]{64}$ ]] ;;
    payload_iv) [[ "$2" =~ ^[0-9a-f]{32}$ ]] ;;
    roles) [[ "$2" =~ ^[a-z_][a-z0-9_]{0,62}(\ [a-z_][a-z0-9_]{0,62}){0,63}$ ]] ;;
    runtime_role) [[ "$2" =~ ^[a-z_][a-z0-9_]{0,62}$ ]] ;;
    extension_allowlist) [[ "$2" =~ ^(none|[a-z_][a-z0-9_]{0,62}(\ [a-z_][a-z0-9_]{0,62}){0,15})$ ]] ;;
    locale) [[ "$2" =~ ^[A-Za-z0-9_.@-]{1,32}$ ]] ;;
    tmpfs_mib | memory_mib | tasks | timeout_seconds) [[ "$2" =~ ^[1-9][0-9]{0,6}$ ]] ;;
    *) return 1 ;;
  esac
}

# dms_vm_input_build <input image> <plaintext payload file> <assoc array name with every DMS_VM_INPUT_KEYS key>
dms_vm_input_build() {
  local img="${1:?image}" payload="${2:?payload}" arr="${3:?array}" k header size pad
  local -n src="$arr"
  header="DMSDRILL-INPUT 1"$'\n'
  for k in $DMS_VM_INPUT_KEYS; do
    _dms_vm_input_value_ok "$k" "${src[$k]-}" || { echo "vm: invalid input value for ${k}" >&2; return 1; }
    header+="${k}=${src[$k]}"$'\n'
  done
  header+="END"$'\n'
  [ "${#header}" -lt "$DMS_VM_HEADER_BYTES" ] || { echo "vm: header too large" >&2; return 1; }
  size="$(stat -c %s -- "$payload")" || return 1
  [ "$size" = "${src[payload_bytes]}" ] || { echo "vm: payload size differs from the header" >&2; return 1; }
  {
    printf '%s' "$header"
    head -c $((DMS_VM_HEADER_BYTES - ${#header})) /dev/zero
    openssl enc -aes-256-ctr -K "${src[payload_key]}" -iv "${src[payload_iv]}" -in "$payload" || return 1
    pad=$(((512 - size % 512) % 512))
    [ "$pad" -eq 0 ] || head -c "$pad" /dev/urandom
  } > "$img"
}

# dms_vm_input_key_iv
# Prints "<key hex64> <iv hex32>" from the kernel's random source.
dms_vm_input_key_iv() {
  local key iv
  key="$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')" && iv="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')" || return 1
  [[ "$key" =~ ^[0-9a-f]{64}$ && "$iv" =~ ^[0-9a-f]{32}$ ]] || return 1
  printf '%s %s\n' "$key" "$iv"
}

# dms_vm_input_parse <input device or image>
# Guest side. Fills DMS_VM_INPUT from the header; returns 1 on any deviation.
dms_vm_input_parse() {
  local dev="${1:?device}" line first=1 ended=0 k v
  DMS_VM_INPUT=()
  local -A seen=()
  while IFS= read -r line; do
    if [ "$first" = 1 ]; then [ "$line" = "DMSDRILL-INPUT 1" ] || return 1; first=0; continue; fi
    if [ "$line" = END ]; then ended=1; break; fi
    [[ "$line" =~ ^([a-z0-9_]{1,32})=(.*)$ ]] || return 1
    k="${BASH_REMATCH[1]}"; v="${BASH_REMATCH[2]}"
    [ -z "${seen[$k]:-}" ] || return 1
    _dms_vm_input_value_ok "$k" "$v" || return 1
    seen[$k]=1
    DMS_VM_INPUT[$k]="$v"
  done < <(head -c "$DMS_VM_HEADER_BYTES" -- "$dev" | tr -d '\000')
  [ "$ended" = 1 ] || return 1
  for k in $DMS_VM_INPUT_KEYS; do [ -n "${seen[$k]:-}" ] || return 1; done
  return 0
}

# dms_vm_input_payload <input device or image> <payload bytes> <key> <iv>
# Streams exactly the decrypted payload to stdout.
dms_vm_input_payload() {
  tail -c +$((DMS_VM_HEADER_BYTES + 1)) -- "${1:?device}" | head -c "${2:?bytes}" \
    | openssl enc -d -aes-256-ctr -K "${3:?key}" -iv "${4:?iv}"
}

# dms_vm_output_write <output device or image> <lines file>
# Guest side: writes the framed output, NUL-padded to the output disk size. The lines file holds key=value lines the
# guest already validated.
dms_vm_output_write() {
  local dev="${1:?device}" lines="${2:?lines}" body
  body="DMSDRILL-OUTPUT 1"$'\n'"$(head -c $((DMS_VM_OUTPUT_READ_BYTES - 64)) -- "$lines")"$'\n'"END"$'\n'
  { printf '%s' "$body"; head -c $((DMS_VM_OUTPUT_BYTES - ${#body})) /dev/zero; } | dd of="$dev" bs=4096 conv=fsync,notrunc status=none
}

# dms_vm_output_parse <output image>
# Host side: reads at most DMS_VM_OUTPUT_READ_BYTES; requires the frame; accepts only `key=value` lines with a bounded
# key and charset, each key once. Fills DMS_VM_OUTPUT; counts duplicates and rejected lines. Returns 1 when the frame
# is missing (no END, no header).
dms_vm_output_parse() {
  local img="${1:?image}" line first=1 ended=0
  DMS_VM_OUTPUT=()
  DMS_VM_OUTPUT_DUPLICATES=0
  DMS_VM_OUTPUT_REJECTED=0
  while IFS= read -r line; do
    if [ "$first" = 1 ]; then [ "$line" = "DMSDRILL-OUTPUT 1" ] || return 1; first=0; continue; fi
    if [ "$line" = END ]; then ended=1; break; fi
    if [[ "$line" =~ ^([a-z0-9_]{1,40})=([A-Za-z0-9_.:-]{0,80})$ ]]; then
      if [ -n "${DMS_VM_OUTPUT[${BASH_REMATCH[1]}]+x}" ]; then
        DMS_VM_OUTPUT_DUPLICATES=$((DMS_VM_OUTPUT_DUPLICATES + 1))
      else
        DMS_VM_OUTPUT[${BASH_REMATCH[1]}]="${BASH_REMATCH[2]}"
      fi
    else
      DMS_VM_OUTPUT_REJECTED=$((DMS_VM_OUTPUT_REJECTED + 1))
    fi
  done < <(head -c "$DMS_VM_OUTPUT_READ_BYTES" -- "$img" | tr -d '\000')
  [ "$ended" = 1 ]
}

# dms_vm_qemu_args <root overlay qcow2> <input image> <output image> <vcpu> <memory MiB>
# Prints the QEMU arguments, one per line: KVM, no default devices, NO network device, no display or serial, the
# root overlay plus the read-only input and the output disk (each identified by serial for the guest), and QEMU's own
# seccomp sandbox. The caller wraps QEMU in `timeout` for the D6 wall-clock limit.
dms_vm_qemu_args() {
  local root="${1:?root}" input="${2:?input}" output="${3:?output}" vcpu="${4:?vcpu}" mem="${5:?memory}"
  # VR-7: no nested virtualisation, no PMU, no vmport/HPET/SMM. VR-3a: disks are -drive if=none + -device
  # virtio-blk-pci (the serial is a device property; `-drive …,serial=` was removed in QEMU 3.0).
  printf '%s\n' \
    -machine q35,accel=kvm,vmport=off,hpet=off,smm=off -cpu host,-vmx,-svm,pmu=off -smp "$vcpu" -m "$mem" \
    -nodefaults -no-user-config -nographic -display none -serial none -monitor none -parallel none \
    -nic none -no-reboot \
    -sandbox on,obsolete=deny,elevateprivileges=deny,spawn=deny,resourcecontrol=deny \
    -drive "if=none,id=dmsroot,file=${root},format=qcow2,discard=unmap" -device virtio-blk-pci,drive=dmsroot,bootindex=0 \
    -drive "if=none,id=dmsin,file=${input},format=raw,readonly=on" -device "virtio-blk-pci,drive=dmsin,serial=${DMS_VM_INPUT_SERIAL}" \
    -drive "if=none,id=dmsout,file=${output},format=raw" -device "virtio-blk-pci,drive=dmsout,serial=${DMS_VM_OUTPUT_SERIAL}"
}

# dms_vm_image_info_ok <qemu-img info JSON file> <max virtual bytes>
# VR-3b: parses the top level of `qemu-img info -f qcow2 --output=json` with a real JSON parser (QEMU 8 adds a
# "children" array whose own "format" would win a line-based match). Requires format qcow2, a virtual size at most the
# limit, no backing file and no external data file. Prints the virtual size, or the reason and returns 1.
dms_vm_image_info_ok() {
  python3 -I -c '
import json, sys
d = json.load(open(sys.argv[1]))
lim = int(sys.argv[2])
if not isinstance(d, dict): sys.exit("not a JSON object")
if d.get("format") != "qcow2": sys.exit("the drill image is not qcow2")
v = d.get("virtual-size")
if not isinstance(v, int) or v <= 0 or v > lim: sys.exit("the drill image virtual size is missing or exceeds the limit")
if "backing-filename" in d or "full-backing-filename" in d: sys.exit("the drill image must not have a backing file")
fs = (d.get("format-specific") or {}).get("data") or {}
if fs.get("data-file") or fs.get("data-file-raw"): sys.exit("the drill image must not use an external data file")
print(v)
' "${1:?json}" "${2:?limit}"
}

# dms_vm_kill_group <pid of the timeout wrapper>
# VR-2: `timeout` leads its own process group, so killing the group reaches QEMU; killing the PID alone would not.
dms_vm_kill_group() {
  local pid="${1:-}"
  [[ "$pid" =~ ^[1-9][0-9]*$ ]] || return 0
  kill -KILL -- "-${pid}" 2> /dev/null || kill -KILL "$pid" 2> /dev/null || true
}

# dms_vm_decision_safe <decision>
# VR-8: a FAIL reason that embeds guest-chosen text must still fit the evidence schema, or the record would be refused
# and the FAIL left unrecorded. Prints GO, the reason unchanged when it fits, or FAIL:guest_invalid_value.
dms_vm_decision_safe() {
  local d="${1:-}"
  if [ "$d" = GO ] || [[ "$d" =~ ^FAIL:[a-z0-9_]{1,60}$ ]]; then printf '%s\n' "$d"; else printf 'FAIL:guest_invalid_value\n'; fi
}

# dms_vm_decide <qemu exit> <run id> <payload sha256> <expected per-table digest> <expected tables>
# Host side, after dms_vm_output_parse. Prints GO or FAIL:<reason>. The VM's own facts must echo what the host sent;
# then the sandbox result must pass dms_sandbox_decide against the per-table digest the host computed from the archive.
# Everything read here is GUEST-ASSERTED (VR-1); the evidence records it as such.
dms_vm_decide() {
  local qemu_exit="${1:-x}" run="${2:-}" sha="${3:-}" exp_digest="${4:-}" exp_tables="${5:-}" k
  [ "$qemu_exit" = 0 ] || { echo "FAIL:vm_exit_${qemu_exit}"; return 0; }
  [ "$DMS_VM_OUTPUT_DUPLICATES" = 0 ] && [ "$DMS_VM_OUTPUT_REJECTED" = 0 ] || { echo "FAIL:vm_output_malformed"; return 0; }
  [ "${DMS_VM_OUTPUT[run_id]:-}" = "$run" ] || { echo "FAIL:vm_run_id_mismatch"; return 0; }
  [ "${DMS_VM_OUTPUT[payload_sha256]:-}" = "$sha" ] || { echo "FAIL:vm_payload_digest_mismatch"; return 0; }
  [ "${DMS_VM_OUTPUT[guest_stage]:-}" = "done" ] || { echo "FAIL:guest_${DMS_VM_OUTPUT[guest_stage]:-no_stage}"; return 0; }
  # shellcheck disable=SC2034  # read by dms_sandbox_decide
  DMS_SANDBOX_RESULT=()
  for k in "${!DMS_VM_OUTPUT[@]}"; do
    case "$k" in sandbox_*) DMS_SANDBOX_RESULT[${k#sandbox_}]="${DMS_VM_OUTPUT[$k]}" ;; esac
  done
  # shellcheck disable=SC2034
  DMS_SANDBOX_DUPLICATES="${DMS_VM_OUTPUT[result_duplicates]:-x}"
  # shellcheck disable=SC2034
  DMS_SANDBOX_REJECTED="${DMS_VM_OUTPUT[result_rejected]:-x}"
  dms_sandbox_decide "${DMS_VM_OUTPUT[unit_exit]:-x}" "$exp_digest" "$exp_tables"
}
