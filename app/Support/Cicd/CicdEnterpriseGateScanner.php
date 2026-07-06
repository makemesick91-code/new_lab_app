<?php

namespace App\Support\Cicd;

/**
 * ENT-10 — read-only CI/CD Enterprise Gate scanner.
 *
 * Verifies the enterprise CI/CD posture without mutating anything: the deploy
 * script backs up before migrating, uses `migrate --force` and no destructive
 * DB command, preserves the ENT-8 cache-order hardening, rebuilds route/config
 * caches, and runs the required foundation gate + release-evidence commands;
 * the CI workflow/script run the same foundation stack on pull requests and
 * carry no destructive command; the release-evidence profiles and the
 * release-safety pre-deploy gate declare the ENT-10 artifact/command plus the
 * ENT-5..9 siblings.
 *
 * All literals (destructive patterns, required commands, markers) come from
 * config('cicd_enterprise_gate') so no app/CI/deploy source file carries the
 * sensitive patterns inline.
 */
class CicdEnterpriseGateScanner
{
    /**
     * Resolve and read a configured gate file. Returns null when the file is
     * missing so callers can flag it explicitly.
     */
    private function readGateFile(string $key): ?string
    {
        $path = (string) config("cicd_enterprise_gate.gate_files.{$key}", '');
        if ($path === '') {
            return null;
        }

        $full = base_path($path);
        if (! is_file($full)) {
            return null;
        }

        return (string) file_get_contents($full);
    }

    /**
     * Deploy script posture: backup-before-migrate, migration safety, no
     * destructive DB command, ENT-8 cache-order preserved, cache rebuild,
     * required foundation gate + evidence commands present.
     *
     * @return array<string, mixed>
     */
    public function deployScriptPosture(): array
    {
        $contents = $this->readGateFile('deploy_script');
        if ($contents === null) {
            return [
                'file_present' => false,
                'ok' => false,
                'issues' => ['deploy script missing'],
            ];
        }

        $lower = strtolower($contents);
        $issues = [];

        // Destructive commands must never appear.
        $destructive = $this->findForbidden($lower);
        if ($destructive !== []) {
            $issues[] = 'destructive command(s): '.implode(', ', $destructive);
        }

        // Migration safety: migrate --force present, backup before migrate.
        $migrateCmd = (string) config('cicd_enterprise_gate.migration_safety.required_migrate_command', 'migrate --force');
        $migrateForcePresent = str_contains($lower, strtolower($migrateCmd));
        if (! $migrateForcePresent) {
            $issues[] = "missing safe migration command ({$migrateCmd})";
        }

        $backupCmd = (string) config('cicd_enterprise_gate.migration_safety.required_backup_command', 'pg_dump');
        $backupPos = stripos($contents, $backupCmd);
        $migratePos = stripos($contents, $migrateCmd);
        $backupBeforeMigrate = $backupPos !== false && $migratePos !== false && $backupPos < $migratePos;
        if (! $backupBeforeMigrate) {
            $issues[] = 'DB backup must run before migrate';
        }

        // ENT-8 cache-order hardening: clears before the first route-dependent gate.
        $cacheOrder = $this->cacheOrderPosture($contents);
        if (! $cacheOrder['ok']) {
            $issues[] = $cacheOrder['reason'];
        }

        // Route/config sanity: caches rebuilt after the gate phase.
        $rebuildMarkers = (array) config('cicd_enterprise_gate.cache_order.post_gate_rebuild_markers', []);
        $missingRebuild = array_values(array_filter($rebuildMarkers, fn (string $m) => ! str_contains($lower, strtolower($m))));
        if ($missingRebuild !== []) {
            $issues[] = 'missing cache rebuild: '.implode(', ', $missingRebuild);
        }

        // Required foundation gate commands present.
        $missingCommands = $this->missingCommands($contents, (array) config('cicd_enterprise_gate.required_foundation_commands', []));
        if ($missingCommands !== []) {
            $issues[] = 'missing foundation gate command(s): '.implode(', ', $missingCommands);
        }

        // Required release-evidence commands present.
        $missingEvidence = $this->missingCommands($contents, (array) config('cicd_enterprise_gate.required_deploy_evidence_commands', []));
        if ($missingEvidence !== []) {
            $issues[] = 'missing deploy evidence command(s): '.implode(', ', $missingEvidence);
        }

        return [
            'file_present' => true,
            'ok' => $issues === [],
            'no_destructive_command' => $destructive === [],
            'migrate_force_present' => $migrateForcePresent,
            'backup_before_migrate' => $backupBeforeMigrate,
            'cache_order_preserved' => $cacheOrder['ok'],
            'cache_rebuild_present' => $missingRebuild === [],
            'missing_foundation_commands' => $missingCommands,
            'missing_evidence_commands' => $missingEvidence,
            'issues' => $issues,
        ];
    }

    /**
     * Assert the ENT-8 cache-order hardening in the given script body: the
     * pre-gate clear markers must appear before the first route-dependent gate.
     *
     * @return array{ok: bool, reason: string}
     */
    private function cacheOrderPosture(string $contents): array
    {
        $clearMarkers = (array) config('cicd_enterprise_gate.cache_order.pre_gate_clear_markers', []);
        $firstGate = (string) config('cicd_enterprise_gate.cache_order.first_route_dependent_gate', '');

        $gatePos = $firstGate !== '' ? stripos($contents, $firstGate) : false;
        if ($gatePos === false) {
            return ['ok' => false, 'reason' => 'ENT-8 cache-order: route-dependent gate not found in deploy script'];
        }

        foreach ($clearMarkers as $marker) {
            $markerPos = stripos($contents, (string) $marker);
            if ($markerPos === false || $markerPos > $gatePos) {
                return ['ok' => false, 'reason' => "ENT-8 cache-order: '{$marker}' must be cleared before route-dependent gates"];
            }
        }

        return ['ok' => true, 'reason' => ''];
    }

    /**
     * CI workflow + CI script posture: pull_request trigger present, foundation
     * stack run, fail-fast marker present, no destructive command.
     *
     * @return array<string, mixed>
     */
    public function ciPosture(): array
    {
        $workflow = $this->readGateFile('ci_workflow');
        $script = $this->readGateFile('ci_script');

        $issues = [];

        if ($workflow === null) {
            $issues[] = 'CI workflow missing';
        }
        if ($script === null) {
            $issues[] = 'CI script missing';
        }

        $triggerOk = false;
        $baseBranchOk = false;
        $destructiveWorkflow = [];
        if ($workflow !== null) {
            $triggers = (array) config('cicd_enterprise_gate.ci_workflow_expectations.required_triggers', []);
            $triggerOk = collect($triggers)->every(fn (string $t) => str_contains($workflow, $t));
            if (! $triggerOk) {
                $issues[] = 'CI workflow missing required trigger(s): '.implode(', ', $triggers);
            }

            $baseBranch = (string) config('cicd_enterprise_gate.ci_workflow_expectations.base_branch', '');
            $baseBranchOk = $baseBranch !== '' && str_contains($workflow, $baseBranch);
            if (! $baseBranchOk) {
                $issues[] = 'CI workflow does not target the approved base branch';
            }

            $destructiveWorkflow = $this->findForbidden(strtolower($workflow));
            if ($destructiveWorkflow !== []) {
                $issues[] = 'CI workflow destructive command(s): '.implode(', ', $destructiveWorkflow);
            }
        }

        $failFastOk = false;
        $destructiveScript = [];
        $missingCiCommands = [];
        if ($script !== null) {
            $failFast = (string) config('cicd_enterprise_gate.ci_workflow_expectations.fail_fast_marker', '');
            $failFastOk = $failFast !== '' && str_contains($script, $failFast);
            if (! $failFastOk) {
                $issues[] = 'CI script missing fail-fast marker';
            }

            $destructiveScript = $this->findForbidden(strtolower($script));
            if ($destructiveScript !== []) {
                $issues[] = 'CI script destructive command(s): '.implode(', ', $destructiveScript);
            }

            // The CI script must run the ENT-5..9 foundation stack (excluding the
            // ENT-10 self-command and roadmap check which live in other jobs).
            $ciRequired = array_values(array_diff(
                (array) config('cicd_enterprise_gate.required_foundation_commands', []),
                ['foundation:cicd-enterprise-gate-check', 'architecture:foundation-roadmap-check', 'foundation:release-safety-check'],
            ));
            $missingCiCommands = $this->missingCommands($script, $ciRequired);
            if ($missingCiCommands !== []) {
                $issues[] = 'CI script missing foundation command(s): '.implode(', ', $missingCiCommands);
            }
        }

        return [
            'workflow_present' => $workflow !== null,
            'script_present' => $script !== null,
            'pull_request_trigger' => $triggerOk,
            'base_branch_targeted' => $baseBranchOk,
            'fail_fast' => $failFastOk,
            'no_destructive_command' => $destructiveWorkflow === [] && $destructiveScript === [],
            'missing_ci_commands' => $missingCiCommands,
            'ok' => $issues === [],
            'issues' => $issues,
        ];
    }

    /**
     * Release-evidence profile posture: the ENT-10 artifact and the ENT-5..9
     * sibling artifacts must be required in the configured profiles.
     *
     * @return array<string, mixed>
     */
    public function evidenceProfilePosture(): array
    {
        $artifact = (string) config('cicd_enterprise_gate.evidence.artifact', '');
        $profiles = (array) config('cicd_enterprise_gate.evidence.required_in_profiles', []);
        $siblings = (array) config('cicd_enterprise_gate.evidence.required_sibling_artifacts', []);

        $issues = [];
        $perProfile = [];

        foreach ($profiles as $profile) {
            $required = (array) config("release_evidence.profiles.{$profile}.required_artifacts", []);

            $artifactPresent = in_array($artifact, $required, true);
            $missingSiblings = array_values(array_filter($siblings, fn (string $s) => ! in_array($s, $required, true)));

            if (! $artifactPresent) {
                $issues[] = "profile {$profile} missing artifact {$artifact}";
            }
            if ($missingSiblings !== []) {
                $issues[] = "profile {$profile} missing sibling artifact(s): ".implode(', ', $missingSiblings);
            }

            $perProfile[$profile] = [
                'artifact_present' => $artifactPresent,
                'missing_siblings' => $missingSiblings,
            ];
        }

        return [
            'artifact' => $artifact,
            'profiles' => $perProfile,
            'ok' => $issues === [],
            'issues' => $issues,
        ];
    }

    /**
     * Release-safety pre-deploy gate posture: the configured command names must
     * appear in config/release_safety.php required_pre_deploy_gates.
     *
     * @return array<string, mixed>
     */
    public function preDeployGatePosture(): array
    {
        $gates = (array) config('release_safety.required_pre_deploy_gates', []);
        $required = (array) config('cicd_enterprise_gate.required_pre_deploy_gate_commands', []);

        $missing = array_values(array_filter(
            $required,
            fn (string $needle) => ! collect($gates)->contains(fn (string $gate) => str_contains($gate, $needle))
        ));

        return [
            'ok' => $missing === [],
            'missing_gate_commands' => $missing,
            'total_gates' => count($gates),
        ];
    }

    /**
     * @return list<string>
     */
    private function findForbidden(string $lowerContents): array
    {
        $found = [];
        foreach ((array) config('cicd_enterprise_gate.forbidden_destructive_patterns', []) as $pattern) {
            $pattern = strtolower((string) $pattern);
            if ($pattern !== '' && str_contains($lowerContents, $pattern)) {
                $found[] = $pattern;
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $commands
     * @return list<string>
     */
    private function missingCommands(string $contents, array $commands): array
    {
        $missing = [];
        foreach ($commands as $command) {
            if ($command !== '' && ! str_contains($contents, (string) $command)) {
                $missing[] = $command;
            }
        }

        return array_values($missing);
    }
}
