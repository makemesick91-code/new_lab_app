<?php

namespace App\Support\LoadTest;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

/**
 * ENT-13 — read-only Load Test 5 Cabang Baseline scanner.
 *
 * Verifies the load-test harness (scripts/load-test-baseline.sh), the baseline
 * runner command (loadtest:baseline-run), the dataset seeders, the
 * scenario/bottleneck taxonomy, the latency targets, the user mix + branch
 * count, the release-evidence profiles, and the release-safety pre-deploy gate
 * without mutating anything: the harness is fail-fast, guards against
 * production/pilot, invokes the runner, writes into the allowed evidence dir,
 * and carries no destructive command; the runner is a registered artisan
 * command; the dataset target band is a positive 60k–70k range produced by the
 * stress:seed-* harness; every scenario domain and every bottleneck category is
 * declared; and the 200–300ms latency targets are positive and ordered.
 *
 * All literals (destructive patterns, required markers) come from
 * config('load_test_baseline') so no harness/app source file carries the
 * sensitive patterns inline.
 */
class LoadTestBaselineScanner
{
    /**
     * Resolve and read a configured harness file. Returns null when the file is
     * missing so callers can flag it explicitly.
     */
    private function readHarnessFile(string $key): ?string
    {
        $path = (string) config("load_test_baseline.harness_files.{$key}", '');
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
     * Load-test harness posture: fail-fast, non-production guard, runs the
     * baseline runner, writes into the allowed evidence dir, no destructive
     * command.
     *
     * @return array<string, mixed>
     */
    public function harnessScriptPosture(): array
    {
        $contents = $this->readHarnessFile('load_test_script');
        if ($contents === null) {
            return ['file_present' => false, 'ok' => false, 'issues' => ['load-test harness script missing']];
        }

        $lower = strtolower($contents);
        $issues = [];

        $destructive = $this->findForbidden($lower);
        if ($destructive !== []) {
            $issues[] = 'destructive command(s): '.implode(', ', $destructive);
        }

        $failFast = (string) config('load_test_baseline.harness_expectations.required_fail_fast_marker', 'set -euo pipefail');
        $failFastPresent = str_contains($contents, $failFast);
        if (! $failFastPresent) {
            $issues[] = 'missing fail-fast marker (set -euo pipefail)';
        }

        $guardMarker = (string) config('load_test_baseline.non_production_guard_marker', 'must not run against production');
        $guardPresent = $guardMarker === '' || str_contains($contents, $guardMarker);
        if (! $guardPresent) {
            $issues[] = 'harness must guard against production/pilot execution';
        }

        $runMarker = (string) config('load_test_baseline.harness_expectations.required_run_command_marker', 'php artisan loadtest:baseline-run');
        $runPresent = str_contains($contents, $runMarker);
        if (! $runPresent) {
            $issues[] = 'harness must invoke the baseline runner (loadtest:baseline-run)';
        }

        $evidenceMarker = (string) config('load_test_baseline.harness_expectations.required_evidence_dir_marker', 'storage/app/load-test');
        $evidencePresent = $evidenceMarker === '' || str_contains($contents, $evidenceMarker);
        if (! $evidencePresent) {
            $issues[] = 'harness must write evidence into the allowed load-test directory';
        }

        return [
            'file_present' => true,
            'ok' => $issues === [],
            'fail_fast' => $failFastPresent,
            'non_production_guard' => $guardPresent,
            'runs_runner' => $runPresent,
            'writes_evidence' => $evidencePresent,
            'no_destructive_command' => $destructive === [],
            'issues' => $issues,
        ];
    }

    /**
     * Baseline runner posture: loadtest:baseline-run is a registered artisan
     * command.
     *
     * @return array<string, mixed>
     */
    public function runnerCommandPosture(): array
    {
        $command = (string) config('load_test_baseline.run_command', '');
        $issues = [];

        $registered = false;
        try {
            $all = app(ConsoleKernel::class)->all();
            $registered = $command !== '' && array_key_exists($command, $all);
        } catch (\Throwable) {
            $registered = false;
        }

        if (! $registered) {
            $issues[] = "baseline runner command not registered: {$command}";
        }

        return [
            'ok' => $issues === [],
            'run_command' => $command,
            'registered' => $registered,
            'issues' => $issues,
        ];
    }

    /**
     * Dataset posture: 60k–70k patient target band is positive and ordered, and
     * the stress:seed-* harness commands are declared.
     *
     * @return array<string, mixed>
     */
    public function datasetPosture(): array
    {
        $min = (int) config('load_test_baseline.dataset.target_patients_min', 0);
        $max = (int) config('load_test_baseline.dataset.target_patients_max', 0);
        $seedCommands = (array) config('load_test_baseline.dataset.seed_commands', []);

        $issues = [];
        if ($min <= 0) {
            $issues[] = 'target_patients_min must be a positive integer';
        }
        if ($max < $min) {
            $issues[] = 'target_patients_max must be >= target_patients_min';
        }
        if ($seedCommands === []) {
            $issues[] = 'dataset seed commands must be declared';
        }

        return [
            'ok' => $issues === [],
            'target_patients_min' => $min,
            'target_patients_max' => $max,
            'seed_commands' => array_values($seedCommands),
            'issues' => $issues,
        ];
    }

    /**
     * Scenario coverage posture: every scenario has a domain covering RME,
     * cashier, dashboard, inventory, and reports.
     *
     * @return array<string, mixed>
     */
    public function scenarioCoveragePosture(): array
    {
        $scenarios = (array) config('load_test_baseline.scenarios', []);
        $requiredDomains = ['rme', 'cashier', 'dashboard', 'inventory', 'reports'];

        $domains = [];
        foreach ($scenarios as $scenario) {
            $domain = (string) ($scenario['domain'] ?? '');
            if ($domain !== '') {
                $domains[$domain] = true;
            }
        }

        $missing = array_values(array_filter($requiredDomains, fn (string $d) => ! isset($domains[$d])));

        $issues = [];
        if ($scenarios === []) {
            $issues[] = 'no load-test scenarios declared';
        }
        if ($missing !== []) {
            $issues[] = 'missing scenario domain(s): '.implode(', ', $missing);
        }

        return [
            'ok' => $issues === [],
            'scenario_count' => count($scenarios),
            'domains' => array_keys($domains),
            'issues' => $issues,
        ];
    }

    /**
     * Bottleneck taxonomy posture: all seven categories are declared for the
     * follow-up classification list.
     *
     * @return array<string, mixed>
     */
    public function bottleneckTaxonomyPosture(): array
    {
        $categories = (array) config('load_test_baseline.bottleneck_categories', []);
        $required = ['db', 'cache', 'queue', 'php', 'network', 'frontend', 'storage'];

        $missing = array_values(array_filter($required, fn (string $c) => ! in_array($c, $categories, true)));

        $issues = [];
        if ($missing !== []) {
            $issues[] = 'missing bottleneck category(ies): '.implode(', ', $missing);
        }

        return [
            'ok' => $issues === [],
            'categories' => array_values($categories),
            'issues' => $issues,
        ];
    }

    /**
     * Objectives posture: latency targets are positive and ordered (p50 <= p95),
     * user mix counts are positive, and the branch count is positive.
     *
     * @return array<string, mixed>
     */
    public function objectivesPosture(): array
    {
        $p50 = (int) config('load_test_baseline.latency_targets.p50_target_ms', 0);
        $p95 = (int) config('load_test_baseline.latency_targets.p95_target_ms', 0);
        $branchCount = (int) config('load_test_baseline.branch_count', 0);
        $clinic = (int) config('load_test_baseline.user_mix.clinic_users', 0);
        $lab = (int) config('load_test_baseline.user_mix.lab_users', 0);
        $inventory = (int) config('load_test_baseline.user_mix.inventory_users', 0);

        $issues = [];
        if ($p50 <= 0) {
            $issues[] = 'p50_target_ms must be a positive integer';
        }
        if ($p95 <= 0) {
            $issues[] = 'p95_target_ms must be a positive integer';
        }
        if ($p95 < $p50) {
            $issues[] = 'p95_target_ms must be >= p50_target_ms';
        }
        if ($branchCount <= 0) {
            $issues[] = 'branch_count must be a positive integer';
        }
        if ($clinic <= 0 || $lab <= 0 || $inventory <= 0) {
            $issues[] = 'user mix (clinic/lab/inventory) must all be positive';
        }

        return [
            'ok' => $issues === [],
            'p50_target_ms' => $p50,
            'p95_target_ms' => $p95,
            'branch_count' => $branchCount,
            'total_users' => $clinic + $lab + $inventory,
            'issues' => $issues,
        ];
    }

    /**
     * Release-evidence profile posture: the ENT-13 artifact and the ENT-10..12
     * sibling artifacts must be required in the configured profiles.
     *
     * @return array<string, mixed>
     */
    public function evidenceProfilePosture(): array
    {
        $artifact = (string) config('load_test_baseline.evidence.artifact', '');
        $profiles = (array) config('load_test_baseline.evidence.required_in_profiles', []);
        $siblings = (array) config('load_test_baseline.evidence.required_sibling_artifacts', []);

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
     * Release-safety posture: the pre-deploy gate list includes the ENT-13
     * command.
     *
     * @return array<string, mixed>
     */
    public function releaseSafetyPosture(): array
    {
        $gates = (array) config('release_safety.required_pre_deploy_gates', []);
        $requiredGate = (string) config('load_test_baseline.required_pre_deploy_gate_command', '');
        $gatePresent = $requiredGate !== '' && collect($gates)->contains(fn (string $gate) => str_contains($gate, $requiredGate));

        $issues = [];
        if (! $gatePresent) {
            $issues[] = "pre-deploy gate missing command: {$requiredGate}";
        }

        return [
            'ok' => $issues === [],
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
        foreach ((array) config('load_test_baseline.forbidden_destructive_patterns', []) as $pattern) {
            $pattern = strtolower((string) $pattern);
            if ($pattern !== '' && str_contains($lowerContents, $pattern)) {
                $found[] = $pattern;
            }
        }

        return $found;
    }
}
