#!/usr/bin/env bash
#
# R6-B6 isolated restore drill (finding A-1) — the replay, INSIDE the sandbox unit.
#
# Started only by scripts/lib/restore-sandbox.sh (dms_sandbox_run), as the unit's main process, in the disposable VM
# (owner directive D1/D2). Everything here is disposable and, from the first byte of the backup on, untrusted: the
# cluster, its roles, this process and every file under /srv die with the unit.
#
# Only bash builtins and the pinned PostgreSQL 16 binaries run here; the unit's ExecPaths= allows nothing else.
#   stdin   the restricted replay stream (the caller verified the backup's trusted digest and built the stream)
#   fd 3    the result channel (the unit's stdout): `DRILL key=value` lines only; no PostgreSQL or psql process
#           inherits it (DR-10)
#   stdout/stderr  /srv/inner.log, which never leaves the unit (no error text, no row ever reaches the caller: CG-11)
# Environment (validated again here):
#   DRILL_ROLES                 role names to create NOLOGIN, from the authenticated role manifest (D3)
#   DRILL_LOCALE                initdb locale
#   DRILL_PG_BINDIR             the pinned PostgreSQL 16 binaries
#   DRILL_EXPECT_DYNAMIC_UID=1  system mode: the uid must be a DynamicUser uid (DR-11)
set -uo pipefail
umask 077
exec 3>&1 1>> /srv/inner.log 2>&1
PGBIN="${DRILL_PG_BINDIR:-/usr/lib/postgresql/16/bin}"
R=/srv
SU=drill_super
RR=drill_restore
DB=dms_restore_drill
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
  case "$r" in "$SU" | "$RR") fail roles_reserved 12 ;; esac
  ROLES+=("$r")
done
LOCALE="${DRILL_LOCALE:-C.UTF-8}"
[[ "$LOCALE" =~ ^[A-Za-z0-9_.@-]{1,32}$ ]] || fail locale_invalid 12

# --- Trusted setup (no backup byte has been read yet) -----------------------------------------------------------
"$PGBIN/initdb" -D "$R/data" -U "$SU" --auth=trust -E UTF8 --locale="$LOCALE" > "$R/initdb.log" 2>&1 3>&- || fail initdb 10
"$PGBIN/postgres" -D "$R/data" -k "$R/data" -c listen_addresses= -c unix_socket_permissions=0700 \
  -c fsync=off -c synchronous_commit=off -c full_page_writes=off -c jit=off -c autovacuum=off \
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
# DR-5: from here on nothing can log in as the bootstrap superuser (not `\c` from the dump, not a native foothold on
# the trust socket). The server is stopped by signal, which needs no login.
su_sql -d postgres -v r="$SU" <<< 'ALTER ROLE :"r" NOLOGIN;' > /dev/null || fail superuser_nologin 12
emit "bootstrap_superuser=nologin"
emit "roles_created=${#ROLES[@]}"

# --- Untrusted: the replay, as the NOSUPERUSER restore role (D4) -------------------------------------------------
"$PGBIN/psql" -X -q -v ON_ERROR_STOP=1 -v VERBOSITY=sqlstate -h "$R/data" -U "$RR" -d "$DB" -f - > /dev/null 2> "$R/replay.err" 3>&-
rc=$?
if [ "$rc" -ne 0 ]; then
  state="unknown"
  while IFS= read -r l; do
    [[ "$l" =~ ERROR:\ +([0-9A-Z]{5})$ ]] && state="${BASH_REMATCH[1]}"
    [[ "$l" =~ restricted ]] && state="restricted_meta"
  done < "$R/replay.err"
  emit "replay_exit=$rc"
  emit "replay_sqlstate=$state"
  fail replay 20
fi
emit "replay=ok"

# --- Verification, as the restore role, search_path pinned, names qualified --------------------------------------
vsql() { "$PGBIN/psql" -X -Atq -v ON_ERROR_STOP=1 -h "$R/data" -U "$RR" -d "$DB" -c "SET search_path = pg_catalog" -c "$1" 3>&-; }
num() { [[ "$1" =~ ^[0-9]{1,15}$ ]]; }
pub="n.oid OPERATOR(pg_catalog.=) c.relnamespace WHERE n.nspname OPERATOR(pg_catalog.=) 'public'"
tables="$(vsql "SELECT pg_catalog.count(*) FROM information_schema.tables WHERE table_schema OPERATOR(pg_catalog.=) 'public'")" || fail verify 30
mig="$(vsql "SELECT pg_catalog.count(*) FROM information_schema.tables WHERE table_schema OPERATOR(pg_catalog.=) 'public' AND table_name OPERATOR(pg_catalog.=) 'migrations'")" || fail verify 30
base="$(vsql "SELECT pg_catalog.count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON ${pub} AND c.relkind OPERATOR(pg_catalog.=) ANY (ARRAY['r','p']::pg_catalog.\"char\"[])")" || fail verify 30
rows="$(vsql "SELECT COALESCE(pg_catalog.sum(((pg_catalog.xpath('/row/c/text()', pg_catalog.query_to_xml(pg_catalog.format('SELECT pg_catalog.count(*) AS c FROM ONLY %I.%I', n.nspname, c.relname), false, true, '')))[1])::text::bigint), 0) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON ${pub} AND c.relkind OPERATOR(pg_catalog.=) 'r'")" || fail verify 30
trg="$(vsql "SELECT pg_catalog.count(*) FROM pg_catalog.pg_trigger t JOIN pg_catalog.pg_class c ON c.oid OPERATOR(pg_catalog.=) t.tgrelid JOIN pg_catalog.pg_namespace n ON ${pub} AND NOT t.tgisinternal")" || fail verify 30
con="$(vsql "SELECT pg_catalog.count(*) FROM pg_catalog.pg_constraint k JOIN pg_catalog.pg_namespace n ON n.oid OPERATOR(pg_catalog.=) k.connamespace WHERE n.nspname OPERATOR(pg_catalog.=) 'public'")" || fail verify 30
fns="$(vsql "SELECT pg_catalog.count(*) FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid OPERATOR(pg_catalog.=) p.pronamespace WHERE n.nspname OPERATOR(pg_catalog.=) 'public'")" || fail verify 30
idx="$(vsql "SELECT pg_catalog.count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON ${pub} AND c.relkind OPERATOR(pg_catalog.=) 'i'")" || fail verify 30
for v in "$tables" "$mig" "$base" "$rows" "$trg" "$con" "$fns" "$idx"; do num "$v" || fail verify_format 30; done
emit "table_count=$tables"
emit "migrations_table=$mig"
emit "base_table_count=$base"
emit "rows_total=$rows"
emit "fidelity_trg_con_fn_idx=${trg}:${con}:${fns}:${idx}"
stop_server
emit "result=GO"
exit 0
