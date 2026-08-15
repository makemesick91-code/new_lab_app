#!/usr/bin/env bash
#
# INFRA-SEC-RUNTIME-1 — provision the dedicated DaengtisiaMS runtime identity.
#
# Moves DaengtisiaMS off the shared www-data account onto its own unprivileged
# Unix identity, so that the co-tenant application on this VPS can no longer read
# DaengtisiaMS secrets or private clinical storage by virtue of sharing a uid.
#
# WHAT IT TOUCHES
#   - creates a dedicated system user + group (no login, no sudo, no home)
#   - installs a dedicated PHP-FPM pool and retires the shared default pool
#   - rebinds ONLY the fastcgi_pass of the DaengtisiaMS nginx site, in place
#   - moves ownership of the runtime-writable paths (storage, bootstrap/cache)
#   - moves the environment file group (via the INFRA-SEC-ENV-1 helper — this
#     script never chmods a secret itself)
#   - runs the DaengtisiaMS systemd units under the dedicated identity
#
# WHAT IT NEVER DOES
#   - never runs a database command of any kind
#   - never chowns the application source tree (source stays root/deploy owned
#     and read-only to the runtime — that boundary is the point)
#   - never touches the co-tenant application's pool, socket, files or data
#   - never prints the contents of a secret or a patient file
#   - never uses a world-writable mode
#
# SAFETY
#   - Dry run by DEFAULT. Nothing is changed without --apply.
#   - Idempotent: re-running converges, it never duplicates a user/group/pool.
#   - Fail closed: any validation failure aborts before the cutover.
#   - Both php-fpm and nginx configurations are syntax-checked BEFORE any reload.
#   - The cutover runs inside Laravel maintenance mode, and an EXIT trap lifts
#     maintenance again even if a later step fails.
#
# Usage:
#   bash scripts/provision-runtime-identity.sh                 # dry run
#   bash scripts/provision-runtime-identity.sh --apply         # provision
#   bash scripts/provision-runtime-identity.sh --rollback --apply
#
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
IDENTITY_FILE="${REPO_DIR}/deploy/runtime-identity.conf"
STAMP="$(date +%Y%m%d-%H%M%S)"
APPLY=0
MODE="provision"

while [ $# -gt 0 ]; do
  case "$1" in
    --apply) APPLY=1; shift ;;
    --dry-run) APPLY=0; shift ;;
    --rollback) MODE="rollback"; shift ;;
    --identity-file) IDENTITY_FILE="${2:?--identity-file needs a value}"; shift 2 ;;
    -h|--help) sed -n '2,40p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "provision-runtime-identity: unknown option '$1'" >&2; exit 2 ;;
  esac
done

[ -r "$IDENTITY_FILE" ] || { echo "FATAL: identity authority unreadable: ${IDENTITY_FILE}" >&2; exit 2; }
# shellcheck disable=SC1090
. "$IDENTITY_FILE"

RUNTIME_USER="${DMS_RUNTIME_USER:?identity authority must declare DMS_RUNTIME_USER}"
RUNTIME_GROUP="${DMS_RUNTIME_GROUP:?identity authority must declare DMS_RUNTIME_GROUP}"
APP_DIR="${DMS_APP_DIR:?identity authority must declare DMS_APP_DIR}"

run() {
  if [ "$APPLY" -eq 1 ]; then
    "$@"
  else
    echo "    DRY-RUN: $*"
  fi
}
step() { echo "== $1 =="; }
note() { echo "    $1"; }
die()  { echo "FATAL: $1" >&2; exit 1; }

# ── Preflight ───────────────────────────────────────────────────────────────
step "Preflight"

[ "$(id -u)" -eq 0 ] || die "must run as root (creates a system account and writes to /etc)"
[ -d "$APP_DIR" ] || die "application directory not found: ${APP_DIR}"

for forbidden in ${DMS_FORBIDDEN_RUNTIME_USERS:-root www-data}; do
  [ "$RUNTIME_USER" != "$forbidden" ] || die "declared runtime user '${RUNTIME_USER}' is forbidden"
done
note "target identity: ${RUNTIME_USER}:${RUNTIME_GROUP}"
note "mode: ${MODE} ($([ "$APPLY" -eq 1 ] && echo APPLY || echo DRY-RUN))"

command -v useradd  >/dev/null 2>&1 || die "useradd unavailable"
command -v groupadd >/dev/null 2>&1 || die "groupadd unavailable"
command -v runuser  >/dev/null 2>&1 || die "runuser unavailable"

FPM_BIN="php-fpm${DMS_FPM_PHP_VERSION}"
command -v "$FPM_BIN" >/dev/null 2>&1 || die "${FPM_BIN} unavailable"
command -v nginx >/dev/null 2>&1 || die "nginx unavailable"

POOL_SOURCE="${REPO_DIR}/deploy/php-fpm/${DMS_FPM_POOL}.conf"
UNIT_SOURCE="${REPO_DIR}/${DMS_QUEUE_UNIT_SOURCE}"
[ -r "$POOL_SOURCE" ] || die "pool source missing: ${POOL_SOURCE}"
[ -r "$UNIT_SOURCE" ] || die "queue unit source missing: ${UNIT_SOURCE}"
[ -r "$DMS_NGINX_SITE" ] || die "nginx site missing: ${DMS_NGINX_SITE}"

# A pre-existing account with an unexpected purpose must never be co-opted.
if getent passwd "$RUNTIME_USER" >/dev/null 2>&1; then
  EXISTING_SHELL="$(getent passwd "$RUNTIME_USER" | awk -F: '{print $7}')"
  case "$EXISTING_SHELL" in
    */nologin|*/false) note "account '${RUNTIME_USER}' already exists (no-login) — will converge" ;;
    *) die "account '${RUNTIME_USER}' already exists with interactive shell '${EXISTING_SHELL}' — refusing to co-opt an unrelated account" ;;
  esac
fi

# ── Rollback path ───────────────────────────────────────────────────────────
# Operational recovery only. It restores the previous SHARED-identity runtime so
# the site can serve, and it deliberately does NOT restore a world-readable
# secret: the INFRA-SEC-ENV-1 invariant (owner root, mode 0640, never 0644)
# survives a rollback. Reaching this path means INFRA-SEC-RUNTIME-1 is WATCH/NO-GO,
# never GO.
if [ "$MODE" = "rollback" ]; then
  step "Rollback to the previous shared runtime identity"
  echo "    WARNING: this restores the shared www-data runtime. Co-tenant same-uid"
  echo "             isolation is NOT provided in this state. INFRA-SEC-RUNTIME-1"
  echo "             must be classified WATCH / NO-GO until re-established."

  PREV_USER="${DMS_NGINX_CONNECT_USER:-www-data}"
  PREV_GROUP="${DMS_NGINX_CONNECT_GROUP:-www-data}"

  ( cd "$APP_DIR" && run php artisan down --retry=30 ) || true

  step "Restore the default shared pool"
  if [ -n "${DMS_FPM_DEFAULT_POOL_FILE:-}" ] && [ -e "${DMS_FPM_DEFAULT_POOL_FILE}.disabled" ]; then
    run mv "${DMS_FPM_DEFAULT_POOL_FILE}.disabled" "${DMS_FPM_DEFAULT_POOL_FILE}"
  fi
  if [ -e "$DMS_FPM_POOL_FILE" ]; then
    run mv "$DMS_FPM_POOL_FILE" "${DMS_FPM_POOL_FILE}.rolled-back-${STAMP}"
  fi
  run "$FPM_BIN" -t

  step "Restore nginx binding"
  LATEST_BACKUP="$(ls -1t "${DMS_NGINX_SITE}".infra-sec-runtime-1.* 2>/dev/null | head -1 || true)"
  if [ -n "$LATEST_BACKUP" ]; then
    run cp -p "$LATEST_BACKUP" "$DMS_NGINX_SITE"
  else
    note "no provisioning backup found — rewriting fastcgi_pass to the default socket"
    run sed -i -E "s#fastcgi_pass unix:${DMS_FPM_SOCKET};#fastcgi_pass unix:/run/php/php${DMS_FPM_PHP_VERSION}-fpm.sock;#" "$DMS_NGINX_SITE"
  fi
  run nginx -t

  step "Restore service identities"
  for unit in "$DMS_QUEUE_SERVICE" ${DMS_BACKGROUND_SERVICES:-}; do
    [ -n "$unit" ] || continue
    [ -e "/etc/systemd/system/${unit}" ] || continue
    run mkdir -p "/etc/systemd/system/${unit}.d"
    if [ "$APPLY" -eq 1 ]; then
      printf '[Service]\nUser=%s\nGroup=%s\n' "$PREV_USER" "$PREV_GROUP" \
        > "/etc/systemd/system/${unit}.d/00-runtime-identity-rollback.conf"
    else
      echo "    DRY-RUN: write /etc/systemd/system/${unit}.d/00-runtime-identity-rollback.conf (User=${PREV_USER})"
    fi
  done
  run systemctl daemon-reload

  step "Restore ownership + secret group"
  for rel in ${DMS_RUNTIME_WRITABLE_PATHS:-}; do
    [ -e "${APP_DIR}/${rel}" ] || continue
    run chown -R "${PREV_USER}:${PREV_GROUP}" "${APP_DIR}/${rel}"
  done
  # INFRA-SEC-ENV-1 stays enforced: owner root, mode 0640, never world-readable.
  ( cd "$REPO_DIR" && run bash scripts/harden-secret-permissions.sh apply \
      --app-dir "$APP_DIR" --owner root --group "$PREV_GROUP" )

  step "Reload services"
  run systemctl reload "$DMS_FPM_SERVICE"
  run systemctl reload nginx
  run systemctl restart "$DMS_QUEUE_SERVICE"
  ( cd "$APP_DIR" && run php artisan up ) || true

  echo
  echo "RUNTIME IDENTITY ROLLBACK COMPLETE (${STAMP})"
  echo "State: shared ${PREV_USER} runtime restored. INFRA-SEC-RUNTIME-1 = WATCH / NO-GO."
  exit 0
fi

# ── 1. Dedicated system account ─────────────────────────────────────────────
step "Dedicated system account"

if getent group "$RUNTIME_GROUP" >/dev/null 2>&1; then
  note "group '${RUNTIME_GROUP}' already present"
else
  run groupadd --system "$RUNTIME_GROUP"
  note "created system group '${RUNTIME_GROUP}'"
fi

if getent passwd "$RUNTIME_USER" >/dev/null 2>&1; then
  note "user '${RUNTIME_USER}' already present"
else
  # System account: no password, no home, no interactive shell, no extra groups.
  run useradd --system \
    --gid "$RUNTIME_GROUP" \
    --no-create-home \
    --home-dir /nonexistent \
    --shell /usr/sbin/nologin \
    --comment "DaengtisiaMS dedicated application runtime" \
    "$RUNTIME_USER"
  note "created system user '${RUNTIME_USER}'"
fi

if [ "$APPLY" -eq 1 ]; then
  # The co-tenant identity must never gain membership of the runtime group, and
  # the runtime must never hold an administrative group.
  CO_TENANT="${DMS_NGINX_CONNECT_USER:-www-data}"
  if id -nG "$CO_TENANT" 2>/dev/null | tr ' ' '\n' | grep -qx "$RUNTIME_GROUP"; then
    die "'${CO_TENANT}' is a member of '${RUNTIME_GROUP}' — that re-opens co-tenant read; remove it first"
  fi
  for g in sudo docker adm staff admin; do
    if id -nG "$RUNTIME_USER" 2>/dev/null | tr ' ' '\n' | grep -qx "$g"; then
      die "'${RUNTIME_USER}' holds privileged group '${g}' — refusing to provision a privileged runtime"
    fi
  done
  note "least-privilege membership verified"
fi

# ── 2. Dedicated PHP-FPM pool ───────────────────────────────────────────────
step "Dedicated PHP-FPM pool"

if [ -e "$DMS_FPM_POOL_FILE" ] && cmp -s "$POOL_SOURCE" "$DMS_FPM_POOL_FILE"; then
  note "pool already converged (${DMS_FPM_POOL_FILE})"
else
  run install -o root -g root -m 0644 "$POOL_SOURCE" "$DMS_FPM_POOL_FILE"
  note "installed pool -> ${DMS_FPM_POOL_FILE}"
fi

# Validate with BOTH pools present before retiring the shared one.
run "$FPM_BIN" -t
note "php-fpm configuration syntax: PASS"

# ── 3. Stage the nginx rebinding (validated, not yet reloaded) ───────────────
step "nginx binding"

CURRENT_BIND="$(grep -E '^[[:space:]]*fastcgi_pass' "$DMS_NGINX_SITE" | head -1 | sed -E 's/.*fastcgi_pass[[:space:]]+//; s/;[[:space:]]*$//' | tr -d '[:space:]')"
note "current fastcgi_pass: ${CURRENT_BIND}"

if [ "$CURRENT_BIND" = "unix:${DMS_FPM_SOCKET}" ]; then
  note "already bound to the dedicated socket"
else
  run cp -p "$DMS_NGINX_SITE" "${DMS_NGINX_SITE}.infra-sec-runtime-1.${STAMP}"
  # Rewrite ONLY the fastcgi_pass target. `listen 80 default_server` — which
  # keeps DaengtisiaMS reachable on this shared host — is preserved untouched.
  run sed -i -E "s#^([[:space:]]*)fastcgi_pass[[:space:]]+[^;]+;#\\1fastcgi_pass unix:${DMS_FPM_SOCKET};#" "$DMS_NGINX_SITE"
  run nginx -t
  note "nginx configuration syntax: PASS"
fi

# ── 4. Cutover (inside maintenance mode) ────────────────────────────────────
step "Cutover"

MAINTENANCE_ON=0
lift_maintenance() {
  if [ "$MAINTENANCE_ON" -eq 1 ]; then
    ( cd "$APP_DIR" && php artisan up ) || echo "WARNING: could not lift maintenance mode — run 'php artisan up' manually" >&2
    MAINTENANCE_ON=0
  fi
}
trap lift_maintenance EXIT

if [ "$APPLY" -eq 1 ]; then
  ( cd "$APP_DIR" && php artisan down --retry=30 ) && MAINTENANCE_ON=1
  note "maintenance mode ON"
else
  echo "    DRY-RUN: php artisan down / up around the cutover"
fi

# 4a. Ownership of the runtime-writable paths ONLY. The application source tree
#     is deliberately left root/deploy owned so the runtime cannot rewrite code.
for rel in ${DMS_RUNTIME_WRITABLE_PATHS:-}; do
  p="${APP_DIR}/${rel}"
  [ -e "$p" ] || { note "skip missing ${rel}"; continue; }
  run chown -R "${RUNTIME_USER}:${RUNTIME_GROUP}" "$p"
  # setgid directories so new files inherit the runtime group. Never 0777.
  run find "$p" -type d -exec chmod 2775 {} +
  run find "$p" -type f -exec chmod 0664 {} +
  note "ownership: ${rel} -> ${RUNTIME_USER}:${RUNTIME_GROUP}"
done

# 4b. Private clinical storage must not be world-readable now that a distinct
#     uid exists on this host that has no business reading it.
for rel in ${DMS_PRIVATE_PATHS:-}; do
  p="${APP_DIR}/${rel}"
  [ -d "$p" ] || continue
  run chmod -R o-rwx "$p"
  note "private: ${rel} world access removed"
done

# 4c. Secret group transition — delegated to the INFRA-SEC-ENV-1 helper, which
#     owns the secret-permission invariant. This script never chmods a secret.
( cd "$REPO_DIR" && run bash scripts/harden-secret-permissions.sh apply \
    --app-dir "$APP_DIR" --owner root --group "$RUNTIME_GROUP" )
note "secret files: root:${RUNTIME_GROUP} 0640 (via INFRA-SEC-ENV-1 helper)"

# 4d. Database dumps outside the storage tree. These contain the entire clinical
#     database; the application runtime has no reason to read them and neither
#     does the co-tenant.
if [ -d "${APP_DIR}/backups" ]; then
  run find "${APP_DIR}/backups" -maxdepth 1 -type f -name '*.sql' -exec chown root:root {} +
  run find "${APP_DIR}/backups" -maxdepth 1 -type f -name '*.sql' -exec chmod 0640 {} +
  note "legacy database dumps under backups/ restricted to root"
fi

# 4e. Retire the shared default pool, then reload both services back to back.
if [ -n "${DMS_FPM_DEFAULT_POOL_FILE:-}" ] && [ -e "$DMS_FPM_DEFAULT_POOL_FILE" ]; then
  run mv "$DMS_FPM_DEFAULT_POOL_FILE" "${DMS_FPM_DEFAULT_POOL_FILE}.disabled"
  note "retired shared default pool -> ${DMS_FPM_DEFAULT_POOL_FILE}.disabled"
  run "$FPM_BIN" -t
fi

run systemctl reload "$DMS_FPM_SERVICE"
run nginx -t
run systemctl reload nginx
note "php-fpm + nginx reloaded onto the dedicated socket"

# ── 5. Service identities ───────────────────────────────────────────────────
step "Service identities"

# Any rollback override from a previous recovery must be removed, or it would
# silently pin the units back to the shared account.
for unit in "$DMS_QUEUE_SERVICE" ${DMS_BACKGROUND_SERVICES:-}; do
  [ -n "$unit" ] || continue
  ROLLBACK_DROPIN="/etc/systemd/system/${unit}.d/00-runtime-identity-rollback.conf"
  if [ -e "$ROLLBACK_DROPIN" ]; then
    run rm -f "$ROLLBACK_DROPIN"
    note "removed stale rollback override for ${unit}"
  fi
done

if ! cmp -s "$UNIT_SOURCE" "/etc/systemd/system/${DMS_QUEUE_SERVICE}"; then
  run install -o root -g root -m 0644 "$UNIT_SOURCE" "/etc/systemd/system/${DMS_QUEUE_SERVICE}"
  note "installed ${DMS_QUEUE_SERVICE} from the tracked source"
fi

# Background units whose unit file lives only on the host get a tracked drop-in
# rather than an untracked in-place edit.
for unit in ${DMS_BACKGROUND_SERVICES:-}; do
  [ -e "/etc/systemd/system/${unit}" ] || { note "skip absent unit ${unit}"; continue; }
  run mkdir -p "/etc/systemd/system/${unit}.d"
  if [ "$APPLY" -eq 1 ]; then
    printf '# INFRA-SEC-RUNTIME-1 dedicated runtime identity.\n[Service]\nUser=%s\nGroup=%s\n' \
      "$RUNTIME_USER" "$RUNTIME_GROUP" > "/etc/systemd/system/${unit}.d/10-runtime-identity.conf"
  else
    echo "    DRY-RUN: write /etc/systemd/system/${unit}.d/10-runtime-identity.conf (User=${RUNTIME_USER})"
  fi
  note "background unit ${unit} pinned to ${RUNTIME_USER}"
done

run systemctl daemon-reload
run systemctl restart "$DMS_QUEUE_SERVICE"
note "queue worker restarted under ${RUNTIME_USER}"

# ── 6. Rebuild the Laravel cache as the new runtime identity ────────────────
step "Runtime cache rebuild"
# Cache artefacts written by a previous identity would be unwritable to the new
# one. Rebuilding AS the runtime user is the same rule
# FIX-LOGIN-REDIRECT-RUNTIME-PERMISSIONS established.
( cd "$APP_DIR" && run runuser -u "$RUNTIME_USER" -- php artisan optimize:clear )
( cd "$APP_DIR" && run runuser -u "$RUNTIME_USER" -- php artisan config:cache )
( cd "$APP_DIR" && run runuser -u "$RUNTIME_USER" -- php artisan route:cache )
( cd "$APP_DIR" && run runuser -u "$RUNTIME_USER" -- php artisan view:cache )
( cd "$APP_DIR" && run runuser -u "$RUNTIME_USER" -- php artisan event:cache )

lift_maintenance
trap - EXIT

# ── 7. Verify ───────────────────────────────────────────────────────────────
step "Verification"
if [ "$APPLY" -eq 1 ]; then
  bash "${SCRIPT_DIR}/verify-runtime-isolation.sh" --app-dir "$APP_DIR" --identity-file "$IDENTITY_FILE" --require-host
else
  echo "    DRY-RUN: bash scripts/verify-runtime-isolation.sh --require-host"
fi

echo
echo "RUNTIME IDENTITY PROVISIONING COMPLETE (${STAMP})"
echo "  runtime : ${RUNTIME_USER}:${RUNTIME_GROUP}"
echo "  pool    : ${DMS_FPM_POOL} (${DMS_FPM_POOL_FILE})"
echo "  socket  : ${DMS_FPM_SOCKET}"
