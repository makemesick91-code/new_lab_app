<?php

namespace App\Services\Foundation;

use App\Support\Cicd\CicdEnterpriseGateScanner;

/**
 * ENT-10 — CI/CD Enterprise Gate governance layer.
 *
 * Extends the shipped NSF-9/NSF-10 flag/smoke/evidence gates into a full
 * enterprise CI/CD gate: it validates the deploy script (backup-before-migrate,
 * migration safety, no destructive DB command, ENT-8 cache-order preserved,
 * cache rebuild, foundation gate + evidence commands), the CI workflow/script
 * (pull_request trigger, foundation stack, fail-fast, no destructive command),
 * the release-evidence profiles, and the release-safety pre-deploy gate, and
 * confirms the ENT-5 / ENT-6 / ENT-7 / ENT-8 / ENT-9 foundations it builds on
 * remain GO. It stays separate from the sibling governance services so their
 * contracts remain intact and is informational only (not wired into the
 * blocking combined decision).
 */
class CicdEnterpriseGateGovernanceService
{
    public function __construct(
        private readonly CicdEnterpriseGateScanner $scanner,
        private readonly QueueRetryFailedJobGovernanceService $queueRetryGovernance,
        private readonly IdempotencyOutboxGovernanceService $idempotencyOutboxGovernance,
        private readonly DeveloperConsoleGovernanceService $developerConsoleGovernance,
        private readonly HealthCheckGovernanceService $healthCheckGovernance,
        private readonly SecurityComplianceGovernanceService $securityComplianceGovernance,
    ) {}

    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'ENT10-CICD001',
                'title' => 'Enterprise CI gate runs on every pull request',
                'description' => 'The Foundation Evidence Gates workflow triggers on pull_request to the approved base branch so a failed gate blocks merge. CI never targets main; the base branch is the only release path.',
            ],
            [
                'id' => 'ENT10-CICD002',
                'title' => 'CI/deploy validate the ENT-5..9 foundation stack before release',
                'description' => 'The CI workflow/script and the deploy script run the queue-retry, idempotency/outbox, developer-console, health-check, and security-compliance governance checks; those strict checks must stay GO for the enterprise gate to be GO.',
            ],
            [
                'id' => 'ENT10-CICD003',
                'title' => 'Required release-evidence artifacts are produced and verified per profile',
                'description' => 'The ci and vps release-evidence profiles require the ENT-10 cicd-enterprise-gate-check.json artifact plus the ENT-5..9 sibling artifacts; release:evidence-capture/check produce and validate them.',
            ],
            [
                'id' => 'ENT10-CICD004',
                'title' => 'Deploy verifies a DB backup before pull/migrate',
                'description' => 'The deploy script takes a pg_dump backup before pulling and migrating, and backup failure stops the deploy (set -euo pipefail). No deploy runs without a verified backup.',
            ],
            [
                'id' => 'ENT10-CICD005',
                'title' => 'Migration safety: migrate --force only, never destructive',
                'description' => 'The deploy/CI chain uses `php artisan migrate --force` and never runs migrate:fresh, migrate:reset, db:wipe, schema:drop, DROP DATABASE/SCHEMA, or TRUNCATE. Migrations stay additive and production-safe.',
            ],
            [
                'id' => 'ENT10-CICD006',
                'title' => 'ENT-8 cache-order hardening is preserved',
                'description' => 'Route/config cache is cleared before the route-dependent governance gates in the deploy script so freshly deployed routes are visible; reordering this fails the gate.',
            ],
            [
                'id' => 'ENT10-CICD007',
                'title' => 'Route/config sanity: caches rebuilt after gates',
                'description' => 'The deploy script rebuilds config:cache, route:cache, and view:cache after the gate phase so the running app serves cached, sane route/config state.',
            ],
            [
                'id' => 'ENT10-CICD008',
                'title' => 'Gate failure exits non-zero; no gate skipped silently',
                'description' => 'CI/deploy scripts run under set -euo pipefail and the governance commands return non-zero on FAIL (and on WATCH under --strict). A failing gate can never be silently ignored.',
            ],
            [
                'id' => 'ENT10-CICD009',
                'title' => 'Evidence artifacts stay non-sensitive',
                'description' => 'Every captured evidence artifact passes the release-evidence forbidden-pattern/regex scan; no secret, credential, or KTP/NIK-shaped value is ever written to an artifact or printed by a gate.',
            ],
            [
                'id' => 'ENT10-CICD010',
                'title' => 'Release-safety pre-deploy gate includes the enterprise gate',
                'description' => 'config/release_safety.php lists the ENT-5..9 foundation checks and foundation:cicd-enterprise-gate-check as required pre-deploy gates, and each command is registered.',
            ],
            [
                'id' => 'ENT10-CICD011',
                'title' => 'Destructive-command patterns live in config, not source',
                'description' => 'The destructive-command literals scanned for live in config/cicd_enterprise_gate.php so no app, CI, or deploy source file carries the sensitive patterns inline (mirrors the ENT-9 config-not-code convention).',
            ],
            [
                'id' => 'ENT10-CICD012',
                'title' => 'New CI/deploy gates register here with tests first',
                'description' => 'Any future CI/deploy gate, evidence artifact, or release-safety gate must extend this contract with coverage and tests and pass foundation:cicd-enterprise-gate-check before shipping.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $checks = [];

        $enabled = (bool) config('cicd_enterprise_gate.enabled', true);
        $checks[] = $enabled
            ? $this->pass('ENT10-CICD-ENABLED', 'CI/CD enterprise gate governance is enabled.')
            : $this->warn('ENT10-CICD-ENABLED', 'CI/CD enterprise gate governance is disabled by configuration.');

        $deploy = $this->scanner->deployScriptPosture();
        $checks[] = $deploy['ok']
            ? $this->pass('ENT10-CICD-DEPLOY-SCRIPT', 'Deploy script is safe (backup-before-migrate, migrate --force, no destructive command, ENT-8 cache-order preserved, foundation + evidence commands present).')
            : $this->fail('ENT10-CICD-DEPLOY-SCRIPT', 'Deploy script posture failed: '.implode('; ', $deploy['issues']).'.');

        $ci = $this->scanner->ciPosture();
        $checks[] = $ci['ok']
            ? $this->pass('ENT10-CICD-CI', 'CI workflow/script run the foundation stack on pull requests, fail-fast, no destructive command.')
            : $this->fail('ENT10-CICD-CI', 'CI posture failed: '.implode('; ', $ci['issues']).'.');

        $evidence = $this->scanner->evidenceProfilePosture();
        $checks[] = $evidence['ok']
            ? $this->pass('ENT10-CICD-EVIDENCE', 'Release-evidence ci/vps profiles require the ENT-10 artifact and ENT-5..9 siblings.')
            : $this->fail('ENT10-CICD-EVIDENCE', 'Release-evidence profile posture failed: '.implode('; ', $evidence['issues']).'.');

        $preDeploy = $this->scanner->preDeployGatePosture();
        $checks[] = $preDeploy['ok']
            ? $this->pass('ENT10-CICD-PRE-DEPLOY-GATE', 'Release-safety pre-deploy gate includes the ENT-5..9 checks and the enterprise gate.')
            : $this->fail('ENT10-CICD-PRE-DEPLOY-GATE', 'Release-safety pre-deploy gate missing command(s): '.implode(', ', $preDeploy['missing_gate_commands']).'.');

        $queueRetry = $this->queueRetryGovernance->collect();
        $checks[] = $this->decisionCheck('ENT10-CICD-ENT5-QUEUE-RETRY', (string) ($queueRetry['decision'] ?? 'FAIL'), 'ENT-5 queue retry governance is GO.');

        $idempotencyOutbox = $this->idempotencyOutboxGovernance->collect();
        $checks[] = $this->decisionCheck('ENT10-CICD-ENT6-IDEMPOTENCY-OUTBOX', (string) ($idempotencyOutbox['decision'] ?? 'FAIL'), 'ENT-6 idempotency/outbox governance is GO.');

        $developerConsole = $this->developerConsoleGovernance->collect();
        $checks[] = $this->decisionCheck('ENT10-CICD-ENT7-DEVELOPER-CONSOLE', (string) ($developerConsole['decision'] ?? 'FAIL'), 'ENT-7 developer-console governance is GO.');

        $healthCheck = $this->healthCheckGovernance->collect();
        $checks[] = $this->decisionCheck('ENT10-CICD-ENT8-HEALTH-CHECK', (string) ($healthCheck['decision'] ?? 'FAIL'), 'ENT-8 health-check governance is GO.');

        $securityCompliance = $this->securityComplianceGovernance->collect();
        $checks[] = $this->decisionCheck('ENT10-CICD-ENT9-SECURITY-COMPLIANCE', (string) ($securityCompliance['decision'] ?? 'FAIL'), 'ENT-9 security compliance governance is GO.');

        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));
        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'sprint' => 'ENT-10',
            'decision' => $decision,
            'readiness_status' => $decision === 'GO' ? 'cicd_enterprise_gate_ready' : strtolower($decision),
            'cicd_enterprise_gate_enabled' => $enabled,
            'deploy_script_ok' => $deploy['ok'],
            'deploy_no_destructive_command' => $deploy['no_destructive_command'] ?? false,
            'deploy_migrate_force_present' => $deploy['migrate_force_present'] ?? false,
            'deploy_backup_before_migrate' => $deploy['backup_before_migrate'] ?? false,
            'deploy_cache_order_preserved' => $deploy['cache_order_preserved'] ?? false,
            'deploy_cache_rebuild_present' => $deploy['cache_rebuild_present'] ?? false,
            'ci_ok' => $ci['ok'],
            'ci_pull_request_trigger' => $ci['pull_request_trigger'] ?? false,
            'ci_fail_fast' => $ci['fail_fast'] ?? false,
            'ci_no_destructive_command' => $ci['no_destructive_command'] ?? false,
            'evidence_profiles_ok' => $evidence['ok'],
            'evidence_artifact' => $evidence['artifact'],
            'pre_deploy_gate_ok' => $preDeploy['ok'],
            'queue_retry_decision' => $queueRetry['decision'] ?? 'UNKNOWN',
            'idempotency_outbox_decision' => $idempotencyOutbox['decision'] ?? 'UNKNOWN',
            'developer_console_decision' => $developerConsole['decision'] ?? 'UNKNOWN',
            'health_check_decision' => $healthCheck['decision'] ?? 'UNKNOWN',
            'security_compliance_decision' => $securityCompliance['decision'] ?? 'UNKNOWN',
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
                'foundation:cicd-enterprise-gate-check',
                'foundation:security-compliance-check',
                'foundation:health-check',
                'foundation:developer-console-check',
                'foundation:idempotency-outbox-check',
                'foundation:queue-retry-failed-job-check',
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
            default => $this->fail($id, "{$id} is {$decision}; ENT-10 cannot be GO."),
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
