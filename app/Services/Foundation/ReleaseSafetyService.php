<?php

namespace App\Services\Foundation;

use Illuminate\Support\Facades\Artisan;

/**
 * NSF-9 — Read-only release safety gate validator.
 *
 * Emits GO / WATCH / FAIL:
 *  - FAIL : release_safety config missing OR a required pre-deploy gate
 *           command does not exist OR a risky feature flag is enabled
 *           without an explicit override reason.
 *  - WATCH: config complete but optional local evidence artifacts are not
 *           present yet (nothing to fail on locally).
 *  - GO   : config complete, all required gate commands registered, no
 *           unsafe risky-flag state.
 */
class ReleaseSafetyService
{
    public function __construct(private readonly FeatureFlagService $flags) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $config = config('release_safety');

        if (! is_array($config) || $config === []) {
            return [
                'generated_at' => now()->toIso8601String(),
                'config_exists' => false,
                'checks' => [[
                    'check_id' => 'RELEASE-SAFETY-CONFIG-EXISTS',
                    'status' => 'failed',
                    'blocking' => true,
                    'message' => 'config/release_safety.php is missing or empty.',
                ]],
                'summary' => ['decision' => 'FAIL', 'checks' => 1, 'passed' => 0, 'warnings' => 0, 'errors' => 1],
            ];
        }

        $checks = [];
        $checks[] = $this->pass('RELEASE-SAFETY-CONFIG-EXISTS', 'release_safety config present and non-empty.');

        $requiredGates = (array) ($config['required_pre_deploy_gates'] ?? []);
        $checks[] = $requiredGates !== []
            ? $this->pass('RELEASE-SAFETY-GATES-DEFINED', 'Required pre-deploy gates are defined.')
            : $this->fail('RELEASE-SAFETY-GATES-DEFINED', 'No required pre-deploy gates configured.');

        // The gate list must reference DQ, DMO, NSF, ROADMAP, and foundation summary commands.
        $mustInclude = [
            'data-quality:dq1-audit',
            'inventory:batch-governance-audit',
            'inventory:source-document-batch-audit',
            'architecture:dmo-governance-check',
            'architecture:nsf-governance-check',
            'architecture:foundation-roadmap-check',
            'architecture:foundation-governance-summary',
        ];
        $missingCoverage = array_filter(
            $mustInclude,
            fn (string $needle) => ! collect($requiredGates)->contains(fn (string $gate) => str_contains($gate, $needle))
        );
        $checks[] = $missingCoverage === []
            ? $this->pass('RELEASE-SAFETY-GATE-COVERAGE', 'Gate list covers DQ/DMO/NSF/ROADMAP/foundation summary.')
            : $this->fail('RELEASE-SAFETY-GATE-COVERAGE', 'Gate list missing coverage for: '.implode(', ', $missingCoverage));

        // Every required gate command must actually be registered (base command name, ignoring options).
        $registered = Artisan::all();
        $unregistered = [];
        foreach ($requiredGates as $gate) {
            $commandName = trim(explode(' ', (string) $gate)[0]);
            if ($commandName !== '' && ! array_key_exists($commandName, $registered)) {
                $unregistered[] = $commandName;
            }
        }
        $checks[] = $unregistered === []
            ? $this->pass('RELEASE-SAFETY-GATE-COMMANDS-EXIST', 'All required gate commands are registered.')
            : $this->fail('RELEASE-SAFETY-GATE-COMMANDS-EXIST', 'Unregistered gate command(s): '.implode(', ', $unregistered));

        $requiredEvidence = (array) ($config['required_deploy_evidence'] ?? []);
        $checks[] = $requiredEvidence !== []
            ? $this->pass('RELEASE-SAFETY-EVIDENCE-DEFINED', 'Required deploy evidence fields are defined.')
            : $this->fail('RELEASE-SAFETY-EVIDENCE-DEFINED', 'No required deploy evidence fields configured.');

        $rollback = (array) ($config['rollback_checklist'] ?? []);
        $checks[] = $rollback !== []
            ? $this->pass('RELEASE-SAFETY-ROLLBACK-DEFINED', 'Rollback checklist is defined.')
            : $this->fail('RELEASE-SAFETY-ROLLBACK-DEFINED', 'No rollback checklist configured.');

        $safetyRules = (array) ($config['safety_rules'] ?? []);
        $checks[] = $safetyRules !== []
            ? $this->pass('RELEASE-SAFETY-RULES-DEFINED', 'Safety rules are defined.')
            : $this->fail('RELEASE-SAFETY-RULES-DEFINED', 'No safety rules configured.');

        // Deploy gate files must exist on disk (deploy script / CI workflow / smoke script).
        $deployFiles = (array) ($config['deploy_gate_files'] ?? []);
        $missingFiles = [];
        foreach ($deployFiles as $label => $path) {
            if (! is_string($path) || $path === '' || ! is_file(base_path($path))) {
                $missingFiles[] = "{$label}({$path})";
            }
        }
        $checks[] = $missingFiles === []
            ? $this->pass('RELEASE-SAFETY-DEPLOY-FILES-EXIST', 'Deploy script/CI workflow/smoke script all present.')
            : $this->fail('RELEASE-SAFETY-DEPLOY-FILES-EXIST', 'Missing deploy gate file(s): '.implode(', ', $missingFiles));

        // Feature flag governance must be GO/WATCH, never FAIL (unsafe risky default/enabled).
        $flagGovernance = $this->flags->validateGovernance();
        $checks[] = $flagGovernance['summary']['decision'] !== 'FAIL'
            ? $this->pass('RELEASE-SAFETY-FLAG-GOVERNANCE', 'Feature flag governance is safe (no unsafe risky flag).')
            : $this->fail('RELEASE-SAFETY-FLAG-GOVERNANCE', 'Feature flag governance reports FAIL — unsafe risky flag default/state.');

        // Local evidence artifacts are advisory only (WATCH), never FAIL.
        $localEvidence = (array) ($config['local_evidence_candidates'] ?? []);
        $missingEvidence = array_values(array_filter($localEvidence, fn (string $path) => ! is_file(base_path($path))));
        $checks[] = $missingEvidence === []
            ? $this->pass('RELEASE-SAFETY-LOCAL-EVIDENCE', 'All local evidence artifacts present.')
            : $this->warn('RELEASE-SAFETY-LOCAL-EVIDENCE', 'Local evidence not yet captured (expected on VPS/CI runs): '.implode(', ', $missingEvidence));

        $errors = count(array_filter($checks, fn (array $c) => $c['status'] === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => $c['status'] === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => $c['status'] === 'passed'));

        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'generated_at' => now()->toIso8601String(),
            'config_exists' => true,
            'required_pre_deploy_gates' => $requiredGates,
            'required_deploy_evidence' => $requiredEvidence,
            'rollback_checklist' => $rollback,
            'safety_rules' => $safetyRules,
            'deploy_gate_files' => collect($deployFiles)->map(fn (string $path) => [
                'path' => $path,
                'exists' => is_file(base_path($path)),
            ])->all(),
            'feature_flag_governance' => $flagGovernance['summary'],
            'risky_enabled_flags' => $flagGovernance['risky_enabled_flags'],
            'checks' => $checks,
            'summary' => [
                'decision' => $decision,
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];
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
