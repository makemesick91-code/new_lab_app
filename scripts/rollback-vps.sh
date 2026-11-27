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
#   - A DB backup is taken, and its digest checked by root, BEFORE the code is
#     switched; backup failure stops the rollback. It is written to the deploy
#     backup directory, which the NSF-10 verifier accepts, and verified
#     (foundation:backup-verify) by the target's code after the switch. A target
#     that could not finish the rollback (its verifier or a post-switch command)
#     is refused before anything is backed up or checked out (R6-B7).
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
# Same rule as the deploy path: the application root is only taken from the
# environment when the trusted bootstrap put it there.
if [ -n "${DMS_DEPLOY_SNAPSHOT_DIR:-}" ] && [ -n "${DMS_DEPLOY_APP_DIR:-}" ]; then
  APP_DIR="$DMS_DEPLOY_APP_DIR"
fi
STAMP="$(date +%Y%m%d-%H%M%S)"
TARGET_REF="${1:-}"

if [ -z "$TARGET_REF" ]; then
  echo "Usage: bash scripts/rollback-vps.sh <target-tag-or-commit>"
  echo "Refusing to roll back without an explicit target ref."
  exit 1
fi

cd "$APP_DIR"

# ─── DEPLOY-HARDEN-1 immutable execution bootstrap ──────────────────────────
# A rollback rewrites the working tree exactly like a deploy does — including
# this script — so it is exposed to the identical self-modification defect and
# gets the identical protection: host lock, exact pinned target, immutable
# execution snapshot.
#
# The snapshot is taken from the CURRENT code (HEAD), never from the older
# rollback target. Rolling the application back must not roll the security
# tooling back: INFRA-SEC-ENV-1 secret hardening and the INFRA-SEC-RUNTIME-1
# isolation verifier have to run in their modern form even when the target
# predates them. This supersedes the previous ad-hoc mktemp staging directory,
# which protected only the verifier and left this script itself mutable.
if [ -z "${DMS_DEPLOY_SNAPSHOT_DIR:-}" ]; then
  ROLLBACK_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
  IMMUTABLE_EXEC="${ROLLBACK_SELF_DIR}/deploy-immutable-exec.sh"
  [ -r "$IMMUTABLE_EXEC" ] || IMMUTABLE_EXEC="${APP_DIR}/scripts/deploy-immutable-exec.sh"
  if [ ! -r "$IMMUTABLE_EXEC" ]; then
    echo "FATAL: immutable deployment bootstrap missing: ${IMMUTABLE_EXEC}" >&2
    echo "       Refusing to roll back from a mutable working-tree script (DEPLOY-HARDEN-1)." >&2
    exit 2
  fi

  # Resolve the operator's tag or commit to ONE exact commit before any mutation.
  # Rule 231: this runs OUTSIDE the release lock, so it mutates as little as
  # possible. A rollback target must be an ancestor of the running checkout, so
  # it is normally already here: resolve locally first and fetch nothing. Only a
  # tag this host has never fetched is fetched — that one tag, into a private
  # ref — never `--tags --prune` (one rewritten tag on the remote made that
  # fetch fail and blocked every rollback; pruning rewrote shared refs while a
  # deploy could be running).
  ROLLBACK_TARGET_SHA=""
  if [[ "$TARGET_REF" =~ ^[0-9a-f]{40}$ ]]; then
    # A full commit SHA is an object name, never looked up as a ref (a ref may
    # carry that name too), and never fetched: a rollback target is an ancestor
    # of the running checkout, so it is already in this object store.
    if [ "$(git cat-file -t "$TARGET_REF" 2>/dev/null)" = "commit" ]; then
      ROLLBACK_TARGET_SHA="$TARGET_REF"
    fi
  elif [[ "$TARGET_REF" =~ ^[0-9a-fA-F]{4,39}$ ]]; then
    # An abbreviated SHA is ambiguous by construction: a tag or branch with that
    # name outranks the commit in `git rev-parse` (several such refs even hide
    # from --symbolic-full-name). Refuse it; never fetch it.
    echo "FATAL: rollback target '${TARGET_REF}' is an abbreviated commit id and is ambiguous — pass the full 40-character commit SHA, or a GO tag name (rule 231)" >&2
    exit 2
  elif [[ "$TARGET_REF" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]*$ ]] && [[ "$TARGET_REF" != *..* ]] \
       && git check-ref-format "refs/tags/${TARGET_REF}" 2>/dev/null; then
    # Any other name is a TAG, resolved only as refs/tags/<name> — never a branch,
    # a remote-tracking ref or an object prefix.
    ROLLBACK_TARGET_SHA="$(git rev-parse --verify --quiet "refs/tags/${TARGET_REF}^{commit}" 2>/dev/null || true)"
    if [ -z "$ROLLBACK_TARGET_SHA" ]; then
      echo "== Fetch the target tag (refs only) =="
      ROLLBACK_FETCH_REF="refs/dms-rollback-target/$$-${RANDOM}${RANDOM}"
      if git fetch --no-tags --quiet origin "+refs/tags/${TARGET_REF}:${ROLLBACK_FETCH_REF}" 2>/dev/null; then
        ROLLBACK_TARGET_SHA="$(git rev-parse --verify --quiet "${ROLLBACK_FETCH_REF}^{commit}" 2>/dev/null || true)"
      fi
      git update-ref -d "$ROLLBACK_FETCH_REF" >/dev/null 2>&1 || true
    fi
  else
    echo "FATAL: rollback target '${TARGET_REF}' is not a full commit SHA or a plain tag name" >&2
    exit 2
  fi
  if [ -z "$ROLLBACK_TARGET_SHA" ]; then
    echo "FATAL: rollback target does not resolve to a commit: ${TARGET_REF}" >&2
    exit 2
  fi
  # Rule 229 / DEPLOY-11: a rollback is target-specific and goes BACK along the
  # deployed history only. A target that is not an ancestor of the running
  # checkout (an unmerged branch, an unrelated object, a newer commit) is never
  # rolled "back" to — moving forward is a deploy, and needs an approval.
  if ! git merge-base --is-ancestor "$ROLLBACK_TARGET_SHA" HEAD; then
    echo "FATAL: rollback target ${ROLLBACK_TARGET_SHA} is not an ancestor of the running checkout $(git rev-parse --verify HEAD) — refusing (rule 229)" >&2
    exit 2
  fi
  echo "ROLLBACK_TARGET_SHA=${ROLLBACK_TARGET_SHA}"

  exec bash "$IMMUTABLE_EXEC" \
    --role rollback \
    --app-dir "$APP_DIR" \
    --target-sha "$ROLLBACK_TARGET_SHA" \
    --snapshot-ref HEAD \
    --overlay "${APP_DIR}/deploy/runtime-identity.conf:deploy/runtime-identity.conf" \
    -- scripts/rollback-vps.sh "$TARGET_REF"
fi

case "${BASH_SOURCE[0]}" in
  "${DMS_DEPLOY_SNAPSHOT_DIR}"/*) ;;
  *)
    echo "FATAL: rollback program is not running from the immutable snapshot (${BASH_SOURCE[0]})" >&2
    exit 2
    ;;
esac
# Rule 229 (independent review, 8D PR2, finding 4): the DMS_DEPLOY_* values are
# trusted only inside the bootstrap's own root-only snapshot with the rollback
# lock held by the bootstrap — never when exported by hand.
# Rule 231: the lock is THE release lock, shared with deploy, in the one lock
# root the bootstrap picks on this host (/run unless the host has no writable
# /run).
ROLLBACK_LOCK_ROOT="/run/daengtisiams-deploy"
if [ ! -d /run ] || [ ! -w /run ]; then
  ROLLBACK_LOCK_ROOT="/var/lock/daengtisiams-deploy"
fi
if [ "$(dirname -- "${DMS_DEPLOY_LOCK_FILE:-/nonexistent/release.lock}")" != "$ROLLBACK_LOCK_ROOT" ]; then
  echo "FATAL: the rollback lock '${DMS_DEPLOY_LOCK_FILE:-}' is not in the trusted lock root ${ROLLBACK_LOCK_ROOT} — NOT GO" >&2
  exit 2
fi
if [ "$DMS_DEPLOY_LOCK_FILE" != "${ROLLBACK_LOCK_ROOT}/release.lock" ] \
   || [ "$DMS_DEPLOY_SNAPSHOT_DIR" != "${ROLLBACK_LOCK_ROOT}/rollback-${DMS_DEPLOY_RUN_ID:-}" ] \
   || [ -L "$DMS_DEPLOY_SNAPSHOT_DIR" ] \
   || [ "$(stat -c '%u %a' "$DMS_DEPLOY_SNAPSHOT_DIR" 2>/dev/null)" != "$(id -u) 700" ]; then
  echo "FATAL: ${DMS_DEPLOY_SNAPSHOT_DIR} is not this run's trusted snapshot — NOT GO" >&2
  exit 2
fi
if flock -n "$DMS_DEPLOY_LOCK_FILE" true 2>/dev/null; then
  echo "FATAL: not running under the rollback lock (the release lock) — not launched by the bootstrap — NOT GO" >&2
  exit 2
fi
ROLLBACK_TARGET_SHA="${DMS_DEPLOY_TARGET_SHA:?immutable bootstrap must pin a rollback target commit}"
DEPLOY_TOOLS_DIR="$DMS_DEPLOY_SNAPSHOT_DIR"
echo "Rollback run id:          ${DMS_DEPLOY_RUN_ID:-unknown}"
echo "Execution source:         ${BASH_SOURCE[0]} (immutable snapshot)"
echo "Pinned rollback target:   ${ROLLBACK_TARGET_SHA} (${TARGET_REF})"
# ────────────────────────────────────────────────────────────────────────────

# ─── DMS-POST-MIGRATION-DEV-INFRA-ALIGNMENT-1 production host preflight ─────
# Same gate as the deploy, before the first mutation. The snapshot is taken from
# the CURRENT code, so the authority is present even when the rollback target
# predates it.
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
if [ "$(id -gn "$RUNTIME_USER")" != "$RUNTIME_GROUP" ]; then
  echo "FATAL: '${RUNTIME_USER}' primary group is '$(id -gn "$RUNTIME_USER")', expected '${RUNTIME_GROUP}' — NOT GO" >&2
  exit 2
fi

# ─── DMS-DEPLOY-PRIVSEP-PHASE-2 (rule 235) build identity ───────────────────
# Same boundary as the deploy: composer and npm run as the build identity in a
# sandboxed transient service, never as root. From the snapshot of the CURRENT
# code, so a rollback to an older release still builds unprivileged.
# shellcheck source=lib/build-privsep.sh
source "${DEPLOY_TOOLS_DIR}/scripts/lib/build-privsep.sh"
BUILD_USER="$(dms_preflight_authority_value "${DEPLOY_TOOLS_DIR}/deploy/production-host.conf" DMS_PRODUCTION_BUILD_USER)"
BUILD_HOME="$(dms_preflight_authority_value "${DEPLOY_TOOLS_DIR}/deploy/production-host.conf" DMS_PRODUCTION_BUILD_HOME)"
dms_build_identity_check "$BUILD_USER" "$BUILD_HOME" "$RUNTIME_USER" "$RUNTIME_GROUP"
BUILD_GROUP="$(id -gn -- "$BUILD_USER")"
BUILD_MASKS="$(dms_build_env_masks "$APP_DIR")"

# ─── DMS-DEPLOY-PRIVSEP-HARDENING-1 (rule 233) privilege separation ─────────
# Same rule as the deploy: root never runs application PHP and never opens,
# writes or chmods a path in the runtime trees. A rollback used to be the worst
# case — it rebuilt the configuration cache (database password and application
# key) and ran the smoke AS ROOT. The library comes from the snapshot of the
# CURRENT code, so a rollback to a release that predates rule 233 still runs
# separated.
# shellcheck source=lib/runtime-privsep.sh
source "${DEPLOY_TOOLS_DIR}/scripts/lib/runtime-privsep.sh"

# The checkout belongs to root, so the runtime user's git (release evidence
# records the commit and the GO tag) would stop at "dubious ownership". Trust
# exactly this directory through command-scope configuration, the one scope git
# accepts for safe.directory besides system and global.
as_runtime() {
  GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0="$APP_DIR" \
    dms_privsep_as_runtime "$RUNTIME_USER" "$@"
}

echo "== Privilege separation preflight (rule 233) =="
dms_privsep_prepare 0 0 "$RUNTIME_USER" "$RUNTIME_GROUP"

# Rule 233: root sources the environment file below. Refuse one that another
# identity could have written: repairing its mode would not repair its content.
dms_privsep_assert_trusted_file 0 .env

# DEPLOY-HARDEN-1: the isolation verifier and the identity authority now live in
# the immutable execution snapshot created before any mutation, so the
# post-rollback security gates still run even when the target ref predates them.
# The previous mktemp staging directory is gone: the snapshot supersedes it, is
# trust-verified (root-owned, 0700, symlink-checked) and is cleaned up by the
# bootstrap on every exit path, including a crash.

echo "== Record current ref (pre-rollback state) =="
CURRENT_HEAD="$(git rev-parse HEAD)"
CURRENT_REF_DESC="$(git describe --tags --always 2>/dev/null || echo "$CURRENT_HEAD")"
echo "Current HEAD: ${CURRENT_HEAD}"
echo "Current ref:  ${CURRENT_REF_DESC}"
echo "Target ref:   ${TARGET_REF}"
git status -sb
# Re-asserted inside the lock, before the backup: the checkout may have moved
# between the hand-over and here.
if ! git merge-base --is-ancestor "$ROLLBACK_TARGET_SHA" "$CURRENT_HEAD"; then
  echo "FATAL: rollback target ${ROLLBACK_TARGET_SHA} is not an ancestor of the running checkout ${CURRENT_HEAD} — nothing was backed up or checked out — NOT GO" >&2
  exit 2
fi

# R6-B4 (IAR-A12): while the database role split is active (host switch
# DMS_R6_MIGRATOR=1), a target that predates R6-B4 is refused before anything is
# backed up or checked out: its deploy would migrate as the runtime user, which can
# no longer create anything. With the switch off this changes nothing.
# shellcheck source=lib/r6-migrate.sh
source "${DEPLOY_TOOLS_DIR}/scripts/lib/r6-migrate.sh"
if ! dms_r6_rollback_guard "$ROLLBACK_TARGET_SHA"; then
  echo "FATAL: the R6 rollback guard refused target ${ROLLBACK_TARGET_SHA} — nothing was backed up or checked out — NOT GO" >&2
  exit 2
fi

# R6-B7: everything after the checkout runs the TARGET's code. Before any backup
# or checkout, refuse a target that could not finish this rollback: its shipped
# backup verifier (config/backup_governance.php at that commit) must accept the
# deploy backup directory and .sql, and it must ship every application command
# this script runs after the checkout (Laravel's own commands always exist).
# Keep this list equal to the commands below (a test enforces it).
ROLLBACK_POST_SWITCH_COMMANDS="foundation:backup-verify architecture:foundation-roadmap-check foundation:queue-retry-failed-job-check foundation:idempotency-outbox-check foundation:developer-console-check foundation:health-check foundation:security-compliance-check foundation:cicd-enterprise-gate-check foundation:deployment-rollback-check foundation:deployment-entrypoint-check foundation:release-safety-check architecture:foundation-governance-summary release:automated-smoke"
TARGET_BACKUP_CONFIG="$(git show "${ROLLBACK_TARGET_SHA}:config/backup_governance.php" 2>/dev/null || true)"
if ! grep -Fq "'storage/app/backups/deploy'" <<< "$TARGET_BACKUP_CONFIG" || ! grep -Fq "'sql'" <<< "$TARGET_BACKUP_CONFIG"; then
  echo "FATAL: rollback target ${ROLLBACK_TARGET_SHA} ships no backup verifier that accepts storage/app/backups/deploy and .sql — nothing was backed up or checked out — NOT GO" >&2
  exit 2
fi
TARGET_MISSING_COMMANDS=""
for rollback_cmd in $ROLLBACK_POST_SWITCH_COMMANDS; do
  if ! git grep -q -E "signature = ['\"]${rollback_cmd}([ '\"]|\$)" "$ROLLBACK_TARGET_SHA" -- app 2>/dev/null; then
    TARGET_MISSING_COMMANDS="${TARGET_MISSING_COMMANDS} ${rollback_cmd}"
  fi
done
if [ -n "$TARGET_MISSING_COMMANDS" ]; then
  echo "FATAL: rollback target ${ROLLBACK_TARGET_SHA} lacks commands this rollback runs after the switch:${TARGET_MISSING_COMMANDS} — nothing was backed up or checked out — NOT GO" >&2
  exit 2
fi

echo "== Backup DB before rollback =="
# SECURITY-FIX-DEPLOY-BACKUP-FILE-PERMISSIONS-1: the dump is created PRIVATE
# (0600 under a scoped `umask 077`) and published unchanged once complete —
# never `pg_dump > file` followed by a chmod. The helper comes from the
# immutable snapshot of the CURRENT code, so rolling back to a ref that predates
# this fix still writes its pre-rollback dump privately.
# Rule 233: written BY THE RUNTIME USER into its own backup tree, exactly as the
# deploy does; root never opens a path there.
# shellcheck source=lib/private-db-dump.sh
source "${DEPLOY_TOOLS_DIR}/scripts/lib/private-db-dump.sh"
DUMP_HELPERS="_dms_dump_err _dms_dump_assert_no_default_acl"
dms_privsep_as_runtime_fn "$RUNTIME_USER" "$DUMP_HELPERS" dms_prepare_private_backup_dir storage/app/backups
dms_privsep_as_runtime_fn "$RUNTIME_USER" "$DUMP_HELPERS" dms_prepare_private_backup_dir storage/app/backups/deploy

set -a
source .env
set +a

# R6-B7: the deploy backup directory, next to the pre-deploy dumps, which the
# NSF-10 verifier accepts (config/backup_governance.php `allowed_directories`);
# the scheduled backup's retention (scripts/backup-vps.sh) treats these dumps
# like pre-deploy dumps.
BACKUP="storage/app/backups/deploy/pre_rollback_${STAMP}.sql"

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
echo "Backup written: ${BACKUP} sha256=${DUMP_SHA256}"

echo "== Checkout pinned rollback target =="
# The operator's ref was resolved to this exact commit before the lock-protected
# critical section began, so a tag that gets moved mid-rollback cannot retarget
# this run. The mutation is safe because the interpreter is reading the
# immutable snapshot, not the file being replaced.
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "FATAL: tracked files are modified in the production checkout — NOT GO" >&2
  git status --porcelain --untracked-files=no >&2
  exit 1
fi
# R6-B7: from the checkout on, every stop before "ROLLBACK OK" (an error, an
# interruption, a failed or partial checkout) says that the host is between
# releases and how to recover (runbook vps-deploy-rollback-runbook.md).
dms_rollback_partial_notice() {
  echo "ROLLBACK STATE: stopped after the code switch began (target ${ROLLBACK_TARGET_SHA}, now at $(git rev-parse --short HEAD 2>/dev/null || echo unknown)). Recover with a forward deploy (a new signed approval), or re-run this rollback only if the target contains R6-B7 — see the runbook." >&2
}
# A signal to the whole process group (an SSH drop, Ctrl+C) can arrive while the
# EXIT handler runs: each handler first ignores further signals, and a signal
# handler states the notice itself, so it is printed exactly once.
dms_rollback_on_exit() {
  local rc=$?
  trap '' TERM INT HUP
  [ "$rc" -eq 0 ] || dms_rollback_partial_notice
}
dms_rollback_on_signal() {
  trap '' TERM INT HUP
  trap - EXIT
  dms_rollback_partial_notice
  exit "$1"
}
trap dms_rollback_on_exit EXIT
trap 'dms_rollback_on_signal 143' TERM
trap 'dms_rollback_on_signal 130' INT
trap 'dms_rollback_on_signal 129' HUP
git checkout "$ROLLBACK_TARGET_SHA"

ROLLED_BACK_SHA="$(git rev-parse --verify HEAD)"
if [ "$ROLLED_BACK_SHA" != "$ROLLBACK_TARGET_SHA" ]; then
  echo "FATAL: checkout is at ${ROLLED_BACK_SHA}, expected the pinned rollback target ${ROLLBACK_TARGET_SHA} — NOT GO" >&2
  exit 1
fi
echo "HEAD verified at pinned rollback target: ${ROLLED_BACK_SHA}"
# R6-B7: a re-run of this script snapshots the CURRENT checkout, which is now
# the target's code and its own rollback tooling.
echo "ROLLBACK STATE: code switched to ${ROLLED_BACK_SHA}; until ROLLBACK OK, recovery is a forward deploy, or a re-run only if the target contains R6-B7"

# Rule 233: the checkout (root's git) writes the tracked files that live under
# storage/ and creates any new tracked directory there. Hand them to the runtime
# user before the first runtime step needs them.
dms_privsep_repair_runtime_ownership "$RUNTIME_USER" "$RUNTIME_GROUP" storage bootstrap/cache

echo "== Composer install (build identity, rule 235) =="
# Rule 233: --no-scripts, then composer.json's post-autoload-dump as the runtime
# user — the same two steps the deploy runs. Rule 235: composer runs as the
# build identity, sandboxed, writing only vendor/, which root claims back.
dms_build_handover "$BUILD_USER" "$BUILD_GROUP" vendor
dms_build_run "$BUILD_USER" "$BUILD_HOME" "$APP_DIR" "${APP_DIR}/vendor" "$BUILD_MASKS" composer install --no-dev --optimize-autoloader --no-scripts --no-interaction
dms_build_claim "$BUILD_USER" vendor

echo "== Composer post-autoload-dump (as the runtime user, rule 233) =="
as_runtime rm -f -- bootstrap/cache/config.php bootstrap/cache/services.php bootstrap/cache/packages.php
as_runtime php artisan package:discover --ansi

echo "== NPM build (build identity, rule 235) =="
# The rolled-back release's dependency scripts and asset build run as the build
# identity, sandboxed — the same boundary as the deploy.
dms_build_handover "$BUILD_USER" "$BUILD_GROUP" node_modules public/build
dms_build_run "$BUILD_USER" "$BUILD_HOME" "$APP_DIR" "${APP_DIR}/node_modules ${APP_DIR}/public/build" "$BUILD_MASKS" npm ci
dms_build_run "$BUILD_USER" "$BUILD_HOME" "$APP_DIR" "${APP_DIR}/node_modules ${APP_DIR}/public/build" "$BUILD_MASKS" npm run build
dms_build_claim "$BUILD_USER" node_modules public/build
dms_build_assert_no_symlinks public/build

echo "== NSF-10 backup verify =="
as_runtime php artisan foundation:backup-verify --path="$BACKUP"

echo "== Clear stale route/config cache before route-dependent gates (ENT-8) =="
# Route-dependent governance gates (foundation:developer-console-check,
# foundation:health-check) inspect the live route table. Clearing here — before
# the gates, well before the cache-rebuild block below — makes those gates see
# the routes of the rolled-back code.
as_runtime php artisan route:clear
as_runtime php artisan config:clear

echo "== Foundation rollback governance gates (re-verify ENT-5..11) =="
as_runtime php artisan architecture:foundation-roadmap-check
as_runtime php artisan foundation:queue-retry-failed-job-check
as_runtime php artisan foundation:idempotency-outbox-check
as_runtime php artisan foundation:developer-console-check
as_runtime php artisan foundation:health-check
as_runtime php artisan foundation:security-compliance-check
as_runtime php artisan foundation:cicd-enterprise-gate-check
as_runtime php artisan foundation:deployment-rollback-check
as_runtime php artisan foundation:deployment-entrypoint-check
as_runtime php artisan foundation:release-safety-check
as_runtime php artisan architecture:foundation-governance-summary

echo "== Laravel cache rebuild =="
as_runtime php artisan optimize:clear
as_runtime php artisan config:cache
as_runtime php artisan route:cache
as_runtime php artisan view:cache
as_runtime php artisan event:cache

echo "== Permissions =="
# Ownership follows the dedicated runtime identity resolved before the checkout,
# never a hardcoded shared account.
#
# Rule 233: root only hands foreign entries (a tracked file the checkout wrote)
# to the runtime user — lchown from inside each entry's directory, never through
# a hardlink — and every MODE is set by the runtime user itself
# (dms_privsep_normalize_modes refuses root). The policy is the deploy's: the
# backup tree 2750/0640 (SECURITY-FIX-DEPLOY-BACKUP-FILE-PERMISSIONS-1), every
# declared private path never widened and stripped of "other"
# (SECURITY-F8-PRIVATE-STORAGE-PATHS-1, INFRA-SEC-RUNTIME-1), the rest 2775/0664.
read -r -a private_paths <<< "${DMS_PRIVATE_PATHS:-}"
dms_privsep_repair_runtime_ownership "$RUNTIME_USER" "$RUNTIME_GROUP" storage bootstrap/cache
dms_privsep_as_runtime_fn "$RUNTIME_USER" "" dms_privsep_normalize_modes "${private_paths[@]}"

# ─── INFRA-SEC-ENV-1 ────────────────────────────────────────────────────────
# Rolling back to an older ref must never restore a world-readable secret file.
# Re-assert the invariant here too, fail closed. The group is the dedicated
# runtime group — rolling back the code must not hand secret read back to the
# shared account.
# DEPLOY-HARDEN-1: run from the immutable snapshot (current code), so a target
# ref that predates INFRA-SEC-ENV-1 still gets the modern hardening applied.
echo "== Harden + verify secret file permissions (INFRA-SEC-ENV-1) =="
bash "${DEPLOY_TOOLS_DIR}/scripts/harden-secret-permissions.sh" apply --app-dir "$APP_DIR" --owner root --group "$RUNTIME_GROUP" --runtime-user "$RUNTIME_USER"
if ! bash "${DEPLOY_TOOLS_DIR}/scripts/harden-secret-permissions.sh" verify --app-dir "$APP_DIR" --owner root --group "$RUNTIME_GROUP" --runtime-user "$RUNTIME_USER"; then
  echo "FATAL: secret file permissions are unsafe after rollback — NOT GO" >&2
  exit 1
fi

# ─── INFRA-SEC-RUNTIME-1 ────────────────────────────────────────────────────
# A rollback must not silently drop co-tenant isolation. The verifier was staged
# before the checkout so this gate runs even when the target ref predates it.
echo "== Verify runtime identity isolation (INFRA-SEC-RUNTIME-1) =="
if ! bash "${DEPLOY_TOOLS_DIR}/scripts/verify-runtime-isolation.sh" \
      --app-dir "$APP_DIR" \
      --identity-file "${DEPLOY_TOOLS_DIR}/deploy/runtime-identity.conf" \
      --additional-forbidden-users "$([ "${DMS_R6_MIGRATOR:-0}" = 1 ] && printf '%s' "${DMS_R6_MIGRATION_OS_USER:-}")" \
      --require-host; then
  echo "FATAL: runtime identity isolation is broken after rollback — NOT GO" >&2
  exit 1
fi

# ─── DMS-DEPLOY-PRIVSEP-HARDENING-1 (rule 233) privilege drift gate ─────────
echo "== Verify privilege separation (rule 233) =="
if ! dms_privsep_final_gate 0 0 "$RUNTIME_USER" "$RUNTIME_GROUP"; then
  echo "FATAL: privilege separation is broken after the rollback — NOT GO" >&2
  exit 1
fi

echo "== Restart services =="
systemctl restart php8.3-fpm
nginx -t
systemctl reload nginx

echo "== Smoke check =="
as_runtime php artisan about
as_runtime php artisan release:automated-smoke --base-url=http://127.0.0.1

FINAL_HEAD="$(git rev-parse --verify HEAD)"
if [ "$FINAL_HEAD" != "$ROLLBACK_TARGET_SHA" ]; then
  echo "FATAL: final HEAD ${FINAL_HEAD} does not match the pinned rollback target ${ROLLBACK_TARGET_SHA} — NOT GO" >&2
  exit 1
fi
echo "ROLLBACK_HEAD_TARGET_MATCH=YES (${FINAL_HEAD})"

trap - EXIT TERM INT HUP
echo "ROLLBACK OK: rolled back to ${TARGET_REF} (from ${CURRENT_REF_DESC}) at ${STAMP}"
echo "Pre-rollback backup: ${BACKUP}"
echo "To restore data (only if required, owner-approved, as the administrator): bash scripts/restore_postgres.sh --file <backup_file> --expected-sha256 <the sha256 printed above for that backup>"
