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
echo " SPRINT / SUB-PHASE FINISH CHECK"
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
echo "--- DIFF STAT ---"
git diff --stat

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
echo " AUTOMATED CHECKS COMPLETE"
echo "========================================"
echo ""
echo "MANUAL NEXT STEPS:"
echo ""
echo "  1. Review changed files:"
echo "       git diff --name-only"
echo "       git status"
echo ""
echo "  2. Update docs/ if needed (sprint notes, architecture, etc.)."
echo ""
echo "  3. Stage and commit manually:"
echo "       git add <files>"
echo "       git commit -m 'your message'"
echo ""
echo "  4. If this completes a sprint or sub-phase, tag manually:"
echo "       git tag sprint-XX-done"
echo "       (or your sprint naming convention)"
echo ""
echo "  5. Push manually when ready:"
echo "       git push"
echo "       git push --tags"
echo ""
echo "  NOTE: This script does NOT commit, tag, or push."
echo "========================================"
