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
echo " PROJECT STATUS"
echo "========================================"

echo ""
echo "--- BRANCH ---"
git rev-parse --abbrev-ref HEAD

echo ""
echo "--- LATEST 5 COMMITS ---"
git log --oneline -5

echo ""
echo "--- GIT STATUS (short) ---"
git status -s

echo ""
echo "--- DIFF STAT ---"
git diff --stat

echo ""
echo "--- SPRINT TAGS ---"
git tag --list 'sprint*' | tail -10 || echo "(no sprint tags found)"

echo ""
echo "========================================"
