#!/usr/bin/env bash
#
# R6-B6 isolated restore drill (finding A-1) — the replay, INSIDE the sandbox unit.
#
# Started only by scripts/lib/restore-sandbox.sh (dms_sandbox_run), as the unit's main process, in the disposable VM
# (owner directive D1/D2). Everything here is disposable and, from the first byte of the backup on, untrusted: the
# cluster, its roles, this process and every file under /srv die with the unit.
#
# Only bash builtins and the pinned PostgreSQL 16 binaries run here; the unit's ExecPaths= allows nothing else.
#   /opt/dmsdrill/archive.dump  the pg_dump 16 CUSTOM-format archive, bound read-only (PR-6: a structured format; the
#                               host validated its TOC and counted its rows per table before the VM booted)
#   /opt/dmsdrill/guard.sql     the load guard (#695, D5), bound read-only, run between pre-data and data
#   stdin   /dev/null
#   fd 3    the result channel (the unit's stdout): `DRILL key=value` lines only; no PostgreSQL or psql process
#           inherits it (DR-10)
#   stdout/stderr  /srv/inner.log, which never leaves the unit (no error text, no row ever reaches the caller: CG-11)
# Environment (validated again here):
#   DRILL_ROLES                 role names to create NOLOGIN, from the authenticated role manifest (D3)
#   DRILL_LOCALE                initdb locale
#   DRILL_PG_BINDIR             the pinned PostgreSQL 16 binaries
#   DRILL_EXPECT_DYNAMIC_UID=1  system mode: the uid must be a DynamicUser uid (DR-11)
#   DRILL_RUNTIME_ROLE          the manifest's runtime role: its object count after the replay decides P5 (PR-3)
#   DRILL_EXTENSIONS            the extension allowlist (PR-8), "none" when empty
#   DRILL_ENV_PINS              F3: production's restore-session settings from the signed Restore Environment
#                               Compatibility Contract ("name=value;…", all 15, or "none"), applied to every restore session
set -uo pipefail
umask 077
exec 3>&1 1>> /srv/inner.log 2>&1
PGBIN="${DRILL_PG_BINDIR:-/usr/lib/postgresql/16/bin}"
R=/srv
SU=drill_super
# R6B-1 (defence in depth): the restore role and the database are named afresh for every run, so no archive can
# branch on them (the bootstrap superuser is NOLOGIN before any byte is read, so its name does not matter).
RR="drill_restore_$(printf '%08x' "$SRANDOM")"
DB="dms_restore_drill_$(printf '%08x' "$SRANDOM")"
PGPID=""

emit() { printf 'DRILL %s\n' "$*" >&3; }
# The cluster is disposable: it always stops immediately (SIGQUIT). A fast shutdown hung on a full tmpfs (F-ISO-4).
stop_server() { if [ -n "$PGPID" ]; then kill -QUIT "$PGPID" 2> /dev/null; wait "$PGPID" 2> /dev/null; PGPID=""; fi; }
fail() { emit "stage=$1"; emit "result=FAIL"; stop_server; exit "$2"; }

[[ "$PGBIN" =~ ^/[A-Za-z0-9/._-]+$ ]] || fail config_pg_bindir 12

# --- Profile attestation, before any input is read (DR-3: the effective profile, not the requested one) ----------
declare -A MOPTS=() MFS=() MSUPER=()
while IFS= read -r line; do
  read -r _ _ _ _ mp opts _ <<< "${line%% - *}"
  read -r fstype _ super <<< "${line#* - }"
  MOPTS["$mp"]="$opts"
  MFS["$mp"]="$fstype"
  MSUPER["$mp"]="$super"
done < /proc/self/mountinfo
for mp in "${!MOPTS[@]}"; do
  if [ -d "$mp" ] && [ -w "$mp" ]; then
    # F-ISO-2: nothing writable may execute. DR-1: nothing writable may be host disk or an uncapped tmpfs.
    [[ ",${MOPTS[$mp]}," == *,noexec,* ]] || fail profile_exec_writable_mount 13
    case "${MFS[$mp]}" in
      tmpfs) [[ ",${MSUPER[$mp]}," == *,size=* ]] || fail profile_uncapped_tmpfs 13 ;;
      mqueue) : ;;
      *) fail profile_writable_persistent_fs 13 ;;
    esac
  fi
done
seen_seccomp=0
while IFS=$':\t ' read -r k v _; do
  case "$k" in
    Seccomp) [ "$v" = 2 ] || fail profile_no_seccomp 13; seen_seccomp=1 ;;
    NoNewPrivs) [ "$v" = 1 ] || fail profile_no_nnp 13 ;;
    CapEff) [ "$v" = 0000000000000000 ] || fail profile_capabilities 13 ;;
  esac
done < /proc/self/status
[ "$seen_seccomp" = 1 ] || fail profile_no_seccomp 13
shopt -s nullglob
nets=(/sys/class/net/*)
[ "${#nets[@]}" = 1 ] && [ "${nets[0]}" = /sys/class/net/lo ] || fail profile_network_interfaces 13
if [ "${DRILL_EXPECT_DYNAMIC_UID:-0}" = 1 ]; then
  [ "$UID" -ge 61184 ] && [ "$UID" -le 65519 ] || fail profile_not_dynamic_uid 13
fi
[ "$UID" != 0 ] || fail profile_root 13
emit "profile=attested"

# --- Inputs --------------------------------------------------------------------------------------------------------
ROLES=()
for r in ${DRILL_ROLES:-}; do
  [[ "$r" =~ ^[a-z_][a-z0-9_]{0,62}$ ]] || fail roles_invalid 12
  case "$r" in "$SU" | drill_restore*) fail roles_reserved 12 ;; esac
  ROLES+=("$r")
done
LOCALE="${DRILL_LOCALE:-C.UTF-8}"
RUNTIME="${DRILL_RUNTIME_ROLE:-}"
EXTS="${DRILL_EXTENSIONS:-none}"
[[ "$EXTS" =~ ^(none|[a-z_][a-z0-9_]{0,62}(\ [a-z_][a-z0-9_]{0,62}){0,15})$ ]] || fail extensions_invalid 12
ARCHIVE=/opt/dmsdrill/archive.dump
GUARD=/opt/dmsdrill/guard.sql
[ -f "$ARCHIVE" ] && [ -r "$ARCHIVE" ] && [ -f "$GUARD" ] && [ -r "$GUARD" ] || fail inputs_missing 12
[ -z "$RUNTIME" ] || [[ "$RUNTIME" =~ ^[a-z_][a-z0-9_]{0,62}$ ]] || fail runtime_role_invalid 12
[[ "$LOCALE" =~ ^[A-Za-z0-9_.@-]{1,32}$ ]] || fail locale_invalid 12
# F3: the pinned restore-session settings: each of the sixteen names exactly once, values of a safe alphabet. They
# become libpq options of every restore session (spaces escaped), exactly as production's restore session has them.
PIN_NAMES="TimeZone DateStyle IntervalStyle array_nulls lc_monetary lc_numeric lc_time extra_float_digits bytea_output xmlbinary xmloption standard_conforming_strings quote_all_identifiers default_text_search_config timezone_abbreviations transform_null_equals"
PINS="${DRILL_ENV_PINS:-none}"
PIN_OPTS=""
if [ "$PINS" != none ]; then
  [[ "$PINS" =~ ^[A-Za-z_]{1,40}=[A-Za-z0-9_.,/@\ :+-]{1,64}(\;[A-Za-z_]{1,40}=[A-Za-z0-9_.,/@\ :+-]{1,64}){15}$ ]] || fail env_pins_invalid 12
  declare -A PSEEN=()
  rest="$PINS"
  while [ -n "$rest" ]; do
    item="${rest%%;*}"
    if [ "$item" = "$rest" ]; then rest=""; else rest="${rest#*;}"; fi
    pname="${item%%=*}"; pval="${item#*=}"
    [[ " ${PIN_NAMES} " == *" ${pname} "* ]] && [ -z "${PSEEN[$pname]:-}" ] || fail env_pins_invalid 12
    PSEEN[$pname]=1
    PIN_OPTS="${PIN_OPTS} -c ${pname}=${pval// /\\ }"
  done
  [ "${#PSEEN[@]}" = 16 ] || fail env_pins_invalid 12
fi

# F3: the restore-environment digest (what a pinned session observes), defined before any restore session.
TZV=unknown
if IFS= read -r tzl < /usr/share/zoneinfo/tzdata.zi 2> /dev/null && [[ "$tzl" =~ ^\#\ version\ ([0-9]{4}[a-z])$ ]]; then TZV="${BASH_REMATCH[1]}"; fi
PIN_SQL=""
for n in $PIN_NAMES; do PIN_SQL="${PIN_SQL}${PIN_SQL:+, }pg_catalog.concat('${n}=', pg_catalog.current_setting('${n}'))"; done
read -r -d '' ENV_EXPR <<SQL
pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(pg_catalog.concat(pg_catalog.concat_ws(E'\\n',
  pg_catalog.concat('pg_major=', pg_catalog.current_setting('server_version_num')::pg_catalog.int4 OPERATOR(pg_catalog./) 10000),
  pg_catalog.concat('server_encoding=', pg_catalog.current_setting('server_encoding')),
  pg_catalog.concat('locale_provider=', CASE d.datlocprovider WHEN 'c' THEN 'libc' WHEN 'i' THEN 'icu' ELSE 'other' END),
  pg_catalog.concat('datcollate=', d.datcollate), pg_catalog.concat('datctype=', d.datctype),
  pg_catalog.concat('collation_version=', COALESCE(d.datcollversion, 'none')),
  pg_catalog.concat('tzdata_version=', '${TZV}'),
  ${PIN_SQL}
), E'\\n'), 'UTF8')), 'hex')
SQL
ENV_FROM="FROM pg_catalog.pg_database d WHERE d.datname OPERATOR(pg_catalog.=) pg_catalog.current_database()"
ENV_SQL="SELECT ${ENV_EXPR} ${ENV_FROM}"
# R6F-E12 (F3 review): the same digest, measured at the end of EVERY restore session, so a setting changed inside a
# restore session is seen. R6F-E14 (fix verification): the marker carries a nonce drawn for that session after the
# archive is fixed, so the archive's own output can neither forge it nor (by leaving a COPY open that swallows it)
# hide a changed setting: a marker that is missing reads as "missing" and the drill reports "inconsistent".
SECTION_ENV=()
# --- Trusted setup (no backup byte has been read yet) -----------------------------------------------------------
"$PGBIN/initdb" -D "$R/data" -U "$SU" --auth=trust -E UTF8 --locale="$LOCALE" > "$R/initdb.log" 2>&1 3>&- || fail initdb 10
"$PGBIN/postgres" -D "$R/data" -k "$R/data" -c listen_addresses= -c unix_socket_permissions=0700 \
  -c fsync=off -c synchronous_commit=off -c full_page_writes=off -c jit=off -c autovacuum=off -c track_functions=all \
  -c max_connections=8 -c shared_buffers=64MB -c max_locks_per_transaction=1024 \
  -c logging_collector=off -c log_min_messages=warning > "$R/pg.log" 2>&1 3>&- &
PGPID=$!
exec {wait_fd}<> <(:)
up=0
for ((i = 0; i < 300; i++)); do
  if "$PGBIN/pg_isready" -q -h "$R/data" -U "$SU" 3>&-; then up=1; break; fi
  read -rt 0.1 -u "$wait_fd" || true
done
[ "$up" = 1 ] || fail server_start 11
su_sql() { "$PGBIN/psql" -X -Atq -v ON_ERROR_STOP=1 -h "$R/data" -U "$SU" "$@" 3>&-; }
# Names are validated above and quoted by psql's :"var" interpolation (stdin: -c does not interpolate).
for r in "${ROLES[@]}"; do
  su_sql -d postgres -v r="$r" <<< 'CREATE ROLE :"r" NOLOGIN;' > /dev/null || fail role_create 12
done
su_sql -d postgres -v r="$RR" <<< 'CREATE ROLE :"r" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;' > /dev/null || fail restore_role 12
for r in "${ROLES[@]}"; do
  su_sql -d postgres -v r="$r" -v m="$RR" <<< 'GRANT :"r" TO :"m" WITH INHERIT TRUE, SET TRUE;' > /dev/null || fail restore_role_grant 12
done
su_sql -d postgres -c "CREATE DATABASE ${DB} OWNER ${RR} TEMPLATE template0" > /dev/null || fail db_create 12
# ALTER … OWNER TO <role> needs the new owner to hold CREATE on the schema when a non-superuser runs it; the dump's own
# ACLs come only at its end. Recorded fidelity deviation: these grants stay in the disposable copy.
for r in "${ROLES[@]}"; do
  su_sql -d "$DB" -v r="$r" <<< 'GRANT CREATE ON SCHEMA public TO :"r";' > /dev/null || fail schema_grant 12
done
# P4P-1(b): the allowlisted extensions are created HERE, by the bootstrap superuser, before any backup byte is read.
# The archive's own EXTENSION entries and comments on extensions are left out of the restore (restore.list below), so
# the restore role never owns an extension: it cannot add its own functions to one (ALTER EXTENSION … ADD, which would
# launder them past every "not extension code" check) or run an extension update script.
if [ "$EXTS" != none ]; then
  for e in $EXTS; do
    su_sql -d "$DB" -v e="$e" <<< 'CREATE EXTENSION IF NOT EXISTS :"e" WITH SCHEMA public;' > /dev/null || fail extension_create 12
  done
fi
# DR-5: from here on nothing can log in as the bootstrap superuser (not `\c` from the dump, not a native foothold on
# the trust socket). The server is stopped by signal, which needs no login.
su_sql -d postgres -v r="$SU" <<< 'ALTER ROLE :"r" NOLOGIN;' > /dev/null || fail superuser_nologin 12
emit "bootstrap_superuser=nologin"
emit "roles_created=${#ROLES[@]}"

# --- Untrusted: the restore, section by section, as the NOSUPERUSER restore role (D4) ------------------------------
# pg_restore turns the structured archive into SQL; psql runs it in restricted mode under a fresh key per section
# (D5: no meta-command can run). The load guard runs between pre-data and data, as its own session: nothing is ever
# injected into the stream (PR-6).
# P5-1: the restore ORDER is the boundary. No function from the dump exists while the restore evaluates anything.
# The archive's FUNCTION entries (with their comments and ACLs), its TRIGGER entries and its remaining ACLs are left out
# of the schema, data and index phases and restored only at the end, when every table, row, index and constraint
# exists. A generated column, CHECK, DEFAULT, partition key, index expression or predicate, exclusion constraint or
# policy that names a dump function then fails with 42883 (undefined function), and the drill FAILs: no code from
# the backup can run as the restore role, so none can run and then erase its traces (review round 5). Creating a
# function or a trigger, or granting, evaluates nothing (pg_dump sets check_function_bodies = off).
# The EXTENSION entries and comments on extensions are dropped entirely (P4P-1(b), above).
"$PGBIN/pg_restore" -l "$ARCHIVE" > "$R/toc.full" 2> /dev/null 3>&- || fail toc_list 20
re_ext='^[0-9]+; [0-9]+ [0-9]+ EXTENSION - '
re_extc='^[0-9]+; [0-9]+ [0-9]+ COMMENT - EXTENSION '
re_fn='^[0-9]+; [0-9]+ [0-9]+ (FUNCTION [^ ]+ |(COMMENT|ACL) [^ ]+ FUNCTION )'
re_trg='^[0-9]+; [0-9]+ [0-9]+ (TRIGGER [^ ]+ |COMMENT [^ ]+ TRIGGER )'
re_acl='^[0-9]+; [0-9]+ [0-9]+ ACL '
: > "$R/list.schema"; : > "$R/list.functions"; : > "$R/list.triggers"; : > "$R/list.acls"
while IFS= read -r l; do
  if [[ "$l" =~ $re_ext ]]; then
    # PR-8 again, inside the unit: an extension outside the allowlist is never left for the restore to skip silently.
    e="${l#*EXTENSION - }"; e="${e%% *}"
    [[ " ${EXTS} " == *" ${e} "* ]] || fail extension_not_allowlisted 20
    continue
  fi
  if [[ "$l" =~ $re_extc ]]; then continue
  elif [[ "$l" =~ $re_fn ]]; then printf '%s\n' "$l" >> "$R/list.functions"
  elif [[ "$l" =~ $re_trg ]]; then printf '%s\n' "$l" >> "$R/list.triggers"
  elif [[ "$l" =~ $re_acl ]]; then printf '%s\n' "$l" >> "$R/list.acls"
  else printf '%s\n' "$l" >> "$R/list.schema"
  fi
done < "$R/toc.full"
restore_part() { # <label> <list file> [section] -> 0, or emits the failure and exits
  local label="$1" list="$2" section="${3:-}" key st prc
  local -a sect=()
  [ -n "$section" ] && sect=(--section="$section")
  key="$(printf '%08x%08x%08x%08x%08x%08x%08x%08x' "$SRANDOM" "$SRANDOM" "$SRANDOM" "$SRANDOM" "$SRANDOM" "$SRANDOM" "$SRANDOM" "$SRANDOM")"
  local nonce
  nonce="$(printf '%08x%08x%08x%08x' "$SRANDOM" "$SRANDOM" "$SRANDOM" "$SRANDOM")"
  # After the part, the session flushes its function-call statistics (track_functions=all, a superuser-only setting):
  # a defence-in-depth signal for verification, never the boundary (P5-2).
  { "$PGBIN/pg_restore" "${sect[@]}" -L "$list" --restrict-key="$key" -f - "$ARCHIVE" 2> "$R/pg_restore.err" 3>&-
    echo "$?" > "$R/pg_restore.rc"
    printf '%s\n' 'SELECT pg_catalog.pg_stat_force_next_flush();' 'SELECT 1;' "SELECT pg_catalog.concat('DMSENV:${nonce}:', ${ENV_EXPR}) ${ENV_FROM};"; } 3>&- \
    | PGOPTIONS="$PIN_OPTS" "$PGBIN/psql" -X -q -At -v ON_ERROR_STOP=1 -v VERBOSITY=sqlstate -h "$R/data" -U "$RR" -d "$DB" -f - > "$R/replay.out" 2> "$R/replay.err" 3>&-
  st=("${PIPESTATUS[@]}")
  prc="$(< "$R/pg_restore.rc")"
  st[0]="${prc:-1}"
  if [ "${st[1]}" -ne 0 ] || [ "${st[0]}" -ne 0 ]; then
    local state="unknown" l
    while IFS= read -r l; do
      [[ "$l" =~ ERROR:\ +([0-9A-Z]{5})$ ]] && state="${BASH_REMATCH[1]}"
      [[ "$l" =~ restricted ]] && state="restricted_meta"
    done < "$R/replay.err"
    [ "${st[0]}" -ne 0 ] && [ "$state" = unknown ] && state="pg_restore_failed"
    emit "replay_section=${label}"
    emit "replay_exit=${st[1]}"
    emit "replay_sqlstate=$state"
    fail replay 20
  fi
  # The environment this session ended in: the line with this session's nonce (no archive output can carry it).
  local env="missing" l
  while IFS= read -r l; do [[ "$l" =~ ^DMSENV:${nonce}:([0-9a-f]{64})$ ]] && env="${BASH_REMATCH[1]}"; done < "$R/replay.out"
  SECTION_ENV+=("$env")
  : > "$R/replay.out"
}
restore_part pre_data "$R/list.schema" pre-data
if ! PGOPTIONS="$PIN_OPTS" "$PGBIN/psql" -X -q -v ON_ERROR_STOP=1 -v VERBOSITY=sqlstate -h "$R/data" -U "$RR" -d "$DB" -f "$GUARD" > /dev/null 2> "$R/guard.err" 3>&-; then
  emit "replay_section=load_guard"
  fail load_guard 21
fi
restore_part data "$R/list.schema" data
restore_part post_data "$R/list.schema" post-data
# R6F-P8: the restored rows under DIFFERENT session settings, before any function of the dump exists (P5-1), so only
# builtins are evaluated. Every table CHECK must still hold, every stored generated column must equal its expression,
# and every expression or partial index must build and, if unique, stay unique, under each profile (time zones far
# apart and a half-hour daylight-saving one, every interval style,
# float digits from -15 to 3, both bytea and XML binary outputs, identifier quoting on and off). A schema whose
# result depends on the session can hold in the drill and fail in a real restore: such a drill FAILs. This is the
# safety net under the guard's list of known session-dependent builtins (age(), quote_ident() …), on the actual data.
# (Held in a variable: only the PostgreSQL binaries, bash, awk, head and wc may run in this unit.)
read -r -d '' DIFF_SQL <<'SQL'
DO $dmsdiff$
DECLARE
  r record; n bigint; total bigint := 0; keys text; nn text; q text[] := '{}'; i int;
  s_tz text := pg_catalog.current_setting('TimeZone'); s_is text := pg_catalog.current_setting('IntervalStyle');
  s_fd text := pg_catalog.current_setting('extra_float_digits'); s_bo text := pg_catalog.current_setting('bytea_output');
  s_xb text := pg_catalog.current_setting('xmlbinary'); s_qa text := pg_catalog.current_setting('quote_all_identifiers');
BEGIN
  -- R6F-P9: every query is written under FIXED settings (a constant printed with too few digits, or in another style,
  -- would be another constant), then run under this session's profile.
  PERFORM pg_catalog.set_config('TimeZone', 'UTC', true), pg_catalog.set_config('IntervalStyle', 'postgres', true),
    pg_catalog.set_config('extra_float_digits', '3', true), pg_catalog.set_config('bytea_output', 'hex', true),
    pg_catalog.set_config('xmlbinary', 'base64', true), pg_catalog.set_config('quote_all_identifiers', 'off', true);
  FOR r IN SELECT c.conrelid::pg_catalog.regclass AS rel, pg_catalog.pg_get_expr(c.conbin, c.conrelid) AS ex
             FROM pg_catalog.pg_constraint c
            WHERE c.contype OPERATOR(pg_catalog.=) 'c' AND c.conrelid OPERATOR(pg_catalog.<>) 0 AND c.oid OPERATOR(pg_catalog.>=) 16384 LOOP
    q := q OPERATOR(pg_catalog.||) pg_catalog.format('SELECT pg_catalog.count(*) FROM ONLY %s WHERE NOT (%s)', r.rel, r.ex);
  END LOOP;
  -- R6F-P10: a generated column equals its expression cast to the column's own type, compared by binary image.
  FOR r IN SELECT a.attrelid::pg_catalog.regclass AS rel, pg_catalog.quote_ident(a.attname::pg_catalog.text) AS col,
                  pg_catalog.pg_get_expr(d.adbin, d.adrelid) AS ex, pg_catalog.format_type(a.atttypid, a.atttypmod) AS ty,
                  a.atttypid AS tid, a.atttypmod AS tmod
             FROM pg_catalog.pg_attrdef d JOIN pg_catalog.pg_attribute a ON a.attrelid OPERATOR(pg_catalog.=) d.adrelid AND a.attnum OPERATOR(pg_catalog.=) d.adnum
            WHERE a.attgenerated OPERATOR(pg_catalog.<>) '' AND d.oid OPERATOR(pg_catalog.>=) 16384 LOOP
    q := q OPERATOR(pg_catalog.||) pg_catalog.format('SELECT pg_catalog.count(*) FROM ONLY %s WHERE NOT pg_catalog.record_image_eq(ROW(%s), ROW(CAST((%s) AS %s)))', r.rel, r.col, r.ex, r.ty);
    -- R6F-P17: COPY assigns (and raises on a value too long); an explicit cast silently truncates. For a length-limited
    -- character column, the expression's value must fit.
    IF r.tid OPERATOR(pg_catalog.=) ANY (ARRAY['pg_catalog.varchar'::pg_catalog.regtype, 'pg_catalog.bpchar'::pg_catalog.regtype]::pg_catalog.oid[]) AND r.tmod OPERATOR(pg_catalog.>) 4 THEN
      q := q OPERATOR(pg_catalog.||) pg_catalog.format('SELECT pg_catalog.count(*) FROM ONLY %s WHERE pg_catalog.char_length(pg_catalog.rtrim(CAST((%s) AS pg_catalog.text))) OPERATOR(pg_catalog.>) %s', r.rel, r.ex, r.tmod OPERATOR(pg_catalog.-) 4);
    END IF;
    -- R6F-P18 (fix verification): the same for every element of a length-limited character array, and for the bit
    -- length of bit(n) (exactly n) and varbit(n) (at most n), and of every element of their arrays: these are the only
    -- types whose explicit cast silently differs from the assignment COPY applies.
    IF r.tid OPERATOR(pg_catalog.=) ANY (ARRAY['pg_catalog._varchar'::pg_catalog.regtype, 'pg_catalog._bpchar'::pg_catalog.regtype]::pg_catalog.oid[]) AND r.tmod OPERATOR(pg_catalog.>) 4 THEN
      q := q OPERATOR(pg_catalog.||) pg_catalog.format('SELECT pg_catalog.count(*) FROM ONLY %s WHERE EXISTS (SELECT 1 FROM pg_catalog.unnest(CAST((%s) AS pg_catalog.text[])) AS u(v) WHERE pg_catalog.char_length(pg_catalog.rtrim(u.v)) OPERATOR(pg_catalog.>) %s)', r.rel, r.ex, r.tmod OPERATOR(pg_catalog.-) 4);
    END IF;
    IF r.tid OPERATOR(pg_catalog.=) ANY (ARRAY['pg_catalog.bit'::pg_catalog.regtype, 'pg_catalog.varbit'::pg_catalog.regtype]::pg_catalog.oid[]) AND r.tmod OPERATOR(pg_catalog.>) 0 THEN
      q := q OPERATOR(pg_catalog.||) pg_catalog.format('SELECT pg_catalog.count(*) FROM ONLY %s WHERE pg_catalog.bit_length(CAST((%s) AS pg_catalog.varbit)) OPERATOR(pg_catalog.%s) %s', r.rel, r.ex,
        CASE WHEN r.tid OPERATOR(pg_catalog.=) 'pg_catalog.bit'::pg_catalog.regtype THEN '<>' ELSE '>' END, r.tmod);
    END IF;
    IF r.tid OPERATOR(pg_catalog.=) ANY (ARRAY['pg_catalog._bit'::pg_catalog.regtype, 'pg_catalog._varbit'::pg_catalog.regtype]::pg_catalog.oid[]) AND r.tmod OPERATOR(pg_catalog.>) 0 THEN
      q := q OPERATOR(pg_catalog.||) pg_catalog.format('SELECT pg_catalog.count(*) FROM ONLY %s WHERE EXISTS (SELECT 1 FROM pg_catalog.unnest(CAST((%s) AS pg_catalog.varbit[])) AS u(v) WHERE pg_catalog.bit_length(u.v) OPERATOR(pg_catalog.%s) %s)', r.rel, r.ex,
        CASE WHEN r.tid OPERATOR(pg_catalog.=) 'pg_catalog._bit'::pg_catalog.regtype THEN '<>' ELSE '>' END, r.tmod);
    END IF;
  END LOOP;
  -- R6F-P11: every index with an expression or a predicate: its keys are evaluated (an error in a real build shows
  -- here), and a unique one stays unique (NULL keys distinct unless NULLS NOT DISTINCT).
  FOR r IN SELECT i.indrelid::pg_catalog.regclass AS rel, i.indexrelid AS idx, i.indnkeyatts AS k, i.indisunique AS u,
                  i.indnullsnotdistinct AS nnd, pg_catalog.pg_get_expr(i.indpred, i.indrelid) AS pr
             FROM pg_catalog.pg_index i
            WHERE (i.indexprs IS NOT NULL OR i.indpred IS NOT NULL) AND i.indexrelid OPERATOR(pg_catalog.>=) 16384 LOOP
    SELECT pg_catalog.string_agg('(' OPERATOR(pg_catalog.||) pg_catalog.pg_get_indexdef(r.idx, g, true) OPERATOR(pg_catalog.||) ')', ', ' ORDER BY g),
           pg_catalog.string_agg('(' OPERATOR(pg_catalog.||) pg_catalog.pg_get_indexdef(r.idx, g, true) OPERATOR(pg_catalog.||) ') IS NOT NULL', ' AND ' ORDER BY g)
      INTO keys, nn FROM pg_catalog.generate_series(1, r.k::pg_catalog.int4) AS g;
    -- R6F-P15: the keys only of the rows the predicate admits, as the index build does (a CASE keeps that order; two
    -- AND-ed conditions may be evaluated cheapest first).
    q := q OPERATOR(pg_catalog.||) pg_catalog.format('SELECT pg_catalog.count(*) OPERATOR(pg_catalog.*) 0 FROM ONLY %s WHERE CASE WHEN %s THEN pg_catalog.num_nulls(%s) OPERATOR(pg_catalog.>=) 0 END',
      r.rel, COALESCE('(' OPERATOR(pg_catalog.||) r.pr OPERATOR(pg_catalog.||) ')', 'true'), keys);
    IF r.u THEN
      q := q OPERATOR(pg_catalog.||) pg_catalog.format('SELECT pg_catalog.count(*) FROM (SELECT 1 FROM ONLY %s WHERE CASE WHEN %s THEN %s END GROUP BY %s HAVING pg_catalog.count(*) OPERATOR(pg_catalog.>) 1) AS x',
        r.rel, COALESCE('(' OPERATOR(pg_catalog.||) r.pr OPERATOR(pg_catalog.||) ')', 'true'), CASE WHEN r.nnd THEN 'true' ELSE nn END, keys);
    END IF;
  END LOOP;
  -- R6F-P11: an exclusion constraint over an expression or with a predicate is not re-checked here, so it is refused.
  SELECT pg_catalog.count(*) INTO n FROM pg_catalog.pg_constraint c JOIN pg_catalog.pg_index i ON i.indexrelid OPERATOR(pg_catalog.=) c.conindid
   WHERE c.contype OPERATOR(pg_catalog.=) 'x' AND c.oid OPERATOR(pg_catalog.>=) 16384 AND (i.indexprs IS NOT NULL OR i.indpred IS NOT NULL);
  total := total OPERATOR(pg_catalog.+) n;
  PERFORM pg_catalog.set_config('TimeZone', s_tz, true), pg_catalog.set_config('IntervalStyle', s_is, true),
    pg_catalog.set_config('extra_float_digits', s_fd, true), pg_catalog.set_config('bytea_output', s_bo, true),
    pg_catalog.set_config('xmlbinary', s_xb, true), pg_catalog.set_config('quote_all_identifiers', s_qa, true);
  -- R6F-P12: every row is read. No index (a partial index built in the drill holds only the rows that matched there,
  -- and a planted "shadow" index can answer a query with nothing) and no constraint exclusion may shortcut a query.
  PERFORM pg_catalog.set_config('enable_indexscan', 'off', true), pg_catalog.set_config('enable_bitmapscan', 'off', true),
    pg_catalog.set_config('enable_indexonlyscan', 'off', true), pg_catalog.set_config('constraint_exclusion', 'off', true),
    pg_catalog.set_config('enable_seqscan', 'on', true);
  FOR i IN 1 .. COALESCE(pg_catalog.array_length(q, 1), 0) LOOP
    EXECUTE q[i] INTO n;
    total := total OPERATOR(pg_catalog.+) n;
  END LOOP;
  IF total OPERATOR(pg_catalog.>) 0 THEN
    RAISE EXCEPTION USING ERRCODE = 'R6B22', MESSAGE = 'environment-dependent schema';
  END IF;
END
$dmsdiff$;
SQL
[ -n "$DIFF_SQL" ] || fail differential 30
for profile in \
  "TimeZone=UTC IntervalStyle=postgres extra_float_digits=1 bytea_output=hex xmlbinary=base64 quote_all_identifiers=off" \
  "TimeZone=Asia/Makassar IntervalStyle=iso_8601 extra_float_digits=0 bytea_output=escape xmlbinary=hex quote_all_identifiers=on" \
  "TimeZone=Etc/GMT+12 IntervalStyle=sql_standard extra_float_digits=-15 bytea_output=hex xmlbinary=hex quote_all_identifiers=off" \
  "TimeZone=Pacific/Kiritimati IntervalStyle=postgres_verbose extra_float_digits=3 bytea_output=escape xmlbinary=base64 quote_all_identifiers=on" \
  "TimeZone=Australia/Lord_Howe IntervalStyle=postgres extra_float_digits=1 bytea_output=hex xmlbinary=base64 quote_all_identifiers=off"; do
  opts="-c search_path=pg_catalog -c row_security=off -c default_transaction_read_only=on -c local_preload_libraries= -c statement_timeout=0"
  for kv in $profile; do opts="${opts} -c ${kv}"; done
  if ! PGOPTIONS="$opts" "$PGBIN/psql" -X -q -v ON_ERROR_STOP=1 -v VERBOSITY=sqlstate -h "$R/data" -U "$RR" -d "$DB" -c "$DIFF_SQL" > /dev/null 2> "$R/differential.err" 3>&-; then
    emit "replay_section=post_data_differential"
    state="unknown"
    while IFS= read -r l; do [[ "$l" =~ ERROR:\ +([0-9A-Z]{5})$ ]] && { state="${BASH_REMATCH[1]}"; break; }; done < "$R/differential.err"
    emit "replay_sqlstate=$state"
    fail environment_dependent 22
  fi
done
# F3: what a pinned restore session actually observes, as one digest: the PostgreSQL major, the database's encoding,
# locale provider, collate, ctype and collation version, the tzdata version PostgreSQL reads (system tzdata), and the
# effective value of every pinned setting. The host compares it with the digest it computed from the signed contract
# (scripts/lib/restore-envcontract.sh, dms_env_expected_digest); this is the guest's assertion, never production's.
envd="$(PGOPTIONS="$PIN_OPTS -c search_path=pg_catalog -c default_transaction_read_only=on" "$PGBIN/psql" -X -Atq -v ON_ERROR_STOP=1 -h "$R/data" -U "$RR" -d "$DB" -c "$ENV_SQL" 2> /dev/null 3>&-)" || fail env_digest 30
[[ "$envd" =~ ^[0-9a-f]{64}$ ]] || fail env_digest 30
restore_part functions "$R/list.functions"
restore_part triggers "$R/list.triggers"
restore_part acls "$R/list.acls"
# All six restore sessions (pre-data, data, post-data, functions, triggers, ACLs) must have ended in the fresh
# session's environment (R6F-E14: the last three were measured but not compared).
for e in "${SECTION_ENV[@]}"; do [ "$e" = "$envd" ] || envd=inconsistent; done
[ "${#SECTION_ENV[@]}" = 6 ] || envd=inconsistent
emit "env_digest=${envd}"
emit "replay=ok"

# --- Verification, as the restore role, search_path pinned, names qualified --------------------------------------
# PR-5, PR-8: the connection options override every per-role and per-database setting the dump may have planted
# (session_preload_libraries needs a superuser to set, so the restore role cannot plant it either); the
# session is read-only; row_security=off makes a query that an RLS policy would touch fail instead of running the
# policy's code.
vsql() {
  PGOPTIONS="-c search_path=pg_catalog -c row_security=off -c default_transaction_read_only=on -c local_preload_libraries= -c statement_timeout=0 -c lock_timeout=0" \
    "$PGBIN/psql" -X -Atq -v ON_ERROR_STOP=1 -h "$R/data" -U "$RR" -d "$DB" -c "$1" 3>&-
}
num() { [[ "$1" =~ ^[0-9]{1,15}$ ]]; }
pub="n.oid OPERATOR(pg_catalog.=) c.relnamespace WHERE n.nspname OPERATOR(pg_catalog.=) 'public'"
# R6F-P1, R6F-P2: index expressions and predicates exist only after post-data, so the load guard never saw them. Like
# the guard for CHECKs: no XML expression, no text-form conversion, no domain coercion, no session-dependent output or
# XML function and no object-id type in any expression of the dump's own objects; such an index (or constraint) can
# build in the drill and fail in a real restore, so the drill FAILs.
regt="SELECT pg_catalog.string_agg(t.oid::pg_catalog.text, '|') AS r FROM pg_catalog.pg_type t WHERE t.typnamespace OPERATOR(pg_catalog.=) 'pg_catalog'::pg_catalog.regnamespace AND pg_catalog.ltrim(t.typname::pg_catalog.text, '_') OPERATOR(pg_catalog.=) ANY (ARRAY['regclass','regtype','regproc','regprocedure','regoper','regoperator','regrole','regnamespace','regcollation','regconfig','regdictionary','aclitem'])"
sens="SELECT pg_catalog.string_agg(t.oid::pg_catalog.text, '|') AS s FROM pg_catalog.pg_type t WHERE t.typnamespace OPERATOR(pg_catalog.=) 'pg_catalog'::pg_catalog.regnamespace AND pg_catalog.ltrim(t.typname::pg_catalog.text, '_') OPERATOR(pg_catalog.=) ANY (ARRAY['float4','float8','bytea','time','timetz','interval','timestamptz','timestamp','date','money','xml','point','lseg','path','box','polygon','line','circle','record'])"
tf=':(consttype|vartype|paramtype|funcresulttype|opresulttype|resulttype|array_typeid|element_typeid|casetype|coalescetype|minmaxtype|typeId|refcontainertype|refelemtype|refrestype|row_typeid|aggtype) '
etrees="SELECT i.indexprs::pg_catalog.text AS t FROM pg_catalog.pg_index i WHERE i.indexrelid OPERATOR(pg_catalog.>=) 16384 AND i.indexprs IS NOT NULL UNION ALL SELECT i.indpred::pg_catalog.text FROM pg_catalog.pg_index i WHERE i.indexrelid OPERATOR(pg_catalog.>=) 16384 AND i.indpred IS NOT NULL UNION ALL SELECT k.conbin::pg_catalog.text FROM pg_catalog.pg_constraint k WHERE k.oid OPERATOR(pg_catalog.>=) 16384 AND k.conbin IS NOT NULL"
efn="EXISTS (SELECT 1 FROM pg_catalog.regexp_matches(e.t, ':[a-z]*funcid ([0-9]+)', 'g') AS m JOIN pg_catalog.pg_proc p ON p.oid OPERATOR(pg_catalog.=) (m[1])::pg_catalog.oid WHERE p.proname OPERATOR(pg_catalog.=) ANY (ARRAY['float4out','float8out','byteaout','time_out','timetz_out','box_out','circle_out','line_out','lseg_out','path_out','point_out','poly_out']::pg_catalog.name[]) OR p.prorettype OPERATOR(pg_catalog.=) 'pg_catalog.xml'::pg_catalog.regtype OR 'pg_catalog.xml'::pg_catalog.regtype OPERATOR(pg_catalog.=) ANY ((p.proargtypes)::pg_catalog.oid[]) OR p.oid OPERATOR(pg_catalog.=) ANY (ARRAY['pg_catalog.age(timestamptz,timestamptz)'::pg_catalog.regprocedure, 'pg_catalog.quote_ident(text)'::pg_catalog.regprocedure, 'pg_catalog.timezone(text,timestamptz)'::pg_catalog.regprocedure, 'pg_catalog.timezone(text,timestamp)'::pg_catalog.regprocedure, 'pg_catalog.date_trunc(text,timestamptz,text)'::pg_catalog.regprocedure, 'pg_catalog.date_add(timestamptz,interval,text)'::pg_catalog.regprocedure, 'pg_catalog.date_subtract(timestamptz,interval,text)'::pg_catalog.regprocedure]::pg_catalog.oid[]))"
envdep="$(vsql "WITH g AS (${regt}), h AS (${sens}), e AS (${etrees}) SELECT pg_catalog.count(*) FROM e, g, h WHERE e.t OPERATOR(pg_catalog.~) '[{](XMLEXPR|COERCETODOMAIN|JSONCONSTRUCTOREXPR) ' OR (e.t OPERATOR(pg_catalog.~) '[{]COERCEVIAIO ' AND (e.t OPERATOR(pg_catalog.~) ('${tf}(' OPERATOR(pg_catalog.||) h.s OPERATOR(pg_catalog.||) ')[ }]') OR e.t OPERATOR(pg_catalog.~) ':varattno 0 ' OR e.t OPERATOR(pg_catalog.~) '[{]ROWEXPR ')) OR e.t OPERATOR(pg_catalog.~) ('${tf}(' OPERATOR(pg_catalog.||) g.r OPERATOR(pg_catalog.||) ')[ }]') OR ${efn}")" || fail verify 30
num "$envdep" || fail verify_format 30
if [ "$envdep" != 0 ]; then emit "replay_section=post_data_environment"; fail environment_dependent 22; fi
tables="$(vsql "SELECT pg_catalog.count(*) FROM information_schema.tables WHERE table_schema OPERATOR(pg_catalog.=) 'public'")" || fail verify 30
mig="$(vsql "SELECT pg_catalog.count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON ${pub} AND c.relname OPERATOR(pg_catalog.=) 'migrations' AND c.relkind OPERATOR(pg_catalog.=) 'r'")" || fail verify 30
base="$(vsql "SELECT pg_catalog.count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON ${pub} AND c.relkind OPERATOR(pg_catalog.=) ANY (ARRAY['r','p']::pg_catalog.\"char\"[])")" || fail verify 30
rows="$(vsql "SELECT COALESCE(pg_catalog.sum(((pg_catalog.xpath('/row/c/text()', pg_catalog.query_to_xml(pg_catalog.format('SELECT pg_catalog.count(*) AS c FROM ONLY %I.%I', n.nspname, c.relname), false, true, '')))[1])::text::bigint), 0) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON ${pub} AND c.relkind OPERATOR(pg_catalog.=) 'r'")" || fail verify 30
trg="$(vsql "SELECT pg_catalog.count(*) FROM pg_catalog.pg_trigger t JOIN pg_catalog.pg_class c ON c.oid OPERATOR(pg_catalog.=) t.tgrelid JOIN pg_catalog.pg_namespace n ON ${pub} AND NOT t.tgisinternal")" || fail verify 30
con="$(vsql "SELECT pg_catalog.count(*) FROM pg_catalog.pg_constraint k JOIN pg_catalog.pg_namespace n ON n.oid OPERATOR(pg_catalog.=) k.connamespace WHERE n.nspname OPERATOR(pg_catalog.=) 'public'")" || fail verify 30
fns="$(vsql "SELECT pg_catalog.count(*) FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid OPERATOR(pg_catalog.=) p.pronamespace WHERE n.nspname OPERATOR(pg_catalog.=) 'public'")" || fail verify 30
idx="$(vsql "SELECT pg_catalog.count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON ${pub} AND c.relkind OPERATOR(pg_catalog.=) 'i'")" || fail verify 30
# PR-2, P4P-1, P4V-1: user code (not extension code) on a restore path, decided from the DEPENDENCY graph and from the
# stored expressions: anything a constraint (CHECK, exclusion, foreign key), a generated column, an index (expression,
# predicate), a partition key, a rule or a domain references, that is a function or operator created by the dump and
# not a member of an extension, OR that no longer resolves (code that ran while the schema was created can drop what it
# was called from). Such code runs during a real restore, as the restoring role.
ext="SELECT d.classid, d.objid FROM pg_catalog.pg_depend d WHERE d.deptype OPERATOR(pg_catalog.=) 'e'"
deps="SELECT d.refclassid AS cls, d.refobjid AS id FROM pg_catalog.pg_depend d WHERE d.refclassid OPERATOR(pg_catalog.=) ANY (ARRAY['pg_catalog.pg_proc'::pg_catalog.regclass, 'pg_catalog.pg_operator'::pg_catalog.regclass]) AND d.deptype OPERATOR(pg_catalog.=) ANY (ARRAY['n','a']::pg_catalog.\"char\"[]) AND (d.classid OPERATOR(pg_catalog.=) ANY (ARRAY['pg_catalog.pg_constraint'::pg_catalog.regclass, 'pg_catalog.pg_class'::pg_catalog.regclass, 'pg_catalog.pg_rewrite'::pg_catalog.regclass, 'pg_catalog.pg_type'::pg_catalog.regclass]) OR (d.classid OPERATOR(pg_catalog.=) 'pg_catalog.pg_attrdef'::pg_catalog.regclass AND EXISTS (SELECT 1 FROM pg_catalog.pg_attrdef ad JOIN pg_catalog.pg_attribute a ON a.attrelid OPERATOR(pg_catalog.=) ad.adrelid AND a.attnum OPERATOR(pg_catalog.=) ad.adnum WHERE ad.oid OPERATOR(pg_catalog.=) d.objid AND a.attgenerated OPERATOR(pg_catalog.<>) '')))"
trees="SELECT indexprs::pg_catalog.text AS t FROM pg_catalog.pg_index WHERE indexprs IS NOT NULL UNION ALL SELECT indpred::pg_catalog.text FROM pg_catalog.pg_index WHERE indpred IS NOT NULL UNION ALL SELECT conbin::pg_catalog.text FROM pg_catalog.pg_constraint WHERE conbin IS NOT NULL UNION ALL SELECT ad.adbin::pg_catalog.text FROM pg_catalog.pg_attrdef ad JOIN pg_catalog.pg_attribute a ON a.attrelid OPERATOR(pg_catalog.=) ad.adrelid AND a.attnum OPERATOR(pg_catalog.=) ad.adnum WHERE a.attgenerated OPERATOR(pg_catalog.<>) '' UNION ALL SELECT partexprs::pg_catalog.text FROM pg_catalog.pg_partitioned_table WHERE partexprs IS NOT NULL UNION ALL SELECT r.ev_action::pg_catalog.text FROM pg_catalog.pg_rewrite r JOIN pg_catalog.pg_class c ON c.oid OPERATOR(pg_catalog.=) r.ev_class WHERE c.relkind OPERATOR(pg_catalog.=) 'm'"
refs="${deps} UNION ALL SELECT 'pg_catalog.pg_proc'::pg_catalog.regclass, (pg_catalog.regexp_matches(t, ':(?:[a-z]*funcid|aggfnoid|winfnoid) ([0-9]+)', 'g'))[1]::pg_catalog.oid FROM (${trees}) e UNION ALL SELECT 'pg_catalog.pg_operator'::pg_catalog.regclass, (pg_catalog.regexp_matches(t, ':opno ([0-9]+)', 'g'))[1]::pg_catalog.oid FROM (${trees}) e UNION ALL SELECT 'pg_catalog.pg_operator'::pg_catalog.regclass, pg_catalog.unnest(conexclop) FROM pg_catalog.pg_constraint WHERE conexclop IS NOT NULL UNION ALL SELECT 'pg_catalog.pg_operator'::pg_catalog.regclass, pg_catalog.unnest(conpfeqop) FROM pg_catalog.pg_constraint WHERE conpfeqop IS NOT NULL"
ucode="$(vsql "WITH ext AS (${ext}), refs AS (${refs}) SELECT pg_catalog.count(*) FROM refs WHERE refs.id OPERATOR(pg_catalog.<>) 0 AND ((refs.id OPERATOR(pg_catalog.>=) 16384 AND NOT EXISTS (SELECT 1 FROM ext WHERE ext.classid OPERATOR(pg_catalog.=) refs.cls AND ext.objid OPERATOR(pg_catalog.=) refs.id)) OR (refs.cls OPERATOR(pg_catalog.=) 'pg_catalog.pg_proc'::pg_catalog.regclass AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_proc p WHERE p.oid OPERATOR(pg_catalog.=) refs.id)) OR (refs.cls OPERATOR(pg_catalog.=) 'pg_catalog.pg_operator'::pg_catalog.regclass AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_operator o WHERE o.oid OPERATOR(pg_catalog.=) refs.id)))")" || fail verify 30
# P4P-1: user functions that RAN during the restore at all (track_functions=all; the restore role can neither turn it
# off nor reset the counters). Any call is a FAIL, whatever the catalog looks like afterwards.
ucalls="$(vsql "WITH ext AS (${ext}) SELECT pg_catalog.count(*) FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid OPERATOR(pg_catalog.=) p.pronamespace WHERE n.nspname OPERATOR(pg_catalog.<>) ALL (ARRAY['pg_catalog','information_schema']) AND n.nspname OPERATOR(pg_catalog.!~) '^pg_(toast|temp)' AND COALESCE(pg_catalog.pg_stat_get_function_calls(p.oid), 0) OPERATOR(pg_catalog.>) 0 AND NOT EXISTS (SELECT 1 FROM ext WHERE ext.classid OPERATOR(pg_catalog.=) 'pg_catalog.pg_proc'::pg_catalog.regclass AND ext.objid OPERATOR(pg_catalog.=) p.oid)")" || fail verify 30
# P4P-1: the catalog shape, in the order the host computes it from the TOC (functions:tables:indexes:constraints:
# fk_constraints:triggers:sequences); objects that belong to an extension are not the dump's and are not counted.
notext="NOT EXISTS (SELECT 1 FROM pg_catalog.pg_depend x WHERE x.deptype OPERATOR(pg_catalog.=) 'e' AND x.objid OPERATOR(pg_catalog.=)"
shape="$(vsql "SELECT pg_catalog.concat_ws(':', (SELECT pg_catalog.count(*) FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid OPERATOR(pg_catalog.=) p.pronamespace WHERE n.nspname OPERATOR(pg_catalog.=) 'public' AND p.prokind OPERATOR(pg_catalog.=) ANY (ARRAY['f','w']::pg_catalog.\"char\"[]) AND ${notext} p.oid AND x.classid OPERATOR(pg_catalog.=) 'pg_catalog.pg_proc'::pg_catalog.regclass)), (SELECT pg_catalog.count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON ${pub} AND c.relkind OPERATOR(pg_catalog.=) ANY (ARRAY['r','p']::pg_catalog.\"char\"[]) AND ${notext} c.oid AND x.classid OPERATOR(pg_catalog.=) 'pg_catalog.pg_class'::pg_catalog.regclass)), (SELECT pg_catalog.count(*) FROM pg_catalog.pg_index i JOIN pg_catalog.pg_class c ON c.oid OPERATOR(pg_catalog.=) i.indexrelid JOIN pg_catalog.pg_namespace n ON ${pub} AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_constraint k WHERE k.conindid OPERATOR(pg_catalog.=) i.indexrelid AND k.conrelid OPERATOR(pg_catalog.=) i.indrelid AND k.contype OPERATOR(pg_catalog.=) ANY (ARRAY['p','u','x']::pg_catalog.\"char\"[])) AND ${notext} i.indrelid AND x.classid OPERATOR(pg_catalog.=) 'pg_catalog.pg_class'::pg_catalog.regclass)), (SELECT pg_catalog.count(*) FROM pg_catalog.pg_constraint k JOIN pg_catalog.pg_class c ON c.oid OPERATOR(pg_catalog.=) k.conrelid JOIN pg_catalog.pg_namespace n ON ${pub} AND k.contype OPERATOR(pg_catalog.=) ANY (ARRAY['p','u','x']::pg_catalog.\"char\"[])), (SELECT pg_catalog.count(*) FROM pg_catalog.pg_constraint k JOIN pg_catalog.pg_class c ON c.oid OPERATOR(pg_catalog.=) k.conrelid JOIN pg_catalog.pg_namespace n ON ${pub} AND k.contype OPERATOR(pg_catalog.=) 'f'), (SELECT pg_catalog.count(*) FROM pg_catalog.pg_trigger t JOIN pg_catalog.pg_class c ON c.oid OPERATOR(pg_catalog.=) t.tgrelid JOIN pg_catalog.pg_namespace n ON ${pub} AND NOT t.tgisinternal), (SELECT pg_catalog.count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON ${pub} AND c.relkind OPERATOR(pg_catalog.=) 'S' AND ${notext} c.oid AND x.classid OPERATOR(pg_catalog.=) 'pg_catalog.pg_class'::pg_catalog.regclass)))")" || fail verify 30
[[ "$shape" =~ ^[0-9]{1,9}(:[0-9]{1,9}){6}$ ]] || fail verify_format 30
# PR-3: P5 is decided from the catalog after the replay, not from a text scan of the dump.
rown=0
if [ -n "$RUNTIME" ]; then
  rown="$(vsql "SELECT (SELECT pg_catalog.count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid OPERATOR(pg_catalog.=) c.relnamespace WHERE pg_catalog.pg_get_userbyid(c.relowner) OPERATOR(pg_catalog.=) '${RUNTIME}' AND n.nspname OPERATOR(pg_catalog.<>) ALL (ARRAY['pg_catalog','information_schema'])) OPERATOR(pg_catalog.+) (SELECT pg_catalog.count(*) FROM pg_catalog.pg_proc p WHERE pg_catalog.pg_get_userbyid(p.proowner) OPERATOR(pg_catalog.=) '${RUNTIME}') OPERATOR(pg_catalog.+) (SELECT pg_catalog.count(*) FROM pg_catalog.pg_type t WHERE pg_catalog.pg_get_userbyid(t.typowner) OPERATOR(pg_catalog.=) '${RUNTIME}') OPERATOR(pg_catalog.+) (SELECT pg_catalog.count(*) FROM pg_catalog.pg_namespace s WHERE pg_catalog.pg_get_userbyid(s.nspowner) OPERATOR(pg_catalog.=) '${RUNTIME}')")" || fail verify 30
fi
# PR-5: one canonical digest over EVERY table's row count ("schema<TAB>table<TAB>rows\n", C order); the host compares
# it with the digest of the counts it took from the archive's data section before the VM booted.
userrel="n.oid OPERATOR(pg_catalog.=) c.relnamespace WHERE c.relkind OPERATOR(pg_catalog.=) 'r' AND n.nspname OPERATOR(pg_catalog.<>) ALL (ARRAY['pg_catalog','information_schema']) AND n.nspname OPERATOR(pg_catalog.!~) '^pg_(toast|temp)'"
tdigest="$(vsql "SELECT pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(COALESCE(pg_catalog.string_agg(n.nspname OPERATOR(pg_catalog.||) E'\\t' OPERATOR(pg_catalog.||) c.relname OPERATOR(pg_catalog.||) E'\\t' OPERATOR(pg_catalog.||) ((pg_catalog.xpath('/row/c/text()', pg_catalog.query_to_xml(pg_catalog.format('SELECT pg_catalog.count(*) AS c FROM ONLY %I.%I', n.nspname, c.relname), false, true, '')))[1])::text OPERATOR(pg_catalog.||) E'\\n', '' ORDER BY n.nspname COLLATE \"C\", c.relname COLLATE \"C\"), ''), 'UTF8')), 'hex') FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON ${userrel}")" || fail verify 30
[[ "$tdigest" =~ ^[0-9a-f]{64}$ ]] || fail verify_format 30
# PR-8: no extension beyond plpgsql and the allowlist exists after the restore.
extbad="$(vsql "SELECT pg_catalog.count(*) FROM pg_catalog.pg_extension WHERE extname OPERATOR(pg_catalog.<>) 'plpgsql' AND extname OPERATOR(pg_catalog.<>) ALL (pg_catalog.string_to_array('${EXTS}', ' '))")" || fail verify 30
for v in "$tables" "$mig" "$base" "$rows" "$trg" "$con" "$fns" "$idx" "$ucode" "$ucalls" "$rown" "$extbad"; do num "$v" || fail verify_format 30; done
emit "table_count=$tables"
emit "migrations_table=$mig"
emit "base_table_count=$base"
emit "rows_total=$rows"
emit "fidelity_trg_con_fn_idx=${trg}:${con}:${fns}:${idx}"
emit "user_code_reachable=$ucode"
emit "user_code_executed=$ucalls"
emit "catalog_shape=$shape"
emit "table_digest=$tdigest"
emit "extensions_outside_allowlist=$extbad"
emit "runtime_owned_objects=$rown"
stop_server
emit "result=GO"
exit 0
