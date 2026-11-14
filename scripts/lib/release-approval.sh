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
  # DMS_RELEASE_APPROVAL_SIGNER_KEY and DMS_RELEASE_APPROVAL_HOST_KEY.
  #
  # The base branch is fetched into a PRIVATE ref for this invocation, never read
  # through the shared FETCH_HEAD that any other fetch on the host (a rollback's
  # `git fetch --tags`) can overwrite between our fetch and our read. Everything
  # after that read uses immutable object ids; the ref is removed on every path.
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
  local -A record=()

  DMS_RELEASE_APPROVED_SHA=""
  DMS_RELEASE_APPROVAL_TIP=""
  DMS_RELEASE_APPROVAL_BLOB=""
  DMS_RELEASE_APPROVAL_PATH=""
  DMS_RELEASE_APPROVAL_SIGNATURE_BLOB=""
  DMS_RELEASE_APPROVAL_SIGNER_KEY=""
  DMS_RELEASE_APPROVAL_HOST_KEY=""

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
  if ! dms_release_approval_git fetch --no-tags --quiet origin "+refs/heads/${branch}:${DMS_RELEASE_APPROVAL_REF}"; then
    dms_release_approval_deny "could not fetch origin ${branch}"
    return
  fi
  tip="$(dms_release_approval_git rev-parse --verify --quiet "${DMS_RELEASE_APPROVAL_REF}^{commit}")" \
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

  DMS_RELEASE_APPROVED_SHA="$approved_sha"
  DMS_RELEASE_APPROVAL_TIP="$tip"
  DMS_RELEASE_APPROVAL_BLOB="$blob"
  DMS_RELEASE_APPROVAL_PATH="$path"
  DMS_RELEASE_APPROVAL_SIGNATURE_BLOB="$sig_blob"
  DMS_RELEASE_APPROVAL_SIGNER_KEY="$signer_key"
  DMS_RELEASE_APPROVAL_HOST_KEY="$prod_host_key"
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
  # dms_release_approval_record_spent <authority> <app_dir> <deploy_uid> <release_id> <approved_sha> <blob> <run_id>
  #
  # Called by the bootstrap, INSIDE the release lock, after the deploy program
  # succeeded: the spend record is written before the lock is released, by the
  # process that actually deployed, whether or not a runner launched it. An
  # exclusive create; a failure is reported, never fatal (the deploy is done).
  local authority="$1" app_dir="$2" uid="$3" release_id="$4" approved="$5" blob="$6" run_id="$7" dir why rec
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
    return 1
  fi
  echo "DEPLOY_APPROVAL_SPENT=${rec}"
}
