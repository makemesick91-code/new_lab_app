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
# ---------------------------------------------------------------------------
# WHY THIS DRILL USED TO FAIL (B2)
# ---------------------------------------------------------------------------
# The shape was right (psql -v ON_ERROR_STOP=1 on a plain-text dump) but it
# could never complete on this host, for two privilege reasons:
#
#   1. `createdb` ran as the application role (DB_USERNAME), which has no
#      CREATEDB privilege:  "permission denied to create database".
#
#   2. Even past that, the restore aborted at the dump's extension block:
#          CREATE EXTENSION IF NOT EXISTS pg_stat_statements WITH SCHEMA public;
#          COMMENT ON EXTENSION pg_stat_statements IS '...';
#      Installing an extension is superuser-only, and pre-creating it as
#      superuser does not help either — that was measured against a disposable
#      database on this host and the next statement still failed with
#      "must be owner of extension pg_stat_statements", because COMMENT ON
#      EXTENSION requires ownership.
#
# The application role is deliberately NOT a superuser and must never be given
# superuser — it is the role a web request runs as. So the scratch database is
# created, restored and dropped by the LOCAL PostgreSQL SUPERUSER over the peer
# socket, with the dump streamed on stdin from an account that can read it
# (backups are mode 0640 owned by the runtime user, so the postgres OS account
# cannot open the file, but it can read a pipe). Ownership of the scratch
# database is still handed to the application role, so the restored object
# ownership matches production.
#
# This drill deliberately re-implements that restore inline rather than calling
# the production restore helper: ENT-12 governance forbids this script from even
# naming that helper, so that a rehearsal can never turn into a production
# restore by accident.
#
# Usage:
#   bash scripts/restore-rehearsal.sh [backup_file.sql]
# With no argument, the latest .sql backup in storage/app/backups/deploy is used.
# Must be run as root (or as the PostgreSQL superuser OS account).
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/asia-dental-lab-v2}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="${BACKUP_DIR:-storage/app/backups/deploy}"
# Overridable so the drill itself can be exercised without writing into the
# production evidence directory; the default is the real ENT-12 location.
EVIDENCE_DIR="${EVIDENCE_DIR:-storage/release-evidence/latest}"
PG_SUPERUSER_ACCOUNT="${PG_SUPERUSER_ACCOUNT:-postgres}"

cd "$APP_DIR"

set -a
source .env
set +a

# INFRA-SEC-RUNTIME-1: this drill needs root (to reach the PostgreSQL
# superuser), but artisan must NOT run as root — it would leave root-owned
# files in the runtime-owned cache/log directories and break PHP-FPM.
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

# DR objectives — Recovery Time Objective (RTO) and Recovery Point Objective
# (RPO), in minutes, documented so the rehearsal records measurable targets.
RTO_MINUTES="${BACKUP_DR_RTO_MINUTES:-60}"
RPO_MINUTES="${BACKUP_DR_RPO_MINUTES:-1440}"

# Run the given command as the local PostgreSQL superuser OS account.
as_superuser() {
    if [ "$(id -un)" = "$PG_SUPERUSER_ACCOUNT" ]; then
        "$@"
    else
        sudo -n -u "$PG_SUPERUSER_ACCOUNT" -- "$@"
    fi
}

echo "== Preflight: PostgreSQL superuser access =="
if ! SUPER_CHECK="$(as_superuser psql -X -Atqc "SELECT usesuper FROM pg_user WHERE usename = current_user" 2>/dev/null)"; then
    echo "FATAL: cannot reach PostgreSQL as OS account '${PG_SUPERUSER_ACCOUNT}'. Run this drill as root."
    exit 1
fi
if [ "$SUPER_CHECK" != "t" ]; then
    echo "FATAL: role reached via '${PG_SUPERUSER_ACCOUNT}' is not a superuser; the dump's extension statements cannot be replayed."
    exit 1
fi

echo "== Select backup to rehearse =="
BACKUP_FILE="${1:-}"
if [ -z "$BACKUP_FILE" ]; then
  echo "No backup passed; taking a fresh verified snapshot first."
  as_runtime mkdir -p "$BACKUP_DIR"
  BACKUP_FILE="${BACKUP_DIR}/rehearsal_source_${STAMP}.sql"
  # Snapshot over the peer socket as the superuser (no password anywhere in a
  # process argument list), written THROUGH the runtime user so the dump keeps
  # runtime ownership and 0640 instead of becoming a root-owned file.
  as_runtime install -m 0640 /dev/null "$BACKUP_FILE"
  set +o errexit
  as_superuser pg_dump -d "${DB_DATABASE}" | as_runtime tee "$BACKUP_FILE" > /dev/null
  SNAPSHOT_STATUS=("${PIPESTATUS[@]}")
  set -o errexit
  if [ "${SNAPSHOT_STATUS[0]:-1}" -ne 0 ] || [ "${SNAPSHOT_STATUS[1]:-1}" -ne 0 ]; then
    echo "FATAL: could not take a fresh snapshot (pg_dump=${SNAPSHOT_STATUS[0]:-?} write=${SNAPSHOT_STATUS[1]:-?})."
    exit 1
  fi
fi

test -s "$BACKUP_FILE"

echo "== Verify backup (NSF-10) =="
as_runtime php artisan foundation:backup-verify --path="$BACKUP_FILE"

# Scratch/non-production target. It MUST differ from the production database.
REHEARSAL_DB="${DB_DATABASE}_dr_rehearsal_${STAMP}"
if [ "$REHEARSAL_DB" = "${DB_DATABASE}" ]; then
  echo "FATAL: rehearsal target must differ from the production database. Aborting."
  exit 1
fi

echo "== Create scratch database: ${REHEARSAL_DB} =="
# Created by the superuser (the application role has no CREATEDB privilege) but
# OWNED by the application role, so restored object ownership matches production.
as_superuser createdb -O "${DB_USERNAME}" -T template0 -- "$REHEARSAL_DB"

cleanup() {
  echo "== Drop scratch database: ${REHEARSAL_DB} =="
  as_superuser dropdb --if-exists -- "$REHEARSAL_DB" || true
}
trap cleanup EXIT

echo "== Restore backup into scratch database (never touches production) =="
# The dump is streamed on stdin: it is mode 0640 owned by the runtime user, so
# the superuser OS account cannot open the path, but it can read a pipe.
BACKUP_OWNER="$(stat -c '%U' -- "$BACKUP_FILE")"
read_backup() {
  if [ -r "$BACKUP_FILE" ]; then
    cat -- "$BACKUP_FILE"
  else
    sudo -n -u "$BACKUP_OWNER" -- cat -- "$BACKUP_FILE"
  fi
}

RESTORE_START="$(date +%s)"
# errexit is suspended for exactly this pipeline so both members' exit statuses
# can be inspected; otherwise the shell aborts before PIPESTATUS can be read.
set +o errexit
read_backup | as_superuser psql \
  --single-transaction \
  -X \
  -q \
  -v ON_ERROR_STOP=1 \
  -d "$REHEARSAL_DB" \
  -f - > /dev/null
PIPE_STATUS=("${PIPESTATUS[@]}")
set -o errexit
RESTORE_END="$(date +%s)"
RESTORE_SECONDS=$(( RESTORE_END - RESTORE_START ))

READER_RC="${PIPE_STATUS[0]:-1}"
PSQL_RC="${PIPE_STATUS[1]:-1}"

# psql is reported first: when it stops on an error the reader dies of SIGPIPE
# (141), which is a consequence of the real failure, not a separate one.
if [ "$PSQL_RC" -ne 0 ]; then
  echo "FATAL: restore into the scratch database FAILED (psql exit ${PSQL_RC}) — the backup is not proven recoverable."
  exit 1
fi
if [ "$READER_RC" -ne 0 ]; then
  echo "FATAL: could not read the backup file (exit ${READER_RC})."
  exit 1
fi

echo "== Verify restored data =="
TABLE_COUNT="$(as_superuser psql -X -Atqc \
  "SELECT count(*) FROM information_schema.tables WHERE table_schema='public';" \
  -d "$REHEARSAL_DB")"

if [ "${TABLE_COUNT:-0}" -lt 1 ]; then
  echo "FATAL: restored scratch database has no tables — backup is not recoverable."
  exit 1
fi

# Representative row counts. Each relation is probed with to_regclass FIRST,
# because a missing relation is a planner error and cannot be guarded inside a
# single query. Counts only — no clinical data is read or recorded.
count_table() {
  local table="$1" exists
  exists="$(as_superuser psql -X -Atqc "SELECT to_regclass('public.${table}') IS NOT NULL" -d "$REHEARSAL_DB" 2>/dev/null || echo f)"
  if [ "$exists" = "t" ]; then
    as_superuser psql -X -Atqc "SELECT count(*) FROM public.${table}" -d "$REHEARSAL_DB" 2>/dev/null || echo 0
  else
    echo 0
  fi
}

PATIENT_COUNT="$(count_table mst_patients)"
VISIT_COUNT="$(count_table trx_clinic_visits)"
PAYMENT_COUNT="$(count_table trx_rme_payments)"

BACKUP_SIZE="$(wc -c < "$BACKUP_FILE" | tr -d ' ')"

echo "== Write restore-rehearsal evidence =="
# Written as the runtime user so the evidence file does not become root-owned.
as_runtime mkdir -p "$EVIDENCE_DIR"
as_runtime tee "${EVIDENCE_DIR}/restore-rehearsal.json" > /dev/null <<JSON
{
  "sprint": "ENT-12",
  "kind": "restore-rehearsal",
  "generated_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "backup_file": "${BACKUP_FILE}",
  "backup_size_bytes": ${BACKUP_SIZE},
  "rehearsal_db": "${REHEARSAL_DB}",
  "restored_table_count": ${TABLE_COUNT},
  "restored_patient_count": ${PATIENT_COUNT},
  "restored_visit_count": ${VISIT_COUNT},
  "restored_payment_count": ${PAYMENT_COUNT},
  "restore_seconds": ${RESTORE_SECONDS},
  "rto_minutes": ${RTO_MINUTES},
  "rpo_minutes": ${RPO_MINUTES},
  "targeted_production": false,
  "privacy_safe": true
}
JSON

echo "RESTORE-REHEARSAL OK: restored ${TABLE_COUNT} tables (patients=${PATIENT_COUNT} visits=${VISIT_COUNT} payments=${PAYMENT_COUNT}) from ${BACKUP_FILE} into ${REHEARSAL_DB} in ${RESTORE_SECONDS}s (RTO ${RTO_MINUTES}m / RPO ${RPO_MINUTES}m)"
