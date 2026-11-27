#!/usr/bin/env bash
#
# PB-7 (DMS-SPN4-READY-PILOT-1, Design Lock row R6-B7) — the PostgreSQL 16
# role-separation tests of the critical gate.
#
# Usage (CI only):  bash scripts/ci/r6-role-test-cluster.sh <test file>...
#
# The critical regression step runs the role-separation suites too, but there
# their real-PostgreSQL role scenarios SKIP: they need a cluster with the rule
# 245 role layout, which the job's database service is not. This step:
#
#   1. starts a PRIVATE, disposable PostgreSQL 16 cluster as the runner user,
#      on a Unix socket only (no TCP listener), with design role names
#      (r6t_*), peer authentication for the bootstrap superuser and the
#      migration login (both mapped to the runner OS user), scram for the
#      runtime login with a random per-run password (masked), and a database
#      owned by the superuser. Synthetic only: no production host, value,
#      credential or data; no network;
#   2. runs the given suites SERIALLY, in their own process, with
#      R6_ROLE_TEST_REQUIRED=1, so a missing or broken cluster FAILS instead of
#      skipping, and requires the run to report no skipped and no failed test;
#   3. acceptance on a FRESH database: migrated as the owner role through
#      pgsql_migrate, provisioned as R6-D would (guard search_path pinned
#      exactly, PUBLIC CONNECT/TEMPORARY revoked on every other database,
#      monitoring roles WITH INHERIT TRUE), then foundation:db-privilege-apply
#      --apply must change objects, foundation:db-privilege-check must exit 0 as
#      the runtime, a second apply must change nothing, and the forward SQL
#      must be stable (Design Lock §12.5);
#   4. removes the cluster on every exit, writes
#      storage/ci-evidence/pb7-role-separation.log (no password) and a step
#      summary.
#
# A run counts only when the JUnit log shows at least PB7_MIN_TESTS tests and
# no failure, error or skip, the summary shows no skipped, failed, incomplete,
# risky, todo or pending test, and neither suite narrows itself (->only( or
# ->todo(). The script itself is pinned by SHA-256 in
# CiTriggerRemovalGovernanceTest: any edit is a governed re-pin.
#
# Server and client alignment: on a GitHub-hosted runner the PostgreSQL 16
# server is installed from the same signed PostgreSQL apt source O-CI-1 added
# (no system cluster is created), and its version must equal the PostgreSQL 16
# client O-CI-1 selected. On the self-hosted runner (PB7_PHP_WRAPPER set) the
# host must already provide the PostgreSQL 16 server binaries and the native
# PHP runtime; nothing is installed there, and if either is missing the step
# FAILS — it never skips.
#
set -euo pipefail

[ "${GITHUB_ACTIONS:-}" = true ] || { echo "PB-7: refusing to run outside CI."; exit 2; }
[ "$#" -ge 1 ] || { echo "usage: $0 <test file>..."; exit 2; }
for f in "$@"; do
    [ -f "$f" ] || { echo "::error::PB-7: $f does not exist"; exit 1; }
done
# A suite that narrows itself would make the run look complete while most of it never ran.
if grep -nE -- '->(only|todo)\(' "$@"; then
    echo "::error::PB-7: a role-separation suite uses ->only( or ->todo(."
    exit 1
fi

# The minimum number of tests the two suites must run (237 when this was pinned).
PB7_MIN_TESTS=230
BIN=/usr/lib/postgresql/16/bin
EVIDENCE=storage/ci-evidence/pb7-role-separation.log
mkdir -p storage/ci-evidence
# Descriptor 3 is the job log only: the mask command goes there, never into the evidence file.
exec 3>&1
exec > >(tee "$EVIDENCE") 2>&1

PHP=(php)
if [ -n "${PB7_PHP_WRAPPER:-}" ]; then
    runtime="$(bash "$PB7_PHP_WRAPPER" --print-runtime)"
    printf '%s\n' "$runtime"
    grep -qx 'runtime_mode=native' <<< "$runtime" \
        || { echo "::error::PB-7 needs the native PHP runtime on the self-hosted runner (the private cluster's socket is not shared with a container)."; exit 1; }
    PHP=(bash "$PB7_PHP_WRAPPER" php)
fi

if [ ! -x "$BIN/initdb" ]; then
    if [ -n "${PB7_PHP_WRAPPER:-}" ]; then
        echo "::error::PB-7: the self-hosted runner has no PostgreSQL 16 server binaries in $BIN. Nothing is installed there; the step fails closed."
        exit 1
    fi
    # GitHub-hosted: the PostgreSQL apt source and its pinned key come from O-CI-1, and
    # the server is installed at exactly the client's version.
    grep -Eq '^ +[0-9]+ https?://apt\.postgresql\.org/pub/repos/apt ' <<< "$(apt-cache policy postgresql-16)" \
        || { echo "::error::PB-7: postgresql-16 is not offered by the signed PostgreSQL apt source (O-CI-1)."; exit 1; }
    CLIENT_PKG="$(dpkg-query -W -f='${Version}' postgresql-client-16)"
    sudo mkdir -p /etc/postgresql-common/createcluster.d
    echo 'create_main_cluster = false' | sudo tee /etc/postgresql-common/createcluster.d/pb7-no-main-cluster.conf >/dev/null
    sudo apt-get install -y --no-install-recommends "postgresql-16=${CLIENT_PKG}"
fi
SERVER="$("$BIN/postgres" --version)"
CLIENT="$("$BIN/psql" --version)"
echo "pb7_server=${SERVER}"
echo "pb7_client=${CLIENT}"
grep -Eq '\(PostgreSQL\) 16\.[0-9]+' <<< "$SERVER" || { echo "::error::PB-7: the server is not PostgreSQL 16."; exit 1; }
[ "${SERVER##* }" = "${CLIENT##* }" ] || { echo "::error::PB-7: server ${SERVER##* } and client ${CLIENT##* } differ."; exit 1; }

# A short base: a Unix socket path is limited to 107 bytes.
BASE="$(mktemp -d /tmp/r6t.XXXXXX)"
DATA="$BASE/d"
SOCK="$BASE/s"
PORT=55432
OSUSER="$(id -un)"
[ $(( ${#SOCK} + 18 )) -le 107 ] || { echo "::error::PB-7: the socket path is too long."; exit 1; }
mkdir -p "$SOCK"
cleanup() {
    "$BIN/pg_ctl" -D "$DATA" -m immediate stop >/dev/null 2>&1 || true
    rm -rf "$BASE"
}
trap cleanup EXIT
RT_PW="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)"
echo "::add-mask::${RT_PW}" >&3

"$BIN/initdb" -D "$DATA" -U pgboot --auth-local=trust --auth-host=reject >/dev/null
"$BIN/pg_ctl" -D "$DATA" -l "$BASE/postgres.log" -o "-k $SOCK -c listen_addresses='' -p $PORT" -w start >/dev/null \
    || { echo "::error::PB-7: the role-test cluster did not start."; tail -20 "$BASE/postgres.log" || true; exit 1; }
PSQL=("$BIN/psql" -X -q -v ON_ERROR_STOP=1 -h "$SOCK" -p "$PORT" -U pgboot)
"${PSQL[@]}" -d postgres <<SQL
CREATE ROLE r6t_owner NOLOGIN;
CREATE ROLE r6t_migrator LOGIN;
GRANT r6t_owner TO r6t_migrator WITH INHERIT FALSE, SET TRUE;
CREATE ROLE r6t_other NOLOGIN;
CREATE ROLE r6t_runtime LOGIN PASSWORD '${RT_PW}';
CREATE DATABASE r6t_db;
REVOKE CREATE, TEMPORARY ON DATABASE r6t_db FROM PUBLIC;
GRANT CONNECT ON DATABASE r6t_db TO r6t_migrator, r6t_runtime;
SQL
provision_schema() { # $1 = database
    "${PSQL[@]}" -d "$1" <<'SQL'
ALTER SCHEMA public OWNER TO r6t_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO r6t_runtime;
ALTER DEFAULT PRIVILEGES FOR ROLE r6t_owner IN SCHEMA public GRANT SELECT ON TABLES TO r6t_runtime;
SQL
}
provision_schema r6t_db
printf 'boot %s pgboot\nmig %s r6t_migrator\n' "$OSUSER" "$OSUSER" > "$DATA/pg_ident.conf"
printf 'local all pgboot peer map=boot\nlocal all r6t_migrator peer map=mig\nlocal all r6t_runtime scram-sha-256\nlocal all all reject\n' > "$DATA/pg_hba.conf"
"$BIN/pg_ctl" -D "$DATA" reload >/dev/null
sleep 1
[ -z "$("${PSQL[@]}" -d postgres -At -c 'show listen_addresses')" ] || { echo "::error::PB-7: the role-test cluster listens on TCP."; exit 1; }

echo "== PB-7: the role-separation suites, serially, with no skip allowed"
set +e
(
    export R6_ROLE_TEST_REQUIRED=1 R6_ROLE_TEST_SOCKET_DIR="$SOCK" R6_ROLE_TEST_PORT="$PORT" R6_ROLE_TEST_DATABASE=r6t_db \
        R6_ROLE_TEST_MIGRATOR=r6t_migrator R6_ROLE_TEST_OWNER=r6t_owner R6_ROLE_TEST_NON_MEMBER=r6t_other \
        R6_ROLE_TEST_RUNTIME=r6t_runtime R6_ROLE_TEST_RUNTIME_PASSWORD="$RT_PW" R6_ROLE_TEST_SUPERUSER=pgboot
    "${PHP[@]}" artisan test --display-skipped --log-junit "$BASE/junit.xml" "$@"
) | tee "$BASE/pest.log"
TEST_STATUS="${PIPESTATUS[0]}"
set -e
echo "pb7_test_exit_status=${TEST_STATUS}"
[ "$TEST_STATUS" = 0 ] || { echo "::error::PB-7: the role-separation suites failed (exit ${TEST_STATUS})."; exit 1; }
SUMMARY="$(grep -E '^ *Tests: ' "$BASE/pest.log" | tail -1 | sed 's/^ *//' || true)"
echo "pb7_test_summary=${SUMMARY}"
[ -n "$SUMMARY" ] || { echo "::error::PB-7: the test run printed no summary."; exit 1; }
if grep -Eqi 'skipped|failed|incomplete|risky|todo|pending' <<< "$SUMMARY"; then
    echo "::error::PB-7: the role-separation suites must run every test: ${SUMMARY}"
    exit 1
fi
COUNTS="$("${PHP[@]}" -r '$x = @simplexml_load_file($argv[1]); if (! $x) { exit(1); } $s = $x->testsuite[0] ?? $x; $a = $s->attributes(); echo (int) $a["tests"], " ", (int) $a["failures"], " ", (int) $a["errors"], " ", (int) $a["skipped"];' "$BASE/junit.xml" || true)"
echo "pb7_junit_tests_failures_errors_skipped=${COUNTS}"
read -r J_TESTS J_FAIL J_ERR J_SKIP <<< "${COUNTS:-0 1 1 1}"
if [ "${J_TESTS:-0}" -lt "$PB7_MIN_TESTS" ] || [ "${J_FAIL:-1}" != 0 ] || [ "${J_ERR:-1}" != 0 ] || [ "${J_SKIP:-1}" != 0 ]; then
    echo "::error::PB-7: the JUnit log must show at least ${PB7_MIN_TESTS} tests with no failure, error or skip (got: ${COUNTS:-none})."
    exit 1
fi

echo "== PB-7: acceptance on a fresh database, as R6-D and the deploy would do it"
"${PSQL[@]}" -d postgres <<'SQL'
REVOKE CONNECT, TEMPORARY ON DATABASE postgres FROM PUBLIC;
REVOKE CONNECT, TEMPORARY ON DATABASE template1 FROM PUBLIC;
REVOKE CONNECT ON DATABASE r6t_db FROM PUBLIC, r6t_runtime;
CREATE DATABASE r6t_accept;
REVOKE CREATE, TEMPORARY ON DATABASE r6t_accept FROM PUBLIC;
GRANT CONNECT ON DATABASE r6t_accept TO r6t_migrator, r6t_runtime;
GRANT pg_read_all_stats, pg_read_all_settings TO r6t_runtime WITH INHERIT TRUE;
SQL
provision_schema r6t_accept
run_as() { # an environment for the acceptance database, then the artisan arguments
    (
        export DB_CONNECTION=pgsql DB_HOST="$SOCK" DB_PORT="$PORT" DB_DATABASE=r6t_accept DB_USERNAME=r6t_runtime DB_PASSWORD="$RT_PW" \
            DB_SSLMODE=disable DB_MIGRATE_SOCKET_DIR="$SOCK" DB_MIGRATE_PORT="$PORT" DB_MIGRATE_DATABASE=r6t_accept \
            DB_MIGRATE_USERNAME=r6t_migrator DB_MIGRATE_OWNER_ROLE=r6t_owner CACHE_STORE=array
        "${PHP[@]}" artisan "$@"
    )
}
run_as migrate --database=pgsql_migrate --force
# The provisioning step R6-D carries: every guard function's search_path, exactly.
for fn in $("${PSQL[@]}" -d r6t_accept -At -c "select distinct p.oid::regprocedure from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_namespace n on n.oid = c.relnamespace join pg_proc p on p.oid = t.tgfoid where n.nspname = 'public' and not t.tgisinternal"); do
    "${PSQL[@]}" -d r6t_accept -c "ALTER FUNCTION ${fn} SET search_path = pg_catalog, pg_temp" >/dev/null
done
FIRST="$(run_as foundation:db-privilege-apply --apply)"
echo "$FIRST"
grep -Eq 'APPLIED: [1-9][0-9]* statement' <<< "$FIRST" || { echo "::error::PB-7: the first apply on a fresh database changed nothing."; exit 1; }
run_as foundation:db-privilege-check
SECOND="$(run_as foundation:db-privilege-apply --apply)"
echo "$SECOND"
grep -q 'APPLIED: 0 statement(s) for 0 object(s)' <<< "$SECOND" || { echo "::error::PB-7: a second apply was not a no-op."; exit 1; }
H1="$(run_as foundation:db-privilege-apply --print-sql | sha256sum | cut -d' ' -f1)"
H2="$(run_as foundation:db-privilege-apply --print-sql | sha256sum | cut -d' ' -f1)"
[ "$H1" = "$H2" ] || { echo "::error::PB-7: the forward SQL is not stable."; exit 1; }
echo "pb7_forward_sql_stable=true"
echo "PB-7 ROLE SEPARATION: GO"
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
    {
        echo "### PB-7 role separation: GO"
        echo "- server: ${SERVER}; client: ${CLIENT}"
        echo "- suites: ${SUMMARY}"
        echo "- JUnit tests/failures/errors/skipped: ${COUNTS}"
        echo "- fresh-database acceptance: first apply changed objects; check MATCH; second apply 0 statements; forward SQL stable"
    } >> "$GITHUB_STEP_SUMMARY"
fi
