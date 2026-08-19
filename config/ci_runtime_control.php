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
        // CI-TEMP-FULL-SUITE-SCHEDULE-GATE — the canonical policy state and the
        // resolver that turns it into a per-run CI decision.
        'full_suite_policy_state' => '.github/ci-policy/full-suite-policy.json',
        'full_suite_policy_resolver' => 'scripts/ci/resolve-full-suite-policy.sh',
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
        // CI-TEMP-FULL-SUITE-SCHEDULE-GATE — the Full Suite authorisation wiring
        // must stay present; deleting it would silently restore the automatic
        // weekly / post-merge Full Suite while the temporary policy is ACTIVE.
        'resolve-full-suite-policy.sh',
        'full_suite_authorized',
    ],

    // Marker strings that must NEVER appear in the workflow — unsafe path
    // filtering / blanket skips that would let relevant changes bypass CI.
    'forbidden_workflow_markers' => [
        'paths-ignore',
    ],

    // The full-suite gate must remain available via at least one of these
    // fallbacks (scheduled / manual dispatch / push-to-base). Presence of any
    // is sufficient.
    // CI-TEMP-FULL-SUITE-SCHEDULE-GATE note: both triggers are deliberately
    // RETAINED in the workflow. The temporary policy narrows WHEN the gate may
    // execute; it never deletes a trigger, so this invariant still holds.
    'full_suite_fallback_triggers' => [
        'schedule',
        'workflow_dispatch',
    ],

    /*
     * CI-TEMP-FULL-SUITE-SCHEDULE-GATE — GLOBAL TEMPORARY FULL-SUITE POLICY.
     *
     * The canonical STATE lives in the JSON file registered above; it is never
     * duplicated here. This block declares only the CONTRACT the state file and
     * the resolver must satisfy, so governance can prove the CI layer and the
     * documentation cannot drift apart.
     */
    'temporary_full_suite_policy' => [
        // Where the human-readable authority lives.
        'canonical_document' => 'docs/governance/global-temporary-full-suite-policy.md',
        'rule_mirror' => '.cursor/rules/107-global-temporary-full-suite-policy.mdc',

        // The only two statuses the resolver may read. Anything else is
        // unresolved and MUST fail closed to "policy active".
        'allowed_statuses' => ['ACTIVE', 'RETIRED'],

        // While ACTIVE these events must never reach the Full Suite. `push`
        // covers the post-merge squash-merge to the base branch.
        'deferred_automatic_events' => ['schedule', 'push'],

        // The one path that survives while ACTIVE: a deliberate dispatch with
        // BOTH inputs set. This keeps the consolidated final closure possible.
        'authorised_manual_event' => 'workflow_dispatch',
        'authorised_manual_inputs' => ['run_full_suite', 'full_suite_policy_override'],

        // Machine-readable reason codes the resolver emits. "Deferred", never
        // "not needed" — a future operator must be able to tell the difference.
        'required_reason_codes' => [
            'TEMPORARY_FULL_SUITE_POLICY_ACTIVE',
            'TEMPORARY_FULL_SUITE_POLICY_ACTIVE_OVERRIDE_REQUIRED',
            'AUTHORISED_CONSOLIDATED_FULL_SUITE',
            'POLICY_STATE_UNRESOLVED_FAIL_CLOSED',
        ],

        // Markers proving the resolver is fail-closed and read-only.
        'required_resolver_markers' => [
            'set -euo pipefail',
            'FAIL CLOSED',
            'POLICY_STATE_UNRESOLVED_FAIL_CLOSED',
            'full_suite_authorized',
        ],

        // The resolver must never run tests or mutate the repository.
        'forbidden_resolver_markers' => [
            'artisan test',
            'vendor/bin/pest',
        ],
    ],
];
