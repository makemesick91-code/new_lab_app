<?php

namespace App\Services\Foundation;

use App\Support\Deploy\DeploymentRollbackScanner;

/**
 * ENT-11 — Deployment & Rollback Automation governance layer.
 *
 * Automates and locks the VPS deploy path (backup → deploy → cache rebuild →
 * permission reset → smoke) and a rehearsable rollback path on top of the
 * shipped NSF-10 evidence chain and the ENT-10 CI/CD enterprise gate. It
 * validates the deploy script, the rollback script, the release-evidence
 * profiles, and the release-safety rollback checklist / pre-deploy gate, and
 * confirms the ENT-5 / ENT-6 / ENT-7 / ENT-8 / ENT-9 / ENT-10 foundations it
 * builds on remain GO. It stays separate from the sibling governance services
 * so their contracts remain intact and is informational only (not wired into
 * the blocking combined decision).
 */
class DeploymentRollbackGovernanceService
{
    public function __construct(
        private readonly DeploymentRollbackScanner $scanner,
        private readonly QueueRetryFailedJobGovernanceService $queueRetryGovernance,
        private readonly IdempotencyOutboxGovernanceService $idempotencyOutboxGovernance,
        private readonly DeveloperConsoleGovernanceService $developerConsoleGovernance,
        private readonly HealthCheckGovernanceService $healthCheckGovernance,
        private readonly SecurityComplianceGovernanceService $securityComplianceGovernance,
        private readonly CicdEnterpriseGateGovernanceService $cicdEnterpriseGateGovernance,
    ) {}

    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'ENT11-DR001',
                'title' => 'Deploy is automated through the canonical deploy script',
                'description' => 'The VPS deploy path runs through scripts/deploy-vps.sh (backup → deploy → migrate → cache rebuild → permission reset → smoke). Ad-hoc manual deploys are not the release path; the script is the mandatory, idempotent baseline.',
            ],
            [
                'id' => 'ENT11-DR002',
                'title' => 'Every deploy takes a verified DB backup before pull/migrate',
                'description' => 'The deploy script pg_dumps the database before pulling and migrating and verifies it (foundation:backup-verify); backup failure stops the deploy under set -euo pipefail. No deploy runs without a verified backup.',
            ],
            [
                'id' => 'ENT11-DR003',
                'title' => 'Migration safety: additive migrate --force only, never destructive',
                'description' => 'The deploy and rollback chain uses php artisan migrate --force and never runs migrate:fresh, migrate:reset, migrate:rollback, db:wipe, schema:drop, DROP DATABASE/SCHEMA, or TRUNCATE. Schema stays additive so older code rolls back safely against the current database.',
            ],
            [
                'id' => 'ENT11-DR004',
                'title' => 'A rehearsable rollback script exists and is fail-fast',
                'description' => 'scripts/rollback-vps.sh rolls the app back to a prior GO tag/commit under set -euo pipefail: it records the current ref, backs up the DB, checks out the target ref, rebuilds, resets permissions, restarts, and re-runs smoke. Every deploy has a tested rollback plan.',
            ],
            [
                'id' => 'ENT11-DR005',
                'title' => 'Rollback records the current ref and backs up before switching',
                'description' => 'The rollback script captures the current HEAD/tag and takes a DB backup before checking out the target ref, so the pre-rollback state is always recoverable and the rollback itself is reversible.',
            ],
            [
                'id' => 'ENT11-DR006',
                'title' => 'Rollback never performs a destructive database operation',
                'description' => 'Code rollback keeps the current additive schema (backward-compatible) and never auto-drops/wipes data. A data rollback is a separate, explicitly guarded restore step using scripts/restore_postgres.sh with a chosen backup file — never automatic.',
            ],
            [
                'id' => 'ENT11-DR007',
                'title' => 'ENT-8 cache-order hardening is preserved in deploy and rollback',
                'description' => 'Both scripts clear route/config cache before the route-dependent governance gates so freshly deployed/rolled-back routes are visible, then rebuild config:cache, route:cache, and view:cache after the gate phase. Reordering this fails the gate.',
            ],
            [
                'id' => 'ENT11-DR008',
                'title' => 'Deploy and rollback reset permissions and restart the runtime',
                'description' => 'Both scripts reset storage/bootstrap-cache ownership to www-data and restart php-fpm + reload nginx (nginx -t first) so the running app matches the deployed/rolled-back code.',
            ],
            [
                'id' => 'ENT11-DR009',
                'title' => 'Deploy and rollback re-verify the ENT-5..10 foundation stack',
                'description' => 'The deploy and rollback scripts run the roadmap, queue-retry, idempotency/outbox, developer-console, health-check, security-compliance, and cicd-enterprise-gate checks plus foundation:deployment-rollback-check; those strict checks must stay GO for the deploy/rollback gate to be GO.',
            ],
            [
                'id' => 'ENT11-DR010',
                'title' => 'Deploy/rollback evidence is captured and required per profile',
                'description' => 'The ci and vps release-evidence profiles require the ENT-11 deployment-rollback-check.json artifact plus the ENT-8..10 sibling artifacts; release:evidence-capture/check produce and validate them, and the vps deploy-runtime artifact records backup path/size, go tag match, migrate result, and smoke result.',
            ],
            [
                'id' => 'ENT11-DR011',
                'title' => 'Evidence and gate output stay non-sensitive',
                'description' => 'No secret, credential, backup contents, environment values, or KTP/NIK-shaped value is ever written to a deploy/rollback evidence artifact or printed by a gate; every artifact passes the release-evidence forbidden-pattern/regex scan.',
            ],
            [
                'id' => 'ENT11-DR012',
                'title' => 'New deploy/rollback automation registers here with tests first',
                'description' => 'Any future deploy step, rollback step, backup/restore helper, or deploy evidence artifact must extend this contract with coverage and tests and pass foundation:deployment-rollback-check before shipping. Destructive-command literals live in config, not source.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $checks = [];

        $enabled = (bool) config('deployment_rollback.enabled', true);
        $checks[] = $enabled
            ? $this->pass('ENT11-DR-ENABLED', 'Deployment & rollback automation governance is enabled.')
            : $this->warn('ENT11-DR-ENABLED', 'Deployment & rollback automation governance is disabled by configuration.');

        $deploy = $this->scanner->deployScriptPosture();
        $checks[] = $deploy['ok']
            ? $this->pass('ENT11-DR-DEPLOY-SCRIPT', 'Deploy script is safe (backup-before-migrate, migrate --force, no destructive command, ENT-8 cache-order preserved, permission reset + smoke + evidence markers present).')
            : $this->fail('ENT11-DR-DEPLOY-SCRIPT', 'Deploy script posture failed: '.implode('; ', $deploy['issues']).'.');

        $rollback = $this->scanner->rollbackScriptPosture();
        $checks[] = $rollback['ok']
            ? $this->pass('ENT11-DR-ROLLBACK-SCRIPT', 'Rollback script is safe (fail-fast, records current ref, backup-before-checkout, no destructive command, ENT-8 cache-order preserved, restart + smoke + foundation re-verification present).')
            : $this->fail('ENT11-DR-ROLLBACK-SCRIPT', 'Rollback script posture failed: '.implode('; ', $rollback['issues']).'.');

        $evidence = $this->scanner->evidenceProfilePosture();
        $checks[] = $evidence['ok']
            ? $this->pass('ENT11-DR-EVIDENCE', 'Release-evidence ci/vps profiles require the ENT-11 artifact and ENT-8..10 siblings.')
            : $this->fail('ENT11-DR-EVIDENCE', 'Release-evidence profile posture failed: '.implode('; ', $evidence['issues']).'.');

        $releaseSafety = $this->scanner->releaseSafetyPosture();
        $checks[] = $releaseSafety['ok']
            ? $this->pass('ENT11-DR-RELEASE-SAFETY', 'Release-safety declares the rollback checklist and includes the ENT-11 pre-deploy gate.')
            : $this->fail('ENT11-DR-RELEASE-SAFETY', 'Release-safety posture failed: '.implode('; ', $releaseSafety['issues']).'.');

        $queueRetry = $this->queueRetryGovernance->collect();
        $checks[] = $this->decisionCheck('ENT11-DR-ENT5-QUEUE-RETRY', (string) ($queueRetry['decision'] ?? 'FAIL'), 'ENT-5 queue retry governance is GO.');

        $idempotencyOutbox = $this->idempotencyOutboxGovernance->collect();
        $checks[] = $this->decisionCheck('ENT11-DR-ENT6-IDEMPOTENCY-OUTBOX', (string) ($idempotencyOutbox['decision'] ?? 'FAIL'), 'ENT-6 idempotency/outbox governance is GO.');

        $developerConsole = $this->developerConsoleGovernance->collect();
        $checks[] = $this->decisionCheck('ENT11-DR-ENT7-DEVELOPER-CONSOLE', (string) ($developerConsole['decision'] ?? 'FAIL'), 'ENT-7 developer-console governance is GO.');

        $healthCheck = $this->healthCheckGovernance->collect();
        $checks[] = $this->decisionCheck('ENT11-DR-ENT8-HEALTH-CHECK', (string) ($healthCheck['decision'] ?? 'FAIL'), 'ENT-8 health-check governance is GO.');

        $securityCompliance = $this->securityComplianceGovernance->collect();
        $checks[] = $this->decisionCheck('ENT11-DR-ENT9-SECURITY-COMPLIANCE', (string) ($securityCompliance['decision'] ?? 'FAIL'), 'ENT-9 security compliance governance is GO.');

        $cicdEnterpriseGate = $this->cicdEnterpriseGateGovernance->collect();
        $checks[] = $this->decisionCheck('ENT11-DR-ENT10-CICD-ENTERPRISE-GATE', (string) ($cicdEnterpriseGate['decision'] ?? 'FAIL'), 'ENT-10 CI/CD enterprise gate governance is GO.');

        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));
        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'sprint' => 'ENT-11',
            'decision' => $decision,
            'readiness_status' => $decision === 'GO' ? 'deployment_rollback_ready' : strtolower($decision),
            'deployment_rollback_enabled' => $enabled,
            'deploy_script_ok' => $deploy['ok'],
            'deploy_backup_before_migrate' => $deploy['backup_before_migrate'] ?? false,
            'deploy_migrate_force_present' => $deploy['migrate_force_present'] ?? false,
            'deploy_no_destructive_command' => $deploy['no_destructive_command'] ?? false,
            'deploy_cache_order_preserved' => $deploy['cache_order_preserved'] ?? false,
            'rollback_script_ok' => $rollback['ok'],
            'rollback_present' => $rollback['file_present'] ?? false,
            'rollback_fail_fast' => $rollback['fail_fast'] ?? false,
            'rollback_records_current_ref' => $rollback['records_current_ref'] ?? false,
            'rollback_backup_before_checkout' => $rollback['backup_before_checkout'] ?? false,
            'rollback_no_destructive_command' => $rollback['no_destructive_command'] ?? false,
            'rollback_cache_order_preserved' => $rollback['cache_order_preserved'] ?? false,
            'rollback_reverifies_foundation_gates' => $rollback['reverifies_foundation_gates'] ?? false,
            'evidence_profiles_ok' => $evidence['ok'],
            'evidence_artifact' => $evidence['artifact'],
            'release_safety_ok' => $releaseSafety['ok'],
            'rollback_checklist_ok' => $releaseSafety['rollback_checklist_ok'] ?? false,
            'pre_deploy_gate_ok' => $releaseSafety['pre_deploy_gate_ok'] ?? false,
            'queue_retry_decision' => $queueRetry['decision'] ?? 'UNKNOWN',
            'idempotency_outbox_decision' => $idempotencyOutbox['decision'] ?? 'UNKNOWN',
            'developer_console_decision' => $developerConsole['decision'] ?? 'UNKNOWN',
            'health_check_decision' => $healthCheck['decision'] ?? 'UNKNOWN',
            'security_compliance_decision' => $securityCompliance['decision'] ?? 'UNKNOWN',
            'cicd_enterprise_gate_decision' => $cicdEnterpriseGate['decision'] ?? 'UNKNOWN',
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
                'foundation:deployment-rollback-check',
                'foundation:cicd-enterprise-gate-check',
                'foundation:release-safety-check',
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
            default => $this->fail($id, "{$id} is {$decision}; ENT-11 cannot be GO."),
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
