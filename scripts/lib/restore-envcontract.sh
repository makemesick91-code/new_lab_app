#!/usr/bin/env bash
#
# R6-B6 isolated restore drill — the Restore Environment Compatibility Contract (owner directive "R6-B6 — Final
# Acceptance Closure", F3: RECORD AND PIN).
#
# A drill can only say "a real restore of this archive succeeds" for the environment the real restore runs in. That
# environment is production's, and the drill cannot observe it: the contract records it. It is a small key=value file
# the owner signs with `ssh-keygen -Y sign -n dms-restore-env-contract` (the role-manifest trust anchor), whose
# sha256 the signed role manifest names (DMS_ROLE_MANIFEST_ENV_CONTRACT_SHA256), so the contract is bound to the
# manifest and, through the manifest's digest, to every evidence record. Neither the operator nor the guest can
# supply or change production's values. Every property is in one of three classes:
#   exact      must equal the drill image's own value, read from the image at build time and bound to its sha256
#              (PG major, encoding, locale provider, collation and ctype, collation version, glibc, ICU, tzdata,
#              extensions); otherwise the drill is refused before any VM boots;
#   compatible same PostgreSQL major; the image's minor is 16.15 or later (the restore tooling's floor);
#   pinned     the restore-session settings (TimeZone, DateStyle, IntervalStyle, array_nulls, lc_*, …) are applied to
#              every restore session in the guest, which reports a digest of what it applied and observed; GO needs
#              that digest to equal the one computed here from the contract.
# Anything missing, unknown, malformed, expired, revoked, unsigned or incompatible prevents GO.

DMS_ENV_CONTRACT_NAMESPACE="dms-restore-env-contract"
DMS_ENV_CONTRACT_MAX_BYTES=4096
DMS_ENV_CONTRACT_MAX_SIG_BYTES=4096
DMS_ENV_CONTRACT_MAX_VALIDITY_SECONDS=$((90 * 86400))
DMS_ENV_CONTRACT_CLOCK_SKEW_SECONDS=300
# The pinned restore-session settings, in the canonical (digest) order.
DMS_ENV_CONTRACT_PINS="TimeZone DateStyle IntervalStyle array_nulls lc_monetary lc_numeric lc_time extra_float_digits bytea_output xmlbinary xmloption standard_conforming_strings quote_all_identifiers default_text_search_config timezone_abbreviations transform_null_equals"
DMS_ENV_CONTRACT_KEYS="DMS_ENV_CONTRACT_VERSION DMS_ENV_CONTRACT_ID DMS_ENV_CONTRACT_SOURCE DMS_ENV_CONTRACT_ISSUED_BY DMS_ENV_CONTRACT_ISSUED_AT DMS_ENV_CONTRACT_NOT_AFTER DMS_ENV_PG_MAJOR DMS_ENV_PG_MINOR DMS_ENV_SERVER_ENCODING DMS_ENV_LOCALE_PROVIDER DMS_ENV_DATCOLLATE DMS_ENV_DATCTYPE DMS_ENV_ICU_LOCALE DMS_ENV_COLLATION_VERSION DMS_ENV_GLIBC_VERSION DMS_ENV_ICU_VERSION DMS_ENV_TZDATA_VERSION DMS_ENV_EXTENSIONS"
for _p in $DMS_ENV_CONTRACT_PINS; do DMS_ENV_CONTRACT_KEYS="${DMS_ENV_CONTRACT_KEYS} DMS_ENV_SET_${_p^^}"; done
unset _p
DMS_IMAGE_PROPERTY_KEYS="DMS_IMAGE_SHA256 DMS_IMAGE_PG_MAJOR DMS_IMAGE_PG_MINOR DMS_IMAGE_GLIBC_VERSION DMS_IMAGE_ICU_VERSION DMS_IMAGE_TZDATA_VERSION DMS_IMAGE_LOCALES DMS_IMAGE_EXTENSIONS"
declare -gA DMS_ENV_CONTRACT=()
declare -gA DMS_IMAGE_PROPERTIES=()

_dms_env_epoch() {
  local iso="${1:-}" epoch back
  [[ "$iso" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || return 1
  epoch="$(date -u -d "$iso" +%s 2> /dev/null)" || return 1
  back="$(date -u -d "@${epoch}" +%Y-%m-%dT%H:%M:%SZ 2> /dev/null)" || return 1
  [ "$back" = "$iso" ] || return 1
  printf '%s' "$epoch"
}

# _dms_env_value_ok <key> <value>: every value has a strict grammar; nothing else is accepted.
_dms_env_value_ok() {
  local k="$1" v="$2"
  case "$k" in
    DMS_ENV_CONTRACT_VERSION) [ "$v" = 1 ] ;;
    DMS_ENV_CONTRACT_ID) [[ "$v" =~ ^[a-z0-9-]{8,64}$ ]] ;;
    DMS_ENV_CONTRACT_SOURCE) [[ "$v" =~ ^[a-z0-9-]{1,64}$ ]] ;;
    DMS_ENV_CONTRACT_ISSUED_BY) [[ "$v" =~ ^[A-Za-z0-9._@-]{1,64}$ ]] ;;
    DMS_ENV_CONTRACT_ISSUED_AT | DMS_ENV_CONTRACT_NOT_AFTER) _dms_env_epoch "$v" > /dev/null ;;
    DMS_ENV_PG_MAJOR) [[ "$v" =~ ^1[0-9]$ ]] ;;
    DMS_ENV_PG_MINOR) [[ "$v" =~ ^[0-9]{1,3}$ ]] ;;
    DMS_ENV_SERVER_ENCODING) [[ "$v" =~ ^[A-Z0-9_]{2,20}$ ]] ;;
    DMS_ENV_LOCALE_PROVIDER) [ "$v" = libc ] || [ "$v" = icu ] ;;
    DMS_ENV_DATCOLLATE | DMS_ENV_DATCTYPE | DMS_ENV_SET_LC_MONETARY | DMS_ENV_SET_LC_NUMERIC | DMS_ENV_SET_LC_TIME) [[ "$v" =~ ^[A-Za-z0-9_.@-]{1,32}$ ]] ;;
    DMS_ENV_ICU_LOCALE) [ "$v" = none ] || [[ "$v" =~ ^[A-Za-z0-9_.@-]{1,32}$ ]] ;;
    DMS_ENV_COLLATION_VERSION) [ "$v" = none ] || [[ "$v" =~ ^[A-Za-z0-9_.-]{1,32}$ ]] ;;
    DMS_ENV_GLIBC_VERSION) [[ "$v" =~ ^[0-9]{1,2}\.[0-9]{1,3}$ ]] ;;
    DMS_ENV_ICU_VERSION) [ "$v" = none ] || [[ "$v" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){0,2}$ ]] ;;
    DMS_ENV_TZDATA_VERSION) [[ "$v" =~ ^[0-9]{4}[a-z]$ ]] ;;
    DMS_ENV_EXTENSIONS) [ "$v" = none ] || [[ "$v" =~ ^[a-z_][a-z0-9_]{0,62}:[0-9A-Za-z.]{1,16}(,[a-z_][a-z0-9_]{0,62}:[0-9A-Za-z.]{1,16}){0,15}$ ]] ;;
    DMS_ENV_SET_TIMEZONE) [[ "$v" =~ ^[A-Za-z0-9_/+-]{1,48}$ ]] ;;
    DMS_ENV_SET_DATESTYLE) [[ "$v" =~ ^(ISO|SQL|Postgres|German),\ (MDY|DMY|YMD)$ ]] ;;
    DMS_ENV_SET_INTERVALSTYLE) [[ "$v" =~ ^(postgres|postgres_verbose|sql_standard|iso_8601)$ ]] ;;
    DMS_ENV_SET_ARRAY_NULLS | DMS_ENV_SET_QUOTE_ALL_IDENTIFIERS | DMS_ENV_SET_TRANSFORM_NULL_EQUALS) [ "$v" = on ] || [ "$v" = off ] ;;
    # These two are set by the archive itself: pg_restore's preamble opens every restore session with
    # standard_conforming_strings = on (the dumping session's value; on since PostgreSQL 9.1) and xmloption = content,
    # in production as in the drill. Another value could never be the restore's environment (and the per-session
    # digest would then FAIL every honest drill), so only these are accepted.
    DMS_ENV_SET_STANDARD_CONFORMING_STRINGS) [ "$v" = on ] ;;
    DMS_ENV_SET_EXTRA_FLOAT_DIGITS) [[ "$v" =~ ^(-1[0-5]|-[0-9]|[0-3])$ ]] ;;
    DMS_ENV_SET_BYTEA_OUTPUT) [ "$v" = hex ] || [ "$v" = escape ] ;;
    DMS_ENV_SET_XMLBINARY) [ "$v" = base64 ] || [ "$v" = hex ] ;;
    DMS_ENV_SET_XMLOPTION) [ "$v" = content ] ;;
    DMS_ENV_SET_DEFAULT_TEXT_SEARCH_CONFIG) [[ "$v" =~ ^pg_catalog\.[a-z_]{1,32}$ ]] ;;
    DMS_ENV_SET_TIMEZONE_ABBREVIATIONS) [[ "$v" =~ ^[A-Za-z0-9_.-]{1,32}$ ]] ;;
    *) return 1 ;;
  esac
}

# dms_env_contract_verify <contract> <allowed_signers anchor> [now epoch]
# Verifies the signature first, then every key and value, then the validity window. Fills DMS_ENV_CONTRACT and
# prints the contract's sha256. Returns 1 with the reason otherwise. The file and its signature are read ONCE into a
# private copy, so a swap between the reads cannot slip unsigned values in.
dms_env_contract_verify() {
  local src="${1:?contract}" anchor="${2:?trust anchor}" now="${3:-}" f tmp rc
  DMS_ENV_CONTRACT=()
  [ -n "$now" ] || now="$(date -u +%s)"
  for f in "$src" "${src}.sig" "$anchor"; do
    if [ -L "$f" ] || [ ! -f "$f" ]; then echo "env contract: ${f} is missing, a symlink or not a regular file"; return 1; fi
  done
  tmp="$(mktemp -d)" || return 1
  if ! head -c $((DMS_ENV_CONTRACT_MAX_BYTES + 1)) -- "$src" > "${tmp}/c" \
    || ! head -c $((DMS_ENV_CONTRACT_MAX_SIG_BYTES + 1)) -- "${src}.sig" > "${tmp}/c.sig"; then
    rm -rf -- "$tmp"
    echo "env contract: cannot read it"
    return 1
  fi
  _dms_env_contract_verify_copy "${tmp}/c" "$anchor" "$now"
  rc=$?
  rm -rf -- "$tmp"
  return "$rc"
}

_dms_env_contract_verify_copy() {
  local c="$1" anchor="$2" now="$3" principal line key value issued not_after
  [ "$(stat -c %s -- "$c")" -le "$DMS_ENV_CONTRACT_MAX_BYTES" ] || { echo "env contract: larger than ${DMS_ENV_CONTRACT_MAX_BYTES} bytes"; return 1; }
  [ "$(stat -c %s -- "${c}.sig")" -le "$DMS_ENV_CONTRACT_MAX_SIG_BYTES" ] || { echo "env contract: signature too large"; return 1; }
  principal="$(sed -n 's/^DMS_ENV_CONTRACT_ISSUED_BY=//p' -- "$c" | head -n 1)"
  [[ "$principal" =~ ^[A-Za-z0-9._@-]{1,64}$ ]] || { echo "env contract: ISSUED_BY is not a valid principal"; return 1; }
  if ! ssh-keygen -Y verify -f "$anchor" -I "$principal" -n "$DMS_ENV_CONTRACT_NAMESPACE" -s "${c}.sig" < "$c" > /dev/null 2>&1; then
    echo "env contract: the signature does not verify for '${principal}' in namespace ${DMS_ENV_CONTRACT_NAMESPACE}"
    return 1
  fi
  local -A seen=()
  while IFS= read -r line || [ -n "$line" ]; do
    [[ "$line" =~ ^(DMS_ENV_[A-Z0-9_]+)=(.*)$ ]] || { echo "env contract: unexpected line"; DMS_ENV_CONTRACT=(); return 1; }
    key="${BASH_REMATCH[1]}"; value="${BASH_REMATCH[2]}"
    [[ " ${DMS_ENV_CONTRACT_KEYS} " == *" ${key} "* ]] || { echo "env contract: unknown key ${key}"; DMS_ENV_CONTRACT=(); return 1; }
    [ -z "${seen[$key]:-}" ] || { echo "env contract: ${key} appears twice"; DMS_ENV_CONTRACT=(); return 1; }
    _dms_env_value_ok "$key" "$value" || { echo "env contract: ${key} has an invalid value"; DMS_ENV_CONTRACT=(); return 1; }
    seen[$key]=1
    DMS_ENV_CONTRACT[$key]="$value"
  done < "$c"
  for key in $DMS_ENV_CONTRACT_KEYS; do
    [ -n "${seen[$key]:-}" ] || { echo "env contract: ${key} is missing"; DMS_ENV_CONTRACT=(); return 1; }
  done
  issued="$(_dms_env_epoch "${DMS_ENV_CONTRACT[DMS_ENV_CONTRACT_ISSUED_AT]}")"
  not_after="$(_dms_env_epoch "${DMS_ENV_CONTRACT[DMS_ENV_CONTRACT_NOT_AFTER]}")"
  [ "$issued" -le $((now + DMS_ENV_CONTRACT_CLOCK_SKEW_SECONDS)) ] || { echo "env contract: issued in the future"; DMS_ENV_CONTRACT=(); return 1; }
  [ "$now" -le "$not_after" ] || { echo "env contract: expired at ${DMS_ENV_CONTRACT[DMS_ENV_CONTRACT_NOT_AFTER]}"; DMS_ENV_CONTRACT=(); return 1; }
  [ "$not_after" -gt "$issued" ] && [ $((not_after - issued)) -le "$DMS_ENV_CONTRACT_MAX_VALIDITY_SECONDS" ] \
    || { echo "env contract: validity window is empty or longer than 90 days"; DMS_ENV_CONTRACT=(); return 1; }
  sha256sum -- "$c" | cut -d' ' -f1
}

# dms_env_contract_revocation_check <revocation list>
# The role manifests' revocation list (same trusted file, same two line kinds) applies to contract ids too.
dms_env_contract_revocation_check() {
  local list="${1:?revocation list}" line id="${DMS_ENV_CONTRACT[DMS_ENV_CONTRACT_ID]:-}" issued nb
  [ -n "$id" ] || { echo "env contract: not verified"; return 1; }
  [ -f "$list" ] && [ ! -L "$list" ] || { echo "env contract: the revocation list ${list} is missing"; return 1; }
  issued="$(_dms_env_epoch "${DMS_ENV_CONTRACT[DMS_ENV_CONTRACT_ISSUED_AT]}")" || { echo "env contract: bad ISSUED_AT"; return 1; }
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in '' | '#'*) continue ;; esac
    if [[ "$line" =~ ^revoked\ ([a-z0-9-]{8,64})$ ]]; then
      [ "${BASH_REMATCH[1]}" != "$id" ] || { echo "env contract: ${id} is revoked"; return 1; }
    elif [[ "$line" =~ ^not-before\ ([0-9TZ:-]{20})$ ]]; then
      nb="$(_dms_env_epoch "${BASH_REMATCH[1]}")" || { echo "env contract: the revocation list has a bad not-before"; return 1; }
      [ "$issued" -ge "$nb" ] || { echo "env contract: ${id} was issued before ${BASH_REMATCH[1]} and is revoked"; return 1; }
    else
      echo "env contract: the revocation list has an unexpected line"
      return 1
    fi
  done < "$list"
  return 0
}

# dms_env_image_properties_parse <file>
# The drill image's own environment, read from the image when it was built (scripts/restore-drill-vm-image.sh) and
# bound to its sha256; installed root-owned on the drill host. Fills DMS_IMAGE_PROPERTIES.
dms_env_image_properties_parse() {
  local f="${1:?properties}" line k v
  DMS_IMAGE_PROPERTIES=()
  [ -f "$f" ] && [ ! -L "$f" ] || { echo "image properties: ${f} is missing"; return 1; }
  local -A seen=()
  while IFS= read -r line || [ -n "$line" ]; do
    [[ "$line" =~ ^(DMS_IMAGE_[A-Z0-9_]+)=(.*)$ ]] || { echo "image properties: unexpected line"; DMS_IMAGE_PROPERTIES=(); return 1; }
    k="${BASH_REMATCH[1]}"; v="${BASH_REMATCH[2]}"
    [[ " ${DMS_IMAGE_PROPERTY_KEYS} " == *" ${k} "* ]] && [ -z "${seen[$k]:-}" ] || { echo "image properties: unknown or repeated ${k}"; DMS_IMAGE_PROPERTIES=(); return 1; }
    case "$k" in
      DMS_IMAGE_SHA256) [[ "$v" =~ ^[0-9a-f]{64}$ ]] ;;
      DMS_IMAGE_PG_MAJOR) [[ "$v" =~ ^1[0-9]$ ]] ;;
      DMS_IMAGE_PG_MINOR) [[ "$v" =~ ^[0-9]{1,3}$ ]] ;;
      DMS_IMAGE_GLIBC_VERSION) [[ "$v" =~ ^[0-9]{1,2}\.[0-9]{1,3}$ ]] ;;
      DMS_IMAGE_ICU_VERSION) [ "$v" = none ] || [[ "$v" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){0,2}$ ]] ;;
      DMS_IMAGE_TZDATA_VERSION) [[ "$v" =~ ^[0-9]{4}[a-z]$ ]] ;;
      DMS_IMAGE_LOCALES) [[ "$v" =~ ^[A-Za-z0-9_.@-]{1,32}(\ [A-Za-z0-9_.@-]{1,32}){0,511}$ ]] ;;
      DMS_IMAGE_EXTENSIONS) [ "$v" = none ] || [[ "$v" =~ ^[a-z_][a-z0-9_]{0,62}:[0-9A-Za-z.]{1,16}(,[a-z_][a-z0-9_]{0,62}:[0-9A-Za-z.]{1,16}){0,63}$ ]] ;;
    esac || { echo "image properties: ${k} has an invalid value"; DMS_IMAGE_PROPERTIES=(); return 1; }
    seen[$k]=1
    DMS_IMAGE_PROPERTIES[$k]="$v"
  done < "$f"
  # shellcheck disable=SC2034  # read by the orchestrator and dms_env_compatibility_check
  for k in $DMS_IMAGE_PROPERTY_KEYS; do [ -n "${seen[$k]:-}" ] || { echo "image properties: ${k} is missing"; DMS_IMAGE_PROPERTIES=(); return 1; }; done
  return 0
}

# _dms_env_locale_norm <name>: locale -a prints C.utf8 where PostgreSQL records C.UTF-8.
_dms_env_locale_norm() { local l="${1,,}"; printf '%s' "${l//utf-8/utf8}"; }

# dms_env_compatibility_check <extension allowlist (space list or none)>
# The contract against the drill image (both verified): prints the first incompatibility and returns 1.
dms_env_compatibility_check() {
  local allow="${1:-none}" l e name
  local -n C=DMS_ENV_CONTRACT I=DMS_IMAGE_PROPERTIES
  [ -n "${C[DMS_ENV_CONTRACT_ID]:-}" ] && [ -n "${I[DMS_IMAGE_SHA256]:-}" ] || { echo "env compatibility: contract or image properties not verified"; return 1; }
  [ "${C[DMS_ENV_PG_MAJOR]}" = "${I[DMS_IMAGE_PG_MAJOR]}" ] || { echo "env compatibility: PostgreSQL ${C[DMS_ENV_PG_MAJOR]} in production, ${I[DMS_IMAGE_PG_MAJOR]} in the drill image (exact major required)"; return 1; }
  [ "${I[DMS_IMAGE_PG_MINOR]}" -ge 15 ] || { echo "env compatibility: the drill image's PostgreSQL minor is below 16.15"; return 1; }
  [ "${C[DMS_ENV_SERVER_ENCODING]}" = UTF8 ] || { echo "env compatibility: the drill restores UTF8 databases only (contract: ${C[DMS_ENV_SERVER_ENCODING]})"; return 1; }
  [ "${C[DMS_ENV_LOCALE_PROVIDER]}" = libc ] && [ "${C[DMS_ENV_ICU_LOCALE]}" = none ] || { echo "env compatibility: only the libc locale provider is supported by the drill (contract: ${C[DMS_ENV_LOCALE_PROVIDER]})"; return 1; }
  [ "${C[DMS_ENV_DATCOLLATE]}" = "${C[DMS_ENV_DATCTYPE]}" ] || { echo "env compatibility: different collate and ctype locales are not supported by the drill"; return 1; }
  [ "${C[DMS_ENV_GLIBC_VERSION]}" = "${I[DMS_IMAGE_GLIBC_VERSION]}" ] || { echo "env compatibility: glibc ${C[DMS_ENV_GLIBC_VERSION]} in production, ${I[DMS_IMAGE_GLIBC_VERSION]} in the drill image (collation; exact required)"; return 1; }
  [ "${C[DMS_ENV_ICU_VERSION]}" = "${I[DMS_IMAGE_ICU_VERSION]}" ] || { echo "env compatibility: ICU ${C[DMS_ENV_ICU_VERSION]} in production, ${I[DMS_IMAGE_ICU_VERSION]} in the drill image (exact required)"; return 1; }
  [ "${C[DMS_ENV_TZDATA_VERSION]}" = "${I[DMS_IMAGE_TZDATA_VERSION]}" ] || { echo "env compatibility: tzdata ${C[DMS_ENV_TZDATA_VERSION]} in production, ${I[DMS_IMAGE_TZDATA_VERSION]} in the drill image (exact required)"; return 1; }
  for l in "${C[DMS_ENV_DATCOLLATE]}" "${C[DMS_ENV_SET_LC_MONETARY]}" "${C[DMS_ENV_SET_LC_NUMERIC]}" "${C[DMS_ENV_SET_LC_TIME]}"; do
    case "$l" in C | POSIX) continue ;; esac
    [[ " $(_dms_env_locale_norm "${I[DMS_IMAGE_LOCALES]}") " == *" $(_dms_env_locale_norm "$l") "* ]] || { echo "env compatibility: locale ${l} is not available in the drill image"; return 1; }
  done
  # R6F-E13: the collation version libc gives that locale on the image's glibc (none for C, POSIX and C.*); a database
  # that recorded another version (stale after a glibc upgrade) cannot be reproduced, and is refused here, not late.
  local cv=none
  case "${C[DMS_ENV_DATCOLLATE]}" in C | POSIX | C.*) cv=none ;; *) cv="${I[DMS_IMAGE_GLIBC_VERSION]}" ;; esac
  [ "${C[DMS_ENV_COLLATION_VERSION]}" = "$cv" ] || { echo "env compatibility: the recorded collation version ${C[DMS_ENV_COLLATION_VERSION]} is not the ${cv} that locale ${C[DMS_ENV_DATCOLLATE]} has on glibc ${I[DMS_IMAGE_GLIBC_VERSION]} (stale in production, or another glibc)"; return 1; }
  if [ "${C[DMS_ENV_EXTENSIONS]}" != none ]; then
    for e in ${C[DMS_ENV_EXTENSIONS]//,/ }; do
      name="${e%%:*}"
      [[ " ${allow} " == *" ${name} "* ]] || { echo "env compatibility: extension ${name} is outside the drill's allowlist"; return 1; }
      [[ ",${I[DMS_IMAGE_EXTENSIONS]}," == *",${e},"* ]] || { echo "env compatibility: extension ${e} is not the version the drill image has"; return 1; }
    done
  fi
  return 0
}

# dms_env_pins: the pinned settings for the input disk, "name=value;…" in canonical order.
dms_env_pins() {
  local p out=""
  for p in $DMS_ENV_CONTRACT_PINS; do out="${out}${out:+;}${p}=${DMS_ENV_CONTRACT[DMS_ENV_SET_${p^^}]}"; done
  printf '%s' "$out"
}

# dms_env_expected_digest: sha256 of the canonical environment lines the guest reports after applying the pins
# (scripts/restore-drill-sandbox-inner.sh computes the same lines from what its restore session observes).
dms_env_expected_digest() {
  local p
  {
    printf 'pg_major=%s\nserver_encoding=%s\nlocale_provider=%s\n' "${DMS_ENV_CONTRACT[DMS_ENV_PG_MAJOR]}" "${DMS_ENV_CONTRACT[DMS_ENV_SERVER_ENCODING]}" "${DMS_ENV_CONTRACT[DMS_ENV_LOCALE_PROVIDER]}"
    printf 'datcollate=%s\ndatctype=%s\ncollation_version=%s\ntzdata_version=%s\n' "${DMS_ENV_CONTRACT[DMS_ENV_DATCOLLATE]}" "${DMS_ENV_CONTRACT[DMS_ENV_DATCTYPE]}" \
      "${DMS_ENV_CONTRACT[DMS_ENV_COLLATION_VERSION]}" "${DMS_ENV_CONTRACT[DMS_ENV_TZDATA_VERSION]}"
    for p in $DMS_ENV_CONTRACT_PINS; do printf '%s=%s\n' "$p" "${DMS_ENV_CONTRACT[DMS_ENV_SET_${p^^}]}"; done
  } | sha256sum | cut -d' ' -f1
}
