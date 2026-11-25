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
# INTEGRITY (rule 235, DMS-DEPLOY-PRIVSEP-PHASE-2): psql only ever reads a
# root-only staging copy whose SHA-256 matches trusted evidence
# (scripts/lib/restore-integrity.sh). A fresh snapshot is written by pg_dump
# straight into that root-only copy and hashed by root; the runtime-owned copy
# kept for the backup check is written FROM it and never read back. A backup
# passed in needs --deploy-id (its deploy's root-only log) or --expected-sha256
# (an independent record the administrator holds); otherwise nothing is restored.
#
# Usage:
#   bash scripts/restore-rehearsal.sh [<backup_file.sql> (--deploy-id <id> | --expected-sha256 <hex>)]
# With no backup file, a fresh snapshot of the production database is taken.
# Must be run as root.
set -euo pipefail

REHEARSE_FILE=""
EVIDENCE_DEPLOY_ID=""
EXPECTED_SHA256=""
while [ "$#" -gt 0 ]; do
  case "$1" in
    --deploy-id) EVIDENCE_DEPLOY_ID="${2:-}"; shift 2 ;;
    --expected-sha256) EXPECTED_SHA256="${2:-}"; shift 2 ;;
    -*) echo "usage: bash scripts/restore-rehearsal.sh [<backup_file.sql> (--deploy-id <id> | --expected-sha256 <hex>)]" >&2; exit 2 ;;
    *) [ -z "$REHEARSE_FILE" ] || { echo "FATAL: one backup file at most" >&2; exit 2; }; REHEARSE_FILE="$1"; shift ;;
  esac
done
if [ -n "$REHEARSE_FILE" ] && [ -z "$EVIDENCE_DEPLOY_ID" ] && [ -z "$EXPECTED_SHA256" ]; then
  echo "FATAL: a passed backup needs trusted digest evidence: --deploy-id <the deploy that wrote it> or --expected-sha256 <digest from an independent record> (rule 235)" >&2
  exit 2
fi
if [ -n "$EVIDENCE_DEPLOY_ID" ] && [ -n "$EXPECTED_SHA256" ]; then
  echo "FATAL: pass --deploy-id OR --expected-sha256, not both" >&2
  exit 2
fi

APP_DIR="${APP_DIR:-/var/www/asia-dental-lab-v2}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="${BACKUP_DIR:-storage/app/backups/deploy}"
# Overridable so the drill itself can be exercised without writing into the
# production evidence directory; the default is the real ENT-12 location.
EVIDENCE_DIR="${EVIDENCE_DIR:-storage/release-evidence/latest}"
PG_SUPERUSER_ACCOUNT="${PG_SUPERUSER_ACCOUNT:-postgres}"

cd "$APP_DIR"

# Rule 235: root sources the environment file and loads two libraries; refuse any
# of them (or a library's directory) that another identity could have written.
for node in .env scripts/lib scripts/lib/trusted-path.sh scripts/lib/restore-integrity.sh; do
  if [ -L "$node" ] || [ "$(stat -c '%u' -- "$node")" != 0 ] || [ $(( 8#$(stat -c '%a' -- "$node") & 8#022 )) -ne 0 ]; then
    echo "FATAL: ${node} is a symlink, not root's, or writable by another identity — refusing to use it"
    exit 1
  fi
done
# shellcheck source=lib/trusted-path.sh
. scripts/lib/trusted-path.sh
# shellcheck source=lib/restore-integrity.sh
. scripts/lib/restore-integrity.sh

set -a
source .env
set +a

STAGING_DIR="$(mktemp -d)"
chmod 0700 -- "$STAGING_DIR"
STAGED_BACKUP=""
trap 'rm -rf -- "$STAGING_DIR"' EXIT

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
BACKUP_FILE="$REHEARSE_FILE"
if [ -z "$BACKUP_FILE" ]; then
  echo "No backup passed; taking a fresh verified snapshot first."
  as_runtime mkdir -p "$BACKUP_DIR"
  BACKUP_FILE="${BACKUP_DIR}/rehearsal_source_${STAMP}.sql"
  # Snapshot over the peer socket as the superuser (no password anywhere in a
  # process argument list) into root's private staging copy; root hashes it.
  STAGED_BACKUP="${STAGING_DIR}/dump.sql"
  ( umask 077 && set -C && : > "$STAGED_BACKUP" )
  as_superuser pg_dump -d "${DB_DATABASE}" > "$STAGED_BACKUP"
  EXPECTED_SHA256="$(sha256sum < "$STAGED_BACKUP" | cut -d' ' -f1)"
  # The runtime-owned copy (0640) the backup check reads is written FROM the
  # staging copy and never read back by root or the superuser.
  as_runtime install -m 0640 /dev/null "$BACKUP_FILE"
  as_runtime tee "$BACKUP_FILE" < "$STAGED_BACKUP" > /dev/null
  echo "Fresh snapshot: ${BACKUP_FILE} sha256=${EXPECTED_SHA256}"
else
  if [ -n "$EVIDENCE_DEPLOY_ID" ]; then
    EVIDENCE_DIR_TRUSTED="$(sed -n 's/^[[:space:]]*DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR=//p' deploy/production-host.conf | head -n 1 | sed 's/^"//; s/"$//')"
    EVIDENCE="$(dms_restore_evidence_digest "$EVIDENCE_DIR_TRUSTED" "$EVIDENCE_DEPLOY_ID" "$(hostname)" "$APP_DIR" "$(id -u)")" || {
      echo "FATAL: no trusted digest evidence for deploy ${EVIDENCE_DEPLOY_ID} — nothing was restored."
      exit 1
    }
    if [ "$(realpath -e -- "$BACKUP_FILE")" != "$(realpath -e -- "${APP_DIR}/${EVIDENCE% *}")" ]; then
      echo "FATAL: deploy ${EVIDENCE_DEPLOY_ID} recorded ${EVIDENCE% *}, not ${BACKUP_FILE} — nothing was restored."
      exit 1
    fi
    EXPECTED_SHA256="${EVIDENCE##* }"
  fi
  STAGED_BACKUP="$(dms_restore_stage_copy "$BACKUP_FILE" "$STAGING_DIR")" || {
    echo "FATAL: could not stage a private copy of ${BACKUP_FILE} — nothing was restored."
    exit 1
  }
fi
dms_restore_verify_copy "$STAGED_BACKUP" "$EXPECTED_SHA256" || {
  echo "FATAL: integrity check failed — nothing was restored."
  exit 1
}

# Owner decision D-Q6-a: every role that owns an object in the dump must already
# exist on this cluster, checked before the scratch database is created. A
# missing role stops the rehearsal. It is never created here, and ownership is
# never stripped, rewritten or given to the runtime.
OWNER_ROLE_STATUS=0
MISSING_OWNER_ROLES="$(dms_restore_missing_owner_roles "$STAGED_BACKUP" as_superuser)" || OWNER_ROLE_STATUS=$?
case "$OWNER_ROLE_STATUS" in
  0) : ;;
  1) echo "FATAL: the dump makes these roles object owners, and they do not exist on this cluster: $(printf '%s\n' "$MISSING_OWNER_ROLES" | paste -sd, -). Nothing was created or restored. An authorised operator provisions the role(s) separately, then repeats the rehearsal (D-Q6-a)."; exit 1 ;;
  *) echo "FATAL: could not read the roles of this cluster as the superuser; nothing was created or restored."; exit 1 ;;
esac

# Owner directive P5: after R6 (the owner role exists on this cluster), objects
# owned by the runtime are never an acceptable final state. A pre-R6 dump is
# refused here, before anything is created, and goes through the separate
# operator-controlled legacy procedure. No flag lets it through (D-Q6-b).
RUNTIME_GATE_STATUS=0
RUNTIME_GATE="$(dms_restore_runtime_ownership_gate "$STAGED_BACKUP" "${DB_USERNAME}" as_superuser)" || RUNTIME_GATE_STATUS=$?
case "$RUNTIME_GATE_STATUS" in
  0) : ;;
  1) echo "FATAL: ${RUNTIME_GATE} Nothing was created."; exit 1 ;;
  *) echo "FATAL: could not decide the runtime ownership gate as the superuser; nothing was created."; exit 1 ;;
esac

echo "== Verify backup (NSF-10) =="
as_runtime php artisan foundation:backup-verify --path="$BACKUP_FILE"

# Scratch/non-production target. It MUST differ from the production database.
REHEARSAL_DB="${DB_DATABASE}_dr_rehearsal_${STAMP}"
if [ "$REHEARSAL_DB" = "${DB_DATABASE}" ]; then
  echo "FATAL: rehearsal target must differ from the production database. Aborting."
  exit 1
fi

echo "== Create scratch database: ${REHEARSAL_DB} =="
# Created AND owned by the superuser, as the production database is
# (DMS-SPN4-READY-PILOT-1 D-Q6, finding N-9). Object ownership comes from the
# dump's own OWNER TO statements, never from the database owner; a database
# owned by the application role would let it create schemas and alter or drop
# the database, undoing rule 245 at the database level.
REHEARSAL_OWNER="$(as_superuser psql -X -Atqc "SELECT current_user")"
case "$REHEARSAL_OWNER" in
  ''|*[!A-Za-z0-9_]*) echo "FATAL: could not resolve the superuser role for the scratch database."; exit 1 ;;
esac
if [ "$REHEARSAL_OWNER" = "${DB_USERNAME}" ]; then
  echo "FATAL: the scratch database would be owned by the application login role (D-Q6, N-9)."
  exit 1
fi
as_superuser createdb -O "$REHEARSAL_OWNER" -T template0 -- "$REHEARSAL_DB"

cleanup() {
  echo "== Drop scratch database: ${REHEARSAL_DB} =="
  as_superuser dropdb --if-exists -- "$REHEARSAL_DB" || true
  rm -rf -- "$STAGING_DIR"
}
trap cleanup EXIT

echo "== Restore backup into scratch database (never touches production) =="
# Only the verified root-only staging copy is streamed on stdin (the superuser
# OS account cannot open it, but it can read a pipe).
read_backup() {
  cat -- "$STAGED_BACKUP"
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

BACKUP_SIZE="$(wc -c < "$STAGED_BACKUP" | tr -d ' ')"

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
