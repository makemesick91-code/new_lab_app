<?php

namespace App\Services\Foundation;

use App\Support\LoadTest\LoadTestScaleProjectionScanner;

/**
 * ENT-14 — Load Test Scale Projection governance layer.
 *
 * Locks the scale-projection harness (analysis runner + readiness gate) on top
 * of the ENT-13 5-branch measured baseline and the shipped LB-1 (horizontal
 * scale) and REPLICA-1 (read routing) readiness foundations. It validates the
 * harness script, the runner command, the projection tiers, the baseline
 * linkage, the bottleneck taxonomy, the LB-1/REPLICA-1 foundation linkage, the
 * release-evidence profiles, and the release-safety pre-deploy gate, and confirms
 * the ENT-5..13 foundations it builds on remain GO. It stays separate from the
 * sibling governance services so their contracts remain intact and is
 * informational only (not wired into the blocking combined decision).
 */
class LoadTestScaleProjectionGovernanceService
{
    public function __construct(
        private readonly LoadTestScaleProjectionScanner $scanner,
        private readonly QueueRetryFailedJobGovernanceService $queueRetryGovernance,
        private readonly IdempotencyOutboxGovernanceService $idempotencyOutboxGovernance,
        private readonly DeveloperConsoleGovernanceService $developerConsoleGovernance,
        private readonly HealthCheckGovernanceService $healthCheckGovernance,
        private readonly SecurityComplianceGovernanceService $securityComplianceGovernance,
        private readonly CicdEnterpriseGateGovernanceService $cicdEnterpriseGateGovernance,
        private readonly DeploymentRollbackGovernanceService $deploymentRollbackGovernance,
        private readonly BackupDrGovernanceService $backupDrGovernance,
        private readonly LoadTestBaselineGovernanceService $loadTestBaselineGovernance,
    ) {}

    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'ENT14-SP001',
                'title' => 'The scale projection is analysis-only',
                'description' => 'The projection extrapolates capacity from the measured ENT-13 baseline. It never activates replica read routing, never switches on multi-node traffic, and never changes production topology; the harness and the loadtest:scale-projection-run command abort on production/pilot.',
            ],
            [
                'id' => 'ENT14-SP002',
                'title' => 'The projection is driven by the canonical harness script',
                'description' => 'The projection runs through scripts/load-test-scale-projection.sh (fail-fast, non-production guarded → invokes loadtest:scale-projection-run → writes a non-sensitive projection pack). Ad-hoc manual math is not the projection path; the harness is the mandatory, idempotent path.',
            ],
            [
                'id' => 'ENT14-SP003',
                'title' => 'Projection is anchored on the ENT-13 measured baseline',
                'description' => 'The projection is derived from the ENT-13 5-branch baseline (branch count, user mix, latency targets). The lowest projection tier matches the baseline branch count so the model is extrapolation, not fabrication. A projection without baseline evidence is a NO-GO.',
            ],
            [
                'id' => 'ENT14-SP004',
                'title' => 'The projection covers the required scale tiers',
                'description' => 'The tiers cover the baseline (5 cabang), the roadmap-mandated 20-branch target, and a national tier (50 cabang), with monotonic-increasing branch counts and positive scale factors so growth is modeled end to end.',
            ],
            [
                'id' => 'ENT14-SP005',
                'title' => 'Projected numbers are modeled/estimated, never a measured claim',
                'description' => 'Every projected user count, patient volume, and latency headroom is labeled modeled/estimated in the evidence pack (projection_basis). No projection is presented as a real production benchmark; scalability is never claimed without the baseline it extrapolates from.',
            ],
            [
                'id' => 'ENT14-SP006',
                'title' => 'Higher tiers tie to the shipped LB-1 / REPLICA-1 foundations',
                'description' => 'The 20-branch and national tiers reference the shipped LB-1 (horizontal scale) and REPLICA-1 (read routing) readiness foundations as the mitigation path, so the projection points at real readiness work instead of an unproven claim.',
            ],
            [
                'id' => 'ENT14-SP007',
                'title' => 'Bottlenecks are classified against a fixed taxonomy',
                'description' => 'Each tier capacity note is categorized against db/cache/queue/php/network/frontend/storage — the same taxonomy as the ENT-13 baseline — so the projected risks and the follow-up actions are comparable across tiers and runs.',
            ],
            [
                'id' => 'ENT14-SP008',
                'title' => 'The projection evidence pack is non-sensitive',
                'description' => 'No secret, credential, environment value, or KTP/NIK-shaped value is ever written to a projection evidence artifact or printed by the gate; every artifact passes the release-evidence forbidden-pattern/regex scan (tiers, modeled counts, risks, and next actions only).',
            ],
            [
                'id' => 'ENT14-SP009',
                'title' => 'Projection readiness evidence is required per release profile',
                'description' => 'The ci and vps release-evidence profiles require the ENT-14 load-test-scale-projection-check.json artifact plus the ENT-11..13 sibling artifacts; release:evidence-capture/check produce and validate them.',
            ],
            [
                'id' => 'ENT14-SP010',
                'title' => 'Projection readiness is a mandatory pre-deploy gate',
                'description' => 'foundation:load-test-scale-projection-check is listed in the release-safety pre-deploy gates and runs (with evidence capture) in the deploy script and CI so the projection readiness is re-verified before every release.',
            ],
            [
                'id' => 'ENT14-SP011',
                'title' => 'Projection governance builds on and preserves the ENT-5..13 foundations',
                'description' => 'The gate re-verifies the queue-retry, idempotency/outbox, developer-console, health-check, security-compliance, cicd-enterprise-gate, deployment-rollback, backup/DR, and load-test-baseline governance; those strict checks must stay GO for the projection gate to be GO.',
            ],
            [
                'id' => 'ENT14-SP012',
                'title' => 'New tiers/mitigations register here with tests first',
                'description' => 'Any future tier, scale-factor change, mitigation, or bottleneck follow-up must extend this contract with coverage and tests and pass foundation:load-test-scale-projection-check before shipping. Projection never activates production topology change.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $checks = [];

        $enabled = (bool) config('load_test_scale_projection.enabled', true);
        $checks[] = $enabled
            ? $this->pass('ENT14-SP-ENABLED', 'Load test scale projection governance is enabled.')
            : $this->warn('ENT14-SP-ENABLED', 'Load test scale projection governance is disabled by configuration.');

        $harness = $this->scanner->harnessScriptPosture();
        $checks[] = $harness['ok']
            ? $this->pass('ENT14-SP-HARNESS', 'Scale-projection harness script is safe (fail-fast, non-production guard, runs runner, writes evidence, no destructive command).')
            : $this->fail('ENT14-SP-HARNESS', 'Scale-projection harness posture failed: '.implode('; ', $harness['issues']).'.');

        $runner = $this->scanner->runnerCommandPosture();
        $checks[] = $runner['ok']
            ? $this->pass('ENT14-SP-RUNNER', 'Projection runner command (loadtest:scale-projection-run) is registered.')
            : $this->fail('ENT14-SP-RUNNER', 'Projection runner posture failed: '.implode('; ', $runner['issues']).'.');

        $tiers = $this->scanner->tiersPosture();
        $checks[] = $tiers['ok']
            ? $this->pass('ENT14-SP-TIERS', 'Projection tiers are monotonic and cover the baseline/20-branch/national targets.')
            : $this->fail('ENT14-SP-TIERS', 'Tier posture failed: '.implode('; ', $tiers['issues']).'.');

        $baseline = $this->scanner->baselineLinkagePosture();
        $checks[] = $baseline['ok']
            ? $this->pass('ENT14-SP-BASELINE', 'Projection is anchored on the ENT-13 baseline (lowest tier matches baseline branch count).')
            : $this->fail('ENT14-SP-BASELINE', 'Baseline linkage failed: '.implode('; ', $baseline['issues']).'.');

        $bottlenecks = $this->scanner->bottleneckTaxonomyPosture();
        $checks[] = $bottlenecks['ok']
            ? $this->pass('ENT14-SP-BOTTLENECKS', 'Bottleneck taxonomy declares all seven categories.')
            : $this->fail('ENT14-SP-BOTTLENECKS', 'Bottleneck taxonomy failed: '.implode('; ', $bottlenecks['issues']).'.');

        $foundations = $this->scanner->foundationLinkagePosture();
        $checks[] = $foundations['ok']
            ? $this->pass('ENT14-SP-FOUNDATIONS', 'Higher tiers tie to the shipped LB-1 and REPLICA-1 readiness foundations.')
            : $this->fail('ENT14-SP-FOUNDATIONS', 'Foundation linkage failed: '.implode('; ', $foundations['issues']).'.');

        $evidence = $this->scanner->evidenceProfilePosture();
        $checks[] = $evidence['ok']
            ? $this->pass('ENT14-SP-EVIDENCE', 'Release-evidence ci/vps profiles require the ENT-14 artifact and ENT-11..13 siblings.')
            : $this->fail('ENT14-SP-EVIDENCE', 'Release-evidence profile posture failed: '.implode('; ', $evidence['issues']).'.');

        $releaseSafety = $this->scanner->releaseSafetyPosture();
        $checks[] = $releaseSafety['ok']
            ? $this->pass('ENT14-SP-RELEASE-SAFETY', 'Release-safety includes the ENT-14 pre-deploy gate.')
            : $this->fail('ENT14-SP-RELEASE-SAFETY', 'Release-safety posture failed: '.implode('; ', $releaseSafety['issues']).'.');

        $queueRetry = $this->queueRetryGovernance->collect();
        $checks[] = $this->decisionCheck('ENT14-SP-ENT5-QUEUE-RETRY', (string) ($queueRetry['decision'] ?? 'FAIL'), 'ENT-5 queue retry governance is GO.');

        $idempotencyOutbox = $this->idempotencyOutboxGovernance->collect();
        $checks[] = $this->decisionCheck('ENT14-SP-ENT6-IDEMPOTENCY-OUTBOX', (string) ($idempotencyOutbox['decision'] ?? 'FAIL'), 'ENT-6 idempotency/outbox governance is GO.');

        $developerConsole = $this->developerConsoleGovernance->collect();
        $checks[] = $this->decisionCheck('ENT14-SP-ENT7-DEVELOPER-CONSOLE', (string) ($developerConsole['decision'] ?? 'FAIL'), 'ENT-7 developer-console governance is GO.');

        $healthCheck = $this->healthCheckGovernance->collect();
        $checks[] = $this->decisionCheck('ENT14-SP-ENT8-HEALTH-CHECK', (string) ($healthCheck['decision'] ?? 'FAIL'), 'ENT-8 health-check governance is GO.');

        $securityCompliance = $this->securityComplianceGovernance->collect();
        $checks[] = $this->decisionCheck('ENT14-SP-ENT9-SECURITY-COMPLIANCE', (string) ($securityCompliance['decision'] ?? 'FAIL'), 'ENT-9 security compliance governance is GO.');

        $cicdEnterpriseGate = $this->cicdEnterpriseGateGovernance->collect();
        $checks[] = $this->decisionCheck('ENT14-SP-ENT10-CICD-ENTERPRISE-GATE', (string) ($cicdEnterpriseGate['decision'] ?? 'FAIL'), 'ENT-10 CI/CD enterprise gate governance is GO.');

        $deploymentRollback = $this->deploymentRollbackGovernance->collect();
        $checks[] = $this->decisionCheck('ENT14-SP-ENT11-DEPLOYMENT-ROLLBACK', (string) ($deploymentRollback['decision'] ?? 'FAIL'), 'ENT-11 deployment/rollback governance is GO.');

        $backupDr = $this->backupDrGovernance->collect();
        $checks[] = $this->decisionCheck('ENT14-SP-ENT12-BACKUP-DR', (string) ($backupDr['decision'] ?? 'FAIL'), 'ENT-12 backup/DR governance is GO.');

        $loadTestBaseline = $this->loadTestBaselineGovernance->collect();
        $checks[] = $this->decisionCheck('ENT14-SP-ENT13-LOAD-TEST-BASELINE', (string) ($loadTestBaseline['decision'] ?? 'FAIL'), 'ENT-13 load-test baseline governance is GO.');

        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));
        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'sprint' => 'ENT-14',
            'decision' => $decision,
            'readiness_status' => $decision === 'GO' ? 'load_test_scale_projection_ready' : strtolower($decision),
            'load_test_scale_projection_enabled' => $enabled,
            'harness_script_ok' => $harness['ok'],
            'harness_fail_fast' => $harness['fail_fast'] ?? false,
            'harness_non_production_guard' => $harness['non_production_guard'] ?? false,
            'harness_runs_runner' => $harness['runs_runner'] ?? false,
            'harness_no_destructive_command' => $harness['no_destructive_command'] ?? false,
            'runner_ok' => $runner['ok'],
            'runner_registered' => $runner['registered'] ?? false,
            'tiers_ok' => $tiers['ok'],
            'tier_count' => $tiers['tier_count'] ?? 0,
            'tier_branch_counts' => $tiers['branch_counts'] ?? [],
            'baseline_linkage_ok' => $baseline['ok'],
            'baseline_branch_count' => $baseline['baseline_branch_count'] ?? 0,
            'bottlenecks_ok' => $bottlenecks['ok'],
            'foundation_linkage_ok' => $foundations['ok'],
            'related_shipped_foundations' => $foundations['related_shipped_foundations'] ?? [],
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
            'backup_dr_decision' => $backupDr['decision'] ?? 'UNKNOWN',
            'load_test_baseline_decision' => $loadTestBaseline['decision'] ?? 'UNKNOWN',
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
                'foundation:load-test-scale-projection-check',
                'loadtest:scale-projection-run',
                'foundation:load-test-baseline-check',
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
            default => $this->fail($id, "{$id} is {$decision}; ENT-14 cannot be GO."),
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
