<?php

/**
 * ENT-14 — Load Test Scale Projection configuration.
 *
 * Read-only registry that declares what the scale-projection harness (analysis
 * runner + readiness gate) must enforce, so
 * App\Support\LoadTest\LoadTestScaleProjectionScanner and
 * App\Services\Foundation\LoadTestScaleProjectionGovernanceService can validate
 * the projection harness script, the projection runner command, the projection
 * tiers, the baseline linkage (ENT-13), the bottleneck taxonomy, the
 * release-evidence profiles, and the release-safety pre-deploy gate against it —
 * without running a load test, seeding data, or touching a database.
 *
 * Scope source: config/foundation_roadmap.php ENT-14 entry (title
 * "Load Test Scale Projection", category performance, depends ENT-13, related
 * REPLICA-1/LB-1). The projection extrapolates the ENT-13 5-branch measured
 * baseline to 20-branch and national tiers and ties each tier to the shipped
 * LB-1 (horizontal scale) and REPLICA-1 (read routing) readiness foundations.
 *
 * SAFETY:
 *  - Projection is analysis-only. It NEVER activates replica read routing or
 *    multi-node traffic and NEVER changes production topology.
 *  - Every destructive-command / production-guard literal lives HERE, so the
 *    harness script and the app code never carry the sensitive patterns inline
 *    (mirrors the ENT-9..13 config-not-code convention).
 *  - The scanner only reads files and config; it never runs a projection, a
 *    load test, a seed, or any database command.
 *  - The projection runner ALWAYS targets a non-production environment; it
 *    aborts on production/pilot. Projected numbers are modeled/estimated, never
 *    a claimed production benchmark.
 */
return [
    'sprint' => 'ENT-14',

    // Master switch for the ENT-14 governance surface.
    'enabled' => (bool) env('LOAD_TEST_SCALE_PROJECTION_GOVERNANCE_ENABLED', true),

    // When true, warning-level findings are treated as failures by default in
    // the governance command (mirrors the ENT-10..13 configs).
    'strict' => (bool) env('LOAD_TEST_SCALE_PROJECTION_GOVERNANCE_STRICT', false),

    // Harness files the scale-projection gate inspects. Missing the harness
    // script is a hard FAIL.
    'harness_files' => [
        'scale_projection_script' => 'scripts/load-test-scale-projection.sh',
    ],

    // The artisan runner that computes the modeled projection tiers and writes
    // the non-sensitive projection evidence pack. Must be a registered command
    // that guards against production/pilot.
    'run_command' => 'loadtest:scale-projection-run',

    // Environments the harness + runner are allowed to run in. Production and
    // pilot are explicitly forbidden — projection is analysis-only and never
    // targets the pilot DB.
    'allowed_environments' => ['local', 'stress', 'testing'],
    'forbidden_environments' => ['production', 'pilot'],

    // Explicit abort text the harness script must carry so a reviewer (and the
    // scanner) can see the non-production guard is present.
    'non_production_guard_marker' => 'must not run against production',

    // Destructive / production-endangering literals that must never appear in
    // the scale-projection harness script. Kept here (config) so no harness/app
    // source file carries the literal patterns inline. Case-insensitive scan.
    'forbidden_destructive_patterns' => [
        'migrate:fresh',
        'migrate:reset',
        'db:wipe',
        'schema:drop',
        'restore_postgres.sh',
    ],

    // Scale-projection harness contract — the script must be fail-fast, guard
    // against production, invoke the projection runner, and write evidence into
    // the allowed load-test evidence directory.
    'harness_expectations' => [
        'required_fail_fast_marker' => 'set -euo pipefail',
        'required_run_command_marker' => 'php artisan loadtest:scale-projection-run',
        'required_evidence_dir_marker' => 'storage/app/load-test',
    ],

    // Where the runner writes non-sensitive projection packs (per-tier modeled
    // capacity, risks, next actions — never PII).
    'evidence_output_dir' => 'storage/app/load-test',

    // The projection is anchored on the ENT-13 baseline config. The scanner
    // validates this key resolves and its lowest tier matches the baseline
    // branch count.
    'baseline_source' => 'load_test_baseline',

    // Guardrail label: every projected number is modeled/estimated, never a
    // measured production benchmark. The runner stamps this on every projection
    // row and the pack basis.
    'projection_basis' => 'modeled',

    // Scale-projection tiers extrapolated from the ENT-13 5-branch baseline.
    // Monotonic-increasing branch_count, starting at the baseline (5), covering
    // the roadmap-mandated 20-branch tier and a national tier (50). scale_factor
    // is branch_count / baseline branch_count.
    'tiers' => [
        'baseline_5' => [
            'branch_count' => 5,
            'scale_factor' => 1.0,
            'label' => 'Baseline (5 cabang)',
            'scale_out_expectation' => 'single-node baseline (ENT-13 measured)',
        ],
        'growth_10' => [
            'branch_count' => 10,
            'scale_factor' => 2.0,
            'label' => 'Pertumbuhan (10 cabang)',
            'scale_out_expectation' => 'vertical headroom + cache (ENT-4)',
        ],
        'target_20' => [
            'branch_count' => 20,
            'scale_factor' => 4.0,
            'label' => 'Target (20 cabang)',
            'scale_out_expectation' => 'LB-1 horizontal scale + REPLICA-1 read routing recommended',
        ],
        'national_50' => [
            'branch_count' => 50,
            'scale_factor' => 10.0,
            'label' => 'Nasional (50 cabang)',
            'scale_out_expectation' => 'LB-1 multi-node + REPLICA-1 read routing required',
        ],
    ],

    // The roadmap-mandated tiers the scanner asserts are present (baseline,
    // 20-branch target, national).
    'required_tier_branch_counts' => [5, 20, 50],

    // Bottleneck categories the per-tier capacity model must classify against
    // (same fixed taxonomy as the ENT-13 baseline).
    'bottleneck_categories' => ['db', 'cache', 'queue', 'php', 'network', 'frontend', 'storage'],

    // Shipped horizontal-scale readiness foundations each higher tier ties to.
    // The scanner asserts both are declared so the projection references real
    // readiness work rather than an unproven claim.
    'related_shipped_foundations' => ['LB-1', 'REPLICA-1'],

    // Release-evidence profiles that must declare the ENT-14 evidence artifact
    // plus the ENT-11..13 sibling artifacts (read from config/release_evidence.php).
    'evidence' => [
        'artifact' => 'load-test-scale-projection-check.json',
        'required_in_profiles' => ['ci', 'vps'],
        'required_sibling_artifacts' => [
            'load-test-baseline-check.json',
            'backup-dr-check.json',
            'deployment-rollback-check.json',
        ],
    ],

    // Release-safety pre-deploy gate (config/release_safety.php) must include
    // this command name.
    'required_pre_deploy_gate_command' => 'foundation:load-test-scale-projection-check',

    // The scale-projection governance doc that documents the projection facts.
    'policy_doc' => 'docs/architecture/load-test-scale-projection-governance.md',
];
