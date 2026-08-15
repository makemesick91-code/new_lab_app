#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/asia-dental-lab-v2"
# Inside the immutable snapshot the application root is whatever the trusted
# bootstrap resolved and exported. Outside it, the hardcoded production path is
# the only accepted value — an inherited environment variable must never be able
# to point a deployment at a different tree.
if [ -n "${DMS_DEPLOY_SNAPSHOT_DIR:-}" ] && [ -n "${DMS_DEPLOY_APP_DIR:-}" ]; then
  APP_DIR="$DMS_DEPLOY_APP_DIR"
fi
BRANCH="feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report"
STAMP="$(date +%Y%m%d-%H%M%S)"

cd "$APP_DIR"

# ─── DEPLOY-HARDEN-1 immutable execution bootstrap ──────────────────────────
# This script rewrites its own working-tree file further down (the checkout that
# advances the deployed code). Interpreting a file while rewriting it is
# undefined behaviour, so the deploy must never be executed straight out of the
# repository. Hand over to the trusted bootstrap, which takes the host deploy
# lock, pins the exact target commit, exports an immutable snapshot of the
# deployment payload from the git object, and re-enters this same script from
# that snapshot. Everything below then runs from bytes no `git checkout` can
# touch.
#
# Fail closed: without the bootstrap there is no safe way to run this script, so
# a tree that does not carry it aborts rather than falling back to the old
# self-modifying path.
if [ -z "${DMS_DEPLOY_SNAPSHOT_DIR:-}" ]; then
  # Prefer the bootstrap shipped beside this script: it keeps the pre-mutation
  # execution closure self-contained, so a trusted copy of scripts/ taken from a
  # release object bootstraps itself without depending on the tree it is about
  # to replace. Fall back to the application checkout for a normal in-tree run.
  DEPLOY_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
  IMMUTABLE_EXEC="${DEPLOY_SELF_DIR}/deploy-immutable-exec.sh"
  [ -r "$IMMUTABLE_EXEC" ] || IMMUTABLE_EXEC="${APP_DIR}/scripts/deploy-immutable-exec.sh"
  if [ ! -r "$IMMUTABLE_EXEC" ]; then
    echo "FATAL: immutable deployment bootstrap missing: ${IMMUTABLE_EXEC}" >&2
    echo "       Refusing to deploy from a mutable working-tree script (DEPLOY-HARDEN-1)." >&2
    exit 2
  fi
  exec bash "$IMMUTABLE_EXEC" \
    --role deploy \
    --app-dir "$APP_DIR" \
    --branch "$BRANCH" \
    --overlay "${APP_DIR}/deploy/runtime-identity.conf:deploy/runtime-identity.conf" \
    -- scripts/deploy-vps.sh
fi

# From here on we are the snapshot copy. Assert it rather than assume it: a
# forged environment variable must not be able to talk this script into running
# the vulnerable in-tree path.
case "${BASH_SOURCE[0]}" in
  "${DMS_DEPLOY_SNAPSHOT_DIR}"/*) ;;
  *)
    echo "FATAL: deploy program is not running from the immutable snapshot (${BASH_SOURCE[0]})" >&2
    exit 2
    ;;
esac
TARGET_SHA="${DMS_DEPLOY_TARGET_SHA:?immutable bootstrap must pin a target commit}"
DEPLOY_TOOLS_DIR="$DMS_DEPLOY_SNAPSHOT_DIR"
echo "Deploy run id:     ${DMS_DEPLOY_RUN_ID:-unknown}"
echo "Execution source:  ${BASH_SOURCE[0]} (immutable snapshot)"
echo "Pinned target SHA: ${TARGET_SHA}"
# ────────────────────────────────────────────────────────────────────────────

# ─── INFRA-SEC-RUNTIME-1 explicit runtime identity resolution ───────────────
# The runtime identity is read from ONE explicit, application-specific authority.
#
# It used to be discovered by grepping every PHP-FPM pool on the host and taking
# the FIRST `user =` line. That was only ever "correct" because every pool on
# this shared VPS ran as www-data; it silently resolved whichever pool the
# directory walk returned first, so the moment DaengtisiaMS got a dedicated pool
# the deploy could have adopted the CO-TENANT's identity and chowned this
# application's runtime to it. The process-table probe and the `www-data`
# default had the same defect: they guess, and a guess that lands on a shared
# account is exactly the vulnerability INFRA-SEC-RUNTIME-1 closes.
#
# Fail closed. No pool scan, no process scan, no default. A missing authority, a
# missing OS account, or a forbidden (privileged / co-tenant) identity aborts
# the deploy.
RUNTIME_IDENTITY_FILE="${RUNTIME_IDENTITY_FILE:-${APP_DIR}/deploy/runtime-identity.conf}"
if [ ! -r "$RUNTIME_IDENTITY_FILE" ]; then
  echo "FATAL: runtime identity authority unreadable: ${RUNTIME_IDENTITY_FILE}" >&2
  echo "       Refusing to guess the runtime user. Run scripts/provision-runtime-identity.sh first." >&2
  exit 2
fi
# shellcheck disable=SC1090
. "$RUNTIME_IDENTITY_FILE"

RUNTIME_USER="${DMS_RUNTIME_USER:-}"
RUNTIME_GROUP="${DMS_RUNTIME_GROUP:-}"
if [ -z "$RUNTIME_USER" ] || [ -z "$RUNTIME_GROUP" ]; then
  echo "FATAL: identity authority does not declare DMS_RUNTIME_USER/DMS_RUNTIME_GROUP — NOT GO" >&2
  exit 2
fi
for forbidden in ${DMS_FORBIDDEN_RUNTIME_USERS:-root www-data}; do
  if [ "$RUNTIME_USER" = "$forbidden" ]; then
    echo "FATAL: declared runtime user '${RUNTIME_USER}' is forbidden (privileged, or shared with a co-tenant application) — NOT GO" >&2
    exit 2
  fi
done
if ! getent passwd "$RUNTIME_USER" >/dev/null 2>&1; then
  echo "FATAL: runtime account '${RUNTIME_USER}' does not exist on this host — NOT GO" >&2
  echo "       Provision it with: bash scripts/provision-runtime-identity.sh --apply" >&2
  exit 2
fi
if [ "$(id -gn "$RUNTIME_USER")" != "$RUNTIME_GROUP" ]; then
  echo "FATAL: '${RUNTIME_USER}' primary group is '$(id -gn "$RUNTIME_USER")', expected '${RUNTIME_GROUP}' — NOT GO" >&2
  exit 2
fi
echo "Runtime user: ${RUNTIME_USER}:${RUNTIME_GROUP} (explicit, from ${RUNTIME_IDENTITY_FILE})"

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
  # Exactly one owner: the explicitly declared dedicated runtime identity.
  # There is deliberately no shared-account branch here any more — a deploy that
  # cannot resolve a dedicated identity has already aborted above.
  # Only the runtime-writable paths are touched; the application source tree
  # stays deploy/root owned so the runtime can never rewrite its own code.
  chown -R "${RUNTIME_USER}:${RUNTIME_GROUP}" storage bootstrap/cache
  find storage bootstrap/cache -type d -exec chmod 2775 {} \;
  find storage bootstrap/cache -type f -exec chmod 0664 {} \;
  restrict_private_paths
}

# INFRA-SEC-RUNTIME-1: the normalization above deliberately makes the whole
# storage tree group-writable and world-readable (2775/0664) so the runtime can
# work — but storage/app/private holds clinical evidence and must NOT inherit
# that. Re-strip world access for every declared private path.
#
# This is called from INSIDE normalize_runtime_ownership, not beside it, because
# the two must never drift apart: a deploy that normalized ownership without
# re-restricting would silently hand the co-tenant uid read access to lab
# workflow evidence, patient documents and the legacy import staging area — the
# exact exposure this sprint closes — and would then fail the isolation gate
# below with a confusing message instead of never happening.
restrict_private_paths() {
  local rel p
  for rel in ${DMS_PRIVATE_PATHS:-}; do
    p="${APP_DIR}/${rel}"
    [ -d "$p" ] || continue
    chmod -R o-rwx "$p"
  done
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

# ─── INFRA-SEC-ENV-1 secret-file permission hardening ───────────────────────
# The production environment file was found world-readable (0644) on a SHARED
# host. Harden it FIRST — before this script sources it and long before the
# runtime user rebuilds the config cache from it — so no phase of the deploy
# ever runs against a world-readable secret. Fail closed: an unsafe secret file
# aborts the deploy (the helper exits non-zero and `set -e` stops us here).
echo "== Harden secret file permissions (INFRA-SEC-ENV-1) =="
bash "${DEPLOY_TOOLS_DIR}/scripts/harden-secret-permissions.sh" apply --app-dir "$APP_DIR" --owner root --group "$RUNTIME_GROUP"
# ────────────────────────────────────────────────────────────────────────────

echo "== Current branch =="
git branch --show-current
git status -sb

# DEPLOY-HARDEN-1: the remote was already fetched and the target pinned by the
# bootstrap, before the lock-protected critical section began. Nothing is
# re-resolved here, so a branch that moves mid-deploy cannot retarget this run.
echo "== Pinned deployment target =="
echo "Target SHA: ${TARGET_SHA}"

# A modified tracked file means the checkout below would either fail or silently
# deploy something other than the pinned commit. Fail closed and let a human
# decide; never discard work with a hard reset.
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "FATAL: tracked files are modified in the production checkout — NOT GO" >&2
  git status --porcelain --untracked-files=no >&2
  exit 1
fi

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

# INFRA-SEC-ENV-1: `pg_dump > file` creates the dump under the deploy user's
# umask (022 => world-readable), and this dump contains the ENTIRE clinical
# database. Close that window immediately, not at the end of the deploy.
chmod 0640 "$BACKUP"

echo "== Advance checkout to the pinned target =="
# Fast-forward the approved branch to the EXACT pinned commit instead of pulling
# whatever the remote tip happens to be right now. This is the mutation that
# used to rewrite this very script mid-run; it is safe because the interpreter
# is reading the immutable snapshot, not the file about to be replaced.
git checkout "$BRANCH"
git merge --ff-only "$TARGET_SHA"

DEPLOYED_SHA="$(git rev-parse --verify HEAD)"
if [ "$DEPLOYED_SHA" != "$TARGET_SHA" ]; then
  echo "FATAL: checkout is at ${DEPLOYED_SHA}, expected the pinned target ${TARGET_SHA} — NOT GO" >&2
  exit 1
fi
echo "HEAD verified at pinned target: ${DEPLOYED_SHA}"

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
php artisan foundation:deployment-entrypoint-check
php artisan foundation:deployment-entrypoint-check --json > storage/release-evidence/latest/deployment-entrypoint-check.json || true
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

# ─── INFRA-SEC-ENV-1 mandatory fail-closed secret-permission gate ────────────
# Re-assert AFTER every file-touching phase (composer/npm/migrate/evidence/cache
# rebuild/ownership normalization) so nothing this deploy did can leave a secret
# file world-readable. A deploy that cannot prove secure secret permissions is
# NOT GO — including when the invariant was silently regressed out-of-band since
# the previous release.
#
# DEPLOY-HARDEN-1: invoked from the immutable snapshot, not from the tree this
# deploy has just rewritten. Re-reading a helper out of the freshly checked-out
# working tree is the same self-modification defect one indirection removed.
echo "== Verify secret file permissions (INFRA-SEC-ENV-1) =="
bash "${DEPLOY_TOOLS_DIR}/scripts/harden-secret-permissions.sh" apply --app-dir "$APP_DIR" --owner root --group "$RUNTIME_GROUP"
if ! bash "${DEPLOY_TOOLS_DIR}/scripts/harden-secret-permissions.sh" verify --app-dir "$APP_DIR" --owner root --group "$RUNTIME_GROUP"; then
  echo "FATAL: secret file permissions are unsafe — NOT GO" >&2
  exit 1
fi

# ─── INFRA-SEC-RUNTIME-1 mandatory fail-closed runtime isolation gate ────────
# Proves, on the real host, that DaengtisiaMS still runs under its own dedicated
# Unix identity and that the co-tenant application cannot read this application's
# secrets or private clinical storage. --require-host means a host fact this
# gate could not actually inspect is a FAIL, never a silent pass. A deploy that
# cannot prove runtime isolation is NOT GO — including when the invariant was
# regressed out-of-band since the previous release.
echo "== Verify runtime identity isolation (INFRA-SEC-RUNTIME-1) =="
if ! bash "${DEPLOY_TOOLS_DIR}/scripts/verify-runtime-isolation.sh" \
      --app-dir "$APP_DIR" \
      --identity-file "${DEPLOY_TOOLS_DIR}/deploy/runtime-identity.conf" \
      --require-host; then
  echo "FATAL: runtime identity isolation is broken — NOT GO" >&2
  exit 1
fi

echo "== Restart services =="
systemctl restart php8.3-fpm
nginx -t
systemctl reload nginx

echo "== Smoke check =="
as_runtime php artisan about
as_runtime php artisan release:automated-smoke --base-url=http://127.0.0.1

# ─── DEPLOY-HARDEN-1 fail-closed target verification ────────────────────────
# Last gate before the success marker: the deployed checkout must still be
# exactly the commit this run pinned. Anything else — a concurrent mutation, a
# helper that moved HEAD, an operator switching branches mid-deploy — is NOT GO.
# `DEPLOY OK` is only ever emitted after every mandatory security, runtime and
# health gate above has already passed.
FINAL_HEAD="$(git rev-parse --verify HEAD)"
if [ "$FINAL_HEAD" != "$TARGET_SHA" ]; then
  echo "FATAL: final HEAD ${FINAL_HEAD} does not match the pinned target ${TARGET_SHA} — NOT GO" >&2
  exit 1
fi
echo "DEPLOY_HEAD_TARGET_MATCH=YES (${FINAL_HEAD})"

echo "DEPLOY OK: ${STAMP}"
