#!/bin/bash
#
# R6-B6 isolated restore drill — the root SUPERVISOR (review round 6: R6A-1, R6A-2, R6A-3, R6A-6, R6A-11, R6C-3).
#
# The only root code of the drill. The drill RUNNER identity reaches it through one sudoers rule:
#   sudo -n <this script> inspect dmsinspect-<12 hex>   < archive   > inspection stream
#   sudo -n <this script> vm dmsdrill-<12 hex>          < input disk > output disk   (status line on stderr)
# and it does exactly two things, each in a transient SYSTEM unit whose bounds the work inside cannot touch:
#   inspect  pg_restore over the untrusted archive in a DynamicUser unit (no user namespace needed, so no AppArmor
#            exception on the drill host); the archive arrives on stdin.
#   vm       the QEMU launcher under the QEMU account in the unit `dmsqemu.service` (one VM at a time): no network,
#            a cgroup that is killed whole at the time limit, memory, task and swap caps, no core dumps, KVM only
#            through the kvm group. It starts a VM only for the run whose signed STARTED record is the newest record
#            of the evidence store (R6C-3: every VM boot has a recorded start).
# It never takes a path, a property or a command from its caller; only the mode and a name it validates.
#
# Configuration: /etc/dms-restore-drill/supervisor.conf, root-owned, writable by nobody else. Keys:
#   DMS_SUPERVISOR_CALLER       the drill runner account (the only caller)
#   DMS_SUPERVISOR_QEMU_USER    the QEMU account;  DMS_SUPERVISOR_SIGNER_USER  the signer account (must differ)
#   DMS_SUPERVISOR_QEMU_LAUNCHER, DMS_SUPERVISOR_QEMU_WORK_DIR, DMS_SUPERVISOR_PG_BINDIR, DMS_SUPERVISOR_SIGNER_STORE
# Exit: the unit's outcome (inspect), 0 when the VM ran (vm; its own exit is in the launcher's status line), 2 refused.
set -uo pipefail
umask 077
if [ "${DMS_SUPERVISOR_CLEAN_ENV:-}" != 1 ]; then
  exec /usr/bin/env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C.UTF-8 DMS_SUPERVISOR_CLEAN_ENV=1 \
    SUDO_USER="${SUDO_USER:-}" SUDO_UID="${SUDO_UID:-}" /bin/bash "$0" "$@"
fi
ulimit -c 0
refuse() { echo "SUPERVISOR refused=$1" >&2; exit 2; }
[ "$(id -u)" = 0 ] || refuse not_root
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
# Root code only: this script, its libraries and every directory above them are root's and writable by nobody else.
_root_ok() { local st; st="$(stat -c '%u %a' -- "$1")" || return 1; [ "${st% *}" = 0 ] && [ $(( 8#${st#* } & 8#022 )) -eq 0 ]; }
d="$HERE"
while :; do _root_ok "$d" || refuse code_directory_unsafe; [ "$d" = / ] && break; d="$(dirname -- "$d")"; done
_root_ok "${HERE}/$(basename "${BASH_SOURCE[0]}")" || refuse code_unsafe
for lib in trusted-path.sh restore-vm.sh restore-archive.sh; do
  node="${HERE}/lib/${lib}"
  [ ! -L "$node" ] && [ -f "$node" ] && _root_ok "$node" && _root_ok "${HERE}/lib" || refuse library_unsafe
  # shellcheck source=/dev/null
  . "$node"
done
CONF=/etc/dms-restore-drill/supervisor.conf
dms_trusted_file "supervisor configuration" "$CONF" 0 "$(cd "$HERE/.." && pwd)" > /dev/null || refuse config_untrusted
KEYS="DMS_SUPERVISOR_CALLER DMS_SUPERVISOR_QEMU_USER DMS_SUPERVISOR_SIGNER_USER DMS_SUPERVISOR_QEMU_LAUNCHER DMS_SUPERVISOR_QEMU_WORK_DIR DMS_SUPERVISOR_PG_BINDIR DMS_SUPERVISOR_SIGNER_STORE"
declare -A C=()
while IFS= read -r line || [ -n "$line" ]; do
  case "$line" in '' | '#'*) continue ;; esac
  [[ "$line" =~ ^(DMS_SUPERVISOR_[A-Z0-9_]+)=([A-Za-z0-9_./@:-]{1,255})$ ]] || refuse config_line
  [[ " ${KEYS} " == *" ${BASH_REMATCH[1]} "* ]] && [ -z "${C[${BASH_REMATCH[1]}]:-}" ] || refuse config_key
  C[${BASH_REMATCH[1]}]="${BASH_REMATCH[2]}"
done < "$CONF"
for k in $KEYS; do [ -n "${C[$k]:-}" ] || refuse "config_missing_${k,,}"; done
# Only the drill runner, through sudo; the runner, QEMU and the signer are three different unprivileged accounts.
[[ "${SUDO_USER:-}" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] && [ "$SUDO_USER" = "${C[DMS_SUPERVISOR_CALLER]}" ] \
  && [ "${SUDO_UID:-x}" = "$(id -u -- "$SUDO_USER" 2> /dev/null)" ] || refuse caller_not_allowed
QUID="$(id -u -- "${C[DMS_SUPERVISOR_QEMU_USER]}" 2> /dev/null)" && SUID_="$(id -u -- "${C[DMS_SUPERVISOR_SIGNER_USER]}" 2> /dev/null)" || refuse accounts_missing
[ "$QUID" != 0 ] && [ "$SUID_" != 0 ] && [ "$QUID" != "$SUDO_UID" ] && [ "$QUID" != "$SUID_" ] && [ "$SUID_" != "$SUDO_UID" ] || refuse identities_not_separate

UNIT=""
stop_unit() { [ -n "$UNIT" ] && systemctl stop "${UNIT}.service" > /dev/null 2>&1; [ -n "$UNIT" ] && systemctl reset-failed "${UNIT}.service" > /dev/null 2>&1; UNIT=""; return 0; }
trap stop_unit EXIT
trap 'stop_unit; exit 143' TERM INT HUP PIPE USR1 USR2 ALRM

MODE="${1:-}"; NAME="${2:-}"
case "$MODE" in
  inspect)
    [[ "$NAME" =~ ^dmsinspect-[0-9a-f]{12}$ ]] || refuse bad_name
    T="$(mktemp -d)" || refuse tmp
    trap 'stop_unit; rm -rf -- "$T"' EXIT
    awk_bin="$(dms_archive_awk_binary)" || refuse no_awk
    dms_archive_count_program > "$T/count.awk"
    chmod 0755 "$T"; chmod 0644 "$T/count.awk"
    mapfile -t PROPS < <(dms_archive_inspect_properties system "${C[DMS_SUPERVISOR_PG_BINDIR]}" "$awk_bin" "$T/count.awk")
    UNIT="$NAME"
    systemd-run --system --wait --pipe --collect --quiet --unit="$NAME" "${PROPS[@]}" -- /usr/bin/bash -c "$(dms_archive_inspect_script)"
    rc=$?
    UNIT=""
    exit "$rc"
    ;;
  vm)
    [[ "$NAME" =~ ^dmsdrill-[0-9a-f]{12}$ ]] || refuse bad_name
    # R6C-3: the newest record of the evidence store must be this run's signed STARTED record.
    shopt -s nullglob
    recs=("${C[DMS_SUPERVISOR_SIGNER_STORE]}"/[0-9][0-9][0-9][0-9][0-9][0-9])
    shopt -u nullglob
    [ "${#recs[@]}" -ge 1 ] || refuse no_started_record
    last="${recs[${#recs[@]} - 1]}/record"
    grep -qx "DMS_DRILL_EVIDENCE_RUN_ID=${NAME}" -- "$last" && grep -qx 'DMS_DRILL_EVIDENCE_DECISION=STARTED' -- "$last" || refuse run_not_started
    W="${C[DMS_SUPERVISOR_QEMU_WORK_DIR]}"
    dms_trusted_dir "QEMU work directory" "$W" "$QUID" "$(cd "$HERE/.." && pwd)" > /dev/null || refuse work_dir_untrusted
    # R6A-1: a run's VM files never outlive it; anything left by an earlier, interrupted run is removed first.
    find "$W" -mindepth 1 -maxdepth 1 -name 'dmsdrill-*' -exec rm -rf -- {} + 2> /dev/null
    # R6A-7: room for the overlay (the 10 GiB virtual disk) and a margin.
    free_kib="$(df -Pk -- "$W" | awk 'NR == 2 { print $4 }')"
    [[ "$free_kib" =~ ^[0-9]+$ ]] && [ "$free_kib" -ge $((11 * 1024 * 1024)) ] || refuse qemu_work_space
    mapfile -t PROPS < <(dms_vm_qemu_unit_properties "${C[DMS_SUPERVISOR_QEMU_USER]}" "$W" 30)
    UNIT=dmsqemu
    systemd-run --system --wait --pipe --collect --quiet --unit=dmsqemu "${PROPS[@]}" -- /bin/bash "${C[DMS_SUPERVISOR_QEMU_LAUNCHER]}" run "$NAME"
    rc=$?
    UNIT=""
    exit "$rc"
    ;;
  *) refuse usage ;;
esac
