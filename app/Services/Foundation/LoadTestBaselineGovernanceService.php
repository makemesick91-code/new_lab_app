<?php

namespace App\Services\Foundation;

use App\Support\LoadTest\LoadTestBaselineScanner;

/**
 * ENT-13 — Load Test 5 Cabang Baseline governance layer.
 *
 * Locks the 5-branch baseline load test harness (dataset + scenario runner) and
 * its readiness gate on top of the ENT-2 database performance contract, the
 * ENT-4 Redis cache policy, and the ENT-12 backup/DR automation. It validates
 * the harness script, the runner command, the dataset seeders, the
 * scenario/bottleneck taxonomy, the latency targets, the release-evidence
 * profiles, and the release-safety pre-deploy gate, and confirms the ENT-5..12
 * foundations it builds on remain GO. It stays separate from the sibling
 * governance services so their contracts remain intact and is informational
 * only (not wired into the blocking combined decision).
 */
class LoadTestBaselineGovernanceService
{
    public function __construct(
        private readonly LoadTestBaselineScanner $scanner,
        private readonly QueueRetryFailedJobGovernanceService $queueRetryGovernance,
        private readonly IdempotencyOutboxGovernanceService $idempotencyOutboxGovernance,
        private readonly DeveloperConsoleGovernanceService $developerConsoleGovernance,
        private readonly HealthCheckGovernanceService $healthCheckGovernance,
        private readonly SecurityComplianceGovernanceService $securityComplianceGovernance,
        private readonly CicdEnterpriseGateGovernanceService $cicdEnterpriseGateGovernance,
        private readonly DeploymentRollbackGovernanceService $deploymentRollbackGovernance,
        private readonly BackupDrGovernanceService $backupDrGovernance,
    ) {}

    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'ENT13-LT001',
                'title' => 'The baseline load test runs on a non-production environment only',
                'description' => 'The harness script and the loadtest:baseline-run command abort on production/pilot; the 5-branch baseline is exercised against non-production data/environments only. Load testing against the production VPS pilot database is out of scope by roadmap rule.',
            ],
            [
                'id' => 'ENT13-LT002',
                'title' => 'The load test is driven by the canonical harness script',
                'description' => 'The baseline runs through scripts/load-test-baseline.sh (fail-fast, non-production guarded → invokes loadtest:baseline-run → writes a non-sensitive evidence pack). Ad-hoc manual timing is not the baseline path; the harness is the mandatory, idempotent baseline.',
            ],
            [
                'id' => 'ENT13-LT003',
                'title' => 'The 5-branch baseline dataset comes from the stress:seed-* harness',
                'description' => 'The 60k–70k patient dataset across 5 branches is produced by the existing stress:seed-foundation / stress:seed-patients / stress:seed-rme-history commands, which themselves only run in local/stress/testing. No production data is copied for load testing.',
            ],
            [
                'id' => 'ENT13-LT004',
                'title' => 'The baseline covers the required scenario pages',
                'description' => 'The scenarios cover RME (patient queue + RM lookup), cashier/invoice, owner dashboard, inventory stock, and reports/receivable — the pages named in the roadmap objective — so the baseline measures the real hot paths, not a synthetic subset.',
            ],
            [
                'id' => 'ENT13-LT005',
                'title' => 'Latency targets are the documented 200–300ms band',
                'description' => 'p50 target 200ms and p95 target 300ms are declared as positive, ordered integers so the baseline results are assessed against a measurable target rather than an aspiration.',
            ],
            [
                'id' => 'ENT13-LT006',
                'title' => 'Bottlenecks are classified against a fixed taxonomy',
                'description' => 'Every scenario slower than target is categorized against db/cache/queue/php/network/frontend/storage so the slow-query follow-up list is actionable and comparable across runs and future scale projection (ENT-14).',
            ],
            [
                'id' => 'ENT13-LT007',
                'title' => 'The baseline evidence pack is non-sensitive',
                'description' => 'No secret, credential, environment value, or KTP/NIK-shaped value is ever written to a load-test evidence artifact or printed by the gate; every artifact passes the release-evidence forbidden-pattern/regex scan (per-scenario timings, counts, and category labels only).',
            ],
            [
                'id' => 'ENT13-LT008',
                'title' => 'The baseline builds on the ENT-2 / ENT-4 performance foundations',
                'description' => 'The baseline validates the database performance contract (ENT-2) and the Redis cache policy (ENT-4) it depends on; it never weakens branch scoping, adds an unbounded trx_* scan, or mutates inventory stock while measuring.',
            ],
            [
                'id' => 'ENT13-LT009',
                'title' => 'Load-test readiness evidence is required per release profile',
                'description' => 'The ci and vps release-evidence profiles require the ENT-13 load-test-baseline-check.json artifact plus the ENT-10..12 sibling artifacts; release:evidence-capture/check produce and validate them.',
            ],
            [
                'id' => 'ENT13-LT010',
                'title' => 'Load-test readiness is a mandatory pre-deploy gate',
                'description' => 'foundation:load-test-baseline-check is listed in the release-safety pre-deploy gates and runs (with evidence capture) in the deploy script and CI so the harness readiness is re-verified before every release.',
            ],
            [
                'id' => 'ENT13-LT011',
                'title' => 'Load-test governance builds on and preserves the ENT-5..12 foundations',
                'description' => 'The gate re-verifies the queue-retry, idempotency/outbox, developer-console, health-check, security-compliance, cicd-enterprise-gate, deployment-rollback, and backup/DR governance; those strict checks must stay GO for the load-test gate to be GO.',
            ],
            [
                'id' => 'ENT13-LT012',
                'title' => 'New load-test scenarios register here with tests first',
                'description' => 'Any future scenario, dataset change, latency target, or bottleneck follow-up must extend this contract with coverage and tests and pass foundation:load-test-baseline-check before shipping. Load tests never endanger the production database.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $checks = [];

        $enabled = (bool) config('load_test_baseline.enabled', true);
        $checks[] = $enabled
            ? $this->pass('ENT13-LT-ENABLED', 'Load test 5 cabang baseline governance is enabled.')
            : $this->warn('ENT13-LT-ENABLED', 'Load test baseline governance is disabled by configuration.');

        $harness = $this->scanner->harnessScriptPosture();
        $checks[] = $harness['ok']
            ? $this->pass('ENT13-LT-HARNESS', 'Load-test harness script is safe (fail-fast, non-production guard, runs runner, writes evidence, no destructive command).')
            : $this->fail('ENT13-LT-HARNESS', 'Load-test harness posture failed: '.implode('; ', $harness['issues']).'.');

        $runner = $this->scanner->runnerCommandPosture();
        $checks[] = $runner['ok']
            ? $this->pass('ENT13-LT-RUNNER', 'Baseline runner command (loadtest:baseline-run) is registered.')
            : $this->fail('ENT13-LT-RUNNER', 'Baseline runner posture failed: '.implode('; ', $runner['issues']).'.');

        $dataset = $this->scanner->datasetPosture();
        $checks[] = $dataset['ok']
            ? $this->pass('ENT13-LT-DATASET', 'Dataset target band (60k–70k) and stress:seed-* harness are declared.')
            : $this->fail('ENT13-LT-DATASET', 'Dataset posture failed: '.implode('; ', $dataset['issues']).'.');

        $scenarios = $this->scanner->scenarioCoveragePosture();
        $checks[] = $scenarios['ok']
            ? $this->pass('ENT13-LT-SCENARIOS', 'Baseline scenarios cover the required RME/cashier/dashboard/inventory/reports domains.')
            : $this->fail('ENT13-LT-SCENARIOS', 'Scenario coverage failed: '.implode('; ', $scenarios['issues']).'.');

        $bottlenecks = $this->scanner->bottleneckTaxonomyPosture();
        $checks[] = $bottlenecks['ok']
            ? $this->pass('ENT13-LT-BOTTLENECKS', 'Bottleneck taxonomy declares all seven categories.')
            : $this->fail('ENT13-LT-BOTTLENECKS', 'Bottleneck taxonomy failed: '.implode('; ', $bottlenecks['issues']).'.');

        $objectives = $this->scanner->objectivesPosture();
        $checks[] = $objectives['ok']
            ? $this->pass('ENT13-LT-OBJECTIVES', 'Latency targets (200–300ms), branch count, and user mix are positive and ordered.')
            : $this->fail('ENT13-LT-OBJECTIVES', 'Objectives posture failed: '.implode('; ', $objectives['issues']).'.');

        $evidence = $this->scanner->evidenceProfilePosture();
        $checks[] = $evidence['ok']
            ? $this->pass('ENT13-LT-EVIDENCE', 'Release-evidence ci/vps profiles require the ENT-13 artifact and ENT-10..12 siblings.')
            : $this->fail('ENT13-LT-EVIDENCE', 'Release-evidence profile posture failed: '.implode('; ', $evidence['issues']).'.');

        $releaseSafety = $this->scanner->releaseSafetyPosture();
        $checks[] = $releaseSafety['ok']
            ? $this->pass('ENT13-LT-RELEASE-SAFETY', 'Release-safety includes the ENT-13 pre-deploy gate.')
            : $this->fail('ENT13-LT-RELEASE-SAFETY', 'Release-safety posture failed: '.implode('; ', $releaseSafety['issues']).'.');

        $queueRetry = $this->queueRetryGovernance->collect();
        $checks[] = $this->decisionCheck('ENT13-LT-ENT5-QUEUE-RETRY', (string) ($queueRetry['decision'] ?? 'FAIL'), 'ENT-5 queue retry governance is GO.');

        $idempotencyOutbox = $this->idempotencyOutboxGovernance->collect();
        $checks[] = $this->decisionCheck('ENT13-LT-ENT6-IDEMPOTENCY-OUTBOX', (string) ($idempotencyOutbox['decision'] ?? 'FAIL'), 'ENT-6 idempotency/outbox governance is GO.');

        $developerConsole = $this->developerConsoleGovernance->collect();
        $checks[] = $this->decisionCheck('ENT13-LT-ENT7-DEVELOPER-CONSOLE', (string) ($developerConsole['decision'] ?? 'FAIL'), 'ENT-7 developer-console governance is GO.');

        $healthCheck = $this->healthCheckGovernance->collect();
        $checks[] = $this->decisionCheck('ENT13-LT-ENT8-HEALTH-CHECK', (string) ($healthCheck['decision'] ?? 'FAIL'), 'ENT-8 health-check governance is GO.');

        $securityCompliance = $this->securityComplianceGovernance->collect();
        $checks[] = $this->decisionCheck('ENT13-LT-ENT9-SECURITY-COMPLIANCE', (string) ($securityCompliance['decision'] ?? 'FAIL'), 'ENT-9 security compliance governance is GO.');

        $cicdEnterpriseGate = $this->cicdEnterpriseGateGovernance->collect();
        $checks[] = $this->decisionCheck('ENT13-LT-ENT10-CICD-ENTERPRISE-GATE', (string) ($cicdEnterpriseGate['decision'] ?? 'FAIL'), 'ENT-10 CI/CD enterprise gate governance is GO.');

        $deploymentRollback = $this->deploymentRollbackGovernance->collect();
        $checks[] = $this->decisionCheck('ENT13-LT-ENT11-DEPLOYMENT-ROLLBACK', (string) ($deploymentRollback['decision'] ?? 'FAIL'), 'ENT-11 deployment/rollback governance is GO.');

        $backupDr = $this->backupDrGovernance->collect();
        $checks[] = $this->decisionCheck('ENT13-LT-ENT12-BACKUP-DR', (string) ($backupDr['decision'] ?? 'FAIL'), 'ENT-12 backup/DR governance is GO.');

        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));
        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'sprint' => 'ENT-13',
            'decision' => $decision,
            'readiness_status' => $decision === 'GO' ? 'load_test_baseline_ready' : strtolower($decision),
            'load_test_baseline_enabled' => $enabled,
            'harness_script_ok' => $harness['ok'],
            'harness_fail_fast' => $harness['fail_fast'] ?? false,
            'harness_non_production_guard' => $harness['non_production_guard'] ?? false,
            'harness_runs_runner' => $harness['runs_runner'] ?? false,
            'harness_no_destructive_command' => $harness['no_destructive_command'] ?? false,
            'runner_ok' => $runner['ok'],
            'runner_registered' => $runner['registered'] ?? false,
            'dataset_ok' => $dataset['ok'],
            'target_patients_min' => $dataset['target_patients_min'] ?? 0,
            'target_patients_max' => $dataset['target_patients_max'] ?? 0,
            'scenarios_ok' => $scenarios['ok'],
            'scenario_count' => $scenarios['scenario_count'] ?? 0,
            'bottlenecks_ok' => $bottlenecks['ok'],
            'objectives_ok' => $objectives['ok'],
            'p50_target_ms' => $objectives['p50_target_ms'] ?? 0,
            'p95_target_ms' => $objectives['p95_target_ms'] ?? 0,
            'branch_count' => $objectives['branch_count'] ?? 0,
            'total_users' => $objectives['total_users'] ?? 0,
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
                'foundation:load-test-baseline-check',
                'loadtest:baseline-run',
                'foundation:backup-dr-check',
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
            default => $this->fail($id, "{$id} is {$decision}; ENT-13 cannot be GO."),
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
