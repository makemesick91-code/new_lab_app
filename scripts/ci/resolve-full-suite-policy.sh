#!/usr/bin/env bash
#
# CI-TEMP-FULL-SUITE-SCHEDULE-GATE — GLOBAL TEMPORARY FULL-SUITE POLICY resolver.
#
# Answers one question deterministically, for one CI run:
#
#     is the NSF-R011 Full Suite AUTHORISED to execute for this event?
#
# While the policy is ACTIVE the two AUTOMATIC paths — the weekly `schedule` and
# the post-merge `push` to the base branch (a squash-merge IS such a push) — are
# DEFERRED, and only an explicitly authorised `workflow_dispatch` may run the
# suite. The gate is deferred, never deleted: the consolidated final closure
# still runs it on the frozen final SHA.
#
# SAFETY CONTRACT — FAIL CLOSED:
#   Any uncertainty (missing file, unreadable file, unknown/blank status, more
#   than one status token) resolves to POLICY ACTIVE, i.e. the Full Suite is NOT
#   authorised. Failing closed here means "do not automatically start an
#   expensive integrated suite", which is always the safe direction: it can
#   never hide a failure, only defer a run.
#
#   This script NEVER runs tests and NEVER mutates anything. It reads one JSON
#   file and prints a decision.
#
# Canonical policy state : .github/ci-policy/full-suite-policy.json
# Canonical document     : docs/governance/global-temporary-full-suite-policy.md
#
# Output: parseable `key=value` lines on stdout; human summary on stderr.
#   temporary_full_suite_policy_active : true | false
#   full_suite_authorized              : true | false
#   full_suite_defer_reason            : machine-readable reason code
#   policy_status                      : ACTIVE | RETIRED | UNRESOLVED
#   policy_source                      : path read, or 'fail-closed-default'
#
# Usage:
#   scripts/ci/resolve-full-suite-policy.sh --event schedule
#   scripts/ci/resolve-full-suite-policy.sh --event push --ref refs/heads/<base> --base-branch <base>
#   scripts/ci/resolve-full-suite-policy.sh --event workflow_dispatch \
#       --dispatch-run-full-suite true --dispatch-override true
#   ... [--policy-file PATH] [--json] [--github-output]

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"

EVENT=""
REF=""
BASE_BRANCH=""
DISPATCH_RUN_FULL_SUITE="false"
DISPATCH_OVERRIDE="false"
POLICY_FILE="${REPO_ROOT}/.github/ci-policy/full-suite-policy.json"
JSON_OUTPUT=false
GITHUB_OUTPUT_MODE=false

for ((i = 1; i <= $#; i++)); do
    arg="${!i}"
    case "$arg" in
        --event)          j=$((i + 1)); EVENT="${!j:-}"; i=$j ;;
        --event=*)        EVENT="${arg#*=}" ;;
        --ref)            j=$((i + 1)); REF="${!j:-}"; i=$j ;;
        --ref=*)          REF="${arg#*=}" ;;
        --base-branch)    j=$((i + 1)); BASE_BRANCH="${!j:-}"; i=$j ;;
        --base-branch=*)  BASE_BRANCH="${arg#*=}" ;;
        --dispatch-run-full-suite)   j=$((i + 1)); DISPATCH_RUN_FULL_SUITE="${!j:-false}"; i=$j ;;
        --dispatch-run-full-suite=*) DISPATCH_RUN_FULL_SUITE="${arg#*=}" ;;
        --dispatch-override)         j=$((i + 1)); DISPATCH_OVERRIDE="${!j:-false}"; i=$j ;;
        --dispatch-override=*)       DISPATCH_OVERRIDE="${arg#*=}" ;;
        --policy-file)    j=$((i + 1)); POLICY_FILE="${!j:-}"; i=$j ;;
        --policy-file=*)  POLICY_FILE="${arg#*=}" ;;
        --json)           JSON_OUTPUT=true ;;
        --github-output)  GITHUB_OUTPUT_MODE=true ;;
        *) : ;;
    esac
done

# GitHub renders an unchecked boolean input as the literal string 'false'; a
# blank/unset value must never be read as consent.
normalise_bool() {
    case "$(printf '%s' "${1:-}" | tr '[:upper:]' '[:lower:]' | tr -d '[:space:]')" in
        true|1|yes|on) echo "true" ;;
        *)             echo "false" ;;
    esac
}

DISPATCH_RUN_FULL_SUITE="$(normalise_bool "$DISPATCH_RUN_FULL_SUITE")"
DISPATCH_OVERRIDE="$(normalise_bool "$DISPATCH_OVERRIDE")"

# ---------------------------------------------------------------------------
# Resolve the canonical policy status. FAIL CLOSED to ACTIVE.
# ---------------------------------------------------------------------------
POLICY_STATUS="UNRESOLVED"
POLICY_SOURCE="fail-closed-default"

if [[ -n "$POLICY_FILE" && -r "$POLICY_FILE" ]]; then
    # Read only the top-level "status" key. Restricting to ACTIVE|RETIRED means a
    # corrupted or creatively-edited value can never be read as RETIRED.
    STATUS_TOKENS="$(grep -oE '"status"[[:space:]]*:[[:space:]]*"(ACTIVE|RETIRED)"' "$POLICY_FILE" 2>/dev/null \
        | grep -oE '(ACTIVE|RETIRED)"$' | tr -d '"' || true)"
    STATUS_COUNT="$(printf '%s' "$STATUS_TOKENS" | grep -c . || true)"

    if [[ "$STATUS_COUNT" == "1" ]]; then
        POLICY_STATUS="$(printf '%s' "$STATUS_TOKENS" | tr -d '[:space:]')"
        POLICY_SOURCE="$POLICY_FILE"
    fi
fi

if [[ "$POLICY_STATUS" == "RETIRED" ]]; then
    POLICY_ACTIVE=false
else
    # ACTIVE, or UNRESOLVED -> fail closed to ACTIVE.
    POLICY_ACTIVE=true
fi

# ---------------------------------------------------------------------------
# Decide authorisation for THIS event.
# ---------------------------------------------------------------------------
AUTHORIZED=false
REASON=""

is_base_push() {
    [[ "$EVENT" == "push" ]] || return 1
    # No base branch supplied -> cannot prove it is the base; treat as a base
    # push so the policy still applies (fail closed, never widens).
    [[ -z "$BASE_BRANCH" ]] && return 0
    [[ "$REF" == "refs/heads/${BASE_BRANCH}" ]]
}

if [[ "$POLICY_STATUS" == "UNRESOLVED" ]]; then
    AUTHORIZED=false
    REASON="POLICY_STATE_UNRESOLVED_FAIL_CLOSED"
elif [[ "$POLICY_ACTIVE" == true ]]; then
    case "$EVENT" in
        schedule)
            REASON="TEMPORARY_FULL_SUITE_POLICY_ACTIVE" ;;
        push)
            if is_base_push; then
                REASON="TEMPORARY_FULL_SUITE_POLICY_ACTIVE"
            else
                REASON="FULL_SUITE_NOT_ENABLED_FOR_EVENT"
            fi ;;
        workflow_dispatch)
            if [[ "$DISPATCH_RUN_FULL_SUITE" != "true" ]]; then
                REASON="FULL_SUITE_NOT_REQUESTED"
            elif [[ "$DISPATCH_OVERRIDE" != "true" ]]; then
                # Deliberately requested, but not explicitly authorised against
                # the ACTIVE policy. Deferred, not silently ignored.
                REASON="TEMPORARY_FULL_SUITE_POLICY_ACTIVE_OVERRIDE_REQUIRED"
            else
                AUTHORIZED=true
                REASON="AUTHORISED_CONSOLIDATED_FULL_SUITE"
            fi ;;
        *)
            REASON="FULL_SUITE_NOT_ENABLED_FOR_EVENT" ;;
    esac
else
    # Policy RETIRED — restore the pre-policy cadence exactly.
    case "$EVENT" in
        schedule)
            AUTHORIZED=true; REASON="POLICY_RETIRED_SCHEDULED_RUN" ;;
        push)
            if is_base_push; then
                AUTHORIZED=true; REASON="POLICY_RETIRED_PUSH_TO_BASE"
            else
                REASON="FULL_SUITE_NOT_ENABLED_FOR_EVENT"
            fi ;;
        workflow_dispatch)
            if [[ "$DISPATCH_RUN_FULL_SUITE" == "true" ]]; then
                AUTHORIZED=true; REASON="POLICY_RETIRED_MANUAL_DISPATCH"
            else
                REASON="FULL_SUITE_NOT_REQUESTED"
            fi ;;
        *)
            REASON="FULL_SUITE_NOT_ENABLED_FOR_EVENT" ;;
    esac
fi

emit_kv() {
    echo "temporary_full_suite_policy_active=${POLICY_ACTIVE}"
    echo "full_suite_authorized=${AUTHORIZED}"
    echo "full_suite_defer_reason=${REASON}"
    echo "policy_status=${POLICY_STATUS}"
    echo "policy_source=${POLICY_SOURCE}"
}

{
    echo "GLOBAL TEMPORARY FULL-SUITE POLICY — resolution"
    echo "  event                    : ${EVENT:-<none>}"
    echo "  ref                      : ${REF:-<none>}"
    echo "  policy status            : ${POLICY_STATUS}"
    echo "  policy source            : ${POLICY_SOURCE}"
    echo "  full suite authorised    : ${AUTHORIZED}"
    echo "  reason                   : ${REASON}"
    if [[ "$AUTHORIZED" != true ]]; then
        echo "  NOTE: the Full Suite is DEFERRED, not removed. It is not being skipped"
        echo "        because it is unnecessary — it runs once, on the frozen final SHA."
    fi
} >&2

if [[ "$JSON_OUTPUT" == true ]]; then
    cat <<JSON
{
  "temporary_full_suite_policy_active": ${POLICY_ACTIVE},
  "full_suite_authorized": ${AUTHORIZED},
  "full_suite_defer_reason": "${REASON}",
  "policy_status": "${POLICY_STATUS}",
  "policy_source": "${POLICY_SOURCE}",
  "event": "${EVENT}"
}
JSON
else
    emit_kv
fi

if [[ "$GITHUB_OUTPUT_MODE" == true && -n "${GITHUB_OUTPUT:-}" ]]; then
    emit_kv >> "$GITHUB_OUTPUT"
fi
