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

PGPASSWORD="${DB_PASSWORD}" pg_dump \
  -h "${DB_HOST:-127.0.0.1}" \
  -p "${DB_PORT:-5432}" \
  -U "${DB_USERNAME}" \
  -d "${DB_DATABASE}" \
  > "storage/app/backups/deploy/pre_auto_deploy_${STAMP}.sql"

test -s "storage/app/backups/deploy/pre_auto_deploy_${STAMP}.sql"

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

echo "== Foundation deploy governance gates =="
php artisan data-quality:dq1-audit --fail-on=error
php artisan inventory:batch-governance-audit --fail-on=error
php artisan inventory:source-document-batch-audit --fail-on=error
php artisan inventory:ambiguous-batch-review-pack
php artisan architecture:dmo-governance-check
php artisan architecture:nsf-governance-check --include-observability
php artisan architecture:foundation-governance-summary

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

echo "DEPLOY OK: ${STAMP}"
