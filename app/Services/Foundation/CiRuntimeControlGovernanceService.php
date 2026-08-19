<?php

namespace App\Services\Foundation;

use App\Support\Cicd\CiRuntimeControlScanner;

/**
 * CICD-CTRL-1 — Safe CI Runtime Control governance layer.
 *
 * Verifies the safe CI gate-control foundation: a conservative classifier
 * (scripts/ci/resolve-gates.sh) picks which gates run per change set, the
 * Foundation Evidence Gates workflow wires it in, and the safety invariant
 * holds — only docs_only skips the expensive critical Pest step while
 * security / governance / release-safety / evidence / smoke gates always run.
 *
 * It also re-verifies the ENT-10 CI/CD enterprise gate stays GO, so the runtime
 * optimization can never be shipped while the enterprise gate is broken.
 *
 * Read-only and informational; NOT wired into the blocking combined decision.
 */
class CiRuntimeControlGovernanceService
{
    public function __construct(
        private readonly CiRuntimeControlScanner $scanner,
        private readonly CicdEnterpriseGateGovernanceService $cicdEnterpriseGate,
    ) {}

    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'CICDCTRL-R001',
                'title' => 'Default-strong: uncertainty always runs the stronger gate',
                'description' => 'When a change set cannot be classified safely (no diff, unreachable base, unknown path), the classifier returns unknown_high_risk and every expensive gate runs. Optimization is applied only when safety is proven.',
            ],
            [
                'id' => 'CICDCTRL-R002',
                'title' => 'docs_only is the only profile that may skip critical tests',
                'description' => 'skip_critical_profiles is exactly [docs_only]. A change is docs_only only when every changed file is Markdown documentation; any code, config, workflow, script, dependency, test, migration, route, policy, permission, or view change forces the critical gate.',
            ],
            [
                'id' => 'CICDCTRL-R003',
                'title' => 'Security / governance / release-safety / evidence / smoke gates always run',
                'description' => 'The quality, release-safety (NSF-9), and release-evidence (NSF-10) jobs are always-on and are never gated by the classifier. The runtime control only decides whether the expensive Pest regression and additive module tests run.',
            ],
            [
                'id' => 'CICDCTRL-R004',
                'title' => 'Full-suite gate is preserved as a fallback',
                'description' => 'The full Pest suite remains available via schedule (weekly), manual workflow_dispatch, and push-to-base. It is not deleted; it is the required fallback for high-risk changes and the periodic safety net.',
            ],
            [
                'id' => 'CICDCTRL-R005',
                'title' => 'No unsafe path filtering / blanket skip',
                'description' => 'The workflow must never use paths-ignore or an equivalent blanket path filter that would let relevant changes bypass CI. Selection is decided by the audited classifier, not by opaque workflow path globs.',
            ],
            [
                'id' => 'CICDCTRL-R006',
                'title' => 'Dependency / workflow / test / app / config changes force stronger gates',
                'description' => 'composer.*, package*.json, lockfiles, vite/tailwind/phpunit/pint config, .github/workflows/*, scripts/*, tests/*, app/*, routes/*, database/*, config/*, and resources/* changes never classify as docs_only and always run the critical gate.',
            ],
            [
                'id' => 'CICDCTRL-R007',
                'title' => 'Selective module tests add coverage, never remove it',
                'description' => 'Path-based module flags (inventory/rme/lab/ui/permission) enable additive module test runs for the affected module. They can only add test execution; they never downgrade the critical gate.',
            ],
            [
                'id' => 'CICDCTRL-R008',
                'title' => 'Classifier is auditable, testable, and non-destructive',
                'description' => 'scripts/ci/resolve-gates.sh is plain bash under set -euo pipefail, runs only git diff --name-only, mutates nothing, and is covered by tests that assert high-risk change sets never select a weak gate.',
            ],
            [
                'id' => 'CICDCTRL-R009',
                'title' => 'CI output states the decision and the safety reason',
                'description' => 'The classifier prints the changed-file summary, the categories present, the selected profile, the gates chosen/skipped, and an explicit safety statement so every run is transparent about why a gate was skipped or a stronger gate was forced.',
            ],
            [
                'id' => 'CICDCTRL-R010',
                'title' => 'The ENT-10 enterprise CI/CD gate must stay GO',
                'description' => 'This governance re-verifies foundation:cicd-enterprise-gate-check. If the enterprise gate is not GO the runtime control is not GO — CI runtime optimization can never ship on top of a broken enterprise gate.',
            ],
            [
                'id' => 'CICDCTRL-R011',
                'title' => 'Widening the skip set requires a reviewed sprint',
                'description' => 'Any change to skip_critical_profiles, the profile precedence, or the always-on gate set must extend this contract with tests and pass foundation:ci-runtime-control-check before shipping.',
            ],
            [
                'id' => 'CICDCTRL-R012',
                'title' => 'While the temporary policy is ACTIVE no automatic trigger may run the Full Suite',
                'description' => 'CI-TEMP-FULL-SUITE-SCHEDULE-GATE. Both automatic paths are deferred: the weekly schedule and the post-merge push to the base branch (a squash-merge IS such a push). full_suite_gate additionally requires needs.classify.outputs.full_suite_authorized == true, which the resolver only returns for an explicitly authorised dispatch. The gate is DEFERRED, never deleted — the schedule and workflow_dispatch triggers both remain, and the consolidated closure still runs the suite on the frozen final SHA.',
            ],
            [
                'id' => 'CICDCTRL-R013',
                'title' => 'One canonical policy state, resolved fail-closed',
                'description' => 'The status lives in exactly one machine-readable file (.github/ci-policy/full-suite-policy.json) that the bash resolver and this PHP governance both read, so the workflow can never disagree with the documentation. Any uncertainty — missing file, unreadable file, unknown or duplicated status — resolves to POLICY ACTIVE and the Full Suite is not authorised. Failing closed can only defer a run; it can never hide a failure.',
            ],
            [
                'id' => 'CICDCTRL-R014',
                'title' => 'The authorised consolidated run stays reachable, and retirement is a deliberate act',
                'description' => 'workflow_dispatch survives with two inputs: run_full_suite AND full_suite_policy_override. Both must be set, so no casual dispatch bypasses the policy while the final closure remains one deliberate action away. Restoring the automatic cadence requires flipping the canonical status to RETIRED — an explicit governance act reserved for the consolidated closure, never a side effect of another sprint.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $checks = [];

        $enabled = (bool) config('ci_runtime_control.enabled', true);
        $checks[] = $enabled
            ? $this->pass('CICDCTRL-ENABLED', 'Safe CI runtime control governance is enabled.')
            : $this->warn('CICDCTRL-ENABLED', 'Safe CI runtime control governance is disabled by configuration.');

        $classifier = $this->scanner->classifierScriptPosture();
        $checks[] = $classifier['ok']
            ? $this->pass('CICDCTRL-CLASSIFIER', 'Classifier script exists and carries the default-strong safety markers.')
            : $this->fail('CICDCTRL-CLASSIFIER', 'Classifier posture failed: '.implode('; ', $classifier['issues']).'.');

        $workflow = $this->scanner->workflowPosture();
        $checks[] = $workflow['ok']
            ? $this->pass('CICDCTRL-WORKFLOW', 'Workflow wires the classifier in, keeps always-on gates unconditional, preserves the full-suite fallback, and adds no unsafe path filtering.')
            : $this->fail('CICDCTRL-WORKFLOW', 'Workflow posture failed: '.implode('; ', $workflow['issues']).'.');

        $invariant = $this->scanner->safetyInvariantPosture();
        $checks[] = $invariant['ok']
            ? $this->pass('CICDCTRL-SAFETY-INVARIANT', 'Safety invariant intact: docs_only is the only skip-critical profile and the default profile is unknown_high_risk.')
            : $this->fail('CICDCTRL-SAFETY-INVARIANT', 'Safety invariant failed: '.implode('; ', $invariant['issues']).'.');

        // CI-TEMP-FULL-SUITE-SCHEDULE-GATE — the temporary policy must be
        // enforced by the CI layer, not merely written down.
        $policy = $this->scanner->temporaryFullSuitePolicyPosture();
        $checks[] = $policy['ok']
            ? $this->pass('CICDCTRL-TEMP-FULL-SUITE-POLICY', "Temporary Full Suite policy is {$policy['status']} and enforced: the resolver is fail-closed and the workflow gates full_suite_gate on its decision.")
            : $this->fail('CICDCTRL-TEMP-FULL-SUITE-POLICY', 'Temporary Full Suite policy posture failed: '.implode('; ', $policy['issues']).'.');

        $enterprise = $this->cicdEnterpriseGate->collect();
        $enterpriseDecision = (string) ($enterprise['decision'] ?? 'FAIL');
        $checks[] = $this->decisionCheck('CICDCTRL-ENT10-CICD-GATE', $enterpriseDecision, 'ENT-10 CI/CD enterprise gate is GO.');

        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));
        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'sprint' => 'CICD-CTRL-1',
            'decision' => $decision,
            'readiness_status' => $decision === 'GO' ? 'safe_ci_runtime_control_ready' : strtolower($decision),
            'enabled' => $enabled,
            'classifier_ok' => $classifier['ok'],
            'workflow_ok' => $workflow['ok'],
            'safety_invariant_ok' => $invariant['ok'],
            'skip_critical_profiles' => $invariant['skip_critical_profiles'],
            'default_profile' => $invariant['default_profile'],
            'full_suite_fallback' => $workflow['full_suite_fallback'] ?? false,
            'temporary_full_suite_policy_ok' => $policy['ok'],
            'temporary_full_suite_policy_status' => $policy['status'],
            'temporary_full_suite_policy_active' => $policy['active'],
            'enterprise_gate_decision' => $enterpriseDecision,
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
                'foundation:ci-runtime-control-check',
                'foundation:cicd-enterprise-gate-check',
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];
    }

    private function decisionCheck(string $id, string $decision, string $goMessage): array
    {
        return match ($decision) {
            'GO' => $this->pass($id, $goMessage),
            'WATCH' => $this->warn($id, "{$id} is WATCH; strict mode should block until resolved."),
            default => $this->fail($id, "{$id} is {$decision}; CICD-CTRL-1 cannot be GO."),
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
