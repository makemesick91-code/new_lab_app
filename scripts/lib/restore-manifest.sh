# shellcheck shell=bash
#
# R6-B6 isolated restore drill — the authenticated role manifest (owner directive D3, 2026-11-25).
#
# The disposable drill VM never connects to production PostgreSQL. The roles it must create (names only, NOLOGIN)
# come from a manifest that an authorised principal signed with `ssh-keygen -Y sign -n dms-restore-role-manifest`,
# verified against a root-owned allowed_signers trust anchor, as rule 236 does for release approvals.
#
# Manifest: exactly these keys, each once, nothing else, at most 4096 bytes:
#   DMS_ROLE_MANIFEST_CONTRACT=1
#   DMS_ROLE_MANIFEST_ID=<[a-z0-9-]{8,64}>
#   DMS_ROLE_MANIFEST_ROLES=<space-separated role names>
#   DMS_ROLE_MANIFEST_RUNTIME_ROLE=<one of ROLES>
#   DMS_ROLE_MANIFEST_R6_OWNER_ROLE=<one of ROLES, or none before R6-D>
#   DMS_ROLE_MANIFEST_ISSUED_BY=<signer principal>
#   DMS_ROLE_MANIFEST_ISSUED_AT=<YYYY-MM-DDTHH:MM:SSZ>
#   DMS_ROLE_MANIFEST_NOT_AFTER=<YYYY-MM-DDTHH:MM:SSZ>
# The signature (<manifest>.sig) must verify for the principal ISSUED_BY names, in the namespace above.
# Uses: D-Q6-a (every owner in the archive's TOC is in the manifest: restore-archive.sh) and P5 (after R6, the runtime
# owns nothing: from the TOC before the VM boots and from the restored catalog after), decided against the manifest
# instead of a live production cluster.

if [ -n "${DMS_RESTORE_MANIFEST_LOADED:-}" ]; then
  return 0
fi
DMS_RESTORE_MANIFEST_LOADED=1

DMS_ROLE_MANIFEST_NAMESPACE="dms-restore-role-manifest"
DMS_ROLE_MANIFEST_MAX_BYTES=4096
DMS_ROLE_MANIFEST_MAX_SIG_BYTES=4096
DMS_ROLE_MANIFEST_MAX_VALIDITY_SECONDS=$((90 * 86400))
DMS_ROLE_MANIFEST_CLOCK_SKEW_SECONDS=300
DMS_ROLE_MANIFEST_KEYS="DMS_ROLE_MANIFEST_CONTRACT DMS_ROLE_MANIFEST_ID DMS_ROLE_MANIFEST_ROLES DMS_ROLE_MANIFEST_RUNTIME_ROLE DMS_ROLE_MANIFEST_R6_OWNER_ROLE DMS_ROLE_MANIFEST_ISSUED_BY DMS_ROLE_MANIFEST_ISSUED_AT DMS_ROLE_MANIFEST_NOT_AFTER"
declare -gA DMS_ROLE_MANIFEST=()

_dms_manifest_epoch() {
  local iso="${1:-}" epoch back
  [[ "$iso" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || return 1
  epoch="$(date -u -d "$iso" +%s 2> /dev/null)" || return 1
  back="$(date -u -d "@${epoch}" +%Y-%m-%dT%H:%M:%SZ 2> /dev/null)" || return 1
  [ "$back" = "$iso" ] || return 1
  printf '%s' "$epoch"
}

# dms_restore_manifest_verify <manifest> <allowed_signers trust anchor> [now epoch]
# Verifies the signature, then the content. Fills DMS_ROLE_MANIFEST and prints the manifest's sha256. Returns 0, or 1
# with the reason on stdout. Never trusts a value before the signature has verified.
dms_restore_manifest_verify() {
  local src="${1:?manifest}" anchor="${2:?trust anchor}" now="${3:-}" f tmp rc
  DMS_ROLE_MANIFEST=()
  [ -n "$now" ] || now="$(date -u +%s)"
  for f in "$src" "${src}.sig" "$anchor"; do
    if [ -L "$f" ] || [ ! -f "$f" ]; then echo "manifest: ${f} is missing, a symlink or not a regular file"; return 1; fi
  done
  # PR-4: read the manifest and its signature ONCE into a private copy; size, signature, values and sha256 all come
  # from that copy, so swapping the file between the reads cannot slip unsigned values in.
  tmp="$(mktemp -d)" || return 1
  if ! head -c $((DMS_ROLE_MANIFEST_MAX_BYTES + 1)) -- "$src" > "${tmp}/m" \
    || ! head -c $((DMS_ROLE_MANIFEST_MAX_SIG_BYTES + 1)) -- "${src}.sig" > "${tmp}/m.sig"; then
    rm -rf -- "$tmp"
    echo "manifest: cannot read it"
    return 1
  fi
  _dms_manifest_verify_copy "${tmp}/m" "$anchor" "$now"
  rc=$?
  rm -rf -- "$tmp"
  return "$rc"
}

_dms_manifest_verify_copy() {
  local manifest="$1" anchor="$2" now="$3" sig size line key value principal
  sig="${manifest}.sig"
  size="$(stat -c %s -- "$manifest")" || return 1
  [ "$size" -le "$DMS_ROLE_MANIFEST_MAX_BYTES" ] || { echo "manifest: larger than ${DMS_ROLE_MANIFEST_MAX_BYTES} bytes"; return 1; }
  size="$(stat -c %s -- "$sig")" || return 1
  [ "$size" -le "$DMS_ROLE_MANIFEST_MAX_SIG_BYTES" ] || { echo "manifest: signature larger than ${DMS_ROLE_MANIFEST_MAX_SIG_BYTES} bytes"; return 1; }
  # The principal to verify for is read from the signed bytes, but it only selects which trusted key must have
  # signed them: a forged ISSUED_BY cannot verify for a key the anchor does not bind to that principal.
  principal="$(sed -n 's/^DMS_ROLE_MANIFEST_ISSUED_BY=//p' -- "$manifest" | head -n 1)"
  [[ "$principal" =~ ^[A-Za-z0-9._@-]{1,64}$ ]] || { echo "manifest: ISSUED_BY is not a valid principal"; return 1; }
  if ! ssh-keygen -Y verify -f "$anchor" -I "$principal" -n "$DMS_ROLE_MANIFEST_NAMESPACE" -s "$sig" < "$manifest" > /dev/null 2>&1; then
    echo "manifest: the signature does not verify for '${principal}' in namespace ${DMS_ROLE_MANIFEST_NAMESPACE}"
    return 1
  fi
  local -A seen=()
  while IFS= read -r line || [ -n "$line" ]; do
    [[ "$line" =~ ^(DMS_ROLE_MANIFEST_[A-Z0-9_]+)=(.*)$ ]] || { echo "manifest: unexpected line"; return 1; }
    key="${BASH_REMATCH[1]}"
    value="${BASH_REMATCH[2]}"
    [[ " ${DMS_ROLE_MANIFEST_KEYS} " == *" ${key} "* ]] || { echo "manifest: unknown key ${key}"; return 1; }
    [ -z "${seen[$key]:-}" ] || { echo "manifest: ${key} appears twice"; return 1; }
    seen[$key]=1
    DMS_ROLE_MANIFEST[$key]="$value"
  done < "$manifest"
  for key in $DMS_ROLE_MANIFEST_KEYS; do
    [ -n "${seen[$key]:-}" ] || { echo "manifest: ${key} is missing"; DMS_ROLE_MANIFEST=(); return 1; }
  done
  _dms_manifest_check_content "$now" || { DMS_ROLE_MANIFEST=(); return 1; }
  sha256sum -- "$manifest" | cut -d' ' -f1
}

_dms_manifest_check_content() {
  local now="$1" issued not_after r runtime_ok=0 owner_ok=0
  [ "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_CONTRACT]}" = 1 ] || { echo "manifest: unsupported contract"; return 1; }
  [[ "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ID]}" =~ ^[a-z0-9-]{8,64}$ ]] || { echo "manifest: bad ID"; return 1; }
  [ -n "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ROLES]}" ] || { echo "manifest: no roles"; return 1; }
  local -A uniq=()
  for r in ${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ROLES]}; do
    [[ "$r" =~ ^[a-z_][a-z0-9_]{0,62}$ ]] || { echo "manifest: role name '${r}' is not a plain lowercase role name"; return 1; }
    case "$r" in drill_super | drill_restore* | pg_*) echo "manifest: role name '${r}' is reserved"; return 1 ;; esac
    [ -z "${uniq[$r]:-}" ] || { echo "manifest: role '${r}' listed twice"; return 1; }
    uniq[$r]=1
    [ "$r" = "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_RUNTIME_ROLE]}" ] && runtime_ok=1
    [ "$r" = "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_R6_OWNER_ROLE]}" ] && owner_ok=1
  done
  [ "${#uniq[@]}" -le 64 ] || { echo "manifest: more than 64 roles"; return 1; }
  [ "$runtime_ok" = 1 ] || { echo "manifest: RUNTIME_ROLE is not one of ROLES"; return 1; }
  [ "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_R6_OWNER_ROLE]}" = none ] || [ "$owner_ok" = 1 ] || { echo "manifest: R6_OWNER_ROLE is neither none nor one of ROLES"; return 1; }
  issued="$(_dms_manifest_epoch "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ISSUED_AT]}")" || { echo "manifest: ISSUED_AT is not a canonical UTC time"; return 1; }
  not_after="$(_dms_manifest_epoch "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_NOT_AFTER]}")" || { echo "manifest: NOT_AFTER is not a canonical UTC time"; return 1; }
  [ "$issued" -le $((now + DMS_ROLE_MANIFEST_CLOCK_SKEW_SECONDS)) ] || { echo "manifest: issued in the future"; return 1; }
  [ "$now" -le "$not_after" ] || { echo "manifest: expired at ${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_NOT_AFTER]}"; return 1; }
  [ "$not_after" -gt "$issued" ] && [ $((not_after - issued)) -le "$DMS_ROLE_MANIFEST_MAX_VALIDITY_SECONDS" ] || { echo "manifest: validity window is empty or longer than 90 days"; return 1; }
  return 0
}

# dms_restore_manifest_revocation_check <revocation list>
# PR-9: after dms_restore_manifest_verify. The revocation list is a trusted file (root- or operator-owned, never
# writable by others: the caller checks that) holding lines of exactly two kinds:
#   revoked <manifest id>               this manifest must never be used again
#   not-before <YYYY-MM-DDTHH:MM:SSZ>   every manifest issued earlier is revoked (e.g. after R6-D changes the roles)
# Comments (#) and blank lines are allowed; anything else makes the whole check fail closed.
dms_restore_manifest_revocation_check() {
  local list="${1:?revocation list}" line id="${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ID]:-}" issued nb
  [ -n "$id" ] || { echo "manifest: not verified"; return 1; }
  [ -f "$list" ] && [ ! -L "$list" ] || { echo "manifest: the revocation list ${list} is missing"; return 1; }
  issued="$(_dms_manifest_epoch "${DMS_ROLE_MANIFEST[DMS_ROLE_MANIFEST_ISSUED_AT]}")" || { echo "manifest: bad ISSUED_AT"; return 1; }
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in '' | '#'*) continue ;; esac
    if [[ "$line" =~ ^revoked\ ([a-z0-9-]{8,64})$ ]]; then
      [ "${BASH_REMATCH[1]}" != "$id" ] || { echo "manifest: ${id} is revoked"; return 1; }
    elif [[ "$line" =~ ^not-before\ ([0-9TZ:-]{20})$ ]]; then
      nb="$(_dms_manifest_epoch "${BASH_REMATCH[1]}")" || { echo "manifest: the revocation list has a bad not-before"; return 1; }
      [ "$issued" -ge "$nb" ] || { echo "manifest: ${id} was issued before ${BASH_REMATCH[1]} and is revoked"; return 1; }
    else
      echo "manifest: the revocation list has an unexpected line"
      return 1
    fi
  done < "$list"
  return 0
}
