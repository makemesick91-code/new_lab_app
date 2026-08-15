#!/usr/bin/env bash
#
# ENT-11 — Deployment & Rollback Automation: rehearsable VPS rollback.
#
# Rolls the application back to a prior GO tag or commit safely:
#   record current ref -> backup DB -> checkout target ref -> rebuild deps ->
#   clear caches -> re-verify foundation gates -> rebuild caches ->
#   reset permissions -> restart runtime -> smoke.
#
# SAFETY (mirrors scripts/deploy-vps.sh):
#   - Fail-fast: any error stops the rollback (set -euo pipefail).
#   - A verified DB backup is taken BEFORE the code is switched; backup failure
#     stops the rollback.
#   - Code rollback keeps the current additive (backward-compatible) schema and
#     NEVER runs a destructive database command or a schema-destroying migration.
#     A data rollback is a separate, explicit step using
#     scripts/restore_postgres.sh <backup_file> — never automatic here.
#   - ENT-8 cache-order hardening is preserved: route/config cache is cleared
#     before the route-dependent governance gates, then rebuilt after.
#
# Usage:
#   bash scripts/rollback-vps.sh <target-tag-or-commit>
# Example:
#   bash scripts/rollback-vps.sh ent-10-cicd-enterprise-gate-go
set -euo pipefail

APP_DIR="/var/www/asia-dental-lab-v2"
STAMP="$(date +%Y%m%d-%H%M%S)"
TARGET_REF="${1:-}"

if [ -z "$TARGET_REF" ]; then
  echo "Usage: bash scripts/rollback-vps.sh <target-tag-or-commit>"
  echo "Refusing to roll back without an explicit target ref."
  exit 1
fi

cd "$APP_DIR"

# ─── INFRA-SEC-RUNTIME-1 explicit runtime identity resolution ───────────────
# Resolved BEFORE the checkout, on purpose. The rollback target may predate this
# sprint and therefore may not contain deploy/runtime-identity.conf at all — but
# rolling the CODE back must never roll the RUNTIME IDENTITY back onto the
# shared www-data account, because that would silently re-open co-tenant read of
# this application's secrets and private clinical storage. The identity and its
# verifier are captured here and reused after the checkout.
RUNTIME_IDENTITY_FILE="${RUNTIME_IDENTITY_FILE:-${APP_DIR}/deploy/runtime-identity.conf}"
if [ ! -r "$RUNTIME_IDENTITY_FILE" ]; then
  echo "FATAL: runtime identity authority unreadable: ${RUNTIME_IDENTITY_FILE}" >&2
  echo "       Refusing to guess the runtime user during a rollback." >&2
  exit 2
fi
# shellcheck disable=SC1090
. "$RUNTIME_IDENTITY_FILE"
RUNTIME_USER="${DMS_RUNTIME_USER:?identity authority must declare DMS_RUNTIME_USER}"
RUNTIME_GROUP="${DMS_RUNTIME_GROUP:?identity authority must declare DMS_RUNTIME_GROUP}"
for forbidden in ${DMS_FORBIDDEN_RUNTIME_USERS:-root www-data}; do
  if [ "$RUNTIME_USER" = "$forbidden" ]; then
    echo "FATAL: declared runtime user '${RUNTIME_USER}' is forbidden — NOT GO" >&2
    exit 2
  fi
done
getent passwd "$RUNTIME_USER" >/dev/null 2>&1 || {
  echo "FATAL: runtime account '${RUNTIME_USER}' does not exist on this host — NOT GO" >&2
  exit 2
}
echo "Runtime user: ${RUNTIME_USER}:${RUNTIME_GROUP} (explicit, from ${RUNTIME_IDENTITY_FILE})"

# Stage the isolation verifier + identity authority outside the working tree so
# the post-rollback gate still runs even when the target ref does not ship them.
RUNTIME_GUARD_DIR="$(mktemp -d -t infra-sec-runtime-1-XXXXXX)"
chmod 0700 "$RUNTIME_GUARD_DIR"
mkdir -p "${RUNTIME_GUARD_DIR}/scripts" "${RUNTIME_GUARD_DIR}/deploy"
cp scripts/verify-runtime-isolation.sh "${RUNTIME_GUARD_DIR}/scripts/"
cp "$RUNTIME_IDENTITY_FILE" "${RUNTIME_GUARD_DIR}/deploy/runtime-identity.conf"
cleanup_runtime_guard() { rm -rf "$RUNTIME_GUARD_DIR"; }
trap cleanup_runtime_guard EXIT

echo "== Record current ref (pre-rollback state) =="
CURRENT_HEAD="$(git rev-parse HEAD)"
CURRENT_REF_DESC="$(git describe --tags --always 2>/dev/null || echo "$CURRENT_HEAD")"
echo "Current HEAD: ${CURRENT_HEAD}"
echo "Current ref:  ${CURRENT_REF_DESC}"
echo "Target ref:   ${TARGET_REF}"
git status -sb

echo "== Backup DB before rollback =="
mkdir -p storage/app/backups/rollback

set -a
source .env
set +a

BACKUP="storage/app/backups/rollback/pre_rollback_${STAMP}.sql"

PGPASSWORD="${DB_PASSWORD}" pg_dump \
  -h "${DB_HOST:-127.0.0.1}" \
  -p "${DB_PORT:-5432}" \
  -U "${DB_USERNAME}" \
  -d "${DB_DATABASE}" \
  > "$BACKUP"

test -s "$BACKUP"

# INFRA-SEC-ENV-1: the dump holds the entire clinical database and pg_dump would
# otherwise leave it world-readable under the default umask.
chmod 0640 "$BACKUP"

echo "Backup written: ${BACKUP}"

echo "== Fetch tags/refs =="
git fetch --tags --prune origin

echo "== Checkout target ref =="
git checkout "$TARGET_REF"

echo "== Composer install =="
composer install --no-dev --optimize-autoloader

echo "== NPM build =="
npm ci
npm run build

echo "== NSF-10 backup verify =="
php artisan foundation:backup-verify --path="$BACKUP"

echo "== Clear stale route/config cache before route-dependent gates (ENT-8) =="
# Route-dependent governance gates (foundation:developer-console-check,
# foundation:health-check) inspect the live route table. Clearing here — before
# the gates, well before the cache-rebuild block below — makes those gates see
# the routes of the rolled-back code.
php artisan route:clear
php artisan config:clear

echo "== Foundation rollback governance gates (re-verify ENT-5..11) =="
php artisan architecture:foundation-roadmap-check
php artisan foundation:queue-retry-failed-job-check
php artisan foundation:idempotency-outbox-check
php artisan foundation:developer-console-check
php artisan foundation:health-check
php artisan foundation:security-compliance-check
php artisan foundation:cicd-enterprise-gate-check
php artisan foundation:deployment-rollback-check
php artisan foundation:release-safety-check
php artisan architecture:foundation-governance-summary

echo "== Laravel cache rebuild =="
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "== Permissions =="
# Ownership follows the dedicated runtime identity resolved before the checkout,
# never a hardcoded shared account.
chown -R "${RUNTIME_USER}:${RUNTIME_GROUP}" storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 2775 {} \;
find storage bootstrap/cache -type f -exec chmod 0664 {} \;

# ─── INFRA-SEC-ENV-1 ────────────────────────────────────────────────────────
# Rolling back to an older ref must never restore a world-readable secret file.
# Re-assert the invariant here too, fail closed. The group is the dedicated
# runtime group — rolling back the code must not hand secret read back to the
# shared account.
echo "== Harden + verify secret file permissions (INFRA-SEC-ENV-1) =="
bash scripts/harden-secret-permissions.sh apply --app-dir "$APP_DIR" --owner root --group "$RUNTIME_GROUP"
if ! bash scripts/harden-secret-permissions.sh verify --app-dir "$APP_DIR" --owner root --group "$RUNTIME_GROUP"; then
  echo "FATAL: secret file permissions are unsafe after rollback — NOT GO" >&2
  exit 1
fi

# ─── INFRA-SEC-RUNTIME-1 ────────────────────────────────────────────────────
# A rollback must not silently drop co-tenant isolation. The verifier was staged
# before the checkout so this gate runs even when the target ref predates it.
echo "== Verify runtime identity isolation (INFRA-SEC-RUNTIME-1) =="
if ! bash "${RUNTIME_GUARD_DIR}/scripts/verify-runtime-isolation.sh" \
      --app-dir "$APP_DIR" \
      --identity-file "${RUNTIME_GUARD_DIR}/deploy/runtime-identity.conf" \
      --require-host; then
  echo "FATAL: runtime identity isolation is broken after rollback — NOT GO" >&2
  exit 1
fi

echo "== Restart services =="
systemctl restart php8.3-fpm
nginx -t
systemctl reload nginx

echo "== Smoke check =="
php artisan about
php artisan release:automated-smoke --base-url=http://127.0.0.1

echo "ROLLBACK OK: rolled back to ${TARGET_REF} (from ${CURRENT_REF_DESC}) at ${STAMP}"
echo "Pre-rollback backup: ${BACKUP}"
echo "To restore data (only if required, and explicitly): bash scripts/restore_postgres.sh <backup_file>"
