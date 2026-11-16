# shellcheck shell=bash
#
# DMS-RME-REVISION-PROGRAM-8D-APPROVED-SHA-GUARD-1 — exact approved release SHA guard (rule 229).
#
# Sourced (never executed) by scripts/deploy-vps-runner.sh (before anything is
# launched) and by scripts/deploy-immutable-exec.sh (inside the deploy lock,
# before the snapshot, the backup, the checkout or any other mutation).
#
# THE DEFECT THIS CLOSES. The deploy pinned whatever the tip of the base branch
# was when the bootstrap fetched it. The pin is immutable once taken, but nothing
# tied it to the commit the owner approved: a push between the approval and the
# fetch was deployed as if it were approved.
#
# THE CONTRACT. A production deploy names a release and the exact commit it
# expects (`--release-id <id> --approved-sha <40-hex>`). It proceeds only when a
# release approval record for that release, MERGED into the base branch on the
# canonical repository, approves exactly that commit:
#
#   deploy/release-approvals/<release-id>.approval   (KEY=VALUE, parsed, never sourced)
#
# The record is read from the FETCHED base-branch tip as a git object — never
# from the working tree, an environment variable or any other writable local
# file. It must name this repository, this host, this branch, the commit and its
# tree, an allowed approver, a validity window and a sprint document that quotes
# the commit. The approved commit must be on the base branch, and the branch may
# carry nothing after it except release approval records and Markdown
# documentation under docs/ (the approval's own evidence document can only be
# written after the commit it quotes; neither is part of what is deployed, which
# is exactly the approved commit). Any other change after the approved commit
# means the approval no longer describes the branch and the deploy is DENIED. The deploy is also forward-only: the running checkout must be
# an ancestor of the approved commit (going back is rollback-vps.sh's job).
#
# There is no substitution. On any mismatch the answer is DEPLOYMENT=DENIED; the
# branch tip, the running HEAD, a tag or an earlier approval are never used
# instead of the approved commit.
#
# TRUST MODEL (rule 231, DMS-RELEASE-GUARD-TRUST-HARDENING-1). Approval
# contract 2 AUTHENTICATES the approval instead of only binding it. The record
# must carry a detached SSH signature (`<release-id>.approval.sig`, made with
# `ssh-keygen -Y sign -n dms-release-approval`) over its exact bytes, by the
# principal it names in APPROVED_BY, verifiable against the release trust
# anchor: an allowed-signers file on the production host, OUTSIDE the
# repository and the checkout, root-owned and provisioned by the owner. Write
# access to the base branch is therefore no longer enough to approve a release:
# the approver's private key is, and it never leaves the approver's workstation.
# Anyone holding the approver's public key can re-check an approval offline with
# the same `ssh-keygen -Y verify` command. The record is also bound to this
# host's SSH host key (DMS_APPROVAL_HOST_KEY_SHA256 == the authority's pinned
# fingerprint == the key this host actually presents), not only to a hostname
# root can rename. Root on the production host remains inside the trust
# boundary (it can replace the anchor or the code that reads it); a signature
# proves which key approved, not which human held it (CG-07). The owner's
# decision itself is still recorded in the sprint document (CG-22).
#
# Every observed value (app dir, branch, host, authority, clock) is a PARAMETER,
# the convention of scripts/lib/production-host-preflight.sh, so the contract is
# testable without root or a server.
#
# Return codes of dms_release_approval_verify: 0 approved, 77 DENIED.

# The trusted-path checks ship beside this library. A missing helper is not a
# crash here: dms_release_approval_verify denies (77) without it.
# shellcheck source=trusted-path.sh
source "$(dirname -- "${BASH_SOURCE[0]}")/trusted-path.sh" 2>/dev/null || true

# Contract 1 (rule 229) was an unsigned record; it is refused, never upgraded.
DMS_RELEASE_APPROVAL_CONTRACT=2
DMS_RELEASE_APPROVAL_DIR="deploy/release-approvals"
# Longest window an approval may span from approved_at to not_after.
DMS_RELEASE_APPROVAL_MAX_VALIDITY_SECONDS=259200
# Clock skew tolerated for an approved_at slightly in the future.
DMS_RELEASE_APPROVAL_CLOCK_SKEW_SECONDS=300
DMS_RELEASE_APPROVAL_MAX_BYTES=4096
DMS_RELEASE_APPROVAL_DENIED=77
# The SSH signature namespace: a signature made for any other purpose (git
# commits, files) never verifies as a release approval, and vice versa.
DMS_RELEASE_APPROVAL_SIGNATURE_NAMESPACE="dms-release-approval"
DMS_RELEASE_APPROVAL_MAX_SIGNATURE_BYTES=4096

DMS_RELEASE_APPROVAL_KEYS="DMS_APPROVAL_CONTRACT DMS_APPROVAL_RELEASE_ID DMS_APPROVAL_REPOSITORY DMS_APPROVAL_HOST DMS_APPROVAL_HOST_KEY_SHA256 DMS_APPROVAL_BASE_BRANCH DMS_APPROVAL_TARGET_SHA DMS_APPROVAL_TARGET_TREE DMS_APPROVAL_GO_TAG DMS_APPROVAL_APPROVED_BY DMS_APPROVAL_APPROVED_AT DMS_APPROVAL_NOT_AFTER DMS_APPROVAL_EVIDENCE"

# CONTRACT 3 (DMS-RELEASE-APPROVAL-DIRECT-1, docs/sprints/dms-release-approval-direct-1.md).
# The same owner-signed binding without the approval commit: a canonical record
# (exactly these 14 keys, in this order, see dms_release_approval_parse_direct)
# and its signature are submitted through the operator entrypoint
# (`submit-approval`, scripts/release-approval-submit.sh), verified by root and
# stored in the root-only approval store the authority declares
# (DMS_PRODUCTION_RELEASE_APPROVAL_STORE/<release-id>/<approval-id>.approval,
# .sig, .receipt). The deploy looks there first: exactly one active stored
# approval whose target is the requested commit is verified as contract 3 —
# every contract-2 binding but the evidence document, plus the scope, the
# approval id (spent once) and the required CI of the exact commit, queried by
# the host itself. A release with no stored approval uses contract 2
# unchanged; a release whose stored approvals do not match is DENIED and never
# falls back. Contract 3 is accepted only where the authority lists it in
# DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS.
DMS_RELEASE_APPROVAL_DIRECT_CONTRACT=3
DMS_RELEASE_APPROVAL_DIRECT_SCOPE="production-deploy"
DMS_RELEASE_APPROVAL_DIRECT_KEYS="DMS_APPROVAL_CONTRACT DMS_APPROVAL_ID DMS_APPROVAL_RELEASE_ID DMS_APPROVAL_SCOPE DMS_APPROVAL_REPOSITORY DMS_APPROVAL_HOST DMS_APPROVAL_HOST_KEY_SHA256 DMS_APPROVAL_BASE_BRANCH DMS_APPROVAL_TARGET_SHA DMS_APPROVAL_TARGET_TREE DMS_APPROVAL_GO_TAG DMS_APPROVAL_APPROVED_BY DMS_APPROVAL_APPROVED_AT DMS_APPROVAL_NOT_AFTER"
# A submitted bundle: the record followed by its armored signature.
DMS_RELEASE_APPROVAL_MAX_BUNDLE_BYTES=8192
DMS_RELEASE_APPROVAL_SIGNATURE_BEGIN="-----BEGIN SSH SIGNATURE-----"
DMS_RELEASE_APPROVAL_SIGNATURE_END="-----END SSH SIGNATURE-----"
# The required-CI query (contract 3) goes to this API only, over TLS.
DMS_RELEASE_APPROVAL_GITHUB_API="https://api.github.com"
# Requests one required-CI check makes (runs, then jobs). They are
# unauthenticated: GitHub allows 60 per hour per host address, and a deploy
# runs the guard twice (runner, then bootstrap).
DMS_RELEASE_APPROVAL_CI_REQUESTS=2
# Requests the check must leave unused for a later deploy. Set here, never read
# from the environment: 0 for `start` and the bootstrap; the runner's
# `check-approval` sets 4 (one deploy) after sourcing this library.
DMS_RELEASE_APPROVAL_CI_RESERVE=0
# The parsed contract-3 record (dms_release_approval_parse_direct).
declare -gA DMS_RELEASE_APPROVAL_DIRECT_RECORD=()
DMS_RELEASE_APPROVAL_DIRECT_WHY=""

dms_release_approval_valid_id() {
  [[ "${1:-}" =~ ^[a-z0-9][a-z0-9-]{2,79}$ ]]
}

dms_release_approval_valid_sha() {
  [[ "${1:-}" =~ ^[0-9a-f]{40}$ ]]
}

dms_release_approval_deny() {
  echo "release-approval: DENIED — $*"
  echo "RELEASE APPROVAL: DENIED"
  echo "DEPLOYMENT=DENIED"
  return "$DMS_RELEASE_APPROVAL_DENIED"
}

dms_release_approval_authority_value() {
  # The LAST KEY= line of the authority, quotes stripped — the semantics of
  # dms_preflight_authority_value in scripts/lib/production-host-preflight.sh.
  local file="$1" key="$2" line value
  line="$(grep -E "^${key}=" "$file" 2>/dev/null | tail -n 1)" || true
  value="${line#*=}"
  value="${value%\"}"
  value="${value#\"}"
  printf '%s' "$value"
}

dms_release_approval_iso_epoch() {
  # Canonical `YYYY-MM-DDTHH:MM:SSZ` only, and it must round-trip (GNU date
  # normalises 2026-02-30 instead of refusing it).
  local iso="${1:-}" epoch back
  [[ "$iso" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || return 1
  epoch="$(date -u -d "$iso" +%s 2>/dev/null)" || return 1
  back="$(date -u -d "@${epoch}" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null)" || return 1
  [ "$back" = "$iso" ] || return 1
  printf '%s' "$epoch"
}

dms_release_approval_normalize_repository() {
  # GitHub https / scp-style / ssh URLs become OWNER/REPO. Anything else (a
  # different host, a local path) is returned as-is, minus a trailing `.git`,
  # so it can only ever match an authority that names exactly that location.
  local url="${1%/}"
  url="${url%.git}"
  if [[ "$url" =~ ^https://([^/@]+@)?github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)$ ]]; then
    printf '%s' "${BASH_REMATCH[2]}"
  elif [[ "$url" =~ ^git@github\.com:([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)$ ]]; then
    printf '%s' "${BASH_REMATCH[1]}"
  elif [[ "$url" =~ ^ssh://git@github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)$ ]]; then
    printf '%s' "${BASH_REMATCH[1]}"
  else
    printf '%s' "$url"
  fi
}

dms_release_approval_git() {
  # No safe.directory override: git's dubious-ownership refusal stays in force
  # (the production checkout is root-owned and the guard runs as root).
  git -C "$DMS_RELEASE_APPROVAL_GIT_DIR" "$@"
}

dms_release_approval_is_github_url() {
  local url="${1%/}"
  url="${url%.git}"
  [[ "$url" =~ ^https://([^/@]+@)?github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ \
     || "$url" =~ ^git@github\.com:[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ \
     || "$url" =~ ^ssh://git@github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]
}

dms_release_approval_observed_host_key() {
  # dms_release_approval_observed_host_key <authority> — the SHA-256
  # fingerprint of the public host key this host presents to SSH clients
  # (DMS_PRODUCTION_HOST_KEY_FILE). Prints nothing and fails when unreadable.
  dms_trusted_host_key_fingerprint "$(dms_release_approval_authority_value "$1" DMS_PRODUCTION_HOST_KEY_FILE)"
}

dms_release_approval_regular_file() {
  # dms_release_approval_regular_file <commit> <path> — true only for a regular,
  # non-executable file entry (a symlink's blob is its target text, not content).
  local entry
  entry="$(dms_release_approval_git ls-tree "$1" -- "$2" 2>/dev/null)" || return 1
  [[ "$entry" == "100644 blob "* ]]
}

dms_release_approval_verify() {
  # dms_release_approval_verify <app_dir> <branch> <release_id> <approved_sha> <authority> <this_host> <now_epoch> <this_host_key> <deploy_uid>
  #
  # <this_host_key> is the observed SHA256 fingerprint of this host's SSH host
  # key (dms_release_approval_observed_host_key); <deploy_uid> is `id -u` of the
  # deploy identity, which the release trust anchor must be owned by (or root).
  #
  # On approval prints `RELEASE APPROVAL: GO` and the bound facts, and sets
  # DMS_RELEASE_APPROVED_SHA, DMS_RELEASE_APPROVAL_TIP, DMS_RELEASE_APPROVAL_BLOB,
  # DMS_RELEASE_APPROVAL_PATH, DMS_RELEASE_APPROVAL_SIGNATURE_BLOB,
  # DMS_RELEASE_APPROVAL_SIGNER_KEY and DMS_RELEASE_APPROVAL_HOST_KEY, and
  # DMS_RELEASE_APPROVAL_VERIFIED_CONTRACT (2 or 3). A contract-3 approval also
  # sets DMS_RELEASE_APPROVAL_ID, DMS_RELEASE_APPROVAL_RECORD_SHA256,
  # DMS_RELEASE_APPROVAL_SIGNATURE_SHA256, DMS_RELEASE_APPROVAL_STORE_PATH (the
  # release's directory in the store) and DMS_RELEASE_APPROVAL_VERIFIED_CI_RUN_ID
  # (the GitHub run whose CI it verified); its BLOB and SIGNATURE_BLOB are empty.
  #
  # The base branch is fetched into a PRIVATE ref for this invocation, never read
  # through the shared FETCH_HEAD that any other fetch on the host (a rollback's
  # `git fetch --tags`) can overwrite between our fetch and our read. Everything
  # after that read uses immutable object ids; the ref is removed on every
  # return. A signal skips that: the caller's EXIT trap removes
  # refs/dms-release-approval/$$-* (the bootstrap's cleanup, the runner's
  # check-approval); this library installs no trap of its own.
  local rc=0
  DMS_RELEASE_APPROVAL_GIT_DIR="$1"
  DMS_RELEASE_APPROVAL_REF="refs/dms-release-approval/$$-${RANDOM}${RANDOM}"
  dms_release_approval_verify_inner "$@" || rc=$?
  dms_release_approval_git update-ref -d "$DMS_RELEASE_APPROVAL_REF" >/dev/null 2>&1 || true
  return "$rc"
}

# The DMS_RELEASE_APPROV* results are read by the sourcing runner/bootstrap.
# shellcheck disable=SC2034
dms_release_approval_verify_inner() {
  local app_dir="$1" branch="$2" release_id="$3" approved_sha="$4" authority="$5" this_host="$6" now="$7"
  local this_host_key="${8:-}" deploy_uid="${9:-}"
  local prod_host frozen_hosts prod_repo approvers origin_url origin_repo prod_host_key anchor
  local tip path blob size content line key value object_type tree changed head
  local approved_epoch not_after_epoch manifest_tag evidence_blob local_branch
  local sig_path sig_blob sig_text verdict signer_key
  local approval_contracts select_rc=0 direct_dir="" direct_id="" id_state
  local -A record=()

  DMS_RELEASE_APPROVED_SHA=""
  DMS_RELEASE_APPROVAL_TIP=""
  DMS_RELEASE_APPROVAL_BLOB=""
  DMS_RELEASE_APPROVAL_PATH=""
  DMS_RELEASE_APPROVAL_SIGNATURE_BLOB=""
  DMS_RELEASE_APPROVAL_SIGNER_KEY=""
  DMS_RELEASE_APPROVAL_HOST_KEY=""
  DMS_RELEASE_APPROVAL_VERIFIED_CONTRACT=""
  DMS_RELEASE_APPROVAL_ID=""
  DMS_RELEASE_APPROVAL_RECORD_SHA256=""
  DMS_RELEASE_APPROVAL_SIGNATURE_SHA256=""
  DMS_RELEASE_APPROVAL_STORE_PATH=""
  DMS_RELEASE_APPROVAL_VERIFIED_CI_RUN_ID=""

  echo "== Release approval guard (rules 229 and 231) =="
  declare -F dms_trusted_file >/dev/null \
    || { dms_release_approval_deny "the trusted-path library (scripts/lib/trusted-path.sh) is missing beside the guard"; return; }
  [[ "$deploy_uid" =~ ^[0-9]+$ ]] \
    || { dms_release_approval_deny "the deploy identity '${deploy_uid}' is not a uid"; return; }

  # ── 1. What the operator asked for ─────────────────────────────────────────
  dms_release_approval_valid_id "$release_id" \
    || { dms_release_approval_deny "release id '${release_id}' is not a release identifier (lowercase, digits and '-', 3-80 characters)"; return; }
  dms_release_approval_valid_sha "$approved_sha" \
    || { dms_release_approval_deny "approved SHA '${approved_sha}' is not a full 40-character lowercase commit SHA (abbreviations, refs and tags are refused)"; return; }
  [[ "$now" =~ ^[0-9]+$ ]] || { dms_release_approval_deny "the clock value '${now}' is not an epoch"; return; }
  [[ "$branch" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]*$ && "$branch" != *..* ]] \
    || { dms_release_approval_deny "base branch '${branch}' is not a plain branch name"; return; }

  # ── 2. The host authority ──────────────────────────────────────────────────
  if [ -z "$authority" ] || [ ! -r "$authority" ]; then
    dms_release_approval_deny "host authority ${authority:-<none>} is missing or unreadable — refusing to guess"
    return
  fi
  prod_host="$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_HOSTNAME)"
  frozen_hosts="$(dms_release_approval_authority_value "$authority" DMS_FROZEN_LEGACY_HOSTNAMES)"
  prod_repo="$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_REPOSITORY)"
  approvers="$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_RELEASE_APPROVERS)"
  prod_host_key="$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_HOST_KEY_SHA256)"
  anchor="$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_RELEASE_TRUST_ANCHOR)"
  [ -n "$prod_host" ] || { dms_release_approval_deny "the authority declares no production hostname"; return; }
  [ -n "$prod_repo" ] || { dms_release_approval_deny "the authority declares no production repository (DMS_PRODUCTION_REPOSITORY)"; return; }
  [ -n "$approvers" ] || { dms_release_approval_deny "the authority declares no release approver (DMS_PRODUCTION_RELEASE_APPROVERS)"; return; }
  for line in $frozen_hosts; do
    [ "$this_host" = "$line" ] && { dms_release_approval_deny "this host '${this_host}' is FROZEN legacy infrastructure"; return; }
  done
  [ "$this_host" = "$prod_host" ] \
    || { dms_release_approval_deny "this host is '${this_host:-<empty>}', the declared production host is '${prod_host}'"; return; }
  # A hostname is a setting; the host key is the identity the workstation pins
  # (CG-14). Both must be the declared production host's.
  [[ "$prod_host_key" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]] \
    || { dms_release_approval_deny "the authority pins no SHA256 host key fingerprint (DMS_PRODUCTION_HOST_KEY_SHA256)"; return; }
  [ "$this_host_key" = "$prod_host_key" ] \
    || { dms_release_approval_deny "this host presents host key '${this_host_key:-<unreadable>}', the declared production host key is '${prod_host_key}'"; return; }
  echo "PASS: host '${this_host}' is the declared production host (host key ${prod_host_key})"

  # ── 2b. Which approval contract (DMS-RELEASE-APPROVAL-DIRECT-1) ────────────
  # The approval store first: a release with stored approvals is decided by
  # them alone (contract 3, or DENIED); only a release with none uses contract 2.
  approval_contracts="$(dms_release_approval_contracts "$authority")" \
    || { dms_release_approval_deny "$approval_contracts"; return; }
  dms_release_approval_direct_select "$authority" "$app_dir" "$deploy_uid" "$release_id" "$approved_sha" "$now" "$approval_contracts" \
    || select_rc=$?
  case "$select_rc" in
    0)
      direct_dir="$DMS_RELEASE_APPROVAL_SELECTED_DIR"
      direct_id="$DMS_RELEASE_APPROVAL_SELECTED_ID"
      ;;
    1)
      dms_release_approval_in_words 2 "$approval_contracts" \
        || { dms_release_approval_deny "release ${release_id} has no stored approval, and this host accepts approval contracts '${approval_contracts}' only (DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS)"; return; }
      ;;
    *)
      return "$select_rc"
      ;;
  esac

  # ── 3. Repository identity ─────────────────────────────────────────────────
  if ! origin_url="$(dms_release_approval_git remote get-url origin 2>/dev/null)"; then
    dms_release_approval_deny "the checkout ${app_dir} has no 'origin' remote"
    return
  fi
  # An OWNER/REPO authority is a GitHub repository: a local path that merely
  # spells OWNER/REPO (a directory inside the checkout) is not it.
  if [[ "$prod_repo" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] && ! dms_release_approval_is_github_url "$origin_url"; then
    dms_release_approval_deny "origin '${origin_url}' is not a GitHub URL for the declared repository '${prod_repo}'"
    return
  fi
  origin_repo="$(dms_release_approval_normalize_repository "$origin_url")"
  [ "$origin_repo" = "$prod_repo" ] \
    || { dms_release_approval_deny "origin is '${origin_repo}', the declared production repository is '${prod_repo}'"; return; }
  echo "PASS: origin is the declared repository '${prod_repo}'"

  # ── 4. Resolve the base branch from the remote (refs only) ─────────────────
  # Into the private ref only: --refmap= leaves refs/remotes/origin/* alone,
  # FETCH_HEAD is not written, and no gc/maintenance is spawned as root.
  if ! dms_release_approval_git fetch --no-tags --quiet --refmap= --no-write-fetch-head --no-auto-maintenance \
       origin "+refs/heads/${branch}:${DMS_RELEASE_APPROVAL_REF}"; then
    dms_release_approval_deny "could not fetch origin ${branch}"
    return
  fi
  tip="$(dms_release_approval_git rev-parse --verify --quiet "${DMS_RELEASE_APPROVAL_REF}^{commit}")" \
    || { dms_release_approval_deny "the fetched ${branch} does not resolve to a commit"; return; }
  echo "Base branch tip:    ${tip} (origin ${branch})"

  object_type="$(dms_release_approval_git cat-file -t "$approved_sha" 2>/dev/null)" || object_type="missing"
  [ "$object_type" = "commit" ] \
    || { dms_release_approval_deny "approved SHA ${approved_sha} is a '${object_type}' object in this repository, not a commit"; return; }

  # ── 5-8. The approval itself ──────────────────────────────────────────────
  # Contract 3: the selected stored approval (record, signature, window). Contract 2
  # (rule 231): the record, its signature and its evidence document from the
  # fetched tip (dms_release_approval_git_record, steps 5 to 8, unchanged).
  if [ -n "$direct_id" ]; then
    dms_release_approval_direct_record "$direct_dir" "$direct_id" "$release_id" "$approved_sha" "$branch" \
      "$prod_repo" "$prod_host" "$prod_host_key" "$approvers" "$anchor" "$app_dir" "$deploy_uid" "$now" || return
    for key in $DMS_RELEASE_APPROVAL_DIRECT_KEYS; do
      record[$key]="${DMS_RELEASE_APPROVAL_DIRECT_RECORD[$key]}"
    done
    signer_key="$DMS_RELEASE_APPROVAL_DIRECT_SIGNER"
    path="${direct_dir}/${direct_id}.approval"
    blob=""
    sig_blob=""
  else
    dms_release_approval_git_record || return
  fi

  # ── 9. The approved commit is merged; only approvals and docs follow it ───
  dms_release_approval_git merge-base --is-ancestor "$approved_sha" "$tip" \
    || { dms_release_approval_deny "approved SHA ${approved_sha} is not on origin ${branch} (not merged)"; return; }
  # Plumbing, and a failure is a denial: an empty list from a failed diff would
  # otherwise read as "nothing follows" (independent review, 8D PR2, finding 2).
  if ! changed="$(dms_release_approval_git diff-tree -r --no-commit-id --name-only --no-renames "$approved_sha" "$tip")"; then
    dms_release_approval_deny "could not list the changes between ${approved_sha} and ${tip}"
    return
  fi
  while IFS= read -r line; do
    [ -n "$line" ] || continue
    if [[ "$line" =~ ^deploy/release-approvals/[a-z0-9][a-z0-9-]{2,79}\.approval(\.sig)?$ ]] \
       || [[ "$line" =~ ^docs/[A-Za-z0-9._/-]+\.md$ && "$line" != *..* ]]; then
      continue
    fi
    { dms_release_approval_deny "origin ${branch} moved past the approved commit with '${line}' — the approval no longer describes the branch; re-approve the new commit"; return; }
  done <<< "$changed"
  echo "PASS: ${approved_sha} is on origin ${branch}; only release approval records and documentation follow it"

  # ── 10. Forward only: the running code must be an ancestor of the target ───
  if ! head="$(dms_release_approval_git rev-parse --verify --quiet 'HEAD^{commit}')"; then
    dms_release_approval_deny "the checkout HEAD of ${app_dir} could not be read"
    return
  fi
  dms_release_approval_git merge-base --is-ancestor "$head" "$approved_sha" \
    || { dms_release_approval_deny "the running checkout ${head} is not an ancestor of ${approved_sha} — a deploy never moves production backwards (use rollback-vps.sh)"; return; }
  # The deploy checks out the local branch and fast-forwards it; a local branch
  # already past the target (e.g. after a rollback) would only fail after the
  # backup, so refuse it here, before anything happens.
  # An absent local branch would be created at the remote tip by that checkout.
  local_branch="$(dms_release_approval_git rev-parse --verify --quiet "refs/heads/${branch}^{commit}")" \
    || { dms_release_approval_deny "the checkout has no local branch ${branch} to fast-forward to the approved commit"; return; }
  dms_release_approval_git merge-base --is-ancestor "$local_branch" "$approved_sha" \
    || { dms_release_approval_deny "the local branch ${branch} (${local_branch}) is not an ancestor of ${approved_sha} — it cannot be fast-forwarded to the approved commit"; return; }

  # ── 11. One approval, one completed deploy (rule 231) ──────────────────────
  dms_release_approval_spent "$authority" "$app_dir" "$deploy_uid" "$release_id" || return
  if [ -n "$direct_id" ]; then
    # Contract 3: the approval id is spent once too.
    id_state="$(dms_release_approval_id_state "$authority" "$app_dir" "$deploy_uid" "$direct_id")" \
      || { dms_release_approval_deny "$id_state"; return; }
    [ "$id_state" = "unspent" ] \
      || { dms_release_approval_deny "approval ${direct_id} was already used: ${id_state} — an approval deploys once"; return; }
    echo "PASS: approval ${direct_id} is unspent on this host"

    # ── 12. Required CI of the exact commit (contract 3) ─────────────────────
    # Asked of GitHub by the host itself, from the authority's repository,
    # branch, workflow and job names — never from anything in the record.
    dms_release_approval_ci_verify "$prod_repo" "$approved_sha" "$branch" \
      "$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_CI_WORKFLOW)" \
      "$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_CI_REQUIRED_JOBS)" \
      "$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_CI_REQUIRED_STEP)" \
      "$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_CI_FORBIDDEN_STEP)" || return
  fi

  DMS_RELEASE_APPROVED_SHA="$approved_sha"
  DMS_RELEASE_APPROVAL_TIP="$tip"
  DMS_RELEASE_APPROVAL_BLOB="$blob"
  DMS_RELEASE_APPROVAL_PATH="$path"
  DMS_RELEASE_APPROVAL_SIGNATURE_BLOB="$sig_blob"
  DMS_RELEASE_APPROVAL_SIGNER_KEY="$signer_key"
  DMS_RELEASE_APPROVAL_HOST_KEY="$prod_host_key"
  echo "PASS: approved by '${record[DMS_APPROVAL_APPROVED_BY]}' at ${record[DMS_APPROVAL_APPROVED_AT]}, valid until ${record[DMS_APPROVAL_NOT_AFTER]}"
  if [ -n "$direct_id" ]; then
    DMS_RELEASE_APPROVAL_VERIFIED_CONTRACT="$DMS_RELEASE_APPROVAL_DIRECT_CONTRACT"
    DMS_RELEASE_APPROVAL_ID="$direct_id"
    DMS_RELEASE_APPROVAL_RECORD_SHA256="$DMS_RELEASE_APPROVAL_DIRECT_RECORD_SHA256"
    DMS_RELEASE_APPROVAL_SIGNATURE_SHA256="$DMS_RELEASE_APPROVAL_DIRECT_SIGNATURE_SHA256"
    DMS_RELEASE_APPROVAL_STORE_PATH="$direct_dir"
    DMS_RELEASE_APPROVAL_VERIFIED_CI_RUN_ID="$DMS_RELEASE_APPROVAL_CI_RUN_ID"
    echo "PASS: contract 3 approval ${direct_id} (record sha256 ${DMS_RELEASE_APPROVAL_RECORD_SHA256}, signature sha256 ${DMS_RELEASE_APPROVAL_SIGNATURE_SHA256}); GO tag ${record[DMS_APPROVAL_GO_TAG]}"
  else
    DMS_RELEASE_APPROVAL_VERIFIED_CONTRACT="$DMS_RELEASE_APPROVAL_CONTRACT"
    echo "PASS: evidence ${record[DMS_APPROVAL_EVIDENCE]}; GO tag ${record[DMS_APPROVAL_GO_TAG]}"
  fi
  echo "RELEASE APPROVAL: GO"
  return 0
}

# Contract 2 (rules 229 and 231), steps 5 to 8 of dms_release_approval_verify_inner,
# moved here verbatim so contract 3 can take their place. It reads and sets the
# caller's locals (bash scoping is dynamic): the record, its blob, path and
# signature, the signer key. Returns 77 (DENIED) on the first mismatch.
# shellcheck disable=SC2034
dms_release_approval_git_record() {
  # ── 5. The approval record, from the fetched tip's tree ────────────────────
  path="${DMS_RELEASE_APPROVAL_DIR}/${release_id}.approval"
  if ! blob="$(dms_release_approval_git rev-parse --verify --quiet "${tip}:${path}")"; then
    dms_release_approval_deny "no approval record ${path} on origin ${branch} at ${tip} — the release is not approved"
    return
  fi
  dms_release_approval_regular_file "$tip" "$path" \
    || { dms_release_approval_deny "${path} at ${tip} is not a regular file"; return; }
  size="$(dms_release_approval_git cat-file -s "$blob")"
  [ "$size" -le "$DMS_RELEASE_APPROVAL_MAX_BYTES" ] \
    || { dms_release_approval_deny "${path} is ${size} bytes; the limit is ${DMS_RELEASE_APPROVAL_MAX_BYTES}"; return; }
  content="$(dms_release_approval_git cat-file blob "$blob")"

  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in ''|'#'*) continue ;; esac
    if [[ ! "$line" =~ ^(DMS_APPROVAL_[A-Z0-9_]+)=([A-Za-z0-9._:/+-]+)$ ]]; then
      dms_release_approval_deny "${path} has a line that is not KEY=VALUE with a plain value: '${line:0:80}'"
      return
    fi
    key="${BASH_REMATCH[1]}"
    value="${BASH_REMATCH[2]}"
    [[ " ${DMS_RELEASE_APPROVAL_KEYS} " == *" ${key} "* ]] \
      || { dms_release_approval_deny "${path} declares an unknown key ${key}"; return; }
    [ -z "${record[$key]+set}" ] \
      || { dms_release_approval_deny "${path} declares ${key} more than once"; return; }
    record[$key]="$value"
  done <<< "$content"

  for key in $DMS_RELEASE_APPROVAL_KEYS; do
    [ -n "${record[$key]:-}" ] || { dms_release_approval_deny "${path} does not declare ${key}"; return; }
  done
  echo "Approval record:    ${tip}:${path} (blob ${blob})"

  # ── 6. Every fact in the record must match ─────────────────────────────────
  [ "${record[DMS_APPROVAL_CONTRACT]}" = "$DMS_RELEASE_APPROVAL_CONTRACT" ] \
    || { dms_release_approval_deny "the record uses approval contract '${record[DMS_APPROVAL_CONTRACT]}', this guard requires ${DMS_RELEASE_APPROVAL_CONTRACT}"; return; }
  [ "${record[DMS_APPROVAL_RELEASE_ID]}" = "$release_id" ] \
    || { dms_release_approval_deny "the record names release '${record[DMS_APPROVAL_RELEASE_ID]}', the deploy asked for '${release_id}'"; return; }
  [ "${record[DMS_APPROVAL_REPOSITORY]}" = "$prod_repo" ] \
    || { dms_release_approval_deny "the record approves repository '${record[DMS_APPROVAL_REPOSITORY]}', this is '${prod_repo}'"; return; }
  [ "${record[DMS_APPROVAL_HOST]}" = "$prod_host" ] \
    || { dms_release_approval_deny "the record approves host '${record[DMS_APPROVAL_HOST]}', this is '${prod_host}'"; return; }
  [ "${record[DMS_APPROVAL_HOST_KEY_SHA256]}" = "$prod_host_key" ] \
    || { dms_release_approval_deny "the record approves host key '${record[DMS_APPROVAL_HOST_KEY_SHA256]}', this host's is '${prod_host_key}'"; return; }
  [ "${record[DMS_APPROVAL_BASE_BRANCH]}" = "$branch" ] \
    || { dms_release_approval_deny "the record approves branch '${record[DMS_APPROVAL_BASE_BRANCH]}', the deploy follows '${branch}'"; return; }
  [ "${record[DMS_APPROVAL_TARGET_SHA]}" = "$approved_sha" ] \
    || { dms_release_approval_deny "the record approves ${record[DMS_APPROVAL_TARGET_SHA]}, the deploy asked for ${approved_sha} — no substitution"; return; }
  tree="$(dms_release_approval_git rev-parse --verify "${approved_sha}^{tree}")"
  [ "${record[DMS_APPROVAL_TARGET_TREE]}" = "$tree" ] \
    || { dms_release_approval_deny "the record approves tree ${record[DMS_APPROVAL_TARGET_TREE]}, commit ${approved_sha} has tree ${tree}"; return; }
  dms_release_approval_in_words "${record[DMS_APPROVAL_APPROVED_BY]}" "$approvers" \
    || { dms_release_approval_deny "approver '${record[DMS_APPROVAL_APPROVED_BY]}' is not a declared release approver (${approvers})"; return; }

  [[ "${record[DMS_APPROVAL_GO_TAG]}" =~ ^[a-z0-9][a-z0-9.-]*-go$ ]] \
    || { dms_release_approval_deny "GO tag '${record[DMS_APPROVAL_GO_TAG]}' does not follow the lowercase '-go' convention"; return; }
  manifest_tag="$(dms_release_approval_git cat-file -p "${approved_sha}:.sprint/current.yml" 2>/dev/null \
    | sed -n 's/^go_tag:[[:space:]]*//p' | tail -n 1 | tr -d '[:space:]')" || manifest_tag=""
  [ "$manifest_tag" = "${record[DMS_APPROVAL_GO_TAG]}" ] \
    || { dms_release_approval_deny "the record names GO tag '${record[DMS_APPROVAL_GO_TAG]}', the approved commit's .sprint/current.yml declares '${manifest_tag:-<none>}'"; return; }

  # ── 6b. The approver's signature (rule 231) ─────────────────────────────────
  # The record's exact bytes must carry a detached SSH signature, read from the
  # same fetched commit, by the principal APPROVED_BY names, verifiable against
  # the release trust anchor on THIS host (never a file from the repository).
  sig_path="${path}.sig"
  sig_blob="$(dms_release_approval_git rev-parse --verify --quiet "${tip}:${sig_path}")" \
    || { dms_release_approval_deny "no signature ${sig_path} on origin ${branch} at ${tip} — an unsigned record is not an approval"; return; }
  dms_release_approval_regular_file "$tip" "$sig_path" \
    || { dms_release_approval_deny "${sig_path} at ${tip} is not a regular file"; return; }
  size="$(dms_release_approval_git cat-file -s "$sig_blob")"
  [ "$size" -le "$DMS_RELEASE_APPROVAL_MAX_SIGNATURE_BYTES" ] \
    || { dms_release_approval_deny "${sig_path} is ${size} bytes; the limit is ${DMS_RELEASE_APPROVAL_MAX_SIGNATURE_BYTES}"; return; }
  sig_text="$(dms_release_approval_git cat-file blob "$sig_blob")" || sig_text=""
  [[ "$sig_text" == "-----BEGIN SSH SIGNATURE-----"* ]] \
    || { dms_release_approval_deny "${sig_path} is not an armored SSH signature"; return; }
  [ -n "$anchor" ] \
    || { dms_release_approval_deny "the authority declares no release trust anchor (DMS_PRODUCTION_RELEASE_TRUST_ANCHOR)"; return; }
  if ! value="$(dms_trusted_file "release trust anchor" "$anchor" "$deploy_uid" "$app_dir")"; then
    dms_release_approval_deny "${value} — the owner provisions it (runbook production-host-identity.md section 6d)"
    return
  fi
  command -v ssh-keygen >/dev/null 2>&1 \
    || { dms_release_approval_deny "ssh-keygen is not installed; the signature cannot be verified"; return; }
  # The record is streamed from the object store (exact bytes); so is the
  # signature. A non-zero exit, from either side of the pipe, is a denial.
  if ! verdict="$(dms_release_approval_git cat-file blob "$blob" \
        | ssh-keygen -Y verify -f "$anchor" -I "${record[DMS_APPROVAL_APPROVED_BY]}" \
            -n "$DMS_RELEASE_APPROVAL_SIGNATURE_NAMESPACE" \
            -s <(dms_release_approval_git cat-file blob "$sig_blob") 2>&1)"; then
    dms_release_approval_deny "the signature ${sig_path} does not verify for '${record[DMS_APPROVAL_APPROVED_BY]}' against the trust anchor ${anchor}: ${verdict//$'\n'/ }"
    return
  fi
  signer_key=""
  if [[ "$verdict" =~ Good\ \"${DMS_RELEASE_APPROVAL_SIGNATURE_NAMESPACE}\"\ signature\ for\ ([^ ]+)\ with\ ([A-Z0-9-]+)\ key\ (SHA256:[A-Za-z0-9+/]{43}) ]] \
     && [ "${BASH_REMATCH[1]}" = "${record[DMS_APPROVAL_APPROVED_BY]}" ]; then
    signer_key="${BASH_REMATCH[3]}"
  fi
  [ -n "$signer_key" ] \
    || { dms_release_approval_deny "ssh-keygen accepted ${sig_path} but did not report a good signature by '${record[DMS_APPROVAL_APPROVED_BY]}' — refusing to guess"; return; }
  echo "PASS: ${sig_path} is a good '${DMS_RELEASE_APPROVAL_SIGNATURE_NAMESPACE}' signature by '${record[DMS_APPROVAL_APPROVED_BY]}' (key ${signer_key}, anchor ${anchor})"

  # ── 7. Validity window (no stale or open-ended approval) ───────────────────
  approved_epoch="$(dms_release_approval_iso_epoch "${record[DMS_APPROVAL_APPROVED_AT]}")" \
    || { dms_release_approval_deny "approved_at '${record[DMS_APPROVAL_APPROVED_AT]}' is not a canonical UTC instant"; return; }
  not_after_epoch="$(dms_release_approval_iso_epoch "${record[DMS_APPROVAL_NOT_AFTER]}")" \
    || { dms_release_approval_deny "not_after '${record[DMS_APPROVAL_NOT_AFTER]}' is not a canonical UTC instant"; return; }
  [ "$not_after_epoch" -gt "$approved_epoch" ] \
    || { dms_release_approval_deny "the approval window ends (${record[DMS_APPROVAL_NOT_AFTER]}) before it starts (${record[DMS_APPROVAL_APPROVED_AT]})"; return; }
  [ $((not_after_epoch - approved_epoch)) -le "$DMS_RELEASE_APPROVAL_MAX_VALIDITY_SECONDS" ] \
    || { dms_release_approval_deny "the approval window is longer than ${DMS_RELEASE_APPROVAL_MAX_VALIDITY_SECONDS} seconds"; return; }
  [ "$approved_epoch" -le $((now + DMS_RELEASE_APPROVAL_CLOCK_SKEW_SECONDS)) ] \
    || { dms_release_approval_deny "the approval is dated in the future (${record[DMS_APPROVAL_APPROVED_AT]})"; return; }
  [ "$now" -le "$not_after_epoch" ] \
    || { dms_release_approval_deny "the approval expired at ${record[DMS_APPROVAL_NOT_AFTER]} — a new approval is required"; return; }

  # ── 8. The evidence document must exist on the branch and quote the commit ─
  [[ "${record[DMS_APPROVAL_EVIDENCE]}" =~ ^docs/[A-Za-z0-9._/-]+\.md$ && "${record[DMS_APPROVAL_EVIDENCE]}" != *..* ]] \
    || { dms_release_approval_deny "evidence '${record[DMS_APPROVAL_EVIDENCE]}' is not a docs/*.md path"; return; }
  evidence_blob="$(dms_release_approval_git rev-parse --verify --quiet "${tip}:${record[DMS_APPROVAL_EVIDENCE]}")" \
    || { dms_release_approval_deny "evidence document ${record[DMS_APPROVAL_EVIDENCE]} is not on origin ${branch}"; return; }
  dms_release_approval_regular_file "$tip" "${record[DMS_APPROVAL_EVIDENCE]}" \
    || { dms_release_approval_deny "evidence document ${record[DMS_APPROVAL_EVIDENCE]} is not a regular file"; return; }
  # Read whole, then match: `cat-file | grep -q` under pipefail can turn an
  # early grep exit (SIGPIPE) into a false denial on a large document.
  value="$(dms_release_approval_git cat-file blob "$evidence_blob")" || value=""
  [[ "$value" == *"$approved_sha"* ]] \
    || { dms_release_approval_deny "evidence document ${record[DMS_APPROVAL_EVIDENCE]} does not quote ${approved_sha}"; return; }
  return 0
}

dms_release_approval_in_words() {
  local needle="$1" item
  # shellcheck disable=SC2086
  for item in $2; do
    [ "$item" = "$needle" ] && return 0
  done
  return 1
}

dms_release_approval_spent() {
  # dms_release_approval_spent <authority> <app_dir> <deploy_uid> <release_id>
  #
  # Rule 231 (closes the replay rule 229 §9 claimed and §4.9 did not stop: after a
  # rollback, the forward-only check passes again). A RELEASE deploys once: it is
  # spent by the first deploy it COMPLETED on this host, whichever signed version
  # of its record that deploy used (an older, re-signed record of the same release
  # cannot be replayed). Two trusted sources, both in the evidence directory: the
  # spend record the bootstrap writes inside the release lock when the deploy
  # program succeeds (spent/<release_id>.spent), and any runner record of that
  # release with result_exit=0. A failed run spends nothing, so it can be retried
  # inside the window; a re-deploy (for example after a rollback) needs a new
  # owner approval under a new release id. Returns 0 when unspent, 77 otherwise.
  local authority="$1" app_dir="$2" uid="$3" release_id="$4" dir why rec id
  # The runner records are found by pathname expansion: never let a caller's
  # `set -f` turn that into "no completed deploy" (DMS-RELEASE-APPROVAL-DIRECT-1).
  local -
  set +f
  dir="$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR)"
  [ -n "$dir" ] \
    || { dms_release_approval_deny "the authority declares no deploy evidence directory (DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR); spent approvals cannot be checked"; return; }
  dms_release_approval_valid_id "$release_id" \
    || { dms_release_approval_deny "release id '${release_id}' is not a release identifier"; return; }
  if [ ! -e "$dir" ] && [ ! -L "$dir" ]; then
    echo "PASS: release ${release_id} is unspent (no deploy evidence on this host yet)"
    return 0
  fi
  if ! why="$(dms_trusted_dir "deploy evidence directory" "$dir" "$uid" "$app_dir")"; then
    dms_release_approval_deny "${why} — spent approvals cannot be checked"
    return
  fi
  rec="${dir}/spent/${release_id}.spent"
  if [ -e "$rec" ] || [ -L "$rec" ]; then
    id="$(sed -n '/^run_id=/{s/^run_id=//p;q;}' "$rec" 2>/dev/null)"
    dms_release_approval_deny "release ${release_id} was already deployed on this host (spend record ${rec}, run ${id:-<unknown>}) — a release deploys once; a re-deploy (for example after a rollback) needs a new owner approval under a new release id"
    return
  fi
  for rec in "$dir"/deploy-*.host; do
    [ -f "$rec" ] && [ ! -L "$rec" ] || continue
    [ "$(sed -n '/^release_id=/{s/^release_id=//p;q;}' "$rec")" = "$release_id" ] || continue
    [ "$(sed -n '/^result_exit=/{s/^result_exit=//p;q;}' "$rec")" = "0" ] || continue
    id="$(sed -n '/^deploy_id=/{s/^deploy_id=//p;q;}' "$rec")"
    dms_release_approval_deny "release ${release_id} was already used by the completed deploy ${id:-<unknown>} — a release deploys once; a re-deploy (for example after a rollback) needs a new owner approval under a new release id"
    return
  done
  echo "PASS: release ${release_id} is unspent on this host"
  return 0
}

dms_release_approval_record_spent() {
  # dms_release_approval_record_spent <authority> <app_dir> <deploy_uid> <release_id> <approved_sha> <blob> <run_id> [<approval_id> <record_sha256>]
  #
  # Called by the bootstrap, INSIDE the release lock, after the deploy program
  # succeeded: the spend record is written before the lock is released, by the
  # process that actually deployed, whether or not a runner launched it. An
  # exclusive create; a failure is reported, never fatal (the deploy is done).
  # A contract-3 deploy (DMS-RELEASE-APPROVAL-DIRECT-1) also spends its
  # approval id: spent/approval-<approval_id>.spent.
  local authority="$1" app_dir="$2" uid="$3" release_id="$4" approved="$5" blob="$6" run_id="$7" dir why rec
  local approval_id="${8:-}" record_sha="${9:-}" rc=0
  dir="$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR)"
  if [ -z "$dir" ] || ! why="$(dms_trusted_dir_prepare "deploy evidence directory" "$dir" "$uid" "$app_dir")" \
     || ! why="$(dms_trusted_dir_prepare "spend records" "${dir}/spent" "$uid" "$app_dir")"; then
    echo "DEPLOY_APPROVAL_SPENT=NOT-RECORDED (${why:-no evidence directory declared})" >&2
    return 1
  fi
  rec="${dir}/spent/${release_id}.spent"
  if ! (umask 077 && set -C && printf 'release_id=%s\napproved_sha=%s\napproval_blob=%s\nrun_id=%s\nspent_at=%s\n' \
        "$release_id" "$approved" "$blob" "$run_id" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$rec") 2>/dev/null; then
    echo "DEPLOY_APPROVAL_SPENT=NOT-RECORDED (${rec} already exists or cannot be created)" >&2
    rc=1
  else
    echo "DEPLOY_APPROVAL_SPENT=${rec}"
  fi
  [ -n "$approval_id" ] || return "$rc"
  rec="${dir}/spent/approval-${approval_id}.spent"
  if [[ ! "$approval_id" =~ ^[0-9a-f]{32}$ ]] \
     || ! (umask 077 && set -C && printf 'approval_id=%s\nrelease_id=%s\napproved_sha=%s\napproval_record_sha256=%s\nrun_id=%s\nspent_at=%s\n' \
        "$approval_id" "$release_id" "$approved" "$record_sha" "$run_id" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$rec") 2>/dev/null; then
    echo "DEPLOY_APPROVAL_ID_SPENT=NOT-RECORDED (${rec} already exists or cannot be created)" >&2
    return 1
  fi
  echo "DEPLOY_APPROVAL_ID_SPENT=${rec}"
  return "$rc"
}

# ═══ Contract 3 — direct signed approvals (DMS-RELEASE-APPROVAL-DIRECT-1) ═══

dms_release_approval_contracts() {
  # dms_release_approval_contracts <authority> — prints the approval contracts
  # this host accepts (DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS). An authority
  # that predates contract 3 declares none and accepts contract 2 only.
  # Anything but the words 2 and 3 prints the reason and returns 1.
  local value word
  value="$(dms_release_approval_authority_value "$1" DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS)"
  [ -n "$value" ] || value="$DMS_RELEASE_APPROVAL_CONTRACT"
  for word in $value; do
    case "$word" in
      "$DMS_RELEASE_APPROVAL_CONTRACT"|"$DMS_RELEASE_APPROVAL_DIRECT_CONTRACT") ;;
      *) echo "the authority's DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS '${value}' is not a list of approval contracts (2, 3)"; return 1 ;;
    esac
  done
  printf '%s' "$value"
}

dms_release_approval_direct_value_ok() {
  # dms_release_approval_direct_value_ok <key> <value> — the value is canonical for that key.
  local key="$1" value="$2"
  case "$key" in
    DMS_APPROVAL_CONTRACT) [ "$value" = "$DMS_RELEASE_APPROVAL_DIRECT_CONTRACT" ] ;;
    DMS_APPROVAL_ID) [[ "$value" =~ ^[0-9a-f]{32}$ ]] ;;
    DMS_APPROVAL_RELEASE_ID) dms_release_approval_valid_id "$value" ;;
    DMS_APPROVAL_SCOPE) [[ "$value" =~ ^[a-z][a-z0-9-]{0,39}$ ]] ;;
    DMS_APPROVAL_REPOSITORY) [[ "$value" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] ;;
    DMS_APPROVAL_HOST) [[ "$value" =~ ^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$ ]] ;;
    DMS_APPROVAL_HOST_KEY_SHA256) [[ "$value" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]] ;;
    DMS_APPROVAL_BASE_BRANCH) [[ "$value" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$ && "$value" != *..* ]] ;;
    DMS_APPROVAL_TARGET_SHA|DMS_APPROVAL_TARGET_TREE) dms_release_approval_valid_sha "$value" ;;
    DMS_APPROVAL_GO_TAG) [[ "$value" =~ ^[a-z0-9][a-z0-9.-]{0,78}-go$ ]] ;;
    DMS_APPROVAL_APPROVED_BY) [[ "$value" =~ ^[A-Za-z0-9][A-Za-z0-9._@-]{0,63}$ ]] ;;
    DMS_APPROVAL_APPROVED_AT|DMS_APPROVAL_NOT_AFTER) dms_release_approval_iso_epoch "$value" >/dev/null ;;
    *) return 1 ;;
  esac
}

dms_release_approval_parse_direct() {
  # dms_release_approval_parse_direct <file> — accepts only the CANONICAL
  # contract-3 record: exactly the keys of DMS_RELEASE_APPROVAL_DIRECT_KEYS, in
  # that order, one `KEY=VALUE` per line, every value canonical for its key,
  # printable ASCII and LF only (no CR, tab, NUL or other byte), a final LF,
  # no comment, no blank line, at most DMS_RELEASE_APPROVAL_MAX_BYTES. One byte
  # sequence per approval, so what was signed is exactly what is parsed.
  # Fills DMS_RELEASE_APPROVAL_DIRECT_RECORD; otherwise sets
  # DMS_RELEASE_APPROVAL_DIRECT_WHY and returns 1. Never sources or evaluates.
  local file="$1" size bad lines line key value n=0
  local -a keys=()
  DMS_RELEASE_APPROVAL_DIRECT_RECORD=()
  DMS_RELEASE_APPROVAL_DIRECT_WHY=""
  read -r -a keys <<< "$DMS_RELEASE_APPROVAL_DIRECT_KEYS"
  if [ -L "$file" ] || [ ! -f "$file" ]; then
    DMS_RELEASE_APPROVAL_DIRECT_WHY="the record ${file} is not a regular file"
    return 1
  fi
  size="$(stat -c %s -- "$file" 2>/dev/null)" || size=-1
  if [ "$size" -lt 1 ] || [ "$size" -gt "$DMS_RELEASE_APPROVAL_MAX_BYTES" ]; then
    DMS_RELEASE_APPROVAL_DIRECT_WHY="the record is ${size} bytes; a record is 1 to ${DMS_RELEASE_APPROVAL_MAX_BYTES} bytes"
    return 1
  fi
  bad="$(LC_ALL=C tr -d '\n\040-\176' < "$file" | wc -c)" || bad=1
  if [ "$bad" -ne 0 ]; then
    DMS_RELEASE_APPROVAL_DIRECT_WHY="the record holds ${bad} byte(s) that are neither printable ASCII nor LF (CR, tab, NUL, non-ASCII)"
    return 1
  fi
  if [ -n "$(tail -c 1 -- "$file")" ]; then
    DMS_RELEASE_APPROVAL_DIRECT_WHY="the record does not end with a line feed"
    return 1
  fi
  lines="$(wc -l < "$file")"
  if [ "$((lines))" -ne "${#keys[@]}" ]; then
    DMS_RELEASE_APPROVAL_DIRECT_WHY="the record has $((lines)) lines; a canonical contract-3 record has exactly ${#keys[@]}"
    return 1
  fi
  while IFS= read -r line; do
    key="${keys[$n]}"
    n=$((n + 1))
    if [[ "$line" != "${key}="* ]]; then
      DMS_RELEASE_APPROVAL_DIRECT_WHY="line ${n} of the record is '${line:0:60}', the canonical record has ${key}= there (fixed order, no comment, no blank line)"
      return 1
    fi
    value="${line#"${key}="}"
    if ! dms_release_approval_direct_value_ok "$key" "$value"; then
      DMS_RELEASE_APPROVAL_DIRECT_WHY="${key} '${value:0:80}' is not a canonical value"
      return 1
    fi
    DMS_RELEASE_APPROVAL_DIRECT_RECORD[$key]="$value"
  done < "$file"
  if [ "$n" -ne "${#keys[@]}" ]; then
    DMS_RELEASE_APPROVAL_DIRECT_WHY="the record could not be read whole"
    return 1
  fi
  return 0
}

dms_release_approval_render_direct() {
  # dms_release_approval_render_direct <approval id> <release id> <repository> <host> <host key> <branch>
  #   <target sha> <target tree> <go tag> <approved by> <approved at> <not after>
  # Prints the canonical contract-3 record (the bytes the approver signs).
  # Pure: the caller validates the output with dms_release_approval_parse_direct.
  [ "$#" -eq 12 ] || return 2
  printf 'DMS_APPROVAL_CONTRACT=%s\nDMS_APPROVAL_ID=%s\nDMS_APPROVAL_RELEASE_ID=%s\nDMS_APPROVAL_SCOPE=%s\nDMS_APPROVAL_REPOSITORY=%s\nDMS_APPROVAL_HOST=%s\nDMS_APPROVAL_HOST_KEY_SHA256=%s\nDMS_APPROVAL_BASE_BRANCH=%s\nDMS_APPROVAL_TARGET_SHA=%s\nDMS_APPROVAL_TARGET_TREE=%s\nDMS_APPROVAL_GO_TAG=%s\nDMS_APPROVAL_APPROVED_BY=%s\nDMS_APPROVAL_APPROVED_AT=%s\nDMS_APPROVAL_NOT_AFTER=%s\n' \
    "$DMS_RELEASE_APPROVAL_DIRECT_CONTRACT" "$1" "$2" "$DMS_RELEASE_APPROVAL_DIRECT_SCOPE" "$3" "$4" "$5" "$6" "$7" "$8" "$9" "${10}" "${11}" "${12}"
}

dms_release_approval_split_bundle() {
  # dms_release_approval_split_bundle <bundle file> <record out> <signature out>
  # A bundle is the record's bytes immediately followed by ONE armored SSH
  # signature that ends the bundle: printable ASCII and LF only, a final LF, at
  # most DMS_RELEASE_APPROVAL_MAX_BUNDLE_BYTES. Splits at the BEGIN line into
  # two new files (exclusive create, private). Prints the reason and returns 1
  # otherwise.
  local bundle="$1" record_out="$2" sig_out="$3" size bad begins ends begin line n=0 armor=0
  if [ -L "$bundle" ] || [ ! -f "$bundle" ]; then echo "the bundle is not a regular file"; return 1; fi
  size="$(stat -c %s -- "$bundle" 2>/dev/null)" || size=-1
  if [ "$size" -lt 1 ]; then echo "the bundle is empty"; return 1; fi
  if [ "$size" -gt "$DMS_RELEASE_APPROVAL_MAX_BUNDLE_BYTES" ]; then
    echo "the bundle is larger than ${DMS_RELEASE_APPROVAL_MAX_BUNDLE_BYTES} bytes"
    return 1
  fi
  bad="$(LC_ALL=C tr -d '\n\040-\176' < "$bundle" | wc -c)" || bad=1
  if [ "$bad" -ne 0 ]; then echo "the bundle holds ${bad} byte(s) that are neither printable ASCII nor LF (binary, CR, tab, NUL)"; return 1; fi
  if [ -n "$(tail -c 1 -- "$bundle")" ]; then echo "the bundle does not end with a line feed"; return 1; fi
  begins="$(grep -c -x -F -e "$DMS_RELEASE_APPROVAL_SIGNATURE_BEGIN" -- "$bundle" 2>/dev/null)" || begins=0
  ends="$(grep -c -x -F -e "$DMS_RELEASE_APPROVAL_SIGNATURE_END" -- "$bundle" 2>/dev/null)" || ends=0
  if [ "$begins" != 1 ] || [ "$ends" != 1 ]; then
    echo "the bundle holds ${begins} signature BEGIN and ${ends} END line(s); exactly one armored SSH signature is required"
    return 1
  fi
  if [ "$(tail -n 1 -- "$bundle")" != "$DMS_RELEASE_APPROVAL_SIGNATURE_END" ]; then
    echo "the bundle carries something after its signature"
    return 1
  fi
  begin="$(grep -n -x -F -e "$DMS_RELEASE_APPROVAL_SIGNATURE_BEGIN" -- "$bundle" | cut -d: -f1)"
  if [[ ! "$begin" =~ ^[0-9]+$ ]] || [ "$begin" -lt 2 ]; then echo "the bundle carries no record before its signature"; return 1; fi
  # The armor between BEGIN and END is base64 only.
  while IFS= read -r line; do
    n=$((n + 1))
    [ "$n" -gt "$begin" ] || continue
    [ "$line" != "$DMS_RELEASE_APPROVAL_SIGNATURE_END" ] || break
    [[ "$line" =~ ^[A-Za-z0-9+/=]{1,76}$ ]] || { echo "the bundle's signature armor has a line that is not base64"; return 1; }
    armor=$((armor + 1))
  done < "$bundle"
  [ "$armor" -ge 1 ] || { echo "the bundle's signature is empty"; return 1; }
  (umask 077 && set -C && head -n "$((begin - 1))" -- "$bundle" > "$record_out") 2>/dev/null \
    || { echo "the record could not be staged"; return 1; }
  (umask 077 && set -C && tail -n "+${begin}" -- "$bundle" > "$sig_out") 2>/dev/null \
    || { echo "the signature could not be staged"; return 1; }
  size="$(stat -c %s -- "$sig_out" 2>/dev/null)" || size=-1
  if [ "$size" -lt 1 ] || [ "$size" -gt "$DMS_RELEASE_APPROVAL_MAX_SIGNATURE_BYTES" ]; then
    echo "the signature is ${size} bytes; the limit is ${DMS_RELEASE_APPROVAL_MAX_SIGNATURE_BYTES}"
    return 1
  fi
  return 0
}

dms_release_approval_sha256() {
  # dms_release_approval_sha256 <file> — the file's SHA-256, hex.
  local out
  out="$(sha256sum -- "$1" 2>/dev/null)" || return 1
  out="${out%% *}"
  [[ "$out" =~ ^[0-9a-f]{64}$ ]] || return 1
  printf '%s' "$out"
}

dms_release_approval_window() {
  # dms_release_approval_window <approved_at> <not_after> <now> — the same
  # window rule as contract 2: canonical instants, at most 72 hours, not dated
  # in the future (beyond the clock skew), not expired. Prints the reason and
  # returns 1 otherwise.
  local approved_epoch not_after_epoch now="$3"
  approved_epoch="$(dms_release_approval_iso_epoch "$1")" || { echo "approved_at '$1' is not a canonical UTC instant"; return 1; }
  not_after_epoch="$(dms_release_approval_iso_epoch "$2")" || { echo "not_after '$2' is not a canonical UTC instant"; return 1; }
  [ "$not_after_epoch" -gt "$approved_epoch" ] || { echo "the approval window ends ($2) before it starts ($1)"; return 1; }
  [ $((not_after_epoch - approved_epoch)) -le "$DMS_RELEASE_APPROVAL_MAX_VALIDITY_SECONDS" ] \
    || { echo "the approval window is longer than ${DMS_RELEASE_APPROVAL_MAX_VALIDITY_SECONDS} seconds"; return 1; }
  [ "$approved_epoch" -le $((now + DMS_RELEASE_APPROVAL_CLOCK_SKEW_SECONDS)) ] || { echo "the approval is dated in the future ($1)"; return 1; }
  [ "$now" -le "$not_after_epoch" ] || { echo "the approval expired at $2 — a new approval is required"; return 1; }
  return 0
}

dms_release_approval_signature_file() {
  # dms_release_approval_signature_file <anchor> <principal> <record file> <signature file> <deploy_uid> <app_dir>
  # The record file's exact bytes carry a good `dms-release-approval`
  # signature by <principal> under the release trust anchor (rule 231, the same
  # verification as contract 2). Prints the signing key's fingerprint, or the
  # reason and returns 1.
  local anchor="$1" principal="$2" rec="$3" sig="$4" uid="$5" app_dir="$6" why verdict size first=""
  [ -n "$anchor" ] || { echo "the authority declares no release trust anchor (DMS_PRODUCTION_RELEASE_TRUST_ANCHOR)"; return 1; }
  if ! why="$(dms_trusted_file "release trust anchor" "$anchor" "$uid" "$app_dir")"; then
    echo "${why} — the owner provisions it (runbook production-host-identity.md section 6d)"
    return 1
  fi
  command -v ssh-keygen >/dev/null 2>&1 || { echo "ssh-keygen is not installed; the signature cannot be verified"; return 1; }
  size="$(stat -c %s -- "$sig" 2>/dev/null)" || size=-1
  if [ "$size" -lt 1 ] || [ "$size" -gt "$DMS_RELEASE_APPROVAL_MAX_SIGNATURE_BYTES" ]; then
    echo "the signature is ${size} bytes; the limit is ${DMS_RELEASE_APPROVAL_MAX_SIGNATURE_BYTES}"
    return 1
  fi
  { IFS= read -r first < "$sig"; } 2>/dev/null || true
  [ "$first" = "$DMS_RELEASE_APPROVAL_SIGNATURE_BEGIN" ] || { echo "the signature is not an armored SSH signature"; return 1; }
  if ! verdict="$(ssh-keygen -Y verify -f "$anchor" -I "$principal" -n "$DMS_RELEASE_APPROVAL_SIGNATURE_NAMESPACE" -s "$sig" < "$rec" 2>&1)"; then
    echo "the signature does not verify for '${principal}' against the trust anchor ${anchor}: ${verdict//$'\n'/ }"
    return 1
  fi
  if [[ "$verdict" =~ Good\ \"${DMS_RELEASE_APPROVAL_SIGNATURE_NAMESPACE}\"\ signature\ for\ ([^ ]+)\ with\ ([A-Z0-9-]+)\ key\ (SHA256:[A-Za-z0-9+/]{43}) ]] \
     && [ "${BASH_REMATCH[1]}" = "$principal" ]; then
    printf '%s' "${BASH_REMATCH[3]}"
    return 0
  fi
  echo "ssh-keygen accepted the signature but did not report a good signature by '${principal}' — refusing to guess"
  return 1
}

dms_release_approval_store_file() {
  # dms_release_approval_store_file <path> <deploy_uid> — a stored approval
  # file: a regular file (never a symlink), owned by the deploy identity, mode
  # 0400, ONE link (a hardlink could be changed through its other name), at
  # most DMS_RELEASE_APPROVAL_MAX_BUNDLE_BYTES. Prints the reason and returns 1.
  local path="$1" uid="$2" out owner mode links size
  if [ -L "$path" ] || [ ! -f "$path" ]; then echo "${path} is not a regular file"; return 1; fi
  out="$(stat -c '%u %a %h %s' -- "$path" 2>/dev/null)" || { echo "${path} cannot be inspected"; return 1; }
  read -r owner mode links size <<< "$out"
  if [ "$owner" != "$uid" ] || [ "$mode" != "400" ] || [ "$links" != "1" ] || [ "$size" -gt "$DMS_RELEASE_APPROVAL_MAX_BUNDLE_BYTES" ]; then
    echo "${path} is uid ${owner} mode ${mode} with ${links} link(s) and ${size} bytes; a stored approval file is the deploy identity's (${uid}), mode 400, one link"
    return 1
  fi
  return 0
}

dms_release_approval_id_state() {
  # dms_release_approval_id_state <authority> <app_dir> <deploy_uid> <approval id>
  # Prints `unspent`, or `spent (<how>)` when a spend record
  # (spent/approval-<id>.spent) or a completed runner record names the id.
  # Prints the reason and returns 1 when that cannot be checked.
  local authority="$1" app_dir="$2" uid="$3" id="$4" dir why rec deploy
  local -
  set +f
  [[ "$id" =~ ^[0-9a-f]{32}$ ]] || { echo "approval id '${id}' is not 32 lowercase hex characters"; return 1; }
  dir="$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR)"
  [ -n "$dir" ] || { echo "the authority declares no deploy evidence directory (DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR); spent approvals cannot be checked"; return 1; }
  if [ ! -e "$dir" ] && [ ! -L "$dir" ]; then
    echo "unspent"
    return 0
  fi
  if ! why="$(dms_trusted_dir "deploy evidence directory" "$dir" "$uid" "$app_dir")"; then
    echo "${why} — spent approvals cannot be checked"
    return 1
  fi
  rec="${dir}/spent/approval-${id}.spent"
  if [ -e "$rec" ] || [ -L "$rec" ]; then
    echo "spent (spend record ${rec})"
    return 0
  fi
  for rec in "$dir"/deploy-*.host; do
    if [ ! -f "$rec" ] || [ -L "$rec" ]; then continue; fi
    [ "$(sed -n '/^approval_id=/{s/^approval_id=//p;q;}' "$rec")" = "$id" ] || continue
    [ "$(sed -n '/^result_exit=/{s/^result_exit=//p;q;}' "$rec")" = "0" ] || continue
    deploy="$(sed -n '/^deploy_id=/{s/^deploy_id=//p;q;}' "$rec")"
    echo "spent (completed deploy ${deploy:-<unknown>})"
    return 0
  done
  echo "unspent"
}

dms_release_approval_store_names() {
  # dms_release_approval_store_names <dir> — one entry name per line, read with
  # find (never pathname expansion: under `set -f` a glob reads nothing, and an
  # unread directory must never look like an empty one). Returns 1 when the
  # directory cannot be listed.
  find "$1" -mindepth 1 -maxdepth 1 -printf '%f\n' 2>/dev/null
}

dms_release_approval_store_scan() {
  # dms_release_approval_store_scan <authority> <app_dir> <deploy_uid> <release dir> <release id> <now>
  # One line per stored approval of the release, ordered by id:
  #   <approval id> <active|expired|spent|withdrawn> <target sha> <not_after>
  # The directory may hold only complete sets <id>.approval/.sig/.receipt (plus
  # an optional <id>.withdrawn marker, scripts/release-approval-withdraw.sh) of
  # stored approval files whose record is canonical and names this release
  # and that id. Anything else (a temporary file of an interrupted submission,
  # an incomplete set, a symlink, a hardlink, a stray name) prints the reason
  # and returns 1: a store the guard cannot read completely is never read as
  # "no approval". The scan only reads; leftovers of an interrupted submission
  # are cleared by the next submission, under the release's lock.
  local authority="$1" app_dir="$2" uid="$3" dir="$4" release_id="$5" now="$6"
  local names name id why state not_after
  local -A parts=()
  if [ ! -r "$dir" ] || [ ! -x "$dir" ]; then echo "the approval directory ${dir} cannot be read"; return 1; fi
  if ! names="$(dms_release_approval_store_names "$dir")"; then
    echo "the approval directory ${dir} cannot be listed"
    return 1
  fi
  if [ -n "$names" ]; then
    while IFS= read -r name; do
      if [[ ! "$name" =~ ^([0-9a-f]{32})\.(approval|sig|receipt|withdrawn)$ ]]; then
        if [[ "$name" =~ ^\.[0-9a-f]{32}\.(approval|sig|receipt|withdrawn)\.tmp$ ]]; then
          echo "the approval directory ${dir} holds '${name}', a temporary file of an interrupted submission — re-run submit-approval for ${release_id} (it clears such leftovers under the release's lock)"
        else
          echo "the approval directory ${dir} holds an unexpected entry '${name//[^A-Za-z0-9._-]/?}' — an administrator inspects and removes it"
        fi
        return 1
      fi
      id="${BASH_REMATCH[1]}"
      parts[$id]="${parts[$id]:-} ${BASH_REMATCH[2]} "
      why="$(dms_release_approval_store_file "${dir}/${name}" "$uid")" || { echo "$why"; return 1; }
    done <<< "$names"
    # Fail closed: a directory that lists entries yields stored approvals.
    if [ "${#parts[@]}" -eq 0 ]; then
      echo "the approval directory ${dir} is not empty, yet no stored approval could be read from it"
      return 1
    fi
  fi
  for id in $(printf '%s\n' "${!parts[@]}" | LC_ALL=C sort); do
    if [[ "${parts[$id]}" != *" approval "* || "${parts[$id]}" != *" sig "* || "${parts[$id]}" != *" receipt "* ]]; then
      if [[ "${parts[$id]}" != *" approval "* && "${parts[$id]}" != *" withdrawn "* ]]; then
        echo "stored approval ${id} in ${dir} is incomplete (it needs .approval, .sig and .receipt): an interrupted submission — re-run submit-approval for ${release_id} (it clears it under the release's lock)"
      else
        echo "stored approval ${id} in ${dir} is incomplete (it needs .approval, .sig and .receipt) — an administrator inspects it"
      fi
      return 1
    fi
    if ! dms_release_approval_parse_direct "${dir}/${id}.approval"; then
      echo "stored approval ${id}: ${DMS_RELEASE_APPROVAL_DIRECT_WHY}"
      return 1
    fi
    if [ "${DMS_RELEASE_APPROVAL_DIRECT_RECORD[DMS_APPROVAL_ID]}" != "$id" ] \
       || [ "${DMS_RELEASE_APPROVAL_DIRECT_RECORD[DMS_APPROVAL_RELEASE_ID]}" != "$release_id" ]; then
      echo "stored approval ${id} is approval ${DMS_RELEASE_APPROVAL_DIRECT_RECORD[DMS_APPROVAL_ID]} of release ${DMS_RELEASE_APPROVAL_DIRECT_RECORD[DMS_APPROVAL_RELEASE_ID]}, not of ${release_id}"
      return 1
    fi
    not_after="$(dms_release_approval_iso_epoch "${DMS_RELEASE_APPROVAL_DIRECT_RECORD[DMS_APPROVAL_NOT_AFTER]}")" \
      || { echo "stored approval ${id} has no canonical not_after"; return 1; }
    state="$(dms_release_approval_id_state "$authority" "$app_dir" "$uid" "$id")" || { echo "$state"; return 1; }
    if [[ "${parts[$id]}" == *" withdrawn "* ]]; then
      state="withdrawn"
    elif [ "$state" != "unspent" ]; then
      state="spent"
    elif [ "$now" -gt "$not_after" ]; then
      state="expired"
    else
      state="active"
    fi
    echo "${id} ${state} ${DMS_RELEASE_APPROVAL_DIRECT_RECORD[DMS_APPROVAL_TARGET_SHA]} ${DMS_RELEASE_APPROVAL_DIRECT_RECORD[DMS_APPROVAL_NOT_AFTER]}"
  done
  return 0
}

dms_release_approval_store_clean_stale() {
  # dms_release_approval_store_clean_stale <release dir>
  # ONLY under the release directory's exclusive flock (every writer of the
  # store — submission and withdrawal — writes under it, so no live writer
  # exists): removes exactly what an interrupted writer leaves behind —
  # `.<id>.<approval|sig|receipt|withdrawn>.tmp` files, and the .sig/.receipt
  # of a set whose record was never renamed into place (the record is renamed
  # last). Nothing else is touched; anything else stays for the scan to refuse.
  local dir="$1" names name id ext
  local -A parts=()
  names="$(dms_release_approval_store_names "$dir")" || return 1
  [ -n "$names" ] || return 0
  while IFS= read -r name; do
    if [[ "$name" =~ ^\.[0-9a-f]{32}\.(approval|sig|receipt|withdrawn)\.tmp$ ]]; then
      if [ -f "${dir}/${name}" ] && [ ! -L "${dir}/${name}" ]; then
        rm -f -- "${dir:?}/${name:?}" && echo "removed the stale temporary file ${dir}/${name}"
      fi
    elif [[ "$name" =~ ^([0-9a-f]{32})\.(approval|sig|receipt|withdrawn)$ ]]; then
      parts[${BASH_REMATCH[1]}]="${parts[${BASH_REMATCH[1]}]:-} ${BASH_REMATCH[2]} "
    fi
  done <<< "$names"
  for id in "${!parts[@]}"; do
    [[ "${parts[$id]}" != *" approval "* && "${parts[$id]}" != *" withdrawn "* ]] || continue
    for ext in sig receipt; do
      if [ -f "${dir}/${id}.${ext}" ] && [ ! -L "${dir}/${id}.${ext}" ]; then
        rm -f -- "${dir:?}/${id:?}.${ext}" && echo "removed ${dir}/${id}.${ext} of an interrupted submission (its record was never stored)"
      fi
    done
  done
  return 0
}

dms_release_approval_direct_select() {
  # dms_release_approval_direct_select <authority> <app_dir> <deploy_uid> <release_id> <approved_sha> <now> <contracts>
  # Returns 0 and sets DMS_RELEASE_APPROVAL_SELECTED_DIR/_ID when exactly one
  # active stored approval of the release approves <approved_sha> (contract 3);
  # 1 when the release has no stored approval (contract 2 decides); 77
  # (DENIED) otherwise — an untrusted store, stored approvals none of which is
  # active, more than one active, one for another commit, or contract 3 not
  # accepted here. A release with stored approvals never falls back.
  local authority="$1" app_dir="$2" uid="$3" release_id="$4" approved_sha="$5" now="$6" contracts="$7"
  local store reldir why listing id state target until active=0 match="" match_target="" detail=""
  DMS_RELEASE_APPROVAL_SELECTED_DIR=""
  DMS_RELEASE_APPROVAL_SELECTED_ID=""
  store="$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_RELEASE_APPROVAL_STORE)"
  if [ -z "$store" ]; then
    if dms_release_approval_in_words "$DMS_RELEASE_APPROVAL_DIRECT_CONTRACT" "$contracts"; then
      dms_release_approval_deny "the authority accepts approval contract 3 but declares no approval store (DMS_PRODUCTION_RELEASE_APPROVAL_STORE)"
      return
    fi
    return 1
  fi
  if [ ! -e "$store" ] && [ ! -L "$store" ]; then
    return 1
  fi
  if ! why="$(dms_trusted_dir "release approval store" "$store" "$uid" "$app_dir")"; then
    dms_release_approval_deny "${why} — stored approvals cannot be trusted"
    return
  fi
  reldir="${store}/${release_id}"
  if [ ! -e "$reldir" ] && [ ! -L "$reldir" ]; then
    return 1
  fi
  if ! why="$(dms_trusted_dir "release approval directory" "$reldir" "$uid" "$app_dir")"; then
    dms_release_approval_deny "${why} — stored approvals cannot be trusted"
    return
  fi
  if ! listing="$(dms_release_approval_store_scan "$authority" "$app_dir" "$uid" "$reldir" "$release_id" "$now")"; then
    dms_release_approval_deny "$listing"
    return
  fi
  # An empty directory holds no approval.
  [ -n "$listing" ] || return 1
  # From here on the release HAS stored approvals: they alone decide.
  dms_release_approval_in_words "$DMS_RELEASE_APPROVAL_DIRECT_CONTRACT" "$contracts" \
    || { dms_release_approval_deny "release ${release_id} has stored (contract 3) approvals, but this host accepts approval contracts '${contracts}' only (DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS) — no fallback to another contract"; return; }
  while read -r id state target until; do
    detail="${detail}${detail:+; }${id} ${state} (approves ${target}, until ${until})"
    [ "$state" = "active" ] || continue
    active=$((active + 1))
    match="$id"
    match_target="$target"
  done <<< "$listing"
  [ "$active" -ge 1 ] \
    || { dms_release_approval_deny "release ${release_id} has stored approvals but none is active (${detail}) — a new approval is required; nothing falls back to contract 2"; return; }
  [ "$active" -eq 1 ] \
    || { dms_release_approval_deny "release ${release_id} has ${active} active stored approvals (${detail}) — at most one may be active"; return; }
  [ "$match_target" = "$approved_sha" ] \
    || { dms_release_approval_deny "the active stored approval ${match} of release ${release_id} approves ${match_target}, the deploy asked for ${approved_sha} — no substitution, no fallback"; return; }
  DMS_RELEASE_APPROVAL_SELECTED_DIR="$reldir"
  DMS_RELEASE_APPROVAL_SELECTED_ID="$match"
  echo "Stored approval:    ${reldir}/${match}.approval (contract 3)"
  return 0
}

# The DMS_RELEASE_APPROVAL_DIRECT_* results are read by verify_inner.
# shellcheck disable=SC2034
dms_release_approval_direct_record() {
  # dms_release_approval_direct_record <dir> <approval id> <release_id> <approved_sha> <branch> <repository>
  #   <host> <host key> <approvers> <anchor> <app_dir> <deploy_uid> <now>
  # Contract 3, steps 5 to 7: the stored record and signature are still the
  # bytes root stored, the record is canonical and binds exactly this release,
  # approval, scope, repository, host, host key, branch, commit, tree and GO
  # tag, its approver's signature verifies against the trust anchor, and its
  # window is open. Sets DMS_RELEASE_APPROVAL_DIRECT_SIGNER,
  # DMS_RELEASE_APPROVAL_DIRECT_RECORD_SHA256 and
  # DMS_RELEASE_APPROVAL_DIRECT_SIGNATURE_SHA256; 77 (DENIED) otherwise.
  local dir="$1" id="$2" release_id="$3" approved_sha="$4" branch="$5" prod_repo="$6" prod_host="$7"
  local prod_host_key="$8" approvers="$9" anchor="${10}" app_dir="${11}" uid="${12}" now="${13}"
  local rec="${dir}/${id}.approval" sig="${dir}/${id}.sig" why value tree manifest_tag
  DMS_RELEASE_APPROVAL_DIRECT_SIGNER=""
  DMS_RELEASE_APPROVAL_DIRECT_RECORD_SHA256=""
  DMS_RELEASE_APPROVAL_DIRECT_SIGNATURE_SHA256=""

  why="$(dms_release_approval_store_file "$rec" "$uid")" || { dms_release_approval_deny "$why"; return; }
  why="$(dms_release_approval_store_file "$sig" "$uid")" || { dms_release_approval_deny "$why"; return; }
  dms_release_approval_parse_direct "$rec" \
    || { dms_release_approval_deny "stored approval ${id}: ${DMS_RELEASE_APPROVAL_DIRECT_WHY}"; return; }
  local -n r=DMS_RELEASE_APPROVAL_DIRECT_RECORD
  echo "Approval record:    ${rec} (contract 3)"

  # ── 6. Every fact in the record must match ─────────────────────────────────
  [ "${r[DMS_APPROVAL_ID]}" = "$id" ] \
    || { dms_release_approval_deny "the stored record is approval '${r[DMS_APPROVAL_ID]}', stored as ${id}"; return; }
  [ "${r[DMS_APPROVAL_RELEASE_ID]}" = "$release_id" ] \
    || { dms_release_approval_deny "the record names release '${r[DMS_APPROVAL_RELEASE_ID]}', the deploy asked for '${release_id}'"; return; }
  [ "${r[DMS_APPROVAL_SCOPE]}" = "$DMS_RELEASE_APPROVAL_DIRECT_SCOPE" ] \
    || { dms_release_approval_deny "the record's scope is '${r[DMS_APPROVAL_SCOPE]}', a deploy needs '${DMS_RELEASE_APPROVAL_DIRECT_SCOPE}'"; return; }
  [ "${r[DMS_APPROVAL_REPOSITORY]}" = "$prod_repo" ] \
    || { dms_release_approval_deny "the record approves repository '${r[DMS_APPROVAL_REPOSITORY]}', this is '${prod_repo}'"; return; }
  [ "${r[DMS_APPROVAL_HOST]}" = "$prod_host" ] \
    || { dms_release_approval_deny "the record approves host '${r[DMS_APPROVAL_HOST]}', this is '${prod_host}'"; return; }
  [ "${r[DMS_APPROVAL_HOST_KEY_SHA256]}" = "$prod_host_key" ] \
    || { dms_release_approval_deny "the record approves host key '${r[DMS_APPROVAL_HOST_KEY_SHA256]}', this host's is '${prod_host_key}'"; return; }
  [ "${r[DMS_APPROVAL_BASE_BRANCH]}" = "$branch" ] \
    || { dms_release_approval_deny "the record approves branch '${r[DMS_APPROVAL_BASE_BRANCH]}', the deploy follows '${branch}'"; return; }
  [ "${r[DMS_APPROVAL_TARGET_SHA]}" = "$approved_sha" ] \
    || { dms_release_approval_deny "the record approves ${r[DMS_APPROVAL_TARGET_SHA]}, the deploy asked for ${approved_sha} — no substitution"; return; }
  tree="$(dms_release_approval_git rev-parse --verify "${approved_sha}^{tree}")" || tree=""
  [ "${r[DMS_APPROVAL_TARGET_TREE]}" = "$tree" ] \
    || { dms_release_approval_deny "the record approves tree ${r[DMS_APPROVAL_TARGET_TREE]}, commit ${approved_sha} has tree ${tree:-<unreadable>}"; return; }
  dms_release_approval_in_words "${r[DMS_APPROVAL_APPROVED_BY]}" "$approvers" \
    || { dms_release_approval_deny "approver '${r[DMS_APPROVAL_APPROVED_BY]}' is not a declared release approver (${approvers})"; return; }
  manifest_tag="$(dms_release_approval_git cat-file -p "${approved_sha}:.sprint/current.yml" 2>/dev/null \
    | sed -n 's/^go_tag:[[:space:]]*//p' | tail -n 1 | tr -d '[:space:]')" || manifest_tag=""
  [ "$manifest_tag" = "${r[DMS_APPROVAL_GO_TAG]}" ] \
    || { dms_release_approval_deny "the record names GO tag '${r[DMS_APPROVAL_GO_TAG]}', the approved commit's .sprint/current.yml declares '${manifest_tag:-<none>}'"; return; }

  # ── 6b. The approver's signature over the exact stored bytes ───────────────
  if ! value="$(dms_release_approval_signature_file "$anchor" "${r[DMS_APPROVAL_APPROVED_BY]}" "$rec" "$sig" "$uid" "$app_dir")"; then
    dms_release_approval_deny "stored approval ${id}: ${value}"
    return
  fi
  DMS_RELEASE_APPROVAL_DIRECT_SIGNER="$value"
  echo "PASS: ${sig} is a good '${DMS_RELEASE_APPROVAL_SIGNATURE_NAMESPACE}' signature by '${r[DMS_APPROVAL_APPROVED_BY]}' (key ${value}, anchor ${anchor})"

  # ── 7. Validity window ─────────────────────────────────────────────────────
  why="$(dms_release_approval_window "${r[DMS_APPROVAL_APPROVED_AT]}" "${r[DMS_APPROVAL_NOT_AFTER]}" "$now")" \
    || { dms_release_approval_deny "$why"; return; }

  DMS_RELEASE_APPROVAL_DIRECT_RECORD_SHA256="$(dms_release_approval_sha256 "$rec")" \
    || { dms_release_approval_deny "the stored record ${rec} could not be hashed"; return; }
  DMS_RELEASE_APPROVAL_DIRECT_SIGNATURE_SHA256="$(dms_release_approval_sha256 "$sig")" \
    || { dms_release_approval_deny "the stored signature ${sig} could not be hashed"; return; }
  return 0
}

dms_release_approval_ci_program() {
  # The fixed evaluator of the GitHub responses (python3 -I -S, stdlib json).
  # Prints `OK <run id>` / `OK`, or `DENY <reason>`; every value it echoes is
  # reduced to a safe character set (a response can never forge a log line).
  cat <<'PY'
import json
import sys
import time


def safe(value):
    text = str(value)
    return "".join(c if c.isascii() and (c.isalnum() or c in " ._/:()-") else "?" for c in text)[:100]


def full_name(obj, key):
    inner = obj.get(key)
    return inner.get("full_name") if isinstance(inner, dict) else None


def main():
    mode = sys.argv[1]
    try:
        doc = json.loads(sys.stdin.buffer.read().decode("utf-8"))
    except Exception:
        return "DENY the GitHub response is not valid JSON"
    if not isinstance(doc, dict):
        return "DENY the GitHub response is not a JSON object"
    if mode == "rate":
        resources = doc.get("resources")
        core = resources.get("core") if isinstance(resources, dict) else None
        if not isinstance(core, dict):
            return "DENY the GitHub rate-limit response names no core limit"
        values = [core.get(k) for k in ("remaining", "limit", "reset")]
        if any(type(v) is not int or v < 0 for v in values):
            return "DENY the GitHub rate-limit response has no usable remaining/limit/reset"
        try:
            when = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(values[2]))
        except (OverflowError, OSError, ValueError):
            return "DENY the GitHub rate-limit reset time is not a time"
        return "OK %d %d %d %s" % (values[0], values[1], values[2], when)
    if mode == "runs":
        repo, sha, branch, workflow = sys.argv[2:6]
        runs = doc.get("workflow_runs")
        if not isinstance(runs, list):
            return "DENY the GitHub response lists no workflow runs"
        found = [r for r in runs if isinstance(r, dict) and type(r.get("id")) is int
                 and r.get("path") == workflow and r.get("head_sha") == sha
                 and r.get("head_branch") == branch and r.get("event") == "push"
                 and full_name(r, "repository") == repo and full_name(r, "head_repository") == repo]
        if not found:
            return "DENY no push run of %s for %s on %s in %s" % (safe(workflow), safe(sha), safe(branch), safe(repo))
        run = max(found, key=lambda r: r["id"])
        if run.get("status") != "completed" or run.get("conclusion") != "success":
            return "DENY the latest push run %d of %s for %s is %s/%s, not completed/success" % (
                run["id"], safe(workflow), safe(sha), safe(run.get("status")), safe(run.get("conclusion")))
        return "OK %d" % run["id"]
    if mode == "jobs":
        sha, run_id, names = sys.argv[2], int(sys.argv[3]), sys.argv[4].split(";")
        required_job, required_step = sys.argv[5].split(":", 1)
        forbidden_job, forbidden_step = sys.argv[6].split(":", 1)
        jobs = doc.get("jobs")
        total = doc.get("total_count")
        if not isinstance(jobs, list) or type(total) is not int:
            return "DENY the GitHub response lists no jobs"
        if total > len(jobs):
            return "DENY the run has %d jobs and the response holds %d" % (total, len(jobs))
        for name in names:
            same = [j for j in jobs if isinstance(j, dict) and j.get("name") == name]
            if any(j.get("head_sha") != sha or j.get("run_id") != run_id for j in same):
                return "DENY a job named %s belongs to another run or commit" % safe(name)
            bad = [j for j in same if j.get("conclusion") not in ("success", "skipped")]
            if bad:
                return "DENY required job %s concluded %s" % (safe(name), safe(bad[0].get("conclusion")))
            if not any(j.get("status") == "completed" and j.get("conclusion") == "success" for j in same):
                return "DENY required job %s did not succeed (missing or skipped)" % safe(name)
        # A docs-only change set skips the test step while its job still
        # concludes success: every job of that name that ran must have run the
        # step itself, and none may have taken the docs-only skip.
        for j in jobs:
            if not isinstance(j, dict):
                continue
            steps = j.get("steps")
            if j.get("name") == forbidden_job and isinstance(steps, list) and any(
                    isinstance(st, dict) and st.get("name") == forbidden_step and st.get("conclusion") == "success" for st in steps):
                return "DENY job %s took the step %s: its tests did not run" % (safe(forbidden_job), safe(forbidden_step))
            if j.get("name") != required_job or j.get("conclusion") != "success":
                continue
            if not isinstance(steps, list):
                return "DENY job %s lists no steps" % safe(required_job)
            if not any(isinstance(st, dict) and st.get("name") == required_step and st.get("status") == "completed"
                       and st.get("conclusion") == "success" for st in steps):
                found = [st.get("conclusion") for st in steps if isinstance(st, dict) and st.get("name") == required_step]
                return "DENY job %s did not run the step %s with success (%s)" % (
                    safe(required_job), safe(required_step), safe(found[0]) if found else "no such step")
        return "OK"
    return "DENY unknown mode"


print(main())
PY
}

dms_release_approval_github_get() {
  # dms_release_approval_github_get <url> — the body of a 200 answer from the
  # GitHub API: unauthenticated, https only, default TLS verification, bounded
  # time and size. Prints the reason and returns 1 on any other outcome.
  local url="$1" out rc=0 code
  out="$(curl -q --silent --show-error --proto '=https' --connect-timeout 10 --max-time 30 --max-filesize 10000000 \
      --header 'Accept: application/vnd.github+json' --header 'X-GitHub-Api-Version: 2022-11-28' \
      --user-agent 'dms-release-approval-guard' --write-out '\n%{http_code}' "$url" 2>/dev/null)" || rc=$?
  if [ "$rc" -ne 0 ]; then
    echo "the GitHub API could not be reached (curl exit ${rc}: network, DNS, TLS or timeout)"
    return 1
  fi
  code="${out##*$'\n'}"
  [[ "$code" =~ ^[0-9]{3}$ ]] || { echo "the GitHub API returned no HTTP status"; return 1; }
  case "$code" in
    200) ;;
    403|429)
      echo "the GitHub API answered HTTP ${code} — this may be its rate limit (unauthenticated: 60 requests per hour per host address); try again after the limit resets"
      return 1
      ;;
    *) echo "the GitHub API answered HTTP ${code}"; return 1 ;;
  esac
  printf '%s' "${out%$'\n'*}"
}

dms_release_approval_ci_budget() {
  # Before the required-CI requests: asks GET /rate_limit (which does not count
  # against the limit) how many unauthenticated requests this host has left,
  # and DENIES when fewer than DMS_RELEASE_APPROVAL_CI_REQUESTS plus
  # DMS_RELEASE_APPROVAL_CI_RESERVE remain — so `check-approval` never spends
  # what a deploy needs. An unreadable answer is DENIED (fail closed).
  local reserve="$DMS_RELEASE_APPROVAL_CI_RESERVE" need out verdict remaining limit reset when
  [[ "$reserve" =~ ^[0-9]{1,2}$ ]] \
    || { dms_release_approval_deny "required CI: the rate-limit reserve '${reserve}' is not a small count"; return; }
  need=$((DMS_RELEASE_APPROVAL_CI_REQUESTS + 10#$reserve))
  out="$(dms_release_approval_github_get "${DMS_RELEASE_APPROVAL_GITHUB_API}/rate_limit")" \
    || { dms_release_approval_deny "required CI: the GitHub API rate limit could not be read (${out})"; return; }
  verdict="$(printf '%s' "$out" | python3 -I -S -c "$(dms_release_approval_ci_program)" rate 2>/dev/null)" \
    || verdict="DENY the GitHub rate-limit response could not be evaluated"
  verdict="${verdict%%$'\n'*}"
  case "$verdict" in
    "OK "*) read -r remaining limit reset when <<< "${verdict#OK }" ;;
    "DENY "*) dms_release_approval_deny "required CI: ${verdict#DENY }"; return ;;
    *) dms_release_approval_deny "required CI: the GitHub rate-limit response could not be evaluated"; return ;;
  esac
  [[ "$remaining" =~ ^[0-9]{1,9}$ && "$limit" =~ ^[0-9]{1,9}$ && "$reset" =~ ^[0-9]{1,12}$ && "$when" =~ ^[0-9T:Z-]{20}$ ]] \
    || { dms_release_approval_deny "required CI: the GitHub rate-limit response could not be evaluated"; return; }
  if [ "$remaining" -lt "$need" ]; then
    dms_release_approval_deny "required CI: the GitHub API rate limit of this host has ${remaining} of ${limit} requests left; this check needs ${DMS_RELEASE_APPROVAL_CI_REQUESTS} and keeps ${reserve} in reserve for a deploy — the limit resets at ${when} (UTC); try again after that"
    return
  fi
  echo "PASS: GitHub API rate limit — ${remaining} of ${limit} requests left (this check needs ${DMS_RELEASE_APPROVAL_CI_REQUESTS}, reserve ${reserve}; resets ${when} UTC)"
  return 0
}

dms_release_approval_ci_verify() {
  # dms_release_approval_ci_verify <repository OWNER/REPO> <sha> <branch> <workflow path> <required jobs, ';'-separated>
  #   <required step, "<job>:<step>"> <forbidden step, "<job>:<step>">
  #
  # Contract 3's explicit CI gate (it replaces the approval pull request's own
  # CI). Asks GitHub for the push runs of <workflow path> on exactly <sha> and
  # <branch> in <repository>: the latest such run must be completed/success,
  # and in its latest attempt every required job name must have at least one
  # successful job and none concluding anything but success or skipped (a
  # skipped job never satisfies a requirement). Any network, HTTP or parse
  # failure, a missing run, another commit, repository, fork, branch, event or
  # workflow is DENIED (77). Every input comes from the authority and the
  # operator's --approved-sha, never from an approval record.
  #
  # A job can conclude success without testing anything: the classifier's
  # docs_only profile skips the critical test step. So every successful job
  # named in <required step> must have run that step with success, and no job
  # named in <forbidden step> may have taken that (docs-only skip) step.
  # First GET /rate_limit (dms_release_approval_ci_budget): too few requests
  # left for the two calls plus the caller's reserve is DENIED before either.
  # Sets DMS_RELEASE_APPROVAL_CI_RUN_ID to the verified run.
  local repo="$1" sha="$2" branch="$3" workflow="$4" jobs="$5" required_step="${6:-}" forbidden_step="${7:-}"
  local name out verdict run_id spec
  local -a required=()
  DMS_RELEASE_APPROVAL_CI_RUN_ID=""
  [[ "$repo" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] \
    || { dms_release_approval_deny "required CI: the repository '${repo}' is not a GitHub OWNER/REPO"; return; }
  dms_release_approval_valid_sha "$sha" || { dms_release_approval_deny "required CI: '${sha}' is not a full commit SHA"; return; }
  [[ "$branch" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]*$ && "$branch" != *..* ]] \
    || { dms_release_approval_deny "required CI: '${branch}' is not a plain branch name"; return; }
  [[ "$workflow" =~ ^\.github/workflows/[A-Za-z0-9._-]+\.ya?ml$ ]] \
    || { dms_release_approval_deny "required CI: the authority declares no workflow path (DMS_PRODUCTION_CI_WORKFLOW='${workflow}')"; return; }
  IFS=';' read -r -a required <<< "$jobs"
  [ "${#required[@]}" -ge 1 ] \
    || { dms_release_approval_deny "required CI: the authority declares no required job (DMS_PRODUCTION_CI_REQUIRED_JOBS)"; return; }
  if [[ "$jobs" == ";"* || "$jobs" == *";" || "$jobs" == *";;"* ]]; then
    dms_release_approval_deny "required CI: the authority's DMS_PRODUCTION_CI_REQUIRED_JOBS has an empty job name"
    return
  fi
  for name in "${required[@]}"; do
    [[ "$name" =~ ^[A-Za-z0-9][A-Za-z0-9\ ._\&()-]{0,99}$ ]] \
      || { dms_release_approval_deny "required CI: '${name}' is not a job name (DMS_PRODUCTION_CI_REQUIRED_JOBS is ';'-separated)"; return; }
  done
  for spec in "$required_step" "$forbidden_step"; do
    [[ "$spec" =~ ^([A-Za-z0-9][A-Za-z0-9\ ._\&()-]{0,99}):([A-Za-z0-9][A-Za-z0-9\ ._\&()-]{0,99})$ ]] \
      || { dms_release_approval_deny "required CI: '${spec}' is not '<job>:<step>' (DMS_PRODUCTION_CI_REQUIRED_STEP, DMS_PRODUCTION_CI_FORBIDDEN_STEP)"; return; }
  done
  dms_release_approval_ci_job_listed "${required_step%%:*}" "${required[@]}" \
    || { dms_release_approval_deny "required CI: the required step's job '${required_step%%:*}' is not a required job"; return; }
  command -v curl >/dev/null 2>&1 || { dms_release_approval_deny "required CI: curl is not installed"; return; }
  command -v python3 >/dev/null 2>&1 || { dms_release_approval_deny "required CI: python3 is not installed"; return; }
  dms_release_approval_ci_budget || return

  out="$(dms_release_approval_github_get "${DMS_RELEASE_APPROVAL_GITHUB_API}/repos/${repo}/actions/runs?head_sha=${sha}&event=push&branch=${branch}&per_page=50")" \
    || { dms_release_approval_deny "required CI: ${out}"; return; }
  verdict="$(printf '%s' "$out" | python3 -I -S -c "$(dms_release_approval_ci_program)" runs "$repo" "$sha" "$branch" "$workflow" 2>/dev/null)" \
    || verdict="DENY the GitHub response could not be evaluated"
  verdict="${verdict%%$'\n'*}"
  case "$verdict" in
    "OK "*) run_id="${verdict#OK }" ;;
    "DENY "*) dms_release_approval_deny "required CI: ${verdict#DENY }"; return ;;
    *) dms_release_approval_deny "required CI: the GitHub response could not be evaluated"; return ;;
  esac
  [[ "$run_id" =~ ^[0-9]{1,20}$ ]] || { dms_release_approval_deny "required CI: no run id"; return; }

  out="$(dms_release_approval_github_get "${DMS_RELEASE_APPROVAL_GITHUB_API}/repos/${repo}/actions/runs/${run_id}/jobs?filter=latest&per_page=100")" \
    || { dms_release_approval_deny "required CI: ${out}"; return; }
  verdict="$(printf '%s' "$out" | python3 -I -S -c "$(dms_release_approval_ci_program)" jobs "$sha" "$run_id" "$jobs" "$required_step" "$forbidden_step" 2>/dev/null)" \
    || verdict="DENY the GitHub response could not be evaluated"
  verdict="${verdict%%$'\n'*}"
  case "$verdict" in
    OK) ;;
    "DENY "*) dms_release_approval_deny "required CI: ${verdict#DENY }"; return ;;
    *) dms_release_approval_deny "required CI: the GitHub response could not be evaluated"; return ;;
  esac
  DMS_RELEASE_APPROVAL_CI_RUN_ID="$run_id"
  echo "PASS: required CI — push run ${run_id} of ${workflow} on ${sha} (${repo}, ${branch}) completed with success; required jobs succeeded: ${jobs}; '${required_step#*:}' ran"
  return 0
}

dms_release_approval_ci_job_listed() {
  # dms_release_approval_ci_job_listed <name> <required job>... — exact membership.
  local name="$1" item
  shift
  for item in "$@"; do
    [ "$item" = "$name" ] && return 0
  done
  return 1
}

dms_release_approval_submit_deny() {
  echo "release-approval-submit: DENIED — $*"
  echo "RELEASE APPROVAL SUBMISSION: DENIED — nothing was stored"
  return "$DMS_RELEASE_APPROVAL_DENIED"
}

dms_release_approval_submit() {
  # dms_release_approval_submit <authority> <app_dir> <deploy_uid> <release_id> <this_host> <this_host_key> <now_epoch> <submitted_by> [<release lock>]
  #   (the bundle on stdin)
  #
  # Root's half of `submit-approval` (scripts/release-approval-submit.sh): reads
  # at most DMS_RELEASE_APPROVAL_MAX_BUNDLE_BYTES from stdin, verifies the
  # bundle completely — canonical record, release id = <release_id>, contract 3
  # accepted here, host, host key (observed == authority == record),
  # repository, branch, scope, approver, window, the approver's signature over
  # the exact record bytes, release unspent, approval id unspent and unused,
  # no other active approval of the release — and only then stores it:
  #   <store>/<release_id>/<approval id>.approval, .sig, .receipt
  # (directories root 0700 under trusted-path checks; each file written with
  # umask 077 and set -C to a temporary name in the same directory, made
  # 0400, then renamed, the record last). Never deploys anything. While a
  # deploy or rollback holds <release lock> (the bootstrap's release.lock) the
  # submission is refused, and it holds that lock while it stores, so an
  # approval never lands inside a deploy's critical section. Returns 0 with
  # `RELEASE APPROVAL SUBMITTED: <id>`, 77 (DENIED) otherwise.
  local rc=0 work
  if ! work="$(umask 077 && mktemp -d 2>/dev/null)" || [ ! -d "$work" ]; then
    dms_release_approval_submit_deny "a private working directory could not be created"
    return
  fi
  dms_release_approval_submit_inner "$work" "$@" || rc=$?
  rm -rf -- "${work:?}"
  return "$rc"
}

dms_release_approval_submit_inner() {
  local work="$1" authority="$2" app_dir="$3" uid="$4" release_id="$5" this_host="$6" this_host_key="$7" now="$8" submitted_by="$9"
  local release_lock="${10:-}"
  local why contracts prod_host frozen prod_repo prod_branch approvers prod_host_key anchor store reldir signer id word
  local record_sha sig_sha size

  echo "== Release approval submission (contract 3, DMS-RELEASE-APPROVAL-DIRECT-1) =="
  declare -F dms_trusted_file >/dev/null \
    || { dms_release_approval_submit_deny "the trusted-path library (scripts/lib/trusted-path.sh) is missing beside the guard"; return; }
  [[ "$uid" =~ ^[0-9]+$ ]] || { dms_release_approval_submit_deny "the deploy identity '${uid}' is not a uid"; return; }
  dms_release_approval_valid_id "$release_id" \
    || { dms_release_approval_submit_deny "release id '${release_id}' is not a release identifier (lowercase, digits and '-', 3-80 characters)"; return; }
  [[ "$now" =~ ^[0-9]+$ ]] || { dms_release_approval_submit_deny "the clock value '${now}' is not an epoch"; return; }
  [[ "$submitted_by" =~ ^[a-z][a-z0-9-]*$ ]] || submitted_by="unknown"

  # ── The bundle (stdin only, bounded) ───────────────────────────────────────
  head -c "$((DMS_RELEASE_APPROVAL_MAX_BUNDLE_BYTES + 1))" > "${work}/bundle" \
    || { dms_release_approval_submit_deny "the bundle could not be read from stdin"; return; }
  size="$(stat -c %s -- "${work}/bundle")"
  why="$(dms_release_approval_split_bundle "${work}/bundle" "${work}/record" "${work}/sig")" \
    || { dms_release_approval_submit_deny "$why"; return; }
  dms_release_approval_parse_direct "${work}/record" \
    || { dms_release_approval_submit_deny "$DMS_RELEASE_APPROVAL_DIRECT_WHY"; return; }
  local -n r=DMS_RELEASE_APPROVAL_DIRECT_RECORD
  id="${r[DMS_APPROVAL_ID]}"
  echo "Bundle:             ${size} bytes, approval ${id} of release ${r[DMS_APPROVAL_RELEASE_ID]} for ${r[DMS_APPROVAL_TARGET_SHA]}"

  # ── The host authority ─────────────────────────────────────────────────────
  if [ -z "$authority" ] || [ ! -r "$authority" ]; then
    dms_release_approval_submit_deny "host authority ${authority:-<none>} is missing or unreadable"
    return
  fi
  contracts="$(dms_release_approval_contracts "$authority")" || { dms_release_approval_submit_deny "$contracts"; return; }
  dms_release_approval_in_words "$DMS_RELEASE_APPROVAL_DIRECT_CONTRACT" "$contracts" \
    || { dms_release_approval_submit_deny "this host accepts approval contracts '${contracts}' only; contract 3 is not enabled (DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS)"; return; }
  prod_host="$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_HOSTNAME)"
  frozen="$(dms_release_approval_authority_value "$authority" DMS_FROZEN_LEGACY_HOSTNAMES)"
  prod_repo="$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_REPOSITORY)"
  prod_branch="$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_DEPLOY_BRANCH)"
  approvers="$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_RELEASE_APPROVERS)"
  prod_host_key="$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_HOST_KEY_SHA256)"
  anchor="$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_RELEASE_TRUST_ANCHOR)"
  store="$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_RELEASE_APPROVAL_STORE)"
  if [ -z "$prod_host" ] || [ -z "$prod_repo" ] || [ -z "$prod_branch" ] || [ -z "$approvers" ] || [ -z "$store" ]; then
    dms_release_approval_submit_deny "the authority does not declare the production host, repository, branch, approvers and approval store"
    return
  fi
  for word in $frozen; do
    [ "$this_host" = "$word" ] && { dms_release_approval_submit_deny "this host '${this_host}' is FROZEN legacy infrastructure"; return; }
  done
  [ "$this_host" = "$prod_host" ] \
    || { dms_release_approval_submit_deny "this host is '${this_host:-<empty>}', the declared production host is '${prod_host}'"; return; }
  [[ "$prod_host_key" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]] \
    || { dms_release_approval_submit_deny "the authority pins no SHA256 host key fingerprint (DMS_PRODUCTION_HOST_KEY_SHA256)"; return; }
  [ "$this_host_key" = "$prod_host_key" ] \
    || { dms_release_approval_submit_deny "this host presents host key '${this_host_key:-<unreadable>}', the declared production host key is '${prod_host_key}'"; return; }

  # ── Every fact in the record ───────────────────────────────────────────────
  [ "${r[DMS_APPROVAL_RELEASE_ID]}" = "$release_id" ] \
    || { dms_release_approval_submit_deny "the record names release '${r[DMS_APPROVAL_RELEASE_ID]}', the submission is for '${release_id}'"; return; }
  [ "${r[DMS_APPROVAL_SCOPE]}" = "$DMS_RELEASE_APPROVAL_DIRECT_SCOPE" ] \
    || { dms_release_approval_submit_deny "the record's scope is '${r[DMS_APPROVAL_SCOPE]}', a deploy approval has scope '${DMS_RELEASE_APPROVAL_DIRECT_SCOPE}'"; return; }
  [ "${r[DMS_APPROVAL_REPOSITORY]}" = "$prod_repo" ] \
    || { dms_release_approval_submit_deny "the record approves repository '${r[DMS_APPROVAL_REPOSITORY]}', this host deploys '${prod_repo}'"; return; }
  [ "${r[DMS_APPROVAL_HOST]}" = "$prod_host" ] \
    || { dms_release_approval_submit_deny "the record approves host '${r[DMS_APPROVAL_HOST]}', this is '${prod_host}'"; return; }
  [ "${r[DMS_APPROVAL_HOST_KEY_SHA256]}" = "$prod_host_key" ] \
    || { dms_release_approval_submit_deny "the record approves host key '${r[DMS_APPROVAL_HOST_KEY_SHA256]}', this host's is '${prod_host_key}'"; return; }
  [ "${r[DMS_APPROVAL_BASE_BRANCH]}" = "$prod_branch" ] \
    || { dms_release_approval_submit_deny "the record approves branch '${r[DMS_APPROVAL_BASE_BRANCH]}', this host follows '${prod_branch}'"; return; }
  dms_release_approval_in_words "${r[DMS_APPROVAL_APPROVED_BY]}" "$approvers" \
    || { dms_release_approval_submit_deny "approver '${r[DMS_APPROVAL_APPROVED_BY]}' is not a declared release approver (${approvers})"; return; }
  why="$(dms_release_approval_window "${r[DMS_APPROVAL_APPROVED_AT]}" "${r[DMS_APPROVAL_NOT_AFTER]}" "$now")" \
    || { dms_release_approval_submit_deny "$why"; return; }
  if ! signer="$(dms_release_approval_signature_file "$anchor" "${r[DMS_APPROVAL_APPROVED_BY]}" "${work}/record" "${work}/sig" "$uid" "$app_dir")"; then
    dms_release_approval_submit_deny "$signer"
    return
  fi
  echo "PASS: a good '${DMS_RELEASE_APPROVAL_SIGNATURE_NAMESPACE}' signature by '${r[DMS_APPROVAL_APPROVED_BY]}' (key ${signer})"

  # ── Never a spent release or approval ──────────────────────────────────────
  if ! why="$(dms_release_approval_spent "$authority" "$app_dir" "$uid" "$release_id")"; then
    why="${why%%$'\n'*}"
    dms_release_approval_submit_deny "${why#release-approval: DENIED — }"
    return
  fi
  why="$(dms_release_approval_id_state "$authority" "$app_dir" "$uid" "$id")" || { dms_release_approval_submit_deny "$why"; return; }
  [ "$why" = "unspent" ] || { dms_release_approval_submit_deny "approval ${id} was already used: ${why}"; return; }

  # ── Store (root's own directories, one active approval per release) ────────
  why="$(dms_trusted_dir_prepare "release approval store" "$store" "$uid" "$app_dir")" \
    || { dms_release_approval_submit_deny "$why"; return; }
  reldir="${store}/${release_id}"
  why="$(dms_trusted_dir_prepare "release approval directory" "$reldir" "$uid" "$app_dir")" \
    || { dms_release_approval_submit_deny "$why"; return; }
  record_sha="$(dms_release_approval_sha256 "${work}/record")" || { dms_release_approval_submit_deny "the record could not be hashed"; return; }
  sig_sha="$(dms_release_approval_sha256 "${work}/sig")" || { dms_release_approval_submit_deny "the signature could not be hashed"; return; }
  if ! printf 'host=%s\nhost_key_sha256=%s\nrelease_id=%s\napproval_id=%s\ntarget_sha=%s\nverified_at=%s\nsigner_key=%s\nrecord_sha256=%s\nsignature_sha256=%s\nsubmitted_by=%s\n' \
    "$this_host" "$this_host_key" "$release_id" "$id" "${r[DMS_APPROVAL_TARGET_SHA]}" "$(date -u -d "@${now}" +%Y-%m-%dT%H:%M:%SZ)" \
    "$signer" "$record_sha" "$sig_sha" "$submitted_by" > "${work}/receipt"; then
    dms_release_approval_submit_deny "the receipt could not be written"
    return
  fi
  command -v flock >/dev/null 2>&1 || { dms_release_approval_submit_deny "flock is not installed"; return; }
  # fd 9 is the directory itself and fd 8 the release lock, each opened only to
  # hold its lock (flock); nothing reads or writes through them.
  # shellcheck disable=SC2094
  if [ -n "$release_lock" ] && { [ -e "$release_lock" ] || [ -L "$release_lock" ]; }; then
    dms_release_approval_submit_store "$work" "$authority" "$app_dir" "$uid" "$reldir" "$release_id" "$id" "$now" "$release_lock" \
      9<"$reldir" 8<"$release_lock" || return
  else
    # No deploy has run since this host booted (the lock lives on /run).
    dms_release_approval_submit_store "$work" "$authority" "$app_dir" "$uid" "$reldir" "$release_id" "$id" "$now" "" 9<"$reldir" || return
  fi

  echo "RELEASE APPROVAL SUBMITTED: ${id}"
  echo "release_id=${release_id}"
  echo "approval_id=${id}"
  echo "target_sha=${r[DMS_APPROVAL_TARGET_SHA]}"
  echo "not_after=${r[DMS_APPROVAL_NOT_AFTER]}"
  echo "signer_key=${signer}"
  echo "record_sha256=${record_sha}"
  echo "signature_sha256=${sig_sha}"
  echo "store=${reldir}/${id}.approval"
  echo "Nothing was deployed. The deploy is: start --release-id ${release_id} --approved-sha ${r[DMS_APPROVAL_TARGET_SHA]}"
  return 0
}

dms_release_approval_submit_store() {
  # Runs with fd 9 open on the release's store directory (and fd 8 on the
  # release lock when one exists): refuses while a deploy or rollback holds the
  # release lock, then under the directory's exclusive lock clears what an
  # interrupted submission left (dms_release_approval_store_clean_stale),
  # refuses a second active approval of the release (or any entry the deploy
  # would refuse), writes the three files to temporary names in the same
  # directory (umask 077, set -C, then 0400) and renames them, the record last
  # — a deploy reads a record only once its .sig and .receipt exist.
  local work="$1" authority="$2" app_dir="$3" uid="$4" reldir="$5" release_id="$6" id="$7" now="$8" release_lock="${9:-}"
  local listing other state target until ext src failed=""
  if [ -n "$release_lock" ]; then
    flock -x -n 8 \
      || { dms_release_approval_submit_deny "a deploy or rollback holds the release lock ${release_lock} (or a check-approval is running) — submit again once it has finished"; return; }
  fi
  flock -x -w 30 9 || { dms_release_approval_submit_deny "another submission for ${release_id} holds the store lock"; return; }
  dms_release_approval_store_clean_stale "$reldir" || { dms_release_approval_submit_deny "${reldir} cannot be listed"; return; }
  if ! listing="$(dms_release_approval_store_scan "$authority" "$app_dir" "$uid" "$reldir" "$release_id" "$now")"; then
    dms_release_approval_submit_deny "$listing"
    return
  fi
  while read -r other state target until; do
    [ -n "$other" ] || continue
    [ "$other" != "$id" ] || { dms_release_approval_submit_deny "approval ${id} is already stored for ${release_id}"; return; }
    [ "$state" != "active" ] \
      || { dms_release_approval_submit_deny "approval ${other} of ${release_id} (for ${target}) is still active until ${until} — one active approval per release; replacing it needs it to expire or a new release id"; return; }
  done <<< "$listing"
  for ext in approval sig receipt; do
    if [ -e "${reldir}/${id}.${ext}" ] || [ -L "${reldir}/${id}.${ext}" ]; then
      dms_release_approval_submit_deny "${reldir}/${id}.${ext} already exists"
      return
    fi
  done
  for ext in sig receipt approval; do
    case "$ext" in approval) src="${work}/record" ;; sig) src="${work}/sig" ;; *) src="${work}/receipt" ;; esac
    if ! (umask 077 && set -C && command cat -- "$src" > "${reldir}/.${id}.${ext}.tmp") 2>/dev/null \
       || ! chmod 0400 -- "${reldir}/.${id}.${ext}.tmp"; then
      failed="${reldir}/.${id}.${ext}.tmp could not be written"
      break
    fi
  done
  if [ -z "$failed" ]; then
    for ext in sig receipt approval; do
      if ! mv -T -- "${reldir}/.${id}.${ext}.tmp" "${reldir}/${id}.${ext}"; then
        failed="${reldir}/${id}.${ext} could not be put in place"
        break
      fi
    done
  fi
  if [ -n "$failed" ]; then
    for ext in sig receipt approval; do
      rm -f -- "${reldir:?}/.${id:?}.${ext:?}.tmp" "${reldir:?}/${id:?}.${ext:?}"
    done
    dms_release_approval_submit_deny "$failed"
    return
  fi
  return 0
}

dms_release_approval_withdraw_deny() {
  echo "release-approval-withdraw: DENIED — $*"
  echo "RELEASE APPROVAL WITHDRAWAL: DENIED — nothing was changed"
  return "$DMS_RELEASE_APPROVAL_DENIED"
}

dms_release_approval_withdraw() {
  # dms_release_approval_withdraw <authority> <app_dir> <deploy_uid> <release_id> <approval_id> <now_epoch> <withdrawn_by>
  #
  # The administrator's revocation of ONE stored contract-3 approval
  # (scripts/release-approval-withdraw.sh, root, NOT reachable through the
  # deploy operator's entrypoint). Under the release directory's exclusive
  # lock it spends the approval id in the trusted evidence directory
  # (spent/approval-<id>.spent, withdrawn=yes) and then stores the marker
  # <store>/<release>/<id>.withdrawn (root 0400: withdrawn_at, withdrawn_by).
  # Either makes the approval inactive for good: it can never deploy, and it
  # no longer blocks a corrected submission for the release. Nothing is
  # deleted. Returns 0 with `RELEASE APPROVAL WITHDRAWN: <id>`, 77 otherwise.
  local authority="$1" app_dir="$2" uid="$3" release_id="$4" id="$5" now="$6" by="${7:-}"
  local store reldir why
  echo "== Release approval withdrawal (contract 3, DMS-RELEASE-APPROVAL-DIRECT-1) =="
  declare -F dms_trusted_file >/dev/null \
    || { dms_release_approval_withdraw_deny "the trusted-path library (scripts/lib/trusted-path.sh) is missing beside the guard"; return; }
  [[ "$uid" =~ ^[0-9]+$ ]] || { dms_release_approval_withdraw_deny "the deploy identity '${uid}' is not a uid"; return; }
  dms_release_approval_valid_id "$release_id" \
    || { dms_release_approval_withdraw_deny "release id '${release_id}' is not a release identifier"; return; }
  [[ "$id" =~ ^[0-9a-f]{32}$ ]] || { dms_release_approval_withdraw_deny "approval id '${id}' is not 32 lowercase hex characters"; return; }
  [[ "$now" =~ ^[0-9]+$ ]] || { dms_release_approval_withdraw_deny "the clock value '${now}' is not an epoch"; return; }
  [[ "$by" =~ ^[a-z][a-z0-9-]*$ ]] || by="unknown"
  if [ -z "$authority" ] || [ ! -r "$authority" ]; then
    dms_release_approval_withdraw_deny "host authority ${authority:-<none>} is missing or unreadable"
    return
  fi
  store="$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_RELEASE_APPROVAL_STORE)"
  [ -n "$store" ] || { dms_release_approval_withdraw_deny "the authority declares no approval store (DMS_PRODUCTION_RELEASE_APPROVAL_STORE)"; return; }
  reldir="${store}/${release_id}"
  why="$(dms_trusted_dir "release approval store" "$store" "$uid" "$app_dir")" || { dms_release_approval_withdraw_deny "$why"; return; }
  why="$(dms_trusted_dir "release approval directory" "$reldir" "$uid" "$app_dir")" || { dms_release_approval_withdraw_deny "$why"; return; }
  command -v flock >/dev/null 2>&1 || { dms_release_approval_withdraw_deny "flock is not installed"; return; }
  # fd 9 is the directory itself, opened only to hold its lock (flock).
  # shellcheck disable=SC2094
  dms_release_approval_withdraw_locked "$authority" "$app_dir" "$uid" "$reldir" "$release_id" "$id" "$now" "$by" 9<"$reldir" || return
  echo "RELEASE APPROVAL WITHDRAWN: ${id}"
  echo "release_id=${release_id}"
  echo "withdrawn_by=${by}"
  echo "The approval can never deploy; a corrected approval for ${release_id} can now be submitted."
  return 0
}

dms_release_approval_withdraw_locked() {
  local authority="$1" app_dir="$2" uid="$3" reldir="$4" release_id="$5" id="$6" now="$7" by="$8"
  local why evidence at
  flock -x -w 30 9 || { dms_release_approval_withdraw_deny "another writer holds the lock of ${reldir}"; return; }
  dms_release_approval_store_clean_stale "$reldir" >/dev/null || { dms_release_approval_withdraw_deny "${reldir} cannot be listed"; return; }
  why="$(dms_release_approval_store_file "${reldir}/${id}.approval" "$uid")" \
    || { dms_release_approval_withdraw_deny "approval ${id} is not stored for ${release_id}: ${why}"; return; }
  if ! dms_release_approval_parse_direct "${reldir}/${id}.approval"; then
    dms_release_approval_withdraw_deny "stored approval ${id}: ${DMS_RELEASE_APPROVAL_DIRECT_WHY}"
    return
  fi
  if [ "${DMS_RELEASE_APPROVAL_DIRECT_RECORD[DMS_APPROVAL_ID]}" != "$id" ] || [ "${DMS_RELEASE_APPROVAL_DIRECT_RECORD[DMS_APPROVAL_RELEASE_ID]}" != "$release_id" ]; then
    dms_release_approval_withdraw_deny "the stored record ${id} is not approval ${id} of ${release_id}"
    return
  fi
  if [ -e "${reldir}/${id}.withdrawn" ] || [ -L "${reldir}/${id}.withdrawn" ]; then
    dms_release_approval_withdraw_deny "approval ${id} of ${release_id} is already withdrawn"
    return
  fi
  at="$(date -u -d "@${now}" +%Y-%m-%dT%H:%M:%SZ)"
  # 1. Spend the approval id where the guard looks (it already may be spent).
  evidence="$(dms_release_approval_authority_value "$authority" DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR)"
  if [ -z "$evidence" ] || ! why="$(dms_trusted_dir_prepare "deploy evidence directory" "$evidence" "$uid" "$app_dir")" \
     || ! why="$(dms_trusted_dir_prepare "spend records" "${evidence}/spent" "$uid" "$app_dir")"; then
    dms_release_approval_withdraw_deny "${why:-the authority declares no deploy evidence directory} — the withdrawal cannot be recorded"
    return
  fi
  if [ -e "${evidence}/spent/approval-${id}.spent" ] || [ -L "${evidence}/spent/approval-${id}.spent" ]; then
    echo "NOTE: approval ${id} was already spent (${evidence}/spent/approval-${id}.spent)"
  elif ! (umask 077 && set -C && printf 'approval_id=%s\nrelease_id=%s\nwithdrawn=yes\nwithdrawn_at=%s\nwithdrawn_by=%s\n' \
        "$id" "$release_id" "$at" "$by" > "${evidence}/spent/approval-${id}.spent") 2>/dev/null; then
    dms_release_approval_withdraw_deny "${evidence}/spent/approval-${id}.spent could not be created"
    return
  fi
  # 2. The marker in the store, beside the approval it withdraws.
  if ! (umask 077 && set -C && printf 'approval_id=%s\nrelease_id=%s\nwithdrawn_at=%s\nwithdrawn_by=%s\n' \
        "$id" "$release_id" "$at" "$by" > "${reldir}/.${id}.withdrawn.tmp") 2>/dev/null \
     || ! chmod 0400 -- "${reldir}/.${id}.withdrawn.tmp" \
     || ! mv -T -- "${reldir}/.${id}.withdrawn.tmp" "${reldir}/${id}.withdrawn"; then
    rm -f -- "${reldir:?}/.${id:?}.withdrawn.tmp"
    dms_release_approval_withdraw_deny "the marker ${reldir}/${id}.withdrawn could not be stored (the approval id is spent regardless)"
    return
  fi
  return 0
}
