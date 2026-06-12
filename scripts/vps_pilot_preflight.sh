#!/usr/bin/env bash
# VPS Pilot Preflight — read-mostly checks (Sprint 22 Phase 22.3)
# Does NOT modify database or run migrations/seeders.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

echo "========================================"
echo " VPS PILOT PREFLIGHT (read-only)"
echo "========================================"
echo ""
echo "This script does not modify database data."
echo "Do not run migrate:fresh or db:wipe on VPS."
echo "Do not run unqualified php artisan db:seed on VPS unless reviewed."
echo ""
echo "After deploy, validate Owner Dashboard manually:"
echo "  docs/pilot/owner_dashboard_manual_smoke_test_checklist.md"
echo "  Login as Owner -> open /dashboard -> confirm Monitoring Pilot RME & Lab"
echo ""

echo "--- CURRENT DIRECTORY ---"
pwd

echo ""
echo "--- GIT BRANCH ---"
git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "(not a git repo)"

echo ""
echo "--- GIT STATUS (short) ---"
git status --short 2>/dev/null || echo "(git unavailable)"

echo ""
echo "--- PHP VERSION ---"
if command -v php >/dev/null 2>&1; then
  php -v | head -1
else
  echo "php: NOT FOUND"
fi

echo ""
echo "--- COMPOSER ---"
if command -v composer >/dev/null 2>&1; then
  composer --version 2>/dev/null | head -1
else
  echo "composer: NOT FOUND"
fi

echo ""
echo "--- ARTISAN ---"
if [[ -f artisan ]]; then
  php artisan --version 2>/dev/null || echo "artisan present but failed to run"
else
  echo "artisan: NOT FOUND"
fi

echo ""
echo "--- ENV FILE ---"
if [[ -f .env ]]; then
  echo ".env: EXISTS"
else
  echo ".env: MISSING (required on VPS)"
fi

echo ""
echo "--- STORAGE LOGS WRITABLE ---"
if [[ -d storage/logs ]]; then
  if [[ -w storage/logs ]]; then
    echo "storage/logs: writable"
  else
    echo "storage/logs: NOT writable (fix permissions before deploy)"
  fi
else
  echo "storage/logs: directory missing"
fi

echo ""
echo "--- SUGGESTED NEXT STEPS (manual — not executed by this script) ---"
echo "1. Verify local tests in Ubuntu Terminal: php artisan test"
echo "2. Backup VPS database before deploy (see docs/pilot/vps_pilot_deployment_checklist.md)"
echo "3. After deploy, run safe seeders in order (manual — not executed here):"
echo "   php artisan db:seed --class=PermissionSeeder"
echo "   php artisan db:seed --class=RoleSeeder"
echo "   php artisan db:seed --class=RmeSmokeTestSeeder   # optional"
echo "4. Owner Dashboard smoke test:"
echo "   docs/pilot/owner_dashboard_manual_smoke_test_checklist.md"
echo ""
echo "========================================"
echo " PREFLIGHT COMPLETE"
echo "========================================"
