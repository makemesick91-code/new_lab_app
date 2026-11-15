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

# ─── Rule 229: exact approved release SHA ───────────────────────────────────
# A deploy names the release and the exact commit the owner approved. Both
# stages (the in-tree hand-over and the snapshot copy) receive them as
# arguments. Without them nothing is handed over, locked, backed up or checked
# out: an unapproved target never enters the mutation path.
#   bash scripts/deploy-vps.sh --release-id <release-id> --approved-sha <40-hex>
RELEASE_ID=""
APPROVED_SHA=""
while [ "$#" -gt 0 ]; do
  case "$1" in
    --release-id)   RELEASE_ID="${2:-}"; shift 2 || shift ;;
    --approved-sha) APPROVED_SHA="${2:-}"; shift 2 || shift ;;
    *) echo "FATAL: unknown argument: $1" >&2; echo "DEPLOYMENT=DENIED"; exit 77 ;;
  esac
done
if [ -z "$RELEASE_ID" ] || [[ ! "$APPROVED_SHA" =~ ^[0-9a-f]{40}$ ]]; then
  echo "FATAL: a deploy needs --release-id <release-id> and --approved-sha <full 40-character commit SHA> (rule 229) — nothing was backed up, checked out or migrated" >&2
  echo "DEPLOYMENT=DENIED"
  exit 77
fi

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
  # The bootstrap verifies the merged release approval inside the deploy lock
  # and pins exactly the approved commit (never the branch tip).
  exec bash "$IMMUTABLE_EXEC" \
    --role deploy \
    --app-dir "$APP_DIR" \
    --branch "$BRANCH" \
    --release-id "$RELEASE_ID" \
    --approved-sha "$APPROVED_SHA" \
    --overlay "${APP_DIR}/deploy/runtime-identity.conf:deploy/runtime-identity.conf" \
    -- scripts/deploy-vps.sh --release-id "$RELEASE_ID" --approved-sha "$APPROVED_SHA"
fi

# From here on we are the snapshot copy. Assert it rather than assume it: the
# DMS_DEPLOY_* values below are trusted only when this really is the bootstrap's
# snapshot, inside its root-only lock root, with the deploy lock HELD by the
# bootstrap. Otherwise someone exported those variables by hand (independent
# review, 8D PR2, finding 4) and nothing here may run. Root on the host can
# still forge anything; this stops a mistaken or scripted direct invocation.
case "${BASH_SOURCE[0]}" in
  "${DMS_DEPLOY_SNAPSHOT_DIR}"/*) ;;
  *)
    echo "FATAL: deploy program is not running from the immutable snapshot (${BASH_SOURCE[0]})" >&2
    exit 2
    ;;
esac
# Rule 231: the lock is THE release lock, shared with rollback, in the one lock
# root the bootstrap picks on this host (/run unless the host has no writable
# /run) — a second, differently-rooted lock could let two runs overlap.
DEPLOY_LOCK_ROOT="/run/daengtisiams-deploy"
if [ ! -d /run ] || [ ! -w /run ]; then
  DEPLOY_LOCK_ROOT="/var/lock/daengtisiams-deploy"
fi
if [ "$(dirname -- "${DMS_DEPLOY_LOCK_FILE:-/nonexistent/release.lock}")" != "$DEPLOY_LOCK_ROOT" ]; then
  echo "FATAL: the deploy lock '${DMS_DEPLOY_LOCK_FILE:-}' is not in the trusted lock root ${DEPLOY_LOCK_ROOT} — not the bootstrap's snapshot — NOT GO" >&2
  echo "DEPLOYMENT=DENIED"
  exit 77
fi
if [ "$DMS_DEPLOY_LOCK_FILE" != "${DEPLOY_LOCK_ROOT}/release.lock" ] \
   || [ "$DMS_DEPLOY_SNAPSHOT_DIR" != "${DEPLOY_LOCK_ROOT}/deploy-${DMS_DEPLOY_RUN_ID:-}" ] \
   || [ -L "$DMS_DEPLOY_SNAPSHOT_DIR" ] \
   || [ "$(stat -c '%u %a' "$DMS_DEPLOY_SNAPSHOT_DIR" 2>/dev/null)" != "$(id -u) 700" ]; then
  echo "FATAL: ${DMS_DEPLOY_SNAPSHOT_DIR} is not this run's trusted snapshot — NOT GO" >&2
  echo "DEPLOYMENT=DENIED"
  exit 77
fi
# The bootstrap holds the lock for the whole run; a lock we can take ourselves
# means no bootstrap is holding it.
if flock -n "$DMS_DEPLOY_LOCK_FILE" true 2>/dev/null; then
  echo "FATAL: not running under the deploy lock (the release lock) — not launched by the bootstrap — NOT GO" >&2
  echo "DEPLOYMENT=DENIED"
  exit 77
fi
TARGET_SHA="${DMS_DEPLOY_TARGET_SHA:?immutable bootstrap must pin a target commit}"
DEPLOY_TOOLS_DIR="$DMS_DEPLOY_SNAPSHOT_DIR"
# Rule 229, re-asserted before the first mutation: the commit the bootstrap
# pinned, the commit its approval check approved and the commit this run was
# asked to deploy must be one and the same. No substitution.
if [ "$TARGET_SHA" != "$APPROVED_SHA" ] \
   || [ "${DMS_DEPLOY_APPROVED_SHA:-}" != "$APPROVED_SHA" ] \
   || [ "${DMS_DEPLOY_RELEASE_ID:-}" != "$RELEASE_ID" ]; then
  echo "FATAL: pinned target ${TARGET_SHA} is not the approved ${APPROVED_SHA} of release ${RELEASE_ID} — nothing was backed up, checked out or migrated — NOT GO" >&2
  echo "DEPLOYMENT=DENIED"
  exit 77
fi
echo "Deploy run id:     ${DMS_DEPLOY_RUN_ID:-unknown}"
echo "Execution source:  ${BASH_SOURCE[0]} (immutable snapshot)"
echo "Approved release:  ${RELEASE_ID}"
echo "Pinned target SHA: ${TARGET_SHA} (approved)"
# ────────────────────────────────────────────────────────────────────────────

# ─── DMS-POST-MIGRATION-DEV-INFRA-ALIGNMENT-1 production host preflight ─────
# Before the FIRST mutation (the secret file, the backup, the checkout): prove
# this is the production host from deploy/production-host.conf, refuse the
# frozen Hostinger host outright, require root, and require every tool the
# deploy calls later. `npm ci` runs after the checkout and `composer install`,
# so a missing Node would otherwise leave new code live beside stale assets.
# shellcheck source=lib/production-host-preflight.sh
source "${DEPLOY_TOOLS_DIR}/scripts/lib/production-host-preflight.sh"
if ! dms_production_host_preflight "${DEPLOY_TOOLS_DIR}/deploy/production-host.conf" \
     "$(id -u)" "$(hostname)" "$(hostname -I 2>/dev/null || true)"; then
  echo "FATAL: production host preflight failed — nothing was backed up, checked out or migrated — NOT GO" >&2
  exit 2
fi
# Rule 235: the version probes run from / so no project configuration is read,
# and composer with --no-plugins — from the application directory even
# `composer --version` can activate a plugin installed in vendor/ as root.
if ! dms_production_toolchain_versions \
     "$(cd / && node --version 2>/dev/null || true)" \
     "$(cd / && npm --version 2>/dev/null || true)" \
     "$(php -r 'echo PHP_VERSION;' 2>/dev/null || true)" \
     "$(cd / && COMPOSER_ALLOW_SUPERUSER=1 composer --version --no-plugins --no-ansi --no-interaction 2>/dev/null | sed -n '1p' || true)"; then
  echo "FATAL: production toolchain versions do not match CI — nothing was backed up, checked out or migrated — NOT GO" >&2
  exit 2
fi
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

# ─── DMS-DEPLOY-PRIVSEP-PHASE-2 (rule 235) build identity ───────────────────
# composer and npm never run as root: they run as the build identity declared in
# the host authority, inside a sandboxed transient service, over trees root
# hands over and claims back (scripts/lib/build-privsep.sh). Checked before the
# first mutation; a missing or privileged build identity is NOT GO. Read from
# the immutable snapshot.
# shellcheck source=lib/build-privsep.sh
source "${DEPLOY_TOOLS_DIR}/scripts/lib/build-privsep.sh"
BUILD_USER="$(dms_preflight_authority_value "${DEPLOY_TOOLS_DIR}/deploy/production-host.conf" DMS_PRODUCTION_BUILD_USER)"
BUILD_HOME="$(dms_preflight_authority_value "${DEPLOY_TOOLS_DIR}/deploy/production-host.conf" DMS_PRODUCTION_BUILD_HOME)"
dms_build_identity_check "$BUILD_USER" "$BUILD_HOME" "$RUNTIME_USER" "$RUNTIME_GROUP"
BUILD_GROUP="$(id -gn -- "$BUILD_USER")"

# ─── DMS-DEPLOY-PRIVSEP-HARDENING-1 (rule 233) privilege separation ─────────
# The runtime user owns storage/ and bootstrap/cache, so anything root executes,
# opens or chmods there is something a runtime compromise controls. Root
# therefore never runs application PHP (every artisan command below runs as the
# runtime user, composer runs --no-scripts), and never opens, writes or chmods a
# path in the runtime trees: the runtime user writes the dump and the evidence
# files and sets every mode there. Root only repairs ownership of entries found
# foreign (lchown, from inside their directory, never through a hardlink) and
# claims for itself any source path another identity owns or can write, before
# it writes there. A run that ends with privilege drift is NOT GO. The library is
# read from the immutable snapshot.
# shellcheck source=lib/runtime-privsep.sh
source "${DEPLOY_TOOLS_DIR}/scripts/lib/runtime-privsep.sh"

# Run a command as the PHP-FPM runtime user — never as root (rule 233).
# The checkout belongs to root, so the runtime user's git (release evidence
# records the commit and the GO tag) would stop at "dubious ownership". Trust
# exactly this directory through command-scope configuration, the one scope git
# accepts for safe.directory besides system and global.
as_runtime() {
  GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0="$APP_DIR" \
    dms_privsep_as_runtime "$RUNTIME_USER" "$@"
}

# Run a command as the runtime user with its stdout written to a file that the
# runtime user opens itself — root never opens a path in a runtime tree.
runtime_json() {
  GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0="$APP_DIR" \
    dms_privsep_runtime_output "$RUNTIME_USER" "$@"
}

echo "== Privilege separation preflight (rule 233) =="
dms_privsep_prepare 0 0 "$RUNTIME_USER" "$RUNTIME_GROUP"

# Rule 233: root sources the environment file below. Refuse one that another
# identity could have written: repairing its mode would not repair its content.
dms_privsep_assert_trusted_file 0 .env

# Ensure required Laravel runtime directories exist — created by the runtime user.
as_runtime mkdir -p \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

# Normalize ownership + safe modes (setgid dirs so new files inherit the group).
# NEVER use a world-writable (0777) mode.
#
# Rule 233: root only hands foreign entries (a tracked file the checkout wrote,
# an operator's leftover) to the runtime user — lchown from inside each entry's
# directory, never through a hardlink. Every MODE is set by the runtime user
# itself (dms_privsep_normalize_modes, which refuses root), so no symlink the
# runtime plants can steer a chmod onto a host file.
#
# The mode policy is unchanged: storage and bootstrap/cache 2775/0664 except the
# backup tree (2750/0640, SECURITY-FIX-DEPLOY-BACKUP-FILE-PERMISSIONS-1) and the
# declared private paths, which are never widened and lose every "other" bit
# (SECURITY-F8-PRIVATE-STORAGE-PATHS-1, INFRA-SEC-RUNTIME-1).
normalize_runtime_ownership() {
  local -a private_paths=()
  read -r -a private_paths <<< "${DMS_PRIVATE_PATHS:-}"
  dms_privsep_repair_runtime_ownership "$RUNTIME_USER" "$RUNTIME_GROUP" storage bootstrap/cache
  dms_privsep_as_runtime_fn "$RUNTIME_USER" "" dms_privsep_normalize_modes "${private_paths[@]}"
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
bash "${DEPLOY_TOOLS_DIR}/scripts/harden-secret-permissions.sh" apply --app-dir "$APP_DIR" --owner root --group "$RUNTIME_GROUP" --runtime-user "$RUNTIME_USER"
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
# SECURITY-FIX-DEPLOY-BACKUP-FILE-PERMISSIONS-1: the dump is created PRIVATE.
# The helper wraps pg_dump in a `umask 077` subshell, so the redirect creates
# the file 0600 before a single byte is written, and it is published unchanged
# at 0600 once complete (the runtime-side normalization later sets 0640). The previous `pg_dump > file; chmod 0640` left the inode 0644
# for the entire dump. The helper is read from the immutable snapshot.
#
# Rule 233: the backup tree belongs to the runtime user, so the directories and
# the dump are created BY THE RUNTIME USER (the helper's functions are handed to
# it from the snapshot). Root opening the dump path inside that tree is exactly
# the redirect a planted symlink would steer. The runtime already holds the
# database credentials, so pg_dump runs with the same database identity as before.
# shellcheck source=lib/private-db-dump.sh
source "${DEPLOY_TOOLS_DIR}/scripts/lib/private-db-dump.sh"
DUMP_HELPERS="_dms_dump_err _dms_dump_assert_no_default_acl"
dms_privsep_as_runtime_fn "$RUNTIME_USER" "$DUMP_HELPERS" dms_prepare_private_backup_dir storage/app/backups
dms_privsep_as_runtime_fn "$RUNTIME_USER" "$DUMP_HELPERS" dms_prepare_private_backup_dir storage/app/backups/deploy
# SECURITY-F8-PRIVATE-STORAGE-PATHS-1: private-path validation + union, used once
# the checkout has advanced. Read from the immutable snapshot.
# shellcheck source=lib/private-paths.sh
source "${DEPLOY_TOOLS_DIR}/scripts/lib/private-paths.sh"

set -a
source .env
set +a

BACKUP="storage/app/backups/deploy/pre_auto_deploy_${STAMP}.sql"

# Rule 235 (R4): the digest the restore tools trust must be ROOT's own. Root
# runs pg_dump (a root-owned binary; no application code) into a private file
# in this run's root-only snapshot directory (the bootstrap removes it on every
# exit), hashes THAT, and hands the runtime user the BYTES on a read-only stdin;
# the runtime user writes its private dump into its tree exactly as before. A
# runtime process can therefore no longer swap the dump before root hashes it.
DUMP_STAGING="${DMS_DEPLOY_SNAPSHOT_DIR}/dump-staging"
mkdir -m 0700 -- "$DUMP_STAGING"
PGPASSWORD="${DB_PASSWORD}" dms_write_private_dump "${DUMP_STAGING}/dump.sql" pg_dump \
  -h "${DB_HOST:-127.0.0.1}" \
  -p "${DB_PORT:-5432}" \
  -U "${DB_USERNAME}" \
  -d "${DB_DATABASE}"
DUMP_SHA256="$(sha256sum < "${DUMP_STAGING}/dump.sql" | cut -d' ' -f1)"
dms_privsep_as_runtime_fn_from "${DUMP_STAGING}/dump.sql" "$RUNTIME_USER" "$DUMP_HELPERS" dms_write_private_dump "$BACKUP" cat

as_runtime test -s "$BACKUP"
if [ "$(as_runtime sha256sum -- "$BACKUP" | cut -d' ' -f1)" != "$DUMP_SHA256" ]; then
  echo "FATAL: the runtime user's copy of the dump differs from the bytes root hashed — NOT GO" >&2
  exit 1
fi
rm -rf -- "$DUMP_STAGING"
# The digest lands in the root-only deploy log: the restore tools compare a dump
# against exactly this line (scripts/lib/restore-integrity.sh).
echo "Pre-deploy backup: ${BACKUP} sha256=${DUMP_SHA256}"

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

# Rule 233: the checkout (root's git) writes the tracked files that live under
# storage/ and creates any new tracked directory there. Hand them to the runtime
# user before the first runtime step needs them.
dms_privsep_repair_runtime_ownership "$RUNTIME_USER" "$RUNTIME_GROUP" storage bootstrap/cache

# SECURITY-F8-PRIVATE-STORAGE-PATHS-1: DMS_PRIVATE_PATHS was read from the LIVE
# identity authority before the fast-forward, so private paths the target
# release adds would otherwise only be protected from the NEXT deploy on, and
# this deploy's storage widening would re-open them. Adopt the target's
# declaration now, read from the pinned commit object (not the working tree).
# Union only: a release can add private paths, never drop one the host already
# protects. Any invalid entry fails the deploy closed.
TARGET_PRIVATE_PATHS="$(
  # shellcheck disable=SC1090
  . <(git show "${TARGET_SHA}:deploy/runtime-identity.conf" 2>/dev/null)
  printf '%s' "${DMS_PRIVATE_PATHS:-}"
)"
if ! EFFECTIVE_PRIVATE_PATHS="$(dms_private_paths_union "${DMS_PRIVATE_PATHS:-}" "$TARGET_PRIVATE_PATHS")"; then
  echo "FATAL: invalid private storage path declaration — NOT GO" >&2
  exit 1
fi
DMS_PRIVATE_PATHS="$EFFECTIVE_PRIVATE_PATHS"
echo "Private storage paths (live + target): ${DMS_PRIVATE_PATHS}"

echo "== Composer install (build identity, rule 235) =="
# Rule 233: --no-scripts — composer's post-autoload-dump scripts boot the
# application (`@php artisan package:discover` `require`s bootstrap/cache/*.php,
# which the runtime user can rewrite); its two steps run below as the runtime
# user instead (DeployHardenPrivilegeSeparationTest pins that list).
# Rule 235: composer itself runs as the build identity, sandboxed, writing only
# vendor/; root claims vendor/ back before anything loads it.
BUILD_MASKS="$(dms_build_env_masks "$APP_DIR")"
dms_build_handover "$BUILD_USER" "$BUILD_GROUP" vendor
dms_build_run "$BUILD_USER" "$BUILD_HOME" "$APP_DIR" "${APP_DIR}/vendor" "$BUILD_MASKS" composer install --no-dev --optimize-autoloader --no-scripts --no-interaction
dms_build_claim "$BUILD_USER" vendor

echo "== Composer post-autoload-dump (as the runtime user, rule 233) =="
# Illuminate\Foundation\ComposerScripts::postAutoloadDump: drop the compiled
# configuration, services and packages manifests so the next boot rebuilds them
# from the new vendor/ — then rediscover the packages.
as_runtime rm -f -- bootstrap/cache/config.php bootstrap/cache/services.php bootstrap/cache/packages.php
as_runtime php artisan package:discover --ansi

echo "== NPM build (build identity, rule 235) =="
# Every dependency's lifecycle scripts and the asset build (vite and its
# plugins) run as the build identity, sandboxed, writing only node_modules/ and
# public/build; root claims both back and the web server's tree carries no symlink.
dms_build_handover "$BUILD_USER" "$BUILD_GROUP" node_modules public/build
dms_build_run "$BUILD_USER" "$BUILD_HOME" "$APP_DIR" "${APP_DIR}/node_modules ${APP_DIR}/public/build" "$BUILD_MASKS" npm ci
dms_build_run "$BUILD_USER" "$BUILD_HOME" "$APP_DIR" "${APP_DIR}/node_modules ${APP_DIR}/public/build" "$BUILD_MASKS" npm run build
dms_build_claim "$BUILD_USER" node_modules public/build
dms_build_assert_no_symlinks public/build

echo "== Laravel migrate =="
as_runtime php artisan migrate --force

echo "== NSF-10 backup verify =="
as_runtime php artisan foundation:backup-verify --path="$BACKUP"

echo "== Clear stale route/config cache before route-dependent gates (ENT-8) =="
# Route-dependent governance gates (foundation:developer-console-check,
# foundation:health-check) inspect the live route table. A stale route cache
# from a prior deploy can hide newly added routes during the gate phase (the
# ENT-7 deploy hiccup). Clearing here — before the gates, well before the
# cache-rebuild block below — makes those gates see the freshly deployed routes.
as_runtime php artisan route:clear
as_runtime php artisan config:clear

echo "== Foundation deploy governance gates =="
as_runtime php artisan architecture:foundation-roadmap-check
as_runtime php artisan data-quality:dq1-audit --fail-on=error
as_runtime php artisan inventory:batch-governance-audit --fail-on=error
as_runtime php artisan inventory:source-document-batch-audit --fail-on=error
as_runtime php artisan inventory:ambiguous-batch-review-pack
as_runtime php artisan architecture:dmo-governance-check
as_runtime php artisan architecture:nsf-governance-check --include-observability
as_runtime php artisan foundation:feature-flags
as_runtime php artisan foundation:cache-governance-check
runtime_json storage/release-evidence/latest/cache-governance-check.json php artisan foundation:cache-governance-check --json || true
as_runtime php artisan foundation:queue-governance-check
runtime_json storage/release-evidence/latest/queue-governance-check.json php artisan foundation:queue-governance-check --json || true
as_runtime php artisan foundation:queue-retry-failed-job-check
as_runtime php artisan foundation:idempotency-outbox-check
runtime_json storage/release-evidence/latest/idempotency-outbox-check.json php artisan foundation:idempotency-outbox-check --json || true
as_runtime php artisan foundation:developer-console-check
runtime_json storage/release-evidence/latest/developer-console-check.json php artisan foundation:developer-console-check --json || true
as_runtime php artisan foundation:health-check
runtime_json storage/release-evidence/latest/health-check-check.json php artisan foundation:health-check --json || true
as_runtime php artisan foundation:security-compliance-check
runtime_json storage/release-evidence/latest/security-compliance-check.json php artisan foundation:security-compliance-check --json || true
as_runtime php artisan foundation:cicd-enterprise-gate-check
runtime_json storage/release-evidence/latest/cicd-enterprise-gate-check.json php artisan foundation:cicd-enterprise-gate-check --json || true
as_runtime php artisan foundation:deployment-rollback-check
runtime_json storage/release-evidence/latest/deployment-rollback-check.json php artisan foundation:deployment-rollback-check --json || true
as_runtime php artisan foundation:deployment-entrypoint-check
runtime_json storage/release-evidence/latest/deployment-entrypoint-check.json php artisan foundation:deployment-entrypoint-check --json || true
as_runtime php artisan foundation:backup-dr-check
runtime_json storage/release-evidence/latest/backup-dr-check.json php artisan foundation:backup-dr-check --json || true
as_runtime php artisan foundation:load-test-baseline-check
runtime_json storage/release-evidence/latest/load-test-baseline-check.json php artisan foundation:load-test-baseline-check --json || true
as_runtime php artisan foundation:load-test-scale-projection-check
runtime_json storage/release-evidence/latest/load-test-scale-projection-check.json php artisan foundation:load-test-scale-projection-check --json || true
as_runtime php artisan foundation:enterprise-documentation-check
runtime_json storage/release-evidence/latest/enterprise-documentation-check.json php artisan foundation:enterprise-documentation-check --json || true
as_runtime php artisan foundation:enterprise-closure-check
runtime_json storage/release-evidence/latest/enterprise-closure-check.json php artisan foundation:enterprise-closure-check --json || true
as_runtime php artisan foundation:ent-1-4-audit-check
runtime_json storage/release-evidence/latest/ent-1-4-audit-check.json php artisan foundation:ent-1-4-audit-check --json || true
as_runtime php artisan foundation:queue-worker-runtime-check
runtime_json storage/release-evidence/latest/queue-worker-runtime-check.json php artisan foundation:queue-worker-runtime-check --json || true
as_runtime php artisan foundation:runtime-hardening-check
runtime_json storage/release-evidence/latest/runtime-hardening-check.json php artisan foundation:runtime-hardening-check --json || true
as_runtime php artisan foundation:devflow-check
runtime_json storage/release-evidence/latest/devflow-check.json php artisan foundation:devflow-check --json || true
runtime_json storage/release-evidence/latest/shared-service-audit.json php artisan foundation:shared-service-audit --json || true
as_runtime php artisan foundation:idempotency-audit
runtime_json storage/release-evidence/latest/idempotency-audit.json php artisan foundation:idempotency-audit --json || true
as_runtime php artisan foundation:outbox-audit
runtime_json storage/release-evidence/latest/outbox-audit.json php artisan foundation:outbox-audit --json || true
as_runtime php artisan foundation:db-performance-check
as_runtime php artisan foundation:db-performance-check --include-db-stats
runtime_json storage/release-evidence/latest/db-performance-check.json php artisan foundation:db-performance-check --include-db-stats --json || true
as_runtime php artisan foundation:postgres-runtime-check
as_runtime php artisan foundation:postgres-runtime-check --include-db-stats
as_runtime php artisan foundation:postgres-runtime-check --include-db-stats --include-pgbouncer-probe || true
runtime_json storage/release-evidence/latest/postgres-runtime-check.json php artisan foundation:postgres-runtime-check --include-db-stats --json || true
as_runtime php artisan foundation:reporting-summary-check
as_runtime php artisan foundation:reporting-summary-check --include-db-inventory
runtime_json storage/release-evidence/latest/reporting-summary-check.json php artisan foundation:reporting-summary-check --include-db-inventory --json || true
as_runtime php artisan foundation:reporting-summary-refresh --dry-run
runtime_json storage/release-evidence/latest/reporting-summary-refresh-dry-run.json php artisan foundation:reporting-summary-refresh --dry-run --json || true
as_runtime php artisan foundation:release-safety-check
as_runtime php artisan release:automated-smoke
as_runtime php artisan architecture:foundation-governance-summary

echo "== NSF-10 release evidence capture/check (vps profile) =="
as_runtime php artisan release:evidence-capture --profile=vps --base-url=http://127.0.0.1 --backup-path="$BACKUP"
as_runtime php artisan release:evidence-check --profile=vps
as_runtime php artisan foundation:release-safety-check --profile=vps

# Rule 233: no normalization is needed before the cache rebuild any more. The
# governance gates used to run as root and leave root-owned cache and log files
# that the runtime user could not overwrite; every one of them now runs as the
# runtime user, so nothing root-owned is created in the runtime trees.
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
bash "${DEPLOY_TOOLS_DIR}/scripts/harden-secret-permissions.sh" apply --app-dir "$APP_DIR" --owner root --group "$RUNTIME_GROUP" --runtime-user "$RUNTIME_USER"
if ! bash "${DEPLOY_TOOLS_DIR}/scripts/harden-secret-permissions.sh" verify --app-dir "$APP_DIR" --owner root --group "$RUNTIME_GROUP" --runtime-user "$RUNTIME_USER"; then
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
      --additional-private-paths "$DMS_PRIVATE_PATHS" \
      --require-host; then
  echo "FATAL: runtime identity isolation is broken — NOT GO" >&2
  exit 1
fi

# ─── DMS-DEPLOY-PRIVSEP-HARDENING-1 (rule 233) privilege drift gate ─────────
# Proves the run left no foreign-owned entry in the runtime trees (nothing root
# created there survives) and no source entry that another identity owns or can
# write. Read-only. A deploy that cannot prove it is NOT GO.
echo "== Verify privilege separation (rule 233) =="
if ! dms_privsep_final_gate 0 0 "$RUNTIME_USER" "$RUNTIME_GROUP"; then
  echo "FATAL: privilege separation is broken after the deploy — NOT GO" >&2
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
if [ "$FINAL_HEAD" != "$TARGET_SHA" ] || [ "$FINAL_HEAD" != "$APPROVED_SHA" ]; then
  echo "FATAL: final HEAD ${FINAL_HEAD} does not match the pinned target ${TARGET_SHA} — NOT GO" >&2
  exit 1
fi
echo "DEPLOY_HEAD_TARGET_MATCH=YES (${FINAL_HEAD})"

echo "DEPLOY OK: ${STAMP}"
