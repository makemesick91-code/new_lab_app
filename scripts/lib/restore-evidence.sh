# shellcheck shell=bash
#
# R6-B6 isolated restore drill — authenticated, tamper-evident drill evidence (owner directive D10; review findings
# VR-1, VR-4, PR-9). Contract 2.
#
# Identities (VR-4): the drill operator (who runs the orchestrator and QEMU) never holds the signing key and cannot
# write the store. It sends an unsigned REQUEST to the SIGNER, a separate identity, through one narrow command
# (scripts/restore-drill-evidence-signer.sh, via sudo). The signer validates the request strictly, refuses a replayed
# run id, verifies its whole store and its head log first, fills the chain fields itself, signs, and publishes.
#
# Store layout (PR-9, atomic publication): <store>/<NNNNNN>/record and <store>/<NNNNNN>/record.sig. A record is built
# in <store>/.stage-<NNNNNN>-<random>/ and published by ONE rename of that directory, so a crash never leaves a record
# without its signature. The signer appends "<NNNNNN> <sha256 of record>" to a HEAD LOG kept at a separate path it
# owns; verification requires the store's head to equal the head log's last entry, so dropping the newest records is
# detected (the operator can also keep the printed head elsewhere).
#
# Trust semantics (VR-1): a record states which facts the HOST verified itself (input integrity and structure,
# expected per-table counts, limits, image) and which facts the disposable GUEST asserted (the restore result). A GO
# means: host-verified inputs AND a guest-asserted restore that matched the host's expectation. It is never stronger
# than the guest's integrity.

if [ -n "${DMS_RESTORE_EVIDENCE_LOADED:-}" ]; then
  return 0
fi
DMS_RESTORE_EVIDENCE_LOADED=1

DMS_DRILL_EVIDENCE_NAMESPACE="dms-restore-drill-evidence"
DMS_DRILL_EVIDENCE_MAX_BYTES=8192
# Keys the SIGNER fills, then keys the operator's request carries, in record order.
DMS_DRILL_EVIDENCE_SIGNER_KEYS="DMS_DRILL_EVIDENCE_CONTRACT DMS_DRILL_EVIDENCE_SEQ DMS_DRILL_EVIDENCE_PREV_SHA256 DMS_DRILL_EVIDENCE_SIGNER DMS_DRILL_EVIDENCE_CALLER DMS_DRILL_EVIDENCE_SIGNED_AT"
DMS_DRILL_EVIDENCE_REQUEST_KEYS="DMS_DRILL_EVIDENCE_RUN_ID DMS_DRILL_EVIDENCE_DRILL_HOST DMS_DRILL_EVIDENCE_STARTED_AT DMS_DRILL_EVIDENCE_COMPLETED_AT DMS_DRILL_EVIDENCE_BACKUP_SHA256 DMS_DRILL_EVIDENCE_BACKUP_BYTES DMS_DRILL_EVIDENCE_DIGEST_SOURCE DMS_DRILL_EVIDENCE_MANIFEST_ID DMS_DRILL_EVIDENCE_MANIFEST_SHA256 DMS_DRILL_EVIDENCE_IMAGE_SHA256 DMS_DRILL_EVIDENCE_LIMITS DMS_DRILL_EVIDENCE_HOST_VERIFIED DMS_DRILL_EVIDENCE_EXPECTED DMS_DRILL_EVIDENCE_GUEST_ASSERTED DMS_DRILL_EVIDENCE_DECISION"
DMS_DRILL_EVIDENCE_KEYS="${DMS_DRILL_EVIDENCE_SIGNER_KEYS} ${DMS_DRILL_EVIDENCE_REQUEST_KEYS}"

# _dms_evidence_value_ok <key> <value>
_dms_evidence_value_ok() {
  local k="$1" v="$2"
  case "$k" in
    DMS_DRILL_EVIDENCE_CONTRACT) [ "$v" = 2 ] ;;
    DMS_DRILL_EVIDENCE_SEQ) [[ "$v" =~ ^[0-9]{6}$ ]] ;;
    DMS_DRILL_EVIDENCE_PREV_SHA256) [ "$v" = genesis ] || [[ "$v" =~ ^[0-9a-f]{64}$ ]] ;;
    DMS_DRILL_EVIDENCE_RUN_ID) [[ "$v" =~ ^dmsdrill-[0-9a-f]{12}$ ]] ;;
    DMS_DRILL_EVIDENCE_BACKUP_SHA256 | DMS_DRILL_EVIDENCE_MANIFEST_SHA256 | DMS_DRILL_EVIDENCE_IMAGE_SHA256) [[ "$v" =~ ^[0-9a-f]{64}$ ]] ;;
    DMS_DRILL_EVIDENCE_STARTED_AT | DMS_DRILL_EVIDENCE_COMPLETED_AT | DMS_DRILL_EVIDENCE_SIGNED_AT) [[ "$v" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] ;;
    DMS_DRILL_EVIDENCE_BACKUP_BYTES) [[ "$v" =~ ^[0-9]{1,15}$ ]] ;;
    DMS_DRILL_EVIDENCE_DECISION) [ "$v" = GO ] || [[ "$v" =~ ^FAIL:[a-z0-9_]{1,60}$ ]] ;;
    DMS_DRILL_EVIDENCE_SIGNER | DMS_DRILL_EVIDENCE_CALLER) [[ "$v" =~ ^[A-Za-z0-9._@-]{1,64}$ ]] ;;
    *) [[ "$v" =~ ^[A-Za-z0-9_.:,=@/-]{1,300}$ ]] ;;
  esac
}

# dms_evidence_request_parse <request file>
# Signer side. The request carries exactly DMS_DRILL_EVIDENCE_REQUEST_KEYS, each once, in that order, valid values,
# at most 8 KiB. Fills DMS_EVIDENCE_REQUEST. Prints the reason and returns 1 otherwise.
declare -gA DMS_EVIDENCE_REQUEST=()
dms_evidence_request_parse() {
  local req="${1:?request}" line k v order=""
  DMS_EVIDENCE_REQUEST=()
  [ "$(stat -c %s -- "$req" 2> /dev/null || echo 99999)" -le "$DMS_DRILL_EVIDENCE_MAX_BYTES" ] || { echo "evidence: request too large"; return 1; }
  while IFS= read -r line || [ -n "$line" ]; do
    [[ "$line" =~ ^(DMS_DRILL_EVIDENCE_[A-Z0-9_]+)=(.*)$ ]] || { echo "evidence: request has an unexpected line"; return 1; }
    k="${BASH_REMATCH[1]}"; v="${BASH_REMATCH[2]}"
    [ -z "${DMS_EVIDENCE_REQUEST[$k]+x}" ] || { echo "evidence: request repeats ${k}"; DMS_EVIDENCE_REQUEST=(); return 1; }
    _dms_evidence_value_ok "$k" "$v" || { echo "evidence: request has an invalid ${k}"; DMS_EVIDENCE_REQUEST=(); return 1; }
    DMS_EVIDENCE_REQUEST[$k]="$v"
    order="${order}${order:+ }${k}"
  done < "$req"
  [ "$order" = "$DMS_DRILL_EVIDENCE_REQUEST_KEYS" ] || { echo "evidence: request keys differ from contract 2"; DMS_EVIDENCE_REQUEST=(); return 1; }
}

# dms_evidence_verify_store <store> <allowed_signers anchor> [head log]
# Verifies every published record in sequence: signature (principal = its SIGNER, namespace above), exact key set and
# order, valid values, SEQ equal to its directory, PREV equal to the previous record's sha256 (genesis first). With a
# head log, the store's head must equal the log's last entry. Prints "VERIFIED <n> head=<sha256|genesis>" or the first
# failure. Staging directories (.stage-*) are not records and are ignored.
dms_evidence_verify_store() {
  local store="${1:?store}" anchor="${2:?anchor}" headlog="${3:-}" n=0 prev=genesis d f line k v signer keys last
  shopt -s nullglob
  local dirs=("$store"/[0-9][0-9][0-9][0-9][0-9][0-9])
  shopt -u nullglob
  for d in "${dirs[@]}"; do
    n=$((n + 1))
    [ "$(basename "$d")" = "$(printf '%06d' "$n")" ] || { echo "FAIL sequence gap at $(basename "$d")"; return 1; }
    f="${d}/record"
    if [ -L "$d" ] || [ ! -d "$d" ] || [ -L "$f" ] || [ ! -f "$f" ] || [ ! -f "${f}.sig" ]; then echo "FAIL $(basename "$d"): missing record or signature"; return 1; fi
    [ "$(stat -c %s -- "$f")" -le "$DMS_DRILL_EVIDENCE_MAX_BYTES" ] || { echo "FAIL $(basename "$d"): too large"; return 1; }
    signer="$(sed -n 's/^DMS_DRILL_EVIDENCE_SIGNER=//p' -- "$f" | head -n 1)"
    [[ "$signer" =~ ^[A-Za-z0-9._@-]{1,64}$ ]] || { echo "FAIL $(basename "$d"): bad signer"; return 1; }
    if ! ssh-keygen -Y verify -f "$anchor" -I "$signer" -n "$DMS_DRILL_EVIDENCE_NAMESPACE" -s "${f}.sig" < "$f" > /dev/null 2>&1; then
      echo "FAIL $(basename "$d"): signature does not verify"
      return 1
    fi
    local -A seen=()
    keys=""
    while IFS= read -r line || [ -n "$line" ]; do
      [[ "$line" =~ ^(DMS_DRILL_EVIDENCE_[A-Z0-9_]+)=(.*)$ ]] || { echo "FAIL $(basename "$d"): unexpected line"; return 1; }
      k="${BASH_REMATCH[1]}"; v="${BASH_REMATCH[2]}"
      [ -z "${seen[$k]+x}" ] || { echo "FAIL $(basename "$d"): ${k} twice"; return 1; }
      seen[$k]="$v"
      keys="${keys}${keys:+ }${k}"
      _dms_evidence_value_ok "$k" "$v" || { echo "FAIL $(basename "$d"): invalid ${k}"; return 1; }
    done < "$f"
    [ "$keys" = "$DMS_DRILL_EVIDENCE_KEYS" ] || { echo "FAIL $(basename "$d"): key set or order differs"; return 1; }
    [ "${seen[DMS_DRILL_EVIDENCE_SEQ]}" = "$(printf '%06d' "$n")" ] || { echo "FAIL $(basename "$d"): SEQ does not match its directory"; return 1; }
    [ "${seen[DMS_DRILL_EVIDENCE_PREV_SHA256]}" = "$prev" ] || { echo "FAIL $(basename "$d"): chain broken (PREV does not match the previous record)"; return 1; }
    prev="$(sha256sum -- "$f" | cut -d' ' -f1)"
    unset seen
  done
  if [ -n "$headlog" ]; then
    [ -f "$headlog" ] && [ ! -L "$headlog" ] || { echo "FAIL head log ${headlog} is missing"; return 1; }
    last="$(grep -v '^$' -- "$headlog" | tail -n 1)"
    if [ "$n" = 0 ]; then
      [ -z "$last" ] || { echo "FAIL the head log names ${last} but the store is empty (records were dropped)"; return 1; }
    else
      [ "$last" = "$(printf '%06d' "$n") ${prev}" ] || { echo "FAIL the store head $(printf '%06d' "$n") ${prev} is not the head log's last entry '${last}' (records were dropped or added)"; return 1; }
    fi
  fi
  echo "VERIFIED ${n} head=${prev}"
}

# dms_evidence_publish <store> <signing key> <principal> <caller> <head log> <anchor>
# Signer side, after dms_evidence_request_parse, under the store lock. Verifies the store and head log, refuses a
# run id already recorded (replay), builds and signs the record in a staging directory, publishes it with ONE rename,
# then appends the new head to the head log. Prints "RECORD <seq> <sha256>".
dms_evidence_publish() {
  local store="${1:?store}" key="${2:?key}" principal="${3:?principal}" caller="${4:?caller}" headlog="${5:?head log}" anchor="${6:?anchor}"
  local verdict n prev seq stage k v rec_sha d
  # A brand-new store starts with an empty head log; a missing log beside a non-empty store then fails verification.
  [ -e "$headlog" ] || : > "$headlog"
  verdict="$(dms_evidence_verify_store "$store" "$anchor" "$headlog")" || { echo "evidence: the store does not verify, nothing is appended (${verdict})"; return 1; }
  n="${verdict#VERIFIED }"; n="${n%% *}"; prev="${verdict##*head=}"
  shopt -s nullglob
  for d in "$store"/[0-9][0-9][0-9][0-9][0-9][0-9]; do
    if grep -qx "DMS_DRILL_EVIDENCE_RUN_ID=${DMS_EVIDENCE_REQUEST[DMS_DRILL_EVIDENCE_RUN_ID]}" -- "${d}/record"; then
      shopt -u nullglob
      echo "evidence: run ${DMS_EVIDENCE_REQUEST[DMS_DRILL_EVIDENCE_RUN_ID]} is already recorded (replay refused)"
      return 1
    fi
  done
  shopt -u nullglob
  seq="$(printf '%06d' $((10#$n + 1)))"
  stage="$(mktemp -d "${store}/.stage-${seq}-XXXXXX")" || return 1
  {
    printf 'DMS_DRILL_EVIDENCE_CONTRACT=2\nDMS_DRILL_EVIDENCE_SEQ=%s\nDMS_DRILL_EVIDENCE_PREV_SHA256=%s\n' "$seq" "$prev"
    printf 'DMS_DRILL_EVIDENCE_SIGNER=%s\nDMS_DRILL_EVIDENCE_CALLER=%s\nDMS_DRILL_EVIDENCE_SIGNED_AT=%s\n' "$principal" "$caller" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    for k in $DMS_DRILL_EVIDENCE_REQUEST_KEYS; do printf '%s=%s\n' "$k" "${DMS_EVIDENCE_REQUEST[$k]}"; done
  } > "${stage}/record"
  while IFS= read -r v; do
    _dms_evidence_value_ok "${v%%=*}" "${v#*=}" || { rm -rf -- "$stage"; echo "evidence: invalid value for ${v%%=*}"; return 1; }
  done < "${stage}/record"
  if ! ssh-keygen -Y sign -f "$key" -n "$DMS_DRILL_EVIDENCE_NAMESPACE" "${stage}/record" > /dev/null 2>&1; then
    rm -rf -- "$stage"
    echo "evidence: signing failed"
    return 1
  fi
  rec_sha="$(sha256sum -- "${stage}/record" | cut -d' ' -f1)"
  if [ -e "${store}/${seq}" ] || ! mv -T -n -- "$stage" "${store}/${seq}" || [ -e "$stage" ]; then
    rm -rf -- "$stage"
    echo "evidence: could not publish ${seq} atomically"
    return 1
  fi
  printf '%s %s\n' "$seq" "$rec_sha" >> "$headlog"
  echo "RECORD ${seq} ${rec_sha}"
}
