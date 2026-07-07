<?php

namespace App\Services\Foundation;

use App\Support\Foundation\EntFoundationRuntimeHardeningScanner;

/**
 * POST-ENT — Enterprise Foundation Runtime Hardening governance layer.
 *
 * A post-closure hardening sprint that runs AFTER ENT-16 closed the ENT-1..ENT-16
 * Enterprise Foundation Freeze (final tag: enterprise-foundation-go). It is NOT
 * ENT-17. It publishes three governed, testable postures:
 *   - ENT-1..ENT-4 audit (foundation:ent-1-4-audit-check)
 *   - Queue worker runtime (foundation:queue-worker-runtime-check)
 *   - Deploy evidence timeout hardening (rolled into foundation:runtime-hardening-check)
 *
 * The umbrella collect() additionally re-verifies that the closed Enterprise
 * Foundation baseline (ENT-5..ENT-16) remains GO via the closure governance
 * service, so this hardening can never be GO while a foundation gate regressed.
 * Informational only — NOT wired into the blocking combined decision.
 */
class EntFoundationRuntimeHardeningGovernanceService
{
    public function __construct(
        private readonly EntFoundationRuntimeHardeningScanner $scanner,
        private readonly EnterpriseFoundationClosureGovernanceService $enterpriseFoundationClosureGovernance,
    ) {}

    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'PEH-R001',
                'title' => 'Post-ENT hardening preserves the closed Enterprise Foundation baseline',
                'description' => 'This is a post-closure hardening sprint, not ENT-17. It must never move an ENT GO tag or the final enterprise-foundation-go tag, and MON-1 remains the next recommended roadmap sprint. The gate re-verifies ENT-5..ENT-16 stay GO.',
            ],
            [
                'id' => 'PEH-R002',
                'title' => 'ENT-1..ENT-4 are audited as governance/config/docs locks',
                'description' => 'ENT-1 (architecture baseline), ENT-2 (database performance contract), ENT-3 (reporting summary contract) and ENT-4 (Redis cache policy) are verified completed, GO-tagged and doc-backed. They are NOT retrofitted into runtime modules — their canonical scope was governance/config/docs only.',
            ],
            [
                'id' => 'PEH-R003',
                'title' => 'The ENT-1..4 audit fails on a real missing mandatory artifact',
                'description' => 'A missing roadmap entry, missing completed status, missing GO tag, missing canonical doc, or a doc that lost its rule-id markers is a hard FAIL. A lock is not "verified" merely because the sprint shipped no runtime code.',
            ],
            [
                'id' => 'PEH-R004',
                'title' => 'Queue worker activation is conservative and worker-ready by default',
                'description' => 'The queue worker runs as a single low-concurrency systemd process using queue:work (never queue:listen or --daemon) with the ENT-5 approved tries/backoff/timeout and a bounded --max-time. High-concurrency or broad worker rollout is out of scope.',
            ],
            [
                'id' => 'PEH-R005',
                'title' => 'The worker is never started by the deploy script',
                'description' => 'Consistent with ENT-5 (no_long_running_worker_started_by_deploy), the systemd unit is installed and enabled by an operator AFTER a successful deploy with green governance gates. The deploy signals a graceful queue:restart, it does not start or scale workers.',
            ],
            [
                'id' => 'PEH-R006',
                'title' => 'The worker connection must be broker-backed, never sync',
                'description' => 'A worker may only be enabled when the environment queue connection is database or redis (never sync), so retries and failed jobs actually exist. The approved queue names are a subset of the ENT-5 allowed queue set.',
            ],
            [
                'id' => 'PEH-R007',
                'title' => 'Queue worker service definition carries no destructive command',
                'description' => 'The systemd unit never runs a destructive queue/DB command; destructive literals live only in config (config-not-code), and the unit streams non-sensitive output to the journal only.',
            ],
            [
                'id' => 'PEH-R008',
                'title' => 'The slow deploy evidence phase must survive an SSH broken pipe',
                'description' => 'The long deploy + evidence-capture phase runs through a server-side detached runner (setsid/nohup) that streams to a log file and records the real final exit code in a status file, so a dropped SSH pipe cannot tear down or falsely pass a deploy.',
            ],
            [
                'id' => 'PEH-R009',
                'title' => 'A deploy is never GO before the mandatory gates actually pass',
                'description' => 'The detached runner reports GO only when scripts/deploy-vps.sh finishes with exit=0 (it is fail-fast, so the mandatory governance gates and evidence checks must pass first). Optional/long evidence capture stays non-blocking but is never silently skipped.',
            ],
            [
                'id' => 'PEH-R010',
                'title' => 'Deploy safety posture is preserved (backup-first, migrate --force, ENT-8 cache order)',
                'description' => 'The deploy script remains backup-first, uses migrate --force only, clears route/config cache before route-dependent gates (ENT-8), and never runs migrate:fresh / db:wipe / schema:drop / migrate:reset on the VPS.',
            ],
            [
                'id' => 'PEH-R011',
                'title' => 'Hardening evidence is non-sensitive and captured per release profile',
                'description' => 'The ent-1-4-audit, queue-worker-runtime and runtime-hardening evidence artifacts are declared in the ci/vps profiles and captured by the deploy/CI scripts. No secret, credential, backup contents, environment value, or KTP/NIK-shaped value is ever written to an artifact.',
            ],
            [
                'id' => 'PEH-R012',
                'title' => 'Future worker/deploy hardening registers here with tests first',
                'description' => 'Any future queue-worker change, worker scale-out, deploy-runner change, or evidence artifact must extend this contract with coverage and tests and pass foundation:runtime-hardening-check before shipping, without weakening any ENT-5..ENT-16 gate or the closed baseline.',
            ],
        ];
    }

    /**
     * ENT-1..ENT-4 audit collection.
     *
     * @return array<string, mixed>
     */
    public function collectEntAudit(): array
    {
        $checks = [];

        $enabled = (bool) config('enterprise_foundation_runtime_hardening.enabled', true);
        $checks[] = $enabled
            ? $this->pass('PEH-AUDIT-ENABLED', 'ENT runtime hardening governance is enabled.')
            : $this->warn('PEH-AUDIT-ENABLED', 'ENT runtime hardening governance is disabled by configuration.');

        $audit = $this->scanner->entAuditPosture();
        $checks[] = $audit['ok']
            ? $this->pass('PEH-ENT-1-4-AUDIT', 'ENT-1..ENT-4 are completed, GO-tagged and doc-backed governance/config/docs locks.')
            : $this->fail('PEH-ENT-1-4-AUDIT', 'ENT-1..ENT-4 audit failed: '.implode('; ', $audit['issues']).'.');

        return $this->finalize('ent_1_4_audit', $checks, [
            'audited_sprints' => $audit['audited_sprints'],
            'per_sprint' => $audit['per_sprint'],
            'runtime_backfill_required' => false,
            'evidence_artifact' => (string) config('enterprise_foundation_runtime_hardening.evidence.artifacts.ent_1_4_audit', ''),
            'commands' => ['foundation:ent-1-4-audit-check', 'foundation:runtime-hardening-check'],
        ]);
    }

    /**
     * Queue worker runtime collection.
     *
     * @return array<string, mixed>
     */
    public function collectQueueWorker(): array
    {
        $checks = [];

        $enabled = (bool) config('enterprise_foundation_runtime_hardening.enabled', true);
        $checks[] = $enabled
            ? $this->pass('PEH-QW-ENABLED', 'ENT runtime hardening governance is enabled.')
            : $this->warn('PEH-QW-ENABLED', 'ENT runtime hardening governance is disabled by configuration.');

        $worker = $this->scanner->queueWorkerRuntimePosture();
        $checks[] = $worker['ok']
            ? $this->pass('PEH-QUEUE-WORKER-RUNTIME', 'Queue worker systemd unit is safe (queue:work, approved queues, tries/timeout/backoff, restart-safe, broker connection, no destructive command).')
            : $this->fail('PEH-QUEUE-WORKER-RUNTIME', 'Queue worker runtime posture failed: '.implode('; ', $worker['issues']).'.');

        return $this->finalize('queue_worker_runtime', $checks, [
            'service_name' => $worker['service_name'] ?? '',
            'service_file_present' => $worker['service_file_present'] ?? false,
            'worker_command_present' => $worker['worker_command_present'] ?? false,
            'no_destructive_command' => $worker['no_destructive_command'] ?? false,
            'queues_subset_of_ent5' => $worker['queues_subset_of_ent5'] ?? false,
            'connection' => $worker['connection'] ?? 'unknown',
            'connection_ok' => $worker['connection_ok'] ?? false,
            'failed_jobs_table' => $worker['failed_jobs_table'] ?? 'failed_jobs',
            'activated_by_deploy' => $worker['activated_by_deploy'] ?? false,
            'evidence_artifact' => (string) config('enterprise_foundation_runtime_hardening.evidence.artifacts.queue_worker_runtime', ''),
            'commands' => ['foundation:queue-worker-runtime-check', 'foundation:queue-retry-failed-job-check', 'foundation:runtime-hardening-check'],
        ]);
    }

    /**
     * Umbrella runtime-hardening collection: ENT-1..4 audit + queue worker +
     * deploy evidence timeout + evidence profiles + closed-baseline re-verify.
     *
     * @param  array<string, mixed>|null  $closureReport  Optional pre-computed closure
     *                                                    governance report (passed by the summary service to avoid recomputing it).
     * @return array<string, mixed>
     */
    public function collect(?array $closureReport = null): array
    {
        $checks = [];

        $enabled = (bool) config('enterprise_foundation_runtime_hardening.enabled', true);
        $checks[] = $enabled
            ? $this->pass('PEH-ENABLED', 'ENT runtime hardening governance is enabled.')
            : $this->warn('PEH-ENABLED', 'ENT runtime hardening governance is disabled by configuration.');

        $audit = $this->scanner->entAuditPosture();
        $checks[] = $audit['ok']
            ? $this->pass('PEH-ENT-1-4-AUDIT', 'ENT-1..ENT-4 governance/config/docs locks verified.')
            : $this->fail('PEH-ENT-1-4-AUDIT', 'ENT-1..ENT-4 audit failed: '.implode('; ', $audit['issues']).'.');

        $worker = $this->scanner->queueWorkerRuntimePosture();
        $checks[] = $worker['ok']
            ? $this->pass('PEH-QUEUE-WORKER-RUNTIME', 'Conservative queue worker runtime posture verified.')
            : $this->fail('PEH-QUEUE-WORKER-RUNTIME', 'Queue worker runtime posture failed: '.implode('; ', $worker['issues']).'.');

        $deploy = $this->scanner->deployEvidenceTimeoutPosture();
        $checks[] = $deploy['ok']
            ? $this->pass('PEH-DEPLOY-EVIDENCE-TIMEOUT', 'Detached deploy runner hardens the slow evidence phase and the deploy script keeps the closed-baseline safety posture.')
            : $this->fail('PEH-DEPLOY-EVIDENCE-TIMEOUT', 'Deploy evidence timeout posture failed: '.implode('; ', $deploy['issues']).'.');

        $evidence = $this->scanner->evidenceProfilePosture();
        $checks[] = $evidence['ok']
            ? $this->pass('PEH-EVIDENCE-PROFILES', 'Hardening evidence artifacts are declared in the ci/vps profiles.')
            : $this->fail('PEH-EVIDENCE-PROFILES', 'Evidence profile posture failed: '.implode('; ', $evidence['issues']).'.');

        // Re-verify the closed Enterprise Foundation baseline (ENT-5..ENT-16) is GO.
        $closure = $closureReport ?? $this->enterpriseFoundationClosureGovernance->collect();
        $closureDecision = (string) ($closure['decision'] ?? 'FAIL');
        $checks[] = match ($closureDecision) {
            'GO' => $this->pass('PEH-CLOSED-BASELINE', 'Closed Enterprise Foundation baseline (ENT-5..ENT-16) is GO.'),
            'WATCH' => $this->warn('PEH-CLOSED-BASELINE', 'Enterprise Foundation closure is WATCH; strict mode should block until resolved.'),
            default => $this->fail('PEH-CLOSED-BASELINE', "Enterprise Foundation closure is {$closureDecision}; hardening cannot be GO."),
        };

        // Baseline must still point at MON-1 (never ENT-17).
        $nextSprint = (string) ($closure['next_recommended_sprint'] ?? '');
        $expectedNext = (string) config('enterprise_foundation_runtime_hardening.baseline.next_recommended_sprint', 'MON-1');
        $checks[] = ($nextSprint === '' || $nextSprint === $expectedNext)
            ? $this->pass('PEH-NEXT-SPRINT', "Next recommended roadmap sprint remains {$expectedNext}.")
            : $this->warn('PEH-NEXT-SPRINT', "Next recommended sprint is {$nextSprint}, expected {$expectedNext}.");

        return $this->finalize('runtime_hardening', $checks, [
            'ent_1_4_audit_ok' => $audit['ok'],
            'audited_sprints' => $audit['audited_sprints'],
            'queue_worker_ok' => $worker['ok'],
            'queue_worker_service_name' => $worker['service_name'] ?? '',
            'queue_connection' => $worker['connection'] ?? 'unknown',
            'deploy_evidence_timeout_ok' => $deploy['ok'],
            'evidence_profiles_ok' => $evidence['ok'],
            'closed_baseline_decision' => $closureDecision,
            'final_closure_tag' => (string) config('enterprise_foundation_runtime_hardening.baseline.final_closure_tag', 'enterprise-foundation-go'),
            'next_recommended_sprint' => $nextSprint !== '' ? $nextSprint : $expectedNext,
            'is_ent_17' => false,
            'evidence_artifact' => (string) config('enterprise_foundation_runtime_hardening.evidence.artifacts.runtime_hardening', ''),
            'commands' => [
                'foundation:runtime-hardening-check',
                'foundation:ent-1-4-audit-check',
                'foundation:queue-worker-runtime-check',
                'foundation:enterprise-closure-check',
            ],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function finalize(string $facet, array $checks, array $extra): array
    {
        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));
        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return array_merge([
            'sprint' => 'POST-ENT-RUNTIME-HARDENING',
            'facet' => $facet,
            'decision' => $decision,
            'readiness_status' => $decision === 'GO' ? "{$facet}_ready" : strtolower($decision),
        ], $extra, [
            'checks' => $checks,
            'summary' => [
                'decision' => $decision,
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
            ],
            'rules' => self::rules(),
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ]);
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
