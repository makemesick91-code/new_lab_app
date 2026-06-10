#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

if [[ ! -f "artisan" ]]; then
  echo "ERROR: Not a Laravel project root. Run from /mnt/DATA/new_lab_app." >&2
  exit 1
fi

mkdir -p storage/logs

echo "Generating route list snapshot..."
php artisan route:list > storage/logs/route-list-check.txt
echo "Saved to: $PROJECT_ROOT/storage/logs/route-list-check.txt"
