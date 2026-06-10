#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

LATEST_LOG=$(ls -t storage/logs/local-check-*.log 2>/dev/null | head -1 || true)

if [[ -z "$LATEST_LOG" ]]; then
  echo "No check logs found in storage/logs/."
  echo "Run: make log-check   to generate one."
  exit 0
fi

echo "========================================"
echo " LAST CHECK LOG: $LATEST_LOG"
echo " (last 120 lines)"
echo "========================================"
echo ""
tail -120 "$LATEST_LOG"
