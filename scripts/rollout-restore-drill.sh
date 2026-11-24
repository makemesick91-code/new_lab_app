#!/usr/bin/env bash
#
# ROLL-5-1A — Staging restore drill (disposable DB) + evidence emitter.
#
# Restores the latest verified deploy backup into a DISPOSABLE database, runs
# read-only verification (table count + a few bounded COUNTs — NO patient rows,
# NO KTP/NIK), drops the disposable database, and writes the ROLL-5-1A canonical
# evidence JSON consumed by `rollout:restore-drill-evidence` and the ROLL-5
# readiness command.
#
# SAFETY (fail-fast, set -euo pipefail):
#   - NEVER restores over the production database. The target is a disposable DB
#     whose name is guarded to (a) differ from the production DB and (b) contain
#     a safe marker (restore_drill / staging / test). If either check fails, abort.
#   - Drops ONLY the disposable database, and only when this run proved it
#     created it: createdb succeeded in this run, and the database still has
#     the oid recorded right after creation. A name that already exists, a
#     createdb that fails, or an oid that changed means the database is not
#     this run's: it is never dropped, and the run fails. A drop that fails
#     also fails the run (decision FAIL); it is never ignored.
#     Never calls scripts/restore_postgres.sh.
#   - Never runs migrate:fresh / db:wipe / schema:drop / migrate:reset.
#   - The evidence file contains NO password / secret / .env / KTP / NIK / raw
#     rows — only sanitized counts and metadata.
#
# PRIVILEGE (DMS-SPN4-READY-PILOT-1 D-Q6 and D-Q7, findings N-5 and N-9):
#   - Runs as the privileged operator (root on the host): every database
#     operation — createdb, the replay, the counts, dropdb — goes through the
#     local PostgreSQL superuser OS account, as scripts/restore-rehearsal.sh does.
#     The application login role is never used for them and never needs
#     CREATEDB (rule 245 #5).
#   - Refuses to run when the application login role holds CREATEDB, CREATEROLE
#     or SUPERUSER.
#   - The disposable database is owned by the superuser, never by the
#     application role (a database owner can create schemas and alter or drop
#     the database). artisan runs as the runtime user, never as root.
#
# INTEGRITY (rule 235; finding A-1, 2026-11-25). The backup is a plain SQL file
# the runtime user owns and can write, and the replay runs as the superuser, so
# the drill never replays it unverified:
#   - The expected SHA-256 comes from evidence the runtime cannot write: the
#     root-only log of the deploy that wrote the backup (--deploy-id), or a digest
#     the administrator passes from an independent record (--expected-sha256).
#     There is no default "latest backup": without trusted evidence nothing runs.
#   - The backup must be a regular file (no symlink), owned by the runtime user or
#     the operator, and not group- or other-writable. It is read ONCE, by its
#     owner, into a root-only staging copy; root hashes that copy. The owner-role
#     preflight, the runtime ownership gate and the replay read only that copy,
#     so replacing the file after the check changes nothing.
#   - The replay runs in psql's restricted mode under a fresh random key: every
#     backslash meta-command in the dump is refused and stops the replay.
#   - The environment file and both libraries are loaded only when no other
#     identity could have written them.
#
# Usage:
#   bash scripts/rollout-restore-drill.sh --deploy-id <YYYYmmdd-HHMMSS> [<backup_file.sql>]
#   bash scripts/rollout-restore-drill.sh --expected-sha256 <hex> <backup_file.sql>
# With --deploy-id and no file, the backup that deploy recorded is used. Needs
# psql 16.10 or later (restricted mode).
#
# This VALIDATES recoverability via a disposable restore. It is NOT a production
# restore and NOT a full DR certification.
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/asia-dental-lab-v2}"
# The shared restore checks live beside this script. Like the two restore tools,
# the drill loads them only when no other identity could have written them: not a
# symlink, owned by the identity running the drill (root on the host), and not
# group- or other-writable, for the file and its directory.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RESTORE_LIB="${SCRIPT_DIR}/lib/restore-integrity.sh"
TRUSTED_LIB="${SCRIPT_DIR}/lib/trusted-path.sh"
for node in "$RESTORE_LIB" "${SCRIPT_DIR}/lib" "$TRUSTED_LIB"; do
  if [ -L "$node" ] || [ ! -e "$node" ] || [ "$(stat -c '%u' -- "$node")" != "$(id -u)" ] \
    || [ $(( 8#$(stat -c '%a' -- "$node") & 8#022 )) -ne 0 ]; then
    echo "FATAL: ${node} is missing, a symlink, not owned by $(id -un), or writable by another identity; refusing to load it. Aborting."
    exit 1
  fi
done
# shellcheck source=lib/trusted-path.sh
. "$TRUSTED_LIB"
# shellcheck source=lib/restore-integrity.sh
. "$RESTORE_LIB"

# --- Trusted digest evidence (rule 235, finding A-1) --------------------------
DRILL_USAGE="usage: bash scripts/rollout-restore-drill.sh (--deploy-id <YYYYmmdd-HHMMSS> [<backup_file.sql>] | --expected-sha256 <hex> <backup_file.sql>)"
BACKUP_FILE=""
EVIDENCE_DEPLOY_ID=""
EXPECTED_SHA256=""
while [ "$#" -gt 0 ]; do
  case "$1" in
    --deploy-id) EVIDENCE_DEPLOY_ID="${2:-}"; shift; [ "$#" -gt 0 ] && shift ;;
    --expected-sha256) EXPECTED_SHA256="${2:-}"; shift; [ "$#" -gt 0 ] && shift ;;
    -*) echo "$DRILL_USAGE" >&2; exit 2 ;;
    *) [ -z "$BACKUP_FILE" ] || { echo "FATAL: one backup file at most. Aborting." >&2; exit 2; }; BACKUP_FILE="$1"; shift ;;
  esac
done
if [ -n "$EVIDENCE_DEPLOY_ID" ] && [ -n "$EXPECTED_SHA256" ]; then
  echo "FATAL: pass --deploy-id OR --expected-sha256, not both. Nothing was created. Aborting." >&2
  exit 2
fi
if [ -z "$EVIDENCE_DEPLOY_ID" ] && [ -z "$EXPECTED_SHA256" ]; then
  echo "FATAL: the drill replays the backup as the PostgreSQL superuser, so it needs trusted digest evidence: --deploy-id <the deploy that wrote it> or --expected-sha256 <digest from an independent record> (rule 235, finding A-1). Nothing was created. Aborting." >&2
  echo "$DRILL_USAGE" >&2
  exit 2
fi
if [ -n "$EXPECTED_SHA256" ] && [ -z "$BACKUP_FILE" ]; then
  echo "FATAL: --expected-sha256 needs the backup file it describes. Nothing was created. Aborting." >&2
  exit 2
fi
if [ -n "$EXPECTED_SHA256" ] && [[ ! "$EXPECTED_SHA256" =~ ^[0-9a-f]{64}$ ]]; then
  echo "FATAL: --expected-sha256 must be 64 lowercase hex digits. Nothing was created. Aborting." >&2
  exit 2
fi

STAMP="$(date +%Y%m%d-%H%M%S)"
STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
EVIDENCE_DIR="storage/app/readiness/restore-drills"
EVIDENCE_FILE="${EVIDENCE_DIR}/latest.json"

cd "$APP_DIR"

# The environment file is sourced by the privileged operator: refuse one that
# another identity could have written (as scripts/restore-rehearsal.sh does).
if [ -L .env ] || [ ! -f .env ] || [ "$(stat -c '%u' -- .env)" != "$(id -u)" ] \
  || [ $(( 8#$(stat -c '%a' -- .env) & 8#022 )) -ne 0 ]; then
  echo "FATAL: the environment file is missing, a symlink, not owned by $(id -un), or writable by another identity; refusing to source it. Aborting."
  exit 1
fi
set -a
# shellcheck disable=SC1091
source .env
set +a

# artisan must not run as root: it would leave root-owned files in the
# runtime-owned cache and log directories (INFRA-SEC-RUNTIME-1).
RUNTIME_USER="daengtisiams"
if [ -r deploy/runtime-identity.conf ]; then
  RUNTIME_USER="$(sed -n 's/^[[:space:]]*DMS_RUNTIME_USER=//p' deploy/runtime-identity.conf | head -n 1 | sed 's/^"//; s/"$//')"
  RUNTIME_USER="${RUNTIME_USER:-daengtisiams}"
fi
as_runtime() {
  if [ "$(id -un)" = "$RUNTIME_USER" ]; then
    "$@"
  else
    sudo -n -u "$RUNTIME_USER" -- "$@"
  fi
}

PG_SUPERUSER_ACCOUNT="${PG_SUPERUSER_ACCOUNT:-postgres}"
as_superuser() {
  if [ "$(id -un)" = "$PG_SUPERUSER_ACCOUNT" ]; then
    "$@"
  else
    sudo -n -u "$PG_SUPERUSER_ACCOUNT" -- "$@"
  fi
}

case "${DB_USERNAME:-}" in
  ''|*[!A-Za-z0-9_]*) echo "FATAL: DB_USERNAME is missing or not a plain role name. Aborting."; exit 1 ;;
esac

DRILL_ENV="${ROLLOUT_DRILL_ENVIRONMENT:-staging}"
OPERATOR="${ROLLOUT_DRILL_OPERATOR:-ops}"

echo "== ROLL-5-1A restore drill =="

# --- Safety gate: never against production -------------------------------
DRILL_DB="${DB_DATABASE}_restore_drill_${STAMP}"
echo "Production DB : ${DB_DATABASE}"
echo "Drill target  : ${DRILL_DB}"

if [ "$DRILL_DB" = "${DB_DATABASE}" ]; then
  echo "FATAL: drill target must differ from the production database. Aborting."
  exit 1
fi
case "$DRILL_DB" in
  *restore_drill*|*staging*|*test*) : ;;
  *) echo "FATAL: drill target name must contain a safe marker (restore_drill/staging/test). Aborting."; exit 1 ;;
esac
# PostgreSQL silently truncates a longer identifier, so the name looked up
# later would not be the database created.
if [ "${#DRILL_DB}" -gt 63 ]; then
  echo "FATAL: drill target name is longer than 63 bytes and would be truncated by PostgreSQL. Aborting."
  exit 1
fi
case "$(printf '%s' "$DRILL_ENV" | tr '[:upper:]' '[:lower:]')" in
  production|pilot|live|prod) echo "FATAL: drill environment must be staging/test, not production/pilot/live. Aborting."; exit 1 ;;
esac

# --- Select + verify a source backup (rule 235, finding A-1) ---------------
if [ -n "$EVIDENCE_DEPLOY_ID" ]; then
  EVIDENCE_DIR_TRUSTED=""
  if [ -r deploy/production-host.conf ]; then
    EVIDENCE_DIR_TRUSTED="$(sed -n 's/^[[:space:]]*DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR=//p' deploy/production-host.conf | head -n 1 | sed 's/^"//; s/"$//')"
  fi
  EVIDENCE="$(dms_restore_evidence_digest "$EVIDENCE_DIR_TRUSTED" "$EVIDENCE_DEPLOY_ID" "$(hostname)" "$APP_DIR" "$(id -u)")" || {
    echo "FATAL: no trusted digest evidence for deploy ${EVIDENCE_DEPLOY_ID}. Nothing was created. Aborting."
    exit 1
  }
  if [ -z "$BACKUP_FILE" ]; then
    BACKUP_FILE="${EVIDENCE% *}"
  elif ! GIVEN_REAL="$(realpath -e -- "$BACKUP_FILE" 2>/dev/null)" \
    || ! RECORDED_REAL="$(realpath -e -- "${APP_DIR}/${EVIDENCE% *}" 2>/dev/null)" \
    || [ -z "$GIVEN_REAL" ] || [ "$GIVEN_REAL" != "$RECORDED_REAL" ]; then
    echo "FATAL: deploy ${EVIDENCE_DEPLOY_ID} recorded ${EVIDENCE% *}, not ${BACKUP_FILE}. Nothing was created. Aborting."
    exit 1
  fi
  EXPECTED_SHA256="${EVIDENCE##* }"
  INTEGRITY_SOURCE="the root-only log of deploy ${EVIDENCE_DEPLOY_ID}"
else
  INTEGRITY_SOURCE="an independent record passed by the administrator"
fi
echo "Source backup : ${BACKUP_FILE}"
dms_restore_check_source "$BACKUP_FILE" "$RUNTIME_USER" "$(id -un)" || {
  echo "FATAL: the backup file failed its source checks. Nothing was created. Aborting."
  exit 1
}
STAGING_DIR="$(mktemp -d)"
chmod 0700 -- "$STAGING_DIR"
trap 'rm -rf -- "$STAGING_DIR"' EXIT
STAGED_BACKUP="$(dms_restore_stage_copy "$BACKUP_FILE" "$STAGING_DIR")" || {
  echo "FATAL: could not stage a private copy of ${BACKUP_FILE}. Nothing was created. Aborting."
  exit 1
}
INTEGRITY="$(dms_restore_verify_copy "$STAGED_BACKUP" "$EXPECTED_SHA256")" || {
  echo "FATAL: integrity check failed: the backup does not match ${INTEGRITY_SOURCE}. Nothing was created. Aborting."
  exit 1
}
echo "$INTEGRITY (expected digest from ${INTEGRITY_SOURCE})"
BACKUP_SIZE="$(wc -c < "$STAGED_BACKUP" | tr -d ' ')"

# The runtime's own format check of its file: informational, never the trust anchor.
as_runtime php artisan foundation:backup-verify --path="$BACKUP_FILE"

# --- Privilege preflight (D-Q7: privileged operator, no runtime CREATEDB) ----
if ! SUPER_CHECK="$(as_superuser psql -X -Atqc "SELECT usesuper FROM pg_user WHERE usename = current_user" 2>/dev/null)"; then
  echo "FATAL: cannot reach PostgreSQL as OS account '${PG_SUPERUSER_ACCOUNT}'. Run this drill as the privileged operator (root). Aborting."
  exit 1
fi
if [ "$SUPER_CHECK" != "t" ]; then
  echo "FATAL: the role reached via '${PG_SUPERUSER_ACCOUNT}' is not a superuser. Aborting."
  exit 1
fi
RUNTIME_ELEVATED="$(as_superuser psql -X -Atqc "SELECT rolcreatedb OR rolcreaterole OR rolsuper FROM pg_roles WHERE rolname = '${DB_USERNAME}'")"
if [ "$RUNTIME_ELEVATED" != "f" ]; then
  echo "FATAL: the application login role '${DB_USERNAME}' holds CREATEDB, CREATEROLE or SUPERUSER, or was not found; the drill never relies on it (rule 245 #5, D-Q7). Aborting."
  exit 1
fi
DRILL_OWNER="$(as_superuser psql -X -Atqc "SELECT current_user")"
case "$DRILL_OWNER" in
  ''|*[!A-Za-z0-9_]*) echo "FATAL: could not resolve the superuser role for the disposable database. Aborting."; exit 1 ;;
esac

# Owner decision D-Q6-a: every role that owns an object in the backup must
# already exist on this cluster, checked before anything is created. A missing
# role stops the drill. It is never created here, and ownership is never
# stripped, rewritten or given to the runtime.
OWNER_ROLE_STATUS=0
MISSING_OWNER_ROLES="$(dms_restore_missing_owner_roles "$STAGED_BACKUP" as_superuser)" || OWNER_ROLE_STATUS=$?
case "$OWNER_ROLE_STATUS" in
  0) : ;;
  1) echo "FATAL: the backup makes these roles object owners, and they do not exist on this cluster: $(printf '%s\n' "$MISSING_OWNER_ROLES" | paste -sd, -). Nothing was created. An authorised operator provisions the role(s) separately, then repeats the drill (D-Q6-a). Aborting."; exit 1 ;;
  *) echo "FATAL: could not read the roles of this cluster as the superuser; nothing was created. Aborting."; exit 1 ;;
esac

# Owner directive P5: after R6 (the owner role exists on this cluster), objects
# owned by the runtime are never an acceptable final state. A pre-R6 dump is
# refused here, before anything is created, and goes through the separate
# operator-controlled legacy procedure. No flag lets it through (D-Q6-b).
RUNTIME_GATE_STATUS=0
RUNTIME_GATE="$(dms_restore_runtime_ownership_gate "$STAGED_BACKUP" "${DB_USERNAME}" as_superuser)" || RUNTIME_GATE_STATUS=$?
case "$RUNTIME_GATE_STATUS" in
  0) : ;;
  1) echo "FATAL: ${RUNTIME_GATE} Nothing was created. Aborting."; exit 1 ;;
  *) echo "FATAL: could not decide the runtime ownership gate as the superuser; nothing was created. Aborting."; exit 1 ;;
esac

# --- Restore into disposable DB (never touches production) ----------------
DB_CONNECTIVITY="FAIL"; MIGRATION_CONSISTENCY="UNKNOWN"; APP_BOOT="UNKNOWN"
HEALTH_ROUTES="UNKNOWN"; SAMPLE_QUERIES="UNKNOWN"; TABLE_COUNT=0; DATABASE_OWNER="UNKNOWN"
RESTORE_START="$(date +%s)"

# Proof of creation. Only a database this run created, still holding the oid
# read right after its creation, is ever dropped.
DRILL_DB_CREATED=0; DRILL_DB_OID=""; DROP_ATTEMPTED=0; DISPOSABLE_DROPPED="FAIL"

drill_db_oid() {
  as_superuser psql -X -Atqc "SELECT oid FROM pg_database WHERE datname = '${DRILL_DB}'"
}

drop_drill_db() {
  DROP_ATTEMPTED=1
  echo "== Drop disposable database: ${DRILL_DB} =="
  local current_oid
  if ! current_oid="$(drill_db_oid)"; then
    echo "FATAL: cannot read the oid of '${DRILL_DB}'; it is NOT dropped. Check it and drop it by hand as the superuser."
    return 1
  fi
  if [ -z "$DRILL_DB_OID" ] || [ "$current_oid" != "$DRILL_DB_OID" ]; then
    echo "FATAL: '${DRILL_DB}' is not the database this run created (created oid '${DRILL_DB_OID:-unknown}', now '${current_oid:-absent}'); it is NOT dropped."
    return 1
  fi
  if ! as_superuser dropdb -- "$DRILL_DB"; then
    echo "FATAL: could not drop '${DRILL_DB}', created by this run. Drop it by hand as the superuser."
    return 1
  fi
  DISPOSABLE_DROPPED="GO"
}

on_exit() {
  local status=$?
  if [ "$DRILL_DB_CREATED" = "1" ] && [ "$DROP_ATTEMPTED" = "0" ]; then
    drop_drill_db || status=1
  elif [ "$DRILL_DB_CREATED" = "0" ]; then
    echo "No disposable database was created by this run; nothing is dropped."
  fi
  rm -rf -- "$STAGING_DIR"
  exit "$status"
}
trap on_exit EXIT

echo "== Create disposable database (owned by the superuser '${DRILL_OWNER}') =="
EXISTING_OID="$(drill_db_oid)"
if [ -n "$EXISTING_OID" ]; then
  echo "FATAL: a database named '${DRILL_DB}' already exists. This run did not create it and does not touch it. Aborting."
  exit 1
fi
# createdb fails when the name exists, so a database created in between by
# anyone else is never marked as this run's.
if ! as_superuser createdb -O "$DRILL_OWNER" -T template0 -- "$DRILL_DB"; then
  echo "FATAL: createdb failed for '${DRILL_DB}'. This run created no database and drops none. Aborting."
  exit 1
fi
DRILL_DB_CREATED=1
DRILL_DB_OID="$(drill_db_oid)"
case "$DRILL_DB_OID" in
  ''|*[!0-9]*) echo "FATAL: cannot read the oid of '${DRILL_DB}' right after creating it; it is not dropped automatically. Aborting."; exit 1 ;;
esac

echo "== Restore backup into disposable database =="
# Only the verified root-only staging copy is replayed, streamed on stdin (the
# superuser OS account cannot open it, but it can read a pipe). The stream puts
# psql in restricted mode under a fresh random key, so no backslash meta-command
# in the dump can run as the superuser's OS account (finding A-1). Before the
# first row is loaded, the load guard refuses schema code the runtime could have
# planted that COPY would run as the superuser (CHECK, domain, generated column,
# partition key; user operators).
RESTRICT_KEY="$(dms_restore_restrict_key)" || { echo "FATAL: could not generate the restricted-mode key. Aborting."; exit 1; }
GUARD_TAG="g$(dms_restore_restrict_key | cut -c1-32)" || { echo "FATAL: could not generate the load-guard tag. Aborting."; exit 1; }
set +o errexit
dms_restore_restricted_stream "$STAGED_BACKUP" "$RESTRICT_KEY" "$GUARD_TAG" | as_superuser psql -X -q -v ON_ERROR_STOP=1 -d "$DRILL_DB" -f - > /dev/null
PIPE_STATUS=("${PIPESTATUS[@]}")
set -o errexit
# psql is reported first: when it stops on an error the reader dies of SIGPIPE.
if [ "${PIPE_STATUS[1]:-1}" -ne 0 ]; then
  echo "FATAL: the replay into '${DRILL_DB}' FAILED (psql exit ${PIPE_STATUS[1]:-1}); a refused meta-command or a SQL error stops it. Aborting."
  exit 1
fi
if [ "${PIPE_STATUS[0]:-1}" -ne 0 ]; then
  echo "FATAL: could not read the staged copy (exit ${PIPE_STATUS[0]:-1}). Aborting."
  exit 1
fi
DB_CONNECTIVITY="GO"

echo "== Verify restored data (read-only counts, NO patient rows) =="
TABLE_COUNT="$(as_superuser psql -X -d "$DRILL_DB" -tAc \
  "SELECT count(*) FROM information_schema.tables WHERE table_schema='public';" | tr -d ' ')"
[ "${TABLE_COUNT:-0}" -ge 1 ] && SAMPLE_QUERIES="GO" || SAMPLE_QUERIES="FAIL"

# Migration table present => migration consistency verifiable.
MIG_COUNT="$(as_superuser psql -X -d "$DRILL_DB" -tAc \
  "SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='migrations';" | tr -d ' ')"
[ "${MIG_COUNT:-0}" -ge 1 ] && MIGRATION_CONSISTENCY="GO" || MIGRATION_CONSISTENCY="WATCH"

# D-Q6 / N-9: the restored database is owned by the superuser, never the runtime.
RESTORED_OWNER="$(as_superuser psql -X -Atqc "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = '${DRILL_DB}'")"
if [ "$RESTORED_OWNER" = "$DRILL_OWNER" ] && [ "$RESTORED_OWNER" != "${DB_USERNAME}" ]; then DATABASE_OWNER="GO"; else DATABASE_OWNER="FAIL"; fi

# App boot + health routes (do not point the app at the disposable DB — just
# confirm the app/artisan boots and health endpoints resolve on this host).
if as_runtime php artisan --version > /dev/null 2>&1; then APP_BOOT="GO"; else APP_BOOT="FAIL"; fi
if as_runtime php artisan route:list --json 2>/dev/null | grep -q 'health/ready'; then HEALTH_ROUTES="GO"; else HEALTH_ROUTES="WATCH"; fi

# Dropped before the evidence is written, so the evidence records the result.
drop_drill_db || true
if [ "$DISPOSABLE_DROPPED" = "GO" ]; then
  CLEANUP_NOTE="disposable DB dropped after verification; production never touched"
else
  CLEANUP_NOTE="disposable DB NOT dropped: manual cleanup required; production never touched"
fi

RESTORE_END="$(date +%s)"
DURATION=$(( RESTORE_END - RESTORE_START ))
COMPLETED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

DECISION="GO"
[ "$SAMPLE_QUERIES" = "FAIL" ] && DECISION="FAIL"
[ "$DATABASE_OWNER" = "FAIL" ] && DECISION="FAIL"
[ "$DISPOSABLE_DROPPED" = "FAIL" ] && DECISION="FAIL"

echo "== Write ROLL-5-1A evidence =="
as_runtime mkdir -p "$EVIDENCE_DIR"
as_runtime tee "$EVIDENCE_FILE" > /dev/null <<JSON
{
  "schema_version": 1,
  "drill_id": "roll-5-1a-${STAMP}",
  "environment": "${DRILL_ENV}",
  "source_backup_path": "${BACKUP_FILE}",
  "source_backup_size_bytes": ${BACKUP_SIZE},
  "restore_target": "${DRILL_DB}",
  "production_overwrite": false,
  "started_at": "${STARTED_AT}",
  "completed_at": "${COMPLETED_AT}",
  "duration_seconds": ${DURATION},
  "operator": "${OPERATOR}",
  "commands_summary": [
    "createdb -O <superuser> <disposable_target> (as the superuser OS account)",
    "psql -d <disposable_target> -f - from the root-only staged copy whose SHA-256 matched trusted evidence, in restricted mode (as the superuser OS account)",
    "read-only count verification (no patient rows)",
    "dropdb <disposable_target>, only when created by this run with an unchanged oid (as the superuser OS account)"
  ],
  "verification": {
    "db_connectivity": "${DB_CONNECTIVITY}",
    "migration_consistency": "${MIGRATION_CONSISTENCY}",
    "app_boot": "${APP_BOOT}",
    "health_routes": "${HEALTH_ROUTES}",
    "sample_readonly_queries": "${SAMPLE_QUERIES}",
    "database_owner_superuser": "${DATABASE_OWNER}",
    "disposable_database_dropped": "${DISPOSABLE_DROPPED}",
    "pii_redaction_confirmed": true
  },
  "decision": "${DECISION}",
  "notes": [
    "${CLEANUP_NOTE}",
    "backup integrity: VERIFIED sha256=${EXPECTED_SHA256}",
    "restored public table count: ${TABLE_COUNT}"
  ]
}
JSON

echo "Evidence written: ${EVIDENCE_FILE} (decision=${DECISION})"
as_runtime php artisan rollout:restore-drill-evidence --path="$EVIDENCE_FILE" || true
if [ "$DISPOSABLE_DROPPED" != "GO" ]; then
  exit 1
fi
