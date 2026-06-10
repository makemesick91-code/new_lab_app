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

TIMESTAMP=$(date +"%Y-%m-%d-%H%M")
LOG_FILE="storage/logs/local-check-${TIMESTAMP}.log"

echo "Running full check — logging to: $LOG_FILE"
echo ""

set +e
bash scripts/check.sh 2>&1 | tee "$LOG_FILE"
EXIT_CODE=${PIPESTATUS[0]}
set -e

echo ""
echo "Log saved to: $PROJECT_ROOT/$LOG_FILE"

if [[ $EXIT_CODE -ne 0 ]]; then
  echo ""
  echo "CHECK FAILED. Log preserved at: $LOG_FILE"
  echo "Run: make tail-check   to inspect the last errors."
  exit $EXIT_CODE
fi

echo ""
echo "CHECK PASSED. Log preserved at: $LOG_FILE"
