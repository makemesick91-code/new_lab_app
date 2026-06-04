#!/bin/bash

set -e

DATE=$(date +%Y%m%d_%H%M%S)
DB_NAME="${DB_DATABASE:-asia_dental_lab}"
DB_USER="${DB_USERNAME:-postgres}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
BACKUP_DIR="${BACKUP_DIR:-./backups}"

mkdir -p "$BACKUP_DIR"

pg_dump \
  -h "$DB_HOST" \
  -p "$DB_PORT" \
  -U "$DB_USER" \
  -d "$DB_NAME" \
  -F c \
  -f "$BACKUP_DIR/${DB_NAME}_${DATE}.dump"

echo "Backup completed: $BACKUP_DIR/${DB_NAME}_${DATE}.dump"
