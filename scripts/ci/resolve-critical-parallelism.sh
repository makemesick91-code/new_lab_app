#!/usr/bin/env bash
#
# CI-PERF-04-CONTROLLED-PARALLEL-TESTING — Critical Gate parallelism resolver.
#
# Answers one question deterministically, for one CI run:
#
#     how many ParaTest workers does the NSF-R011 critical filter run with?
#
# SAFETY CONTRACT — FAIL TOWARD SEQUENTIAL:
#   Sequential (1 worker) is the known-good Critical Gate. Every uncertainty —
#   missing/unreadable policy, an unknown or duplicated status, an out-of-range
#   or non-numeric worker count — resolves to 1 worker with the exact
#   pre-CI-PERF-04 command line (no extra arguments at all). Failing toward
#   sequential can only make the gate slower, never weaker or different.
#
#   While the committed policy is OFF, pull_request / push / schedule ALWAYS run
#   sequentially and the repository variable is IGNORED: a variable flip alone can
#   never activate parallelism. The only parallel path while OFF is an explicit
#   evaluation `workflow_dispatch` with the `critical_test_workers` input, which is
#   never a merge gate and is labelled as an evaluation run.
#
#   This script NEVER runs tests and NEVER mutates anything. It reads one JSON file
#   and prints a decision.
#
# Canonical policy state : .github/ci-policy/critical-parallel-policy.json
# Rule mirror            : .cursor/rules/212-critical-gate-controlled-parallel-testing.mdc
#
# Output: parseable `key=value` lines on stdout; human summary on stderr.
#   critical_test_workers          : 1 | 2 | 4 (policy allowed counts only)
#   critical_parallel_mode         : sequential | parallel
#   critical_parallel_evaluation   : true | false
#   critical_parallel_reason       : machine-readable reason code
#   critical_parallel_policy_status: OFF | ACTIVE | UNRESOLVED
#   critical_test_extra_args       : arguments appended to `php artisan test`
#                                    (empty for a non-evaluation sequential run)
#
# Usage:
#   scripts/ci/resolve-critical-parallelism.sh --event pull_request [--repository-workers 4]
#   scripts/ci/resolve-critical-parallelism.sh --event workflow_dispatch --dispatch-workers 4
#   ... [--policy-file PATH] [--github-output]

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"

EVENT=""
DISPATCH_WORKERS=""
REPOSITORY_WORKERS=""
POLICY_FILE="${REPO_ROOT}/.github/ci-policy/critical-parallel-policy.json"
GITHUB_OUTPUT_MODE=false
JUNIT_PATH="storage/ci-evidence/nsf-r011-critical-junit.xml"

for ((i = 1; i <= $#; i++)); do
    arg="${!i}"
    case "$arg" in
        --event)                 j=$((i + 1)); EVENT="${!j:-}"; i=$j ;;
        --event=*)               EVENT="${arg#*=}" ;;
        --dispatch-workers)      j=$((i + 1)); DISPATCH_WORKERS="${!j:-}"; i=$j ;;
        --dispatch-workers=*)    DISPATCH_WORKERS="${arg#*=}" ;;
        --repository-workers)    j=$((i + 1)); REPOSITORY_WORKERS="${!j:-}"; i=$j ;;
        --repository-workers=*)  REPOSITORY_WORKERS="${arg#*=}" ;;
        --policy-file)           j=$((i + 1)); POLICY_FILE="${!j:-}"; i=$j ;;
        --policy-file=*)         POLICY_FILE="${arg#*=}" ;;
        --github-output)         GITHUB_OUTPUT_MODE=true ;;
        *) : ;;
    esac
done

trim() { printf '%s' "${1:-}" | tr -d '[:space:]'; }
EVENT="$(trim "$EVENT")"
DISPATCH_WORKERS="$(trim "$DISPATCH_WORKERS")"
REPOSITORY_WORKERS="$(trim "$REPOSITORY_WORKERS")"

# ---------------------------------------------------------------------------
# Read the committed policy. Anything unexpected -> UNRESOLVED -> sequential.
# ---------------------------------------------------------------------------
POLICY_STATUS="UNRESOLVED"
ALLOWED=" 1 "
MAX_WORKERS=1
DEFAULT_WORKERS=1

if [[ -n "$POLICY_FILE" && -r "$POLICY_FILE" ]]; then
    # Only the top-level "status" key, and only the two known values. A second
    # status token (e.g. a merge conflict leftover) makes the policy UNRESOLVED.
    STATUS_TOKENS="$(grep -oE '"status"[[:space:]]*:[[:space:]]*"(OFF|ACTIVE)"' "$POLICY_FILE" 2>/dev/null \
        | grep -oE '(OFF|ACTIVE)"$' | tr -d '"' || true)"
    if [[ "$(printf '%s' "$STATUS_TOKENS" | grep -c . || true)" == "1" ]]; then
        POLICY_STATUS="$(trim "$STATUS_TOKENS")"
    fi

    COUNTS="$(grep -oE '"allowed_worker_counts"[[:space:]]*:[[:space:]]*\[[0-9,[:space:]]*\]' "$POLICY_FILE" 2>/dev/null \
        | grep -oE '[0-9]+' | tr '\n' ' ' || true)"
    MAX="$(grep -oE '"max_workers"[[:space:]]*:[[:space:]]*[0-9]+' "$POLICY_FILE" 2>/dev/null | grep -oE '[0-9]+$' | head -1 || true)"
    DEF="$(grep -oE '"default_workers"[[:space:]]*:[[:space:]]*[0-9]+' "$POLICY_FILE" 2>/dev/null | grep -oE '[0-9]+$' | head -1 || true)"

    if [[ "$POLICY_STATUS" != "UNRESOLVED" && -n "$COUNTS" && "$MAX" =~ ^[0-9]+$ ]]; then
        ALLOWED=" ${COUNTS}"
        MAX_WORKERS="$MAX"
        [[ "$DEF" =~ ^[0-9]+$ ]] && DEFAULT_WORKERS="$DEF"
    else
        POLICY_STATUS="UNRESOLVED"
    fi
fi

# A worker count is valid only when it is a plain integer, listed in the policy,
# and within max_workers. 1 is always valid.
valid_workers() {
    local n="$1"
    [[ "$n" =~ ^[0-9]+$ ]] || return 1
    n=$((10#$n))
    [[ "$n" -ge 1 ]] || return 1
    [[ "$n" -eq 1 ]] && return 0
    [[ "$n" -le "$MAX_WORKERS" ]] || return 1
    [[ "$ALLOWED" == *" ${n} "* ]] || return 1
}

WORKERS=1
EVALUATION=false
REASON=""

if [[ "$EVENT" == "workflow_dispatch" && -n "$DISPATCH_WORKERS" && "$DISPATCH_WORKERS" != "repository-default" ]]; then
    if [[ "$POLICY_STATUS" == "UNRESOLVED" ]]; then
        REASON="POLICY_UNRESOLVED_SEQUENTIAL"
    elif valid_workers "$DISPATCH_WORKERS"; then
        WORKERS=$((10#$DISPATCH_WORKERS))
        EVALUATION=true
        REASON="CRITICAL_PARALLELISM_EVALUATION_DISPATCH"
    else
        REASON="INVALID_WORKER_COUNT_SEQUENTIAL"
    fi
elif [[ "$POLICY_STATUS" == "UNRESOLVED" ]]; then
    REASON="POLICY_UNRESOLVED_SEQUENTIAL"
elif [[ "$POLICY_STATUS" == "OFF" ]]; then
    # The repository variable is deliberately ignored while OFF.
    REASON="CRITICAL_PARALLELISM_OFF"
else
    CANDIDATE="${REPOSITORY_WORKERS:-$DEFAULT_WORKERS}"
    if valid_workers "$CANDIDATE"; then
        WORKERS=$((10#$CANDIDATE))
        REASON="CRITICAL_PARALLELISM_ACTIVE"
    else
        REASON="INVALID_WORKER_COUNT_SEQUENTIAL"
    fi
fi

MODE="sequential"
EXTRA_ARGS=""
if [[ "$WORKERS" -gt 1 ]]; then
    MODE="parallel"
    EXTRA_ARGS="--parallel --processes=${WORKERS}"
fi
if [[ "$EVALUATION" == true ]]; then
    EXTRA_ARGS="${EXTRA_ARGS:+${EXTRA_ARGS} }--log-junit ${JUNIT_PATH}"
fi

emit_kv() {
    echo "critical_test_workers=${WORKERS}"
    echo "critical_parallel_mode=${MODE}"
    echo "critical_parallel_evaluation=${EVALUATION}"
    echo "critical_parallel_reason=${REASON}"
    echo "critical_parallel_policy_status=${POLICY_STATUS}"
    echo "critical_test_extra_args=${EXTRA_ARGS}"
}

{
    echo "CI-PERF-04 Critical Gate parallelism — resolution"
    echo "  event              : ${EVENT:-<none>}"
    echo "  policy status      : ${POLICY_STATUS}"
    echo "  dispatch input     : ${DISPATCH_WORKERS:-<none>}"
    echo "  repository variable: ${REPOSITORY_WORKERS:-<unset>}$([[ "$POLICY_STATUS" == "OFF" && -n "$REPOSITORY_WORKERS" ]] && echo ' (IGNORED: policy OFF)')"
    echo "  workers            : ${WORKERS} (${MODE})"
    echo "  evaluation run     : ${EVALUATION}"
    echo "  reason             : ${REASON}"
} >&2

emit_kv

if [[ "$GITHUB_OUTPUT_MODE" == true && -n "${GITHUB_OUTPUT:-}" ]]; then
    emit_kv >> "$GITHUB_OUTPUT"
fi
