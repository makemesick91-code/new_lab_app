#!/usr/bin/env bash
#
# ENT-13 — Load Test 5 Cabang Baseline: non-production baseline harness.
#
# Runs the 5-branch baseline load test scenarios (RME queue, cashier invoices,
# owner dashboard, inventory stock, reports, RM lookup) via the guarded
# loadtest:baseline-run runner and writes a non-sensitive evidence pack.
#
# Flow:
#   verify non-production env -> loadtest:baseline-run --write-evidence -> evidence pack
#
# SAFETY:
#   - Fail-fast: any error stops the run (set -euo pipefail).
#   - This harness must not run against production/pilot. It aborts unless
#     APP_ENV is one of the allowed non-production environments. Load testing
#     against the production VPS pilot database is out of scope (ENT-13 rule).
#   - Read-only: it only runs the read-side baseline runner. It NEVER runs a
#     destructive database command and never seeds production data.
#
# Usage:
#   bash scripts/load-test-baseline.sh
set -euo pipefail

APP_DIR="${APP_DIR:-$(pwd)}"
EVIDENCE_DIR="storage/app/load-test"

cd "$APP_DIR"

# Resolve the current environment without printing secrets.
#
# Deliberately NOT the interactive REPL. This line asks "am I on production?",
# so running the REPL to answer it executes the forbidden command on production
# BEFORE the check that would have refused it — and the REPL writes ERROR
# records into the application log on its way. `artisan env` is purpose-built,
# read-only, and prints one line.
APP_ENV_VALUE="$(php artisan env 2>/dev/null | sed -n 's/.*\[\(.*\)\].*/\1/p' | tail -n1 | tr -d '[:space:]')"

case "$APP_ENV_VALUE" in
  local|stress|testing)
    echo "== ENT-13 baseline harness (env: ${APP_ENV_VALUE}) =="
    ;;
  *)
    echo "ABORT: ENT-13 load test must not run against production/pilot (env: ${APP_ENV_VALUE:-unknown})." >&2
    exit 1
    ;;
esac

mkdir -p "$EVIDENCE_DIR"

echo "-- running baseline scenarios --"
php artisan loadtest:baseline-run --write-evidence

echo "== ENT-13 baseline complete; evidence under ${EVIDENCE_DIR} =="
