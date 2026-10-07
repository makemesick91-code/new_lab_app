#!/bin/bash

set -e

# SECURITY-FIX-DEPLOY-BACKUP-FILE-PERMISSIONS-1: `pg_dump -f <file>` creates the
# file itself, under the caller's umask (022 => 0644), so this manual helper
# produced a world-readable custom-format dump of the whole database. The dump
# now goes through the shared helper, which creates it 0600 and publishes it
# unchanged once complete.
# shellcheck source=lib/private-db-dump.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/private-db-dump.sh"

DATE=$(date +%Y%m%d_%H%M%S)
DB_NAME="${DB_DATABASE:-asia_dental_lab}"
DB_USER="${DB_USERNAME:-postgres}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
BACKUP_DIR="${BACKUP_DIR:-./backups}"

dms_prepare_private_backup_dir "$BACKUP_DIR"

dms_write_private_dump "$BACKUP_DIR/${DB_NAME}_${DATE}.dump" pg_dump \
  -h "$DB_HOST" \
  -p "$DB_PORT" \
  -U "$DB_USER" \
  -d "$DB_NAME" \
  -F c

echo "Backup completed: $BACKUP_DIR/${DB_NAME}_${DATE}.dump"
