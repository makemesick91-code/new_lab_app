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
SNAPSHOT_FILE="storage/logs/context-snapshot.txt"

{
  echo "========================================"
  echo " CONTEXT SNAPSHOT"
  echo " Generated: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "========================================"

  echo ""
  echo "--- BRANCH ---"
  git rev-parse --abbrev-ref HEAD

  echo ""
  echo "--- LATEST 10 COMMITS ---"
  git log --oneline -10

  echo ""
  echo "--- GIT STATUS (short) ---"
  git status -s

  echo ""
  echo "--- DIFF STAT ---"
  git diff --stat

  echo ""
  echo "--- PHP VERSION ---"
  php --version | head -1

  echo ""
  echo "--- LARAVEL VERSION ---"
  php artisan --version

  echo ""
  echo "--- ROUTE COUNT ---"
  ROUTE_COUNT=$(php artisan route:list 2>/dev/null | grep -c '│' || echo "unknown")
  echo "Routes: $ROUTE_COUNT"

  echo ""
  echo "--- MODULES (app/Modules) ---"
  ls -1 app/Modules/ 2>/dev/null || echo "(none found)"

  echo ""
  echo "========================================"
  echo " END OF SNAPSHOT"
  echo "========================================"
} > "$SNAPSHOT_FILE"

echo "Context snapshot saved to: $PROJECT_ROOT/$SNAPSHOT_FILE"
echo ""
echo "You can paste its contents to Claude/ChatGPT for context."
echo "  cat $SNAPSHOT_FILE"
