#!/usr/bin/env bash
# OD-R6-1 — OS-level negatives for the dedicated migration OS account (DMS-SPN4-READY-PILOT-1, R6-B2b / R6-B4 design).
#
# Runs ONLY as root on a disposable GitHub-hosted runner VM (the validation branch's workflow). Synthetic only:
# design names (r6t_*, dms-migrate, dmsrt), a private PostgreSQL 16 cluster on a Unix socket with no TCP listener,
# random disposable secrets, no production host, data, credential or network target.
#
# Proves, at the OS level, what a private workstation cluster cannot:
#   - the runtime OS account (stand-in for PHP-FPM and the queue worker) cannot become the migration OS account
#     (no sudoers rule, no su) and cannot authenticate as the migration login (peer map lists only dms-migrate);
#   - the migration OS account cannot read the runtime's secrets (the environment file; the config cache once its
#     mode is restricted) and runs with a minimal allowlisted environment that excludes them;
#   - the deploy-runner path (root -> sudo -u dms-migrate, env -i allowlist) migrates through pgsql_migrate and every
#     object it creates is owned by the owner role.
set -uo pipefail

[ "$(id -u)" = 0 ] || { echo "refusing: must run as root"; exit 2; }
[ "${GITHUB_ACTIONS:-}" = true ] || { echo "refusing: only on a disposable GitHub-hosted runner"; exit 2; }
SRC=${1:?checkout path}

BIN=/usr/lib/postgresql/16/bin
W=/srv/odr61; APP=$W/app; PGD=$W/pg; SOCK=$W/s; PORT=5433
RT=dmsrt; MIG=dms-migrate
RT_PW="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)"
APPKEY="base64:$(head -c 32 /dev/urandom | base64)"
RES=0
pass() { echo "PASS  $*"; }
fail() { echo "FAIL  $*"; RES=1; }
note() { echo "NOTE  $*"; }
expect_fail() { # label, expected-regex, command...
  local label=$1 want=$2; shift 2; local out
  if out="$("$@" 2>&1)"; then fail "$label :: unexpectedly succeeded :: $(head -c 200 <<<"$out")"
  elif grep -qiE -- "$want" <<<"$out"; then pass "$label"
  else fail "$label :: $(head -c 300 <<<"$out")"; fi
}
expect_ok() { local label=$1; shift; local out
  if out="$("$@" 2>&1)"; then pass "$label"; else fail "$label :: $(tail -c 600 <<<"$out")"; fi
}

echo "== toolchain"
[ -x "$BIN/initdb" ] || { DEBIAN_FRONTEND=noninteractive apt-get -qq update && apt-get -qq install -y postgresql-16 >/dev/null; }
php -m | grep -qi '^pdo_pgsql$' || { echo "pdo_pgsql missing"; exit 2; }
"$BIN/postgres" --version; php -r 'echo "php ", PHP_VERSION, PHP_EOL;'

echo "== OS accounts (design names)"
useradd --system --shell /usr/sbin/nologin --home-dir /nonexistent --no-create-home --user-group "$RT"
useradd --system --shell /usr/sbin/nologin --home-dir /nonexistent --no-create-home --user-group "$MIG"
id "$RT"; id "$MIG"

echo "== private PostgreSQL 16 cluster (Unix socket only, no TCP)"
mkdir -p "$PGD" "$SOCK"; chown postgres:postgres "$PGD" "$SOCK"; chmod 0755 "$SOCK"
runuser -u postgres -- "$BIN/initdb" -D "$PGD" -U postgres --auth-local=peer --auth-host=reject >/dev/null
cat > "$PGD/pg_ident.conf" <<ID
mig   $MIG   r6t_migrator
ID
cat > "$PGD/pg_hba.conf" <<HBA
local  all  postgres      peer
local  all  r6t_migrator  peer  map=mig
local  all  r6t_runtime   scram-sha-256
local  all  all           reject
host   all  all  0.0.0.0/0  reject
host   all  all  ::/0       reject
HBA
chown postgres:postgres "$PGD/pg_ident.conf" "$PGD/pg_hba.conf"
runuser -u postgres -- "$BIN/pg_ctl" -D "$PGD" -l "$PGD/postgres.log" -o "-k $SOCK -c listen_addresses='' -p $PORT" -w start >/dev/null \
  || { echo "HARNESS ERROR: the private cluster did not start"; cat "$PGD/postgres.log" 2>/dev/null | tail -20; exit 3; }
SU=(runuser -u postgres -- "$BIN/psql" -X -q -v ON_ERROR_STOP=1 -h "$SOCK" -p "$PORT" -U postgres)
"${SU[@]}" -d postgres <<SQL || { echo "HARNESS ERROR: role provisioning failed"; exit 3; }
CREATE ROLE r6t_owner NOLOGIN;
CREATE ROLE r6t_migrator LOGIN;
GRANT r6t_owner TO r6t_migrator WITH INHERIT FALSE, SET TRUE;
CREATE ROLE r6t_runtime LOGIN PASSWORD '$RT_PW';
CREATE DATABASE r6t_db;
REVOKE CREATE, TEMPORARY ON DATABASE r6t_db FROM PUBLIC;
GRANT CONNECT ON DATABASE r6t_db TO r6t_migrator, r6t_runtime;
SQL
"${SU[@]}" -d r6t_db <<'SQL' || { echo "HARNESS ERROR: schema provisioning failed"; exit 3; }
ALTER SCHEMA public OWNER TO r6t_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO r6t_runtime;
ALTER DEFAULT PRIVILEGES FOR ROLE r6t_owner IN SCHEMA public GRANT SELECT ON TABLES TO r6t_runtime;
ALTER DEFAULT PRIVILEGES FOR ROLE r6t_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO r6t_runtime;
SQL

echo "== application tree (code world-readable, runtime-writable storage, runtime-only secrets)"
mkdir -p "$APP"; cp -a "$SRC/." "$APP/"; rm -rf "$APP/.git"
rm -f "$APP/.env" "$APP"/bootstrap/cache/config.php
chown -R root:root "$APP"; chmod -R u=rwX,go=rX "$APP"
chown -R "$RT:$RT" "$APP/storage" "$APP/bootstrap/cache"
cat > "$APP/.env" <<ENV
APP_ENV=production
APP_KEY=$APPKEY
APP_DEBUG=false
LOG_CHANNEL=stderr
CACHE_STORE=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
DB_CONNECTION=pgsql
DB_HOST=$SOCK
DB_PORT=$PORT
DB_DATABASE=r6t_db
DB_USERNAME=r6t_runtime
DB_PASSWORD=$RT_PW
DB_MIGRATE_SOCKET_DIR=$SOCK
DB_MIGRATE_PORT=$PORT
DB_MIGRATE_DATABASE=r6t_db
DB_MIGRATE_USERNAME=r6t_migrator
DB_MIGRATE_OWNER_ROLE=r6t_owner
ENV
chown root:"$RT" "$APP/.env"; chmod 0640 "$APP/.env"   # the production layout (CG-15): root:runtime 0640

# The deploy runner's invocation (R6-B4 design): root -> the migration OS account, an explicit allowlist only.
ALLOW=(PATH=/usr/local/bin:/usr/bin:/bin HOME=/nonexistent APP_ENV=production APP_CONFIG_CACHE=/nonexistent/config.php
       LOG_CHANNEL=stderr CACHE_STORE=array DB_CONNECTION=pgsql_migrate
       DB_MIGRATE_SOCKET_DIR="$SOCK" DB_MIGRATE_PORT="$PORT" DB_MIGRATE_DATABASE=r6t_db
       DB_MIGRATE_USERNAME=r6t_migrator DB_MIGRATE_OWNER_ROLE=r6t_owner)
ALLOW_NAMES="$(printf '%s\n' "${ALLOW[@]}" | cut -d= -f1 | sort | tr '\n' ' ')"
asmig() { (cd "$APP" && sudo -n -u "$MIG" env -i "${ALLOW[@]}" "$@"); }
asrt() { (cd "$APP" && runuser -u "$RT" -- "$@"); }
PSQL=("$BIN/psql" -X -h "$SOCK" -p "$PORT" -d r6t_db)

echo "== positives (the deploy-runner path)"
expect_ok "P1 the deploy-runner path migrates as the migration OS account through pgsql_migrate (allowlist env, no APP_KEY, no runtime password)" \
  asmig php artisan migrate --database=pgsql_migrate --force --no-interaction
OUT="$(asmig /usr/bin/env | cut -d= -f1 | sort | tr '\n' ' ')"
[ "$OUT" = "$ALLOW_NAMES" ] && pass "P2 the migration process environment is exactly the allowlist ($ALLOW_NAMES)" || fail "P2 environment differs :: got [$OUT] want [$ALLOW_NAMES]"
grep -qwE 'APP_KEY|DB_PASSWORD' <<<"$OUT" && fail "P2b a runtime secret name reached the migration process" || pass "P2b APP_KEY and DB_PASSWORD are absent from the migration process"
OWN="$("${SU[@]}" -d r6t_db -At -c "select count(*) filter (where pg_get_userbyid(c.relowner)<>'r6t_owner'), count(*) from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public'")"
FN="$("${SU[@]}" -d r6t_db -At -c "select count(*) from pg_proc p join pg_namespace n on n.oid=p.pronamespace where n.nspname='public' and pg_get_userbyid(p.proowner)<>'r6t_owner'")"
MOWN="$("${SU[@]}" -d r6t_db -At -c "select count(*) from pg_class where pg_get_userbyid(relowner)='r6t_migrator'")"
MIGS="$("${SU[@]}" -d r6t_db -At -c "select count(*) from public.migrations")"
note "ownership: relations not owned by the owner role|public relations = $OWN; functions not owned = $FN; owned by the migrator = $MOWN; migrations rows = $MIGS"
[ "${OWN%%|*}" = 0 ] && [ "$FN" = 0 ] && [ "$MOWN" = 0 ] && [ "${MIGS:-0}" -gt 0 ] && pass "P3 every migrated object is owned by the owner role" || fail "P3 ownership"
expect_ok "P4 the runtime application (its environment file, default connection) reads the migrated schema" \
  asrt php artisan migrate:status --no-interaction

echo "== measurement: the config cache mode Laravel writes as the runtime account"
asrt php artisan config:cache --no-interaction >/dev/null 2>&1 || true
if [ -f "$APP/bootstrap/cache/config.php" ]; then
  MODE="$(stat -c '%U:%G %a' "$APP/bootstrap/cache/config.php")"
  note "M1 bootstrap/cache/config.php after config:cache as the runtime = $MODE"
  if runuser -u "$MIG" -- test -r "$APP/bootstrap/cache/config.php"; then
    note "M1 with that mode the migration OS account CAN read the cached config (it embeds the runtime secrets): the R6-B4 runner must restrict it"
  else
    note "M1 with that mode the migration OS account cannot read the cached config"
  fi
  chmod 0640 "$APP/bootstrap/cache/config.php"
else
  note "M1 config:cache did not produce a file"
fi

echo "== negatives"
expect_fail "N1 the runtime OS account cannot sudo to the migration OS account" "password is required|not in the sudoers|not allowed|may not run" \
  runuser -u "$RT" -- sudo -n -u "$MIG" /usr/bin/true
expect_fail "N2 the runtime OS account cannot su to the migration OS account" "Authentication failure|must be run from a terminal|incorrect password|cannot open session|Permission denied" \
  runuser -u "$RT" -- su -s /bin/sh "$MIG" -c /usr/bin/true
expect_fail "N3 the runtime OS account cannot authenticate as the migration login (peer map)" "Peer authentication failed" \
  runuser -u "$RT" -- "${PSQL[@]}" -U r6t_migrator -c 'select 1'
expect_fail "N4 the runtime password does not open the migration login" "Peer authentication failed" \
  runuser -u "$RT" -- env PGPASSWORD="$RT_PW" "${PSQL[@]}" -U r6t_migrator -c 'select 1'
expect_fail "N5 pgsql_migrate from the runtime OS account is refused by the server" "Peer authentication failed" \
  asrt php artisan migrate:status --database=pgsql_migrate --no-interaction
expect_fail "N6 the runtime login cannot assume the owner role" "permission denied to set role" \
  runuser -u "$RT" -- env PGPASSWORD="$RT_PW" "${PSQL[@]}" -U r6t_runtime -c 'set role r6t_owner'
expect_fail "N7 the runtime login cannot create objects" "permission denied for schema public" \
  runuser -u "$RT" -- env PGPASSWORD="$RT_PW" "${PSQL[@]}" -U r6t_runtime -c 'create table public.neg_rt (id int)'
expect_fail "N8 the migration OS account cannot read the runtime environment file" "Permission denied" \
  runuser -u "$MIG" -- cat "$APP/.env"
expect_fail "N9 the migration OS account cannot read the restricted config cache" "Permission denied" \
  runuser -u "$MIG" -- cat "$APP/bootstrap/cache/config.php"
expect_fail "N10 the migration OS account cannot use the runtime login without its password" "password authentication failed|no password supplied" \
  runuser -u "$MIG" -- "${PSQL[@]}" -U r6t_runtime -c 'select 1'
expect_fail "N11 no TCP path to the cluster" "Connection refused|could not connect" \
  runuser -u "$MIG" -- "$BIN/psql" -X -h 127.0.0.1 -p "$PORT" -U r6t_migrator -d r6t_db -c 'select 1'
SH="$(getent passwd "$MIG" | cut -d: -f7)"; PS="$(passwd -S "$MIG" | awk '{print $2}')"
[ "$SH" = /usr/sbin/nologin ] && [ "$PS" = L ] && pass "N12 the migration OS account has no login shell and a locked password ($SH, $PS)" || fail "N12 shell=$SH status=$PS"
expect_fail "N13 the runtime OS account cannot write the application code" "Permission denied" \
  runuser -u "$RT" -- sh -c "echo x >> '$APP/artisan'"

echo "== teardown"
runuser -u postgres -- "$BIN/pg_ctl" -D "$PGD" -m immediate stop >/dev/null 2>&1 || true
echo "RESULT=$([ $RES = 0 ] && echo PASS || echo FAIL)"
exit $RES
