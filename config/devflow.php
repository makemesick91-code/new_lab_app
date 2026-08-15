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

    // DEVFLOW-FIX-BASE-REF-1 — canonical base resolution.
    //
    // The comparison authority for every DEVFLOW diff is an EXACT commit SHA.
    // A bare branch name resolves to the LOCAL `refs/heads/<branch>`, which may
    // be stale, ahead, or diverged — that is the defect this block removes.
    //
    // Authority order: explicit exact SHA -> `<remote>/<branch>` -> FAIL CLOSED.
    // There is no local fallback, and no implicit main/master/HEAD~1/tag.
    'base_resolution' => [
        // The canonical remote. Explicit on purpose: with both `origin` and
        // `upstream` present, auto-selecting "the first remote" is how a tool
        // ends up comparing against the wrong fork.
        'remote' => env('DEVFLOW_CANONICAL_REMOTE', 'origin'),

        // Fetch the canonical remote branch before resolving. Disable only for
        // deliberately offline analysis — resolution then still fails closed
        // when the remote-tracking ref is absent.
        'fetch_enabled' => (bool) env('DEVFLOW_BASE_FETCH_ENABLED', true),
        'fetch_timeout_seconds' => (int) env('DEVFLOW_BASE_FETCH_TIMEOUT', 120),

        // Environment keys carrying an authoritative exact base SHA, checked in
        // order. CI populates these from the immutable PR event payload.
        'explicit_sha_env' => ['DEVFLOW_BASE_SHA', 'CI_BASE_SHA'],

        // Exact object id only — 40 hex (sha1) or 64 hex (sha256). Abbreviations
        // and revision expressions (HEAD, HEAD~1, ref^{}, --option) are rejected.
        'exact_sha_pattern' => '/^[0-9a-f]{40}(?:[0-9a-f]{24})?$/i',

        // Conservative ref-name allowlist. Blocks leading dashes (option
        // injection), whitespace and revision metacharacters before the value
        // can reach a git argument list.
        'safe_branch_pattern' => '/^(?!-)[A-Za-z0-9._\/-]{1,200}$/',

        // Refs that must NEVER become an automatic base authority. Documented
        // here so the prohibition is auditable, not just implied by code.
        'forbidden_fallbacks' => [
            'local_branch_ref', 'main', 'master', 'HEAD', 'HEAD~1', 'latest_tag',
        ],
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
        'ci_classifier' => [
            'unknown_high_risk', 'docs_only', 'run_critical_tests',
            // DEVFLOW-FIX-BASE-REF-1: the classifier must report its authority
            // and must validate a ref before handing it to git.
            'BASE_SOURCE', 'base_sha',
        ],
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
