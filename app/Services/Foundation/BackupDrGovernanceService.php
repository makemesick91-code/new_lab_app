<?php

namespace App\Services\Foundation;

use App\Support\Backup\BackupDrScanner;

/**
 * ENT-12 — Backup & Disaster Recovery Automation governance layer.
 *
 * Automates and locks the backup path (DB + storage backup with retention) and
 * a rehearsable, non-production restore path on top of the shipped NSF-10
 * backup-verify gate and the ENT-11 deploy/rollback automation. It validates
 * the backup script, the restore-rehearsal script, the retention + RTO/RPO
 * objectives, the release-evidence profiles, and the release-safety pre-deploy
 * gate, and confirms the ENT-5 / ENT-6 / ENT-7 / ENT-8 / ENT-9 / ENT-10 / ENT-11
 * foundations it builds on remain GO. It stays separate from the sibling
 * governance services so their contracts remain intact and is informational
 * only (not wired into the blocking combined decision).
 */
class BackupDrGovernanceService
{
    public function __construct(
        private readonly BackupDrScanner $scanner,
        private readonly QueueRetryFailedJobGovernanceService $queueRetryGovernance,
        private readonly IdempotencyOutboxGovernanceService $idempotencyOutboxGovernance,
        private readonly DeveloperConsoleGovernanceService $developerConsoleGovernance,
        private readonly HealthCheckGovernanceService $healthCheckGovernance,
        private readonly SecurityComplianceGovernanceService $securityComplianceGovernance,
        private readonly CicdEnterpriseGateGovernanceService $cicdEnterpriseGateGovernance,
        private readonly DeploymentRollbackGovernanceService $deploymentRollbackGovernance,
    ) {}

    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'ENT12-BDR001',
                'title' => 'Backup is automated through the canonical backup script',
                'description' => 'DB backup runs through scripts/backup-vps.sh (fail-fast pg_dump into an allowed directory → verify → prune by retention). Ad-hoc manual dumps are not the backup path; the script is the mandatory, idempotent baseline that the ENT-11 deploy path also relies on.',
            ],
            [
                'id' => 'ENT12-BDR002',
                'title' => 'Every automated backup is verified before it is trusted',
                'description' => 'The backup script pg_dumps the database and immediately verifies the dump with foundation:backup-verify (NSF-10) so a truncated/zero-byte/misplaced dump fails the run under set -euo pipefail. No backup is trusted without verification.',
            ],
            [
                'id' => 'ENT12-BDR003',
                'title' => 'Backups are retained with a bounded, pruned retention window',
                'description' => 'The backup script prunes dumps older than the configured BACKUP_DR_RETENTION_DAYS while keeping a minimum number of recent backups, so backups neither grow unbounded nor drop below the recoverable floor.',
            ],
            [
                'id' => 'ENT12-BDR004',
                'title' => 'A rehearsable restore drill exists and is fail-fast',
                'description' => 'scripts/restore-rehearsal.sh restores the latest verified backup under set -euo pipefail, verifies the restored data, and records evidence. Backup readiness is proven by a real restore rehearsal, not by the existence of a dump alone.',
            ],
            [
                'id' => 'ENT12-BDR005',
                'title' => 'Restore rehearsal NEVER targets the production database',
                'description' => 'The rehearsal restores into a scratch database whose name is explicitly guarded to differ from the production database; the drill aborts if they match, drops only the scratch database, and never restores over the pilot data. Production restore is the separate, explicit scripts/restore_postgres.sh step.',
            ],
            [
                'id' => 'ENT12-BDR006',
                'title' => 'Backup / rehearsal scripts carry no production-endangering command',
                'description' => 'Neither the backup script nor the rehearsal script runs migrate:fresh, migrate:reset, db:wipe, schema:drop, or the production restore helper; destructive-command literals live in config/backup_dr.php, not in the scripts or app source.',
            ],
            [
                'id' => 'ENT12-BDR007',
                'title' => 'RTO and RPO objectives are documented and measurable',
                'description' => 'Recovery Time Objective (rto_minutes) and Recovery Point Objective (rpo_minutes) are declared as positive integers in config and documented in the DR governance doc, so the DR posture is a measurable target rather than an aspiration.',
            ],
            [
                'id' => 'ENT12-BDR008',
                'title' => 'DR evidence stays non-sensitive',
                'description' => 'No secret, credential, backup contents, environment values, or KTP/NIK-shaped value is ever written to a backup/DR evidence artifact or printed by the gate; every artifact passes the release-evidence forbidden-pattern/regex scan (path/size and object counts only).',
            ],
            [
                'id' => 'ENT12-BDR009',
                'title' => 'DR evidence artifact is required per release profile',
                'description' => 'The ci and vps release-evidence profiles require the ENT-12 backup-dr-check.json artifact plus the ENT-9..11 sibling artifacts; release:evidence-capture/check produce and validate them.',
            ],
            [
                'id' => 'ENT12-BDR010',
                'title' => 'Backup/DR is a mandatory pre-deploy gate',
                'description' => 'foundation:backup-dr-check is listed in the release-safety pre-deploy gates and runs (with evidence capture) in the deploy script and CI so DR readiness is re-verified before every release.',
            ],
            [
                'id' => 'ENT12-BDR011',
                'title' => 'Backup/DR builds on and preserves the ENT-5..11 foundations',
                'description' => 'The gate re-verifies the queue-retry, idempotency/outbox, developer-console, health-check, security-compliance, cicd-enterprise-gate, and deployment-rollback governance; those strict checks must stay GO for the backup/DR gate to be GO.',
            ],
            [
                'id' => 'ENT12-BDR012',
                'title' => 'New backup/DR automation registers here with tests first',
                'description' => 'Any future backup step, retention change, restore rehearsal, storage-file backup, or DR evidence artifact must extend this contract with coverage and tests and pass foundation:backup-dr-check before shipping. Restore drills never endanger the production database.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $checks = [];

        $enabled = (bool) config('backup_dr.enabled', true);
        $checks[] = $enabled
            ? $this->pass('ENT12-BDR-ENABLED', 'Backup & disaster recovery automation governance is enabled.')
            : $this->warn('ENT12-BDR-ENABLED', 'Backup & disaster recovery automation governance is disabled by configuration.');

        $backup = $this->scanner->backupScriptPosture();
        $checks[] = $backup['ok']
            ? $this->pass('ENT12-BDR-BACKUP-SCRIPT', 'Backup script is safe (fail-fast, pg_dump into allowed dir, verify, retention prune, no destructive command).')
            : $this->fail('ENT12-BDR-BACKUP-SCRIPT', 'Backup script posture failed: '.implode('; ', $backup['issues']).'.');

        $rehearsal = $this->scanner->restoreRehearsalPosture();
        $checks[] = $rehearsal['ok']
            ? $this->pass('ENT12-BDR-RESTORE-REHEARSAL', 'Restore-rehearsal script is safe (fail-fast, non-production scratch guard, verify, evidence, never restores over production).')
            : $this->fail('ENT12-BDR-RESTORE-REHEARSAL', 'Restore-rehearsal posture failed: '.implode('; ', $rehearsal['issues']).'.');

        $objectives = $this->scanner->objectivesPosture();
        $checks[] = $objectives['ok']
            ? $this->pass('ENT12-BDR-OBJECTIVES', 'Retention window + RTO/RPO objectives are documented and positive.')
            : $this->fail('ENT12-BDR-OBJECTIVES', 'Retention/RTO/RPO objectives failed: '.implode('; ', $objectives['issues']).'.');

        $evidence = $this->scanner->evidenceProfilePosture();
        $checks[] = $evidence['ok']
            ? $this->pass('ENT12-BDR-EVIDENCE', 'Release-evidence ci/vps profiles require the ENT-12 artifact and ENT-9..11 siblings.')
            : $this->fail('ENT12-BDR-EVIDENCE', 'Release-evidence profile posture failed: '.implode('; ', $evidence['issues']).'.');

        $releaseSafety = $this->scanner->releaseSafetyPosture();
        $checks[] = $releaseSafety['ok']
            ? $this->pass('ENT12-BDR-RELEASE-SAFETY', 'Release-safety includes the ENT-12 pre-deploy gate.')
            : $this->fail('ENT12-BDR-RELEASE-SAFETY', 'Release-safety posture failed: '.implode('; ', $releaseSafety['issues']).'.');

        $queueRetry = $this->queueRetryGovernance->collect();
        $checks[] = $this->decisionCheck('ENT12-BDR-ENT5-QUEUE-RETRY', (string) ($queueRetry['decision'] ?? 'FAIL'), 'ENT-5 queue retry governance is GO.');

        $idempotencyOutbox = $this->idempotencyOutboxGovernance->collect();
        $checks[] = $this->decisionCheck('ENT12-BDR-ENT6-IDEMPOTENCY-OUTBOX', (string) ($idempotencyOutbox['decision'] ?? 'FAIL'), 'ENT-6 idempotency/outbox governance is GO.');

        $developerConsole = $this->developerConsoleGovernance->collect();
        $checks[] = $this->decisionCheck('ENT12-BDR-ENT7-DEVELOPER-CONSOLE', (string) ($developerConsole['decision'] ?? 'FAIL'), 'ENT-7 developer-console governance is GO.');

        $healthCheck = $this->healthCheckGovernance->collect();
        $checks[] = $this->decisionCheck('ENT12-BDR-ENT8-HEALTH-CHECK', (string) ($healthCheck['decision'] ?? 'FAIL'), 'ENT-8 health-check governance is GO.');

        $securityCompliance = $this->securityComplianceGovernance->collect();
        $checks[] = $this->decisionCheck('ENT12-BDR-ENT9-SECURITY-COMPLIANCE', (string) ($securityCompliance['decision'] ?? 'FAIL'), 'ENT-9 security compliance governance is GO.');

        $cicdEnterpriseGate = $this->cicdEnterpriseGateGovernance->collect();
        $checks[] = $this->decisionCheck('ENT12-BDR-ENT10-CICD-ENTERPRISE-GATE', (string) ($cicdEnterpriseGate['decision'] ?? 'FAIL'), 'ENT-10 CI/CD enterprise gate governance is GO.');

        $deploymentRollback = $this->deploymentRollbackGovernance->collect();
        $checks[] = $this->decisionCheck('ENT12-BDR-ENT11-DEPLOYMENT-ROLLBACK', (string) ($deploymentRollback['decision'] ?? 'FAIL'), 'ENT-11 deployment/rollback governance is GO.');

        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));
        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'sprint' => 'ENT-12',
            'decision' => $decision,
            'readiness_status' => $decision === 'GO' ? 'backup_dr_ready' : strtolower($decision),
            'backup_dr_enabled' => $enabled,
            'backup_script_ok' => $backup['ok'],
            'backup_fail_fast' => $backup['fail_fast'] ?? false,
            'backup_command_present' => $backup['backup_command_present'] ?? false,
            'backup_verify_present' => $backup['verify_present'] ?? false,
            'backup_retention_present' => $backup['retention_present'] ?? false,
            'backup_no_destructive_command' => $backup['no_destructive_command'] ?? false,
            'restore_rehearsal_ok' => $rehearsal['ok'],
            'restore_rehearsal_present' => $rehearsal['file_present'] ?? false,
            'restore_rehearsal_fail_fast' => $rehearsal['fail_fast'] ?? false,
            'restore_rehearsal_non_production_guard' => $rehearsal['non_production_guard'] ?? false,
            'restore_rehearsal_records_evidence' => $rehearsal['records_evidence'] ?? false,
            'restore_rehearsal_no_production_restore' => $rehearsal['no_production_restore'] ?? false,
            'objectives_ok' => $objectives['ok'],
            'retention_days' => $objectives['retention_days'] ?? 0,
            'rto_minutes' => $objectives['rto_minutes'] ?? 0,
            'rpo_minutes' => $objectives['rpo_minutes'] ?? 0,
            'evidence_profiles_ok' => $evidence['ok'],
            'evidence_artifact' => $evidence['artifact'],
            'release_safety_ok' => $releaseSafety['ok'],
            'pre_deploy_gate_ok' => $releaseSafety['pre_deploy_gate_ok'] ?? false,
            'queue_retry_decision' => $queueRetry['decision'] ?? 'UNKNOWN',
            'idempotency_outbox_decision' => $idempotencyOutbox['decision'] ?? 'UNKNOWN',
            'developer_console_decision' => $developerConsole['decision'] ?? 'UNKNOWN',
            'health_check_decision' => $healthCheck['decision'] ?? 'UNKNOWN',
            'security_compliance_decision' => $securityCompliance['decision'] ?? 'UNKNOWN',
            'cicd_enterprise_gate_decision' => $cicdEnterpriseGate['decision'] ?? 'UNKNOWN',
            'deployment_rollback_decision' => $deploymentRollback['decision'] ?? 'UNKNOWN',
            'checks' => $checks,
            'summary' => [
                'decision' => $decision,
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
            ],
            'rules' => self::rules(),
            'commands' => [
                'foundation:backup-dr-check',
                'foundation:deployment-rollback-check',
                'foundation:backup-verify',
                'release:evidence-check',
                'architecture:foundation-governance-summary',
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];
    }

    private function decisionCheck(string $id, string $decision, string $goMessage): array
    {
        return match ($decision) {
            'GO' => $this->pass($id, $goMessage),
            'WATCH' => $this->warn($id, "{$id} is WATCH; strict mode should block until resolved."),
            default => $this->fail($id, "{$id} is {$decision}; ENT-12 cannot be GO."),
        };
    }

    private function pass(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'passed', 'blocking' => false, 'message' => $message];
    }

    private function warn(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'warning', 'blocking' => false, 'message' => $message];
    }

    private function fail(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'failed', 'blocking' => true, 'message' => $message];
    }
}
