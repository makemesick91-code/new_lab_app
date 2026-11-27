#!/usr/bin/env bash
# R6-B4 on a DISPOSABLE VM — the real deploy code paths with the host switch for the migrator, plus the F1-B fixes.
# Owner directive "R6-B3 ACCEPTANCE AND PARALLEL CRITICAL-PATH EXECUTION" §4 (2026-11-27). Validation branch only.
#
# Runs as root on a fresh GitHub-hosted runner (refuses elsewhere). Synthetic only: design account names (r4*),
# a private PostgreSQL 16 cluster on a Unix socket, random disposable secrets. It sources the repository's own
# scripts/lib/runtime-privsep.sh and scripts/lib/r6-migrate.sh and runs scripts/verify-runtime-isolation.sh, so the
# code under test is exactly what the deploy runs.
#
# PASS / FAIL (exit 1 on any FAIL) / NOTE.
set -uo pipefail
[ "${GITHUB_ACTIONS:-}" = true ] && [ "$(id -u)" = 0 ] || { echo "refusing: only as root on a disposable GitHub-hosted runner"; exit 2; }

SRC=${1:?source checkout}
RT=r4rt; MIG=r4mig; CO=r4co; PRIM=r4prim
ROOT=/srv/r4; APP=$ROOT/app; PGD=$ROOT/pg; SOCK=$ROOT/s; PORT=5435
BIN=/usr/lib/postgresql/16/bin
RES=0
pass() { echo "PASS  $*"; }
fail() { echo "FAIL  $*"; RES=1; }
note() { echo "NOTE  $*"; }
RT_PW="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
APPKEY="base64:$(head -c 32 /dev/urandom | base64)"

echo "== toolchain"
[ -x "$BIN/initdb" ] || { DEBIAN_FRONTEND=noninteractive apt-get -qq update && apt-get -qq install -y postgresql-16 >/dev/null; }
command -v setfacl >/dev/null || { DEBIAN_FRONTEND=noninteractive apt-get -qq install -y acl >/dev/null; }

echo "== accounts: runtime, migration (outside the runtime group), co-tenant stand-in"
for u in "$RT" "$MIG" "$CO"; do
  id "$u" >/dev/null 2>&1 || useradd --system --shell /usr/sbin/nologin --home-dir /nonexistent --no-create-home --user-group "$u"
done

echo "== private PostgreSQL 16 cluster (rule 245 layout; the migration login maps only to $MIG)"
mkdir -p "$PGD" "$SOCK"; chown postgres:postgres "$PGD" "$SOCK"; chmod 0755 "$SOCK"
runuser -u postgres -- "$BIN/initdb" -D "$PGD" -U postgres --auth-local=peer --auth-host=reject >/dev/null
printf 'mig %s r6t_migrator\n' "$MIG" > "$PGD/pg_ident.conf"
printf 'local all postgres peer\nlocal all r6t_migrator peer map=mig\nlocal all r6t_runtime scram-sha-256\nlocal all all reject\n' > "$PGD/pg_hba.conf"
chown postgres:postgres "$PGD"/pg_*.conf
runuser -u postgres -- "$BIN/pg_ctl" -D "$PGD" -l "$PGD/postgres.log" -o "-k $SOCK -c listen_addresses='' -p $PORT" -w start >/dev/null || { echo "HARNESS ERROR: cluster"; exit 3; }
SU=(runuser -u postgres -- "$BIN/psql" -X -q -v ON_ERROR_STOP=1 -h "$SOCK" -p "$PORT" -U postgres)
"${SU[@]}" -d postgres -c "CREATE ROLE r6t_owner NOLOGIN" -c "CREATE ROLE r6t_migrator LOGIN" \
  -c "GRANT r6t_owner TO r6t_migrator WITH INHERIT FALSE, SET TRUE" -c "CREATE ROLE r6t_runtime LOGIN PASSWORD '$RT_PW'" \
  -c "GRANT pg_read_all_stats, pg_read_all_settings TO r6t_runtime WITH INHERIT TRUE" \
  -c "CREATE DATABASE r6t_db" -c "REVOKE CREATE, TEMPORARY ON DATABASE r6t_db FROM PUBLIC" \
  -c "REVOKE CONNECT, TEMPORARY ON DATABASE postgres FROM PUBLIC" -c "REVOKE CONNECT, TEMPORARY ON DATABASE template1 FROM PUBLIC" \
  -c "GRANT CONNECT ON DATABASE r6t_db TO r6t_migrator, r6t_runtime" >/dev/null || { echo "HARNESS ERROR: roles"; exit 3; }
"${SU[@]}" -d r6t_db -c "ALTER SCHEMA public OWNER TO r6t_owner" -c "REVOKE CREATE ON SCHEMA public FROM PUBLIC" \
  -c "GRANT USAGE ON SCHEMA public TO r6t_runtime" -c "ALTER DEFAULT PRIVILEGES FOR ROLE r6t_owner IN SCHEMA public GRANT SELECT ON TABLES TO r6t_runtime" >/dev/null

echo "== application tree in the production layout (bootstrap/cache 2770, environment file root:runtime 0640)"
rm -rf "$ROOT/app"; mkdir -p "$APP"; cp -a "$SRC/." "$APP/"; rm -rf "$APP/.git" "$APP/.env"; rm -f "$APP"/bootstrap/cache/*.php
chown -R root:root "$APP"; chmod -R u=rwX,go=rX "$APP"
chown -R "$RT:$RT" "$APP/storage" "$APP/bootstrap/cache"; chmod 0755 "$APP/bootstrap"; chmod 2770 "$APP/bootstrap/cache"
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
DB_SSLMODE=disable
ENV
chown root:"$RT" "$APP/.env"; chmod 0640 "$APP/.env"
cd "$APP" || exit 3
# shellcheck source=/dev/null
. scripts/lib/runtime-privsep.sh
# shellcheck source=/dev/null
. scripts/lib/r6-migrate.sh
SWITCH="$ROOT/migrator.conf"
cat > "$SWITCH" <<CONF
DMS_R6_MIGRATOR=1
DMS_R6_MIGRATION_OS_USER=$MIG
DMS_MIGRATE_SOCKET_DIR=$SOCK
DMS_MIGRATE_PORT=$PORT
DMS_MIGRATE_DATABASE=r6t_db
DMS_MIGRATE_USERNAME=r6t_migrator
DMS_MIGRATE_OWNER_ROLE=r6t_owner
DMS_PRIVILEGE_RUNTIME_ROLE=r6t_runtime
CONF
chown root:root "$SWITCH"; chmod 0600 "$SWITCH"
# The library's fixed values are functions (never the environment or the environment file, rule 233); this harness
# redefines only the switch path after sourcing.
R4_SWITCH="$SWITCH"
_dms_r6_switch_file() { printf '%s\n' "$R4_SWITCH"; }
read -r -a PRIVATE <<< "$(. deploy/runtime-identity.conf && printf '%s' "$DMS_PRIVATE_PATHS")"
PRIV="${PRIVATE[*]}"
r6_runtime_migrate() { dms_privsep_as_runtime "$RT" php artisan migrate --force; }
step() { dms_r6_migrate "$RT" "$RT" "$CO" "$PRIV" r6_runtime_migrate; }
preflight() { dms_r6_preflight "$RT" "$RT" "$CO" "$PRIV"; }

echo "== R6-B4-1 the switch file must be trusted"
chmod 0620 "$SWITCH"; step >/dev/null 2>&1 && fail "R6-B4-1 a group-writable switch file was accepted" || pass "R6-B4-1 a group-writable switch file is refused"
chmod 0600 "$SWITCH"
usermod -a -G "$RT" "$MIG"; dms_r6_switch_read 0 "$RT" "$RT" "$CO" >/dev/null 2>&1 && fail "R6-B4-1 a migration account in the runtime group was accepted" || pass "R6-B4-1 a migration account in the runtime group is refused"
gpasswd -d "$MIG" "$RT" >/dev/null
id -nG "$MIG" | tr ' ' '\n' | grep -qx "$RT" && { echo "HARNESS ERROR: $MIG is still in $RT"; exit 3; }
usermod -s /bin/bash "$MIG"; dms_r6_switch_read 0 "$RT" "$RT" "$CO" >/dev/null 2>&1 && fail "R6-B4-1 a migration account with a login shell was accepted" || pass "R6-B4-1 a migration account with a login shell is refused"
usermod -s /usr/sbin/nologin "$MIG"
dms_r6_switch_read 0 "$RT" "$RT" "$MIG" >/dev/null 2>&1 && fail "R6-B4-1 a forbidden (build/admin) account was accepted" || pass "R6-B4-1 an account on the forbidden list is refused"
dms_r6_switch_read 0 "$RT" "$RT" "$CO" >/dev/null 2>&1 && pass "R6-B4-1 the dedicated migration account is accepted" || fail "R6-B4-1 the dedicated migration account was refused"

echo "== R6-B4-8 no runtime secret reaches the migration account (real runuser; review #1)"
LEAK="$(export APP_KEY="$APPKEY" DB_PASSWORD="$RT_PW" R4_OTHER=x; _DMS_R6_ALLOW=(PATH=/usr/local/bin:/usr/bin:/bin R4_ALLOWED=1); _dms_r6_as_migrator "$MIG" sh -c 'id -un; tr "\\0" "\\n" < /proc/self/environ')"
[ "$(head -n 1 <<<"$LEAK")" = "$MIG" ] && pass "R6-B4-8 the hop runs as the migration account" || fail "R6-B4-8 the hop ran as $(head -n 1 <<<"$LEAK")"
grep -qF "$RT_PW" <<<"$LEAK" || grep -qF "$APPKEY" <<<"$LEAK" || grep -q '^R4_OTHER=' <<<"$LEAK" \
  && fail "R6-B4-8 a value of root's environment reached the migration account" || pass "R6-B4-8 the migration account's process environment holds no value of root's environment"
grep -q '^R4_ALLOWED=1$' <<<"$LEAK" && pass "R6-B4-8 the allowlisted values arrive through the environment" || fail "R6-B4-8 the allowlist did not arrive"
note "R6-B4-8 environment names the migration account received: $(sed -n '2,$p' <<<"$LEAK" | cut -d= -f1 | sort | tr '\n' ' ')"

echo "== R6-B4-9 the preflight, before the first mutation, without application code"
OUT="$(preflight 2>&1)"; RC=$?
[ "$RC" = 0 ] && grep -q "the database state agrees" <<<"$OUT" && pass "R6-B4-9 switch 1 on the provisioned (split, still empty) database: the preflight (migration connection included) agrees" || fail "R6-B4-9 preflight rc=$RC :: $(tail -c 300 <<<"$OUT")"
OUT="$(R4_SWITCH=$ROOT/absent.conf preflight 2>&1)"; RC=$?
[ "$RC" != 0 ] && grep -q "already split" <<<"$OUT" && pass "R6-B4-9 switch 0 on a split database is refused in the preflight" || fail "R6-B4-9 switch 0 on a split database: rc=$RC :: $(tail -c 300 <<<"$OUT")"
grep -qE 'r6t_(runtime|owner|migrator|db)' <<<"$OUT" && fail "R6-B4-9 a configured value appears in the preflight output" || pass "R6-B4-9 no configured value in the preflight output"

echo "== R6-B4-12 a process of the migration account is killed and refused (review round 2, N5)"
runuser -u "$MIG" -- sleep 300 >/dev/null 2>&1 &
sleep 1
OUT="$(preflight 2>&1)"; RC=$?
[ "$RC" != 0 ] && grep -q "was running — killed" <<<"$OUT" && pass "R6-B4-12 the preflight refuses while a process of the migration account runs" || fail "R6-B4-12 rc=$RC :: $(tail -c 300 <<<"$OUT")"
sleep 1
pgrep -u "$MIG" >/dev/null 2>&1 && fail "R6-B4-12 a process of the migration account survived" || pass "R6-B4-12 the process was killed"
wait 2>/dev/null || true

echo "== R6-B4-13 an environment file root must not trust is never read (review round 2, N1)"
cp -p .env "$ROOT/env.bak"
printf 'R4_PAYLOAD=$(touch %s/pwned)\n' "$ROOT" >> .env; chmod 0660 .env
OUT="$(preflight 2>&1)"; RC=$?
[ "$RC" != 0 ] && grep -q "root will not source it" <<<"$OUT" && pass "R6-B4-13 a group-writable environment file is refused before it is read" || fail "R6-B4-13 rc=$RC :: $(tail -c 300 <<<"$OUT")"
[ -e "$ROOT/pwned" ] && fail "R6-B4-13 the untrusted environment file was executed" || pass "R6-B4-13 nothing in the untrusted environment file ran"
cp -p "$ROOT/env.bak" .env; chown root:"$RT" .env; chmod 0640 .env

echo "== R6-B4-10 the migration connection must reach the runtime's database (review #2)"
"${SU[@]}" -d postgres -c "CREATE DATABASE r6t_db2" -c "GRANT CONNECT ON DATABASE r6t_db2 TO r6t_migrator" >/dev/null
sed -i 's/^DMS_MIGRATE_DATABASE=.*/DMS_MIGRATE_DATABASE=r6t_db2/' "$SWITCH"
OUT="$(preflight 2>&1)"; RC=$?
[ "$RC" != 0 ] && grep -q "does not reach the runtime's database" <<<"$OUT" && pass "R6-B4-10 the preflight refuses a switch pointing at another database (round 2, N3)" || fail "R6-B4-10 preflight rc=$RC :: $(tail -c 300 <<<"$OUT")"
OUT="$(step 2>&1)"; RC=$?
[ "$RC" != 0 ] && grep -q "does not reach the runtime's database" <<<"$OUT" && pass "R6-B4-10 a switch pointing at another database is refused before migrating" || fail "R6-B4-10 rc=$RC :: $(tail -c 300 <<<"$OUT")"
[ "$("${SU[@]}" -d r6t_db2 -At -c "select count(*) from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public'")" = 0 ] && pass "R6-B4-10 nothing was created in the other database" || fail "R6-B4-10 objects landed in the other database"
sed -i 's/^DMS_MIGRATE_DATABASE=.*/DMS_MIGRATE_DATABASE=r6t_db/' "$SWITCH"
# The extra database carries PUBLIC CONNECT by default, which the privilege check rightly reports (another database
# the runtime can connect to): remove it before the steps that expect MATCH.
"${SU[@]}" -d postgres -c "DROP DATABASE r6t_db2" >/dev/null || { echo "HARNESS ERROR: drop r6t_db2"; exit 3; }
mkdir -p "$ROOT/open"; chmod 1777 "$ROOT/open"; sed -i "s|^DMS_MIGRATE_SOCKET_DIR=.*|DMS_MIGRATE_SOCKET_DIR=$ROOT/open|" "$SWITCH"
OUT="$(step 2>&1)"; RC=$?
[ "$RC" != 0 ] && grep -q "migration socket" <<<"$OUT" && pass "R6-B4-10 a socket directory others can write is refused" || fail "R6-B4-10 socket rc=$RC :: $(tail -c 300 <<<"$OUT")"
sed -i "s|^DMS_MIGRATE_SOCKET_DIR=.*|DMS_MIGRATE_SOCKET_DIR=$SOCK|" "$SWITCH"

echo "== R6-B4-2 switch 1, before provisioning pins the guard functions: migrate and grants run, the check is DRIFT, the step is NOT GO"
BEFORE="$(cd bootstrap/cache && ls -la --time-style=+%s | sha256sum)"
OUT="$(step 2>&1)"; RC=$?
grep -q "R6 migrator switch: 1" <<<"$OUT" && pass "R6-B4-2 the switch is read as on" || fail "R6-B4-2 switch not on :: $(head -c 300 <<<"$OUT")"
[ "$RC" != 0 ] && grep -q "does not match the role separation" <<<"$OUT" && pass "R6-B4-2 a privilege check that is not MATCH stops the step (NOT GO) before any smoke" || fail "R6-B4-2 the step did not stop on DRIFT (rc=$RC) :: $(tail -c 400 <<<"$OUT")"
MIGS="$("${SU[@]}" -d r6t_db -At -c 'select count(*) from public.migrations')"; note "R6-B4-2 migrations recorded by the migration account: $MIGS"
[ "${MIGS:-0}" -gt 200 ] && pass "R6-B4-2 the migration account migrated through pgsql_migrate" || fail "R6-B4-2 migrations: $MIGS"
[ "$("${SU[@]}" -d r6t_db -At -c "select count(*) from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public' and pg_get_userbyid(c.relowner)<>'r6t_owner'")" = 0 ] && pass "R6-B4-2 every object is owned by the owner role" || fail "R6-B4-2 foreign-owned objects in public"
[ "$("${SU[@]}" -d r6t_db -At -c "select has_table_privilege('r6t_runtime','public.users','INSERT')")" = t ] && pass "R6-B4-2 foundation:db-privilege-apply granted the runtime its classes" || fail "R6-B4-2 runtime grants missing"
AFTER="$(cd bootstrap/cache && ls -la --time-style=+%s | sha256sum)"
[ "$BEFORE" = "$AFTER" ] && pass "R6-B4-2 nothing was written in bootstrap/cache" || fail "R6-B4-2 bootstrap/cache changed"
ls -d /run/dms-r6-migrate.* >/dev/null 2>&1 && fail "R6-B4-2 the private cache directory was left behind" || pass "R6-B4-2 the migration account's private cache directory was removed"

echo "== provisioning step (R6-D does this): pin every guard function's search_path exactly"
for f in $("${SU[@]}" -d r6t_db -At -c "select distinct p.oid::regprocedure from pg_trigger t join pg_class c on c.oid=t.tgrelid join pg_namespace n on n.oid=c.relnamespace join pg_proc p on p.oid=t.tgfoid where n.nspname='public' and not t.tgisinternal"); do
  "${SU[@]}" -d r6t_db -c "ALTER FUNCTION $f SET search_path = pg_catalog, pg_temp" >/dev/null
done

echo "== R6-B4-3 switch 1 after provisioning: idempotent migrate and grants, no pending migration, the check is MATCH, the step is GO"
OUT="$(step 2>&1)"; RC=$?
[ "$RC" = 0 ] && grep -q "DB PRIVILEGE CHECK: MATCH" <<<"$OUT" && grep -q "APPLIED: 0 statement" <<<"$OUT" && pass "R6-B4-3 GO: nothing to migrate, 0 grant statements, MATCH" || fail "R6-B4-3 rc=$RC :: $(tail -c 500 <<<"$OUT")"
grep -qE 'r6t_(runtime|owner|migrator)' <<<"$OUT" && fail "R6-B4-3 a role name appears in the step output" || pass "R6-B4-3 no role name in the step output"
pgrep -u "$MIG" >/dev/null 2>&1 && fail "R6-B4-3 a process of the migration account outlived the step" || pass "R6-B4-3 no process of the migration account outlived the step"

echo "== R6-B4-4 switch 0 on the split database is refused before migrating (OD-R6-2 v5 A4)"
OUT="$(R4_SWITCH=$ROOT/absent.conf step 2>&1)"; RC=$?
[ "$RC" != 0 ] && grep -q "already split" <<<"$OUT" && pass "R6-B4-4 switch 0 on a split database is refused (the runtime must never migrate after the split)" || fail "R6-B4-4 rc=$RC :: $(tail -c 300 <<<"$OUT")"

echo "== R6-B4-5 config cache: creation by the runtime wrapper, then the deploy normalization"
dms_privsep_as_runtime "$RT" php artisan config:cache --no-interaction >/dev/null
read -r -a PRIVATE <<< "$(. deploy/runtime-identity.conf && printf '%s' "$DMS_PRIVATE_PATHS")"
normalize() { dms_privsep_repair_runtime_ownership "$RT" "$RT" storage bootstrap/cache >/dev/null && dms_privsep_as_runtime_fn "$RT" "" dms_privsep_normalize_modes "${PRIVATE[@]}"; }
normalize >/dev/null
[ "$(stat -c '%a' bootstrap/cache/config.php)" = 640 ] && [ "$(stat -c '%a' bootstrap/cache)" = 2770 ] && pass "R6-B4-5 config.php 0640 in a 2770 directory" || fail "R6-B4-5 modes $(stat -c '%a' bootstrap/cache/config.php)/$(stat -c '%a' bootstrap/cache)"
runuser -u "$MIG" -- grep -qF "$RT_PW" bootstrap/cache/config.php 2>/dev/null && fail "R6-B4-5 the migration account reads the runtime password" || pass "R6-B4-5 the migration account cannot read the runtime secrets"

echo "== R6-B4-6 the isolation gate (fixed) and the normalization (fixed) against F1-B's gaps"
sed -e "s|^DMS_RUNTIME_USER=.*|DMS_RUNTIME_USER=$RT|" -e "s|^DMS_RUNTIME_GROUP=.*|DMS_RUNTIME_GROUP=$RT|" -e "s|^DMS_APP_DIR=.*|DMS_APP_DIR=$APP|" \
    -e "s|^DMS_FORBIDDEN_RUNTIME_USERS=.*|DMS_FORBIDDEN_RUNTIME_USERS=\"root www-data nobody daemon postgres $CO\"|" deploy/runtime-identity.conf > "$ROOT/identity.conf"
gate() { bash scripts/verify-runtime-isolation.sh --app-dir "$APP" --identity-file "$ROOT/identity.conf" --fpm-pool-dir "$ROOT/none" --nginx-site "$ROOT/none" --systemd-dir "$ROOT/none" --additional-forbidden-users "$MIG" 2>&1; }
G="$(gate)"
grep -q "no other account has '$RT' as its primary group" <<<"$G" && pass "R6-B4-6 baseline: no primary-group account" || fail "R6-B4-6 primary-group baseline line missing"
grep -q "'$MIG' cannot read bootstrap/cache/config.php" <<<"$G" && grep -q "'$MIG' cannot traverse bootstrap/cache" <<<"$G" && pass "R6-B4-6 the gate proves the migration account cannot traverse the cache or read config.php" || fail "R6-B4-6 migration-account lines missing"
useradd --system --shell /usr/sbin/nologin --home-dir /nonexistent --no-create-home -g "$RT" "$PRIM"
grep -q "account(s) with '$RT' as their primary group: $PRIM" <<<"$(gate)" && pass "R6-B4-6 F1B-12 fixed: the gate detects an account whose primary group is the runtime group" || fail "R6-B4-6 F1B-12 still undetected"
userdel "$PRIM"
setfacl -m "u:$CO:--x" bootstrap/cache; setfacl -m "u:$CO:r--" bootstrap/cache/config.php
mkdir -p storage/app/public; setfacl -m "u:$CO:r-x" storage/app/public
G="$(gate)"
grep -q "bootstrap/cache carries .* extended ACL" <<<"$G" && grep -q "'$CO' CAN read bootstrap/cache/config.php" <<<"$G" && grep -q "'$CO' CAN traverse bootstrap/cache" <<<"$G" \
  && pass "R6-B4-6 F1B-13 fixed: the gate reports the ACL, the traverse and the readable config.php" || fail "R6-B4-6 F1B-13 still undetected :: $(grep -E "bootstrap/cache|$CO" <<<"$G" | head -5)"
normalize >/dev/null
runuser -u "$CO" -- test -r bootstrap/cache/config.php && fail "R6-B4-6 the normalization left the ACL exposure" || pass "R6-B4-6 the normalization removed the ACL from the private tree"
getfacl -p --omit-header storage/app/public 2>/dev/null | grep -q "^user:$CO:" && pass "R6-B4-6 the normalization left the ACL of a non-private path alone" || fail "R6-B4-6 an ACL outside the private trees was removed"
setfacl -b storage/app/public
grep -q "RUNTIME ISOLATION SUMMARY" <<<"$(gate)" && grep -q "bootstrap/cache carries no extended ACL" <<<"$(gate)" && pass "R6-B4-6 after normalization the gate sees no ACL in the private tree" || fail "R6-B4-6 ACL still reported"

echo "== R6-B4-7 PHP-FPM pool and queue worker as the runtime read the cache"
if DEBIAN_FRONTEND=noninteractive apt-get -qq install -y php8.3-fpm libfcgi-bin >/dev/null 2>&1; then
  cat > "$ROOT/pool.conf" <<POOL
[global]
pid = $ROOT/fpm.pid
error_log = $ROOT/fpm.log
[r4]
user = $RT
group = $RT
listen = $ROOT/fpm.sock
listen.owner = root
listen.mode = 0600
pm = static
pm.max_children = 1
POOL
  printf '<?php $c = require "%s/bootstrap/cache/config.php"; echo is_array($c) && isset($c["app"]) ? "CONFIG-OK" : "CONFIG-BAD";\n' "$APP" > "$ROOT/probe.php"; chmod 0644 "$ROOT/probe.php"
  /usr/sbin/php-fpm8.3 -y "$ROOT/pool.conf" -R && sleep 1
  R="$(SCRIPT_FILENAME=$ROOT/probe.php REQUEST_METHOD=GET cgi-fcgi -bind -connect "$ROOT/fpm.sock" 2>&1)"
  grep -q CONFIG-OK <<<"$R" && pass "R6-B4-7 a PHP-FPM pool running as the runtime reads the cached configuration" || fail "R6-B4-7 FPM probe :: $(head -c 300 <<<"$R")"
  kill "$(cat "$ROOT/fpm.pid")" 2>/dev/null || true
else
  note "R6-B4-7 php8.3-fpm could not be installed on this runner: the FPM probe is NOT RUN"
fi
runuser -u "$RT" -- php artisan queue:work --once --stop-when-empty --no-interaction >/dev/null 2>&1 \
  && pass "R6-B4-7 a queue worker as the runtime boots from the cache" || fail "R6-B4-7 the queue worker failed"

echo "== teardown"
runuser -u postgres -- "$BIN/pg_ctl" -D "$PGD" -m immediate stop >/dev/null 2>&1 || true
echo "RESULT=$([ $RES = 0 ] && echo PASS || echo FAIL)"
exit $RES
