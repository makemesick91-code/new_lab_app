# shellcheck shell=bash
#
# R6-B6 isolated restore drill — authenticated, tamper-evident drill evidence (owner directive D10, 2026-11-25).
#
# Each drill writes one record into an evidence store on the drill host: a fixed key set, signed by the drill host's
# key with `ssh-keygen -Y sign -n dms-restore-drill-evidence`, and chained: DMS_DRILL_EVIDENCE_PREV_SHA256 is the
# sha256 of the previous record (the first record names `genesis`). Changing a record breaks its signature; removing,
# inserting or reordering records breaks the chain; dropping the newest records is detected only against a head
# digest kept elsewhere (`--expect-head`, residual). The readiness service is NOT wired to this store (D10: separate).
#
# Store layout: <store>/<NNNNNN>.evidence and <store>/<NNNNNN>.evidence.sig, NNNNNN = 000001, 000002, …

if [ -n "${DMS_RESTORE_EVIDENCE_LOADED:-}" ]; then
  return 0
fi
DMS_RESTORE_EVIDENCE_LOADED=1

DMS_DRILL_EVIDENCE_NAMESPACE="dms-restore-drill-evidence"
DMS_DRILL_EVIDENCE_MAX_BYTES=8192
DMS_DRILL_EVIDENCE_KEYS="DMS_DRILL_EVIDENCE_CONTRACT DMS_DRILL_EVIDENCE_SEQ DMS_DRILL_EVIDENCE_PREV_SHA256 DMS_DRILL_EVIDENCE_RUN_ID DMS_DRILL_EVIDENCE_DRILL_HOST DMS_DRILL_EVIDENCE_SIGNER DMS_DRILL_EVIDENCE_STARTED_AT DMS_DRILL_EVIDENCE_COMPLETED_AT DMS_DRILL_EVIDENCE_BACKUP_SHA256 DMS_DRILL_EVIDENCE_BACKUP_BYTES DMS_DRILL_EVIDENCE_DIGEST_SOURCE DMS_DRILL_EVIDENCE_MANIFEST_ID DMS_DRILL_EVIDENCE_MANIFEST_SHA256 DMS_DRILL_EVIDENCE_IMAGE_SHA256 DMS_DRILL_EVIDENCE_LIMITS DMS_DRILL_EVIDENCE_EXPECTED DMS_DRILL_EVIDENCE_OBSERVED DMS_DRILL_EVIDENCE_FIDELITY DMS_DRILL_EVIDENCE_DECISION"

# _dms_evidence_value_ok <key> <value>
_dms_evidence_value_ok() {
  local k="$1" v="$2"
  case "$k" in
    DMS_DRILL_EVIDENCE_CONTRACT) [ "$v" = 1 ] ;;
    DMS_DRILL_EVIDENCE_SEQ) [[ "$v" =~ ^[0-9]{6}$ ]] ;;
    DMS_DRILL_EVIDENCE_PREV_SHA256) [ "$v" = genesis ] || [[ "$v" =~ ^[0-9a-f]{64}$ ]] ;;
    DMS_DRILL_EVIDENCE_BACKUP_SHA256 | DMS_DRILL_EVIDENCE_MANIFEST_SHA256 | DMS_DRILL_EVIDENCE_IMAGE_SHA256) [[ "$v" =~ ^[0-9a-f]{64}$ ]] ;;
    DMS_DRILL_EVIDENCE_STARTED_AT | DMS_DRILL_EVIDENCE_COMPLETED_AT) [[ "$v" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] ;;
    DMS_DRILL_EVIDENCE_BACKUP_BYTES) [[ "$v" =~ ^[0-9]{1,15}$ ]] ;;
    DMS_DRILL_EVIDENCE_DECISION) [ "$v" = GO ] || [[ "$v" =~ ^FAIL:[a-z0-9_]{1,60}$ ]] ;;
    *) [[ "$v" =~ ^[A-Za-z0-9_.:,=@/-]{1,200}$ ]] ;;
  esac
}

# dms_evidence_store_next <store>
# Prints "<next seq> <sha256 of the last record or genesis>" after verifying nothing about the store; the caller
# appends under a lock. Returns 1 when the store's names are not a contiguous sequence.
dms_evidence_store_next() {
  local store="${1:?store}" n=0 last="" f
  shopt -s nullglob
  local files=("$store"/[0-9][0-9][0-9][0-9][0-9][0-9].evidence)
  shopt -u nullglob
  for f in "${files[@]}"; do
    n=$((n + 1))
    [ "$(basename "$f")" = "$(printf '%06d.evidence' "$n")" ] || { echo "evidence: store is not a contiguous sequence at ${f}" >&2; return 1; }
    last="$f"
  done
  if [ -z "$last" ]; then printf '000001 genesis\n'; else printf '%06d %s\n' $((n + 1)) "$(sha256sum -- "$last" | cut -d' ' -f1)"; fi
}

# dms_evidence_write <store> <signing key> <assoc array name holding the other keys>
# Builds the record in key order, validates every value, writes it as <seq>.evidence and signs it. Prints the record
# path. The record is created exclusively (noclobber), so two writers cannot overwrite each other.
dms_evidence_write() {
  local store="${1:?store}" key_file="${2:?key}" arr="${3:?array}" seq prev rec k v tmp
  local -n src="$arr"
  read -r seq prev < <(dms_evidence_store_next "$store") || return 1
  [ -n "$seq" ] || return 1
  src[DMS_DRILL_EVIDENCE_CONTRACT]=1
  src[DMS_DRILL_EVIDENCE_SEQ]="$seq"
  src[DMS_DRILL_EVIDENCE_PREV_SHA256]="$prev"
  rec="${store}/${seq}.evidence"
  tmp="$(mktemp "${store}/.tmp.XXXXXX")" || return 1
  for k in $DMS_DRILL_EVIDENCE_KEYS; do
    v="${src[$k]-}"
    _dms_evidence_value_ok "$k" "$v" || { echo "evidence: invalid value for ${k}" >&2; rm -f -- "$tmp"; return 1; }
    printf '%s=%s\n' "$k" "$v" >> "$tmp"
  done
  if ! ssh-keygen -Y sign -f "$key_file" -n "$DMS_DRILL_EVIDENCE_NAMESPACE" "$tmp" > /dev/null 2>&1; then
    echo "evidence: signing failed" >&2
    rm -f -- "$tmp" "${tmp}.sig"
    return 1
  fi
  if ! ln -- "$tmp" "$rec" 2> /dev/null; then
    echo "evidence: ${rec} already exists" >&2
    rm -f -- "$tmp" "${tmp}.sig"
    return 1
  fi
  mv -- "${tmp}.sig" "${rec}.sig" && rm -f -- "$tmp"
  printf '%s\n' "$rec"
}

# dms_evidence_verify_store <store> <allowed_signers trust anchor> [expected head sha256]
# Verifies every record: signature (principal = its SIGNER, namespace above), exact key set, valid values, SEQ equal
# to its file name, PREV equal to the previous record's sha256 (genesis first). Prints "VERIFIED <n> head=<sha256>" or
# the first failure, returns 0 or 1.
dms_evidence_verify_store() {
  local store="${1:?store}" anchor="${2:?anchor}" want_head="${3:-}" n=0 prev=genesis f line k v signer size
  shopt -s nullglob
  local files=("$store"/[0-9][0-9][0-9][0-9][0-9][0-9].evidence)
  shopt -u nullglob
  for f in "${files[@]}"; do
    n=$((n + 1))
    [ "$(basename "$f")" = "$(printf '%06d.evidence' "$n")" ] || { echo "FAIL sequence gap at $(basename "$f")"; return 1; }
    if [ -L "$f" ] || [ ! -f "$f" ] || [ ! -f "${f}.sig" ]; then echo "FAIL $(basename "$f"): missing record or signature"; return 1; fi
    size="$(stat -c %s -- "$f")"
    [ "$size" -le "$DMS_DRILL_EVIDENCE_MAX_BYTES" ] || { echo "FAIL $(basename "$f"): too large"; return 1; }
    signer="$(sed -n 's/^DMS_DRILL_EVIDENCE_SIGNER=//p' -- "$f" | head -n 1)"
    [[ "$signer" =~ ^[A-Za-z0-9._@-]{1,64}$ ]] || { echo "FAIL $(basename "$f"): bad signer"; return 1; }
    if ! ssh-keygen -Y verify -f "$anchor" -I "$signer" -n "$DMS_DRILL_EVIDENCE_NAMESPACE" -s "${f}.sig" < "$f" > /dev/null 2>&1; then
      echo "FAIL $(basename "$f"): signature does not verify"
      return 1
    fi
    local -A seen=()
    local keys_in_order=""
    while IFS= read -r line || [ -n "$line" ]; do
      [[ "$line" =~ ^(DMS_DRILL_EVIDENCE_[A-Z0-9_]+)=(.*)$ ]] || { echo "FAIL $(basename "$f"): unexpected line"; return 1; }
      k="${BASH_REMATCH[1]}"; v="${BASH_REMATCH[2]}"
      [ -z "${seen[$k]:-}" ] || { echo "FAIL $(basename "$f"): ${k} twice"; return 1; }
      seen[$k]="$v"
      keys_in_order="${keys_in_order}${keys_in_order:+ }${k}"
      _dms_evidence_value_ok "$k" "$v" || { echo "FAIL $(basename "$f"): invalid ${k}"; return 1; }
    done < "$f"
    [ "$keys_in_order" = "$DMS_DRILL_EVIDENCE_KEYS" ] || { echo "FAIL $(basename "$f"): key set or order differs"; return 1; }
    [ "${seen[DMS_DRILL_EVIDENCE_SEQ]}" = "$(printf '%06d' "$n")" ] || { echo "FAIL $(basename "$f"): SEQ does not match its name"; return 1; }
    [ "${seen[DMS_DRILL_EVIDENCE_PREV_SHA256]}" = "$prev" ] || { echo "FAIL $(basename "$f"): chain broken (PREV does not match the previous record)"; return 1; }
    prev="$(sha256sum -- "$f" | cut -d' ' -f1)"
    unset seen
  done
  [ "$n" -gt 0 ] || { echo "FAIL empty store"; return 1; }
  if [ -n "$want_head" ] && [ "$prev" != "$want_head" ]; then echo "FAIL head ${prev} is not the expected head ${want_head} (records were dropped or added)"; return 1; fi
  echo "VERIFIED ${n} head=${prev}"
}
