#!/usr/bin/env bash
#
# R6-B6 F3 — read-only collection of production's restore environment, for a Restore Environment Compatibility
# Contract (scripts/lib/restore-envcontract.sh). It prints an UNSIGNED DRAFT on stdout and changes nothing.
#
# Not to be run without a separate, explicit owner authorization for read-only production access. When authorized,
# the owner runs it ON THE PRODUCTION DATABASE HOST, as the account and with the connection settings the real restore
# uses (scripts/restore_postgres.sh), so that the session settings it reports are the restore session's own: server
# defaults, role- and database-level settings, and any PGOPTIONS/PGTZ of that procedure. The owner then reviews every
# line, fills in the contract id and validity window, signs the file offline with the owner key in the namespace
# dms-restore-env-contract (runbook), and names its sha256 in the next role manifest.
#
# Usage: restore-env-contract-collect.sh <database name>
# Reads: PostgreSQL through psql (every statement read-only: default_transaction_read_only=on), getconf, the ICU
# library file name, and the tzdata version line. Writes nothing.
set -euo pipefail
DB="${1:?usage: restore-env-contract-collect.sh <database name>}"
[[ "$DB" =~ ^[a-z_][a-z0-9_]{0,62}$ ]] || { echo "the database name must be a plain identifier" >&2; exit 2; }
# The restore procedure's own PGOPTIONS stay in effect; read-only is added.
q() { PGOPTIONS="${PGOPTIONS:-} -c default_transaction_read_only=on" psql -X -At -v ON_ERROR_STOP=1 -d "$DB" -c "$1"; }
PINS="TimeZone DateStyle IntervalStyle array_nulls lc_monetary lc_numeric lc_time extra_float_digits bytea_output xmlbinary xmloption standard_conforming_strings quote_all_identifiers default_text_search_config timezone_abbreviations transform_null_equals"

ver="$(q "SELECT current_setting('server_version_num')")"
[[ "$ver" =~ ^[0-9]{6}$ ]] || { echo "unexpected server_version_num" >&2; exit 1; }
IFS='|' read -r enc prov coll ctype icu collver < <(q "SELECT pg_encoding_to_char(d.encoding), d.datlocprovider, d.datcollate, d.datctype, COALESCE(d.daticulocale, 'none'), COALESCE(d.datcollversion, 'none') FROM pg_database d WHERE d.datname = current_database()")
case "$prov" in c) prov=libc ;; i) prov=icu ;; *) prov="unsupported-${prov}" ;; esac
glibc="$(getconf GNU_LIBC_VERSION)"; glibc="${glibc#glibc }"
icuv=none
for f in /usr/lib/x86_64-linux-gnu/libicuuc.so.*.*; do
  [[ -e "$f" && "$f" =~ libicuuc\.so\.([0-9]+(\.[0-9]+){1,2})$ ]] && icuv="${BASH_REMATCH[1]}"
done
tz="$(sed -n 's/^# version \([0-9]\{4\}[a-z]\)$/\1/p' /usr/share/zoneinfo/tzdata.zi | head -n 1)"
ext="$(q "SELECT COALESCE(string_agg(extname || ':' || extversion, ',' ORDER BY extname), 'none') FROM pg_extension WHERE extname <> 'plpgsql'")"

# Notes go to stderr, so stdout is exactly the draft (the verifier accepts no comment lines).
echo "UNSIGNED DRAFT of a Restore Environment Compatibility Contract, collected read-only on $(hostname -s) at $(date -u +%Y-%m-%dT%H:%M:%SZ)." >&2
echo "Review every value; replace the four values marked FILL; sign offline (runbook); never edit after signing." >&2
echo "DMS_ENV_CONTRACT_VERSION=1"
echo "DMS_ENV_CONTRACT_ID=FILL-env-contract-id"
echo "DMS_ENV_CONTRACT_SOURCE=production"
echo "DMS_ENV_CONTRACT_ISSUED_BY=FILL-owner-principal"
echo "DMS_ENV_CONTRACT_ISSUED_AT=FILL"
echo "DMS_ENV_CONTRACT_NOT_AFTER=FILL"
echo "DMS_ENV_PG_MAJOR=$((ver / 10000))"
echo "DMS_ENV_PG_MINOR=$((ver % 100))"
echo "DMS_ENV_SERVER_ENCODING=${enc}"
echo "DMS_ENV_LOCALE_PROVIDER=${prov}"
echo "DMS_ENV_DATCOLLATE=${coll}"
echo "DMS_ENV_DATCTYPE=${ctype}"
echo "DMS_ENV_ICU_LOCALE=${icu}"
echo "DMS_ENV_COLLATION_VERSION=${collver}"
echo "DMS_ENV_GLIBC_VERSION=${glibc}"
echo "DMS_ENV_ICU_VERSION=${icuv}"
echo "DMS_ENV_TZDATA_VERSION=${tz:-UNKNOWN}"
echo "DMS_ENV_EXTENSIONS=${ext}"
for p in $PINS; do
  v="$(q "SELECT current_setting('${p}')")"
  if { [ "$p" = standard_conforming_strings ] && [ "$v" != on ]; } || { [ "$p" = xmloption ] && [ "$v" != content ]; }; then
    echo "NOTE: ${p}=${v} here, but every pg_dump archive restores under ${p}=$([ "$p" = xmloption ] && echo content || echo on) (its preamble sets it); review before signing." >&2
  fi
  echo "DMS_ENV_SET_${p^^}=${v}"
done
