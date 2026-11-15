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

        $privilegeSeparation = $this->privilegeSeparationIssues($contents);
        if ($privilegeSeparation !== []) {
            $issues[] = 'privilege separation (rule 233): '.implode('; ', $privilegeSeparation);
        }

        return [
            'file_present' => true,
            'ok' => $issues === [],
            'no_destructive_command' => $destructive === [],
            'migrate_force_present' => $migrateForcePresent,
            'backup_before_migrate' => $backupBeforeMigrate,
            'cache_order_preserved' => $cacheOrder['ok'],
            'cache_rebuild_present' => $missingRebuild === [],
            'privilege_separation' => $privilegeSeparation === [],
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

        $privilegeSeparation = $this->privilegeSeparationIssues($contents);
        if ($privilegeSeparation !== []) {
            $issues[] = 'privilege separation (rule 233): '.implode('; ', $privilegeSeparation);
        }

        return [
            'privilege_separation' => $privilegeSeparation === [],
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
     * DMS-DEPLOY-PRIVSEP-HARDENING-1 (rule 233): a deploy or rollback script
     * runs as root over trees the runtime user owns, so it must never execute
     * application PHP as root (composer scripts included) and never mutate a
     * runtime tree by path as root. Scans logical lines (continuations joined,
     * comments dropped) against config('deployment_rollback.privilege_separation').
     * A regression guard for reviewed code: it reads the script, it cannot see
     * what a helper does at run time.
     *
     * @return list<string>
     */
    public function privilegeSeparationIssues(string $contents, bool $requireMarkers = true): array
    {
        $contract = (array) config('deployment_rollback.privilege_separation', []);
        $issues = [];

        if ($requireMarkers) {
            foreach ($this->missingMarkers($contents, array_values((array) ($contract['required_markers'] ?? []))) as $marker) {
                $issues[] = "missing marker: {$marker}";
            }
        }

        $runtimePhpPrefix = (string) ($contract['runtime_php_prefix'] ?? '/(?!)/');
        $runtimeLinePrefix = (string) ($contract['runtime_line_prefix'] ?? '/(?!)/');
        $allowedPhp = array_values(array_filter((array) ($contract['allowed_root_php'] ?? [])));
        $allowedComposer = array_values(array_filter((array) ($contract['allowed_root_composer_without_no_scripts'] ?? [])));
        $anyLine = (array) ($contract['forbidden_line_patterns'] ?? []);
        $rootLine = (array) ($contract['forbidden_root_line_patterns'] ?? []);
        $buildPrefix = (string) ($contract['build_line_prefix'] ?? '/(?!)/');
        $allowedBuild = array_values(array_filter((array) ($contract['allowed_root_build_probes'] ?? [])));

        foreach ($this->logicalShellLines($contents) as [$number, $line]) {
            // php, php8.3, /usr/bin/php — wherever it appears on the line.
            $phpLine = str_replace($allowedPhp, '', $line);
            preg_match_all('/(?<![\w.\/-])(?:\/[\w.\/-]*\/)?php(?:\d+(?:\.\d+)*)?(?=[\s\'"`;)|&]|$)/', $phpLine, $tokens, PREG_OFFSET_CAPTURE);
            foreach ($tokens[0] as [, $offset]) {
                if (preg_match($runtimePhpPrefix, substr($phpLine, 0, (int) $offset)) !== 1) {
                    $issues[] = "line {$number}: application PHP runs as root (hand it to the runtime user with as_runtime)";
                    break;
                }
            }

            $composerLine = str_replace($allowedComposer, '', $line);
            if (preg_match('/(?<![\w.\/-])(?:\/[\w.\/-]*\/)?composer(?:\.phar)?(?:\s+-\S+)*\s+(?:install|update|upgrade|require|remove|reinstall|dump-autoload|dumpautoload|run-script|run|exec|create-project)\b/', $composerLine) === 1
                && ! str_contains($composerLine, '--no-scripts')) {
                $issues[] = "line {$number}: composer runs its scripts as root (add --no-scripts)";
            }

            // Rule 235: composer, npm, npx, node, yarn, pnpm, bun — wherever on
            // the line — only behind the build identity's sandboxed hand-off.
            // Each `;` / `&&` / `||` / `|` segment is judged on its own, so a
            // second command chained after a hand-off is not covered by it.
            $buildLine = str_replace($allowedBuild, '', $line);
            foreach (preg_split('/\s*(?:&&|\|\||;|\|)\s*/', $buildLine) ?: [] as $segment) {
                if (preg_match('/(?<![\w.\/-])(?:\/[\w.\/-]*\/)?(?:composer(?:\.phar)?|npm-cli\.js|npm|npx|nodejs|node|yarnpkg|yarn|pnpm|bun)(?=[\s\'"`;)|&]|$)/', $segment) === 1
                    && preg_match($buildPrefix, $segment) !== 1) {
                    $issues[] = "line {$number}: composer/npm runs as root (hand it to the build identity with dms_build_run)";
                    break;
                }
            }

            foreach ($anyLine as $label => $pattern) {
                if (is_string($pattern) && $pattern !== '' && preg_match($pattern, $line) === 1) {
                    $issues[] = "line {$number}: {$label}";
                }
            }

            if (preg_match($runtimeLinePrefix, $line) !== 1) {
                foreach ($rootLine as $label => $pattern) {
                    if (is_string($pattern) && $pattern !== '' && preg_match($pattern, $line) === 1) {
                        $issues[] = "line {$number}: {$label}";
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * Logical shell lines: backslash continuations joined, blank and comment
     * lines dropped, a trailing " # comment" outside quotes removed.
     *
     * @return list<array{0:int,1:string}> [first physical line number, text]
     */
    private function logicalShellLines(string $contents): array
    {
        $lines = [];
        $buffer = '';
        $start = 0;
        foreach (preg_split('/\R/', $contents) ?: [] as $index => $physical) {
            if ($buffer === '') {
                $start = $index + 1;
                if (preg_match('/^\s*#/', $physical) === 1) {
                    continue;
                }
            }
            if (preg_match('/\\\\$/', $physical) === 1) {
                $buffer .= substr($physical, 0, -1).' ';

                continue;
            }
            $line = $this->stripTrailingShellComment($buffer.$physical);
            $buffer = '';
            if (trim($line) !== '') {
                $lines[] = [$start, $line];
            }
        }
        if (trim($buffer) !== '') {
            $lines[] = [$start, $this->stripTrailingShellComment($buffer)];
        }

        return $lines;
    }

    private function stripTrailingShellComment(string $line): string
    {
        $single = false;
        $double = false;
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === '\\' && ! $single) {
                $i++;

                continue;
            }
            if ($char === "'" && ! $double) {
                $single = ! $single;
            } elseif ($char === '"' && ! $single) {
                $double = ! $double;
            } elseif ($char === '#' && ! $single && ! $double && ($i === 0 || ctype_space($line[$i - 1]))) {
                return rtrim(substr($line, 0, $i));
            }
        }

        return $line;
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
