#!/usr/bin/env bash
#
# R6-B6 isolated restore drill — the evidence SIGNER (review finding VR-4; owner directive D10).
#
# Runs as a dedicated signer identity that is neither the drill operator nor the identity QEMU runs as. It alone can
# read the signing key and write the evidence store and its head log. The operator reaches it only through
#   sudo -n -u <signer> -- <this script> sign < request
# (a sudoers rule that allows exactly this command), so a compromised operator or QEMU process can ask for a record
# but can never read the key, rewrite or drop published records, or reorder the chain.
#
# Usage:
#   restore-drill-evidence-signer.sh sign < request     request = contract-2 request keys (restore-evidence.sh)
#   restore-drill-evidence-signer.sh verify             verify the whole store against the head log
#   restore-drill-evidence-signer.sh recover-head       after a crash between publication and the head-log append
# Configuration: DMS_SIGNER_CONF (default /etc/dms-restore-drill/signer.conf), owned by the signer or root, not
# writable by others, outside any checkout. Keys: DMS_SIGNER_KEY, DMS_SIGNER_PRINCIPAL, DMS_SIGNER_ANCHOR,
# DMS_SIGNER_STORE, DMS_SIGNER_HEAD_LOG, DMS_SIGNER_ALLOWED_CALLERS (space-separated user names).
# Exit: 0 done, 1 refused or failed, 2 misconfigured.
set -uo pipefail
umask 077
if [ "${DMS_SIGNER_CLEAN_ENV:-}" != 1 ]; then
  exec env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C.UTF-8 DMS_SIGNER_CLEAN_ENV=1 \
    DMS_SIGNER_CONF="${DMS_SIGNER_CONF:-/etc/dms-restore-drill/signer.conf}" \
    SUDO_USER="${SUDO_USER:-}" SUDO_UID="${SUDO_UID:-}" bash "$0" "$@"
fi
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for lib in trusted-path.sh restore-evidence.sh; do
  node="${HERE}/lib/${lib}"
  [ ! -L "$node" ] && [ -f "$node" ] && [ $(( 8#$(stat -c '%a' -- "$node") & 8#022 )) -eq 0 ] || { echo "SIGNER: ${node} is unsafe"; exit 2; }
  # shellcheck source=/dev/null
  . "$node"
done
MODE="${1:-}"
case "$MODE" in sign | verify | recover-head) : ;; *) echo "usage: restore-drill-evidence-signer.sh sign|verify|recover-head"; exit 2 ;; esac

CONF="$DMS_SIGNER_CONF"
why="$(dms_trusted_file "signer configuration" "$CONF" "$(id -u)" "$(cd "$HERE/.." && pwd)")" || { echo "SIGNER: ${why}"; exit 2; }
declare -A C=()
KEYS="DMS_SIGNER_KEY DMS_SIGNER_PRINCIPAL DMS_SIGNER_ANCHOR DMS_SIGNER_STORE DMS_SIGNER_HEAD_LOG DMS_SIGNER_ALLOWED_CALLERS"
while IFS= read -r line || [ -n "$line" ]; do
  case "$line" in '' | '#'*) continue ;; esac
  [[ "$line" =~ ^(DMS_SIGNER_[A-Z_]+)=([A-Za-z0-9_./@:\ -]{1,255})$ ]] || { echo "SIGNER: unexpected line in ${CONF}"; exit 2; }
  [[ " ${KEYS} " == *" ${BASH_REMATCH[1]} "* ]] && [ -z "${C[${BASH_REMATCH[1]}]:-}" ] || { echo "SIGNER: unknown or repeated key in ${CONF}"; exit 2; }
  C[${BASH_REMATCH[1]}]="${BASH_REMATCH[2]}"
done < "$CONF"
for k in $KEYS; do [ -n "${C[$k]:-}" ] || { echo "SIGNER: ${k} is missing"; exit 2; }; done
[[ "${C[DMS_SIGNER_PRINCIPAL]}" =~ ^[A-Za-z0-9._@-]{1,64}$ ]] || { echo "SIGNER: bad principal"; exit 2; }
for k in DMS_SIGNER_KEY DMS_SIGNER_ANCHOR; do
  why="$(dms_trusted_file "$k" "${C[$k]}" "$(id -u)" "$(cd "$HERE/.." && pwd)")" || { echo "SIGNER: ${why}"; exit 2; }
done
why="$(dms_trusted_dir DMS_SIGNER_STORE "${C[DMS_SIGNER_STORE]}" "$(id -u)" "$(cd "$HERE/.." && pwd)")" || { echo "SIGNER: ${why}"; exit 2; }
HEADLOG="${C[DMS_SIGNER_HEAD_LOG]}"
[ ! -L "$HEADLOG" ] && { [ ! -e "$HEADLOG" ] || [ "$(stat -c '%u' -- "$HEADLOG")" = "$(id -u)" ]; } || { echo "SIGNER: the head log is not the signer's"; exit 2; }
[ -e "$HEADLOG" ] || : > "$HEADLOG"

exec {lock_fd}>> "${C[DMS_SIGNER_STORE]}/.lock"
flock -w 30 "$lock_fd" || { echo "SIGNER: the store is locked"; exit 1; }

case "$MODE" in
  verify)
    dms_evidence_verify_store "${C[DMS_SIGNER_STORE]}" "${C[DMS_SIGNER_ANCHOR]}" "$HEADLOG"
    exit $?
    ;;
  recover-head)
    # Only the one crash window: the store verifies on its own, and the head log is exactly one record behind.
    v="$(dms_evidence_verify_store "${C[DMS_SIGNER_STORE]}" "${C[DMS_SIGNER_ANCHOR]}")" || { echo "SIGNER: ${v}"; exit 1; }
    n="${v#VERIFIED }"; n="${n%% *}"
    [ "$n" -ge 1 ] || { echo "SIGNER: nothing to recover"; exit 1; }
    last="$(grep -v '^$' -- "$HEADLOG" | tail -n 1)"
    prevseq="$(printf '%06d' $((10#$n - 1)))"
    if [ "$prevseq" = 000000 ]; then want_prev=""; else want_prev="${prevseq} $(sha256sum -- "${C[DMS_SIGNER_STORE]}/${prevseq}/record" | cut -d' ' -f1)"; fi
    [ "$last" = "$want_prev" ] || { echo "SIGNER: the head log is not exactly one record behind; refusing"; exit 1; }
    printf '%s %s\n' "$(printf '%06d' "$n")" "${v##*head=}" >> "$HEADLOG"
    echo "SIGNER: head log recovered to $(printf '%06d' "$n")"
    exit 0
    ;;
esac

# --- sign: only a sudo caller on the allowlist, never the signer itself -------------------------------------------
[[ "${SUDO_UID:-}" =~ ^[0-9]+$ ]] && [ "$SUDO_UID" != "$(id -u)" ] || { echo "SIGNER: refused: requests come only through sudo from a different identity (VR-4)"; exit 1; }
[[ "${SUDO_USER:-}" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] && [[ " ${C[DMS_SIGNER_ALLOWED_CALLERS]} " == *" ${SUDO_USER} "* ]] || { echo "SIGNER: refused: caller is not allowed"; exit 1; }
REQ="$(mktemp)"
trap 'rm -f -- "$REQ"' EXIT
head -c $((DMS_DRILL_EVIDENCE_MAX_BYTES + 1)) > "$REQ"
why="$(dms_evidence_request_parse "$REQ")" || { echo "SIGNER: refused: ${why}"; exit 1; }
dms_evidence_request_parse "$REQ" > /dev/null
dms_evidence_publish "${C[DMS_SIGNER_STORE]}" "${C[DMS_SIGNER_KEY]}" "${C[DMS_SIGNER_PRINCIPAL]}" "$SUDO_USER" "$HEADLOG" "${C[DMS_SIGNER_ANCHOR]}"
