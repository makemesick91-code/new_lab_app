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
# DMS_SIGNER_IMAGE_SHA256 (space-separated sha256 digests of the drill images this host may report),
# DMS_SIGNER_PROFILE (drill-host | development), DMS_SIGNER_WITNESS_USER and DMS_SIGNER_WITNESS_COMMAND (the witness
# identity and entrypoint; `none` in development), DMS_SIGNER_ORCHESTRATOR_DIR and DMS_SIGNER_QEMU_LAUNCHER (the
# installed drill code the signer verifies itself and records as INSTALL; `none` in development).
#   drill-host   the configuration is root-owned; every record is witnessed by a separate identity; the signer verifies
#                that the orchestrator, the QEMU launcher, the witness and itself are root-owned installs nobody else
#                can change, records their digest (INSTALL=root:<sha256>), and refuses a GO from any other install.
#   development  local tests only: no witness, INSTALL=unverified:development on every record (never acceptance).
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
# P5-6: the install directory itself never relies on the sticky exception; only directories above it may.
_signer_owned_ok "$HERE" || { echo "SIGNER: ${HERE} is not owned by root or the signer, or is writable by another identity"; exit 2; }
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
KEYS="DMS_SIGNER_KEY DMS_SIGNER_PRINCIPAL DMS_SIGNER_ANCHOR DMS_SIGNER_STORE DMS_SIGNER_HEAD_LOG DMS_SIGNER_ALLOWED_CALLERS DMS_SIGNER_IMAGE_SHA256 DMS_SIGNER_PROFILE DMS_SIGNER_WITNESS_USER DMS_SIGNER_WITNESS_COMMAND DMS_SIGNER_ORCHESTRATOR_DIR DMS_SIGNER_QEMU_LAUNCHER DMS_SIGNER_SUPERVISOR"
while IFS= read -r line || [ -n "$line" ]; do
  case "$line" in '' | '#'*) continue ;; esac
  [[ "$line" =~ ^(DMS_SIGNER_[A-Z0-9_]+)=([A-Za-z0-9_./@:\ -]{1,255})$ ]] || { echo "SIGNER: unexpected line in ${CONF}"; exit 2; }
  [[ " ${KEYS} " == *" ${BASH_REMATCH[1]} "* ]] && [ -z "${C[${BASH_REMATCH[1]}]:-}" ] || { echo "SIGNER: unknown or repeated key in ${CONF}"; exit 2; }
  C[${BASH_REMATCH[1]}]="${BASH_REMATCH[2]}"
done < "$CONF"
for k in $KEYS; do [ -n "${C[$k]:-}" ] || { echo "SIGNER: ${k} is missing"; exit 2; }; done
[[ "${C[DMS_SIGNER_PRINCIPAL]}" =~ ^[A-Za-z0-9._@-]{1,64}$ ]] || { echo "SIGNER: bad principal"; exit 2; }
[[ "${C[DMS_SIGNER_IMAGE_SHA256]}" =~ ^[0-9a-f]{64}(\ [0-9a-f]{64}){0,15}$ ]] || { echo "SIGNER: DMS_SIGNER_IMAGE_SHA256 must be 1-16 sha256 digests"; exit 2; }
PROFILE="${C[DMS_SIGNER_PROFILE]}"
case "$PROFILE" in
  drill-host)
    # Phase 3: only root may change what the signer trusts on a drill host.
    dms_trusted_file "signer configuration" "$CONF" 0 "$(cd "$HERE/.." && pwd)" > /dev/null || { echo "SIGNER: a drill-host configuration must be owned by root"; exit 2; }
    [[ "${C[DMS_SIGNER_WITNESS_USER]}" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || { echo "SIGNER: a drill host needs a witness identity"; exit 2; }
    WITNESS_UID="$(id -u -- "${C[DMS_SIGNER_WITNESS_USER]}" 2> /dev/null)" || { echo "SIGNER: the witness account does not exist"; exit 2; }
    [ "$WITNESS_UID" != "$(id -u)" ] && [ "$WITNESS_UID" != 0 ] || { echo "SIGNER: the witness must be a separate, unprivileged identity"; exit 2; }
    w="${C[DMS_SIGNER_WITNESS_COMMAND]}"
    [ -f "$w" ] && [ ! -L "$w" ] && dms_trusted_canonical "$w" && [ "$(stat -c '%u' -- "$w")" = 0 ] && [ $(( 8#$(stat -c '%a' -- "$w") & 8#022 )) -eq 0 ] \
      || { echo "SIGNER: the witness entrypoint is not a root-owned install"; exit 2; }
    ;;
  development)
    [ "${C[DMS_SIGNER_WITNESS_USER]}" = none ] && [ "${C[DMS_SIGNER_ORCHESTRATOR_DIR]}" = none ] && [ "${C[DMS_SIGNER_SUPERVISOR]}" = none ] || { echo "SIGNER: the development profile has no witness and no install"; exit 2; }
    ;;
  *) echo "SIGNER: DMS_SIGNER_PROFILE must be drill-host or development"; exit 2 ;;
esac
# install_state: the signer's OWN verification of the installed drill code (P5-4, Phase 3; never the caller's word).
# Every file must be a root-owned regular file nobody else can write, in root-owned directories nobody else can write
# (no sticky exception). Prints root:<sha256 over "<sha256>  <path>" lines> or unverified:<reason>.
install_state() {
  [ "$PROFILE" = drill-host ] || { echo "unverified:development"; return 0; }
  local -a files=() lines=() cfg=()
  local f d st o="${C[DMS_SIGNER_ORCHESTRATOR_DIR]}" q="${C[DMS_SIGNER_QEMU_LAUNCHER]}" w="${C[DMS_SIGNER_WITNESS_COMMAND]}" sv="${C[DMS_SIGNER_SUPERVISOR]}" code
  shopt -s nullglob
  files=("$o/scripts/restore-drill-vm.sh" "$o"/scripts/lib/*.sh "$o/deploy/production-host.conf" "$q" "$(dirname -- "$q")"/lib/*.sh \
    "$sv" "$(dirname -- "$sv")"/lib/*.sh "$w" "$(dirname -- "$w")"/lib/*.sh "$HERE/$(basename "${BASH_SOURCE[0]}")" "$HERE"/lib/*.sh)
  cfg=(/etc/dms-restore-drill/*.conf)
  shopt -u nullglob
  # R6F-E4: the binaries the drill runs (QEMU, qemu-img, pg_restore) are part of the install.
  local qb qi pb
  qb="$(sed -n 's/^DMS_QEMU_BINARY=//p' /etc/dms-restore-drill/qemu.conf 2> /dev/null | head -n 1)"
  qi="$(sed -n 's/^DMS_QEMU_IMG=//p' /etc/dms-restore-drill/qemu.conf 2> /dev/null | head -n 1)"
  pb="$(sed -n 's/^DMS_DRILL_PG_BINDIR=//p' /etc/dms-restore-drill/drill.conf 2> /dev/null | head -n 1)"
  [ -n "$qb" ] && [ -n "$qi" ] && [ -n "$pb" ] || { echo "unverified:binaries_unknown"; return 0; }
  files+=("$qb" "$qi" "$pb/pg_restore")
  # R6F-E4: the trust roots (manifest anchor, revocations) and the sudoers rules the identity separation rests on.
  local an rv
  an="$(sed -n 's/^DMS_DRILL_MANIFEST_ANCHOR=//p' /etc/dms-restore-drill/drill.conf 2> /dev/null | head -n 1)"
  rv="$(sed -n 's/^DMS_DRILL_MANIFEST_REVOCATIONS=//p' /etc/dms-restore-drill/drill.conf 2> /dev/null | head -n 1)"
  [ -n "$an" ] && [ -n "$rv" ] || { echo "unverified:trust_roots_unknown"; return 0; }
  local -a roots=()
  for f in "$an" "$rv"; do [ -e "$f" ] && roots+=("$f"); done
  # The rules themselves live in /etc/dms-restore-drill/sudoers (root 0444), which the fixed drop-in
  # /etc/sudoers.d/dms-restore-drill only @includes (/etc/sudoers.d is not readable by other accounts).
  [ -r /etc/dms-restore-drill/sudoers ] || { echo "unverified:sudoers_unreadable"; return 0; }
  cfg+=("${roots[@]}" /etc/dms-restore-drill/sudoers)
  # R6C-8: the orchestrator's own library directory must be there in full, not only "enough files".
  for f in trusted-path.sh restore-integrity.sh restore-sandbox.sh restore-manifest.sh restore-evidence.sh restore-vm.sh restore-archive.sh; do
    [ -f "$o/scripts/lib/$f" ] || { echo "unverified:install_incomplete"; return 0; }
  done
  for f in "${files[@]}" "${cfg[@]}"; do
    [ -f "$f" ] && [ ! -L "$f" ] && dms_trusted_canonical "$f" && [ -r "$f" ] || { echo "unverified:install_not_canonical"; return 0; }
    st="$(stat -c '%u %a' -- "$f")"
    [ "${st% *}" = 0 ] && [ $(( 8#${st#* } & 8#022 )) -eq 0 ] || { echo "unverified:install_not_root_owned"; return 0; }
    d="$(dirname -- "$f")"
    while :; do
      st="$(stat -c '%u %a' -- "$d")"
      [ "${st% *}" = 0 ] && [ $(( 8#${st#* } & 8#022 )) -eq 0 ] || { echo "unverified:install_directory_writable"; return 0; }
      [ "$d" = / ] && break
      d="$(dirname -- "$d")"
    done
  done
  # R6C-8: the drill configuration must point at exactly the signer and the supervisor this digest covers.
  grep -qx "DMS_DRILL_SIGNER_COMMAND=$HERE/$(basename "${BASH_SOURCE[0]}")" /etc/dms-restore-drill/drill.conf 2> /dev/null \
    && grep -qx "DMS_DRILL_SUPERVISOR_COMMAND=${sv}" /etc/dms-restore-drill/drill.conf 2> /dev/null || { echo "unverified:config_paths_differ"; return 0; }
  # R6F-E4: the supervisor starts the very launcher this digest covers and checks the very store this signer keeps.
  grep -qx "DMS_SUPERVISOR_QEMU_LAUNCHER=${q}" /etc/dms-restore-drill/supervisor.conf 2> /dev/null \
    && grep -qx "DMS_SUPERVISOR_SIGNER_STORE=${STORE}" /etc/dms-restore-drill/supervisor.conf 2> /dev/null \
    && grep -qx "DMS_SUPERVISOR_PG_BINDIR=${pb}" /etc/dms-restore-drill/supervisor.conf 2> /dev/null || { echo "unverified:config_paths_differ"; return 0; }
  # R6F-E8: the signer's state (store, head log, working files) and the witness log each live on a file system of
  # their own, so no other identity can fill them and stop a publication half way.
  local wl hd
  wl="$(sed -n 's/^DMS_WITNESS_LOG=//p' /etc/dms-restore-drill/witness.conf 2> /dev/null | head -n 1)"
  hd="$(dirname -- "$HEADLOG")"
  [ -n "$wl" ] && mountpoint -q -- "$hd" && mountpoint -q -- "$(dirname -- "$wl")" \
    && [ "$(dirname -- "$STORE")" = "$hd" ] || { echo "unverified:evidence_not_on_dedicated_filesystems"; return 0; }
  for f in "${files[@]}"; do lines+=("$(sha256sum -- "$f")"); done
  code="$(printf '%s\n' "${lines[@]}" | LC_ALL=C sort | sha256sum | cut -d' ' -f1)"
  lines=()
  for f in "${cfg[@]}"; do lines+=("$(sha256sum -- "$f")"); done
  for f in "$an" "$rv"; do [ -e "$f" ] || lines+=("absent  ${f}"); done
  echo "root:${code},config:$(printf '%s\n' "${lines[@]}" | LC_ALL=C sort | sha256sum | cut -d' ' -f1)"
}
# The witness (Phase 3): its log is read for every verification and extended after every publication.
witness_show() { sudo -n -u "${C[DMS_SIGNER_WITNESS_USER]}" -- "${C[DMS_SIGNER_WITNESS_COMMAND]}" show; }
witness_append() { sudo -n -u "${C[DMS_SIGNER_WITNESS_USER]}" -- "${C[DMS_SIGNER_WITNESS_COMMAND]}" append "$1" "$2"; }
for k in DMS_SIGNER_KEY DMS_SIGNER_ANCHOR; do
  why="$(dms_trusted_file "$k" "${C[$k]}" "$(id -u)" "$(cd "$HERE/.." && pwd)")" || { echo "SIGNER: ${why}"; exit 2; }
done
# P4V-7: the key is the signer's own and readable by nobody else (ssh-keygen checks that only for its caller's keys).
[ "$(stat -c '%u' -- "${C[DMS_SIGNER_KEY]}")" = "$(id -u)" ] && [ $(( 8#$(stat -c '%a' -- "${C[DMS_SIGNER_KEY]}") & 8#077 )) -eq 0 ] \
  || { echo "SIGNER: the signing key must be owned by the signer with mode 0600 or 0400"; exit 2; }
STORE="${C[DMS_SIGNER_STORE]}"
why="$(dms_trusted_dir DMS_SIGNER_STORE "$STORE" "$(id -u)" "$(cd "$HERE/.." && pwd)")" || { echo "SIGNER: ${why}"; exit 2; }
HEADLOG="${C[DMS_SIGNER_HEAD_LOG]}"
# P4V-7: the head log is an absolute, canonical path in a directory only the signer (or root) can change.
dms_trusted_canonical "$HEADLOG" || { echo "SIGNER: the head log path is not canonical and absolute"; exit 2; }
why="$(dms_trusted_dir "head log directory" "$(dirname -- "$HEADLOG")" "$(id -u)" "$(cd "$HERE/.." && pwd)")" || { echo "SIGNER: ${why}"; exit 2; }
[ ! -L "$HEADLOG" ] && { [ ! -e "$HEADLOG" ] || [ "$(stat -c '%u' -- "$HEADLOG")" = "$(id -u)" ]; } || { echo "SIGNER: the head log is not the signer's"; exit 2; }
[ -e "$HEADLOG" ] || : > "$HEADLOG"
# R6F-E6: on a drill host no caller limit on processes, CPU time or address space reaches the signer (sudo pins them;
# this is the signer's own check): a lowered limit could make it fail between publication and the witness.
if [ "$PROFILE" = drill-host ]; then
  for o in u t v; do
    ulimit -S "-$o" unlimited 2> /dev/null
    [ "$(ulimit -H "-$o")" = unlimited ] && [ "$(ulimit -S "-$o")" = unlimited ] || { echo "SIGNER: refused: a process, CPU or memory limit is set (ulimit -$o)"; exit 1; }
  done
fi
# R6C-2: no relayed or terminal signal stops the signer half way (sudo relays every catchable signal to it).
trap '' INT TERM HUP QUIT USR1 USR2 ALRM PIPE TSTP TTIN TTOU
# R6F-E8: working files in the signer's own directory, never in a shared /tmp another identity can fill.
TMP="$(mktemp -d "$(dirname -- "$HEADLOG")/.signer-tmp.XXXXXX")" || { echo "SIGNER: cannot create its working directory"; exit 1; }
trap 'rm -rf -- "$TMP"' EXIT

# --- sign: only a sudo caller on the allowlist, never the signer itself; the request is read BEFORE the store lock
# is taken, with a deadline, so a caller that never closes its input cannot hold the lock (P4V-5). ------------------
if [ "$MODE" = sign ]; then
  [[ "${SUDO_UID:-}" =~ ^[0-9]+$ ]] && [ "$SUDO_UID" != "$(id -u)" ] || { echo "SIGNER: refused: requests come only through sudo from a different identity (VR-4)"; exit 1; }
  [[ "${SUDO_USER:-}" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] && [[ " ${C[DMS_SIGNER_ALLOWED_CALLERS]} " == *" ${SUDO_USER} "* ]] || { echo "SIGNER: refused: caller is not allowed"; exit 1; }
  REQ="$TMP/request"
  timeout 30 head -c $((DMS_DRILL_EVIDENCE_MAX_BYTES + 1)) > "$REQ" || { echo "SIGNER: refused: the request was not complete within 30 seconds"; exit 1; }
  why="$(dms_evidence_request_parse "$REQ")" || { echo "SIGNER: refused: ${why}"; exit 1; }
  dms_evidence_request_parse "$REQ" > /dev/null
  why="$(dms_evidence_request_policy "$(hostname | tr -cd 'A-Za-z0-9._-' | head -c 64)" "${C[DMS_SIGNER_IMAGE_SHA256]}" "$(date -u +%s)")" || { echo "SIGNER: refused: ${why}"; exit 1; }
  INSTALL="$(install_state)"
  # Phase 3: a GO from a drill host stands only on a verified, root-owned install of the drill code.
  if [ "$PROFILE" = drill-host ] && [ "${DMS_EVIDENCE_REQUEST[DMS_DRILL_EVIDENCE_DECISION]}" = GO ] && [ "${INSTALL%%:*}" != root ]; then
    echo "SIGNER: refused: a GO from an install the signer could not verify (${INSTALL})"; exit 1
  fi
fi

exec {lock_fd}>> "${STORE}/.lock"
flock -w 30 "$lock_fd" || { echo "SIGNER: the store is locked"; exit 1; }
unlock() { exec {lock_fd}>&-; }
# The witness log, read under the store lock (drill-host). R6C-3: everything is printed only after the lock is gone,
# so a reader that never drains its output cannot hold the store.
read_witness() { [ "$PROFILE" = drill-host ] || return 0; witness_show > "$TMP/witness" 2> /dev/null; }
# R6F-E3: on a drill host the verifier also requires every GO to stand on a verified root install, the same as its start.
ROOTGO=0; [ "$PROFILE" = drill-host ] && ROOTGO=1
WARG() { [ "$PROFILE" = drill-host ] && printf '%s' "$TMP/witness"; }

case "$MODE" in
  verify)
    out="FAIL the witness log could not be read"
    if read_witness; then out="$(dms_evidence_verify_store "$STORE" "${C[DMS_SIGNER_ANCHOR]}" "$HEADLOG" "$(WARG)" "$ROOTGO")"; fi
    unlock
    printf '%s\n' "$out"
    [ "${out#VERIFIED }" != "$out" ]
    exit $?
    ;;
  recover-head)
    # R6C-2: every crash window between publication, the witness and the head log. The store must verify on its own;
    # the head log and (drill-host) the witness may each be EXACTLY one record behind, with every earlier entry equal
    # to the store; the missing entry is added to the witness first, then to the head log.
    v="$(dms_evidence_verify_store "$STORE" "${C[DMS_SIGNER_ANCHOR]}")" || { unlock; echo "SIGNER: ${v}"; exit 1; }
    n="${v#VERIFIED }"; n="${n%% *}"; head="${v##*head=}"
    [ "$n" -ge 1 ] || { unlock; echo "SIGNER: nothing to recover"; exit 1; }
    seqn="$(printf '%06d' "$n")"
    prefix_ok() { # <file> <lines expected>: the file's first lines equal the store's record digests
      local i=0 a b
      while IFS=' ' read -r a b; do
        i=$((i + 1)); [ "$i" -le "$2" ] || return 1
        [ "$a" = "$(printf '%06d' "$i")" ] && [ "$b" = "$(sha256sum -- "${STORE}/$(printf '%06d' "$i")/record" | cut -d' ' -f1)" ] || return 1
      done < "$1"
      [ "$i" = "$2" ]
    }
    fixed=0
    if [ "$PROFILE" = drill-host ]; then
      read_witness || { unlock; echo "SIGNER: the witness log could not be read"; exit 1; }
      if prefix_ok "$TMP/witness" $((n - 1)); then
        fixed=1
        witness_append "$seqn" "$head" > /dev/null || { unlock; echo "SIGNER: the witness refused the catch-up"; exit 1; }
      elif ! prefix_ok "$TMP/witness" "$n"; then
        unlock; echo "SIGNER: the witness log is not within one record of the store; refusing"; exit 1
      fi
    fi
    grep -v '^$' -- "$HEADLOG" > "$TMP/head" || true
    if prefix_ok "$TMP/head" $((n - 1)); then
      fixed=1
      dms_evidence_headlog_append "$HEADLOG" "$seqn" "$head" || { unlock; echo "SIGNER: the head log could not be replaced"; exit 1; }
    elif ! prefix_ok "$TMP/head" "$n"; then
      unlock; echo "SIGNER: the head log is not within one record of the store; refusing"; exit 1
    fi
    unlock
    [ "$fixed" = 1 ] || { echo "SIGNER: nothing to recover: the head log and the witness already match the store"; exit 1; }
    echo "SIGNER: head log and witness recovered to ${seqn}"
    exit 0
    ;;
esac

# --- sign ----------------------------------------------------------------------------------------------------------
# Before anything is added: the store, the head log and (drill-host) EVERY witnessed digest agree.
read_witness || { unlock; echo "SIGNER: refused: the witness log could not be read"; exit 1; }
v="$(dms_evidence_verify_store "$STORE" "${C[DMS_SIGNER_ANCHOR]}" "$HEADLOG" "$(WARG)" "$ROOTGO")" || { unlock; echo "SIGNER: refused: the store does not verify (${v})"; exit 1; }
# R6C-11: the store's identity, fixed at its first record and carried by every record.
[ -s "${STORE}/.store-id" ] || od -An -N16 -tx1 /dev/urandom | tr -d ' \n' > "${STORE}/.store-id"
STORE_ID="$(head -c 32 -- "${STORE}/.store-id")"
[[ "$STORE_ID" =~ ^[0-9a-f]{32}$ ]] || { unlock; echo "SIGNER: the store id is invalid"; exit 1; }
# publish_one: publication in THIS shell (R6C-2: no subshell left behind), witness before head log.
publish_one() {
  dms_evidence_publish "$STORE" "${C[DMS_SIGNER_KEY]}" "${C[DMS_SIGNER_PRINCIPAL]}" "$SUDO_USER" "$HEADLOG" "${C[DMS_SIGNER_ANCHOR]}" "$INSTALL" "$STORE_ID" defer > "$TMP/out" || return 1
  read -r _ PSEQ PSHA < "$TMP/out"
  if [ "$PROFILE" = drill-host ]; then
    witness_append "$PSEQ" "$PSHA" > /dev/null || { echo "evidence: record ${PSEQ} was published but NOT witnessed; run recover-head" > "$TMP/out"; return 1; }
  fi
  dms_evidence_headlog_append "$HEADLOG" "$PSEQ" "$PSHA" || { echo "evidence: record ${PSEQ} published but the head log was not updated; run recover-head" > "$TMP/out"; return 1; }
}
DEC="${DMS_EVIDENCE_REQUEST[DMS_DRILL_EVIDENCE_DECISION]}"
RUN="${DMS_EVIDENCE_REQUEST[DMS_DRILL_EVIDENCE_RUN_ID]}"
bind="$(dms_evidence_binding_check "$STORE" "$DEC" "$RUN")" || { unlock; echo "SIGNER: refused: ${bind#evidence: }"; exit 1; }
newest="$(find "$STORE" -mindepth 1 -maxdepth 1 -name '[0-9][0-9][0-9][0-9][0-9][0-9]' | LC_ALL=C sort | tail -n 1)"
if [ "$DEC" != STARTED ]; then
  # R6F-E3: an outcome carries its start's operator, host and start time; a GO also its start's verified install.
  for k in DMS_DRILL_EVIDENCE_OPERATOR DMS_DRILL_EVIDENCE_DRILL_HOST DMS_DRILL_EVIDENCE_STARTED_AT; do
    [ "$(sed -n "s/^${k}=//p" -- "${newest}/record" | head -n 1)" = "${DMS_EVIDENCE_REQUEST[$k]}" ] || { unlock; echo "SIGNER: refused: ${k#DMS_DRILL_EVIDENCE_} differs from the run's STARTED record"; exit 1; }
  done
  if [ "$DEC" = GO ] && [ "$(sed -n 's/^DMS_DRILL_EVIDENCE_INSTALL=//p' -- "${newest}/record" | head -n 1)" != "$INSTALL" ]; then
    unlock; echo "SIGNER: refused: a GO from an install that changed since the run started"; exit 1
  fi
fi
if [ "${bind%% *}" = open ] && [ "$PROFILE" = drill-host ]; then
  # R6F-E1: never close a run whose VM may still be running.
  case "$(systemctl show -P ActiveState dmsqemu.service 2> /dev/null)" in
    inactive | failed) : ;;
    *) unlock; echo "SIGNER: refused: a drill VM may still be running; its run is not closed"; exit 1 ;;
  esac
fi
if [ "${bind%% *}" = open ]; then
  # R6C-3: a run that never closed (its orchestrator was killed) is closed by the signer itself before a new start.
  OPEN_RUN="${bind#open }"
  last="$(find "$STORE" -mindepth 1 -maxdepth 1 -name '[0-9][0-9][0-9][0-9][0-9][0-9]' | LC_ALL=C sort | tail -n 1)/record"
  declare -A NEWREQ=()
  for k in "${!DMS_EVIDENCE_REQUEST[@]}"; do NEWREQ[$k]="${DMS_EVIDENCE_REQUEST[$k]}"; done
  DMS_EVIDENCE_REQUEST=()
  for k in $DMS_DRILL_EVIDENCE_REQUEST_KEYS; do DMS_EVIDENCE_REQUEST[$k]="$(sed -n "s/^${k}=//p" -- "$last" | head -n 1)"; done
  DMS_EVIDENCE_REQUEST[DMS_DRILL_EVIDENCE_COMPLETED_AT]="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  DMS_EVIDENCE_REQUEST[DMS_DRILL_EVIDENCE_DECISION]="ABORTED:unclosed"
  # R6F-E1: the supervisor's root-owned marker says whether that run's VM had booted (its outcome was then suppressed).
  [ -f "/var/lib/dms-restore-drill/supervisor-booted/${OPEN_RUN}" ] && DMS_EVIDENCE_REQUEST[DMS_DRILL_EVIDENCE_DECISION]="ABORTED:unclosed_after_boot"
  [ "${DMS_EVIDENCE_REQUEST[DMS_DRILL_EVIDENCE_RUN_ID]}" = "$OPEN_RUN" ] || { unlock; echo "SIGNER: the open run could not be read"; exit 1; }
  publish_one || { unlock; cat -- "$TMP/out"; exit 1; }
  CLOSED="closed ${OPEN_RUN} as ${PSEQ}; "
  DMS_EVIDENCE_REQUEST=()
  for k in "${!NEWREQ[@]}"; do DMS_EVIDENCE_REQUEST[$k]="${NEWREQ[$k]}"; done
fi
publish_one || { unlock; cat -- "$TMP/out"; exit 1; }
unlock
if [ "$PROFILE" = drill-host ]; then echo "RECORD ${PSEQ} ${PSHA} witnessed ${CLOSED:-}"; else echo "RECORD ${PSEQ} ${PSHA} ${CLOSED:-}"; fi
