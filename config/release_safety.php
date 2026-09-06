<?php

/**
 * NSF-9 / NSF-10 — Release safety gate configuration.
 *
 * Read-only registry of required pre-deploy gates, required deploy evidence,
 * the rollback checklist, and safety rules. Consumed by:
 *  - App\Services\Foundation\ReleaseSafetyService
 *  - foundation:release-safety-check (governance command)
 *
 * This config never runs commands itself — it only declares what a safe
 * release must include so the service/command can validate presence and
 * the deploy script/CI workflow can be checked against it.
 *
 * NSF-10 closes the previous RELEASE_SAFETY WATCH by having
 * ReleaseSafetyService consume a real, profile-aware evidence chain
 * (config/release_evidence.php via App\Services\Foundation\ReleaseEvidenceService)
 * instead of a static local file-existence list.
 */
return [
    'sprint' => 'NSF-10',

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
        'foundation:cache-governance-check',
        'foundation:queue-governance-check',
        'foundation:idempotency-outbox-check',
        'foundation:developer-console-check',
        'foundation:health-check',
        'foundation:security-compliance-check',
        'foundation:cicd-enterprise-gate-check',
        'foundation:deployment-rollback-check',
        // DEPLOY-HARDEN-1 — the deployment entrypoint must stay immutable,
        // serialized and exact-SHA pinned. A release that regressed the
        // entrypoint back to a self-modifying script is NOT safe to deploy.
        'foundation:deployment-entrypoint-check',
        'foundation:backup-dr-check',
        'foundation:load-test-baseline-check',
        'foundation:load-test-scale-projection-check',
        'foundation:enterprise-documentation-check',
        'foundation:enterprise-closure-check',
        'foundation:idempotency-audit',
        'foundation:outbox-audit',
        'foundation:db-performance-check',
        'foundation:postgres-runtime-check',
        'foundation:reporting-summary-check',
        'foundation:reporting-summary-refresh --dry-run',
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

    /*
    |--------------------------------------------------------------------------
    | Forbidden production shell commands
    |--------------------------------------------------------------------------
    |
    | An interactive REPL on production is an unaudited write path, and PsySH
    | writes real ERROR records into the application log — which pins the
    | monitoring log signal to WATCH for 24 hours and blinds it to genuine
    | errors. The prohibition was written down in four places and the command
    | was still executed against production twice, because prose is not a
    | control.
    |
    | The patterns live HERE rather than in the guard's own source so the guard
    | never contains the literal it forbids. A scanner that reddens on itself
    | is one people learn to switch off — the same reasoning that keeps the
    | destructive-command patterns of ENT-11 and ENT-12 in config.
    |
    | KNOWN LIMIT, stated rather than implied: this matches the command as
    | written. A dynamically assembled invocation is not detectable by reading
    | the script, so this narrows the path, it does not seal it.
    |
    */
    'forbidden_production_commands' => [
        'reason' => 'An interactive REPL on production is an unaudited write path, and it writes ERROR records into the application log that pin the monitoring log signal to WATCH.',

        'patterns' => [
            // `\b` after the command name on purpose: a longer command that
            // merely starts with the same letters is a different command, and
            // a guard that reddens on obedience gets deleted.
            'artisan_repl' => '/\bartisan\s+tinker\b/i',
            'repl_binary' => '/(^|[\/\s])psysh\b/i',
            'repl_programmatic' => '/\bPsy\\\\Shell\b/',
        ],

        // Executable scripts only. Runbooks and rule files quote the forbidden
        // command in order to forbid it; scanning them would redden the very
        // documents that carry the prohibition.
        'scanned_files' => [
            'scripts/sprint-release.sh',
            'scripts/deploy-vps.sh',
            'scripts/deploy-vps-runner.sh',
            'scripts/deploy-immutable-exec.sh',
            'scripts/rollback-vps.sh',
            'scripts/backup-vps.sh',
            'scripts/restore-rehearsal.sh',
            'scripts/rollout-restore-drill.sh',
            'scripts/load-test-baseline.sh',
            'scripts/load-test-scale-projection.sh',
            'scripts/vps_pilot_preflight.sh',
        ],
    ],

    'deploy_gate_files' => [
        'deploy_script' => 'scripts/deploy-vps.sh',
        'rollback_script' => 'scripts/rollback-vps.sh',
        'backup_script' => 'scripts/backup-vps.sh',
        'restore_rehearsal_script' => 'scripts/restore-rehearsal.sh',
        'ci_workflow' => '.github/workflows/foundation-evidence-gates.yml',
        'smoke_script' => 'scripts/release/automated-smoke.sh',
    ],
];
