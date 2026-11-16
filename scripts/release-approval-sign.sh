#!/usr/bin/env bash
#
# DMS-RELEASE-APPROVAL-DIRECT-1 — the OWNER signs one production release
# approval (approval contract 3) on their own workstation.
#
#   bash scripts/release-approval-sign.sh --release-id <id> --target-sha <40-hex> --go-tag <tag-go> \
#       [--hours <1-72, default 48>] [--key <private key, default ~/.ssh/dms_release_approval_ed25519>] \
#       [--repo-dir <checkout, default this program's repository>]
#
# It reads every fact it binds from the TARGET COMMIT itself — its tree, its
# .sprint/current.yml GO tag, and its deploy/production-host.conf (repository,
# production host, pinned host key, base branch, approver, the deploy operator
# alias) — after proving the commit is on origin/<base branch> locally (fetch
# first). It generates a random approval id, renders the canonical record
# (scripts/lib/release-approval.sh), shows it with its window in UTC and WITA,
# and signs only after the owner types YES on the controlling terminal (never
# from a pipe; no terminal, no signature):
#
#   env -u SSH_AUTH_SOCK SSH_ASKPASS_REQUIRE=never ssh-keygen -Y sign -f <key> -n dms-release-approval <record file>
#
# (no ssh-agent: the key's passphrase is asked on the terminal), then verifies the signature
# with the key's .pub and writes the bundle (record + signature) to
#   ~/DaengtisiaMS-release-approvals/<release-id>/<approval-id>.bundle   (0600, directories 0700)
# and prints its SHA-256, the record's SHA-256 and the exact submit command.
#
# It never commits, pushes, deploys or contacts any host, and never reads,
# prints or copies the private key (only ssh-keygen opens it).
#
# Exit codes: 0 bundle written; 1 refused (nothing written); 64 usage.
set -euo pipefail
umask 077

SELF_DIR="$(cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=lib/release-approval.sh
source "${SELF_DIR}/lib/release-approval.sh"

usage() {
  sed -n '7,9p' "${BASH_SOURCE[0]}" | sed 's/^#//' >&2
  exit 64
}

refuse() {
  echo "release-approval-sign: REFUSED — $*" >&2
  echo "RELEASE APPROVAL NOT SIGNED — nothing was written" >&2
  exit 1
}

RELEASE_ID=""
TARGET_SHA=""
GO_TAG=""
HOURS="48"
KEY="${HOME}/.ssh/dms_release_approval_ed25519"
REPO_DIR="$(cd -P -- "${SELF_DIR}/.." && pwd -P)"

while [ "$#" -gt 0 ]; do
  case "$1" in
    --release-id|--target-sha|--go-tag|--hours|--key|--repo-dir)
      [ "$#" -ge 2 ] || usage
      case "$1" in
        --release-id) RELEASE_ID="$2" ;;
        --target-sha) TARGET_SHA="$2" ;;
        --go-tag) GO_TAG="$2" ;;
        --hours) HOURS="$2" ;;
        --key) KEY="$2" ;;
        --repo-dir) REPO_DIR="$2" ;;
      esac
      shift 2
      ;;
    -h|--help) usage ;;
    *) echo "release-approval-sign: unknown argument: $1" >&2; usage ;;
  esac
done

dms_release_approval_valid_id "$RELEASE_ID" || refuse "--release-id '${RELEASE_ID}' is not a release identifier (lowercase, digits and '-', 3-80 characters)"
dms_release_approval_valid_sha "$TARGET_SHA" || refuse "--target-sha '${TARGET_SHA}' is not a full 40-character lowercase commit SHA"
[[ "$GO_TAG" =~ ^[a-z0-9][a-z0-9.-]{0,78}-go$ ]] || refuse "--go-tag '${GO_TAG}' does not follow the lowercase '-go' convention"
# Decimal only: `08` is 8 hours, never an octal error.
if [[ ! "$HOURS" =~ ^[0-9]{1,2}$ ]] || [ $((10#$HOURS)) -lt 1 ] || [ $((10#$HOURS)) -gt $((DMS_RELEASE_APPROVAL_MAX_VALIDITY_SECONDS / 3600)) ]; then
  refuse "--hours '${HOURS}' must be a whole number of hours from 1 to $((DMS_RELEASE_APPROVAL_MAX_VALIDITY_SECONDS / 3600))"
fi
HOURS=$((10#$HOURS))
if [ ! -f "$KEY" ] || [ ! -f "${KEY}.pub" ]; then
  refuse "the signing key ${KEY} (and ${KEY}.pub) does not exist"
fi
command -v ssh-keygen >/dev/null 2>&1 || refuse "ssh-keygen is not installed"
git -C "$REPO_DIR" rev-parse --git-dir >/dev/null 2>&1 || refuse "${REPO_DIR} is not a git checkout"

WORK="$(mktemp -d)"
trap 'rm -rf -- "${WORK:?}"' EXIT

repo_git() {
  git -C "$REPO_DIR" "$@"
}

# ── The commit and the facts it carries ───────────────────────────────────────
[ "$(repo_git cat-file -t "$TARGET_SHA" 2>/dev/null || true)" = "commit" ] \
  || refuse "${TARGET_SHA} is not a commit in ${REPO_DIR} — fetch the base branch first"
repo_git cat-file blob "${TARGET_SHA}:deploy/production-host.conf" > "${WORK}/authority" 2>/dev/null \
  || refuse "${TARGET_SHA} carries no deploy/production-host.conf"
authority() {
  dms_release_approval_authority_value "${WORK}/authority" "$1"
}
BRANCH="$(authority DMS_PRODUCTION_DEPLOY_BRANCH)"
REPOSITORY="$(authority DMS_PRODUCTION_REPOSITORY)"
HOST="$(authority DMS_PRODUCTION_HOSTNAME)"
HOST_KEY="$(authority DMS_PRODUCTION_HOST_KEY_SHA256)"
APPROVERS="$(authority DMS_PRODUCTION_RELEASE_APPROVERS)"
DEPLOY_ALIAS="$(authority DMS_PRODUCTION_DEPLOY_SSH_ALIAS)"
[[ "$BRANCH" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]*$ && "$BRANCH" != *..* ]] || refuse "the commit's authority declares no plain base branch"
[[ "$REPOSITORY" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || refuse "the commit's authority declares no GitHub OWNER/REPO repository"
[ -n "$HOST" ] || refuse "the commit's authority declares no production host"
[[ "$HOST_KEY" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]] || refuse "the commit's authority pins no SHA256 host key"
[[ "$DEPLOY_ALIAS" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || refuse "the commit's authority declares no deploy operator alias (DMS_PRODUCTION_DEPLOY_SSH_ALIAS)"
read -r -a approver_list <<< "$APPROVERS"
[ "${#approver_list[@]}" -eq 1 ] || refuse "the commit's authority declares ${#approver_list[@]} release approvers ('${APPROVERS}'); this helper signs as the single declared approver"
APPROVER="${approver_list[0]}"

origin_tip="$(repo_git rev-parse --verify --quiet "refs/remotes/origin/${BRANCH}^{commit}")" \
  || refuse "${REPO_DIR} has no origin/${BRANCH} — fetch it first"
repo_git merge-base --is-ancestor "$TARGET_SHA" "$origin_tip" \
  || refuse "${TARGET_SHA} is not on origin/${BRANCH} (${origin_tip}) — only a merged commit is approved"
TREE="$(repo_git rev-parse --verify "${TARGET_SHA}^{tree}")"
manifest_tag="$(repo_git cat-file -p "${TARGET_SHA}:.sprint/current.yml" 2>/dev/null \
  | sed -n 's/^go_tag:[[:space:]]*//p' | tail -n 1 | tr -d '[:space:]')" || manifest_tag=""
[ "$manifest_tag" = "$GO_TAG" ] || refuse "--go-tag is '${GO_TAG}', the commit's .sprint/current.yml declares '${manifest_tag:-<none>}'"

# ── The record ────────────────────────────────────────────────────────────────
APPROVAL_ID="$(od -An -tx1 -N16 /dev/urandom | tr -d ' \n')"
[[ "$APPROVAL_ID" =~ ^[0-9a-f]{32}$ ]] || refuse "no random approval id could be generated"
NOW="$(date -u +%s)"
NOT_AFTER_EPOCH=$((NOW + HOURS * 3600))
APPROVED_AT="$(date -u -d "@${NOW}" +%Y-%m-%dT%H:%M:%SZ)"
NOT_AFTER="$(date -u -d "@${NOT_AFTER_EPOCH}" +%Y-%m-%dT%H:%M:%SZ)"
RECORD="${WORK}/${APPROVAL_ID}.approval"
dms_release_approval_render_direct "$APPROVAL_ID" "$RELEASE_ID" "$REPOSITORY" "$HOST" "$HOST_KEY" "$BRANCH" \
  "$TARGET_SHA" "$TREE" "$GO_TAG" "$APPROVER" "$APPROVED_AT" "$NOT_AFTER" > "$RECORD"
dms_release_approval_parse_direct "$RECORD" || refuse "the rendered record is not canonical: ${DMS_RELEASE_APPROVAL_DIRECT_WHY}"

echo "== Production release approval (contract 3) — review before signing =="
cat -- "$RECORD"
echo "Window (UTC):  ${APPROVED_AT} → ${NOT_AFTER} (${HOURS} h)"
echo "Window (WITA): $(TZ=Asia/Makassar date -d "@${NOW}" '+%Y-%m-%d %H:%M:%S %Z') → $(TZ=Asia/Makassar date -d "@${NOT_AFTER_EPOCH}" '+%Y-%m-%d %H:%M:%S %Z')"
echo "This approves deploying exactly ${TARGET_SHA} to ${HOST} once, as release ${RELEASE_ID}."
# A human approves: the answer is read from the controlling terminal, never
# from a pipe, and the signature needs the key itself (its passphrase on that
# terminal) — an ssh-agent cannot stand in for the owner.
if [ ! -t 0 ] || ! { exec 3<>/dev/tty; } 2>/dev/null; then
  refuse "the approval must be confirmed by the owner at a terminal (no controlling terminal, or stdin is not one)"
fi
printf 'Type YES to sign it with %s: ' "$KEY" >&3
answer=""
read -r answer <&3 || true
[ "$answer" = "YES" ] || refuse "not confirmed (typed '${answer}')"

# ── Sign the record FILE, then verify with the public key ─────────────────────
env -u SSH_AUTH_SOCK SSH_ASKPASS_REQUIRE=never ssh-keygen -Y sign -f "$KEY" -n "$DMS_RELEASE_APPROVAL_SIGNATURE_NAMESPACE" "$RECORD" \
  || refuse "ssh-keygen could not sign the record"
[ -s "${RECORD}.sig" ] || refuse "ssh-keygen wrote no signature"
printf '%s namespaces="%s" %s\n' "$APPROVER" "$DMS_RELEASE_APPROVAL_SIGNATURE_NAMESPACE" "$(head -n 1 -- "${KEY}.pub")" > "${WORK}/allowed_signers"
ssh-keygen -Y verify -f "${WORK}/allowed_signers" -I "$APPROVER" -n "$DMS_RELEASE_APPROVAL_SIGNATURE_NAMESPACE" \
  -s "${RECORD}.sig" < "$RECORD" >/dev/null 2>&1 || refuse "the new signature does not verify with ${KEY}.pub"

# ── The bundle ────────────────────────────────────────────────────────────────
OUT_DIR="${HOME}/DaengtisiaMS-release-approvals/${RELEASE_ID}"
if [ -L "${HOME}/DaengtisiaMS-release-approvals" ] || [ -L "$OUT_DIR" ]; then
  refuse "${OUT_DIR} or its parent is a symlink, not a private directory"
fi
mkdir -p -- "$OUT_DIR"
chmod 700 -- "${HOME}/DaengtisiaMS-release-approvals" "$OUT_DIR"
[ -d "$OUT_DIR" ] || refuse "${OUT_DIR} is not a directory"
BUNDLE="${OUT_DIR}/${APPROVAL_ID}.bundle"
(set -C && cat -- "$RECORD" "${RECORD}.sig" > "$BUNDLE") || refuse "${BUNDLE} already exists"
chmod 600 -- "$BUNDLE"
# The host splits it exactly like this; prove it round-trips before handing it over.
if ! dms_release_approval_split_bundle "$BUNDLE" "${WORK}/check.record" "${WORK}/check.sig" >/dev/null \
   || ! cmp -s "$RECORD" "${WORK}/check.record" || ! cmp -s "${RECORD}.sig" "${WORK}/check.sig"; then
  rm -f -- "${BUNDLE:?}"
  refuse "the bundle does not split back into the signed record (removed)"
fi

echo "RELEASE APPROVAL BUNDLE: ${BUNDLE}"
echo "approval_id=${APPROVAL_ID}"
echo "bundle_sha256=$(dms_release_approval_sha256 "$BUNDLE")"
echo "record_sha256=$(dms_release_approval_sha256 "$RECORD")"
echo "not_after=${NOT_AFTER}"
echo "Submit it (nothing is deployed by this step):"
echo "  ssh ${DEPLOY_ALIAS} submit-approval --release-id ${RELEASE_ID} < ${BUNDLE}"
