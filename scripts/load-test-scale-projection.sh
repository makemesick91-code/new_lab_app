#!/usr/bin/env bash
#
# ENT-14 — Load Test Scale Projection: non-production analysis harness.
#
# Extrapolates the ENT-13 5-branch measured baseline to the configured scale
# tiers (20-branch target + national) via the guarded loadtest:scale-projection-run
# runner and writes a non-sensitive, modeled projection pack.
#
# Flow:
#   verify non-production env -> loadtest:scale-projection-run --write-evidence -> projection pack
#
# SAFETY:
#   - Fail-fast: any error stops the run (set -euo pipefail).
#   - This harness must not run against production/pilot. It aborts unless
#     APP_ENV is one of the allowed non-production environments. Projection is
#     analysis-only and never targets the production VPS pilot database.
#   - Analysis-only: it only computes a modeled projection. It NEVER runs a
#     destructive database command, never activates replica read routing, and
#     never changes production topology.
#
# Usage:
#   bash scripts/load-test-scale-projection.sh
set -euo pipefail

APP_DIR="${APP_DIR:-$(pwd)}"
EVIDENCE_DIR="storage/app/load-test"

cd "$APP_DIR"

# Resolve the current environment without printing secrets.
APP_ENV_VALUE="$(php artisan tinker --execute='echo app()->environment();' 2>/dev/null | tail -n1 | tr -d '[:space:]')"

case "$APP_ENV_VALUE" in
  local|stress|testing)
    echo "== ENT-14 scale projection harness (env: ${APP_ENV_VALUE}) =="
    ;;
  *)
    echo "ABORT: ENT-14 scale projection must not run against production/pilot (env: ${APP_ENV_VALUE:-unknown})." >&2
    exit 1
    ;;
esac

mkdir -p "$EVIDENCE_DIR"

echo "-- computing modeled scale projection --"
php artisan loadtest:scale-projection-run --write-evidence

echo "== ENT-14 scale projection complete; evidence under ${EVIDENCE_DIR} =="
