#!/usr/bin/env bash
set -euo pipefail

# ─── INFRA-SEC-ENV-1 — Production Secret File Permission Hardening ────────────
#
# PROBLEM (production finding, 2026-08-15):
#   /var/www/asia-dental-lab-v2/.env was mode 0644 (world-readable) owned
#   root:root. The production host is SHARED (a co-tenant application and a
#   postgres service account exist), so every local account could read the
#   application secrets. No deploy step had ever asserted a secure mode: the
#   file was created once by hand under the default umask 022 and simply
#   inherited 0644 forever.
#
# FIX:
#   Enforce a least-privilege mode on every secret-bearing environment file on
#   EVERY deploy and rollback, and FAIL CLOSED when the result is unsafe.
#
# WHY 0640 AND NOT 0600 (determined from the real runtime, not assumed):
#   scripts/deploy-vps.sh rebuilds the Laravel caches AS THE PHP-FPM RUNTIME
#   USER (`as_runtime php artisan config:cache`, FIX-LOGIN-REDIRECT-RUNTIME-
#   PERMISSIONS). `config:cache` first clears the cached config, then re-boots
#   the framework to read the environment file and materialise the cache. If the
#   runtime user could not read that file, phpdotenv's safeLoad() would swallow
#   the permission error and SILENTLY produce a config cache with an empty
#   APP_KEY and empty database credentials — a total, silent outage. The runtime
#   group therefore genuinely requires read access, so 0600 is wrong here and
#   0640 (owner read/write, runtime group read, NOTHING for other) is the
#   correct least-privilege state.
#
# SAFETY CONTRACT:
#   - NEVER prints the contents of a secret file (metadata only).
#   - NEVER creates, truncates, or edits a secret file.
#   - NEVER runs a recursive chmod over the application tree.
#   - Refuses to operate on a symlinked environment file (fail closed).
#   - Idempotent: repeated runs converge on the same state.
#
# USAGE:
#   bash scripts/harden-secret-permissions.sh apply  [options]
#   bash scripts/harden-secret-permissions.sh verify [options]
#
# OPTIONS:
#   --app-dir DIR    Application root (default: script's repository root)
#   --owner USER     Expected owner  (verify: asserted; apply: chown target)
#   --group GROUP    Expected group  (verify: asserted; apply: chown target)
#
# EXIT CODES:
#   0  safe
#   1  unsafe / verification failed  (deploy MUST abort)
#   2  usage error
# ─────────────────────────────────────────────────────────────────────────────

# Canonical least-privilege modes. Runtime-readable env files may be 0640 (the
# runtime group must read them); pure backups are never read by the runtime and
# are held at 0600.
RUNTIME_ENV_MODE="640"
BACKUP_ENV_MODE="600"

# A secret file is safe only at one of these modes: no group-write, and no
# read/write/execute bit for "other" in either case.
SAFE_MODES_RUNTIME="600 640"
SAFE_MODES_BACKUP="600"

# `.env.example` ships in git and holds no secret; it is deliberately excluded.
PUBLIC_ENV_FILE=".env.example"

# Database dumps produced by the deploy/backup automation contain the ENTIRE
# clinical database (patients, national identity numbers, medical records,
# invoices). `pg_dump > file` creates them under the deploy user's umask (022 =>
# 0644) and the runtime-ownership pass then normalises every storage file to
# 0664 — both world-readable. They are hardened by stripping the "other" bits
# ONLY: owner and group are left exactly as they are, so root (backup verify,
# restore, evidence capture) and the runtime user (developer-console backup
# listing) keep the access they already had, and only unrelated local accounts
# lose it.
DATA_BACKUP_DIR="storage/app/backups"
DATA_BACKUP_MODE="640"
SAFE_MODES_DATA_BACKUP="600 640"

action="${1:-verify}"
shift || true

APP_DIR=""
EXPECT_OWNER=""
EXPECT_GROUP=""

while [ "$#" -gt 0 ]; do
  case "$1" in
    --app-dir) APP_DIR="${2:-}"; shift 2 ;;
    --owner)   EXPECT_OWNER="${2:-}"; shift 2 ;;
    --group)   EXPECT_GROUP="${2:-}"; shift 2 ;;
    *) echo "harden-secret-permissions: unknown option '$1'" >&2; exit 2 ;;
  esac
done

if [ -z "$APP_DIR" ]; then
  APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fi

if [ ! -d "$APP_DIR" ]; then
  echo "harden-secret-permissions: app dir not found: ${APP_DIR}" >&2
  exit 2
fi

failures=0

note()  { echo "secret-perm: $*"; }
fail()  { echo "secret-perm: FAIL $*" >&2; failures=$((failures + 1)); }

# Classify an environment file: "backup" files are never read by the runtime,
# everything else may be loaded by the framework and needs runtime-group read.
classify() {
  case "$(basename "$1")" in
    .env.bak*|.env.backup*|.env.save*|.env.old*|.env.orig*|*.bak) echo "backup" ;;
    *) echo "runtime" ;;
  esac
}

target_mode() {
  if [ "$(classify "$1")" = "backup" ]; then echo "$BACKUP_ENV_MODE"; else echo "$RUNTIME_ENV_MODE"; fi
}

safe_modes() {
  if [ "$(classify "$1")" = "backup" ]; then echo "$SAFE_MODES_BACKUP"; else echo "$SAFE_MODES_RUNTIME"; fi
}

# Enumerate secret-bearing environment files at the application root only
# (maxdepth 1). Never follows symlinks; never descends into vendor/storage.
# Restricted to regular files and symlinks so a directory that happens to match
# the pattern is never chmod'ed (removing +x from a directory breaks traversal).
list_secret_files() {
  find "$APP_DIR" -maxdepth 1 \
    \( -type f -o -type l \) \
    \( -name '.env' -o -name '.env.*' \) \
    ! -name "$PUBLIC_ENV_FILE" \
    -print 2>/dev/null | sort
}

# Database dumps / runtime archives produced by the deploy and backup automation.
list_data_backups() {
  [ -d "${APP_DIR}/${DATA_BACKUP_DIR}" ] || return 0
  find "${APP_DIR}/${DATA_BACKUP_DIR}" -type f \
    \( -name '*.sql' -o -name '*.sql.gz' -o -name '*.dump' -o -name '*.tar.gz' \) \
    -print 2>/dev/null | sort
}

# Strip the world/"other" bits from a data backup without touching owner/group,
# so every account that legitimately reads it today keeps working.
harden_data_backup() {
  local file="$1" actual
  actual="$(stat -c '%a' "$file")"
  if [ "$actual" != "$DATA_BACKUP_MODE" ] && [ "$actual" != "600" ]; then
    chmod "0${DATA_BACKUP_MODE}" "$file"
  fi
}

verify_data_backup() {
  local file="$1" actual ok
  actual="$(stat -c '%a' "$file")"
  ok=0
  for m in $SAFE_MODES_DATA_BACKUP; do
    if [ "$actual" = "$m" ]; then
      ok=1
    fi
  done
  if [ "$ok" -ne 1 ]; then
    fail "$(basename "$file") database backup mode 0${actual} is world-readable (allowed: ${SAFE_MODES_DATA_BACKUP// /, })"
  fi
}

apply_one() {
  local file="$1" mode owner group

  if [ -L "$file" ]; then
    fail "$(basename "$file") is a symlink — refusing to harden a symlinked secret file"
    return
  fi

  mode="$(target_mode "$file")"

  # chown requires privilege; skip cleanly when unprivileged (local/CI runs)
  # rather than aborting, but ALWAYS enforce the mode.
  if [ "$(id -u)" = "0" ] && { [ -n "$EXPECT_OWNER" ] || [ -n "$EXPECT_GROUP" ]; }; then
    owner="${EXPECT_OWNER:-$(stat -c '%U' "$file")}"
    group="${EXPECT_GROUP:-$(stat -c '%G' "$file")}"
    chown "${owner}:${group}" "$file"
  fi

  chmod "0${mode}" "$file"
  note "applied 0${mode} to $(basename "$file")"
}

verify_one() {
  local file="$1" actual owner group allowed ok

  if [ -L "$file" ]; then
    fail "$(basename "$file") is a symlink — a symlinked secret file can bypass mode verification"
    return
  fi

  actual="$(stat -c '%a' "$file")"
  owner="$(stat -c '%U' "$file")"
  group="$(stat -c '%G' "$file")"
  allowed="$(safe_modes "$file")"

  # Explicit if/then (not `[ ] && ok=1`) so the loop's exit status can never
  # interact with `set -e` — a security gate must not depend on that subtlety.
  ok=0
  for m in $allowed; do
    if [ "$actual" = "$m" ]; then
      ok=1
    fi
  done

  if [ "$ok" -ne 1 ]; then
    fail "$(basename "$file") mode 0${actual} is unsafe (allowed: ${allowed// /, }) — world/group exposure of a secret file"
  fi

  if [ -n "$EXPECT_OWNER" ] && [ "$owner" != "$EXPECT_OWNER" ]; then
    fail "$(basename "$file") owner '${owner}' != expected '${EXPECT_OWNER}'"
  fi

  if [ -n "$EXPECT_GROUP" ] && [ "$(classify "$file")" = "runtime" ] && [ "$group" != "$EXPECT_GROUP" ]; then
    fail "$(basename "$file") group '${group}' != expected runtime group '${EXPECT_GROUP}'"
  fi

  # ACLs can grant read access that the Unix mode alone does not reveal.
  if command -v getfacl >/dev/null 2>&1; then
    if getfacl -p --omit-header "$file" 2>/dev/null | grep -qE '^(user|group):[^:]+:.*r'; then
      fail "$(basename "$file") has a named-user/named-group ACL granting read access"
    fi
  fi

  note "verified $(basename "$file") mode=0${actual} owner=${owner} group=${group}"
}

case "$action" in
  apply)
    found=0
    while IFS= read -r file; do
      [ -n "$file" ] || continue
      found=1
      apply_one "$file"
    done <<< "$(list_secret_files)"
    [ "$found" = "1" ] || note "no environment file present in ${APP_DIR} — nothing to harden"

    hardened_backups=0
    while IFS= read -r file; do
      [ -n "$file" ] || continue
      harden_data_backup "$file"
      hardened_backups=$((hardened_backups + 1))
    done <<< "$(list_data_backups)"
    [ "$hardened_backups" -eq 0 ] || note "applied 0${DATA_BACKUP_MODE} to ${hardened_backups} database backup file(s)"
    ;;
  verify) ;;
  *)
    echo "usage: bash scripts/harden-secret-permissions.sh {apply|verify} [--app-dir DIR] [--owner USER] [--group GROUP]" >&2
    exit 2
    ;;
esac

# Verification always runs, for both actions, so `apply` can never report
# success on a state it did not actually reach.
verified=0
while IFS= read -r file; do
  [ -n "$file" ] || continue
  verified=$((verified + 1))
  verify_one "$file"
done <<< "$(list_secret_files)"

while IFS= read -r file; do
  [ -n "$file" ] || continue
  verify_data_backup "$file"
done <<< "$(list_data_backups)"

if [ "$verified" -eq 0 ]; then
  # Not a failure here: a missing environment file is the deploy's own concern
  # (scripts/deploy-vps.sh aborts under `set -e` when it cannot source it). This
  # script never creates a replacement.
  note "no environment file found in ${APP_DIR} — nothing to verify"
fi

if [ "$failures" -ne 0 ]; then
  echo "SECRET FILE PERMISSIONS: FAIL (${failures}) — NOT GO" >&2
  exit 1
fi

echo "SECRET FILE PERMISSIONS: GO"
