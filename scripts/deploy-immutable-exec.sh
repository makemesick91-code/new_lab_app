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
#   acquire THE release lock (flock, root-controlled path) — one lock for deploy
#     AND rollback (rule 231): they can never interleave. The pre-231 per-role
#     locks are taken too, so a bootstrap from an older tree is still excluded
#   reconcile what an interrupted run left behind (snapshots, private refs) and
#     refuse a stale git index lock before anything is touched
#     -> deploy: verify the merged release approval record for the requested
#        release and PIN exactly the commit it approves (rule 229 — the branch
#        tip is never substituted; a mismatch is DEPLOYMENT=DENIED, exit 77)
#     -> rollback: PIN the exact commit the operator's ref resolved to
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
#       --release-id <release-id> --approved-sha <40-hex> \
#       -- scripts/deploy-vps.sh --release-id <release-id> --approved-sha <40-hex>
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
RELEASE_ID=""
APPROVED_SHA=""
RUN_ID=""
declare -a OVERLAY_FILES=()
declare -a PROGRAM_ARGV=()

usage() {
  cat >&2 <<'USAGE'
usage: bash scripts/deploy-immutable-exec.sh --role <deploy|rollback> [options] -- <program-relative-path> [args...]

  --role ROLE          deploy | rollback (scopes the lock and the snapshot dir)
  --app-dir DIR        application root (default: repository root of this file)
  --branch BRANCH      deploy: the base branch the release approval is merged on
  --release-id ID      deploy: the release whose approval record authorises it
  --approved-sha SHA   deploy: the exact commit the operator expects; it must be
                       the commit the merged approval record approves (rule 229)
  --target-sha SHA     rollback only: pin this exact commit (refused for deploy)
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
    --release-id)   RELEASE_ID="${2:?--release-id needs a value}"; shift 2 ;;
    --approved-sha) APPROVED_SHA="${2:?--approved-sha needs a value}"; shift 2 ;;
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

# ─── Rule 229: a deploy is bound to an approved release, never a branch tip ──
# Checked here, before the lock, the snapshot or anything else: a deploy that
# does not even name its approval never gets further. The approval itself is
# verified inside the lock, below.
deny() {
  echo "immutable-exec: DENIED $*" >&2
  echo "DEPLOYMENT=DENIED"
  exit 77
}
# Each role runs exactly one program. A deploy's snapshot is always exported
# from the approved commit itself: a --snapshot-ref would let an approved deploy
# execute the deployment program of ANY other commit (independent review, 8D
# PR2, finding 1).
case "$ROLE:${PROGRAM_ARGV[0]}" in
  deploy:scripts/deploy-vps.sh|rollback:scripts/rollback-vps.sh) ;;
  *) deny "role '${ROLE}' runs only its own program, not '${PROGRAM_ARGV[0]}'" ;;
esac
# The only live file ever laid over the snapshot is the runtime identity
# authority. An overlay of anything else (the program, a library, the host
# authority) would replace approved bytes after the export (review, 8D PR2).
for overlay in "${OVERLAY_FILES[@]:-}"; do
  [ -n "$overlay" ] || continue
  [ "${overlay#*:}" = "deploy/runtime-identity.conf" ] \
    || deny "only deploy/runtime-identity.conf may be overlaid on the snapshot, not '${overlay#*:}'"
done
if [ "$ROLE" = "deploy" ]; then
  [ -z "$TARGET_SHA" ] || deny "--target-sha is not accepted for a deploy: the target is the commit the merged release approval approves (rule 229)"
  [ -z "$SNAPSHOT_REF" ] || deny "--snapshot-ref is not accepted for a deploy: the deployment program comes from the approved commit only (rule 229)"
  [ -n "$BRANCH" ] || deny "a deploy needs --branch (the base branch the approval is merged on)"
  [ -n "$RELEASE_ID" ] && [ -n "$APPROVED_SHA" ] \
    || deny "a deploy needs --release-id and --approved-sha; an unapproved target never enters the mutation path (rule 229)"
  [[ "$APPROVED_SHA" =~ ^[0-9a-f]{40}$ ]] || deny "--approved-sha '${APPROVED_SHA}' is not a full 40-character lowercase commit SHA"
else
  [ -z "$RELEASE_ID" ] && [ -z "$APPROVED_SHA" ] \
    || fatal "--release-id/--approved-sha belong to a deploy; a rollback pins its target with --target-sha"
fi

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

# Rule 231: ONE lock for every role. A deploy and a rollback both rewrite the
# checkout, rebuild vendor/ and the assets and restart PHP-FPM; interleaving
# them leaves a tree neither of them describes.
LOCK_FILE="${LOCK_DIR}/release.lock"
HOLDER_FILE="${LOCK_DIR}/release.holder"
SNAPSHOT_DIR="${LOCK_DIR}/${ROLE}-${RUN_ID}"

# ─── Exclusive release lock ─────────────────────────────────────────────────
# flock is advisory but kernel-backed and, crucially, released automatically when
# the holding process dies — a crashed or SIGTERM'd deploy can never leave a
# permanently stuck lock the way a hand-rolled PID file does. The descriptor is
# held by THIS process for the entire critical section, so the child deployment
# program inherits the protection without being able to drop it. Every lock is
# taken non-blocking, so no ordering can deadlock: any one busy means BUSY.
command -v flock >/dev/null 2>&1 || fatal "flock is required for safe deployment serialisation but is not installed"

busy() {
  echo "immutable-exec: BUSY $* — refusing to start a ${ROLE} (lock root: ${LOCK_DIR})" >&2
  if [ -f "$HOLDER_FILE" ] && [ ! -L "$HOLDER_FILE" ]; then
    echo "immutable-exec: last recorded holder: $(tr '\n' ' ' < "$HOLDER_FILE" 2>/dev/null)" >&2
  fi
  echo "immutable-exec: no backup, no migration, no checkout was performed" >&2
  exit 75
}
exec 9>"$LOCK_FILE"
flock -n 9 || busy "another deploy or rollback holds the release lock"
# Transition (rule 231): a bootstrap from a tree older than rule 231 locks only
# ${role}.lock. Holding both role locks too keeps such a run and this one apart.
exec 8>"${LOCK_DIR}/deploy.lock"
flock -n 8 || busy "a pre-rule-231 deploy holds deploy.lock"
exec 7>"${LOCK_DIR}/rollback.lock"
flock -n 7 || busy "a pre-rule-231 rollback holds rollback.lock"
note "lock acquired role=${ROLE} file=${LOCK_FILE}"
echo "DEPLOY_LOCK_ACQUIRED=YES"
echo "DEPLOY_LOCK_FILE=${LOCK_FILE}"
# Who holds it, for the operator who meets BUSY. Informational only: the flock is
# the authority, and a stale holder note after a crash means nothing.
(umask 077 && printf 'role=%s\nrun_id=%s\npid=%s\nsince=%s\n' "$ROLE" "$RUN_ID" "$$" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "${HOLDER_FILE}.${RUN_ID}" \
  && mv -f -- "${HOLDER_FILE}.${RUN_ID}" "$HOLDER_FILE") || true

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
  # A private approval ref left behind by an interrupted guard (rule 229).
  git -C "$APP_DIR" for-each-ref --format='%(refname)' "refs/dms-release-approval/$$-*" 2>/dev/null \
    | while IFS= read -r ref; do git -C "$APP_DIR" update-ref -d "$ref" 2>/dev/null || true; done || true
  purge_snapshot "$SNAPSHOT_DIR"
  if [ -f "$HOLDER_FILE" ] && grep -qxF "run_id=${RUN_ID}" "$HOLDER_FILE" 2>/dev/null; then
    rm -f -- "$HOLDER_FILE"
  fi
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

# ─── Recovery after an interrupted run (rule 231) ───────────────────────────
# With every lock held, no other bootstrap — of this tree or an older one — is
# alive, so a snapshot left in the lock root belongs to a run that was killed
# (SIGKILL, power loss: no trap ran). It is removed here, under the same guards
# as our own. A private approval ref whose process is gone is removed too.
for stale in "${LOCK_DIR}"/deploy-* "${LOCK_DIR}"/rollback-*; do
  [ -e "$stale" ] || [ -L "$stale" ] || continue
  name="${stale##*/}"
  if [ -L "$stale" ] || [ ! -d "$stale" ] || [[ ! "$name" =~ ^(deploy|rollback)-[A-Za-z0-9._-]+$ ]]; then
    echo "immutable-exec: leaving unexpected entry in the lock root untouched: ${stale}" >&2
    continue
  fi
  rm -rf -- "$stale"
  echo "DEPLOY_STALE_SNAPSHOT_RECONCILED=${name}"
done
while IFS= read -r ref; do
  [[ "$ref" =~ ^refs/dms-(release-approval|rollback-target)/([0-9]+)- ]] || continue
  [ -d "/proc/${BASH_REMATCH[2]}" ] && continue
  git -C "$APP_DIR" update-ref -d "$ref" 2>/dev/null && echo "DEPLOY_STALE_APPROVAL_REF_RECONCILED=${ref}"
done < <(git -C "$APP_DIR" for-each-ref --format='%(refname)' 'refs/dms-release-approval/' 'refs/dms-rollback-target/' 2>/dev/null || true)
# A git lock left by a run killed mid-checkout or mid-merge (index, HEAD, a
# branch ref, packed-refs, config) makes the NEXT run fail after its backup.
# Refuse now, before anything: only an operator who has confirmed no git process
# is running may remove it (runbook).
GIT_DIR_PATH="$(git -C "$APP_DIR" rev-parse --absolute-git-dir 2>/dev/null)" || fatal "cannot resolve the git directory of ${APP_DIR}"
STALE_GIT_LOCK=""
for candidate in index.lock HEAD.lock packed-refs.lock config.lock; do
  if [ -e "${GIT_DIR_PATH}/${candidate}" ] || [ -L "${GIT_DIR_PATH}/${candidate}" ]; then
    STALE_GIT_LOCK="${GIT_DIR_PATH}/${candidate}"
    break
  fi
done
if [ -z "$STALE_GIT_LOCK" ] && [ -d "${GIT_DIR_PATH}/refs" ]; then
  STALE_GIT_LOCK="$(find "${GIT_DIR_PATH}/refs" -name '*.lock' -print -quit 2>/dev/null || true)"
fi
if [ -n "$STALE_GIT_LOCK" ]; then
  fatal "stale git lock ${STALE_GIT_LOCK} — an earlier run was interrupted during a checkout, or a git command is running now; nothing was backed up, checked out or migrated (recovery: vps-deploy-rollback-runbook.md, Troubleshooting)"
fi

# ─── Pin the exact target commit ────────────────────────────────────────────
if [ "$ROLE" = "deploy" ]; then
  # Rule 229. The approval guard and the authority it reads are taken from
  # beside THIS program — the same trusted source as the bootstrap itself (the
  # checkout before any mutation, or a bundle exported from the approved release
  # object during the one-time bootstrap). It fetches the base branch (refs
  # only), reads the approval record from the fetched commit object and
  # approves exactly one commit. No snapshot, backup, checkout, migration or
  # restart has happened yet; a denial leaves production untouched.
  BOOTSTRAP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  APPROVAL_LIB="${BOOTSTRAP_ROOT}/scripts/lib/release-approval.sh"
  APPROVAL_AUTHORITY="${BOOTSTRAP_ROOT}/deploy/production-host.conf"
  [ -r "$APPROVAL_LIB" ] || deny "release approval guard missing: ${APPROVAL_LIB}"
  # shellcheck source=lib/release-approval.sh
  source "$APPROVAL_LIB"
  approval_rc=0
  dms_release_approval_verify "$APP_DIR" "$BRANCH" "$RELEASE_ID" "$APPROVED_SHA" \
    "$APPROVAL_AUTHORITY" "$(hostname)" "$(date -u +%s)" \
    "$(dms_release_approval_observed_host_key "$APPROVAL_AUTHORITY" || true)" "$(id -u)" || approval_rc=$?
  if [ "$approval_rc" -ne 0 ] || [ "${DMS_RELEASE_APPROVED_SHA:-}" != "$APPROVED_SHA" ]; then
    deny "release ${RELEASE_ID} is not approved for ${APPROVED_SHA} — nothing was backed up, checked out or migrated"
  fi
  # (Rule 231: the guard above also refused a release already spent by a
  # completed deploy — checked here again inside the lock, against the trusted
  # evidence directory, so a rollback cannot be undone by replaying it. The
  # spend record itself is written below, still inside the lock.)
  TARGET_SHA="$DMS_RELEASE_APPROVED_SHA"
  echo "DEPLOY_APPROVED_SHA=${TARGET_SHA}"
  echo "DEPLOY_APPROVAL_RECORD=${DMS_RELEASE_APPROVAL_TIP}:${DMS_RELEASE_APPROVAL_PATH} blob=${DMS_RELEASE_APPROVAL_BLOB}"
  echo "DEPLOY_APPROVAL_SIGNER=${DMS_RELEASE_APPROVAL_SIGNER_KEY}"
fi
if [ -z "$TARGET_SHA" ]; then
  TARGET_SHA="$(git rev-parse --verify "HEAD^{commit}")"
fi
git rev-parse --verify --quiet "${TARGET_SHA}^{commit}" >/dev/null \
  || fatal "target commit is not present in this repository: ${TARGET_SHA}"
TARGET_SHA="$(git rev-parse --verify "${TARGET_SHA}^{commit}")"

# From here on the target is frozen. If origin advances while this deployment
# runs, that newer commit belongs to the NEXT deployment; this one still lands
# on exactly the commit pinned above — for a deploy, the approved commit.
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
export DMS_DEPLOY_RELEASE_ID="$RELEASE_ID"
export DMS_DEPLOY_APPROVED_SHA="$APPROVED_SHA"

# fds 7-9 (the locks) are closed in the child so the descriptors are not
# inherited by composer/npm/php/systemctl and everything else the deploy spawns.
# The locks themselves stay held by THIS process for the whole critical section.
set +e
bash "${SNAPSHOT_DIR}/${PROGRAM_REL}" "${PROGRAM_ARGV[@]:1}" 7>&- 8>&- 9>&-
PROGRAM_RC=$?
set -e

# Rule 231: a completed deploy spends its release, recorded HERE — inside the
# release lock, by the process that deployed, before the lock is released — so
# single use does not depend on the runner's record or its timing.
if [ "$ROLE" = "deploy" ] && [ "$PROGRAM_RC" -eq 0 ]; then
  dms_release_approval_record_spent "$APPROVAL_AUTHORITY" "$APP_DIR" "$(id -u)" "$RELEASE_ID" \
    "$TARGET_SHA" "${DMS_RELEASE_APPROVAL_BLOB:-}" "$RUN_ID" || true
fi

note "program finished rc=${PROGRAM_RC}"
exit "$PROGRAM_RC"
