# shellcheck shell=bash
#
# DMS-DEPLOY-PRIVSEP-PHASE-2 (rule 235) — a dump is restored only when its bytes
# match TRUSTED evidence (rule 233 §9 R4).
#
# Sourced (never executed) by the two superuser restore tools,
# scripts/restore_postgres.sh and scripts/restore-rehearsal.sh, after
# scripts/lib/trusted-path.sh.
#
# WHY: every dump is plain SQL, owned and writable by the runtime user, and the
# restore streams it into psql as the local PostgreSQL superuser. A dump changed
# after it was written could carry a psql meta-command that runs as that OS
# account. A digest stored beside the dump proves nothing: the runtime user could
# rewrite both. So:
#
#   1. The EXPECTED digest comes from evidence the runtime cannot write — the
#      root-only log of the deploy that wrote the dump
#      (DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR: root-owned, not group/other-writable,
#      canonical, outside the checkout — trusted-path.sh), with exactly one
#      `Pre-deploy backup: <path> sha256=<hex>` line and a run record naming this
#      host and that deploy — or a digest the ADMINISTRATOR passes from an
#      independent record (the rollback's own output, an off-server manifest).
#   2. The dump is read ONCE, by the account that owns it, into a root-only
#      staging copy. Root hashes the copy and the restore reads only the copy,
#      so nothing can change the bytes between the check and the use.
#   3. The copy is a non-empty plain-text pg_dump (header check).
# Missing, ambiguous or mismatched evidence fails closed (exit 1).

_dms_restore_err() { echo "restore-integrity: $*" >&2; }

# dms_restore_evidence_digest <evidence dir> <deploy id> <this host> <app dir> <trusted uid>
# Prints "<dump path relative to the app dir> <sha256>" from the deploy's
# root-only log; returns 1 when the evidence is missing, untrusted or ambiguous.
dms_restore_evidence_digest() {
  local dir="${1:-}" id="${2:-}" host="${3:-}" app="${4:-}" uid="${5:-}" log record why lines matches
  if [[ ! "$id" =~ ^[0-9]{8}-[0-9]{6}$ ]]; then
    _dms_restore_err "deploy id '${id}' is not YYYYmmdd-HHMMSS"
    return 1
  fi
  if ! why="$(dms_trusted_dir "deploy evidence directory" "$dir" "$uid" "$app")"; then
    _dms_restore_err "$why"
    return 1
  fi
  log="${dir}/deploy-${id}.log"
  record="${dir}/deploy-${id}.host"
  local evidence
  for evidence in "$log" "$record"; do
    if ! why="$(dms_trusted_file "deploy evidence" "$evidence" "$uid" "$app")"; then
      _dms_restore_err "$why"
      return 1
    fi
  done
  if [ "$(sed -n 's/^host=//p' "$record" | head -n 1)" != "$host" ] \
     || [ "$(sed -n 's/^deploy_id=//p' "$record" | head -n 1)" != "$id" ]; then
    _dms_restore_err "the run record of deploy ${id} does not name this host and that deploy"
    return 1
  fi
  lines="$(grep -c '^Pre-deploy backup:' "$log" || true)"
  matches="$(grep -E '^Pre-deploy backup: storage/app/backups/deploy/pre_auto_deploy_[0-9]{8}-[0-9]{6}\.sql sha256=[0-9a-f]{64}$' "$log" || true)"
  if [ "$lines" != 1 ] || [ "$(printf '%s\n' "$matches" | grep -c . || true)" != 1 ]; then
    _dms_restore_err "deploy ${id}'s log holds ${lines} pre-deploy backup line(s), not exactly one well-formed line — refusing ambiguous evidence"
    return 1
  fi
  printf '%s\n' "$matches" | sed -E 's/^Pre-deploy backup: ([^ ]+) sha256=([0-9a-f]{64})$/\1 \2/'
}

# dms_restore_stage_copy <dump path> <staging dir> — copies the dump into a new
# root-only file inside <staging dir> and prints its path. The dump is opened by
# the account that owns it (never by root through a path that account controls);
# a symlink, a non-regular file or an empty dump is refused.
dms_restore_stage_copy() {
  local dump="${1:-}" staging="${2:-}" owner staged
  if [ -L "$dump" ] || [ ! -f "$dump" ]; then
    _dms_restore_err "${dump} is missing, a symlink or not a regular file"
    return 1
  fi
  if [ ! -d "$staging" ] || [ -L "$staging" ] || [ "$(stat -c '%u %a' -- "$staging")" != "$(id -u) 700" ]; then
    _dms_restore_err "staging directory ${staging} is not this identity's private (0700) directory"
    return 1
  fi
  owner="$(stat -c '%U' -- "$dump")"
  staged="${staging}/dump.sql"
  ( umask 077 && set -C && : > "$staged" ) || { _dms_restore_err "could not create the staging copy"; return 1; }
  if [ "$owner" = "$(id -un)" ]; then
    cat -- "$dump" > "$staged"
  else
    runuser -u "$owner" -- cat -- "$dump" < /dev/null > "$staged"
  fi
  [ -s "$staged" ] || { _dms_restore_err "the staging copy of ${dump} is empty"; return 1; }
  printf '%s\n' "$staged"
}

# dms_restore_verify_copy <staged copy> <expected sha256> — the copy's digest
# equals the trusted one and the copy is a plain-text pg_dump.
dms_restore_verify_copy() {
  local staged="${1:-}" expected="${2:-}" actual
  if [[ ! "$expected" =~ ^[0-9a-f]{64}$ ]]; then
    _dms_restore_err "the expected digest is not a SHA-256 hex digest"
    return 1
  fi
  actual="$(sha256sum < "$staged" | cut -d' ' -f1)"
  if [ "$actual" != "$expected" ]; then
    _dms_restore_err "DIGEST MISMATCH: the dump is ${actual}, the trusted evidence says ${expected} — the dump changed after it was written; nothing was restored"
    return 1
  fi
  if ! head -c 4096 -- "$staged" | grep -q -- '-- PostgreSQL database dump'; then
    _dms_restore_err "the dump is not a plain-text pg_dump (no header) — nothing was restored"
    return 1
  fi
  echo "RESTORE_INTEGRITY=VERIFIED sha256=${actual}"
}
