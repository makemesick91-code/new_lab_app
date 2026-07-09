<?php

/**
 * CICD-CTRL-1 — Safe CI Runtime Control configuration.
 *
 * Read-only registry that declares the safe CI gate-control contract so
 * App\Support\Cicd\CiRuntimeControlScanner and
 * App\Services\Foundation\CiRuntimeControlGovernanceService can verify the
 * classifier script (scripts/ci/resolve-gates.sh) and the Foundation Evidence
 * Gates workflow honour it — without mutating anything.
 *
 * SAFETY CONTRACT:
 *  - DEFAULT-STRONG: any uncertainty resolves to the stronger gate.
 *  - The ONLY gate profile allowed to skip the expensive critical Pest step is
 *    `docs_only` (see `skip_critical_profiles`). Every other profile — and the
 *    unclassifiable `unknown_high_risk` default — runs the critical gate.
 *  - Security, governance, release-safety, evidence, and smoke gates ALWAYS
 *    run (see `always_on_jobs`); the classifier can never skip them.
 *  - Optimization is allowed only when safety is proven.
 */
return [
    'sprint' => 'CICD-CTRL-1',

    // Master switch for the CICD-CTRL-1 governance surface.
    'enabled' => (bool) env('CI_RUNTIME_CONTROL_ENABLED', true),

    // Files the governance layer inspects. Missing any is a hard FAIL.
    'files' => [
        'classifier_script' => 'scripts/ci/resolve-gates.sh',
        'ci_workflow' => '.github/workflows/foundation-evidence-gates.yml',
    ],

    // The conservative gate profiles, strongest first. `unknown_high_risk` is
    // the default when a change set cannot be classified safely.
    'profiles' => [
        'unknown_high_risk',
        'ci_workflow',
        'dependency_or_build',
        'permissions_security',
        'runtime_app',
        'ui_only',
        'docs_only',
    ],

    // The single safety invariant: only these profiles may skip the critical
    // test step. Keep this to exactly [docs_only] — widening it requires an
    // explicit, reviewed sprint and its own proof of safety.
    'skip_critical_profiles' => ['docs_only'],

    // The default profile when classification is uncertain (no diff, unreachable
    // base, unknown path). MUST be the strongest profile.
    'default_profile' => 'unknown_high_risk',

    // CI jobs that ALWAYS run for every PR/push regardless of classification.
    // The classifier must never gate these.
    'always_on_jobs' => [
        'quality_gate',
        'release_safety_gate',
        'nsf10_release_evidence_gate',
    ],

    // CI jobs / steps whose expensive work the classifier MAY gate (but only
    // downgrade to skip for the profiles in `skip_critical_profiles`).
    'classifier_gated_jobs' => [
        'critical_test_gate',
        'selective_module_gate',
        'full_suite_gate',
    ],

    // Parseable output keys the classifier script emits and the workflow reads.
    'output_keys' => [
        'gate_profile',
        'run_critical_tests',
        'run_ui_tests',
        'run_permission_tests',
        'run_inventory_tests',
        'run_rme_tests',
        'run_lab_tests',
        'run_build',
        'run_full_suite',
    ],

    // Marker strings that must be present in the classifier script — proof the
    // default-strong safety logic is in place.
    'required_classifier_markers' => [
        'DEFAULT-STRONG',
        'unknown_high_risk',
        'docs_only',
        'run_critical_tests',
        'set -euo pipefail',
    ],

    // Marker strings that must be present in the workflow — proof the classifier
    // is wired in and required/fallback gates are preserved.
    'required_workflow_markers' => [
        'resolve-gates.sh',
        'needs.classify.outputs.run_critical_tests',
        'pull_request',
        'workflow_dispatch',
        'schedule',
        'quality_gate',
        'critical_test_gate',
        'release_safety_gate',
        'nsf10_release_evidence_gate',
        'full_suite_gate',
    ],

    // Marker strings that must NEVER appear in the workflow — unsafe path
    // filtering / blanket skips that would let relevant changes bypass CI.
    'forbidden_workflow_markers' => [
        'paths-ignore',
    ],

    // The full-suite gate must remain available via at least one of these
    // fallbacks (scheduled / manual dispatch / push-to-base). Presence of any
    // is sufficient.
    'full_suite_fallback_triggers' => [
        'schedule',
        'workflow_dispatch',
    ],
];
