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
  if [ "$owner" = "$(id -un)" ] || [ "$(id -u)" != 0 ]; then
    # Opened without following a symlink and without blocking on a FIFO: the path
    # may have been swapped since the check above (finding A-1 review, F2). An
    # unprivileged identity (the drill runner reading the operator's inbox file
    # through its group) opens it with its own permissions; only root, which would
    # bypass them, reads another account's file as that account.
    dd if="$dump" iflag=nofollow,nonblock status=none > "$staged" \
      || { _dms_restore_err "could not read ${dump} (changed into a symlink or a special file?)"; return 1; }
  else
    runuser -u "$owner" -- cat -- "$dump" < /dev/null > "$staged" \
      || { _dms_restore_err "could not read ${dump} as its owner ${owner}"; return 1; }
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

# dms_restore_pg_client_check <bin dir> <trusted uid>
# Owner directive "G6 PR #699 AND #695 PUBLICATION" (2026-11-25): the drill runs
# psql, createdb and dropdb from ONE pinned directory by absolute path, never
# through PATH, so another binary cannot be swapped in by changing PATH (or
# through the version-switching wrapper on PATH). The directory must be canonical
# (no symlink), outside the application checkout <app dir>, owned by root or the
# trusted uid, not group- or other-writable, with a trusted chain above it
# (trusted-path.sh). Each tool must be a regular, executable, non-symlink file
# with the same owner and mode rules. Returns 1 with the reason on stderr.
dms_restore_pg_client_check() {
  local dir="${1:-}" uid="${2:-}" app="${3:-}" tool path stat_out owner mode why
  if [ -z "$dir" ] || [ "$dir" = "/" ] || ! dms_trusted_canonical "$dir" || [ -L "$dir" ] || [ ! -d "$dir" ]; then
    _dms_restore_err "PostgreSQL client directory '${dir}' is not a canonical, real directory (no symlink, no '..')"
    return 1
  fi
  if ! dms_trusted_outside "$dir" "$app"; then
    _dms_restore_err "PostgreSQL client directory ${dir} lies inside the application checkout ${app}"
    return 1
  fi
  stat_out="$(stat -c '%u %a' -- "$dir" 2>/dev/null)" || { _dms_restore_err "cannot inspect ${dir}"; return 1; }
  owner="${stat_out% *}"; mode="${stat_out#* }"
  if ! dms_trusted_owner_ok "$owner" "$uid" || ! dms_trusted_mode_private "$mode"; then
    _dms_restore_err "PostgreSQL client directory ${dir} is uid ${owner} mode ${mode}; it must be root's or uid ${uid}'s and not group- or other-writable"
    return 1
  fi
  if ! why="$(dms_trusted_ancestors "$dir" "$uid")"; then
    _dms_restore_err "PostgreSQL client directory ${dir}: ${why}"
    return 1
  fi
  for tool in psql createdb dropdb; do
    path="${dir}/${tool}"
    if ! dms_trusted_canonical "$path" || [ -L "$path" ] || [ ! -f "$path" ] || [ ! -x "$path" ]; then
      _dms_restore_err "${path} is missing, a symlink, not a regular file or not executable"
      return 1
    fi
    stat_out="$(stat -c '%u %a' -- "$path" 2>/dev/null)" || { _dms_restore_err "cannot inspect ${path}"; return 1; }
    owner="${stat_out% *}"; mode="${stat_out#* }"
    if ! dms_trusted_owner_ok "$owner" "$uid" || ! dms_trusted_mode_private "$mode"; then
      _dms_restore_err "${path} is uid ${owner} mode ${mode}; it must be root's or uid ${uid}'s and not group- or other-writable"
      return 1
    fi
  done
}

# dms_restore_psql_version_check <first line of `psql --version`>
# The drill needs psql from PostgreSQL 16, at 16.15 or later (owner directive:
# restricted mode, CVE-2025-8714, arrived in 16.10; 16.15 is the required floor).
# Another major version, an older minor or an unreadable answer fails closed.
dms_restore_psql_version_check() {
  local line="${1:-}" major minor
  # At most 4 digits per part, so the comparison below can never overflow.
  if [[ "$line" =~ ^psql\ \(PostgreSQL\)\ ([0-9]{1,4})\.([0-9]{1,4})([^0-9.]|$) ]]; then
    major="${BASH_REMATCH[1]}"; minor="${BASH_REMATCH[2]}"
  else
    _dms_restore_err "cannot verify the psql version (answer: '${line:0:80}')"
    return 1
  fi
  if [ "$major" != 16 ] || (( 10#$minor < 15 )); then
    _dms_restore_err "psql ${major}.${minor} is not allowed: the drill needs PostgreSQL 16 psql 16.15 or later"
    return 1
  fi
  printf 'PSQL_CLIENT=VERIFIED %s.%s\n' "$major" "$minor"
}

# dms_restore_split_check_entries <staged copy>
# Third independent review of finding A-1: when a CHECK constraint (on a table or
# a domain) and its function depend on each other, for example through the
# table's row type, pg_dump writes the constraint as its own entry (`Type: CHECK
# CONSTRAINT`) after the data. That `ALTER … ADD CONSTRAINT` validates every
# existing row in the replay's superuser session before any later guard call can
# see the constraint. The load guard cannot run in between, so such a dump is
# refused before anything is created. The match is anywhere in a header line: a
# planted name can add text to a header but cannot remove pg_dump's own `Type:`.
# Prints the number of such entries and returns 1 when there is any.
dms_restore_split_check_entries() {
  local staged="${1:-}" count
  [ -f "$staged" ] && [ ! -L "$staged" ] || { _dms_restore_err "the staged copy is missing"; return 2; }
  count="$(grep -cE '^-- (Data for )?Name: .*; Type: CHECK CONSTRAINT;' -- "$staged" || true)"
  printf '%s\n' "${count:-0}"
  [ "${count:-0}" = 0 ]
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

# dms_restore_load_guard_sql <tag>
# Finding A-1 reviews (F1, then H1-H4): a digest-matching dump is pg_dump's
# faithful output, but before R6-D the runtime owns the schema and can plant code
# in it, which the replay runs in the superuser session. Places found so far:
# - COPY evaluates CHECK and domain constraints, generated columns and partition
#   keys;
# - in the schema section, an aggregate INITCOND and a partition bound are coerced
#   through a domain CHECK;
# - SQL/JSON constructors (JSON_OBJECT …) call a type's cast to json without a
#   function id in the stored expression;
# - foreign-key validation (RI_Initial_Check) runs as the session user and uses
#   operators and implicit casts.
# Index builds and REFRESH MATERIALIZED VIEW run as the table owner; triggers,
# rules and policies do not fire during the replay.
# This prints a session-local function (pg_temp: gone with the session, never part
# of the restored database). It refuses when any such expression calls a
# user-defined function or operator or a builtin that is not IMMUTABLE (lo_export,
# query_to_xml, pg_read_file and the like), and when the database defines its own
# casts, operators, operator classes or families. Objects that belong to an
# extension come from trusted server files. The stream calls it before every
# object pg_dump writes and at the end (see dms_restore_restricted_stream). <tag>
# is random per run, so no dump text can close its dollar quote. This is a
# deny-list: it narrows a superuser replay of a runtime-owned schema, but it is
# not a proof that no other path exists (rule 235 §5).
dms_restore_load_guard_sql() {
  local tag="${1:-}"
  [[ "$tag" =~ ^g[0-9a-f]{16,}$ ]] || { _dms_restore_err "the load-guard tag is not g<hex>"; return 1; }
  cat <<SQL
CREATE FUNCTION pg_temp.dms_restore_guard_${tag}() RETURNS void
LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS \$${tag}\$
DECLARE
  problem text;
  r record;
  regt text;
  sens text;
  tf text := ':(consttype|vartype|paramtype|funcresulttype|opresulttype|resulttype|array_typeid|element_typeid|casetype|coalescetype|minmaxtype|typeId|refcontainertype|refelemtype|refrestype|row_typeid|aggtype) ';
BEGIN
  -- R6F-P2, R6F-P6: the types (and arrays) whose values the RESTORING cluster assigns: object ids, and ACL items (role
  -- ids). R6F-P7: the types whose text form depends on session settings (time zone, styles, float digits, bytea and
  -- XML output); a conversion through the text form is refused only when one of them is involved.
  SELECT string_agg(t.oid::text, '|') INTO regt FROM pg_type t
   WHERE t.typnamespace = 'pg_catalog'::regnamespace
     AND ltrim(t.typname, '_') = ANY (ARRAY['regclass', 'regtype', 'regproc', 'regprocedure', 'regoper', 'regoperator',
       'regrole', 'regnamespace', 'regcollation', 'regconfig', 'regdictionary', 'aclitem']);
  -- (R6F-P14: regconfig and regdictionary stay in the list: even the built-in text search objects get their ids after
  -- the collations at initdb, so the ids differ with the host's locales; full-text expressions are a false NO-GO.)
  SELECT string_agg(t.oid::text, '|') INTO sens FROM pg_type t
   WHERE t.typnamespace = 'pg_catalog'::regnamespace
     AND ltrim(t.typname, '_') = ANY (ARRAY['float4', 'float8', 'bytea', 'time', 'timetz', 'interval', 'timestamptz',
       'timestamp', 'date', 'money', 'xml', 'point', 'lseg', 'path', 'box', 'polygon', 'line', 'circle', 'record']);
  WITH ext AS (
    SELECT classid, objid FROM pg_depend WHERE deptype = 'e'
  ), expr AS (
    SELECT format('check constraint %I', c.conname) AS what, c.conbin::text AS tree, c.oid AS uoid
      FROM pg_constraint c WHERE c.contype = 'c' AND c.conbin IS NOT NULL
    UNION ALL
    SELECT format('generated column %s.%I', d.adrelid::regclass, a.attname), d.adbin::text, d.oid
      FROM pg_attrdef d JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum
     WHERE a.attgenerated <> ''
    UNION ALL
    SELECT format('partition key of %s', p.partrelid::regclass), p.partexprs::text, p.partrelid
      FROM pg_partitioned_table p WHERE p.partexprs IS NOT NULL
  ), ops AS (
    SELECT e.what, m[1]::oid AS op, e.uoid
      FROM expr e CROSS JOIN LATERAL regexp_matches(e.tree, ':opno ([0-9]+)', 'g') AS m
    UNION ALL
    SELECT e.what, x::oid, e.uoid
      FROM expr e CROSS JOIN LATERAL regexp_matches(e.tree, ':opnos \(o ([0-9 ]+)\)', 'g') AS m
      CROSS JOIN LATERAL unnest(string_to_array(m[1], ' ')) AS x
  ), fns AS (
    SELECT e.what, m[1]::oid AS fn, e.uoid
      FROM expr e CROSS JOIN LATERAL regexp_matches(e.tree, ':[a-z]*funcid ([0-9]+)', 'g') AS m
    UNION ALL
    SELECT o.what, p.oprcode::oid, o.uoid FROM ops o JOIN pg_operator p ON p.oid = o.op
  )
  SELECT string_agg(DISTINCT reason, '; ') INTO problem FROM (
    -- P4P-1: code that ran while the schema was created can drop the function it was called from; the stored
    -- expression then names an OID that no longer resolves, which is refused rather than ignored. (A stored tree
    -- carries opfuncid 0 until it is planned: 0 is "not set", never a reference.)
    SELECT f.what || ' calls a function that no longer exists (oid ' || f.fn::text || ')' AS reason
      FROM fns f WHERE f.fn <> 0 AND NOT EXISTS (SELECT 1 FROM pg_proc p WHERE p.oid = f.fn)
    UNION ALL
    SELECT o.what || ' uses an operator that no longer exists (oid ' || o.op::text || ')'
      FROM ops o WHERE o.op <> 0 AND NOT EXISTS (SELECT 1 FROM pg_operator p WHERE p.oid = o.op)
    UNION ALL
    SELECT f.what || ' calls ' || p.oid::regprocedure::text
      FROM fns f JOIN pg_proc p ON p.oid = f.fn
     WHERE p.provolatile <> 'i'
        OR (p.oid >= 16384 AND NOT EXISTS (SELECT 1 FROM ext WHERE ext.classid = 'pg_proc'::regclass AND ext.objid = p.oid))
    UNION ALL
    SELECT o.what || ' uses operator ' || o.op::regoperator::text
      FROM ops o
     WHERE o.op >= 16384 AND NOT EXISTS (SELECT 1 FROM ext WHERE ext.classid = 'pg_operator'::regclass AND ext.objid = o.op)
    UNION ALL
    SELECT 'user-defined operator ' || p.oid::regoperator::text
      FROM pg_operator p
     WHERE p.oid >= 16384 AND NOT EXISTS (SELECT 1 FROM ext WHERE ext.classid = 'pg_operator'::regclass AND ext.objid = p.oid)
    UNION ALL
    SELECT 'user-defined operator class ' || quote_ident(c.opcname)
      FROM pg_opclass c
     WHERE c.oid >= 16384 AND NOT EXISTS (SELECT 1 FROM ext WHERE ext.classid = 'pg_opclass'::regclass AND ext.objid = c.oid)
    UNION ALL
    SELECT 'user-defined operator family ' || quote_ident(f.opfname)
      FROM pg_opfamily f
     WHERE f.oid >= 16384 AND NOT EXISTS (SELECT 1 FROM ext WHERE ext.classid = 'pg_opfamily'::regclass AND ext.objid = f.oid)
    UNION ALL
    -- R6B-1: session- or time-dependent values carry no function id (CURRENT_USER, CURRENT_DATE, LOCALTIMESTAMP …).
    SELECT e.what || ' uses a session- or time-dependent value'
      FROM expr e WHERE e.tree ~ '[{](SQLVALUEFUNCTION|NEXTVALUEEXPR) '
    UNION ALL
    SELECT 'user-defined cast ' || format_type(c.castsource, NULL) || ' AS ' || format_type(c.casttarget, NULL)
      FROM pg_cast c
     WHERE c.oid >= 16384 AND NOT EXISTS (SELECT 1 FROM ext WHERE ext.classid = 'pg_cast'::regclass AND ext.objid = c.oid)
    UNION ALL
    -- R6F-P1: IMMUTABLE by PostgreSQL's label, yet the result depends on session settings (TimeZone, extra_float_digits,
    -- bytea_output, IntervalStyle, xmlbinary …): an XML expression, a conversion through a value's text form, a domain
    -- coercion, or a call of such an output or XML function. Refused in the dump's own objects.
    SELECT e.what || ' uses an XML or JSON constructor, a domain coercion, or a text-form conversion of a session-dependent value'
      FROM expr e
     WHERE e.uoid >= 16384
       AND (e.tree ~ '[{](XMLEXPR|COERCETODOMAIN|JSONCONSTRUCTOREXPR) '
            OR (e.tree ~ '[{]COERCEVIAIO ' AND (e.tree ~ (tf || '(' || sens || ')[ }]') OR e.tree ~ ':varattno 0 ' OR e.tree ~ '[{]ROWEXPR ')))
    UNION ALL
    SELECT f.what || ' calls ' || p.oid::regprocedure::text || ' (its result depends on session settings)'
      FROM fns f JOIN pg_proc p ON p.oid = f.fn
     WHERE f.uoid >= 16384 AND p.pronamespace = 'pg_catalog'::regnamespace
       AND (p.proname = ANY (ARRAY['float4out', 'float8out', 'byteaout', 'time_out', 'timetz_out', 'box_out', 'circle_out',
              'line_out', 'lseg_out', 'path_out', 'point_out', 'poly_out'])
            OR p.prorettype = 'pg_catalog.xml'::regtype OR 'pg_catalog.xml'::regtype = ANY (p.proargtypes::oid[])
            -- R6F-P5: labelled IMMUTABLE, yet age() reads TimeZone and quote_ident() quote_all_identifiers.
            OR p.oid = ANY (ARRAY['pg_catalog.age(timestamptz,timestamptz)'::regprocedure, 'pg_catalog.quote_ident(text)'::regprocedure,
              -- R6F-P16: a zone NAME resolves through timezone_abbreviations, the tzdata files and, for 'localtime', the
              -- restore host's /etc/localtime, none of which a session setting pins.
              'pg_catalog.timezone(text,timestamptz)'::regprocedure, 'pg_catalog.timezone(text,timestamp)'::regprocedure,
              'pg_catalog.date_trunc(text,timestamptz,text)'::regprocedure, 'pg_catalog.date_add(timestamptz,interval,text)'::regprocedure,
              'pg_catalog.date_subtract(timestamptz,interval,text)'::regprocedure]::oid[]))
    UNION ALL
    -- R6F-P2: an object id (regclass …) in an expression or as a column: the restoring cluster assigns it, so a check
    -- over it can hold in the drill's fresh cluster and fail in a real restore.
    SELECT e.what || ' uses an object-id or ACL type (its value is assigned by the restoring cluster)'
      FROM expr e
     WHERE e.uoid >= 16384 AND regt IS NOT NULL
       AND e.tree ~ (tf || '(' || regt || ')[ }]')
    UNION ALL
    SELECT format('column %s.%I', a.attrelid::regclass, a.attname) || ' has an object-id or ACL type ' || format_type(a.atttypid, NULL) || ' (its values are assigned by the restoring cluster)'
      FROM pg_attribute a JOIN pg_class cl ON cl.oid = a.attrelid
     WHERE cl.oid >= 16384 AND cl.relkind IN ('r', 'p') AND a.attnum > 0 AND NOT a.attisdropped
       AND regt IS NOT NULL AND a.atttypid::text ~ ('^(' || regt || ')$')
       AND NOT EXISTS (SELECT 1 FROM ext WHERE ext.classid = 'pg_class'::regclass AND ext.objid = cl.oid)
    UNION ALL
    -- R6F-P3: a domain constraint of the dump's (the archive validator refuses domains; this is the second layer).
    SELECT format('domain constraint %I', c.conname) || ' (a domain of the dump; not restored by the drill)'
      FROM pg_constraint c
     WHERE c.contype = 'c' AND c.conrelid = 0 AND c.contypid <> 0 AND c.oid >= 16384
       AND NOT EXISTS (SELECT 1 FROM ext WHERE ext.classid = 'pg_type'::regclass AND ext.objid = c.contypid)
  ) r;
  -- R6B-1: every table CHECK must be IMMUTABLE by PostgreSQL's own test, not only by the function ids it names: a CHECK
  -- that depends on the session or the clock ('now'::date, CURRENT_USER …) can hold in the drill and fail in a real
  -- restore. Each one is tried as an index expression on a temporary copy of its table (42P17 when not immutable);
  -- any other error refuses too, named as such. No function of the dump exists yet (P5-1), so this evaluates nothing
  -- from the backup. R6F-P4: each probe runs in a subtransaction that is always rolled back (the sentinel R6B00), so
  -- it keeps no lock: any number of CHECKs fits the lock table.
  FOR r IN SELECT c.conname, c.conrelid, cl.relname, pg_get_expr(c.conbin, c.conrelid) AS expr
             FROM pg_constraint c JOIN pg_class cl ON cl.oid = c.conrelid
            WHERE c.contype = 'c' AND c.conbin IS NOT NULL LOOP
    BEGIN
      EXECUTE format('CREATE TEMP TABLE %I (LIKE %s)', r.relname, r.conrelid::regclass);
      EXECUTE format('CREATE INDEX ON pg_temp.%I ((%s))', r.relname, r.expr);
      RAISE SQLSTATE 'R6B00';
    EXCEPTION
      WHEN SQLSTATE 'R6B00' THEN NULL;
      WHEN SQLSTATE '42P17' THEN
        problem := concat_ws('; ', problem, format('check constraint %I is not immutable (SQLSTATE 42P17)', r.conname));
      WHEN OTHERS THEN
        problem := concat_ws('; ', problem, format('check constraint %I could not be verified (SQLSTATE %s)', r.conname, SQLSTATE));
    END;
  END LOOP;
  IF problem IS NOT NULL THEN
    RAISE EXCEPTION 'restore load guard (finding A-1): % would run as the superuser during the replay; the replay stops here', problem;
  END IF;
END
\$${tag}\$;
SQL
}

# dms_restore_restricted_stream <staged copy> <key> [<load-guard tag>]
# Prints the replay stream: `\restrict <key>`, then (with a tag) the load guard
# function, then the staged dump. The dump's own envelope is removed, and only
# when it is complete: a first statement line `\restrict K` together with a last
# non-blank line `\unrestrict K`, with the same K. psql (16.15 or later, checked by the drill) then runs
# the whole dump in restricted mode: every backslash meta-command, at the start of
# a line or inside one, is refused, and with ON_ERROR_STOP the replay stops. COPY
# data and SQL are unaffected. A dump whose envelope is incomplete keeps its own
# `\restrict` line, which restricted mode refuses, so the replay fails closed.
# With a tag, `SELECT pg_temp.dms_restore_guard_<tag>();` goes before EVERY line
# that looks like a COPY statement or like pg_dump's one-line object header
# (`-- Name: …` / `-- Data for Name: …`, names cleaned of newlines by pg_dump),
# and once more at the end. So the guard runs before every object and every data
# load, whatever text the dump holds; a COPY line split by a newline in a table
# name still has its header before it. The call has no quote, dollar sign or
# comment marker: inside a literal, an identifier or a function body it is inert
# text. Inside COPY data it can break the load, or add one row to the disposable
# copy; it never runs there.
dms_restore_restricted_stream() {
  local staged="${1:-}" key="${2:-}" tag="${3:-}"
  [[ "$key" =~ ^[A-Za-z0-9]+$ ]] || { _dms_restore_err "the restricted-mode key is not alphanumeric"; return 1; }
  [ -f "$staged" ] && [ ! -L "$staged" ] || { _dms_restore_err "the staged copy is missing"; return 1; }
  printf '\\restrict %s\n' "$key"
  if [ -n "$tag" ]; then
    dms_restore_load_guard_sql "$tag" || return 1
  fi
  DMS_GUARD_TAG="$tag" awk '
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
      tag = ENVIRON["DMS_GUARD_TAG"]
    }
    FNR == drop_first || FNR == drop_last { next }
    tag != "" && (/^COPY .* FROM stdin;$/ || /^-- (Data for )?Name: /) { print "SELECT pg_temp.dms_restore_guard_" tag "();" }
    { print }
    END { if (tag != "") print "SELECT pg_temp.dms_restore_guard_" tag "();" }
  ' "$staged" "$staged"
}
