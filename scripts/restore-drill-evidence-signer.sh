#!/bin/bash
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
# DMS_SIGNER_STORE, DMS_SIGNER_HEAD_LOG, DMS_SIGNER_ALLOWED_CALLERS (space-separated user names),
# DMS_SIGNER_IMAGE_SHA256 (space-separated sha256 digests of the drill images this host may report).
# The sudoers rule must not allow SETENV or keep DMS_SIGNER_CONF; sudo's env_reset then always selects the default.
# Exit: 0 done, 1 refused or failed, 2 misconfigured.
set -uo pipefail
umask 077
# P4V-6: the interpreter is /bin/bash by absolute path (above and here), never found through the caller's PATH.
if [ "${DMS_SIGNER_CLEAN_ENV:-}" != 1 ]; then
  exec /usr/bin/env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C.UTF-8 DMS_SIGNER_CLEAN_ENV=1 \
    DMS_SIGNER_CONF="${DMS_SIGNER_CONF:-/etc/dms-restore-drill/signer.conf}" \
    SUDO_USER="${SUDO_USER:-}" SUDO_UID="${SUDO_UID:-}" /bin/bash "$0" "$@"
fi
# P4V-5: no core file; a caller's file-size limit (sudo passes rlimits on) could truncate a record or the head log.
ulimit -c 0
ulimit -S -f unlimited 2> /dev/null
[ "$(ulimit -H -f)" = unlimited ] && [ "$(ulimit -S -f)" = unlimited ] || { echo "SIGNER: refused: a file-size limit is set (ulimit -f); records are never written under one"; exit 1; }
# P4V-7: the signer's own code (this script, its libraries and every directory above them) must belong to root or to
# the signer itself and be writable by nobody else: an operator-owned install would let the operator run code as the
# signer and read its key.
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
SELF="${HERE}/$(basename "${BASH_SOURCE[0]}")"
_signer_owned_ok() { local st; st="$(stat -c '%u %a' -- "$1")" || return 1; { [ "${st% *}" = 0 ] || [ "${st% *}" = "$(id -u)" ]; } && [ $(( 8#${st#* } & 8#022 )) -eq 0 ]; }
[ ! -L "$SELF" ] && [ -f "$SELF" ] && _signer_owned_ok "$SELF" || { echo "SIGNER: ${SELF} is not owned by root or the signer, or is writable by another identity"; exit 2; }
_signer_sticky_root() { local st; st="$(stat -c '%u %a' -- "$1")" && [ "${st% *}" = 0 ] && [ $(( 8#${st#* } & 8#1000 )) -ne 0 ]; }
d="$HERE"
while :; do
  _signer_owned_ok "$d" || _signer_sticky_root "$d" || { echo "SIGNER: ${d} is not owned by root or the signer, or is writable by another identity"; exit 2; }
  [ "$d" = / ] && break
  d="$(dirname -- "$d")"
done
for lib in trusted-path.sh restore-evidence.sh; do
  node="${HERE}/lib/${lib}"
  [ ! -L "$node" ] && [ -f "$node" ] && _signer_owned_ok "$node" && _signer_owned_ok "${HERE}/lib" || { echo "SIGNER: ${node} is unsafe"; exit 2; }
  # shellcheck source=/dev/null
  . "$node"
done
MODE="${1:-}"
case "$MODE" in sign | verify | recover-head) : ;; *) echo "usage: restore-drill-evidence-signer.sh sign|verify|recover-head"; exit 2 ;; esac

CONF="$DMS_SIGNER_CONF"
why="$(dms_trusted_file "signer configuration" "$CONF" "$(id -u)" "$(cd "$HERE/.." && pwd)")" || { echo "SIGNER: ${why}"; exit 2; }
declare -A C=()
KEYS="DMS_SIGNER_KEY DMS_SIGNER_PRINCIPAL DMS_SIGNER_ANCHOR DMS_SIGNER_STORE DMS_SIGNER_HEAD_LOG DMS_SIGNER_ALLOWED_CALLERS DMS_SIGNER_IMAGE_SHA256"
while IFS= read -r line || [ -n "$line" ]; do
  case "$line" in '' | '#'*) continue ;; esac
  [[ "$line" =~ ^(DMS_SIGNER_[A-Z0-9_]+)=([A-Za-z0-9_./@:\ -]{1,255})$ ]] || { echo "SIGNER: unexpected line in ${CONF}"; exit 2; }
  [[ " ${KEYS} " == *" ${BASH_REMATCH[1]} "* ]] && [ -z "${C[${BASH_REMATCH[1]}]:-}" ] || { echo "SIGNER: unknown or repeated key in ${CONF}"; exit 2; }
  C[${BASH_REMATCH[1]}]="${BASH_REMATCH[2]}"
done < "$CONF"
for k in $KEYS; do [ -n "${C[$k]:-}" ] || { echo "SIGNER: ${k} is missing"; exit 2; }; done
[[ "${C[DMS_SIGNER_PRINCIPAL]}" =~ ^[A-Za-z0-9._@-]{1,64}$ ]] || { echo "SIGNER: bad principal"; exit 2; }
[[ "${C[DMS_SIGNER_IMAGE_SHA256]}" =~ ^[0-9a-f]{64}(\ [0-9a-f]{64}){0,15}$ ]] || { echo "SIGNER: DMS_SIGNER_IMAGE_SHA256 must be 1-16 sha256 digests"; exit 2; }
for k in DMS_SIGNER_KEY DMS_SIGNER_ANCHOR; do
  why="$(dms_trusted_file "$k" "${C[$k]}" "$(id -u)" "$(cd "$HERE/.." && pwd)")" || { echo "SIGNER: ${why}"; exit 2; }
done
# P4V-7: the key is the signer's own and readable by nobody else (ssh-keygen checks that only for its caller's keys).
[ "$(stat -c '%u' -- "${C[DMS_SIGNER_KEY]}")" = "$(id -u)" ] && [ $(( 8#$(stat -c '%a' -- "${C[DMS_SIGNER_KEY]}") & 8#077 )) -eq 0 ] \
  || { echo "SIGNER: the signing key must be owned by the signer with mode 0600 or 0400"; exit 2; }
why="$(dms_trusted_dir DMS_SIGNER_STORE "${C[DMS_SIGNER_STORE]}" "$(id -u)" "$(cd "$HERE/.." && pwd)")" || { echo "SIGNER: ${why}"; exit 2; }
HEADLOG="${C[DMS_SIGNER_HEAD_LOG]}"
# P4V-7: the head log is an absolute, canonical path in a directory only the signer (or root) can change.
dms_trusted_canonical "$HEADLOG" || { echo "SIGNER: the head log path is not canonical and absolute"; exit 2; }
why="$(dms_trusted_dir "head log directory" "$(dirname -- "$HEADLOG")" "$(id -u)" "$(cd "$HERE/.." && pwd)")" || { echo "SIGNER: ${why}"; exit 2; }
[ ! -L "$HEADLOG" ] && { [ ! -e "$HEADLOG" ] || [ "$(stat -c '%u' -- "$HEADLOG")" = "$(id -u)" ]; } || { echo "SIGNER: the head log is not the signer's"; exit 2; }
[ -e "$HEADLOG" ] || : > "$HEADLOG"

# --- sign: only a sudo caller on the allowlist, never the signer itself; the request is read BEFORE the store lock
# is taken, with a deadline, so a caller that never closes its input cannot hold the lock (P4V-5). ------------------
if [ "$MODE" = sign ]; then
  [[ "${SUDO_UID:-}" =~ ^[0-9]+$ ]] && [ "$SUDO_UID" != "$(id -u)" ] || { echo "SIGNER: refused: requests come only through sudo from a different identity (VR-4)"; exit 1; }
  [[ "${SUDO_USER:-}" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] && [[ " ${C[DMS_SIGNER_ALLOWED_CALLERS]} " == *" ${SUDO_USER} "* ]] || { echo "SIGNER: refused: caller is not allowed"; exit 1; }
  REQ="$(mktemp)"
  trap 'rm -f -- "$REQ"' EXIT
  timeout 30 head -c $((DMS_DRILL_EVIDENCE_MAX_BYTES + 1)) > "$REQ" || { echo "SIGNER: refused: the request was not complete within 30 seconds"; exit 1; }
  why="$(dms_evidence_request_parse "$REQ")" || { echo "SIGNER: refused: ${why}"; exit 1; }
  dms_evidence_request_parse "$REQ" > /dev/null
  why="$(dms_evidence_request_policy "$(hostname | tr -cd 'A-Za-z0-9._-' | head -c 64)" "${C[DMS_SIGNER_IMAGE_SHA256]}" "$(date -u +%s)")" || { echo "SIGNER: refused: ${why}"; exit 1; }
fi

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

# --- sign: publication runs with interrupts ignored, so a relayed signal cannot stop it half way (P4V-5) ------------
trap '' INT TERM HUP QUIT
dms_evidence_publish "${C[DMS_SIGNER_STORE]}" "${C[DMS_SIGNER_KEY]}" "${C[DMS_SIGNER_PRINCIPAL]}" "$SUDO_USER" "$HEADLOG" "${C[DMS_SIGNER_ANCHOR]}"
