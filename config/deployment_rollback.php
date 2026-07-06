<?php

/**
 * ENT-11 — Deployment & Rollback Automation configuration.
 *
 * Read-only registry that declares what a safe, automated VPS deploy path
 * (backup → deploy → cache rebuild → permission reset → smoke) and a tested
 * rollback path must enforce, so App\Support\Deploy\DeploymentRollbackScanner
 * and App\Services\Foundation\DeploymentRollbackGovernanceService can validate
 * the deploy script, the rollback script, the backup/restore helpers, the
 * release-evidence profiles, and the release-safety rollback checklist against
 * it — without mutating anything or running a single command.
 *
 * Builds on the shipped NSF-10 evidence chain and the ENT-10 CI/CD enterprise
 * gate: the deploy path already exists in scripts/deploy-vps.sh, this sprint
 * hardens it, adds a rehearsable rollback path in scripts/rollback-vps.sh, and
 * captures deploy/rollback evidence.
 *
 * SAFETY:
 *  - Every destructive-command / safety literal lives HERE, so the deploy
 *    script, the rollback script, and the app code never carry the sensitive
 *    patterns inline (mirrors the ENT-9 / ENT-10 config-not-code convention).
 *  - The scanner only reads files and config; it never runs a deploy, a
 *    rollback, a backup, a restore, or any destructive command.
 */
return [
    'sprint' => 'ENT-11',

    // Master switch for the ENT-11 governance surface.
    'enabled' => (bool) env('DEPLOYMENT_ROLLBACK_GOVERNANCE_ENABLED', true),

    // When true, warning-level findings are treated as failures by default in
    // the governance command (mirrors the ENT-10/health-check configs).
    'strict' => (bool) env('DEPLOYMENT_ROLLBACK_GOVERNANCE_STRICT', false),

    // Automation files the deploy/rollback gate inspects. Missing the deploy or
    // rollback script is a hard FAIL; the backup/restore helpers are advisory.
    'automation_files' => [
        'deploy_script' => 'scripts/deploy-vps.sh',
        'rollback_script' => 'scripts/rollback-vps.sh',
        'backup_script' => 'scripts/backup_postgres.sh',
        'restore_script' => 'scripts/restore_postgres.sh',
    ],

    // Destructive command literals that must never appear in the deploy or
    // rollback script. Kept here (config) so no deploy/rollback/app source file
    // carries the literal patterns inline. Case-insensitive scan.
    'forbidden_destructive_patterns' => [
        'migrate:fresh',
        'migrate:reset',
        'migrate:rollback',
        'db:wipe',
        'schema:drop',
        'drop database',
        'drop schema',
        'truncate',
    ],

    // Deploy automation contract — the deploy script must take a DB backup
    // before pulling/migrating, migrate additively with --force, rebuild caches,
    // reset permissions, and run the smoke command.
    'deploy_expectations' => [
        'required_backup_command' => 'pg_dump',
        'required_migrate_command' => 'migrate --force',
        'backup_before_migrate' => true,
        'required_markers' => [
            'php artisan release:automated-smoke',
            'chown -R www-data:www-data',
        ],
        // The deploy path must also capture/verify NSF-10 release evidence.
        'required_evidence_markers' => [
            'release:evidence-capture',
            'release:evidence-check',
            'foundation:backup-verify',
        ],
    ],

    // Rollback automation contract — the rollback script must be fail-fast,
    // record the current ref before switching, take a DB backup before the
    // rollback, check out the target ref, rebuild caches, reset permissions,
    // restart the runtime, and re-run the smoke command. It must NEVER run a
    // destructive database command or an automatic schema-destroying migration
    // (additive migrations are backward-compatible so older code runs against
    // the current schema; a data rollback is a separate, explicitly guarded
    // restore step using scripts/restore_postgres.sh).
    'rollback_expectations' => [
        'required_fail_fast_marker' => 'set -euo pipefail',
        // Records the current HEAD/tag before rolling back (rollback checklist).
        'required_ref_capture_markers' => [
            'git rev-parse HEAD',
        ],
        // Mandatory DB backup before any rollback action.
        'required_backup_command' => 'pg_dump',
        // Backup must be taken before the code is checked out to the target ref.
        'backup_before_checkout' => true,
        'required_checkout_marker' => 'git checkout',
        // Post-rollback runtime hardening + verification.
        'required_markers' => [
            'php artisan release:automated-smoke',
            'chown -R www-data:www-data',
            'nginx -t',
        ],
        // The rollback path re-verifies the ENT-5..10 foundation stack.
        'required_gate_markers' => [
            'php artisan architecture:foundation-roadmap-check',
            'php artisan foundation:cicd-enterprise-gate-check',
            'php artisan foundation:deployment-rollback-check',
        ],
    ],

    // ENT-8 cache-order hardening: route/config cache must be cleared BEFORE the
    // route-dependent governance gates run in BOTH the deploy and rollback
    // scripts. The scanner asserts the clear markers appear before the first
    // route-dependent gate marker.
    'cache_order' => [
        'pre_gate_clear_markers' => [
            'php artisan route:clear',
            'php artisan config:clear',
        ],
        'first_route_dependent_gate' => 'php artisan foundation:developer-console-check',
        'post_gate_rebuild_markers' => [
            'config:cache',
            'route:cache',
            'view:cache',
        ],
    ],

    // Release-evidence profiles that must declare the ENT-11 evidence artifact
    // plus the ENT-5..10 sibling artifacts (read from config/release_evidence.php).
    'evidence' => [
        'artifact' => 'deployment-rollback-check.json',
        'required_in_profiles' => ['ci', 'vps'],
        'required_sibling_artifacts' => [
            'cicd-enterprise-gate-check.json',
            'security-compliance-check.json',
            'health-check-check.json',
        ],
    ],

    // Release-safety rollback checklist (config/release_safety.php
    // rollback_checklist) must include these markers so every deploy has a
    // documented, rehearsable rollback plan.
    'required_rollback_checklist' => [
        'previous_head_or_tag_recorded',
        'db_backup_verified_before_deploy',
        'no_destructive_migration',
    ],

    // Release-safety pre-deploy gate (config/release_safety.php) must include
    // this command name.
    'required_pre_deploy_gate_command' => 'foundation:deployment-rollback-check',
];
