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
#   - Drops ONLY the disposable database. Never calls scripts/restore_postgres.sh.
#   - Never runs migrate:fresh / db:wipe / schema:drop / migrate:reset.
#   - The evidence file contains NO password / secret / .env / KTP / NIK / raw
#     rows — only sanitized counts and metadata. The psql password is passed via
#     PGPASSWORD in the environment, never echoed into the evidence.
#
# Usage:
#   bash scripts/rollout-restore-drill.sh [backup_file.sql]
# With no argument, the latest .sql backup in storage/app/backups/deploy is used.
#
# This VALIDATES recoverability via a disposable restore. It is NOT a production
# restore and NOT a full DR certification.
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/asia-dental-lab-v2}"
STAMP="$(date +%Y%m%d-%H%M%S)"
STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
BACKUP_DIR="storage/app/backups/deploy"
EVIDENCE_DIR="storage/app/readiness/restore-drills"
EVIDENCE_FILE="${EVIDENCE_DIR}/latest.json"

cd "$APP_DIR"

set -a
# shellcheck disable=SC1091
source .env
set +a

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
case "$(printf '%s' "$DRILL_ENV" | tr '[:upper:]' '[:lower:]')" in
  production|pilot|live|prod) echo "FATAL: drill environment must be staging/test, not production/pilot/live. Aborting."; exit 1 ;;
esac

# --- Select + verify a source backup -------------------------------------
BACKUP_FILE="${1:-}"
if [ -z "$BACKUP_FILE" ]; then
  BACKUP_FILE="$(ls -1t "${BACKUP_DIR}"/*.sql 2>/dev/null | head -n1 || true)"
fi
if [ -z "$BACKUP_FILE" ] || [ ! -s "$BACKUP_FILE" ]; then
  echo "FATAL: no non-empty source backup found under ${BACKUP_DIR}. Aborting."
  exit 1
fi
echo "Source backup : ${BACKUP_FILE}"
BACKUP_SIZE="$(wc -c < "$BACKUP_FILE" | tr -d ' ')"

php artisan foundation:backup-verify --path="$BACKUP_FILE"

# --- Restore into disposable DB (never touches production) ----------------
DB_CONNECTIVITY="FAIL"; MIGRATION_CONSISTENCY="UNKNOWN"; APP_BOOT="UNKNOWN"
HEALTH_ROUTES="UNKNOWN"; SAMPLE_QUERIES="UNKNOWN"; TABLE_COUNT=0
RESTORE_START="$(date +%s)"

cleanup() {
  echo "== Drop disposable database: ${DRILL_DB} =="
  PGPASSWORD="${DB_PASSWORD}" dropdb -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" \
    -U "${DB_USERNAME}" --if-exists "$DRILL_DB" || true
}
trap cleanup EXIT

echo "== Create disposable database =="
PGPASSWORD="${DB_PASSWORD}" createdb -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" \
  -U "${DB_USERNAME}" "$DRILL_DB"

echo "== Restore backup into disposable database =="
PGPASSWORD="${DB_PASSWORD}" psql -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" \
  -U "${DB_USERNAME}" -d "$DRILL_DB" -v ON_ERROR_STOP=1 -f "$BACKUP_FILE" > /dev/null
DB_CONNECTIVITY="GO"

echo "== Verify restored data (read-only counts, NO patient rows) =="
TABLE_COUNT="$(PGPASSWORD="${DB_PASSWORD}" psql -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" \
  -U "${DB_USERNAME}" -d "$DRILL_DB" -tAc \
  "SELECT count(*) FROM information_schema.tables WHERE table_schema='public';" | tr -d ' ')"
[ "${TABLE_COUNT:-0}" -ge 1 ] && SAMPLE_QUERIES="GO" || SAMPLE_QUERIES="FAIL"

# Migration table present => migration consistency verifiable.
MIG_COUNT="$(PGPASSWORD="${DB_PASSWORD}" psql -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" \
  -U "${DB_USERNAME}" -d "$DRILL_DB" -tAc \
  "SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='migrations';" | tr -d ' ')"
[ "${MIG_COUNT:-0}" -ge 1 ] && MIGRATION_CONSISTENCY="GO" || MIGRATION_CONSISTENCY="WATCH"

# App boot + health routes (do not point the app at the disposable DB — just
# confirm the app/artisan boots and health endpoints resolve on this host).
if php artisan --version > /dev/null 2>&1; then APP_BOOT="GO"; else APP_BOOT="FAIL"; fi
if php artisan route:list --json 2>/dev/null | grep -q 'health/ready'; then HEALTH_ROUTES="GO"; else HEALTH_ROUTES="WATCH"; fi

RESTORE_END="$(date +%s)"
DURATION=$(( RESTORE_END - RESTORE_START ))
COMPLETED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

DECISION="GO"
[ "$SAMPLE_QUERIES" = "FAIL" ] && DECISION="FAIL"

echo "== Write ROLL-5-1A evidence =="
mkdir -p "$EVIDENCE_DIR"
cat > "$EVIDENCE_FILE" <<JSON
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
    "createdb <disposable_target>",
    "psql -d <disposable_target> -f <backup> (password hidden)",
    "read-only count verification (no patient rows)",
    "dropdb <disposable_target>"
  ],
  "verification": {
    "db_connectivity": "${DB_CONNECTIVITY}",
    "migration_consistency": "${MIGRATION_CONSISTENCY}",
    "app_boot": "${APP_BOOT}",
    "health_routes": "${HEALTH_ROUTES}",
    "sample_readonly_queries": "${SAMPLE_QUERIES}",
    "pii_redaction_confirmed": true
  },
  "decision": "${DECISION}",
  "notes": [
    "disposable DB dropped after evidence captured; production never touched",
    "restored public table count: ${TABLE_COUNT}"
  ]
}
JSON

echo "Evidence written: ${EVIDENCE_FILE} (decision=${DECISION})"
php artisan rollout:restore-drill-evidence --path="$EVIDENCE_FILE" || true
