# shellcheck shell=bash
#
# R6-B6 isolated restore drill — authenticated, tamper-evident drill evidence (owner directive D10; review findings
# VR-1, VR-4, PR-9; Phase 3). Contract 3: the signer records the install it verified itself (INSTALL); refused and
# aborted drills are records too (DECISION REFUSED:<stage> / ABORTED:<stage>); every published record is also
# appended to an independent WITNESS log (scripts/restore-drill-evidence-witness.sh) that the signer cannot rewrite.
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
DMS_DRILL_EVIDENCE_SIGNER_KEYS="DMS_DRILL_EVIDENCE_CONTRACT DMS_DRILL_EVIDENCE_SEQ DMS_DRILL_EVIDENCE_PREV_SHA256 DMS_DRILL_EVIDENCE_SIGNER DMS_DRILL_EVIDENCE_CALLER DMS_DRILL_EVIDENCE_INSTALL DMS_DRILL_EVIDENCE_SIGNED_AT"
DMS_DRILL_EVIDENCE_REQUEST_KEYS="DMS_DRILL_EVIDENCE_RUN_ID DMS_DRILL_EVIDENCE_DRILL_HOST DMS_DRILL_EVIDENCE_STARTED_AT DMS_DRILL_EVIDENCE_COMPLETED_AT DMS_DRILL_EVIDENCE_BACKUP_SHA256 DMS_DRILL_EVIDENCE_BACKUP_BYTES DMS_DRILL_EVIDENCE_DIGEST_SOURCE DMS_DRILL_EVIDENCE_MANIFEST_ID DMS_DRILL_EVIDENCE_MANIFEST_SHA256 DMS_DRILL_EVIDENCE_IMAGE_SHA256 DMS_DRILL_EVIDENCE_LIMITS DMS_DRILL_EVIDENCE_HOST_VERIFIED DMS_DRILL_EVIDENCE_EXPECTED DMS_DRILL_EVIDENCE_GUEST_ASSERTED DMS_DRILL_EVIDENCE_DECISION"
DMS_DRILL_EVIDENCE_KEYS="${DMS_DRILL_EVIDENCE_SIGNER_KEYS} ${DMS_DRILL_EVIDENCE_REQUEST_KEYS}"
# What the orchestrator verifies on the host before the VM boots (VR-1). GO and FAIL records carry exactly this list.
DMS_DRILL_EVIDENCE_HOST_VERIFIED_FACTS="backup_sha256,archive_custom_format,manifest_signature,manifest_not_revoked,archive_toc,owners,extensions,per_table_counts,catalog_shape,image_sha256,no_nic_by_construction,limits"
# The D6 limits, fixed: a record never states others.
DMS_DRILL_EVIDENCE_LIMITS_D6="vcpu=1,memory_mib=2048,disk_gib=10,minutes=30,tasks=64"
# P5-3: the two comma lists have fixed keys in a fixed order with typed values (no duplicate, unknown or reordered key).
DMS_DRILL_EVIDENCE_EXPECTED_RE='^tables=[0-9]{1,9},rows=[0-9]{1,15},per_table_digest=[0-9a-f]{64},catalog_shape=[0-9]{1,9}(:[0-9]{1,9}){6}$'
DMS_DRILL_EVIDENCE_GUEST_RE='^restore=[a-z_]{1,20},tables=[0-9a-z]{1,12},per_table_digest=([0-9a-f]{64}|none|invalid),catalog_shape=[0-9:a-z]{1,80},user_code=[0-9a-z]{1,12},user_code_executed=[0-9a-z]{1,12},fidelity=[0-9:a-z]{1,40},vm_ifaces=[a-z0-9:]{1,40}$'

# _dms_evidence_value_ok <key> <value>
_dms_evidence_value_ok() {
  local k="$1" v="$2"
  case "$k" in
    DMS_DRILL_EVIDENCE_CONTRACT) [ "$v" = 3 ] ;;
    DMS_DRILL_EVIDENCE_SEQ) [[ "$v" =~ ^[0-9]{6}$ ]] ;;
    DMS_DRILL_EVIDENCE_PREV_SHA256) [ "$v" = genesis ] || [[ "$v" =~ ^[0-9a-f]{64}$ ]] ;;
    DMS_DRILL_EVIDENCE_RUN_ID) [[ "$v" =~ ^dmsdrill-[0-9a-f]{12}$ ]] ;;
    DMS_DRILL_EVIDENCE_BACKUP_SHA256 | DMS_DRILL_EVIDENCE_MANIFEST_SHA256 | DMS_DRILL_EVIDENCE_IMAGE_SHA256) [[ "$v" =~ ^([0-9a-f]{64}|none)$ ]] ;;
    DMS_DRILL_EVIDENCE_INSTALL) [[ "$v" =~ ^(root:[0-9a-f]{64}|unverified:[a-z0-9_]{1,60})$ ]] ;;
    DMS_DRILL_EVIDENCE_STARTED_AT | DMS_DRILL_EVIDENCE_COMPLETED_AT | DMS_DRILL_EVIDENCE_SIGNED_AT) [[ "$v" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] ;;
    DMS_DRILL_EVIDENCE_BACKUP_BYTES) [[ "$v" =~ ^[0-9]{1,15}$ ]] ;;
    DMS_DRILL_EVIDENCE_DECISION) [ "$v" = GO ] || [[ "$v" =~ ^(FAIL|REFUSED|ABORTED):[a-z0-9_]{1,60}$ ]] ;;
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
  [ "$order" = "$DMS_DRILL_EVIDENCE_REQUEST_KEYS" ] || { echo "evidence: request keys differ from contract 3"; DMS_EVIDENCE_REQUEST=(); return 1; }
}

# _dms_evidence_field <comma list of key=value> <key> — prints the value of <key>, or nothing.
_dms_evidence_field() {
  local item
  local -a items
  IFS=',' read -r -a items <<< "$1"
  for item in "${items[@]}"; do
    [ "${item%%=*}" = "$2" ] && { printf '%s' "${item#*=}"; return 0; }
  done
  return 1
}

# dms_evidence_request_policy <signer host name> <allowed image digests, space-separated> <now, epoch seconds>
# Signer side, after dms_evidence_request_parse (P4V-4, P4P-5): the request must be internally consistent and match
# what the signer knows itself, so that a well-formed but contradictory or invented record is never signed:
#   - DRILL_HOST is the signer's own host; IMAGE_SHA256 is a digest pinned in the signer's configuration;
#   - LIMITS are the D6 limits; HOST_VERIFIED is the fixed fact list (install root or operator);
#   - STARTED_AT <= COMPLETED_AT <= now (+60 s), and the drill started at most 2 hours ago;
#   - a GO needs: restore=ok, equal table counts, equal per-table digests, equal catalog shapes, no user code reachable
#     or executed, and loopback as the VM's only interface.
# A caller that controls the operator identity can still state a consistent GO it did not run (QEMU runs as the
# operator: residual VR-4); this bounds what it can state and keeps every record self-consistent.
dms_evidence_request_policy() {
  local host="${1:?host}" images=" ${2:?images} " now="${3:?now}" started completed hv exp guest e g kind k
  local -n R=DMS_EVIDENCE_REQUEST
  kind="${R[DMS_DRILL_EVIDENCE_DECISION]%%:*}"
  [ "${R[DMS_DRILL_EVIDENCE_DRILL_HOST]:-}" = "$host" ] || { echo "evidence: DRILL_HOST is not this signer's host"; return 1; }
  [ "${R[DMS_DRILL_EVIDENCE_LIMITS]:-}" = "$DMS_DRILL_EVIDENCE_LIMITS_D6" ] || { echo "evidence: LIMITS are not the D6 limits"; return 1; }
  started="$(date -u -d "${R[DMS_DRILL_EVIDENCE_STARTED_AT]:-x}" +%s 2> /dev/null)" && completed="$(date -u -d "${R[DMS_DRILL_EVIDENCE_COMPLETED_AT]:-x}" +%s 2> /dev/null)" \
    || { echo "evidence: the drill times do not parse"; return 1; }
  [ "$started" -le "$completed" ] && [ "$completed" -le $((now + 60)) ] && [ $((now - started)) -le 7200 ] \
    || { echo "evidence: the drill times are out of order or not recent (STARTED_AT <= COMPLETED_AT <= now, within 2 hours)"; return 1; }
  hv="${R[DMS_DRILL_EVIDENCE_HOST_VERIFIED]:-}"; exp="${R[DMS_DRILL_EVIDENCE_EXPECTED]:-}"; guest="${R[DMS_DRILL_EVIDENCE_GUEST_ASSERTED]:-}"
  if [ "$kind" = REFUSED ] || [ "$kind" = ABORTED ]; then
    # Phase 3: a refused or aborted drill is evidence too. It asserts nothing about a restore: no host-verified facts
    # and no guest facts; an unknown digest is `none`, a known one is valid (and the image, if named, is pinned).
    [ "$hv" = none ] || { echo "evidence: a ${kind} record claims host-verified facts"; return 1; }
    [ "$guest" = none ] || { echo "evidence: a ${kind} record carries guest facts"; return 1; }
    [ "$exp" = none ] || [[ "$exp" =~ $DMS_DRILL_EVIDENCE_EXPECTED_RE ]] || { echo "evidence: EXPECTED is not none or the fixed key list"; return 1; }
    [ "${R[DMS_DRILL_EVIDENCE_IMAGE_SHA256]}" = none ] || [[ "$images" == *" ${R[DMS_DRILL_EVIDENCE_IMAGE_SHA256]} "* ]] \
      || { echo "evidence: IMAGE_SHA256 is not a drill image pinned in the signer's configuration"; return 1; }
    return 0
  fi
  # GO and FAIL: the VM ran, so every input fact is established.
  for k in DMS_DRILL_EVIDENCE_BACKUP_SHA256 DMS_DRILL_EVIDENCE_MANIFEST_SHA256 DMS_DRILL_EVIDENCE_IMAGE_SHA256; do
    [[ "${R[$k]}" =~ ^[0-9a-f]{64}$ ]] || { echo "evidence: a ${kind} record without ${k#DMS_DRILL_EVIDENCE_}"; return 1; }
  done
  [ "${R[DMS_DRILL_EVIDENCE_MANIFEST_ID]}" != none ] || { echo "evidence: a ${kind} record without a manifest"; return 1; }
  [[ "$images" == *" ${R[DMS_DRILL_EVIDENCE_IMAGE_SHA256]} "* ]] || { echo "evidence: IMAGE_SHA256 is not a drill image pinned in the signer's configuration"; return 1; }
  [ "$hv" = "$DMS_DRILL_EVIDENCE_HOST_VERIFIED_FACTS" ] || { echo "evidence: HOST_VERIFIED is not the fixed fact list"; return 1; }
  [[ "$exp" =~ $DMS_DRILL_EVIDENCE_EXPECTED_RE ]] || { echo "evidence: EXPECTED is not the fixed key list"; return 1; }
  [[ "$guest" =~ $DMS_DRILL_EVIDENCE_GUEST_RE ]] || { echo "evidence: GUEST_ASSERTED is not the fixed key list"; return 1; }
  [ "$kind" = GO ] || return 0
  [ "$(_dms_evidence_field "$guest" restore)" = ok ] || { echo "evidence: a GO without restore=ok"; return 1; }
  for e in tables per_table_digest catalog_shape; do
    g="$(_dms_evidence_field "$guest" "$e")"
    [ -n "$g" ] && [ "$g" = "$(_dms_evidence_field "$exp" "$e")" ] || { echo "evidence: a GO whose guest ${e} differs from the expected one"; return 1; }
  done
  [ "$(_dms_evidence_field "$guest" user_code)" = 0 ] && [ "$(_dms_evidence_field "$guest" user_code_executed)" = 0 ] \
    || { echo "evidence: a GO with user code on a restore path"; return 1; }
  [ "$(_dms_evidence_field "$guest" vm_ifaces)" = lo ] || { echo "evidence: a GO from a VM with a network interface"; return 1; }
}

# dms_evidence_verify_store <store> <allowed_signers anchor> [head log] [witness log]
# Verifies every published record in sequence: signature (principal = its SIGNER, namespace above), exact key set and
# order, valid values, SEQ equal to its directory, PREV equal to the previous record's sha256 (genesis first). With a
# head log, the store's head must equal the log's last entry. Prints "VERIFIED <n> head=<sha256|genesis>" or the first
# failure. Staging directories (.stage-*) are not records and are ignored.
dms_evidence_verify_store() {
  local store="${1:?store}" anchor="${2:?anchor}" headlog="${3:-}" witness="${4:-}" n=0 prev=genesis d f line k v signer keys last
  local -a shas=()
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
    shas+=("$prev")
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
  if [ -n "$witness" ]; then
    # Phase 3: EVERY witnessed digest must match the record at that sequence number, and every record is witnessed:
    # a rollback, a truncation or a rewrite of the store after the fact is visible against the witness's own log.
    [ -f "$witness" ] && [ ! -L "$witness" ] || { echo "FAIL the witness log ${witness} is missing"; return 1; }
    local wn=0 wseq wsha
    while IFS=' ' read -r wseq wsha; do
      wn=$((wn + 1))
      [ "$wseq" = "$(printf '%06d' "$wn")" ] || { echo "FAIL the witness log has a gap at line ${wn}"; return 1; }
      [ "$wn" -le "$n" ] || { echo "FAIL the witness saw record ${wseq}, which the store no longer has (rollback or truncation)"; return 1; }
      [ "$wsha" = "${shas[$((wn - 1))]}" ] || { echo "FAIL record ${wseq} differs from the digest the witness recorded"; return 1; }
    done < "$witness"
    [ "$wn" = "$n" ] || { echo "FAIL the store has ${n} records and the witness ${wn}: a record was published without the witness"; return 1; }
  fi
  echo "VERIFIED ${n} head=${prev}"
}

# dms_evidence_publish <store> <signing key> <principal> <caller> <head log> <anchor> <install>
# Signer side, after dms_evidence_request_parse, under the store lock. Verifies the store and head log, refuses a
# run id already recorded (replay), builds and signs the record in a staging directory, publishes it with ONE rename,
# then appends the new head to the head log. Prints "RECORD <seq> <sha256>".
dms_evidence_publish() {
  local store="${1:?store}" key="${2:?key}" principal="${3:?principal}" caller="${4:?caller}" headlog="${5:?head log}" anchor="${6:?anchor}" install="${7:?install}"
  local verdict n prev seq stage k v rec_sha d rec keys tmp
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
  # P4V-5: a staging directory left by an earlier crash is never a record; it is removed here, under the store lock.
  for d in "$store"/.stage-*; do [ -d "$d" ] && [ ! -L "$d" ] && rm -rf -- "$d"; done
  shopt -u nullglob
  seq="$(printf '%06d' $((10#$n + 1)))"
  rec="$(
    printf 'DMS_DRILL_EVIDENCE_CONTRACT=3\nDMS_DRILL_EVIDENCE_SEQ=%s\nDMS_DRILL_EVIDENCE_PREV_SHA256=%s\n' "$seq" "$prev"
    printf 'DMS_DRILL_EVIDENCE_SIGNER=%s\nDMS_DRILL_EVIDENCE_CALLER=%s\nDMS_DRILL_EVIDENCE_INSTALL=%s\n' "$principal" "$caller" "$install"
    printf 'DMS_DRILL_EVIDENCE_SIGNED_AT=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    for k in $DMS_DRILL_EVIDENCE_REQUEST_KEYS; do printf '%s=%s\n' "$k" "${DMS_EVIDENCE_REQUEST[$k]}"; done
  )"
  rec="${rec}"$'\n'
  stage="$(mktemp -d "${store}/.stage-${seq}-XXXXXX")" || { echo "evidence: cannot create a staging directory"; return 1; }
  printf '%s' "$rec" > "${stage}/record" || { rm -rf -- "$stage"; echo "evidence: cannot write the record"; return 1; }
  # P4V-5: what is on disk is exactly the record built above (a full disk or a file-size limit cannot truncate it),
  # and it passes the same key-set, order and value checks verification applies.
  [ "$(sha256sum -- "${stage}/record" | cut -d' ' -f1)" = "$(printf '%s' "$rec" | sha256sum | cut -d' ' -f1)" ] \
    || { rm -rf -- "$stage"; echo "evidence: the written record differs from the built record"; return 1; }
  keys=""
  while IFS= read -r v; do
    _dms_evidence_value_ok "${v%%=*}" "${v#*=}" || { rm -rf -- "$stage"; echo "evidence: invalid value for ${v%%=*}"; return 1; }
    keys="${keys}${keys:+ }${v%%=*}"
  done < "${stage}/record"
  [ "$keys" = "$DMS_DRILL_EVIDENCE_KEYS" ] || { rm -rf -- "$stage"; echo "evidence: the record's key set differs from contract 3"; return 1; }
  # P5-5: the record, its signature and the staging directory reach the disk before the rename publishes them.
  if ! ssh-keygen -Y sign -f "$key" -n "$DMS_DRILL_EVIDENCE_NAMESPACE" "${stage}/record" > /dev/null 2>&1 \
    || ! ssh-keygen -Y verify -f "$anchor" -I "$principal" -n "$DMS_DRILL_EVIDENCE_NAMESPACE" -s "${stage}/record.sig" < "${stage}/record" > /dev/null 2>&1 \
    || ! sync -- "${stage}/record" "${stage}/record.sig" "$stage"; then
    rm -rf -- "$stage"
    echo "evidence: signing failed, or the signature does not verify against the anchor"
    return 1
  fi
  rec_sha="$(sha256sum -- "${stage}/record" | cut -d' ' -f1)"
  if [ -e "${store}/${seq}" ] || ! mv -T -n -- "$stage" "${store}/${seq}" || [ -e "$stage" ]; then
    rm -rf -- "$stage"
    echo "evidence: could not publish ${seq} atomically"
    return 1
  fi
  sync -- "$store" || { echo "evidence: record ${seq} published but the store directory could not be synced; run verify"; return 1; }
  # P4V-5: the head log is replaced atomically (copy, append, fsync, rename), so it never holds a partial line. If this
  # fails, the record is published and the head log is exactly one record behind: recover-head repairs that window.
  tmp="$(mktemp "${headlog}.XXXXXX")" || { echo "evidence: record ${seq} published but the head log was not updated; run recover-head"; return 1; }
  if ! { cat -- "$headlog" > "$tmp" && printf '%s %s\n' "$seq" "$rec_sha" >> "$tmp" && sync -- "$tmp" \
    && [ "$(tail -n 1 -- "$tmp")" = "${seq} ${rec_sha}" ] && mv -f -- "$tmp" "$headlog" && sync -- "$(dirname -- "$headlog")"; }; then
    rm -f -- "$tmp"
    echo "evidence: record ${seq} published but the head log was not updated; run recover-head"
    return 1
  fi
  echo "RECORD ${seq} ${rec_sha}"
}
