#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

if [[ ! -f "artisan" ]]; then
  echo "ERROR: Not a Laravel project root. Run from /mnt/DATA/new_lab_app." >&2
  exit 1
fi

echo "========================================"
echo " LOCAL QUALITY GATE"
echo "========================================"

echo ""
echo "--- BRANCH ---"
git rev-parse --abbrev-ref HEAD

echo ""
echo "--- LATEST 5 COMMITS ---"
git log --oneline -5

echo ""
echo "--- GIT STATUS ---"
git status

echo ""
echo "--- RUNNING TESTS ---"
php artisan test

echo ""
echo "--- RUNNING PINT (dirty files only) ---"
./vendor/bin/pint --dirty

echo ""
echo "--- GENERATING ROUTE SNAPSHOT ---"
mkdir -p storage/logs
php artisan route:list > storage/logs/route-list-check.txt
echo "Route list saved to: storage/logs/route-list-check.txt"

echo ""
echo "========================================"
echo " ALL CHECKS PASSED"
echo "========================================"
