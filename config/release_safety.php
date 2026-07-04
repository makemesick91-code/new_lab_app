<?php

/**
 * NSF-9 — Release safety gate configuration.
 *
 * Read-only registry of required pre-deploy gates, required deploy evidence,
 * the rollback checklist, and safety rules. Consumed by:
 *  - App\Services\Foundation\ReleaseSafetyService
 *  - foundation:release-safety-check (governance command)
 *
 * This config never runs commands itself — it only declares what a safe
 * release must include so the service/command can validate presence and
 * the deploy script/CI workflow can be checked against it.
 */
return [
    'sprint' => 'NSF-9',

    'required_pre_deploy_gates' => [
        'architecture:foundation-roadmap-check',
        'data-quality:dq1-audit --fail-on=error',
        'inventory:batch-governance-audit --fail-on=error',
        'inventory:source-document-batch-audit --fail-on=error',
        'inventory:ambiguous-batch-review-pack',
        'architecture:dmo-governance-check',
        'architecture:nsf-governance-check --include-observability',
        'architecture:foundation-governance-summary',
        'foundation:feature-flags',
        'foundation:release-safety-check',
        'release:automated-smoke',
    ],

    'required_deploy_evidence' => [
        'db_backup_path_and_size',
        'go_tag_exact_match',
        'composer_install_result',
        'npm_ci_build_result',
        'migrate_force_result',
        'cache_restart_result',
        'smoke_result',
        'laravel_log_check',
    ],

    'rollback_checklist' => [
        'previous_head_or_tag_recorded',
        'db_backup_verified_before_deploy',
        'runtime_change_documented',
        'config_env_change_documented',
        'no_destructive_migration',
        'go_tag_not_moved_by_evidence_only_commit',
    ],

    'safety_rules' => [
        'no_risky_foundation_change_without_feature_flag',
        'no_deploy_without_backup',
        'no_release_without_smoke',
        'no_release_with_failing_dq_dmo_nsf_roadmap_gate',
        'no_release_with_secrets_or_pii_in_logs_or_artifacts',
    ],

    // Local evidence file candidates (checked as WATCH — not required to exist
    // for a GO decision; their absence just means "not run/captured locally yet").
    'local_evidence_candidates' => [
        'storage/app/architecture/nsf6-governance-check.json',
        'storage/app/architecture/foundation-governance-summary.json',
        'storage/ci-evidence/nsf-r011-critical-tests.txt',
        'storage/ci-evidence/nsf-r012-build-pint.txt',
    ],

    'deploy_gate_files' => [
        'deploy_script' => 'scripts/deploy-vps.sh',
        'ci_workflow' => '.github/workflows/foundation-evidence-gates.yml',
        'smoke_script' => 'scripts/release/automated-smoke.sh',
    ],
];
