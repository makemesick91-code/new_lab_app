<?php

namespace App\Support\Deploy;

/**
 * ENT-11 — read-only Deployment & Rollback Automation scanner.
 *
 * Verifies the automated deploy path (scripts/deploy-vps.sh) and the rehearsable
 * rollback path (scripts/rollback-vps.sh) without mutating anything: the deploy
 * script backs up before migrating, migrates additively with --force, preserves
 * the ENT-8 cache-order hardening, rebuilds caches, resets permissions, and runs
 * the smoke command; the rollback script is fail-fast, records the current ref,
 * backs up the DB before checking out the target ref, carries no destructive DB
 * command, rebuilds caches, restarts the runtime, and re-runs the smoke + the
 * ENT-5..10 foundation gates; the release-evidence profiles and the
 * release-safety rollback checklist declare the ENT-11 artifact/plan.
 *
 * All literals (destructive patterns, required markers) come from
 * config('deployment_rollback') so no deploy/rollback/app source file carries
 * the sensitive patterns inline.
 */
class DeploymentRollbackScanner
{
    /**
     * Resolve and read a configured automation file. Returns null when the file
     * is missing so callers can flag it explicitly.
     */
    private function readAutomationFile(string $key): ?string
    {
        $path = (string) config("deployment_rollback.automation_files.{$key}", '');
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
     * permission reset + smoke markers, evidence capture markers.
     *
     * @return array<string, mixed>
     */
    public function deployScriptPosture(): array
    {
        $contents = $this->readAutomationFile('deploy_script');
        if ($contents === null) {
            return ['file_present' => false, 'ok' => false, 'issues' => ['deploy script missing']];
        }

        $lower = strtolower($contents);
        $issues = [];

        $destructive = $this->findForbidden($lower);
        if ($destructive !== []) {
            $issues[] = 'destructive command(s): '.implode(', ', $destructive);
        }

        $migrateCmd = (string) config('deployment_rollback.deploy_expectations.required_migrate_command', 'migrate --force');
        $migrateForcePresent = str_contains($lower, strtolower($migrateCmd));
        if (! $migrateForcePresent) {
            $issues[] = "missing safe migration command ({$migrateCmd})";
        }

        $backupCmd = (string) config('deployment_rollback.deploy_expectations.required_backup_command', 'pg_dump');
        $backupPos = stripos($contents, $backupCmd);
        $migratePos = stripos($contents, $migrateCmd);
        $backupBeforeMigrate = $backupPos !== false && $migratePos !== false && $backupPos < $migratePos;
        if (! $backupBeforeMigrate) {
            $issues[] = 'DB backup must run before migrate';
        }

        $cacheOrder = $this->cacheOrderPosture($contents);
        if (! $cacheOrder['ok']) {
            $issues[] = $cacheOrder['reason'];
        }

        $rebuildMarkers = (array) config('deployment_rollback.cache_order.post_gate_rebuild_markers', []);
        $missingRebuild = $this->missingMarkers($lower, array_map('strtolower', $rebuildMarkers));
        if ($missingRebuild !== []) {
            $issues[] = 'missing cache rebuild: '.implode(', ', $missingRebuild);
        }

        $missingMarkers = $this->missingMarkers($contents, (array) config('deployment_rollback.deploy_expectations.required_markers', []));
        if ($missingMarkers !== []) {
            $issues[] = 'missing deploy marker(s): '.implode(', ', $missingMarkers);
        }

        $missingEvidence = $this->missingMarkers($contents, (array) config('deployment_rollback.deploy_expectations.required_evidence_markers', []));
        if ($missingEvidence !== []) {
            $issues[] = 'missing deploy evidence marker(s): '.implode(', ', $missingEvidence);
        }

        return [
            'file_present' => true,
            'ok' => $issues === [],
            'no_destructive_command' => $destructive === [],
            'migrate_force_present' => $migrateForcePresent,
            'backup_before_migrate' => $backupBeforeMigrate,
            'cache_order_preserved' => $cacheOrder['ok'],
            'cache_rebuild_present' => $missingRebuild === [],
            'issues' => $issues,
        ];
    }

    /**
     * Rollback script posture: present, fail-fast, records the current ref,
     * backs up before checkout, no destructive DB command, ENT-8 cache-order
     * preserved, cache rebuild + restart + smoke markers, foundation gate
     * re-verification markers.
     *
     * @return array<string, mixed>
     */
    public function rollbackScriptPosture(): array
    {
        $contents = $this->readAutomationFile('rollback_script');
        if ($contents === null) {
            return ['file_present' => false, 'ok' => false, 'issues' => ['rollback script missing']];
        }

        $lower = strtolower($contents);
        $issues = [];

        $destructive = $this->findForbidden($lower);
        if ($destructive !== []) {
            $issues[] = 'destructive command(s): '.implode(', ', $destructive);
        }

        $failFast = (string) config('deployment_rollback.rollback_expectations.required_fail_fast_marker', 'set -euo pipefail');
        $failFastPresent = str_contains($contents, $failFast);
        if (! $failFastPresent) {
            $issues[] = 'missing fail-fast marker (set -euo pipefail)';
        }

        $missingRefCapture = $this->missingMarkers($contents, (array) config('deployment_rollback.rollback_expectations.required_ref_capture_markers', []));
        if ($missingRefCapture !== []) {
            $issues[] = 'rollback must record the current ref: '.implode(', ', $missingRefCapture);
        }

        $backupCmd = (string) config('deployment_rollback.rollback_expectations.required_backup_command', 'pg_dump');
        $checkoutMarker = (string) config('deployment_rollback.rollback_expectations.required_checkout_marker', 'git checkout');
        $backupPresent = str_contains($lower, strtolower($backupCmd));
        if (! $backupPresent) {
            $issues[] = "rollback must back up the DB ({$backupCmd}) before checkout";
        }
        $backupPos = stripos($contents, $backupCmd);
        $checkoutPos = stripos($contents, $checkoutMarker);
        $backupBeforeCheckout = $backupPos !== false && $checkoutPos !== false && $backupPos < $checkoutPos;
        if (! $backupBeforeCheckout) {
            $issues[] = 'DB backup must run before the rollback checkout';
        }

        $cacheOrder = $this->cacheOrderPosture($contents);
        if (! $cacheOrder['ok']) {
            $issues[] = $cacheOrder['reason'];
        }

        $rebuildMarkers = (array) config('deployment_rollback.cache_order.post_gate_rebuild_markers', []);
        $missingRebuild = $this->missingMarkers($lower, array_map('strtolower', $rebuildMarkers));
        if ($missingRebuild !== []) {
            $issues[] = 'missing cache rebuild: '.implode(', ', $missingRebuild);
        }

        $missingMarkers = $this->missingMarkers($contents, (array) config('deployment_rollback.rollback_expectations.required_markers', []));
        if ($missingMarkers !== []) {
            $issues[] = 'missing rollback marker(s): '.implode(', ', $missingMarkers);
        }

        $missingGates = $this->missingMarkers($contents, (array) config('deployment_rollback.rollback_expectations.required_gate_markers', []));
        if ($missingGates !== []) {
            $issues[] = 'missing rollback foundation gate(s): '.implode(', ', $missingGates);
        }

        return [
            'file_present' => true,
            'ok' => $issues === [],
            'fail_fast' => $failFastPresent,
            'records_current_ref' => $missingRefCapture === [],
            'no_destructive_command' => $destructive === [],
            'backup_before_checkout' => $backupBeforeCheckout,
            'cache_order_preserved' => $cacheOrder['ok'],
            'cache_rebuild_present' => $missingRebuild === [],
            'reverifies_foundation_gates' => $missingGates === [],
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
        $clearMarkers = (array) config('deployment_rollback.cache_order.pre_gate_clear_markers', []);
        $firstGate = (string) config('deployment_rollback.cache_order.first_route_dependent_gate', '');

        $gatePos = $firstGate !== '' ? stripos($contents, $firstGate) : false;
        if ($gatePos === false) {
            return ['ok' => false, 'reason' => 'ENT-8 cache-order: route-dependent gate not found in script'];
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
     * Release-evidence profile posture: the ENT-11 artifact and the ENT-8..10
     * sibling artifacts must be required in the configured profiles.
     *
     * @return array<string, mixed>
     */
    public function evidenceProfilePosture(): array
    {
        $artifact = (string) config('deployment_rollback.evidence.artifact', '');
        $profiles = (array) config('deployment_rollback.evidence.required_in_profiles', []);
        $siblings = (array) config('deployment_rollback.evidence.required_sibling_artifacts', []);

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

            $perProfile[$profile] = ['artifact_present' => $artifactPresent, 'missing_siblings' => $missingSiblings];
        }

        return ['artifact' => $artifact, 'profiles' => $perProfile, 'ok' => $issues === [], 'issues' => $issues];
    }

    /**
     * Release-safety posture: the rollback checklist declares every required
     * marker and the pre-deploy gate list includes the ENT-11 command.
     *
     * @return array<string, mixed>
     */
    public function releaseSafetyPosture(): array
    {
        $checklist = (array) config('release_safety.rollback_checklist', []);
        $requiredChecklist = (array) config('deployment_rollback.required_rollback_checklist', []);
        $missingChecklist = array_values(array_filter($requiredChecklist, fn (string $m) => ! in_array($m, $checklist, true)));

        $gates = (array) config('release_safety.required_pre_deploy_gates', []);
        $requiredGate = (string) config('deployment_rollback.required_pre_deploy_gate_command', '');
        $gatePresent = $requiredGate !== '' && collect($gates)->contains(fn (string $gate) => str_contains($gate, $requiredGate));

        $issues = [];
        if ($missingChecklist !== []) {
            $issues[] = 'rollback checklist missing: '.implode(', ', $missingChecklist);
        }
        if (! $gatePresent) {
            $issues[] = "pre-deploy gate missing command: {$requiredGate}";
        }

        return [
            'ok' => $issues === [],
            'rollback_checklist_ok' => $missingChecklist === [],
            'pre_deploy_gate_ok' => $gatePresent,
            'issues' => $issues,
        ];
    }

    /**
     * @return list<string>
     */
    private function findForbidden(string $lowerContents): array
    {
        $found = [];
        foreach ((array) config('deployment_rollback.forbidden_destructive_patterns', []) as $pattern) {
            $pattern = strtolower((string) $pattern);
            if ($pattern !== '' && str_contains($lowerContents, $pattern)) {
                $found[] = $pattern;
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $markers
     * @return list<string>
     */
    private function missingMarkers(string $contents, array $markers): array
    {
        $missing = [];
        foreach ($markers as $marker) {
            if ($marker !== '' && ! str_contains($contents, (string) $marker)) {
                $missing[] = $marker;
            }
        }

        return array_values($missing);
    }
}
