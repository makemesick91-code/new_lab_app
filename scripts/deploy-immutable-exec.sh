#!/usr/bin/env bash
#
# DEPLOY-HARDEN-1 — Immutable Deployment Entrypoint & Self-Update Safety.
#
# THE DEFECT THIS CLOSES
# ----------------------
# scripts/deploy-vps.sh used to be executed straight out of the working tree
# while, roughly halfway through its own body, it ran `git checkout` +
# `git pull --ff-only`. bash does not slurp a script: it reads it incrementally
# and keeps a byte offset into the open file. Rewriting those bytes underneath
# a running interpreter is undefined behaviour — the shell can resume mid-token
# at a stale offset and execute a line no author ever wrote. The same applied to
# every helper the deploy invoked AFTER the pull (harden-secret-permissions.sh,
# verify-runtime-isolation.sh): those were re-read from the tree the deploy had
# just rewritten. Two releases had to be rescued with a manual pre-pull. A
# manual pre-pull is a workaround, not an architecture.
#
# THE INVARIANT
# -------------
#   RUNNING_DEPLOY_PROGRAM != MUTABLE_REPOSITORY_FILE
#
# Once a deployment starts executing its deployment program, that program is
# immutable for the whole run. A repository update during the deploy can never
# modify the bytes being interpreted.
#
# HOW
# ---
#   acquire exclusive host lock (flock, root-controlled path)
#     -> resolve + PIN the exact target SHA (a remote branch that moves after
#        this point belongs to a LATER deployment, never this one)
#     -> materialise an immutable execution snapshot from the git OBJECT
#        (git archive: tracked bytes of that commit, never the working tree)
#     -> verify the snapshot trust boundary (root-owned, 0700, no symlink,
#        not writable by the application runtime or any co-tenant)
#     -> run the deployment program FROM THE SNAPSHOT
#     -> the snapshot program may now mutate the working tree freely
#     -> cleanup snapshot, release lock, preserve the real exit code
#
# The pre-mutation bootstrap chain (runner -> this file) may still be read from
# the working tree: nothing has mutated it yet, and this program `exec`s no
# further working-tree file once the snapshot exists. Everything that runs at or
# after the first repository mutation runs from the snapshot.
#
# This program NEVER deploys, migrates, backs up, restores or restarts anything
# itself. It is only the trusted entrypoint that makes those steps safe.
#
# USAGE
#   bash scripts/deploy-immutable-exec.sh \
#       --role deploy --app-dir /var/www/app --branch <branch> \
#       -- scripts/deploy-vps.sh
#
#   bash scripts/deploy-immutable-exec.sh \
#       --role rollback --app-dir /var/www/app --snapshot-ref HEAD \
#       -- scripts/rollback-vps.sh <target>
#
set -euo pipefail

ROLE=""
APP_DIR=""
BRANCH=""
TARGET_SHA=""
SNAPSHOT_REF=""
RUN_ID=""
declare -a OVERLAY_FILES=()
declare -a PROGRAM_ARGV=()

usage() {
  cat >&2 <<'USAGE'
usage: bash scripts/deploy-immutable-exec.sh --role <deploy|rollback> [options] -- <program-relative-path> [args...]

  --role ROLE          deploy | rollback (scopes the lock and the snapshot dir)
  --app-dir DIR        application root (default: repository root of this file)
  --branch BRANCH      deploy: remote branch whose tip becomes the pinned target
  --target-sha SHA     pin an exact commit instead of resolving a branch tip
  --snapshot-ref REF   git object the execution snapshot is exported from
                       (default: the pinned target for deploy, HEAD for rollback)
  --overlay SRC:REL    copy a LIVE host file into the snapshot at REL, after the
                       export (used for the runtime identity authority, which
                       must never be rolled back with the code)
  --run-id ID          unique id for this run (default: generated)
  --lock-dir DIR       trusted lock/snapshot root (default: /run/daengtisiams-deploy)
USAGE
  exit 2
}

while [ $# -gt 0 ]; do
  case "$1" in
    --role)         ROLE="${2:?--role needs a value}"; shift 2 ;;
    --app-dir)      APP_DIR="${2:?--app-dir needs a value}"; shift 2 ;;
    --branch)       BRANCH="${2:?--branch needs a value}"; shift 2 ;;
    --target-sha)   TARGET_SHA="${2:?--target-sha needs a value}"; shift 2 ;;
    --snapshot-ref) SNAPSHOT_REF="${2:?--snapshot-ref needs a value}"; shift 2 ;;
    --overlay)      OVERLAY_FILES+=("${2:?--overlay needs SRC:REL}"); shift 2 ;;
    --run-id)       RUN_ID="${2:?--run-id needs a value}"; shift 2 ;;
    --lock-dir)     DMS_DEPLOY_LOCK_DIR="${2:?--lock-dir needs a value}"; shift 2 ;;
    --)             shift; PROGRAM_ARGV=("$@"); break ;;
    -h|--help)      usage ;;
    *)              echo "immutable-exec: unknown option: $1" >&2; usage ;;
  esac
done

fatal() { echo "immutable-exec: FATAL $*" >&2; exit 2; }
note()  { echo "immutable-exec: $*"; }

case "$ROLE" in
  deploy|rollback) ;;
  *) fatal "--role must be 'deploy' or 'rollback' (got '${ROLE}')" ;;
esac

[ "${#PROGRAM_ARGV[@]}" -gt 0 ] || fatal "no program given after --"

PROGRAM_REL="${PROGRAM_ARGV[0]}"
case "$PROGRAM_REL" in
  /*|*..*) fatal "program must be a relative path inside the snapshot: ${PROGRAM_REL}" ;;
esac

if [ -z "$APP_DIR" ]; then
  APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fi
[ -d "$APP_DIR/.git" ] || [ -f "$APP_DIR/.git" ] || fatal "not a git repository: ${APP_DIR}"

# A unique per-run identity. Second-resolution alone can collide, so the stamp
# is combined with the pid and a random suffix.
if [ -z "$RUN_ID" ]; then
  RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$-${RANDOM}"
fi
case "$RUN_ID" in
  *[!A-Za-z0-9._-]*) fatal "run id contains unsafe characters: ${RUN_ID}" ;;
esac

# ─── Trusted lock / snapshot root ───────────────────────────────────────────
# Deliberately NOT under the application directory: storage/ and bootstrap/cache
# are writable by the runtime user, so a lock or an execution payload living
# there could be replaced by the very process the deploy is meant to constrain
# (or by the co-tenant application sharing this host). /run is a root-owned
# tmpfs; /var/lock is the fallback for hosts without it.
DEFAULT_LOCK_DIR="/run/daengtisiams-deploy"
if [ ! -d /run ] || [ ! -w /run ]; then
  DEFAULT_LOCK_DIR="/var/lock/daengtisiams-deploy"
fi
LOCK_DIR="${DMS_DEPLOY_LOCK_DIR:-$DEFAULT_LOCK_DIR}"

[ -L "$LOCK_DIR" ] && fatal "lock root is a symlink, refusing to follow it: ${LOCK_DIR}"
mkdir -p "$LOCK_DIR"
chmod 0700 "$LOCK_DIR"

LOCK_ROOT_OWNER="$(stat -c '%u' "$LOCK_DIR")"
if [ "$LOCK_ROOT_OWNER" != "$(id -u)" ]; then
  fatal "lock root ${LOCK_DIR} is owned by uid ${LOCK_ROOT_OWNER}, not by this deploy identity ($(id -u))"
fi

LOCK_FILE="${LOCK_DIR}/${ROLE}.lock"
SNAPSHOT_DIR="${LOCK_DIR}/${ROLE}-${RUN_ID}"

# ─── Exclusive deployment lock ──────────────────────────────────────────────
# flock is advisory but kernel-backed and, crucially, released automatically when
# the holding process dies — a crashed or SIGTERM'd deploy can never leave a
# permanently stuck lock the way a hand-rolled PID file does. The descriptor is
# held by THIS process for the entire critical section, so the child deployment
# program inherits the protection without being able to drop it.
command -v flock >/dev/null 2>&1 || fatal "flock is required for safe deployment serialisation but is not installed"

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  echo "immutable-exec: BUSY another ${ROLE} is already running (lock: ${LOCK_FILE})" >&2
  echo "immutable-exec: refusing to start a second ${ROLE} — no backup, no migration, no checkout was performed" >&2
  exit 75
fi
note "lock acquired role=${ROLE} file=${LOCK_FILE}"
echo "DEPLOY_LOCK_ACQUIRED=YES"

# ─── Guarded snapshot removal ───────────────────────────────────────────────
# Every guard here exists because this function runs `rm -rf` as root: the path
# must be non-empty, must live under the trusted root, must carry this run's id,
# must be a real directory and must not be a symlink we would follow out of the
# sandbox.
# shellcheck disable=SC2317  # invoked from the EXIT/TERM/INT traps below
purge_snapshot() {
  local dir="$1"
  [ -n "$dir" ] || return 0
  case "$dir" in
    "${LOCK_DIR}/${ROLE}-${RUN_ID}") ;;
    *) echo "immutable-exec: refusing to remove unexpected path: ${dir}" >&2; return 0 ;;
  esac
  [ -L "$dir" ] && { echo "immutable-exec: refusing to remove a symlink: ${dir}" >&2; return 0; }
  [ -d "$dir" ] || return 0
  rm -rf -- "$dir"
}

CLEANUP_DONE=0
# shellcheck disable=SC2317  # invoked from the EXIT/TERM/INT traps below
cleanup() {
  local rc=$?
  [ "$CLEANUP_DONE" = "1" ] && return
  CLEANUP_DONE=1
  purge_snapshot "$SNAPSHOT_DIR"
  if [ ! -e "$SNAPSHOT_DIR" ]; then
    echo "DEPLOY_SNAPSHOT_CLEANED=YES"
  else
    echo "DEPLOY_SNAPSHOT_CLEANED=NO (${SNAPSHOT_DIR})" >&2
  fi
  # The original failure code is preserved: a cleanup trap must never turn a
  # failed deployment into a successful one.
  return "$rc"
}
trap cleanup EXIT
trap 'exit 143' TERM
trap 'exit 130' INT

cd "$APP_DIR"

# ─── Pin the exact target commit ────────────────────────────────────────────
if [ -z "$TARGET_SHA" ] && [ -n "$BRANCH" ]; then
  note "fetching origin ${BRANCH} (working tree untouched)"
  git fetch --no-tags origin "$BRANCH"
  TARGET_SHA="$(git rev-parse --verify "FETCH_HEAD^{commit}")"
fi
if [ -z "$TARGET_SHA" ]; then
  TARGET_SHA="$(git rev-parse --verify "HEAD^{commit}")"
fi
git rev-parse --verify --quiet "${TARGET_SHA}^{commit}" >/dev/null \
  || fatal "target commit is not present in this repository: ${TARGET_SHA}"
TARGET_SHA="$(git rev-parse --verify "${TARGET_SHA}^{commit}")"

# From here on the target is frozen. If origin advances while this deployment
# runs, that newer commit belongs to the NEXT deployment; this one still lands
# on exactly the commit pinned above.
note "target pinned ${TARGET_SHA}"
echo "DEPLOY_TARGET_PINNED=${TARGET_SHA}"

[ -n "$SNAPSHOT_REF" ] || {
  if [ "$ROLE" = "rollback" ]; then
    # A rollback snapshot is taken from the CURRENT code, never from the older
    # target: rolling the application back must not roll the security tooling
    # (INFRA-SEC-ENV-1 secret hardening, INFRA-SEC-RUNTIME-1 isolation verifier)
    # back to a version that predates those invariants.
    SNAPSHOT_REF="HEAD"
  else
    SNAPSHOT_REF="$TARGET_SHA"
  fi
}
SNAPSHOT_SHA="$(git rev-parse --verify "${SNAPSHOT_REF}^{commit}")"

# ─── Immutable execution snapshot ───────────────────────────────────────────
# `git archive` streams the tracked bytes of a COMMIT OBJECT. It never reads the
# working tree, so the snapshot cannot be poisoned by a dirty checkout and is
# byte-identical no matter what happens to the tree afterwards.
[ -e "$SNAPSHOT_DIR" ] && fatal "snapshot directory already exists: ${SNAPSHOT_DIR}"
(umask 077 && mkdir -p "$SNAPSHOT_DIR")
chmod 0700 "$SNAPSHOT_DIR"

declare -a SNAPSHOT_PATHS=()
for candidate in scripts deploy; do
  if git cat-file -e "${SNAPSHOT_SHA}:${candidate}" 2>/dev/null; then
    SNAPSHOT_PATHS+=("$candidate")
  fi
done
[ "${#SNAPSHOT_PATHS[@]}" -gt 0 ] || fatal "commit ${SNAPSHOT_SHA} carries no deployment payload"

git archive --format=tar "$SNAPSHOT_SHA" -- "${SNAPSHOT_PATHS[@]}" | tar -x -C "$SNAPSHOT_DIR"

# Live host files that must survive a code rollback (the runtime identity
# authority above all: rolling the CODE back must never roll the RUNTIME
# IDENTITY back onto the shared co-tenant account).
for overlay in "${OVERLAY_FILES[@]:-}"; do
  [ -n "$overlay" ] || continue
  src="${overlay%%:*}"
  rel="${overlay#*:}"
  case "$rel" in
    ""|/*|*..*) fatal "unsafe overlay destination: ${rel}" ;;
  esac
  [ -r "$src" ] || fatal "overlay source unreadable: ${src}"
  mkdir -p "${SNAPSHOT_DIR}/$(dirname "$rel")"
  cp -- "$src" "${SNAPSHOT_DIR}/${rel}"
done

[ -f "${SNAPSHOT_DIR}/${PROGRAM_REL}" ] || fatal "program not present in snapshot: ${PROGRAM_REL}"

# ─── Snapshot trust boundary ────────────────────────────────────────────────
# The deployment payload must not be replaceable by anything less privileged
# than the deploy identity itself — not the DaengtisiaMS runtime user, not the
# co-tenant application, not any other local account.
assert_trusted() {
  local path="$1" label="$2"
  [ -L "$path" ] && fatal "${label} is a symlink: ${path}"
  local owner mode
  owner="$(stat -c '%u' "$path")"
  mode="$(stat -c '%a' "$path")"
  [ "$owner" = "$(id -u)" ] || fatal "${label} is owned by uid ${owner}, not by the deploy identity ($(id -u)): ${path}"
  case "$mode" in
    700|600|500|400) ;;
    *) fatal "${label} mode ${mode} is not restricted to the deploy identity: ${path}" ;;
  esac
}
assert_trusted "$SNAPSHOT_DIR" "execution snapshot"
chmod 0600 "${SNAPSHOT_DIR}/${PROGRAM_REL}"
assert_trusted "${SNAPSHOT_DIR}/${PROGRAM_REL}" "deployment program"

SOURCE_PROGRAM_SHA256="unavailable"
if [ -r "${APP_DIR}/${PROGRAM_REL}" ]; then
  SOURCE_PROGRAM_SHA256="$(sha256sum "${APP_DIR}/${PROGRAM_REL}" | awk '{print $1}')"
fi
SNAPSHOT_PROGRAM_SHA256="$(sha256sum "${SNAPSHOT_DIR}/${PROGRAM_REL}" | awk '{print $1}')"

note "snapshot ready ${SNAPSHOT_DIR} (from ${SNAPSHOT_SHA})"
echo "DEPLOY_RUN_ID=${RUN_ID}"
echo "DEPLOY_SNAPSHOT_CREATED=YES"
echo "DEPLOY_SNAPSHOT_TRUSTED=PASS"
echo "DEPLOY_EXECUTION_SOURCE=${SNAPSHOT_DIR}/${PROGRAM_REL}"
echo "SOURCE_DEPLOY_SCRIPT_SHA256=${SOURCE_PROGRAM_SHA256}"
echo "SNAPSHOT_DEPLOY_SCRIPT_SHA256=${SNAPSHOT_PROGRAM_SHA256}"

# ─── Run the deployment program from the immutable snapshot ─────────────────
# Deliberately NOT `exec`: this process keeps the lock descriptor open for the
# whole critical section and keeps its EXIT trap alive so the snapshot is always
# cleaned up and the child's real exit code is propagated.
export DMS_DEPLOY_SNAPSHOT_DIR="$SNAPSHOT_DIR"
export DMS_DEPLOY_RUN_ID="$RUN_ID"
export DMS_DEPLOY_TARGET_SHA="$TARGET_SHA"
export DMS_DEPLOY_ROLE="$ROLE"
export DMS_DEPLOY_APP_DIR="$APP_DIR"
export DMS_DEPLOY_LOCK_FILE="$LOCK_FILE"

# fd 9 (the lock) is closed in the child so the descriptor is not inherited by
# composer/npm/php/systemctl and everything else the deploy spawns. The lock
# itself stays held by THIS process for the whole critical section.
set +e
bash "${SNAPSHOT_DIR}/${PROGRAM_REL}" "${PROGRAM_ARGV[@]:1}" 9>&-
PROGRAM_RC=$?
set -e

note "program finished rc=${PROGRAM_RC}"
exit "$PROGRAM_RC"
