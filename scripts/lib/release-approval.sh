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
# TRUST MODEL (what this does and does not prove). The guard BINDS a deploy to a
# reviewed, merged, content-addressed approval record; it does not AUTHENTICATE
# a human. The record's integrity comes from the canonical remote (it reaches the
# base branch only through a merged pull request, attributable to a GitHub
# account) and from git's content addressing (the host reads the blob from the
# fetched commit). Anyone with root on the production host is inside the trust
# boundary and can bypass any check on that host; the guard stops mistakes,
# races, stale approvals and unreviewed targets, not a malicious root. The
# owner's decision itself is recorded in the sprint document (CG-22).
#
# Every observed value (app dir, branch, host, authority, clock) is a PARAMETER,
# the convention of scripts/lib/production-host-preflight.sh, so the contract is
# testable without root or a server.
#
# Return codes of dms_release_approval_verify: 0 approved, 77 DENIED.

DMS_RELEASE_APPROVAL_CONTRACT=1
DMS_RELEASE_APPROVAL_DIR="deploy/release-approvals"
# Longest window an approval may span from approved_at to not_after.
DMS_RELEASE_APPROVAL_MAX_VALIDITY_SECONDS=259200
# Clock skew tolerated for an approved_at slightly in the future.
DMS_RELEASE_APPROVAL_CLOCK_SKEW_SECONDS=300
DMS_RELEASE_APPROVAL_MAX_BYTES=4096
DMS_RELEASE_APPROVAL_DENIED=77

DMS_RELEASE_APPROVAL_KEYS="DMS_APPROVAL_CONTRACT DMS_APPROVAL_RELEASE_ID DMS_APPROVAL_REPOSITORY DMS_APPROVAL_HOST DMS_APPROVAL_BASE_BRANCH DMS_APPROVAL_TARGET_SHA DMS_APPROVAL_TARGET_TREE DMS_APPROVAL_GO_TAG DMS_APPROVAL_APPROVED_BY DMS_APPROVAL_APPROVED_AT DMS_APPROVAL_NOT_AFTER DMS_APPROVAL_EVIDENCE"

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
  # Trust for this one repository is scoped to this invocation (the runner and
  # the bootstrap run as root over a root-owned checkout; the tests do not).
  git -c safe.directory="$DMS_RELEASE_APPROVAL_GIT_DIR" -C "$DMS_RELEASE_APPROVAL_GIT_DIR" "$@"
}

# The DMS_RELEASE_APPROV* results are read by the sourcing runner/bootstrap.
# shellcheck disable=SC2034
dms_release_approval_verify() {
  # dms_release_approval_verify <app_dir> <branch> <release_id> <approved_sha> <authority> <this_host> <now_epoch>
  #
  # On approval prints `RELEASE APPROVAL: GO` and the bound facts, and sets
  # DMS_RELEASE_APPROVED_SHA, DMS_RELEASE_APPROVAL_TIP, DMS_RELEASE_APPROVAL_BLOB
  # and DMS_RELEASE_APPROVAL_PATH for the caller.
  local app_dir="$1" branch="$2" release_id="$3" approved_sha="$4" authority="$5" this_host="$6" now="$7"
  local prod_host frozen_hosts prod_repo approvers origin_url origin_repo
  local tip path blob size content line key value object_type tree changed head
  local approved_epoch not_after_epoch manifest_tag evidence_blob
  local -A record=()

  DMS_RELEASE_APPROVED_SHA=""
  DMS_RELEASE_APPROVAL_TIP=""
  DMS_RELEASE_APPROVAL_BLOB=""
  DMS_RELEASE_APPROVAL_PATH=""
  DMS_RELEASE_APPROVAL_GIT_DIR="$app_dir"

  echo "== Release approval guard (rule 229) =="

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
  [ -n "$prod_host" ] || { dms_release_approval_deny "the authority declares no production hostname"; return; }
  [ -n "$prod_repo" ] || { dms_release_approval_deny "the authority declares no production repository (DMS_PRODUCTION_REPOSITORY)"; return; }
  [ -n "$approvers" ] || { dms_release_approval_deny "the authority declares no release approver (DMS_PRODUCTION_RELEASE_APPROVERS)"; return; }
  for line in $frozen_hosts; do
    [ "$this_host" = "$line" ] && { dms_release_approval_deny "this host '${this_host}' is FROZEN legacy infrastructure"; return; }
  done
  [ "$this_host" = "$prod_host" ] \
    || { dms_release_approval_deny "this host is '${this_host:-<empty>}', the declared production host is '${prod_host}'"; return; }
  echo "PASS: host '${this_host}' is the declared production host"

  # ── 3. Repository identity ─────────────────────────────────────────────────
  if ! origin_url="$(dms_release_approval_git remote get-url origin 2>/dev/null)"; then
    dms_release_approval_deny "the checkout ${app_dir} has no 'origin' remote"
    return
  fi
  origin_repo="$(dms_release_approval_normalize_repository "$origin_url")"
  [ "$origin_repo" = "$prod_repo" ] \
    || { dms_release_approval_deny "origin is '${origin_repo}', the declared production repository is '${prod_repo}'"; return; }
  echo "PASS: origin is the declared repository '${prod_repo}'"

  # ── 4. Resolve the base branch from the remote (refs only) ─────────────────
  if ! dms_release_approval_git fetch --no-tags --quiet origin "refs/heads/${branch}"; then
    dms_release_approval_deny "could not fetch origin ${branch}"
    return
  fi
  tip="$(dms_release_approval_git rev-parse --verify --quiet 'FETCH_HEAD^{commit}')" \
    || { dms_release_approval_deny "the fetched ${branch} does not resolve to a commit"; return; }
  echo "Base branch tip:    ${tip} (origin ${branch})"

  object_type="$(dms_release_approval_git cat-file -t "$approved_sha" 2>/dev/null)" || object_type="missing"
  [ "$object_type" = "commit" ] \
    || { dms_release_approval_deny "approved SHA ${approved_sha} is a '${object_type}' object in this repository, not a commit"; return; }

  # ── 5. The approval record, from the fetched tip's tree ────────────────────
  path="${DMS_RELEASE_APPROVAL_DIR}/${release_id}.approval"
  if ! blob="$(dms_release_approval_git rev-parse --verify --quiet "${tip}:${path}")"; then
    dms_release_approval_deny "no approval record ${path} on origin ${branch} at ${tip} — the release is not approved"
    return
  fi
  [ "$(dms_release_approval_git cat-file -t "$blob" 2>/dev/null)" = "blob" ] \
    || { dms_release_approval_deny "${path} at ${tip} is not a file"; return; }
  size="$(dms_release_approval_git cat-file -s "$blob")"
  [ "$size" -le "$DMS_RELEASE_APPROVAL_MAX_BYTES" ] \
    || { dms_release_approval_deny "${path} is ${size} bytes; the limit is ${DMS_RELEASE_APPROVAL_MAX_BYTES}"; return; }
  content="$(dms_release_approval_git cat-file blob "$blob")"

  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in ''|'#'*) continue ;; esac
    if [[ ! "$line" =~ ^(DMS_APPROVAL_[A-Z_]+)=([A-Za-z0-9._:/+-]+)$ ]]; then
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
  # Read whole, then match: `cat-file | grep -q` under pipefail can turn an
  # early grep exit (SIGPIPE) into a false denial on a large document.
  value="$(dms_release_approval_git cat-file blob "$evidence_blob")" || value=""
  [[ "$value" == *"$approved_sha"* ]] \
    || { dms_release_approval_deny "evidence document ${record[DMS_APPROVAL_EVIDENCE]} does not quote ${approved_sha}"; return; }

  # ── 9. The approved commit is merged; only approvals and docs follow it ───
  dms_release_approval_git merge-base --is-ancestor "$approved_sha" "$tip" \
    || { dms_release_approval_deny "approved SHA ${approved_sha} is not on origin ${branch} (not merged)"; return; }
  changed="$(dms_release_approval_git diff --name-only --no-renames "$approved_sha" "$tip")"
  while IFS= read -r line; do
    [ -n "$line" ] || continue
    if [[ "$line" =~ ^deploy/release-approvals/[a-z0-9][a-z0-9-]{2,79}\.approval$ ]] \
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

  DMS_RELEASE_APPROVED_SHA="$approved_sha"
  DMS_RELEASE_APPROVAL_TIP="$tip"
  DMS_RELEASE_APPROVAL_BLOB="$blob"
  DMS_RELEASE_APPROVAL_PATH="$path"
  echo "PASS: approved by '${record[DMS_APPROVAL_APPROVED_BY]}' at ${record[DMS_APPROVAL_APPROVED_AT]}, valid until ${record[DMS_APPROVAL_NOT_AFTER]}"
  echo "PASS: evidence ${record[DMS_APPROVAL_EVIDENCE]}; GO tag ${record[DMS_APPROVAL_GO_TAG]}"
  echo "RELEASE APPROVAL: GO"
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
