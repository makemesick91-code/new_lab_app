#!/bin/bash
#
# R6-B6 isolated restore drill — the evidence WITNESS (owner directive "R6-B6 — Phase 3", mandatory hardening 1:
# independently retained chain-head digests; no rollback, truncation, replay or unauthorized publication).
#
# Runs as a dedicated witness identity: neither the operator, nor QEMU, nor the signer. It keeps its own append-only
# log of every published evidence record, one line per record: "<NNNNNN> <sha256 of the record>". The signer can only
# APPEND the next sequence number (sudoers: the signer may run `append`); nobody but this identity (and root) can write
# the log, so a signer that is later compromised can neither drop, reorder nor rewrite a witnessed record: the store
# must match EVERY witnessed digest, not only the newest. The operator (and the signer) may read the log (`show`).
#
# Usage:
#   restore-drill-evidence-witness.sh append <NNNNNN> <sha256>   (from the signer, through sudo)
#   restore-drill-evidence-witness.sh show                        (from the signer or the operator, through sudo)
# Configuration: /etc/dms-restore-drill/witness.conf, owned by root, writable by nobody else. Keys:
#   DMS_WITNESS_LOG                the log (in a directory owned by this identity, mode 0700)
#   DMS_WITNESS_APPEND_CALLERS     user names that may append (the signer)
#   DMS_WITNESS_SHOW_CALLERS       user names that may read (the signer and the operator)
# Exit: 0 done, 1 refused, 2 misconfigured.
set -uo pipefail
umask 077
if [ "${DMS_WITNESS_CLEAN_ENV:-}" != 1 ]; then
  exec /usr/bin/env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C.UTF-8 DMS_WITNESS_CLEAN_ENV=1 \
    DMS_WITNESS_CONF="${DMS_WITNESS_CONF:-/etc/dms-restore-drill/witness.conf}" \
    SUDO_USER="${SUDO_USER:-}" SUDO_UID="${SUDO_UID:-}" /bin/bash "$0" "$@"
fi
ulimit -c 0
ulimit -S -f unlimited 2> /dev/null
[ "$(ulimit -H -f)" = unlimited ] && [ "$(ulimit -S -f)" = unlimited ] || { echo "WITNESS: refused: a file-size limit is set"; exit 1; }
[ "$(id -u)" != 0 ] || { echo "WITNESS: never as root"; exit 2; }
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
_owned_ok() { local st; st="$(stat -c '%u %a' -- "$1")" || return 1; { [ "${st% *}" = 0 ] || [ "${st% *}" = "$(id -u)" ]; } && [ $(( 8#${st#* } & 8#022 )) -eq 0 ]; }
_sticky_root() { local st; st="$(stat -c '%u %a' -- "$1")" && [ "${st% *}" = 0 ] && [ $(( 8#${st#* } & 8#1000 )) -ne 0 ]; }
_owned_ok "${HERE}/$(basename "${BASH_SOURCE[0]}")" && _owned_ok "$HERE" || { echo "WITNESS: its code is not owned by root or the witness, or is writable by another identity"; exit 2; }
d="$HERE"
while :; do
  _owned_ok "$d" || _sticky_root "$d" || { echo "WITNESS: ${d} is unsafe"; exit 2; }
  [ "$d" = / ] && break
  d="$(dirname -- "$d")"
done
node="${HERE}/lib/trusted-path.sh"
[ ! -L "$node" ] && [ -f "$node" ] && _owned_ok "$node" && _owned_ok "${HERE}/lib" || { echo "WITNESS: ${node} is unsafe"; exit 2; }
# shellcheck source=/dev/null
. "$node"

MODE="${1:-}"
case "$MODE" in append | show) : ;; *) echo "usage: restore-drill-evidence-witness.sh append <NNNNNN> <sha256> | show"; exit 2 ;; esac
[[ "${SUDO_UID:-}" =~ ^[0-9]+$ ]] && [ "$SUDO_UID" != "$(id -u)" ] && [[ "${SUDO_USER:-}" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] \
  || { echo "WITNESS: refused: requests come only through sudo from a different identity"; exit 1; }

CONF="$DMS_WITNESS_CONF"
dms_trusted_file "witness configuration" "$CONF" "$(id -u)" "$(cd "$HERE/.." && pwd)" > /dev/null || { echo "WITNESS: the configuration is not owned by root or the witness, or is writable by others"; exit 2; }
declare -A C=()
KEYS="DMS_WITNESS_LOG DMS_WITNESS_APPEND_CALLERS DMS_WITNESS_SHOW_CALLERS"
while IFS= read -r line || [ -n "$line" ]; do
  case "$line" in '' | '#'*) continue ;; esac
  [[ "$line" =~ ^(DMS_WITNESS_[A-Z0-9_]+)=([A-Za-z0-9_./@:\ -]{1,255})$ ]] || { echo "WITNESS: unexpected configuration line"; exit 2; }
  [[ " ${KEYS} " == *" ${BASH_REMATCH[1]} "* ]] && [ -z "${C[${BASH_REMATCH[1]}]:-}" ] || { echo "WITNESS: unknown or repeated key"; exit 2; }
  C[${BASH_REMATCH[1]}]="${BASH_REMATCH[2]}"
done < "$CONF"
for k in $KEYS; do [ -n "${C[$k]:-}" ] || { echo "WITNESS: ${k} is missing"; exit 2; }; done
LOG="${C[DMS_WITNESS_LOG]}"
dms_trusted_canonical "$LOG" || { echo "WITNESS: the log path is not canonical and absolute"; exit 2; }
[ "$(stat -c '%u %a' -- "$(dirname -- "$LOG")" 2> /dev/null)" = "$(id -u) 700" ] || { echo "WITNESS: the log directory must be the witness's own, mode 0700"; exit 2; }
[ ! -L "$LOG" ] && { [ ! -e "$LOG" ] || [ "$(stat -c '%u' -- "$LOG")" = "$(id -u)" ]; } || { echo "WITNESS: the log is not the witness's"; exit 2; }
[ -e "$LOG" ] || : > "$LOG"

# The log itself must be well formed and gapless before anything is read from it or added to it.
log_ok() {
  local n=0 seq sha
  while IFS=' ' read -r seq sha; do
    n=$((n + 1))
    [ "$seq" = "$(printf '%06d' "$n")" ] && [[ "$sha" =~ ^[0-9a-f]{64}$ ]] || return 1
  done < "$LOG"
  [ "$(wc -l < "$LOG")" = "$n" ]
}

exec {lock_fd}>> "$(dirname -- "$LOG")/.lock"
flock -w 30 "$lock_fd" || { echo "WITNESS: the log is locked"; exit 1; }
log_ok || { echo "WITNESS: FAIL the witness log is malformed or has a gap"; exit 1; }

case "$MODE" in
  show)
    [[ " ${C[DMS_WITNESS_SHOW_CALLERS]} " == *" ${SUDO_USER} "* ]] || { echo "WITNESS: refused: caller may not read"; exit 1; }
    cat -- "$LOG"
    ;;
  append)
    [[ " ${C[DMS_WITNESS_APPEND_CALLERS]} " == *" ${SUDO_USER} "* ]] || { echo "WITNESS: refused: caller may not append"; exit 1; }
    SEQ="${2:-}"; SHA="${3:-}"
    [[ "$SEQ" =~ ^[0-9]{6}$ ]] && [[ "$SHA" =~ ^[0-9a-f]{64}$ ]] || { echo "WITNESS: refused: bad arguments"; exit 1; }
    want="$(printf '%06d' $(( $(wc -l < "$LOG") + 1 )))"
    # No replay, no rollback, no gap: only the next sequence number is accepted, exactly once.
    [ "$SEQ" = "$want" ] || { echo "WITNESS: refused: ${SEQ} is not the next sequence number (${want})"; exit 1; }
    trap '' INT TERM HUP QUIT
    tmp="$(mktemp "${LOG}.XXXXXX")" || { echo "WITNESS: cannot write"; exit 1; }
    if ! { cat -- "$LOG" > "$tmp" && printf '%s %s\n' "$SEQ" "$SHA" >> "$tmp" && sync -- "$tmp" \
      && mv -f -- "$tmp" "$LOG" && sync -- "$(dirname -- "$LOG")"; }; then
      rm -f -- "$tmp"; echo "WITNESS: the log could not be replaced"; exit 1
    fi
    echo "WITNESSED ${SEQ} ${SHA}"
    ;;
esac
