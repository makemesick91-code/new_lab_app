<?php

namespace App\Services\Foundation;

use App\Support\Cicd\SelfHostedRunnerScanner;

/**
 * CICD-CTRL-3 — Dedicated Self-Hosted CI Runner governance layer.
 *
 * Verifies the hybrid CI foundation: heavy gates may execute on a dedicated,
 * project-labelled self-hosted runner while GitHub Actions remains the
 * authoritative control plane, the production VPS is never a general CI runner,
 * and no gate is weakened to make CI faster.
 *
 * It also re-verifies that the CICD-CTRL-1 safe runtime control stays GO, so
 * runner routing can never be shipped on top of a broken gate classifier.
 *
 * Read-only and informational; NOT wired into the blocking combined decision.
 */
class SelfHostedRunnerGovernanceService
{
    public function __construct(
        private readonly SelfHostedRunnerScanner $scanner,
        private readonly CiRuntimeControlGovernanceService $ciRuntimeControl,
    ) {}

    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'CICDCTRL3-R001',
                'title' => 'GitHub Actions remains the authoritative CI control plane',
                'description' => 'A self-hosted runner adds execution capacity and cost/control benefits. It does not make CI independent of a GitHub Actions outage, and it never becomes a second source of truth for whether a gate passed.',
            ],
            [
                'id' => 'CICDCTRL3-R002',
                'title' => 'The production VPS is never a general CI runner',
                'description' => 'Production runtime and general CI execution stay on separate machines. Deployment keeps its own boundary: scripts/deploy-vps-runner.sh is executed ON the VPS and is never invoked from a CI job.',
            ],
            [
                'id' => 'CICDCTRL3-R003',
                'title' => 'The runner executes CI as an unprivileged service user',
                'description' => 'The GitHub Actions runner runs as a dedicated non-root service user under a managed systemd unit. Interactive run.sh processes are never left behind, and the service user is not added to the docker group (which is root-equivalent).',
            ],
            [
                'id' => 'CICDCTRL3-R004',
                'title' => 'The runner holds no production material',
                'description' => 'No production environment file, no production database credential, no production SSH private key, and no production application path exist on the runner. The health check reports PRESENT/ABSENT posture only and never prints a credential.',
            ],
            [
                'id' => 'CICDCTRL3-R005',
                'title' => 'CI uses a local, non-production database, enforced before every migration',
                'description' => 'ci:assert-non-production-database runs before migrations in every DB-heavy job on both runner types. It fails closed unless the active database is local and carries a CI/test name, which blocks every remote database rather than only enumerated ones.',
            ],
            [
                'id' => 'CICDCTRL3-R006',
                'title' => 'Heavy jobs target an explicit project label set',
                'description' => 'Self-hosted jobs use [self-hosted, linux, x64, daengtisia-ci]. A bare `runs-on: self-hosted` is forbidden because any other runner on the account could satisfy it and pick up DaengtisiaMS work.',
            ],
            [
                'id' => 'CICDCTRL3-R007',
                'title' => 'Required check names are preserved across runner modes',
                'description' => 'The GitHub-hosted and self-hosted variants share one check name and mutually exclusive conditions, so exactly one runs. Routing changes must never make a required check disappear or let a PR merge because a gate vanished.',
            ],
            [
                'id' => 'CICDCTRL3-R008',
                'title' => 'A runner outage queues the gate; it never silently passes',
                'description' => 'When routing targets the self-hosted runner and the runner is offline, the job queues and the gate stays unsatisfied. There is no automatic failover that would let a required gate resolve without executing.',
            ],
            [
                'id' => 'CICDCTRL3-R009',
                'title' => 'Falling back to GitHub-hosted is explicit and equivalent',
                'description' => 'Fallback is an operator action (repository variable or workflow_dispatch input), defaulting to github-hosted. The fallback path runs the same test filter and the same guards; it never skips a test, weakens a permission, or renames a required check.',
            ],
            [
                'id' => 'CICDCTRL3-R010',
                'title' => 'Untrusted pull requests never execute on the dedicated runner',
                'description' => 'A pull request from a fork is always routed to GitHub-hosted infrastructure, never to the self-hosted runner and never to its secrets — and it is redirected rather than skipped, so the gate still runs.',
            ],
            [
                'id' => 'CICDCTRL3-R011',
                'title' => 'The persistent workspace is cleaned and caches carry no secrets',
                'description' => 'A persistent runner keeps state between jobs, so checkout is clean and generated CI artifacts and environment files are removed afterwards. Package caches may persist; environment files, dumps, credentials, and clinical fixtures may not.',
            ],
            [
                'id' => 'CICDCTRL3-R012',
                'title' => 'Concurrency comes from a benchmark, not from core count',
                'description' => 'Heavy jobs run one at a time on the dedicated hardware, and the Pest worker count is chosen from a measured benchmark that stays clear of swap thrashing, thermal throttling, and database contention.',
            ],
            [
                'id' => 'CICDCTRL3-R013',
                'title' => 'The authoritative PHP runtime is pinned and isolated, never the host PHP',
                'description' => 'The runner host cannot supply the authoritative PHP version, so the self-hosted variant runs every php/composer/artisan command inside a digest-pinned image via rootless Podman under the dedicated service user. The host PHP is never authoritative, the base image is pinned by digest rather than a floating tag, and the image carries the same extension set and Poppler binaries as the GitHub-hosted gate so no test silently skips. Rootful Docker and docker-group membership are forbidden — the docker group is root-equivalent.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $checks = [];

        $enabled = (bool) config('ci_runner.enabled', true);
        $checks[] = $enabled
            ? $this->pass('CICDCTRL3-ENABLED', 'Self-hosted CI runner governance is enabled.')
            : $this->warn('CICDCTRL3-ENABLED', 'Self-hosted CI runner governance is disabled by configuration.');

        $contract = $this->scanner->contractPosture();
        $checks[] = $contract['ok']
            ? $this->pass('CICDCTRL3-CONTRACT', 'Runner contract is coherent: explicit label set, fail-safe github-hosted default, classifier and deploy pinned to GitHub-hosted.')
            : $this->fail('CICDCTRL3-CONTRACT', 'Runner contract failed: '.implode('; ', $contract['issues']).'.');

        $workflow = $this->scanner->workflowPosture();
        $checks[] = $workflow['ok']
            ? $this->pass('CICDCTRL3-WORKFLOW', 'Workflow routes heavy CI safely: explicit labels, no bare self-hosted target, guard before every migration, no production command in CI.')
            : $this->fail('CICDCTRL3-WORKFLOW', 'Workflow posture failed: '.implode('; ', $workflow['issues']).'.');

        $deploy = $this->scanner->deployIsolationPosture();
        $checks[] = $deploy['ok']
            ? $this->pass('CICDCTRL3-DEPLOY-ISOLATION', 'Deployment never runs on the general CI runner.')
            : $this->fail('CICDCTRL3-DEPLOY-ISOLATION', 'Deploy isolation failed: '.implode('; ', $deploy['issues']).'.');

        $health = $this->scanner->healthScriptPosture();
        $checks[] = $health['ok']
            ? $this->pass('CICDCTRL3-HEALTH-SCRIPT', 'Runner health script exists, fails fast, checks production isolation, and mutates nothing.')
            : $this->fail('CICDCTRL3-HEALTH-SCRIPT', 'Health script posture failed: '.implode('; ', $health['issues']).'.');

        $pipeline = $this->scanner->pipelineExitPosture();
        $checks[] = $pipeline['ok']
            ? $this->pass('CICDCTRL3-PIPELINE-EXIT', 'Safety-critical steps propagate the producer exit status through their evidence pipe; a NO-GO health verdict fails the job.')
            : $this->fail('CICDCTRL3-PIPELINE-EXIT', 'Pipeline exit posture failed: '.implode('; ', $pipeline['issues']).'.');

        $evidence = $this->scanner->runtimeEvidencePosture();
        $checks[] = $evidence['ok']
            ? $this->pass('CICDCTRL3-RUNTIME-EVIDENCE', 'Runtime evidence is derived from the resolved runtime mode, never asserted as a fixed engine.')
            : $this->fail('CICDCTRL3-RUNTIME-EVIDENCE', 'Runtime evidence posture failed: '.implode('; ', $evidence['issues']).'.');

        $runtime = $this->scanner->ciRuntimePosture();
        $checks[] = $runtime['ok']
            ? $this->pass('CICDCTRL3-CI-RUNTIME', 'Authoritative CI runtime is a digest-pinned image executed through rootless Podman, with the full extension set.')
            : $this->fail('CICDCTRL3-CI-RUNTIME', 'CI runtime posture failed: '.implode('; ', $runtime['issues']).'.');

        $guard = $this->scanner->databaseGuardPosture();
        $checks[] = $guard['ok']
            ? $this->pass('CICDCTRL3-DB-GUARD', 'Production database guard is strict: testing-only environment, local-only hosts, explicit production denylist.')
            : $this->fail('CICDCTRL3-DB-GUARD', 'Database guard posture failed: '.implode('; ', $guard['issues']).'.');

        $suiteCoverage = $this->scanner->criticalGateSuiteCoveragePosture();
        $checks[] = $suiteCoverage['ok']
            ? $this->pass('CICDCTRL3-CRITICAL-SUITE-COVERAGE', sprintf(
                'All %d mandatory critical suites are selected by every critical gate variant.',
                count($suiteCoverage['declared'])
            ))
            : $this->fail('CICDCTRL3-CRITICAL-SUITE-COVERAGE', 'Mandatory critical suite coverage failed: '.implode('; ', $suiteCoverage['issues']).'.');

        $runtimeControl = $this->ciRuntimeControl->collect();
        $runtimeDecision = (string) ($runtimeControl['decision'] ?? 'FAIL');
        $checks[] = $this->decisionCheck('CICDCTRL3-CICDCTRL1-GATE', $runtimeDecision, 'CICD-CTRL-1 safe CI runtime control is GO.');

        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));
        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'sprint' => 'CICD-CTRL-3',
            'decision' => $decision,
            'readiness_status' => $decision === 'GO' ? 'self_hosted_runner_ready' : strtolower($decision),
            'enabled' => $enabled,
            'contract_ok' => $contract['ok'],
            'workflow_ok' => $workflow['ok'],
            'deploy_isolation_ok' => $deploy['ok'],
            'health_script_ok' => $health['ok'],
            'critical_suite_coverage_ok' => $suiteCoverage['ok'],
            'pipeline_exit_ok' => $pipeline['ok'],
            'pipeline_exit_unprotected' => $pipeline['unprotected'],
            'runtime_evidence_ok' => $evidence['ok'],
            'ci_runtime_ok' => $runtime['ok'],
            'ci_runtime_digest_pinned' => $runtime['digest_pinned'],
            'ci_runtime_image' => (string) config('ci_runner.ci_runtime.image', ''),
            'database_guard_ok' => $guard['ok'],
            'required_labels' => $contract['required_labels'],
            'default_runner_mode' => $contract['default_mode'],
            'runner_name' => (string) config('ci_runner.runner.name', ''),
            'runner_service_user' => (string) config('ci_runner.runner.service_user', ''),
            'ci_runtime_control_decision' => $runtimeDecision,
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
                'foundation:self-hosted-runner-check',
                'ci:assert-non-production-database',
                'foundation:ci-runtime-control-check',
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];
    }

    private function decisionCheck(string $id, string $decision, string $goMessage): array
    {
        return match ($decision) {
            'GO' => $this->pass($id, $goMessage),
            'WATCH' => $this->warn($id, "{$id} is WATCH; strict mode should block until resolved."),
            default => $this->fail($id, "{$id} is {$decision}; CICD-CTRL-3 cannot be GO."),
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
