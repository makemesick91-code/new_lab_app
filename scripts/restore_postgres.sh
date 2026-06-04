#!/bin/bash

set -e

BACKUP_FILE=$1
DB_NAME="${DB_DATABASE:-asia_dental_lab}"
DB_USER="${DB_USERNAME:-postgres}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"

if [ -z "$BACKUP_FILE" ]; then
  echo "Usage: ./scripts/restore_postgres.sh backup_file.dump"
  exit 1
fi

pg_restore \
  -h "$DB_HOST" \
  -p "$DB_PORT" \
  -U "$DB_USER" \
  -d "$DB_NAME" \
  --clean \
  --if-exists \
  "$BACKUP_FILE"

echo "Restore completed from: $BACKUP_FILE"
