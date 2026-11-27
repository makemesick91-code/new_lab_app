# shellcheck shell=bash
#
# DMS-SPN4-READY-PILOT-1 R6-B4 (rule 245; Design Lock §13.4 row R6-B4; OD-R6-1;
# F1-B) — the deploy's migrate step behind the host switch for the migrator.
#
# Sourced by scripts/deploy-vps.sh and scripts/rollback-vps.sh after
# scripts/lib/runtime-privsep.sh. Root runs it, from the application directory.
#
# The switch is a root-owned host file (/etc/dms-r6/migrator.conf, fixed here and
# never taken from the environment or the environment file). It is opened once
# and read, never sourced: only the keys below, each once, only plain values.
#
#   absent, or DMS_R6_MIGRATOR=0
#       today's behaviour, unchanged: the caller's runtime migrate (the deploy
#       passes a function that runs `php artisan migrate --force` as the
#       runtime user).
#   DMS_R6_MIGRATOR=1   (only after R6-D has split the roles)
#       1. the migration OS account is checked (its own uid and primary group,
#          no supplementary group, a nologin shell; not a shared, system, build
#          or admin account; local accounts only), and so is the server socket;
#       2. the account must not be able to read the environment file, the
#          cached configuration or a private path, nor write the runtime trees,
#          and no process, cron, at or linger entry of it may exist;
#       3. both sessions must reach the same database of the same running
#          cluster instance;
#       4. `php artisan migrate --database=pgsql_migrate --force` and
#          `php artisan foundation:db-privilege-apply --apply` run as that
#          account with an EMPTY environment plus an explicit allowlist, cleared
#          BEFORE the uid switch (root's environment holds every key of the
#          environment file): the `array` cache store, and every Laravel cache
#          path redirected to a fresh 0700 directory the account owns;
#       5. as the RUNTIME user: no migration may be pending, and
#          `php artisan foundation:db-privilege-check` must be MATCH (exit 0).
#
# dms_r6_preflight runs the switch, account, socket, migration-connection and
# database-state checks before the deploy's first mutation, without
# application code (OD-R6-2 v5 A4).
#
# Every refusal names the problem and never a configured value.

# Fixed values are FUNCTIONS: neither the environment nor the environment file
# (which the deploy sources later with `set -a`) can override them (rule 233;
# R6-B4 review round 2, N7). A test redefines the function.
_dms_r6_switch_file() { printf '%s\n' /etc/dms-r6/migrator.conf; }
# Where the per-deploy private cache directory of the migration account is made (root-only).
_dms_r6_cache_root() { printf '%s\n' /run; }
# The identity that owns the switch file and the environment file: root.
_dms_r6_trusted_uid() { printf '%s\n' 0; }
# The account the PostgreSQL server runs as: it must own the migration socket.
_dms_r6_server_account() { printf '%s\n' postgres; }
_dms_r6_nsswitch_file() { printf '%s\n' /etc/nsswitch.conf; }
# The ONE directory the PostgreSQL client is run from, by absolute path, never through PATH (rule 235).
_dms_r6_pg_bindir() { printf '%s\n' /usr/lib/postgresql/16/bin; }
_dms_r6_spool_dirs() { printf '%s\n' /var/spool/cron/crontabs /var/spool/cron/atjobs /var/lib/systemd/linger; }

# The keys a switch file may hold; any other key, or a value outside the
# plain-character class, refuses the whole file.
_dms_r6_keys() { printf '%s\n' "DMS_R6_MIGRATOR DMS_R6_MIGRATION_OS_USER DMS_MIGRATE_SOCKET_DIR DMS_MIGRATE_PORT DMS_MIGRATE_DATABASE DMS_MIGRATE_USERNAME DMS_MIGRATE_OWNER_ROLE DMS_PRIVILEGE_RUNTIME_ROLE"; }

# Accounts that are shared, privileged or belong to the system: never the migration account.
_dms_r6_shared_accounts() { printf '%s\n' "root daemon bin sys sync games man lp mail news uucp proxy www-data backup list irc gnats nobody postgres sshd syslog messagebus _apt systemd-network systemd-resolve systemd-timesync"; }

_dms_r6_err() { echo "r6-migrate: $*" >&2; }

# _dms_r6_trusted_ancestry <allowed owner uids (space list)> <directory>
#
# Every directory from <directory> up to / must be owned by one of the allowed
# uids and must not be writable by group or others, except a sticky directory
# (such as /tmp), where only an entry's owner can replace it.
_dms_r6_trusted_ancestry() {
  local allowed=" $1 " dir="$2" meta owner mode
  while :; do
    meta="$(stat -L -c '%u %a' -- "$dir" 2>/dev/null)" || return 1
    owner="${meta%% *}"
    mode="${meta##* }"
    [[ "$allowed" == *" ${owner} "* ]] || return 1
    if [ $(( 8#${mode} & 8#022 )) -ne 0 ] && [ $(( 8#${mode} & 8#1000 )) -eq 0 ]; then
      return 1
    fi
    [ "$dir" = / ] && return 0
    dir="$(dirname -- "$dir")"
  done
}

# _dms_r6_gids <account>: the account's group ids, numeric (primary and supplementary).
_dms_r6_gids() { id -G -- "$1" 2>/dev/null; }

# _dms_r6_local_accounts_only: the account checks enumerate local accounts; a
# name service with other sources (LDAP, SSSD, NIS…) could hold accounts they
# never see, so it is refused rather than passed (review round 2, N11).
_dms_r6_local_accounts_only() {
  local file
  file="$(_dms_r6_nsswitch_file)"
  [ -r "$file" ] || return 0
  if grep -Eq '^(passwd|group|initgroups):.*[[:space:]](ldap|sss|nis|nisplus|winbind|db|extrausers|hesiod)([[:space:]]|$)' "$file"; then
    _dms_r6_err "the name service has sources beyond local files: the migration account cannot be checked completely — NOT GO"
    return 1
  fi
}

# _dms_r6_account_check <migration account> <runtime user> <runtime group> <other forbidden accounts>
_dms_r6_account_check() {
  local mig="$1" runtime_user="$2" runtime_group="$3" forbidden="$4"
  local uid gid rt_uid rt_gid shell a other_uid
  _dms_r6_local_accounts_only || return 1
  uid="$(id -u -- "$mig" 2>/dev/null)" || { _dms_r6_err "the migration OS account does not exist — NOT GO"; return 1; }
  rt_uid="$(id -u -- "$runtime_user" 2>/dev/null)" || { _dms_r6_err "the runtime account does not exist — NOT GO"; return 1; }
  if [ "$uid" = 0 ] || [ "$mig" = "$runtime_user" ] || [ "$uid" = "$rt_uid" ]; then
    _dms_r6_err "the migration OS account must be its own unprivileged account — NOT GO"
    return 1
  fi
  for a in $(_dms_r6_shared_accounts) $forbidden; do
    [ "$mig" != "$a" ] || { _dms_r6_err "the migration OS account must not be a shared, system, build or admin account — NOT GO"; return 1; }
    other_uid="$(id -u -- "$a" 2>/dev/null)" || continue
    [ "$uid" != "$other_uid" ] || { _dms_r6_err "the migration OS account must not share a uid with a shared, system, build or admin account — NOT GO"; return 1; }
  done
  # No second name for the same uid.
  if [ -n "$(getent passwd | awk -F: -v u="$uid" -v me="$mig" '$3 == u && $1 != me { print $1 }')" ]; then
    _dms_r6_err "another account shares the migration OS account's uid — NOT GO"
    return 1
  fi
  shell="$(getent passwd "$mig" | cut -d: -f7)"
  case "$shell" in
    /usr/sbin/nologin|/sbin/nologin|/bin/false|/usr/bin/false) ;;
    *) _dms_r6_err "the migration OS account must have no login shell — NOT GO"; return 1 ;;
  esac
  # OD-R6-1 / F1-B: never in the runtime group, by primary or supplementary group, compared by number.
  rt_gid="$(getent group "$runtime_group" | cut -d: -f3)"
  [ -n "$rt_gid" ] || { _dms_r6_err "the runtime group does not exist — NOT GO"; return 1; }
  if [[ " $(_dms_r6_gids "$mig") " == *" ${rt_gid} "* ]]; then
    _dms_r6_err "the migration OS account must not be in the runtime group — NOT GO"
    return 1
  fi
  # No supplementary group at all (docker, adm, disk… would each widen what its code can reach; review round 2 N11).
  gid="$(id -g -- "$mig")"
  if [ "$(_dms_r6_gids "$mig")" != "$gid" ]; then
    _dms_r6_err "the migration OS account must have no supplementary group — NOT GO"
    return 1
  fi
  # A dedicated primary group: no other account has it as primary group, and it has no other member.
  if [ -n "$(getent passwd | awk -F: -v g="$gid" -v me="$mig" '$4 == g && $1 != me { print $1 }')" ] \
    || [ -n "$(getent group | awk -F: -v g="$gid" -v me="$mig" '$3 == g { n = split($4, m, ","); for (i = 1; i <= n; i++) if (m[i] != "" && m[i] != me) print m[i] }')" ]; then
    _dms_r6_err "the migration OS account must have a primary group of its own — NOT GO"
    return 1
  fi
  return 0
}

# dms_r6_switch_read <expected owner uid> <runtime user> <runtime group> [<other forbidden accounts>]
#
# Sets DMS_R6_MIGRATOR (0 or 1) and, for 1, the DMS_R6_*/DMS_MIGRATE_* values,
# after checking the migration account (_dms_r6_account_check).
# Returns 0 when the switch is absent or valid, 1 when it must not be trusted.
dms_r6_switch_read() {
  local owner_uid="${1:?owner uid}" runtime_user="${2:?runtime user}" runtime_group="${3:?runtime group}" forbidden="${4:-}"
  _dms_r6_switch_value_read "$owner_uid" || return 1
  [ "$DMS_R6_MIGRATOR" = 1 ] || return 0
  _dms_r6_account_check "$DMS_R6_MIGRATION_OS_USER" "$runtime_user" "$runtime_group" "$forbidden"
}

# _dms_r6_switch_value_read <expected owner uid>
#
# Only the file's trust and its values: the rollback guard needs no more.
_dms_r6_switch_value_read() {
  local owner_uid="${1:?owner uid}" file line key value meta fd f_uid f_mode keys
  local -a lines=()
  local -A seen=()
  file="$(_dms_r6_switch_file)"
  keys="$(_dms_r6_keys)"
  DMS_R6_MIGRATOR=0
  for key in $keys; do
    [ "$key" = DMS_R6_MIGRATOR ] || unset "$key"
  done

  if [ ! -e "$file" ] && [ ! -L "$file" ]; then
    return 0
  fi
  if [ -L "$file" ] || [ ! -f "$file" ]; then
    _dms_r6_err "the migrator switch file is not a regular file — NOT GO"
    return 1
  fi
  # Opened once: the checks and the read are on the same open file. The
  # directories above it are checked too (below), so it cannot have been swapped
  # before it was opened.
  exec {fd}<"$file" || { _dms_r6_err "the migrator switch file cannot be opened — NOT GO"; return 1; }
  meta="$(stat -L -c '%u %a %F' -- "/proc/${BASHPID}/fd/${fd}" 2>/dev/null)" || meta=""
  case "$meta" in
    *" regular file"|*" regular empty file") ;;
    *) exec {fd}<&-; _dms_r6_err "the migrator switch file is not a regular file — NOT GO"; return 1 ;;
  esac
  read -r f_uid f_mode _ <<<"$meta"
  if [ "$f_uid" != "$owner_uid" ] || [ $(( 8#${f_mode} & 8#037 )) -ne 0 ]; then
    exec {fd}<&-
    _dms_r6_err "the migrator switch file must be owned by the deploy identity and writable by nobody else (mode 0640 at most) — NOT GO"
    return 1
  fi
  if ! _dms_r6_trusted_ancestry "0 ${owner_uid}" "$(dirname -- "$file")"; then
    exec {fd}<&-
    _dms_r6_err "a directory above the migrator switch file can be written by another account — NOT GO"
    return 1
  fi
  mapfile -t -u "$fd" lines
  exec {fd}<&-

  for line in "${lines[@]}"; do
    case "$line" in ''|'#'*) continue ;; esac
    key="${line%%=*}"
    value="${line#*=}"
    if [ "$key" = "$line" ] || ! [[ "$key" =~ ^[A-Z0-9_]+$ ]] || [[ " ${keys} " != *" ${key} "* ]]; then
      _dms_r6_err "the migrator switch file holds an unknown or malformed line — NOT GO"
      return 1
    fi
    if [ -n "${seen[$key]:-}" ]; then
      _dms_r6_err "the migrator switch file sets ${key} twice — NOT GO"
      return 1
    fi
    seen[$key]=1
    case "$value" in
      *[!A-Za-z0-9_./-]*|'') _dms_r6_err "the migrator switch file holds a value outside the plain character class (${key}) — NOT GO"; return 1 ;;
    esac
    printf -v "$key" '%s' "$value" || { _dms_r6_err "the migrator switch file holds an unknown or malformed line — NOT GO"; return 1; }
  done

  case "$DMS_R6_MIGRATOR" in
    0) return 0 ;;
    1) ;;
    *) _dms_r6_err "DMS_R6_MIGRATOR must be 0 or 1 — NOT GO"; return 1 ;;
  esac

  for key in $keys; do
    if [ -z "${!key:-}" ]; then
      _dms_r6_err "the migrator switch is on but ${key} is missing — NOT GO"
      return 1
    fi
  done
  case "$DMS_MIGRATE_SOCKET_DIR" in /*) ;; *) _dms_r6_err "DMS_MIGRATE_SOCKET_DIR must be absolute — NOT GO"; return 1 ;; esac
  case "$DMS_MIGRATE_PORT" in *[!0-9]*) _dms_r6_err "DMS_MIGRATE_PORT must be numeric — NOT GO"; return 1 ;; esac
  return 0
}

# _dms_r6_socket_check <socket dir> <port> <migration account> <runtime user>
#
# The server socket the migration account connects to must be one nobody else
# can plant: the configured directory is its own canonical path (no symlink to
# repoint later; review round 2 N8), the socket is owned by the PostgreSQL
# server account (N9), the directory and every directory above it by root or
# that account and never other-writable, and a group-writable socket directory
# is accepted only when neither the migration account nor the runtime user is in
# its group.
_dms_r6_socket_check() {
  local dir="$1" port="$2" mig="$3" runtime_user="$4" sock server server_uid d_uid d_gid d_mode canonical
  canonical="$(readlink -f -- "$dir" 2>/dev/null)" || canonical=""
  if [ -z "$canonical" ] || [ "$canonical" != "$dir" ]; then
    _dms_r6_err "the migration socket directory must be given by its canonical path (no symlink) — NOT GO"
    return 1
  fi
  sock="${dir}/.s.PGSQL.${port}"
  if [ ! -d "$dir" ] || [ -L "$sock" ] || [ ! -S "$sock" ]; then
    _dms_r6_err "the migration socket is not a server socket — NOT GO"
    return 1
  fi
  server="$(stat -c '%u' -- "$sock")"
  server_uid="$(id -u -- "$(_dms_r6_server_account)" 2>/dev/null)" || server_uid=""
  if [ "$server" = "$(id -u -- "$mig" 2>/dev/null)" ] || [ "$server" = "$(id -u -- "$runtime_user" 2>/dev/null)" ]; then
    _dms_r6_err "the migration socket belongs to the migration account or the runtime user — NOT GO"
    return 1
  fi
  if [ -z "$server_uid" ] || [ "$server" != "$server_uid" ]; then
    _dms_r6_err "the migration socket is not owned by the PostgreSQL server account — NOT GO"
    return 1
  fi
  read -r d_uid d_gid d_mode <<<"$(stat -c '%u %g %a' -- "$dir")"
  if [ "$dir" != / ] && ! _dms_r6_trusted_ancestry "0 ${server}" "$(dirname -- "$dir")"; then
    _dms_r6_err "a directory above the migration socket can be written by another account — NOT GO"
    return 1
  fi
  if [[ " 0 ${server} " != *" ${d_uid} "* ]] || [ $(( 8#${d_mode} & 8#002 )) -ne 0 ]; then
    _dms_r6_err "the migration socket directory can be replaced or written by another account — NOT GO"
    return 1
  fi
  if [ $(( 8#${d_mode} & 8#020 )) -ne 0 ] && [[ " $(_dms_r6_gids "$mig") $(_dms_r6_gids "$runtime_user") " == *" ${d_gid} "* ]]; then
    _dms_r6_err "the migration socket directory is writable by a group of the migration account or the runtime user — NOT GO"
    return 1
  fi
  return 0
}

# _dms_r6_as_migrator <migration account> <command> [args...]
#
# Runs <command> as the migration account with an EMPTY environment plus
# _DMS_R6_ALLOW (NAME=value entries, set by the caller). Root's environment is
# cleared in the subshell BEFORE runuser switches the uid, so no value of root's
# environment (the deploy exports the environment file) ever reaches a process
# of that uid, and the allowlisted values travel in the environment, never on a
# command line. An entry the shell cannot unset (a name that is not an
# identifier) refuses the hop (review round 2, N6).
_dms_r6_as_migrator() {
  local mig="${1:?migration account}" runuser_bin env_bin
  shift
  runuser_bin="$(command -v runuser)" || { _dms_r6_err "runuser is missing — NOT GO"; return 2; }
  env_bin="$(command -v env)" || { _dms_r6_err "env is missing — NOT GO"; return 2; }
  (
    umask 027
    _dms_privsep_close_inherited_fds
    while read -r _ _ f; do export -fn "$f"; done < <(declare -Fx)
    for v in $(compgen -e); do unset "$v" 2>/dev/null; done
    [ -z "$(compgen -e)" ] || { _dms_r6_err "the environment could not be cleared — NOT GO"; exit 2; }
    export "${_DMS_R6_ALLOW[@]}"
    want="$(printf '%s\n' "${_DMS_R6_ALLOW[@]%%=*}" | LC_ALL=C sort -u)"
    got="$("$env_bin" | cut -d= -f1 | grep -vx '_' | LC_ALL=C sort -u)"
    [ "$want" = "$got" ] || { _dms_r6_err "the environment could not be cleared (an entry the shell cannot remove) — NOT GO"; exit 2; }
    exec "$runuser_bin" -u "$mig" -- "$@"
  ) </dev/null 2>&1 | cat
  return "${PIPESTATUS[0]}"
}

# _dms_r6_no_migrator_process <migration account>
#
# Nothing of the migration account may outlive a step: no process (killed, and
# refused, when one is found), no crontab, at job or linger entry (review
# round 2, N5).
_dms_r6_no_migrator_process() {
  local mig="$1" uid d
  uid="$(id -u -- "$mig" 2>/dev/null)" || { _dms_r6_err "the migration OS account does not exist — NOT GO"; return 1; }
  if pgrep -u "$uid" >/dev/null 2>&1; then
    pkill -KILL -u "$uid" >/dev/null 2>&1 || true
    _dms_r6_err "a process of the migration OS account was running — killed — NOT GO"
    return 1
  fi
  for d in $(_dms_r6_spool_dirs); do
    [ -d "$d" ] || continue
    if [ -e "${d}/${mig}" ] || [ -n "$(find "$d" -maxdepth 1 -user "$uid" -print -quit 2>/dev/null)" ]; then
      _dms_r6_err "the migration OS account has a cron, at or linger entry — NOT GO"
      return 1
    fi
  done
  return 0
}

# The split state, read-only. It mirrors
# App\Support\Database\DatabasePrivilegeCheck::splitState exactly (a test runs
# both on the same states): the login owns every table in public → 0, none →
# 1, a mix → unknown; with no table yet, split when the login neither owns
# public nor may create in it.
_dms_r6_split_sql() {
  cat <<'SQL'
with me as (select r.oid from pg_catalog.pg_roles r where r.rolname = session_user),
 t as (select c.relowner from pg_catalog.pg_class c join pg_catalog.pg_namespace n on n.oid = c.relnamespace
       where n.nspname = 'public' and c.relkind in ('r', 'p')),
 p as (select n.oid, n.nspowner from pg_catalog.pg_namespace n where n.nspname = 'public')
select case
  when (select count(*) from me) <> 1 then 'unknown'
  when (select count(*) from t) = 0 then
    case when (select count(*) from p) <> 1 then 'unknown'
         when (select p.nspowner = me.oid or pg_catalog.has_schema_privilege(me.oid, p.oid, 'CREATE') from p, me) then '0'
         else '1' end
  when (select count(*) from t, me where t.relowner = me.oid) = (select count(*) from t) then '0'
  when (select count(*) from t, me where t.relowner = me.oid) = 0 then '1'
  else 'unknown' end
SQL
}

# Which running cluster instance and database a session reaches, and as whom:
# system identifier | database | postmaster start (epoch) | current_user | session_user.
_dms_r6_identity_sql() {
  cat <<'SQL'
select s.system_identifier::text, pg_catalog.current_database()::text,
       extract(epoch from pg_catalog.pg_postmaster_start_time())::bigint::text,
       current_user::text, session_user::text
from pg_catalog.pg_control_system() s
SQL
}

# _dms_r6_psql: prints the pinned psql (a regular, executable, non-symlink file
# owned by root, not group- or other-writable, in a trusted directory chain), or
# fails. Rule 235: the client is never found through PATH.
_dms_r6_psql() {
  local dir bin meta trusted
  dir="$(_dms_r6_pg_bindir)"
  bin="${dir}/psql"
  trusted="0 $(_dms_r6_trusted_uid)"
  if [ -L "$dir" ] || [ ! -d "$dir" ] || [ -L "$bin" ] || [ ! -f "$bin" ] || [ ! -x "$bin" ]; then
    _dms_r6_err "the pinned PostgreSQL client is missing or not a regular executable file"
    return 1
  fi
  meta="$(stat -c '%u %a' -- "$bin")"
  if [[ " ${trusted} " != *" ${meta%% *} "* ]] || [ $(( 8#${meta##* } & 8#022 )) -ne 0 ] || ! _dms_r6_trusted_ancestry "$trusted" "$dir"; then
    _dms_r6_err "the pinned PostgreSQL client can be replaced by another account"
    return 1
  fi
  printf '%s\n' "$bin"
}

# _dms_r6_root_psql <sql>
#
# Root runs the pinned psql (a root-owned binary, as it runs pg_dump; no application code)
# in a read-only transaction with the runtime's database identity from the
# environment file. The environment file is read only AFTER the rule 233 trust
# check (root owns it, nobody else can write it; review round 2, N1); the
# connection values travel in the environment, never on a command line, and
# every other PG* variable — inherited or set by the environment file — is
# cleared first.
_dms_r6_root_psql() {
  local sql="$1" psql_bin
  psql_bin="$(_dms_r6_psql)" || return 3
  dms_privsep_assert_trusted_file "$(_dms_r6_trusted_uid)" .env >/dev/null || return 3
  (
    set +u
    for v in $(compgen -e); do case "$v" in PG*) unset "$v" ;; esac; done
    set -a
    # shellcheck disable=SC1091
    . ./.env
    set +a
    for v in $(compgen -e); do case "$v" in PG*) unset "$v" ;; esac; done
    export PGHOST="${DB_HOST:-127.0.0.1}" PGPORT="${DB_PORT:-5432}" PGUSER="${DB_USERNAME:-}" PGDATABASE="${DB_DATABASE:-}" \
      PGPASSWORD="${DB_PASSWORD:-}" PGCONNECT_TIMEOUT=10 PGOPTIONS='-c default_transaction_read_only=on -c search_path=pg_catalog'
    "$psql_bin" -X -A -t -q -v ON_ERROR_STOP=1 -c "$sql" 2>/dev/null
  )
}

# _dms_r6_split_probe_sql: prints split=0, split=1 or split=unknown (root, read-only, no application code).
_dms_r6_split_probe_sql() {
  local out rc=0
  out="$(_dms_r6_root_psql "$(_dms_r6_split_sql)")" || rc=$?
  case "$rc:$out" in
    0:0|0:1) printf 'split=%s\n' "$out" ;;
    *) _dms_r6_err "the split-state probe without application code gave no usable answer (exit ${rc})"; printf 'split=unknown\n' ;;
  esac
}

# _dms_r6_migration_connection_check <migration account>
#
# Before the first mutation (review round 2, N3): as the migration account,
# with psql and no application code, connect exactly as pgsql_migrate will
# (the configured socket, database and login, SET ROLE to the owner role at
# connection start, read-only), and require the same cluster instance and
# database as the runtime's connection, the owner role as current_user and the
# migration login as session_user. No value is printed.
_dms_r6_migration_connection_check() {
  local mig="$1" root_id mig_id r_sid r_db r_start m_sid m_db m_start m_cur m_sess psql_bin
  psql_bin="$(_dms_r6_psql)" || { _dms_r6_err "the migration connection cannot be checked — NOT GO"; return 1; }
  root_id="$(_dms_r6_root_psql "$(_dms_r6_identity_sql)")" || root_id=""
  local -a _DMS_R6_ALLOW=(
    PATH=/usr/local/bin:/usr/bin:/bin PGHOST="$DMS_MIGRATE_SOCKET_DIR" PGPORT="$DMS_MIGRATE_PORT"
    PGDATABASE="$DMS_MIGRATE_DATABASE" PGUSER="$DMS_MIGRATE_USERNAME" PGCONNECT_TIMEOUT=10
    "PGOPTIONS=-c role=${DMS_MIGRATE_OWNER_ROLE} -c default_transaction_read_only=on -c search_path=pg_catalog"
  )
  mig_id="$(_dms_r6_as_migrator "$mig" "$psql_bin" -X -A -t -q -v ON_ERROR_STOP=1 -c "$(_dms_r6_identity_sql)" | tail -n 1)" || mig_id=""
  IFS='|' read -r r_sid r_db r_start _ _ <<<"$root_id"
  IFS='|' read -r m_sid m_db m_start m_cur m_sess <<<"$mig_id"
  if [ -z "$r_sid" ] || [ -z "$m_sid" ] || [ "$r_sid|$r_db|$r_start" != "$m_sid|$m_db|$m_start" ]; then
    _dms_r6_err "the migration connection does not reach the runtime's database — refused before the first mutation — NOT GO"
    return 1
  fi
  if [ "$m_cur" != "$DMS_MIGRATE_OWNER_ROLE" ] || [ "$m_sess" != "$DMS_MIGRATE_USERNAME" ]; then
    _dms_r6_err "the migration connection does not log in as the migration login and act as the owner role — NOT GO"
    return 1
  fi
  return 0
}

# _dms_r6_state_agrees <split probe answer>: the switch must agree with the database.
_dms_r6_state_agrees() {
  case "${DMS_R6_MIGRATOR}:$1" in
    0:split=0|1:split=1) return 0 ;;
    0:split=1) _dms_r6_err "the migrator switch is off but the database is already split — refused before migrating — NOT GO" ;;
    1:split=0) _dms_r6_err "the migrator switch is on but the database is not split yet (R6-D comes first) — refused before migrating — NOT GO" ;;
    *) _dms_r6_err "the role-split state of the database cannot be determined — refused before migrating — NOT GO" ;;
  esac
  return 1
}

# _dms_r6_migrator_isolated <migration account> [<private paths (space list)>]
#
# Before the account runs anything of the application: it must not read the
# environment file, the cached configuration or any declared private path, nor
# write the runtime trees (review round 2, N12); it must read the code it runs.
# The end-of-deploy isolation gate checks the full set again.
_dms_r6_migrator_isolated() {
  local mig="$1" private="${2:-}" p
  local -a _DMS_R6_ALLOW=(PATH=/usr/local/bin:/usr/bin:/bin)
  for p in .env bootstrap/cache/config.php $private; do
    if _dms_r6_as_migrator "$mig" test -r "$p" >/dev/null; then
      _dms_r6_err "the migration OS account can read $([ "$p" = .env ] && echo 'the environment file' || echo "$p") — NOT GO"
      return 1
    fi
  done
  for p in bootstrap/cache storage; do
    if _dms_r6_as_migrator "$mig" test -w "$p" >/dev/null; then
      _dms_r6_err "the migration OS account can write ${p} — NOT GO"
      return 1
    fi
  done
  for p in artisan vendor/autoload.php; do
    if ! _dms_r6_as_migrator "$mig" test -r "$p" >/dev/null; then
      _dms_r6_err "the migration OS account cannot read the application code (${p}) — NOT GO"
      return 1
    fi
  done
  return 0
}

# dms_r6_preflight <runtime user> <runtime group> <other forbidden accounts> [<private paths>]
#
# Before the deploy's first mutation (the secret file, the backup, the
# checkout): the switch file, the migration account, its socket, its isolation,
# the migration connection itself, and the switch agreeing with the database —
# all without application code, so it also works when the running release
# predates R6-B4 (OD-R6-2 v5 A4). A refusal here leaves the running release
# untouched. The migrate step re-asserts it with the new release's code.
dms_r6_preflight() {
  local runtime_user="${1:?runtime user}" runtime_group="${2:?runtime group}" forbidden="${3:-}" private="${4:-}"
  echo "== R6-B4 preflight (migrator switch, before the first mutation) =="
  dms_r6_switch_read "$(_dms_r6_trusted_uid)" "$runtime_user" "$runtime_group" "$forbidden" || return 1
  if [ "$DMS_R6_MIGRATOR" = 1 ]; then
    _dms_r6_socket_check "$DMS_MIGRATE_SOCKET_DIR" "$DMS_MIGRATE_PORT" "$DMS_R6_MIGRATION_OS_USER" "$runtime_user" || return 1
    _dms_r6_no_migrator_process "$DMS_R6_MIGRATION_OS_USER" || return 1
    _dms_r6_migrator_isolated "$DMS_R6_MIGRATION_OS_USER" "$private" || return 1
    _dms_r6_migration_connection_check "$DMS_R6_MIGRATION_OS_USER" || return 1
    _dms_r6_no_migrator_process "$DMS_R6_MIGRATION_OS_USER" || return 1
  fi
  _dms_r6_state_agrees "$(_dms_r6_split_probe_sql)" || return 1
  echo "R6-B4 preflight: switch ${DMS_R6_MIGRATOR}, the database state agrees"
}

# _dms_r6_split_probe <runtime user>: prints split=0, split=1 or split=unknown
# (read-only, as the runtime, with the release's code). The probe's own output
# is never printed: it could name a configured value.
_dms_r6_split_probe() {
  local out rc=0
  out="$(dms_privsep_as_runtime "$1" php artisan foundation:db-privilege-check --split-state)" || rc=$?
  out="$(printf '%s\n' "$out" | tail -n 1)"
  case "$rc:$out" in
    0:split=0|0:split=1) printf '%s\n' "$out" ;;
    *) _dms_r6_err "the split-state probe gave no usable answer (exit ${rc})"; printf 'split=unknown\n' ;;
  esac
}

# dms_r6_migrate <runtime user> <runtime group> <other forbidden accounts> <private paths> <runtime migrate...>
#
# The deploy's migrate step. For switch 0 it runs <runtime migrate...>, the
# caller's command that runs `php artisan migrate --force` as the runtime user
# (written in the deploy as runtime code, so its scanners see it), unchanged.
dms_r6_migrate() {
  local runtime_user="${1:?runtime user}" runtime_group="${2:?runtime group}" forbidden="${3:-}" private="${4:-}"
  shift 4 || true
  [ "$#" -gt 0 ] || { _dms_r6_err "usage: dms_r6_migrate <runtime user> <runtime group> <forbidden accounts> <private paths> <runtime migrate...>"; return 2; }
  dms_r6_switch_read "$(_dms_r6_trusted_uid)" "$runtime_user" "$runtime_group" "$forbidden" || return 1

  # v4 A4: the switch must agree with the database. Read-only, as the runtime, with the new release's code.
  _dms_r6_state_agrees "$(_dms_r6_split_probe "$runtime_user")" || return 1

  if [ "$DMS_R6_MIGRATOR" = 0 ]; then
    echo "R6 migrator switch: 0 (migrations run as the runtime user, unchanged)"
    "$@"
    return
  fi

  echo "R6 migrator switch: 1 (migrations as the dedicated migration OS account through pgsql_migrate)"
  local mig="$DMS_R6_MIGRATION_OS_USER" cache status=0 id_m id_r
  _dms_r6_socket_check "$DMS_MIGRATE_SOCKET_DIR" "$DMS_MIGRATE_PORT" "$mig" "$runtime_user" || return 1
  _dms_r6_migrator_isolated "$mig" "$private" || return 1
  _dms_r6_no_migrator_process "$mig" || return 1

  cache="$(mktemp -d "$(_dms_r6_cache_root)/dms-r6-migrate.XXXXXX")" || { _dms_r6_err "no private cache directory — NOT GO"; return 1; }
  chown -h -- "$mig" "$cache" && chmod 0700 -- "$cache" || { rm -rf -- "$cache"; _dms_r6_err "the private cache directory could not be handed over — NOT GO"; return 1; }

  local -a _DMS_R6_ALLOW=(
    PATH=/usr/local/bin:/usr/bin:/bin HOME=/nonexistent APP_ENV=production LOG_CHANNEL=stderr
    CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array DB_CONNECTION=pgsql_migrate
    DB_MIGRATE_SOCKET_DIR="$DMS_MIGRATE_SOCKET_DIR" DB_MIGRATE_PORT="$DMS_MIGRATE_PORT"
    DB_MIGRATE_DATABASE="$DMS_MIGRATE_DATABASE" DB_MIGRATE_USERNAME="$DMS_MIGRATE_USERNAME"
    DB_MIGRATE_OWNER_ROLE="$DMS_MIGRATE_OWNER_ROLE" DB_PRIVILEGE_RUNTIME_ROLE="$DMS_PRIVILEGE_RUNTIME_ROLE"
    APP_CONFIG_CACHE="$cache/config.php" APP_SERVICES_CACHE="$cache/services.php" APP_PACKAGES_CACHE="$cache/packages.php"
    APP_ROUTES_CACHE="$cache/routes.php" APP_EVENTS_CACHE="$cache/events.php"
  )

  # Both sessions must reach the same database of the same running cluster
  # instance (a digest of the cluster's system identifier, its start time and
  # the database name; no value is printed).
  id_m="$(_dms_r6_as_migrator "$mig" php artisan foundation:db-privilege-check --identity --database=pgsql_migrate | tail -n 1)" || id_m=""
  id_r="$(dms_privsep_as_runtime "$runtime_user" php artisan foundation:db-privilege-check --identity | tail -n 1)" || id_r=""
  if ! [[ "$id_m" =~ ^identity=[0-9a-f]{64}$ ]] || [ "$id_m" != "$id_r" ]; then
    rm -rf -- "$cache"
    _dms_r6_err "the migration connection does not reach the runtime's database — refused before migrating — NOT GO"
    return 1
  fi
  _dms_r6_no_migrator_process "$mig" || { rm -rf -- "$cache"; return 1; }

  echo "== R6-B4 migrate (migration OS account, pgsql_migrate) =="
  _dms_r6_as_migrator "$mig" php artisan migrate --database=pgsql_migrate --force || status=$?
  _dms_r6_no_migrator_process "$mig" || status=1
  if [ "$status" = 0 ]; then
    echo "== R6-B4 runtime grants (foundation:db-privilege-apply --apply) =="
    _dms_r6_as_migrator "$mig" php artisan foundation:db-privilege-apply --apply || status=$?
    _dms_r6_no_migrator_process "$mig" || status=1
  fi
  rm -rf -- "$cache"
  if [ "$status" != 0 ]; then
    _dms_r6_err "the migration or the runtime grants failed (exit ${status}) — NOT GO"
    return 1
  fi

  echo "== R6-B4 no pending migration, as the runtime =="
  status=0
  dms_privsep_as_runtime "$runtime_user" php artisan migrate:status --pending=1 >/dev/null || status=$?
  if [ "$status" != 0 ]; then
    _dms_r6_err "the runtime's database still has pending migrations, or their state cannot be read (exit ${status}) — NOT GO"
    return 1
  fi

  echo "== R6-B4 privilege check as the runtime (foundation:db-privilege-check) =="
  status=0
  (
    export DB_MIGRATE_OWNER_ROLE="$DMS_MIGRATE_OWNER_ROLE" DB_PRIVILEGE_RUNTIME_ROLE="$DMS_PRIVILEGE_RUNTIME_ROLE"
    dms_privsep_as_runtime "$runtime_user" php artisan foundation:db-privilege-check
  ) || status=$?
  if [ "$status" != 0 ]; then
    _dms_r6_err "the database does not match the role separation (check exit ${status}) — the deploy stops before smoke — NOT GO"
    return 1
  fi
  echo "R6-B4: migrations, runtime grants and the privilege check: GO"
}

# dms_r6_rollback_guard <target sha> <runtime user>
#
# IAR-A12: while the split is active — the switch is 1, or the database is not
# unsplit — a rollback to a commit that predates R6-B4 (no
# scripts/lib/r6-migrate.sh) is refused before anything is backed up or checked
# out: that code would migrate as the runtime user, which can no longer create
# anything. The split state is probed without application code (a release that
# cannot boot is the usual reason to roll back; review round 2, N4). A rollback
# never migrates through the switch, so the migration account is not checked.
dms_r6_rollback_guard() {
  local target="${1:?target sha}" state
  : "${2:?runtime user}"
  _dms_r6_switch_value_read "$(_dms_r6_trusted_uid)" || return 1
  if git cat-file -e "${target}:scripts/lib/r6-migrate.sh" 2>/dev/null; then
    return 0
  fi
  if [ "$DMS_R6_MIGRATOR" = 1 ]; then
    _dms_r6_err "the role split is active (switch on) and the rollback target predates R6-B4 — refused before the backup and the checkout — NOT GO"
    return 1
  fi
  state="$(_dms_r6_split_probe_sql)"
  case "$state" in
    split=0) return 0 ;;
    split=1) _dms_r6_err "the database is split and the rollback target predates R6-B4 — refused before the backup and the checkout — NOT GO" ;;
    *) _dms_r6_err "the role-split state cannot be determined and the rollback target predates R6-B4 — refused before the backup and the checkout — NOT GO" ;;
  esac
  return 1
}
