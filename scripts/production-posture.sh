#!/usr/bin/env bash
#
# DMS-DEPLOY-PRIVSEP-PHASE-2 (rule 235) — READ-ONLY production posture.
#
# What a deploy or a verification needs to see on the host, without a shell and
# without general root: the host, the checkout's HEAD and branch, whether a
# tracked file is modified, the application's units and timers, whether a
# release lock is held, and the count (never the content) of ERROR lines in
# the application log. The deploy operator reaches it through its entrypoint
# (`posture`); the administrator may run it directly as root.
#
# It never creates, changes or deletes anything, prints no secret and no log
# line, and runs no PHP. The log is read by the runtime user that owns it, so
# root never opens a path inside a runtime tree (rule 233).
set -euo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SELF_DIR}/.." && pwd)"
LOCK_DIR="/run/daengtisiams-deploy"

[ "$#" -eq 0 ] || { echo "usage: bash scripts/production-posture.sh   (no arguments)" >&2; exit 2; }
[ "$(id -u)" = "0" ] || { echo "production-posture: run as root (through the deploy operator entrypoint)" >&2; exit 2; }

identity_value() {
  sed -n "s/^[[:space:]]*$1=//p" "${APP_DIR}/deploy/runtime-identity.conf" | head -n 1 | sed 's/^"//; s/"$//'
}
RUNTIME_USER="$(identity_value DMS_RUNTIME_USER)"

echo "== Production posture (rule 235, read-only) =="
echo "HOST=$(hostname)"
echo "ADDRESSES=$(hostname -I 2>/dev/null || true)"
echo "HEAD=$(git -C "$APP_DIR" rev-parse --verify HEAD)"
echo "BRANCH=$(git -C "$APP_DIR" branch --show-current)"
echo "TRACKED_MODIFIED=$(git -C "$APP_DIR" status --porcelain --untracked-files=no | wc -l)"

for unit in php8.3-fpm nginx postgresql daengtisiams-queue-worker.service; do
  echo "UNIT ${unit} active=$(systemctl is-active "$unit" 2>/dev/null || true) enabled=$(systemctl is-enabled "$unit" 2>/dev/null || true)"
done
for timer in daengtisiams-db-backup.timer daengtisiams-bk2-package.timer daengtisiams-ktp-temp-prune.timer daengtisiams-pilot-snapshot.timer; do
  echo "TIMER ${timer} active=$(systemctl is-active "$timer" 2>/dev/null || true) enabled=$(systemctl is-enabled "$timer" 2>/dev/null || true)"
done

for lock in deploy release rollback; do
  if [ ! -e "${LOCK_DIR}/${lock}.lock" ]; then
    state="ABSENT"
  elif fuser -s "${LOCK_DIR}/${lock}.lock" 2>/dev/null; then
    state="HELD"
  else
    state="IDLE"
  fi
  echo "LOCK ${lock}=${state}"
done

if [ -n "$RUNTIME_USER" ] && [ "$RUNTIME_USER" != "root" ] && id -u -- "$RUNTIME_USER" >/dev/null 2>&1; then
  errors="$(runuser -u "$RUNTIME_USER" -- grep -c '\.ERROR:' "${APP_DIR}/storage/logs/laravel.log" </dev/null 2>/dev/null || true)"
  echo "LARAVEL_LOG_ERROR_LINES=${errors:-unreadable}"
else
  echo "LARAVEL_LOG_ERROR_LINES=unreadable (no runtime identity declared)"
fi
