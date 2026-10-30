#!/usr/bin/env bash
#
# CI-PERF-04-CONTROLLED-PARALLEL-TESTING — parallel worker database provisioning.
#
# Laravel's parallel testing gives every ParaTest worker its own database named
# `<DB_DATABASE>_test_<token>` (token 1..N) and migrates it with migrate:fresh on
# the worker's first test. On a database server that already holds those worker
# databases from an earlier run, the first migrate:fresh issues ONE `DROP TABLE`
# over ~190 tables per worker; N workers doing that within milliseconds of each
# other exhaust PostgreSQL's shared lock table (SQLSTATE 53200, "out of shared
# memory") and every later test in the failed worker cascades (CI-PERF-03 finding 2,
# proven with the DDL log). `--recreate-databases` did not help on this stack.
#
# Mitigation proven in CI-PERF-03: drop the N worker databases explicitly BEFORE the
# run, so every worker starts on a database that does not exist yet. On the GitHub
# service container (fresh per job) this is a no-op; on a persistent PostgreSQL —
# a developer machine or the self-hosted runner — it is what keeps the run green.
#
# SAFETY: drops ONLY databases named exactly `<DB_DATABASE>_test_<1..N>`, and only
# when APP_ENV=testing. It runs after `ci:assert-non-production-database` in the
# workflow. It never touches DB_DATABASE itself.
#
# Usage: [CI_PHP_RUNNER="bash scripts/ci/self-hosted-php.sh php"] scripts/ci/prepare-parallel-test-databases.sh <workers>

set -euo pipefail

WORKERS="${1:-}"
if ! [[ "$WORKERS" =~ ^[1-9][0-9]*$ ]] || [[ "$WORKERS" -gt 16 ]]; then
    echo "::error::prepare-parallel-test-databases: worker count must be an integer 1..16 (got '${WORKERS}')" >&2
    exit 2
fi
if [[ "${APP_ENV:-}" != "testing" ]]; then
    echo "::error::prepare-parallel-test-databases: refusing to run with APP_ENV='${APP_ENV:-<unset>}' (testing required)" >&2
    exit 2
fi
if ! [[ "${DB_DATABASE:-}" =~ ^[A-Za-z0-9_]{1,40}$ ]]; then
    echo "::error::prepare-parallel-test-databases: DB_DATABASE missing or not a plain identifier" >&2
    exit 2
fi
if [[ "${DB_CONNECTION:-}" != "pgsql" ]]; then
    echo "prepare-parallel-test-databases: DB_CONNECTION='${DB_CONNECTION:-<unset>}' is not pgsql — nothing to provision."
    exit 0
fi

# The self-hosted variant runs PHP inside the authoritative CI runtime; it passes
# that wrapper as CI_PHP_RUNNER (e.g. "bash scripts/ci/self-hosted-php.sh php").
read -r -a PHP_CMD <<< "${CI_PHP_RUNNER:-php}"

"${PHP_CMD[@]}" -r '
$base = getenv("DB_DATABASE");
$n = (int) $argv[1];
$dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST") ?: "127.0.0.1", getenv("DB_PORT") ?: "5432", $base);
$pdo = new PDO($dsn, getenv("DB_USERNAME") ?: "", getenv("DB_PASSWORD") ?: "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$existing = $pdo->query("select datname from pg_database")->fetchAll(PDO::FETCH_COLUMN);
$dropped = [];
for ($i = 1; $i <= $n; $i++) {
    $name = $base . "_test_" . $i;
    if (in_array($name, $existing, true)) {
        $pdo->exec("DROP DATABASE IF EXISTS \"" . $name . "\" WITH (FORCE)");
        $dropped[] = $name;
    }
}
printf("parallel_worker_databases=%d dropped_preexisting=%d%s\n", $n, count($dropped), $dropped ? " (" . implode(",", $dropped) . ")" : "");
' "$WORKERS"
