#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/asia-dental-lab-v2"
BRANCH="feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report"
STAMP="$(date +%Y%m%d-%H%M%S)"

cd "$APP_DIR"

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

echo "== Laravel cache rebuild =="
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "== Permissions =="
chown -R www-data:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;

echo "== Restart services =="
systemctl restart php8.3-fpm
nginx -t
systemctl reload nginx

echo "== Smoke check =="
php artisan about
php artisan route:list | grep -E "dashboard|rme|inventory" | head -30 || true
php artisan release:automated-smoke --base-url=http://127.0.0.1

echo "DEPLOY OK: ${STAMP}"
