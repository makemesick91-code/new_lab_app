#!/usr/bin/env bash
#
# DMS-DEPLOY-PRIVSEP-PHASE-2 (rule 235) — rehearse the unprivileged build on the
# production host WITHOUT touching the application.
#
# Runs exactly the deploy's build sequence (scripts/lib/build-privsep.sh) on a
# scratch copy of one commit: the commit is exported from the application's git
# objects (read-only) into a fresh root-owned workspace, the build trees are
# handed to the build identity, `composer install --no-scripts`, `npm ci` and
# `npm run build` run in the sandboxed transient service, the trees are claimed
# back, and the workspace is removed. A root-only placeholder environment file
# in the workspace proves the sandbox masks environment files (the build would
# fail on it otherwise). The live checkout, vendor/, node_modules/, public/build,
# storage/ and the database are never touched; no PHP of the application runs.
#
#   sudo bash scripts/build-privsep-rehearsal.sh --ref <40-hex commit>
#
# Exit 0 and BUILD_PRIVSEP_REHEARSAL=PASS when every step passed; 1 otherwise;
# 2 on usage.
set -euo pipefail
umask 022

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AUTHORITY="${SELF_DIR}/../deploy/production-host.conf"
REF=""

usage() { echo "usage: sudo bash scripts/build-privsep-rehearsal.sh --ref <40-hex commit>" >&2; exit 2; }
while [ "$#" -gt 0 ]; do
  case "$1" in
    --ref) [ "$#" -ge 2 ] || usage; REF="$2"; shift 2 ;;
    *) usage ;;
  esac
done
[[ "$REF" =~ ^[0-9a-f]{40}$ ]] || usage
[ "$(id -u)" = "0" ] || { echo "build-privsep-rehearsal: run as root on the production host" >&2; exit 1; }

# shellcheck source=lib/production-host-preflight.sh
. "${SELF_DIR}/lib/production-host-preflight.sh"
# shellcheck source=lib/build-privsep.sh
. "${SELF_DIR}/lib/build-privsep.sh"

dms_production_host_preflight "$AUTHORITY" "$(id -u)" "$(hostname)" "$(hostname -I 2>/dev/null || true)" \
  git tar systemd-run composer npm node pgrep || exit 1

value() { dms_preflight_authority_value "$AUTHORITY" "$1"; }
APP_DIR="$(value DMS_PRODUCTION_APP_DIR)"
BUILD_USER="$(value DMS_PRODUCTION_BUILD_USER)"
BUILD_HOME="$(value DMS_PRODUCTION_BUILD_HOME)"
identity_value() { sed -n "s/^[[:space:]]*$1=//p" "${APP_DIR}/deploy/runtime-identity.conf" | head -n 1 | sed 's/^"//; s/"$//'; }
RUNTIME_USER="$(identity_value DMS_RUNTIME_USER)"
RUNTIME_GROUP="$(identity_value DMS_RUNTIME_GROUP)"

echo "== Build identity =="
dms_build_identity_check "$BUILD_USER" "$BUILD_HOME" "$RUNTIME_USER" "$RUNTIME_GROUP"
BUILD_GROUP="$(id -gn -- "$BUILD_USER")"
git -C "$APP_DIR" cat-file -e "${REF}^{commit}" || { echo "build-privsep-rehearsal: commit ${REF} is not in ${APP_DIR}'s objects (fetch the base branch's refs first)" >&2; exit 1; }

# Under /var/lib (root's): the sandbox's PrivateTmp hides /tmp and /var/tmp.
WORK="$(mktemp -d /var/lib/dms-build-rehearsal.XXXXXX)"
chmod 0755 -- "$WORK"
cleanup() {
  # No build process can be alive (each step's cgroup is killed when it ends);
  # the workspace is removed whatever happened, on this file system only.
  rm -rf --one-file-system -- "$WORK"
}
trap cleanup EXIT

echo "== Export ${REF} into the scratch workspace (read-only on the repository) =="
git -C "$APP_DIR" archive "$REF" | tar -x --no-same-owner --no-same-permissions -C "$WORK"
# A root-only placeholder environment file: the build identity cannot read it,
# so vite would fail on it unless the sandbox masks it with /dev/null.
( umask 077 && printf 'REHEARSAL_PLACEHOLDER=1\n' > "${WORK}/.env" )

cd "$WORK"
MASKS="$(dms_build_env_masks "$WORK")"
started="$(date +%s)"

echo "== composer install (build identity, sandboxed) =="
dms_build_handover "$BUILD_USER" "$BUILD_GROUP" vendor
dms_build_run "$BUILD_USER" "$BUILD_HOME" "$WORK" "${WORK}/vendor" "$MASKS" composer install --no-dev --optimize-autoloader --no-scripts --no-interaction
dms_build_claim "$BUILD_USER" vendor
composer_done="$(date +%s)"

echo "== npm ci + npm run build (build identity, sandboxed) =="
dms_build_handover "$BUILD_USER" "$BUILD_GROUP" node_modules public/build
dms_build_run "$BUILD_USER" "$BUILD_HOME" "$WORK" "${WORK}/node_modules ${WORK}/public/build" "$MASKS" npm ci
dms_build_run "$BUILD_USER" "$BUILD_HOME" "$WORK" "${WORK}/node_modules ${WORK}/public/build" "$MASKS" npm run build
dms_build_claim "$BUILD_USER" node_modules public/build
dms_build_assert_no_symlinks public/build
npm_done="$(date +%s)"

echo "== Results =="
dms_build_no_processes "$BUILD_USER"
echo "BUILD_PROCESSES_LEFT=0"
for tree in vendor node_modules public/build; do
  echo "TREE ${tree} entries=$(find "$tree" -xdev | wc -l) not_root_or_writable=$(find "$tree" -xdev \( ! -uid 0 -o ! -gid 0 -o \( ! -type l -perm /022 \) \) | wc -l)"
done
[ -s public/build/manifest.json ] || { echo "build-privsep-rehearsal: no public/build/manifest.json" >&2; exit 1; }
rehearsed="$(sha256sum < public/build/manifest.json | cut -d' ' -f1)"
live="$(sha256sum < "${APP_DIR}/public/build/manifest.json" | cut -d' ' -f1)"
echo "MANIFEST_SHA256 rehearsal=${rehearsed} live=${live}"
if [ "$rehearsed" = "$live" ]; then
  echo "BUILD_OUTPUT_IDENTICAL_TO_LIVE=YES"
else
  echo "BUILD_OUTPUT_IDENTICAL_TO_LIVE=NO (expected only when ${REF} changes the frontend sources)"
fi
echo "DURATION composer=$((composer_done - started))s npm=$((npm_done - composer_done))s"
echo "BUILD_PRIVSEP_REHEARSAL=PASS"
