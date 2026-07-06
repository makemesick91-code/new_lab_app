#!/usr/bin/env bash
# NSF-7 — Foundation evidence gates (local + GitHub Actions).
# Safe: read-only governance commands; no destructive DB operations.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

EVIDENCE_DIR="${CI_EVIDENCE_DIR:-storage/ci-evidence}"
mkdir -p "$EVIDENCE_DIR"

CRITICAL_ONLY=false
QUALITY_ONLY=false

for arg in "$@"; do
    case "$arg" in
        --critical-only) CRITICAL_ONLY=true ;;
        --quality-only) QUALITY_ONLY=true ;;
    esac
done

mask_env() {
    env | grep -E '^(APP_|DB_|CACHE_|QUEUE_|SESSION_|MAIL_|FILESYSTEM_)' \
        | sed -E 's/(PASSWORD|SECRET|KEY)=.*/\1=***MASKED***/' || true
}

section() {
    echo ""
    echo "=== $1 ==="
}

run_quality_gate() {
    section "NSF-R012 Quality Gate"
    {
        echo "NSF-R012 quality gate"
        echo "generated_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        mask_env
        echo ""
        echo "--- npm run build ---"
        npm run build
        echo ""
        echo "--- pint --test ---"
        ./vendor/bin/pint --test
        echo ""
        echo "--- git diff --check ---"
        git diff --check
    } 2>&1 | tee "$EVIDENCE_DIR/nsf-r012-build-pint.txt"
}

run_critical_governance() {
    section "DQ + Foundation Governance Audits"
    {
        echo "DQ + foundation governance audits"
        echo "generated_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        mask_env
        echo ""
        echo "--- data-quality:dq1-audit --fail-on=error ---"
        php artisan data-quality:dq1-audit --fail-on=error
        echo ""
        echo "--- inventory:batch-governance-audit --fail-on=error ---"
        php artisan inventory:batch-governance-audit --fail-on=error
        echo ""
        echo "--- inventory:source-document-batch-audit --fail-on=error ---"
        php artisan inventory:source-document-batch-audit --fail-on=error
        echo ""
        echo "--- inventory:ambiguous-batch-review-pack ---"
        php artisan inventory:ambiguous-batch-review-pack
        echo ""
        echo "--- architecture:foundation-governance-summary ---"
        php artisan architecture:foundation-governance-summary
        if php artisan list --raw 2>/dev/null | grep -q '^architecture:dmo-governance-check$'; then
            echo ""
            echo "--- architecture:dmo-governance-check ---"
            php artisan architecture:dmo-governance-check
        fi
        if php artisan list --raw 2>/dev/null | grep -q '^architecture:nsf-governance-check$'; then
            echo ""
            echo "--- architecture:nsf-governance-check (CI-safe; no --include-observability) ---"
            php artisan architecture:nsf-governance-check
        fi
    } 2>&1 | tee "$EVIDENCE_DIR/dq-audits.txt"

    php artisan architecture:foundation-governance-summary --json \
        > "$EVIDENCE_DIR/foundation-summary.txt" 2>/dev/null \
        || php artisan architecture:foundation-governance-summary \
            > "$EVIDENCE_DIR/foundation-summary.txt"
}

run_release_safety() {
    section "NSF-9 Feature Flags + Release Safety + Automated Smoke"
    {
        echo "NSF-9 release safety gates"
        echo "generated_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        echo ""
        echo "--- architecture:foundation-roadmap-check ---"
        php artisan architecture:foundation-roadmap-check
        echo ""
        echo "--- foundation:feature-flags ---"
        php artisan foundation:feature-flags
        echo ""
        echo "--- foundation:cache-governance-check ---"
        php artisan foundation:cache-governance-check
        echo ""
        echo "--- foundation:queue-governance-check ---"
        php artisan foundation:queue-governance-check
        echo ""
        echo "--- foundation:idempotency-outbox-check ---"
        php artisan foundation:idempotency-outbox-check
        echo ""
        echo "--- foundation:developer-console-check ---"
        php artisan foundation:developer-console-check
        echo ""
        echo "--- foundation:idempotency-audit ---"
        php artisan foundation:idempotency-audit
        echo ""
        echo "--- foundation:outbox-audit ---"
        php artisan foundation:outbox-audit
        echo ""
        echo "--- foundation:release-safety-check ---"
        php artisan foundation:release-safety-check
        echo ""
        echo "--- release:automated-smoke (command-readiness only) ---"
        php artisan release:automated-smoke
    } 2>&1 | tee "$EVIDENCE_DIR/nsf-9-release-safety.txt"
}

run_critical_tests() {
    section "NSF-R011 Critical Regression Tests"
    php artisan test --filter='FoundationGovernance|Nsf7|NsfGovernance|DmoGovernance|Dmo3|Dq31|Dq3SourceDocumentBatch|Dq2BatchGovernance|Dq1|RmeDoctorCashierCompletionGate|RmeRoomAssignmentGate|MedicalRecordFinalization|CashierBilling|RmePayment|PatientOutstandingReceivableCarryOver|PatientCentricRmWorkspace' \
        2>&1 | tee "$EVIDENCE_DIR/nsf-r011-critical-tests.txt"
}

run_full_suite() {
    section "NSF-R011 Full Suite"
    php artisan test 2>&1 | tee "$EVIDENCE_DIR/nsf-r011-full-suite.txt"
}

if [[ "$QUALITY_ONLY" == true ]]; then
    run_quality_gate
    exit 0
fi

if [[ "$CRITICAL_ONLY" == true ]]; then
    run_critical_governance
    run_release_safety
    exit 0
fi

run_quality_gate
run_critical_tests
run_critical_governance
run_release_safety

if [[ "${RUN_FULL_SUITE:-false}" == "true" ]]; then
    run_full_suite
fi

echo ""
echo "Foundation evidence gates completed. Artifacts: $EVIDENCE_DIR"
