<?php

/**
 * ENT-12 — Backup & Disaster Recovery Automation configuration.
 *
 * Read-only registry that declares what a safe, automated backup path (DB +
 * storage backup with retention) and a rehearsable restore path (restore drill
 * that NEVER targets the production database) must enforce, so
 * App\Support\Backup\BackupDrScanner and
 * App\Services\Foundation\BackupDrGovernanceService can validate the backup
 * script, the restore-rehearsal script, the release-evidence profiles, and the
 * release-safety pre-deploy gate against it — without mutating anything, taking
 * a backup, running a restore, or touching a database.
 *
 * Builds on the shipped NSF-10 backup-verify gate and the ENT-11 deploy/rollback
 * automation: the deploy path already backs up before migrating, this sprint
 * adds automated backup + retention and a non-production restore rehearsal with
 * documented RTO/RPO targets and evidence.
 *
 * SAFETY:
 *  - Every destructive-command / safety literal lives HERE, so the backup
 *    script, the restore-rehearsal script, and the app code never carry the
 *    sensitive patterns inline (mirrors the ENT-9 / ENT-10 / ENT-11
 *    config-not-code convention).
 *  - The scanner only reads files and config; it never runs a backup, a
 *    restore, a drop, or any destructive command.
 *  - The restore rehearsal ALWAYS targets a scratch/non-production database; a
 *    production data restore is the separate, explicitly guarded
 *    scripts/restore_postgres.sh step, never automatic.
 */
return [
    'sprint' => 'ENT-12',

    // Master switch for the ENT-12 governance surface.
    'enabled' => (bool) env('BACKUP_DR_GOVERNANCE_ENABLED', true),

    // When true, warning-level findings are treated as failures by default in
    // the governance command (mirrors the ENT-10/ENT-11 configs).
    'strict' => (bool) env('BACKUP_DR_GOVERNANCE_STRICT', false),

    // Automation files the backup/DR gate inspects. Missing the backup or
    // restore-rehearsal script is a hard FAIL; the deploy/restore helpers are
    // advisory context.
    'automation_files' => [
        'backup_script' => 'scripts/backup-vps.sh',
        'restore_rehearsal_script' => 'scripts/restore-rehearsal.sh',
        'deploy_script' => 'scripts/deploy-vps.sh',
        'restore_script' => 'scripts/restore_postgres.sh',
    ],

    // Destructive / production-endangering literals that must never appear in
    // the automated backup script or the restore-rehearsal script. Kept here
    // (config) so no backup/restore/app source file carries the literal patterns
    // inline. Case-insensitive scan.
    //
    // NOTE: the restore rehearsal legitimately needs createdb/dropdb against a
    // SCRATCH database — those are gated separately by the non-production guard
    // marker below, not by this list. This list is only the patterns that would
    // endanger the production database / pilot.
    'forbidden_destructive_patterns' => [
        'migrate:fresh',
        'migrate:reset',
        'db:wipe',
        'schema:drop',
        'restore_postgres.sh',
    ],

    // Automated backup contract — the backup script must pg_dump the DB into an
    // allowed backup directory, verify it (foundation:backup-verify), and prune
    // old backups by retention. Fail-fast so a failed backup stops the run.
    'backup_expectations' => [
        'required_fail_fast_marker' => 'set -euo pipefail',
        'required_backup_command' => 'pg_dump',
        // The backup must be verified after it is written.
        'required_verify_marker' => 'php artisan foundation:backup-verify',
        'required_markers' => [
            'test -s',
        ],
        // Retention pruning must be present so backups do not grow unbounded.
        'required_retention_marker' => 'BACKUP_DR_RETENTION_DAYS',
        // Backups must land under a NSF-10 backup-verify allowed directory.
        'required_backup_directory' => 'storage/app/backups/deploy',
    ],

    // Restore-rehearsal contract — the rehearsal script must be fail-fast, pick
    // the latest verified backup, restore it into a SCRATCH database whose name
    // differs from the production database (explicit guard), verify the restore,
    // drop ONLY the scratch database, and record non-sensitive evidence
    // (backup path/size, rehearsal db, RTO/RPO). It must NEVER restore over the
    // production database or call the production restore helper.
    'restore_rehearsal_expectations' => [
        'required_fail_fast_marker' => 'set -euo pipefail',
        // Explicit guard that the rehearsal target is NOT the production DB.
        'required_non_production_guard_marker' => 'REHEARSAL_DB',
        // The guard must abort if the rehearsal DB equals the production DB.
        'required_guard_abort_marker' => 'must differ from the production database',
        'required_backup_command' => 'pg_dump',
        'required_verify_marker' => 'php artisan foundation:backup-verify',
        // Evidence recording (path + size) is mandatory.
        'required_evidence_markers' => [
            'restore-rehearsal',
            'RTO',
            'RPO',
        ],
        // The rehearsal never runs an interactive/production restore helper.
        'forbidden_production_restore_markers' => [
            'restore_postgres.sh',
        ],
    ],

    // Retention policy for automated backups (days). The backup script prunes
    // dumps older than this in the allowed backup directory.
    'retention' => [
        'db_backup_retention_days' => (int) env('BACKUP_DR_RETENTION_DAYS', 14),
        'min_retained_backups' => 3,
    ],

    // Disaster-recovery objectives. Documented, testable targets so the DR
    // posture is measurable rather than aspirational.
    'objectives' => [
        // Recovery Time Objective — max acceptable downtime to restore service.
        'rto_minutes' => (int) env('BACKUP_DR_RTO_MINUTES', 60),
        // Recovery Point Objective — max acceptable data loss window.
        'rpo_minutes' => (int) env('BACKUP_DR_RPO_MINUTES', 1440),
    ],

    // Release-evidence profiles that must declare the ENT-12 evidence artifact
    // plus the ENT-9..11 sibling artifacts (read from config/release_evidence.php).
    'evidence' => [
        'artifact' => 'backup-dr-check.json',
        'required_in_profiles' => ['ci', 'vps'],
        'required_sibling_artifacts' => [
            'deployment-rollback-check.json',
            'cicd-enterprise-gate-check.json',
            'security-compliance-check.json',
        ],
    ],

    // Release-safety pre-deploy gate (config/release_safety.php) must include
    // this command name.
    'required_pre_deploy_gate_command' => 'foundation:backup-dr-check',

    // The DR governance doc must document these RTO/RPO + rehearsal facts.
    'policy_doc' => 'docs/architecture/backup-disaster-recovery-automation-governance.md',
];
