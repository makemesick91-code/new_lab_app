#!/usr/bin/env bash
# F1-B (R6-B4 preparation) — config-cache credential isolation and permission-regression checks on a DISPOSABLE VM.
# Owner evidence "F1 PRODUCTION CONFIG CACHE VERIFICATION" §3/§4 (2026-11-27); evidence pack 62 §2.2 (F1B-1 … F1B-14).
#
# Validation branch only. Runs as root on a fresh GitHub-hosted runner (refuses elsewhere). Synthetic only: design
# account names (f1b*), a private PostgreSQL 16 cluster on a Unix socket, random disposable secrets. It reproduces the
# production layout the owner observed: bootstrap root:root 0755, bootstrap/cache <runtime>:<runtime> 2770,
# config.php 0640, the administrator and the migration account outside the runtime group. It calls the repository's
# own deploy functions (scripts/lib/runtime-privsep.sh) and its isolation gate (scripts/verify-runtime-isolation.sh).
#
# Output lines: PASS / FAIL (a protection did not hold: exit 1) / GAP (the protection holds or fails as recorded, but
# the repository's gate does not detect it: an R6-B4 work item, reported, exit unaffected) / NOTE.
set -uo pipefail
[ "${GITHUB_ACTIONS:-}" = true ] && [ "$(id -u)" = 0 ] || { echo "refusing: only as root on a disposable GitHub-hosted runner"; exit 2; }

SRC=${1:?source checkout}
RT=f1brt; MIG=f1bmig; CO=f1bco; PRIM=f1bprim
ROOT=/srv/f1b; APP=$ROOT/app; PGD=$ROOT/pg; SOCK=$ROOT/s; PORT=5434
BIN=/usr/lib/postgresql/16/bin
RES=0; GAPS=0
pass() { echo "PASS  $*"; }
fail() { echo "FAIL  $*"; RES=1; }
gap()  { echo "GAP   $*"; GAPS=$((GAPS + 1)); }
note() { echo "NOTE  $*"; }
mode() { stat -c '%a' "$1"; }
SECRET="F1B_SYNTHETIC_SECRET_$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
RT_PW="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
APPKEY="base64:$(head -c 32 /dev/urandom | base64)"
CFG=$APP/bootstrap/cache/config.php

echo "== toolchain"
[ -x "$BIN/initdb" ] || { DEBIAN_FRONTEND=noninteractive apt-get -qq update && apt-get -qq install -y postgresql-16 >/dev/null; }
command -v setfacl >/dev/null || { DEBIAN_FRONTEND=noninteractive apt-get -qq install -y acl >/dev/null; }
sysctl -n fs.protected_hardlinks fs.protected_symlinks | tr '\n' ' '; echo "(protected_hardlinks protected_symlinks)"

echo "== accounts (design names): runtime, migration, co-tenant stand-in; none in another's group"
for u in "$RT" "$MIG" "$CO"; do
  id "$u" >/dev/null 2>&1 || useradd --system --shell /usr/sbin/nologin --home-dir /nonexistent --no-create-home --user-group "$u"
done
id "$RT"; id "$MIG"; id "$CO"

echo "== application tree in the production layout"
rm -rf "$ROOT"; mkdir -p "$APP"; cp -a "$SRC/." "$APP/"; rm -rf "$APP/.git" "$APP/.env"
rm -f "$APP"/bootstrap/cache/*.php
chown -R root:root "$APP"; chmod -R u=rwX,go=rX "$APP"
chown -R "$RT:$RT" "$APP/storage" "$APP/bootstrap/cache"
chmod 0755 "$APP/bootstrap"; chmod 2770 "$APP/bootstrap/cache"
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
DB_PASSWORD=$SECRET
DB_MIGRATE_SOCKET_DIR=$SOCK
DB_MIGRATE_PORT=$PORT
DB_MIGRATE_DATABASE=r6t_db
DB_MIGRATE_USERNAME=r6t_migrator
DB_MIGRATE_OWNER_ROLE=r6t_owner
ENV
chown root:"$RT" "$APP/.env"; chmod 0640 "$APP/.env"
# shellcheck source=/dev/null
. "$APP/scripts/lib/runtime-privsep.sh"
read -r -a PRIVATE <<< "$(. "$APP/deploy/runtime-identity.conf" && printf '%s' "$DMS_PRIVATE_PATHS")"
printf '%s\n' "${PRIVATE[@]}" | grep -qx 'bootstrap/cache' && pass "F1B-14a bootstrap/cache is a declared private path (deploy/runtime-identity.conf)" || fail "F1B-14a bootstrap/cache is not in DMS_PRIVATE_PATHS"
normalize() { (cd "$APP" && dms_privsep_repair_runtime_ownership "$RT" "$RT" storage bootstrap/cache >/dev/null && dms_privsep_as_runtime_fn "$RT" "" dms_privsep_normalize_modes "${PRIVATE[@]}"); }
denied() { # account, path → 0 when the account can neither stat nor read the file
  ! runuser -u "$1" -- test -e "$2" 2>/dev/null && ! runuser -u "$1" -- cat "$2" >/dev/null 2>&1
}
reads_secret() { runuser -u "$1" -- cat "$2" 2>/dev/null | grep -q "$SECRET"; }

echo "== F1B-1 initial creation through the deploy's runtime wrapper (umask 027), then the deploy normalization"
(cd "$APP" && dms_privsep_as_runtime "$RT" php artisan config:cache --no-interaction >/dev/null)
[ -f "$CFG" ] && note "F1B-1 config.php after config:cache via dms_privsep_as_runtime = $(mode "$CFG"), directory $(mode "$APP/bootstrap/cache")"
grep -q "$SECRET" "$CFG" && note "F1B-1 the cache embeds the synthetic runtime secret (as production's does)" || fail "F1B-1 the cache does not carry the secret: the test proves nothing"
for u in "$CO" "$MIG"; do denied "$u" "$CFG" && pass "F1B-1 $u cannot stat or read config.php before normalization" || fail "F1B-1 $u reaches config.php before normalization"; done
normalize >/dev/null || fail "F1B-1 the deploy normalization failed"
[ "$(mode "$CFG")" = 640 ] && [ "$(mode "$APP/bootstrap/cache")" = 2770 ] && pass "F1B-1 after normalization: config.php 0640, directory 2770" || fail "F1B-1 after normalization: config.php $(mode "$CFG"), directory $(mode "$APP/bootstrap/cache")"

echo "== F1B-2 regeneration (config:clear + config:cache) and F1B-5 an interruption before normalization (umask 022, the worst case)"
(cd "$APP" && runuser -u "$RT" -- sh -c 'umask 022; php artisan config:cache --no-interaction' >/dev/null)
note "F1B-2/5 config.php regenerated under umask 022 without normalization = $(mode "$CFG") (new inode: config:cache clears first)"
for u in "$CO" "$MIG"; do denied "$u" "$CFG" && pass "F1B-5 $u cannot reach the 0$(mode "$CFG") file: the 2770 directory is the boundary" || fail "F1B-5 $u reaches the regenerated file"; done
normalize >/dev/null; [ "$(mode "$CFG")" = 640 ] && pass "F1B-2 the next normalization restores 0640" || fail "F1B-2 config.php $(mode "$CFG") after normalization"

echo "== F1B-6 file replacement, a planted symlink and a hardlink"
install -o root -g root -m 0600 /dev/null "$ROOT/root-target"
runuser -u "$RT" -- ln -s "$ROOT/root-target" "$APP/bootstrap/cache/evil-link" 2>/dev/null || note "F1B-6 symlink creation refused"
runuser -u "$RT" -- ln "$ROOT/root-target" "$APP/bootstrap/cache/evil-hard" 2>/dev/null && fail "F1B-6 the runtime could hardlink a root file" || pass "F1B-6 the runtime cannot hardlink a root-owned file (protected_hardlinks)"
(cd "$APP" && runuser -u "$RT" -- sh -c 'umask 022; cp bootstrap/cache/config.php bootstrap/cache/.new && mv -f bootstrap/cache/.new bootstrap/cache/config.php')
normalize >/dev/null
[ "$(stat -c '%U:%G %a' "$ROOT/root-target")" = "root:root 600" ] && pass "F1B-6 normalization did not follow the planted symlink (target still root:root 0600)" || fail "F1B-6 the symlink target changed: $(stat -c '%U:%G %a' "$ROOT/root-target")"
[ "$(mode "$CFG")" = 640 ] && pass "F1B-6 an atomically replaced config.php is normalized to 0640" || fail "F1B-6 replaced file is $(mode "$CFG")"
rm -f "$APP/bootstrap/cache/evil-link"

echo "== F1B-7 / F1B-8 the runtime still reads its cache (PHP-FPM and the queue worker run as the runtime account)"
(cd "$APP" && runuser -u "$RT" -- php -r '$c = require "bootstrap/cache/config.php"; exit(is_array($c) && isset($c["app"]) ? 0 : 1);') \
  && pass "F1B-7 the runtime account loads the cached configuration" || fail "F1B-7 the runtime account cannot load its cache"
(cd "$APP" && runuser -u "$RT" -- php artisan about --only=cache --no-interaction >/dev/null 2>&1) \
  && pass "F1B-8 an artisan process as the runtime account boots from the cache (stand-in for the queue worker; the FPM pool itself is NOT RUN here)" \
  || fail "F1B-8 artisan as the runtime account failed"

echo "== F1B-9 the migration OS account is denied the runtime secrets"
denied "$MIG" "$CFG" && pass "F1B-9 $MIG cannot stat or read config.php" || fail "F1B-9 $MIG reaches config.php"
(cd "$APP" && runuser -u "$MIG" -- php -r 'exit(@include "bootstrap/cache/config.php" ? 1 : 0);') && pass "F1B-9 $MIG cannot include config.php from PHP" || fail "F1B-9 $MIG included config.php"
runuser -u "$MIG" -- cat "$APP/.env" >/dev/null 2>&1 && fail "F1B-9 $MIG reads the environment file" || pass "F1B-9 $MIG cannot read the environment file"

echo "== F1B-10 the migration still works without the secrets (private PostgreSQL 16, peer over the socket)"
mkdir -p "$PGD" "$SOCK"; chown postgres:postgres "$PGD" "$SOCK"; chmod 0755 "$SOCK"
runuser -u postgres -- "$BIN/initdb" -D "$PGD" -U postgres --auth-local=peer --auth-host=reject >/dev/null
printf 'mig   %s   r6t_migrator\n' "$MIG" > "$PGD/pg_ident.conf"
printf 'local all postgres peer\nlocal all r6t_migrator peer map=mig\nlocal all r6t_runtime scram-sha-256\nlocal all all reject\n' > "$PGD/pg_hba.conf"
chown postgres:postgres "$PGD"/pg_*.conf
runuser -u postgres -- "$BIN/pg_ctl" -D "$PGD" -l "$PGD/postgres.log" -o "-k $SOCK -c listen_addresses='' -p $PORT" -w start >/dev/null || { echo "HARNESS ERROR: cluster"; exit 3; }
SU=(runuser -u postgres -- "$BIN/psql" -X -q -v ON_ERROR_STOP=1 -h "$SOCK" -p "$PORT" -U postgres)
"${SU[@]}" -d postgres -c "CREATE ROLE r6t_owner NOLOGIN" -c "CREATE ROLE r6t_migrator LOGIN" \
  -c "GRANT r6t_owner TO r6t_migrator WITH INHERIT FALSE, SET TRUE" -c "CREATE ROLE r6t_runtime LOGIN PASSWORD '$SECRET'" \
  -c "CREATE DATABASE r6t_db" -c "REVOKE CREATE, TEMPORARY ON DATABASE r6t_db FROM PUBLIC" \
  -c "GRANT CONNECT ON DATABASE r6t_db TO r6t_migrator, r6t_runtime" >/dev/null || { echo "HARNESS ERROR: roles"; exit 3; }
"${SU[@]}" -d r6t_db -c "ALTER SCHEMA public OWNER TO r6t_owner" -c "REVOKE CREATE ON SCHEMA public FROM PUBLIC" -c "GRANT USAGE ON SCHEMA public TO r6t_runtime" >/dev/null
BEFORE="$(cd "$APP/bootstrap/cache" && ls -la --time-style=+%s | sha256sum)"
MIGCACHE=/run/f1bmig-cache; install -d -o "$MIG" -g "$MIG" -m 0700 "$MIGCACHE"
BASE_ENV=(PATH=/usr/local/bin:/usr/bin:/bin HOME=/nonexistent APP_ENV=production LOG_CHANNEL=stderr CACHE_STORE=array
          DB_CONNECTION=pgsql_migrate DB_MIGRATE_SOCKET_DIR="$SOCK" DB_MIGRATE_PORT="$PORT" DB_MIGRATE_DATABASE=r6t_db
          DB_MIGRATE_USERNAME=r6t_migrator DB_MIGRATE_OWNER_ROLE=r6t_owner)
ONLY_CONFIG=(APP_CONFIG_CACHE=/nonexistent/config.php)
REDIRECT=(APP_CONFIG_CACHE="$MIGCACHE/config.php" APP_SERVICES_CACHE="$MIGCACHE/services.php" APP_PACKAGES_CACHE="$MIGCACHE/packages.php"
          APP_ROUTES_CACHE="$MIGCACHE/routes.php" APP_EVENTS_CACHE="$MIGCACHE/events.php")
asmig() { (cd "$APP" && sudo -n -u "$MIG" env -i "$@"); }
if OUT="$(asmig "${BASE_ENV[@]}" "${ONLY_CONFIG[@]}" php artisan migrate:status --database=pgsql_migrate --no-interaction 2>&1)"; then
  note "F1B-10a with only APP_CONFIG_CACHE redirected the migration account still boots (manifests not needed here)"
else
  note "F1B-10a with only APP_CONFIG_CACHE redirected it fails under 2770: $(grep -m1 -oE '(bootstrap/cache[^.]*|must be present and writable|Permission denied)' <<<"$OUT" | head -1) — every cache path must be redirected"
fi
asmig "${BASE_ENV[@]}" "${REDIRECT[@]}" php artisan migrate --database=pgsql_migrate --force --no-interaction >/dev/null 2>"$ROOT/mig.err" \
  && pass "F1B-10 the migration account migrates through pgsql_migrate with every cache path redirected, no APP_KEY, no runtime password" \
  || fail "F1B-10 the migration failed: $(head -c 300 "$ROOT/mig.err")"
ENVNAMES="$(asmig "${BASE_ENV[@]}" "${REDIRECT[@]}" /usr/bin/env | cut -d= -f1 | tr '\n' ' ')"
grep -qwE 'APP_KEY|DB_PASSWORD' <<<"$ENVNAMES" && fail "F1B-10 a runtime secret name reached the migration process" || pass "F1B-10 no APP_KEY or DB_PASSWORD in the migration process"
grep -rq "$SECRET" "$MIGCACHE" 2>/dev/null && fail "F1B-10 the migration account's own cache holds the runtime secret" || pass "F1B-10 the migration account's redirected cache holds no runtime secret"
AFTER="$(cd "$APP/bootstrap/cache" && ls -la --time-style=+%s | sha256sum)"
[ "$BEFORE" = "$AFTER" ] && pass "F1B-10 nothing was written in bootstrap/cache by the migration" || fail "F1B-10 bootstrap/cache changed during the migration"
note "F1B-10 migrations recorded: $("${SU[@]}" -d r6t_db -At -c 'select count(*) from public.migrations')"

echo "== the repository's isolation gate against a synthetic identity (F1B-3/4 component level, F1B-11/12/13)"
sed -e "s|^DMS_RUNTIME_USER=.*|DMS_RUNTIME_USER=$RT|" -e "s|^DMS_RUNTIME_GROUP=.*|DMS_RUNTIME_GROUP=$RT|" \
    -e "s|^DMS_APP_DIR=.*|DMS_APP_DIR=$APP|" \
    -e "s|^DMS_FORBIDDEN_RUNTIME_USERS=.*|DMS_FORBIDDEN_RUNTIME_USERS=\"root www-data nobody daemon postgres $CO $MIG\"|" \
    "$APP/deploy/runtime-identity.conf" > "$ROOT/identity.conf"
gate() { bash "$APP/scripts/verify-runtime-isolation.sh" --app-dir "$APP" --identity-file "$ROOT/identity.conf" \
           --fpm-pool-dir "$ROOT/none" --nginx-site "$ROOT/none" --systemd-dir "$ROOT/none" 2>&1; }
G="$(gate)"
grep -q "'$CO' cannot read bootstrap/cache" <<<"$G" && pass "F1B-11 the gate proves the co-tenant stand-in cannot read bootstrap/cache" || fail "F1B-11 gate line missing: $(grep -m1 "bootstrap/cache" <<<"$G")"
grep -q "'$MIG' cannot read bootstrap/cache" <<<"$G" && pass "F1B-9 the gate proves the migration account cannot read bootstrap/cache (when it is listed as forbidden)" || fail "F1B-9 gate line for $MIG missing"
denied "$CO" "$CFG" && pass "F1B-11 the co-tenant stand-in cannot reach config.php" || fail "F1B-11 the co-tenant stand-in reaches config.php"
grep -q "has no foreign member" <<<"$G" && note "F1B-12 baseline: the gate reports no foreign group member"

echo "== F1B-12 an account whose PRIMARY group is the runtime group"
useradd --system --shell /usr/sbin/nologin --home-dir /nonexistent --no-create-home -g "$RT" "$PRIM"
reads_secret "$PRIM" "$CFG" && note "F1B-12 an account with the runtime group as its primary group reads the secret (expected: group read)" || note "F1B-12 the primary-group account cannot read the cache"
G="$(gate)"
if grep -q "has no foreign member" <<<"$G" && ! grep -q "$PRIM" <<<"$G"; then
  gap "F1B-12 the isolation gate does NOT detect an account whose primary group is the runtime group (it reads only the supplementary member list); R6-B4 must scan getent passwd for the runtime gid"
else
  pass "F1B-12 the isolation gate detects the primary-group account"
fi
userdel "$PRIM"

echo "== F1B-13 ACLs: traverse-only on the directory plus read on the file"
setfacl -m "u:$CO:--x" "$APP/bootstrap/cache"; setfacl -m "u:$CO:r--" "$CFG"
reads_secret "$CO" "$CFG" && note "F1B-13 with the two ACL entries the co-tenant stand-in READS the secret by path" || note "F1B-13 the ACL entries did not expose the file"
G="$(gate)"
if reads_secret "$CO" "$CFG" && grep -q "'$CO' cannot read bootstrap/cache" <<<"$G"; then
  gap "F1B-13 the isolation gate reports 'cannot read bootstrap/cache' (test -r on the directory) while the secret is readable through an ACL; R6-B4 must check getfacl on bootstrap/cache and its files and test the file itself"
else
  pass "F1B-13 the isolation gate detects the ACL exposure"
fi
normalize >/dev/null
reads_secret "$CO" "$CFG" && gap "F1B-13 the deploy normalization does not remove a named ACL (chmod keeps ACL entries)" || pass "F1B-13 normalization removed the exposure"
setfacl -b "$APP/bootstrap/cache" "$CFG"

echo "== F1B-14 persistence after all of the above"
[ "$(stat -c '%U:%G %a' "$APP/bootstrap/cache")" = "$RT:$RT 2770" ] && [ "$(stat -c '%U:%G %a' "$CFG")" = "$RT:$RT 640" ] \
  && pass "F1B-14 bootstrap/cache $RT:$RT 2770 and config.php $RT:$RT 0640 at the end" || fail "F1B-14 final: $(stat -c '%U:%G %a' "$APP/bootstrap/cache") / $(stat -c '%U:%G %a' "$CFG")"
note "F1B-3 (a full deploy-runner pass) and F1B-4 (the whole rollback script) are NOT RUN here: only their cache steps (dms_privsep_as_runtime config:cache, repair + normalize) are exercised above"

echo "== teardown"
runuser -u postgres -- "$BIN/pg_ctl" -D "$PGD" -m immediate stop >/dev/null 2>&1 || true
echo "GAPS=$GAPS"
echo "RESULT=$([ $RES = 0 ] && echo PASS || echo FAIL)"
exit $RES
