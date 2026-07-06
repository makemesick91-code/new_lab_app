<?php

/**
 * ENT-13 — Load Test 5 Cabang Baseline configuration.
 *
 * Read-only registry that declares what the 5-branch baseline load test harness
 * (dataset + scenario runner) and its readiness gate must enforce, so
 * App\Support\LoadTest\LoadTestBaselineScanner and
 * App\Services\Foundation\LoadTestBaselineGovernanceService can validate the load
 * test harness script, the baseline runner command, the dataset seeders, the
 * scenario/bottleneck taxonomy, the latency targets, the release-evidence
 * profiles, and the release-safety pre-deploy gate against it — without running a
 * load test, seeding data, or touching a database.
 *
 * Scope source: config/foundation_roadmap.php ENT-13 entry (title
 * "Load Test 5 Cabang Baseline", category performance, depends ENT-2/ENT-4/ENT-12).
 *
 * SAFETY:
 *  - Every destructive-command / production-guard literal lives HERE, so the
 *    harness script and the app code never carry the sensitive patterns inline
 *    (mirrors the ENT-9 / ENT-10 / ENT-11 / ENT-12 config-not-code convention).
 *  - The scanner only reads files and config; it never runs a load test, a seed,
 *    or any database command.
 *  - The load test ALWAYS targets a non-production environment; the runner and
 *    the harness script abort on production/pilot. Load testing against the
 *    production VPS pilot database is out of scope by roadmap rule.
 */
return [
    'sprint' => 'ENT-13',

    // Master switch for the ENT-13 governance surface.
    'enabled' => (bool) env('LOAD_TEST_BASELINE_GOVERNANCE_ENABLED', true),

    // When true, warning-level findings are treated as failures by default in
    // the governance command (mirrors the ENT-10/ENT-11/ENT-12 configs).
    'strict' => (bool) env('LOAD_TEST_BASELINE_GOVERNANCE_STRICT', false),

    // Harness files the load-test-baseline gate inspects. Missing the harness
    // script is a hard FAIL.
    'harness_files' => [
        'load_test_script' => 'scripts/load-test-baseline.sh',
    ],

    // The artisan runner that executes the in-process baseline scenarios and
    // writes the non-sensitive evidence pack. Must be a registered command that
    // guards against production/pilot.
    'run_command' => 'loadtest:baseline-run',

    // Environments the harness + runner are allowed to run in. Production and
    // pilot are explicitly forbidden — load testing never targets the pilot DB.
    'allowed_environments' => ['local', 'stress', 'testing'],
    'forbidden_environments' => ['production', 'pilot'],

    // Explicit abort text the harness script must carry so a reviewer (and the
    // scanner) can see the non-production guard is present.
    'non_production_guard_marker' => 'must not run against production',

    // Destructive / production-endangering literals that must never appear in
    // the load-test harness script. Kept here (config) so no harness/app source
    // file carries the literal patterns inline. Case-insensitive scan.
    'forbidden_destructive_patterns' => [
        'migrate:fresh',
        'migrate:reset',
        'db:wipe',
        'schema:drop',
        'restore_postgres.sh',
    ],

    // Load-test harness contract — the script must be fail-fast, guard against
    // production, invoke the baseline runner, and write evidence into the
    // allowed load-test evidence directory.
    'harness_expectations' => [
        'required_fail_fast_marker' => 'set -euo pipefail',
        'required_run_command_marker' => 'php artisan loadtest:baseline-run',
        'required_evidence_dir_marker' => 'storage/app/load-test',
    ],

    // Where the runner writes non-sensitive evidence packs (per-scenario timings,
    // environment, dataset size, bottleneck follow-up list — never PII).
    'evidence_output_dir' => 'storage/app/load-test',

    // 5-branch baseline shape from the roadmap objective.
    'branch_count' => (int) env('LOAD_TEST_BRANCH_COUNT', 5),

    // Concurrent user mix: 25 clinic + 2 lab + 2 inventory users.
    'user_mix' => [
        'clinic_users' => 25,
        'lab_users' => 2,
        'inventory_users' => 2,
    ],

    // Dataset target: 60k–70k patients across the 5-branch baseline, produced by
    // the existing stress:seed-* harness (non-production only).
    'dataset' => [
        'target_patients_min' => 60000,
        'target_patients_max' => 70000,
        'seed_commands' => [
            'stress:seed-foundation',
            'stress:seed-patients',
            'stress:seed-rme-history',
        ],
    ],

    // Latency targets (ms). The 200–300ms band from the roadmap go_criteria:
    // p50 target 200ms, p95 target 300ms.
    'latency_targets' => [
        'p50_target_ms' => 200,
        'p95_target_ms' => 300,
        'band_label' => '200-300ms',
    ],

    // Scenario pages/domains exercised by the baseline (RME, cashier, owner
    // dashboard, inventory, reports, RM lookup). Each maps to a representative
    // read-side query the runner times.
    'scenarios' => [
        'rme_patient_queue' => ['domain' => 'rme', 'label' => 'Antrian Pasien RME'],
        'cashier_invoices' => ['domain' => 'cashier', 'label' => 'Kasir / Invoice RME'],
        'owner_dashboard' => ['domain' => 'dashboard', 'label' => 'Owner KPI Dashboard'],
        'inventory_stock' => ['domain' => 'inventory', 'label' => 'Inventory Stock View'],
        'reports' => ['domain' => 'reports', 'label' => 'Reports / Receivable'],
        'rm_lookup' => ['domain' => 'rme', 'label' => 'RM Lookup'],
    ],

    // Bottleneck categories the follow-up list must classify against.
    'bottleneck_categories' => ['db', 'cache', 'queue', 'php', 'network', 'frontend', 'storage'],

    // Release-evidence profiles that must declare the ENT-13 evidence artifact
    // plus the ENT-10..12 sibling artifacts (read from config/release_evidence.php).
    'evidence' => [
        'artifact' => 'load-test-baseline-check.json',
        'required_in_profiles' => ['ci', 'vps'],
        'required_sibling_artifacts' => [
            'backup-dr-check.json',
            'deployment-rollback-check.json',
            'cicd-enterprise-gate-check.json',
        ],
    ],

    // Release-safety pre-deploy gate (config/release_safety.php) must include
    // this command name.
    'required_pre_deploy_gate_command' => 'foundation:load-test-baseline-check',

    // The load-test governance doc that documents the baseline facts.
    'policy_doc' => 'docs/architecture/load-test-5-cabang-baseline-governance.md',
];
