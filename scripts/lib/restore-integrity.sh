# shellcheck shell=bash
#
# DMS-DEPLOY-PRIVSEP-PHASE-2 (rule 235) — a dump is restored only when its bytes
# match TRUSTED evidence (rule 233 §9 R4).
#
# Sourced (never executed) by the three superuser restore tools,
# scripts/restore_postgres.sh, scripts/restore-rehearsal.sh and
# scripts/rollout-restore-drill.sh, after scripts/lib/trusted-path.sh. The drill
# (finding A-1, 2026-11-25) uses the full path below, plus the source checks and
# the restricted replay stream at the end of this file.
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

# dms_restore_owner_roles <plain-text dump>
# Prints, once each, every role the dump makes an object owner of: the target of
# `ALTER … OWNER TO <role>;` and of `SET SESSION AUTHORIZATION '<role>';`.
# COPY data blocks are skipped, so table contents never count as statements. A
# quoted name is unquoted ("" becomes ").
dms_restore_owner_roles() {
  awk '
    function unquote(name) {
      if (name ~ /^".*"$/) { name = substr(name, 2, length(name) - 2); gsub(/""/, "\"", name) }
      return name
    }
    in_copy { if ($0 == "\\.") in_copy = 0; next }
    /^COPY .* FROM stdin;$/ { in_copy = 1; next }
    /^ALTER .* OWNER TO .+;$/ {
      name = $0; sub(/^.* OWNER TO /, "", name); sub(/;$/, "", name)
      print unquote(name); next
    }
    /^SET SESSION AUTHORIZATION .+;$/ {
      name = $0; sub(/^SET SESSION AUTHORIZATION /, "", name); sub(/;$/, "", name)
      if (name ~ /^'\''.*'\''$/) { name = substr(name, 2, length(name) - 2); gsub(/'\'''\''/, "'\''", name) }
      print unquote(name)
    }
  ' < "${1:?dump}" | sort -u
}

# dms_restore_missing_owner_roles <plain-text dump> <command prefix…>
# Owner decision D-Q6-a: every role that owns an object in the dump must already
# exist on the target cluster before anything is created or replayed. The
# command prefix runs psql as the superuser (e.g. as_superuser). Prints each
# missing role, one per line. Returns 0 when none is missing, 1 when any is, and
# 2 when the cluster cannot be asked. It never creates a role, never strips or
# rewrites ownership, and never hands ownership to the runtime: an authorised
# operator provisions the role separately, then repeats the restore.
dms_restore_missing_owner_roles() {
  local dump="${1:?dump}" owners existing missing
  shift
  owners="$(dms_restore_owner_roles "$dump")" || return 2
  [ -n "$owners" ] || return 0
  existing="$("$@" psql -X -Atqc 'SELECT rolname FROM pg_catalog.pg_roles' 2> /dev/null)" || return 2
  [ -n "$existing" ] || return 2
  missing="$(comm -23 <(printf '%s\n' "$owners") <(printf '%s\n' "$existing" | sort -u))"
  if [ -n "$missing" ]; then
    printf '%s\n' "$missing"
    return 1
  fi
  return 0
}

# dms_restore_r6_owner_role
# The schema owner role of the R6 design (rule 245). Its presence on a cluster
# means the privilege split has been provisioned there. Override with
# DMS_R6_OWNER_ROLE; the name must be a plain role name.
dms_restore_r6_owner_role() {
  local role="${DMS_R6_OWNER_ROLE:-dms_owner}"
  case "$role" in
    ''|*[!A-Za-z0-9_]*) _dms_restore_err "DMS_R6_OWNER_ROLE is not a plain role name"; return 2 ;;
  esac
  printf '%s\n' "$role"
}

# dms_restore_r6_active <command prefix…>
# Returns 0 when the cluster has the R6 owner role (the privilege split is
# provisioned), 1 when it has not, 2 when the cluster cannot be asked.
dms_restore_r6_active() {
  local owner_role present
  owner_role="$(dms_restore_r6_owner_role)" || return 2
  present="$("$@" psql -X -Atqc "SELECT count(*) FROM pg_catalog.pg_roles WHERE rolname = '${owner_role}'" 2> /dev/null)" || return 2
  case "$present" in
    0) return 1 ;;
    1) return 0 ;;
    *) return 2 ;;
  esac
}

# dms_restore_runtime_ownership_gate <plain-text dump> <runtime role> <command prefix…>
# Owner directive "G6 RELEASE-1 AND R6 CONTINUATION" P5: after the R6 split,
# objects owned by the runtime are never an acceptable final state. A dump that
# makes the runtime an object owner is a pre-R6 dump: the standard restore
# refuses it before anything is created, and points to the separate
# operator-controlled legacy procedure. No flag lets it through (D-Q6-b). On a
# cluster without the owner role (before R6-D), runtime ownership is still the
# current design and the gate passes.
# Returns 0 to proceed, 1 to refuse (reason on stdout), 2 when it cannot decide.
dms_restore_runtime_ownership_gate() {
  local dump="${1:?dump}" runtime="${2:?runtime}" active=0
  shift 2
  case "$runtime" in
    *[!A-Za-z0-9_]*) _dms_restore_err "the runtime role is not a plain role name"; return 2 ;;
  esac
  dms_restore_r6_active "$@" || active=$?
  case "$active" in
    1) return 0 ;;
    0) : ;;
    *) return 2 ;;
  esac
  if dms_restore_owner_roles "$dump" | grep -qxF -- "$runtime"; then
    printf '%s\n' "this cluster has the R6 owner role '$(dms_restore_r6_owner_role)', and the dump makes the application login role '${runtime}' an object owner (a pre-R6 dump). Runtime ownership is not an acceptable final state after R6, so the standard restore refuses it. Restore it into a new database with the separate operator-controlled legacy procedure."
    return 1
  fi
  return 0
}

# dms_restore_runtime_owned_objects <database> <runtime role> <command prefix…>
# Prints how many tables, views, sequences, functions, types and schemas in the
# database the runtime owns (catalog only; no row is read).
dms_restore_runtime_owned_objects() {
  local db="${1:?database}" runtime="${2:?runtime}"
  shift 2
  case "$runtime" in
    *[!A-Za-z0-9_]*) _dms_restore_err "the runtime role is not a plain role name"; return 2 ;;
  esac
  "$@" psql -X -Atq -d "$db" -c "SELECT (SELECT count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE pg_catalog.pg_get_userbyid(c.relowner) = '${runtime}' AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg_toast%') + (SELECT count(*) FROM pg_catalog.pg_proc p WHERE pg_catalog.pg_get_userbyid(p.proowner) = '${runtime}') + (SELECT count(*) FROM pg_catalog.pg_type t WHERE pg_catalog.pg_get_userbyid(t.typowner) = '${runtime}') + (SELECT count(*) FROM pg_catalog.pg_namespace s WHERE pg_catalog.pg_get_userbyid(s.nspowner) = '${runtime}')"
}

# dms_restore_check_source <dump path> <allowed owner…>
# Finding A-1 (2026-11-25): before a dump is staged for a superuser replay, the
# file itself must look like a backup the expected identities wrote. Refused: a
# missing path, a symlink, anything but a regular file, an owner other than the
# listed accounts (the runtime that writes backups, or the operator running the
# tool), and a file that is group- or other-writable. These checks narrow who
# could have changed the file; the trusted digest decides whether it did.
dms_restore_check_source() {
  local dump="${1:-}" owner mode allowed ok=0
  shift || true
  if [ -z "$dump" ] || [ -L "$dump" ] || [ ! -f "$dump" ]; then
    _dms_restore_err "${dump:-<none>} is missing, a symlink or not a regular file"
    return 1
  fi
  owner="$(stat -c '%U' -- "$dump")" || { _dms_restore_err "cannot read the owner of ${dump}"; return 1; }
  mode="$(stat -c '%a' -- "$dump")" || { _dms_restore_err "cannot read the mode of ${dump}"; return 1; }
  for allowed in "$@"; do
    [ -n "$allowed" ] && [ "$owner" = "$allowed" ] && ok=1
  done
  if [ "$ok" != 1 ]; then
    _dms_restore_err "${dump} is owned by '${owner}', not by one of: $*"
    return 1
  fi
  if [ $(( 8#${mode} & 8#022 )) -ne 0 ]; then
    _dms_restore_err "${dump} is group- or other-writable (mode ${mode})"
    return 1
  fi
}

# dms_restore_restrict_key — prints a fresh random key for psql's restricted mode.
# It is generated after the dump was written and is never stored, so no dump can
# carry the matching \unrestrict.
dms_restore_restrict_key() {
  local key
  key="$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  [[ "$key" =~ ^[0-9a-f]{64}$ ]] || { _dms_restore_err "could not generate a restricted-mode key"; return 1; }
  printf '%s\n' "$key"
}

# dms_restore_restricted_stream <staged copy> <key>
# Prints the replay stream: `\restrict <key>`, then the staged dump. The dump's
# own envelope is removed, and only when it is complete: a first statement line
# `\restrict K` together with a last non-blank line `\unrestrict K`, with the same
# K. Everything else is passed through unchanged. psql (16.10 or later) then runs
# the whole dump in restricted mode: every backslash meta-command, at the start of
# a line or inside one, is refused, and with ON_ERROR_STOP the replay stops. COPY
# data and SQL are unaffected. A dump whose envelope is incomplete keeps its own
# `\restrict` line, which restricted mode refuses, so the replay fails closed.
dms_restore_restricted_stream() {
  local staged="${1:-}" key="${2:-}"
  [[ "$key" =~ ^[A-Za-z0-9]+$ ]] || { _dms_restore_err "the restricted-mode key is not alphanumeric"; return 1; }
  [ -f "$staged" ] && [ ! -L "$staged" ] || { _dms_restore_err "the staged copy is missing"; return 1; }
  printf '\\restrict %s\n' "$key"
  awk '
    NR == FNR {
      if (!first && $0 !~ /^[[:space:]]*$/ && $0 !~ /^--/) { first = FNR; firstline = $0 }
      if ($0 !~ /^[[:space:]]*$/) { last = FNR; lastline = $0 }
      next
    }
    FNR == 1 {
      drop_first = 0; drop_last = 0
      if (firstline ~ /^\\restrict [A-Za-z0-9]+$/) {
        k = substr(firstline, 11)
        if (last != first && lastline == "\\unrestrict " k) { drop_first = first; drop_last = last }
      }
    }
    FNR == drop_first || FNR == drop_last { next }
    { print }
  ' "$staged" "$staged"
}
