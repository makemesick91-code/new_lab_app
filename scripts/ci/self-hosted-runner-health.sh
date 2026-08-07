#!/usr/bin/env bash
#
# CICD-CTRL-3 — self-hosted CI runner health check.
#
# Read-only pre-flight for the dedicated DaengtisiaMS runner. It answers one
# question: is this machine safe and capable enough to run a heavy CI job right
# now? It prints GO or NO-GO and exits non-zero on NO-GO.
#
# It NEVER prints a secret, a password, a token, or a database credential — only
# PRESENT/ABSENT posture and resource numbers.
# It NEVER mutates the machine: no install, no chmod, no delete, no service
# restart, no database write.
#
# Usage:
#   scripts/ci/self-hosted-runner-health.sh            # human readable
#   scripts/ci/self-hosted-runner-health.sh --json     # machine readable
#
# Exit codes:
#   0  GO      — runner is healthy and production-isolated
#   1  NO-GO   — at least one blocking check failed

set -euo pipefail

JSON_OUTPUT=0
for arg in "$@"; do
    case "$arg" in
        --json) JSON_OUTPUT=1 ;;
        -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

# Thresholds mirror config/ci_runner.php resource_guards. They are overridable
# by environment so a benchmark can retune them without editing this script.
MIN_FREE_DISK_GB="${CI_RUNNER_MIN_FREE_DISK_GB:-40}"
MIN_AVAILABLE_RAM_MB="${CI_RUNNER_MIN_AVAILABLE_RAM_MB:-4096}"
REQUIRED_PHP_VERSION="${CI_RUNNER_PHP_VERSION:-8.3}"
CI_DB_NAME="${CI_RUNNER_DB_NAME:-daengtisia_ci}"
CI_DB_HOST="${CI_RUNNER_DB_HOST:-127.0.0.1}"
CI_DB_PORT="${CI_RUNNER_DB_PORT:-5433}"
CI_DB_MAJOR="${CI_RUNNER_DB_MAJOR:-16}"
CI_DB_USER="${CI_RUNNER_DB_USER:-daengtisia_ci_user}"
CI_PHP_IMAGE="${CI_PHP_IMAGE:-localhost/daengtisia-ci-php:8.3}"

# Groups that are privileged or outright root-equivalent. The CI runtime user
# must be in none of them. Mirrors config/ci_runner.php
# ci_runtime.forbidden_service_user_groups.
FORBIDDEN_GROUPS="${CI_RUNNER_FORBIDDEN_GROUPS:-docker sudo lxd wheel root adm disk shadow}"

PASS_COUNT=0
FAIL_COUNT=0
WARN_COUNT=0
RESULTS=()

record() {
    # record <status> <check> <detail>
    local status="$1" check="$2" detail="$3"
    case "$status" in
        PASS) PASS_COUNT=$((PASS_COUNT + 1)) ;;
        WARN) WARN_COUNT=$((WARN_COUNT + 1)) ;;
        FAIL) FAIL_COUNT=$((FAIL_COUNT + 1)) ;;
    esac
    RESULTS+=("${status}|${check}|${detail}")
}

have() { command -v "$1" >/dev/null 2>&1; }

# ---------------------------------------------------------------------------
# 1. Identity — the runner must not execute CI as root.
# ---------------------------------------------------------------------------
CURRENT_USER="$(id -un)"
if [ "$(id -u)" -eq 0 ]; then
    record FAIL "runtime_user" "CI is running as root; the runner must run as an unprivileged service user."
else
    record PASS "runtime_user" "Running as unprivileged user: ${CURRENT_USER}"
fi

# ---------------------------------------------------------------------------
# 2. Host tooling — pre-provisioned so CI jobs never need sudo at run time.
#
# PHP, Composer and Poppler are deliberately NOT host requirements: they are
# supplied by the pinned CI image, because the host cannot provide the
# authoritative PHP version.
# ---------------------------------------------------------------------------
for tool in node npm psql git podman; do
    if have "$tool"; then
        record PASS "tool_${tool}" "$(command -v "$tool")"
    else
        record FAIL "tool_${tool}" "Required host tool '${tool}' is not installed on the runner."
    fi
done

# ---------------------------------------------------------------------------
# 2b. Privileged group membership — ALWAYS evaluated, on every host.
#
# SECURITY INVARIANT: these checks must NEVER be nested under a container
# runtime probe.
#
# A previous revision evaluated the docker-group check inside `if have podman`.
# On a host without Podman the root-equivalence finding silently disappeared:
# the gate reported NO-GO for the missing tool while saying nothing at all about
# the runtime user sitting in sudo/docker/lxd. A missing tool must never be able
# to suppress a security finding.
#
# Every group below therefore emits exactly one PASS or FAIL on every run, on
# every host, whether or not any container runtime is installed.
# ---------------------------------------------------------------------------
CURRENT_GROUPS="$(id -nG "$CURRENT_USER" 2>/dev/null | tr ' ' '\n' || true)"

for group in $FORBIDDEN_GROUPS; do
    if printf '%s\n' "$CURRENT_GROUPS" | grep -qx -- "$group"; then
        record FAIL "group_${group}" "${CURRENT_USER} is in the '${group}' group, which is privileged/root-equivalent and is forbidden for the CI runtime user."
    else
        record PASS "group_${group}" "${CURRENT_USER} is not in the '${group}' group."
    fi
done

# ---------------------------------------------------------------------------
# 2c. Container runtime posture — evaluated only when a container runtime is
# actually present. Absence is not a security finding: the security verdict is
# owned by 2b above, which always runs, and the capability verdict by the tool
# checks in section 2.
# ---------------------------------------------------------------------------
if have podman; then
    if [ "$(podman info --format '{{.Host.Security.Rootless}}' 2>/dev/null)" = "true" ]; then
        record PASS "podman_rootless" "Podman is running rootless as ${CURRENT_USER}."
    else
        record FAIL "podman_rootless" "Podman is not rootless; the CI runtime must never run rootful."
    fi

    # The authoritative PHP runtime comes from the pinned image, not the host.
    if podman image exists "$CI_PHP_IMAGE" 2>/dev/null; then
        IMAGE_PHP="$(podman run --rm "$CI_PHP_IMAGE" \
            php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo unknown)"
        if [ "$IMAGE_PHP" = "$REQUIRED_PHP_VERSION" ]; then
            record PASS "ci_runtime_php" "Authoritative CI image provides PHP ${IMAGE_PHP} (matches the GitHub-hosted gate)."
        else
            record FAIL "ci_runtime_php" "CI image provides PHP ${IMAGE_PHP}; the authoritative gate requires ${REQUIRED_PHP_VERSION}."
        fi
    else
        record FAIL "ci_runtime_php" "Authoritative CI image '${CI_PHP_IMAGE}' is not present on this runner."
    fi
fi

# ---------------------------------------------------------------------------
# 3. Resource guards — refuse heavy CI rather than thrash.
# ---------------------------------------------------------------------------
FREE_DISK_GB="$(df -BG --output=avail . 2>/dev/null | tail -1 | tr -dc '0-9' || echo 0)"
FREE_DISK_GB="${FREE_DISK_GB:-0}"
if [ "$FREE_DISK_GB" -ge "$MIN_FREE_DISK_GB" ]; then
    record PASS "disk_free" "${FREE_DISK_GB}GB free (minimum ${MIN_FREE_DISK_GB}GB)"
else
    record FAIL "disk_free" "${FREE_DISK_GB}GB free is below the ${MIN_FREE_DISK_GB}GB heavy-CI minimum."
fi

AVAILABLE_RAM_MB="$(awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)"
AVAILABLE_RAM_MB="${AVAILABLE_RAM_MB:-0}"
if [ "$AVAILABLE_RAM_MB" -ge "$MIN_AVAILABLE_RAM_MB" ]; then
    record PASS "ram_available" "${AVAILABLE_RAM_MB}MB available (minimum ${MIN_AVAILABLE_RAM_MB}MB)"
else
    record FAIL "ram_available" "${AVAILABLE_RAM_MB}MB available is below the ${MIN_AVAILABLE_RAM_MB}MB heavy-CI minimum."
fi

SWAP_USED_MB="$(awk '/SwapTotal/ {t=$2} /SwapFree/ {f=$2} END {if (t>0) print int((t-f)/1024); else print 0}' /proc/meminfo 2>/dev/null || echo 0)"
if [ "${SWAP_USED_MB:-0}" -gt 2048 ]; then
    record WARN "swap_pressure" "${SWAP_USED_MB}MB of swap in use; heavy CI may be thrashing."
else
    record PASS "swap_pressure" "${SWAP_USED_MB:-0}MB swap in use."
fi

# ---------------------------------------------------------------------------
# 4. Workspace writability.
# ---------------------------------------------------------------------------
if [ -w . ]; then
    record PASS "workspace_writable" "Workspace $(pwd) is writable."
else
    record FAIL "workspace_writable" "Workspace $(pwd) is not writable by ${CURRENT_USER}."
fi

# ---------------------------------------------------------------------------
# 5. CI database — local, reachable, and NOT production.
# ---------------------------------------------------------------------------
if have psql; then
    # `|| true` is essential: under `set -euo pipefail` a failing psql makes the
    # whole command substitution non-zero and would abort the health check
    # before it printed anything. The password comes from the job environment
    # when present; this script never stores a credential of its own.
    ACTUAL_PG="$(PGPASSWORD="${DB_PASSWORD:-}" PGCONNECT_TIMEOUT=5 \
        psql -h "$CI_DB_HOST" -p "$CI_DB_PORT" -U "${CI_DB_USER}" -d "$CI_DB_NAME" \
        -tAc 'SHOW server_version' 2>/dev/null | cut -d. -f1 | tr -d ' ' || true)"

    if [ -z "$ACTUAL_PG" ] && [ -z "${DB_PASSWORD:-}" ]; then
        # Run outside a CI job: no credential available, so this is not a fault.
        record WARN "ci_database" "CI database not probed: DB_PASSWORD is not set in this shell (expected outside a CI job)."
    elif [ -n "$ACTUAL_PG" ]; then
        record PASS "ci_database" "CI database '${CI_DB_NAME}' reachable on ${CI_DB_HOST}:${CI_DB_PORT} (PostgreSQL ${ACTUAL_PG})."
        if [ "$ACTUAL_PG" = "$CI_DB_MAJOR" ]; then
            record PASS "ci_database_version" "PostgreSQL ${ACTUAL_PG} matches the authoritative gate's major version."
        else
            record FAIL "ci_database_version" "PostgreSQL ${ACTUAL_PG} does not match the gate's required major ${CI_DB_MAJOR}; results will diverge."
        fi
    else
        record FAIL "ci_database" "CI database '${CI_DB_NAME}' is not reachable on ${CI_DB_HOST}:${CI_DB_PORT} for ${CURRENT_USER}."
    fi
fi

case "$CI_DB_NAME" in
    *pilot*|*prod*|*production*|*live*|asia_dental_lab*)
        record FAIL "ci_database_name" "CI database name '${CI_DB_NAME}' looks like a production database."
        ;;
    *)
        record PASS "ci_database_name" "CI database name '${CI_DB_NAME}' is non-production."
        ;;
esac

# PostgreSQL must not be published to the network.
if have ss; then
    if ss -tulpn 2>/dev/null | grep -E ':5432\b' | grep -qvE '127\.0\.0\.1|\[?::1\]?'; then
        record FAIL "postgres_exposure" "PostgreSQL is listening on a non-loopback address."
    else
        record PASS "postgres_exposure" "PostgreSQL is not exposed beyond loopback."
    fi
fi

# ---------------------------------------------------------------------------
# 6. Production isolation — PRESENT/ABSENT only, never contents.
# ---------------------------------------------------------------------------
PROD_ARTIFACT_FOUND=0
for artifact in \
    "${HOME}/.ssh/daengtisiams_vps_ed25519" \
    "${HOME}/.ssh/daengtisiams_deploy" \
    "${HOME}/.ssh/daengtisiams_actions_deploy" \
    "/var/www/asia-dental-lab-v2"; do
    if [ -e "$artifact" ]; then
        PROD_ARTIFACT_FOUND=1
        record FAIL "production_isolation" "Production artifact PRESENT on the runner: $(basename "$artifact")"
    fi
done
if [ "$PROD_ARTIFACT_FOUND" -eq 0 ]; then
    record PASS "production_isolation" "No production SSH key or production application path on this runner (ABSENT)."
fi

# A production environment file must never be staged in the workspace.
if [ -f .env ] && grep -qiE '^APP_ENV=(production|pilot)' .env 2>/dev/null; then
    record FAIL "workspace_env" "A production/pilot environment file is present in the workspace."
else
    record PASS "workspace_env" "No production/pilot environment file in the workspace."
fi

# ---------------------------------------------------------------------------
# 7. APP_ENV posture for the current job.
# ---------------------------------------------------------------------------
if [ -n "${APP_ENV:-}" ]; then
    if [ "${APP_ENV}" = "testing" ]; then
        record PASS "app_env" "APP_ENV=testing"
    else
        record FAIL "app_env" "APP_ENV=${APP_ENV}; CI heavy jobs must run with APP_ENV=testing."
    fi
else
    record WARN "app_env" "APP_ENV is not set in this shell; the job env must set it before tests."
fi

# ---------------------------------------------------------------------------
# Verdict.
# ---------------------------------------------------------------------------
DECISION="GO"
[ "$FAIL_COUNT" -gt 0 ] && DECISION="NO-GO"

if [ "$JSON_OUTPUT" -eq 1 ]; then
    printf '{\n  "sprint": "CICD-CTRL-3",\n  "check": "self_hosted_runner_health",\n  "decision": "%s",\n' "$DECISION"
    printf '  "hostname": "%s",\n  "runtime_user": "%s",\n' "$(hostname)" "$CURRENT_USER"
    printf '  "passed": %d,\n  "warnings": %d,\n  "failed": %d,\n  "checks": [\n' \
        "$PASS_COUNT" "$WARN_COUNT" "$FAIL_COUNT"
    first=1
    for row in "${RESULTS[@]}"; do
        status="${row%%|*}"; rest="${row#*|}"; check="${rest%%|*}"; detail="${rest#*|}"
        detail="${detail//\\/\\\\}"; detail="${detail//\"/\\\"}"
        [ "$first" -eq 0 ] && printf ',\n'
        printf '    {"status": "%s", "check": "%s", "detail": "%s"}' "$status" "$check" "$detail"
        first=0
    done
    printf '\n  ]\n}\n'
else
    echo "CICD-CTRL-3 — self-hosted runner health"
    echo "  host : $(hostname)"
    echo "  user : ${CURRENT_USER}"
    echo ""
    for row in "${RESULTS[@]}"; do
        status="${row%%|*}"; rest="${row#*|}"; check="${rest%%|*}"; detail="${rest#*|}"
        printf '  [%-4s] %-22s %s\n' "$status" "$check" "$detail"
    done
    echo ""
    echo "  passed=${PASS_COUNT} warnings=${WARN_COUNT} failed=${FAIL_COUNT}"
    echo "  DECISION: ${DECISION}"
fi

[ "$DECISION" = "GO" ] && exit 0
exit 1
