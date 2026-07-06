#!/usr/bin/env bash
#
# ENT-12 — Backup & Disaster Recovery Automation: non-production restore rehearsal.
#
# Proves the backup is actually recoverable by restoring the latest verified
# backup into a SCRATCH database, verifying the restored data, then dropping the
# scratch database. Produces a non-sensitive restore-rehearsal evidence file
# (backup path/size, rehearsal db, table count, RTO/RPO). This is how DR
# readiness is evidenced — a dump that has never been restored is not proven.
#
# SAFETY:
#   - Fail-fast: any error stops the drill (set -euo pipefail).
#   - The rehearsal ALWAYS targets a scratch database whose name is guarded to
#     differ from the production database; if they match, the drill aborts.
#   - It drops ONLY the scratch database and NEVER restores over the production
#     database. A real production data restore is the separate, explicit
#     production restore helper step — never invoked from this drill.
#   - A fresh pg_dump snapshot of production is taken first so the drill runs
#     against a just-verified backup.
#
# Usage:
#   bash scripts/restore-rehearsal.sh [backup_file.sql]
# With no argument, the latest .sql backup in storage/app/backups/deploy is used.
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/asia-dental-lab-v2}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="storage/app/backups/deploy"
EVIDENCE_DIR="storage/release-evidence/latest"

cd "$APP_DIR"

set -a
source .env
set +a

# DR objectives — Recovery Time Objective (RTO) and Recovery Point Objective
# (RPO), in minutes, documented so the rehearsal records measurable targets.
RTO_MINUTES="${BACKUP_DR_RTO_MINUTES:-60}"
RPO_MINUTES="${BACKUP_DR_RPO_MINUTES:-1440}"

echo "== Select backup to rehearse =="
BACKUP_FILE="${1:-}"
if [ -z "$BACKUP_FILE" ]; then
  echo "No backup passed; taking a fresh verified snapshot first."
  mkdir -p "$BACKUP_DIR"
  BACKUP_FILE="${BACKUP_DIR}/rehearsal_source_${STAMP}.sql"
  PGPASSWORD="${DB_PASSWORD}" pg_dump \
    -h "${DB_HOST:-127.0.0.1}" \
    -p "${DB_PORT:-5432}" \
    -U "${DB_USERNAME}" \
    -d "${DB_DATABASE}" \
    > "$BACKUP_FILE"
fi

test -s "$BACKUP_FILE"

echo "== Verify backup (NSF-10) =="
php artisan foundation:backup-verify --path="$BACKUP_FILE"

# Scratch/non-production target. It MUST differ from the production database.
REHEARSAL_DB="${DB_DATABASE}_dr_rehearsal_${STAMP}"
if [ "$REHEARSAL_DB" = "${DB_DATABASE}" ]; then
  echo "FATAL: rehearsal target must differ from the production database. Aborting."
  exit 1
fi

echo "== Create scratch database: ${REHEARSAL_DB} =="
PGPASSWORD="${DB_PASSWORD}" createdb \
  -h "${DB_HOST:-127.0.0.1}" \
  -p "${DB_PORT:-5432}" \
  -U "${DB_USERNAME}" \
  "$REHEARSAL_DB"

cleanup() {
  echo "== Drop scratch database: ${REHEARSAL_DB} =="
  PGPASSWORD="${DB_PASSWORD}" dropdb \
    -h "${DB_HOST:-127.0.0.1}" \
    -p "${DB_PORT:-5432}" \
    -U "${DB_USERNAME}" \
    --if-exists "$REHEARSAL_DB" || true
}
trap cleanup EXIT

echo "== Restore backup into scratch database (never touches production) =="
RESTORE_START="$(date +%s)"
PGPASSWORD="${DB_PASSWORD}" psql \
  -h "${DB_HOST:-127.0.0.1}" \
  -p "${DB_PORT:-5432}" \
  -U "${DB_USERNAME}" \
  -d "$REHEARSAL_DB" \
  -v ON_ERROR_STOP=1 \
  -f "$BACKUP_FILE" > /dev/null
RESTORE_END="$(date +%s)"
RESTORE_SECONDS=$(( RESTORE_END - RESTORE_START ))

echo "== Verify restored data =="
TABLE_COUNT="$(PGPASSWORD="${DB_PASSWORD}" psql \
  -h "${DB_HOST:-127.0.0.1}" \
  -p "${DB_PORT:-5432}" \
  -U "${DB_USERNAME}" \
  -d "$REHEARSAL_DB" \
  -tAc "SELECT count(*) FROM information_schema.tables WHERE table_schema='public';")"

if [ "${TABLE_COUNT:-0}" -lt 1 ]; then
  echo "FATAL: restored scratch database has no tables — backup is not recoverable."
  exit 1
fi

BACKUP_SIZE="$(wc -c < "$BACKUP_FILE" | tr -d ' ')"

echo "== Write restore-rehearsal evidence =="
mkdir -p "$EVIDENCE_DIR"
cat > "${EVIDENCE_DIR}/restore-rehearsal.json" <<JSON
{
  "sprint": "ENT-12",
  "kind": "restore-rehearsal",
  "generated_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "backup_file": "${BACKUP_FILE}",
  "backup_size_bytes": ${BACKUP_SIZE},
  "rehearsal_db": "${REHEARSAL_DB}",
  "restored_table_count": ${TABLE_COUNT},
  "restore_seconds": ${RESTORE_SECONDS},
  "rto_minutes": ${RTO_MINUTES},
  "rpo_minutes": ${RPO_MINUTES},
  "targeted_production": false,
  "privacy_safe": true
}
JSON

echo "RESTORE-REHEARSAL OK: restored ${TABLE_COUNT} tables from ${BACKUP_FILE} into ${REHEARSAL_DB} in ${RESTORE_SECONDS}s (RTO ${RTO_MINUTES}m / RPO ${RPO_MINUTES}m)"
