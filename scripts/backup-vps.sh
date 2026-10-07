#!/usr/bin/env bash
#
# ENT-12 — Backup & Disaster Recovery Automation: automated DB backup + retention.
#
# Produces a verified database backup and prunes old backups by retention so
# backups neither grow unbounded nor drop below the recoverable floor. This is
# the standalone, schedulable backup path; the ENT-11 deploy script already
# takes a pre-deploy backup inline.
#
# Flow:
#   pg_dump -> verify (NSF-10 foundation:backup-verify) -> prune by retention
#
# SAFETY (mirrors scripts/deploy-vps.sh):
#   - Fail-fast: any error stops the run (set -euo pipefail).
#   - Backup is verified after it is written; a truncated/zero-byte dump fails.
#   - NEVER runs a destructive database command — it only reads (pg_dump) and
#     prunes old backup *files* by mtime, keeping a minimum number of recent
#     backups.
#
# Usage:
#   bash scripts/backup-vps.sh
# Retention (days) is BACKUP_DR_RETENTION_DAYS (default 14).
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/asia-dental-lab-v2}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="storage/app/backups/deploy"

cd "$APP_DIR"

set -a
source .env
set +a

BACKUP_DR_RETENTION_DAYS="${BACKUP_DR_RETENTION_DAYS:-14}"
MIN_RETAINED="${BACKUP_DR_MIN_RETAINED:-3}"

echo "== Automated DB backup (ENT-12) =="
# SECURITY-FIX-DEPLOY-BACKUP-FILE-PERMISSIONS-1: this path runs under systemd as
# the runtime user with the default UMask (0022) and previously wrote
# `pg_dump > file` with NO chmod at all, so every scheduled dump was born — and
# stayed — 0644. The helper creates it 0600 under a scoped `umask 077` and
# publishes it unchanged at 0600 once complete.
# shellcheck source=lib/private-db-dump.sh
source "${APP_DIR}/scripts/lib/private-db-dump.sh"
dms_prepare_private_backup_dir "$BACKUP_DIR"
BACKUP="${BACKUP_DIR}/auto_backup_${STAMP}.sql"

PGPASSWORD="${DB_PASSWORD}" dms_write_private_dump "$BACKUP" pg_dump \
  -h "${DB_HOST:-127.0.0.1}" \
  -p "${DB_PORT:-5432}" \
  -U "${DB_USERNAME}" \
  -d "${DB_DATABASE}"

test -s "$BACKUP"
echo "Backup written: ${BACKUP}"

echo "== Verify backup (NSF-10) =="
php artisan foundation:backup-verify --path="$BACKUP"

echo "== Prune backups older than ${BACKUP_DR_RETENTION_DAYS} day(s) (keep >= ${MIN_RETAINED}) =="
# Count current .sql backups; only prune if we would still keep at least the
# minimum number of recent backups. Never delete below the recoverable floor.
TOTAL="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name '*.sql' | wc -l | tr -d ' ')"
if [ "$TOTAL" -gt "$MIN_RETAINED" ]; then
  # Candidate stale files (older than retention window).
  while IFS= read -r stale; do
    [ -z "$stale" ] && continue
    REMAINING="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name '*.sql' | wc -l | tr -d ' ')"
    if [ "$REMAINING" -le "$MIN_RETAINED" ]; then
      echo "Retention floor reached (${MIN_RETAINED}); keeping remaining backups."
      break
    fi
    echo "Pruning stale backup: ${stale}"
    rm -f "$stale"
  done < <(find "$BACKUP_DIR" -maxdepth 1 -type f -name '*.sql' -mtime +"$BACKUP_DR_RETENTION_DAYS" | sort)
else
  echo "Only ${TOTAL} backup(s) present; nothing pruned (floor ${MIN_RETAINED})."
fi

echo "BACKUP OK: ${BACKUP} at ${STAMP}"
