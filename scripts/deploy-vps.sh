#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/asia-dental-lab-v2"
BRANCH="feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report"
STAMP="$(date +%Y%m%d-%H%M%S)"

cd "$APP_DIR"

# ─── FIX-LOGIN-REDIRECT-RUNTIME-PERMISSIONS ─────────────────────────────────
# Resolve the PHP-FPM runtime user so every cache/runtime file is created owned
# by (and writable to) the web SAPI. Root-owned Laravel cache (Spatie permission
# FileStore, config/route/view cache, logs) is exactly what caused the post-login
# 500. Fail closed: never treat root as the runtime user.
RUNTIME_USER="${RUNTIME_USER:-}"
if [ -z "$RUNTIME_USER" ]; then
  RUNTIME_USER="$(grep -rhoE '^[[:space:]]*user[[:space:]]*=[[:space:]]*[A-Za-z0-9_.-]+' /etc/php/*/fpm/pool.d/ 2>/dev/null | grep -vE '^[[:space:]]*;' | head -1 | sed -E 's/.*=[[:space:]]*//' || true)"
fi
if [ -z "$RUNTIME_USER" ]; then
  RUNTIME_USER="$(ps -eo user,comm 2>/dev/null | awk '$2 ~ /php-fpm/ && $1 != "root" {print $1; exit}' || true)"
fi
[ -n "$RUNTIME_USER" ] || RUNTIME_USER="www-data"
if [ "$RUNTIME_USER" = "root" ]; then
  echo "FATAL: refusing to use 'root' as the Laravel runtime cache owner" >&2
  exit 2
fi
RUNTIME_GROUP="$(id -gn "$RUNTIME_USER" 2>/dev/null || echo "$RUNTIME_USER")"
echo "Runtime user: ${RUNTIME_USER}:${RUNTIME_GROUP}"

# Run a command as the PHP-FPM runtime user (direct when already that user).
as_runtime() {
  if [ "$(id -un)" = "$RUNTIME_USER" ]; then
    "$@"
  else
    runuser -u "$RUNTIME_USER" -- "$@"
  fi
}

# Ensure required Laravel runtime directories exist before ownership work.
mkdir -p \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

# Normalize ownership + safe modes (setgid dirs so new files inherit the group).
# NEVER use a world-writable (0777) mode.
normalize_runtime_ownership() {
  chown -R "${RUNTIME_USER}:${RUNTIME_GROUP}" storage bootstrap/cache
  find storage bootstrap/cache -type d -exec chmod 2775 {} \;
  find storage bootstrap/cache -type f -exec chmod 0664 {} \;
}

# Fail-closed writable gate: every runtime path must be writable by the runtime
# user or the deploy is NOT GO.
assert_runtime_writable() {
  local fail=0
  declare -A labels=(
    ["storage/framework/cache/data"]="CACHE WRITE"
    ["storage/framework/sessions"]="SESSION WRITE"
    ["storage/framework/views"]="VIEW CACHE WRITE"
    ["storage/logs"]="LOG WRITE"
    ["bootstrap/cache"]="BOOTSTRAP CACHE WRITE"
  )
  for p in storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; do
    if as_runtime test -w "$p"; then
      echo "${labels[$p]}: GO"
    else
      echo "${labels[$p]}: FAIL ($p not writable by ${RUNTIME_USER})"
      fail=1
    fi
  done
  return $fail
}
# ────────────────────────────────────────────────────────────────────────────

echo "== Current branch =="
git branch --show-current
git status -sb

echo "== Fetch origin =="
git fetch --no-tags origin "$BRANCH"

echo "== Backup DB =="
mkdir -p storage/app/backups/deploy

set -a
source .env
set +a

BACKUP="storage/app/backups/deploy/pre_auto_deploy_${STAMP}.sql"

PGPASSWORD="${DB_PASSWORD}" pg_dump \
  -h "${DB_HOST:-127.0.0.1}" \
  -p "${DB_PORT:-5432}" \
  -U "${DB_USERNAME}" \
  -d "${DB_DATABASE}" \
  > "$BACKUP"

test -s "$BACKUP"

echo "== Pull approved branch =="
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"

echo "== Composer install =="
composer install --no-dev --optimize-autoloader

echo "== NPM build =="
npm ci
npm run build

echo "== Laravel migrate =="
php artisan migrate --force

echo "== NSF-10 backup verify =="
php artisan foundation:backup-verify --path="$BACKUP"

echo "== Clear stale route/config cache before route-dependent gates (ENT-8) =="
# Route-dependent governance gates (foundation:developer-console-check,
# foundation:health-check) inspect the live route table. A stale route cache
# from a prior deploy can hide newly added routes during the gate phase (the
# ENT-7 deploy hiccup). Clearing here — before the gates, well before the
# cache-rebuild block below — makes those gates see the freshly deployed routes.
php artisan route:clear
php artisan config:clear

echo "== Foundation deploy governance gates =="
php artisan architecture:foundation-roadmap-check
php artisan data-quality:dq1-audit --fail-on=error
php artisan inventory:batch-governance-audit --fail-on=error
php artisan inventory:source-document-batch-audit --fail-on=error
php artisan inventory:ambiguous-batch-review-pack
php artisan architecture:dmo-governance-check
php artisan architecture:nsf-governance-check --include-observability
php artisan foundation:feature-flags
php artisan foundation:cache-governance-check
php artisan foundation:cache-governance-check --json > storage/release-evidence/latest/cache-governance-check.json || true
php artisan foundation:queue-governance-check
php artisan foundation:queue-governance-check --json > storage/release-evidence/latest/queue-governance-check.json || true
php artisan foundation:queue-retry-failed-job-check
php artisan foundation:idempotency-outbox-check
php artisan foundation:idempotency-outbox-check --json > storage/release-evidence/latest/idempotency-outbox-check.json || true
php artisan foundation:developer-console-check
php artisan foundation:developer-console-check --json > storage/release-evidence/latest/developer-console-check.json || true
php artisan foundation:health-check
php artisan foundation:health-check --json > storage/release-evidence/latest/health-check-check.json || true
php artisan foundation:security-compliance-check
php artisan foundation:security-compliance-check --json > storage/release-evidence/latest/security-compliance-check.json || true
php artisan foundation:cicd-enterprise-gate-check
php artisan foundation:cicd-enterprise-gate-check --json > storage/release-evidence/latest/cicd-enterprise-gate-check.json || true
php artisan foundation:deployment-rollback-check
php artisan foundation:deployment-rollback-check --json > storage/release-evidence/latest/deployment-rollback-check.json || true
php artisan foundation:backup-dr-check
php artisan foundation:backup-dr-check --json > storage/release-evidence/latest/backup-dr-check.json || true
php artisan foundation:load-test-baseline-check
php artisan foundation:load-test-baseline-check --json > storage/release-evidence/latest/load-test-baseline-check.json || true
php artisan foundation:load-test-scale-projection-check
php artisan foundation:load-test-scale-projection-check --json > storage/release-evidence/latest/load-test-scale-projection-check.json || true
php artisan foundation:enterprise-documentation-check
php artisan foundation:enterprise-documentation-check --json > storage/release-evidence/latest/enterprise-documentation-check.json || true
php artisan foundation:enterprise-closure-check
php artisan foundation:enterprise-closure-check --json > storage/release-evidence/latest/enterprise-closure-check.json || true
php artisan foundation:ent-1-4-audit-check
php artisan foundation:ent-1-4-audit-check --json > storage/release-evidence/latest/ent-1-4-audit-check.json || true
php artisan foundation:queue-worker-runtime-check
php artisan foundation:queue-worker-runtime-check --json > storage/release-evidence/latest/queue-worker-runtime-check.json || true
php artisan foundation:runtime-hardening-check
php artisan foundation:runtime-hardening-check --json > storage/release-evidence/latest/runtime-hardening-check.json || true
php artisan foundation:devflow-check
php artisan foundation:devflow-check --json > storage/release-evidence/latest/devflow-check.json || true
php artisan foundation:shared-service-audit --json > storage/release-evidence/latest/shared-service-audit.json || true
php artisan foundation:idempotency-audit
php artisan foundation:idempotency-audit --json > storage/release-evidence/latest/idempotency-audit.json || true
php artisan foundation:outbox-audit
php artisan foundation:outbox-audit --json > storage/release-evidence/latest/outbox-audit.json || true
php artisan foundation:db-performance-check
php artisan foundation:db-performance-check --include-db-stats
php artisan foundation:db-performance-check --include-db-stats --json > storage/release-evidence/latest/db-performance-check.json || true
php artisan foundation:postgres-runtime-check
php artisan foundation:postgres-runtime-check --include-db-stats
php artisan foundation:postgres-runtime-check --include-db-stats --include-pgbouncer-probe || true
php artisan foundation:postgres-runtime-check --include-db-stats --json > storage/release-evidence/latest/postgres-runtime-check.json || true
php artisan foundation:reporting-summary-check
php artisan foundation:reporting-summary-check --include-db-inventory
php artisan foundation:reporting-summary-check --include-db-inventory --json > storage/release-evidence/latest/reporting-summary-check.json || true
php artisan foundation:reporting-summary-refresh --dry-run
php artisan foundation:reporting-summary-refresh --dry-run --json > storage/release-evidence/latest/reporting-summary-refresh-dry-run.json || true
php artisan foundation:release-safety-check
php artisan release:automated-smoke
php artisan architecture:foundation-governance-summary

echo "== NSF-10 release evidence capture/check (vps profile) =="
php artisan release:evidence-capture --profile=vps --base-url=http://127.0.0.1 --backup-path="$BACKUP"
php artisan release:evidence-check --profile=vps
php artisan foundation:release-safety-check --profile=vps

echo "== Normalize runtime ownership before rebuilding cache as runtime user =="
# The governance gates above ran as the deploy user (root) and wrote root-owned
# cache/log files. Normalize FIRST so the runtime-user cache rebuild can clear
# and overwrite them without a permission-denied abort.
normalize_runtime_ownership

echo "== Laravel cache rebuild (as PHP-FPM runtime user) =="
as_runtime php artisan optimize:clear
as_runtime php artisan config:cache
as_runtime php artisan route:cache
as_runtime php artisan view:cache
as_runtime php artisan event:cache

echo "== Functional runtime permission + authenticated-authorization smoke =="
# Exercises the exact failing path as the runtime user: Illuminate cache write +
# Spatie permission cache reset/load + role-aware landing authorization
# (Admin Lab must not land on / access the forbidden dashboard; Super Admin can).
# NO_GO here (500/403 contradiction) aborts the deploy via set -e.
as_runtime php artisan deploy:auth-landing-smoke --strict

echo "== Re-normalize ownership + mandatory writable gates =="
normalize_runtime_ownership
if ! assert_runtime_writable; then
  echo "FATAL: Laravel runtime paths are not writable by ${RUNTIME_USER} — NOT GO" >&2
  exit 1
fi

echo "== Restart services =="
systemctl restart php8.3-fpm
nginx -t
systemctl reload nginx

echo "== Smoke check =="
as_runtime php artisan about
as_runtime php artisan release:automated-smoke --base-url=http://127.0.0.1

echo "DEPLOY OK: ${STAMP}"
