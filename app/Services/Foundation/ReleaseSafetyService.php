<?php

namespace App\Services\Foundation;

use Illuminate\Support\Facades\Artisan;

/**
 * NSF-9 / NSF-10 — Read-only release safety gate validator.
 *
 * Emits GO / WATCH / FAIL:
 *  - FAIL : release_safety config missing OR a required pre-deploy gate
 *           command does not exist OR a risky feature flag is enabled
 *           without an explicit override reason OR (NSF-10) the release
 *           evidence chain / backup verification for the given profile
 *           reports FAIL.
 *  - WATCH: config complete but optional evidence for the given profile is
 *           not present yet (nothing to fail on for that profile).
 *  - GO   : config complete, all required gate commands registered, no
 *           unsafe risky-flag state, and (NSF-10) the release evidence
 *           chain for the given profile is GO.
 *
 * NSF-10 replaces the NSF-9 "local evidence candidates" file-existence check
 * with a profile-aware evidence chain consumed from
 * App\Services\Foundation\ReleaseEvidenceService, so release safety honestly
 * reflects local vs CI vs VPS evidence instead of always evaluating against
 * local-only file paths.
 */
class ReleaseSafetyService
{
    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly ReleaseEvidenceService $evidence,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(string $profile = 'local'): array
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

        // NSF-10: profile-aware release evidence chain replaces the NSF-9
        // "local evidence candidates" file check. local profile has no
        // required artifacts so it never fails; ci/vps profiles require
        // their captured artifacts to exist, be safe, and be fresh.
        $evidenceChain = $this->evidence->check($profile);
        $evidenceDecision = (string) ($evidenceChain['summary']['decision'] ?? 'FAIL');
        $checks[] = match ($evidenceDecision) {
            'GO' => $this->pass('RELEASE-SAFETY-EVIDENCE-CHAIN', "Release evidence chain for profile \"{$profile}\" is GO."),
            'WATCH' => $this->warn('RELEASE-SAFETY-EVIDENCE-CHAIN', "Release evidence chain for profile \"{$profile}\" is WATCH — optional evidence not yet captured."),
            default => $this->fail('RELEASE-SAFETY-EVIDENCE-CHAIN', "Release evidence chain for profile \"{$profile}\" is FAIL — required evidence missing/empty/unsafe/stale."),
        };

        // NSF-10: vps profile must also confirm the captured backup-verify
        // artifact itself reports GO/WATCH (never FAIL) before release
        // safety can be GO. Not applicable to local/ci profiles.
        $backupVerification = null;
        if ($profile === 'vps') {
            $backupVerification = $this->readBackupVerificationArtifact($evidenceChain['directory'] ?? null);
            $backupDecision = $backupVerification['decision'] ?? null;

            $checks[] = match ($backupDecision) {
                'GO' => $this->pass('RELEASE-SAFETY-BACKUP-VERIFIED', 'Captured backup verification evidence is GO.'),
                'WATCH' => $this->warn('RELEASE-SAFETY-BACKUP-VERIFIED', 'Captured backup verification evidence is WATCH.'),
                'FAIL' => $this->fail('RELEASE-SAFETY-BACKUP-VERIFIED', 'Captured backup verification evidence is FAIL.'),
                default => $this->fail('RELEASE-SAFETY-BACKUP-VERIFIED', 'No backup verification evidence found for vps profile — run release:evidence-capture --profile=vps --backup-path=... first.'),
            };
        }

        $errors = count(array_filter($checks, fn (array $c) => $c['status'] === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => $c['status'] === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => $c['status'] === 'passed'));

        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'generated_at' => now()->toIso8601String(),
            'profile' => $profile,
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
            'evidence_chain' => [
                'decision' => $evidenceDecision,
                'directory' => $evidenceChain['directory'] ?? null,
                'summary' => $evidenceChain['summary'] ?? [],
            ],
            'backup_verification' => $backupVerification,
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

    /**
     * Read the previously captured backup-verify.json artifact (written by
     * release:evidence-capture --profile=vps). Never re-reads the backup
     * file itself — only the already-summarized, safe verification result.
     *
     * @return array<string, mixed>|null
     */
    private function readBackupVerificationArtifact(?string $evidenceDirectory): ?array
    {
        if ($evidenceDirectory === null || $evidenceDirectory === '') {
            return null;
        }

        $path = base_path($evidenceDirectory.DIRECTORY_SEPARATOR.'backup-verify.json');
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return null;
        }

        return [
            'decision' => $decoded['summary']['decision'] ?? null,
            'path' => $decoded['path'] ?? null,
            'size_bytes' => $decoded['size_bytes'] ?? null,
            'generated_at' => $decoded['generated_at'] ?? null,
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
