<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DEVFLOW-1 — Safe Sprint Acceleration Foundation
|--------------------------------------------------------------------------
|
| Central config for the DEVFLOW-1 tooling: manifest schema, canonical file
| pointers, release-lock location, and the governance markers that
| `foundation:devflow-check` verifies. Read-only tooling only — nothing here
| executes shell or mutates state.
|
*/

return [

    'sprint' => 'DEVFLOW-1',
    'enabled' => (bool) env('DEVFLOW_ENABLED', true),

    // Canonical file pointers (relative to base_path). The devflow scanner
    // asserts these exist so future sprints keep the foundation intact.
    'files' => [
        'sprint_profiles' => 'config/sprint_profiles.php',
        'regression_matrix' => 'config/sprint_regression_matrix.php',
        'shared_foundations' => 'config/shared_foundations.php',
        'release_wrapper' => 'scripts/sprint-release.sh',
        'deploy_runner' => 'scripts/deploy-vps-runner.sh',
        'rollback_runner' => 'scripts/rollback-vps.sh',
        'ci_classifier' => 'scripts/ci/resolve-gates.sh',
        'sprint_runtime_template' => 'docs/engineering/sprint-runtime-template.md',
        'hotfix_template' => 'docs/engineering/hotfix-runtime-template.md',
        'foundation_template' => 'docs/engineering/foundation-sprint-template.md',
        'release_dod' => 'docs/engineering/release-definition-of-done.md',
        'quick_start' => 'docs/engineering/devflow-quick-start.md',
        'manifest_example' => '.sprint/example.yml',
    ],

    // Sprint manifest schema (source of truth for the manifest validator).
    'manifest' => [
        // Default on-disk location the tooling reads when --manifest is omitted.
        'default_path' => '.sprint/current.yml',
        'directory' => '.sprint',

        // Required scalar/boolean fields. Missing any -> validator FAILs.
        'required_fields' => [
            'id', 'type', 'module', 'base_branch',
            'runtime_change', 'schema_change', 'frontend_change', 'security_impact',
            'deploy_required',
        ],

        'boolean_fields' => [
            'runtime_change', 'schema_change', 'frontend_change',
            'security_impact', 'branch_isolation_impact', 'ledger_impact',
            'deploy_required', 'browser_required',
        ],

        // The base branch a manifest MUST target. Targeting main is rejected.
        'required_base_branch' => 'feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report',
        'forbidden_base_branches' => ['main', 'master'],

        // GO tag naming: lowercase, digits, dashes, must end with `-go`.
        'go_tag_regex' => '/^[a-z0-9]+(?:-[a-z0-9]+)*-go$/',
    ],

    // Release lock (Phase 20): a single-writer file lock preventing concurrent
    // releases. The wrapper acquires/records/releases it; stale locks are
    // reported, never silently removed.
    'release_lock' => [
        'path' => 'storage/framework/devflow-release.lock',
        'stale_after_seconds' => 3600,
    ],

    // Evidence output roots (Phase 12).
    'evidence' => [
        'log_root' => 'storage/logs/sprint-evidence',
        'doc_root' => 'docs/sprints',
        // Fields that must never be rendered/serialized raw in evidence.
        'redact_labels' => ['password', 'secret', 'token', 'api_key', 'authorization', 'ktp', 'nik'],
    ],

    // Governance markers verified by foundation:devflow-check. Kept in config
    // (never inline in app/) per the config-driven pattern. Each maps a file
    // key (from `files` above) to substrings that MUST be present.
    'required_markers' => [
        'ci_classifier' => ['unknown_high_risk', 'docs_only', 'run_critical_tests'],
        'release_wrapper' => ['--dry-run', '--apply', 'set -euo pipefail'],
    ],

    // Markers that MUST NOT appear anywhere the scanner reads (fail closed).
    // Kept here so app/ code never carries the literal destructive strings.
    'forbidden_markers' => [
        'release_wrapper' => ['migrate:fresh', 'db:wipe', 'push --force'],
    ],

    // The DEVFLOW-1 governance rule ids (published into the foundation summary).
    'governance_section' => 'devflow_governance',
];
