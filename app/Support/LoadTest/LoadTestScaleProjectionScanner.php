<?php

namespace App\Support\LoadTest;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

/**
 * ENT-14 — read-only Load Test Scale Projection scanner.
 *
 * Verifies the scale-projection harness (scripts/load-test-scale-projection.sh),
 * the projection runner command (loadtest:scale-projection-run), the projection
 * tiers, the ENT-13 baseline linkage, the bottleneck taxonomy, the shipped
 * LB-1/REPLICA-1 foundation linkage, the release-evidence profiles, and the
 * release-safety pre-deploy gate without mutating anything: the harness is
 * fail-fast, guards against production/pilot, invokes the runner, writes into
 * the allowed evidence dir, and carries no destructive command; the runner is a
 * registered artisan command; the tiers are monotonic-increasing and cover the
 * baseline/20-branch/national branch counts; the baseline source resolves and
 * its lowest tier matches the baseline branch count; every bottleneck category
 * and both LB-1/REPLICA-1 readiness foundations are declared.
 *
 * All literals (destructive patterns, required markers) come from
 * config('load_test_scale_projection') so no harness/app source file carries the
 * sensitive patterns inline.
 */
class LoadTestScaleProjectionScanner
{
    /**
     * Resolve and read a configured harness file. Returns null when the file is
     * missing so callers can flag it explicitly.
     */
    private function readHarnessFile(string $key): ?string
    {
        $path = (string) config("load_test_scale_projection.harness_files.{$key}", '');
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
     * Scale-projection harness posture: fail-fast, non-production guard, runs the
     * projection runner, writes into the allowed evidence dir, no destructive
     * command.
     *
     * @return array<string, mixed>
     */
    public function harnessScriptPosture(): array
    {
        $contents = $this->readHarnessFile('scale_projection_script');
        if ($contents === null) {
            return ['file_present' => false, 'ok' => false, 'issues' => ['scale-projection harness script missing']];
        }

        $lower = strtolower($contents);
        $issues = [];

        $destructive = $this->findForbidden($lower);
        if ($destructive !== []) {
            $issues[] = 'destructive command(s): '.implode(', ', $destructive);
        }

        $failFast = (string) config('load_test_scale_projection.harness_expectations.required_fail_fast_marker', 'set -euo pipefail');
        $failFastPresent = str_contains($contents, $failFast);
        if (! $failFastPresent) {
            $issues[] = 'missing fail-fast marker (set -euo pipefail)';
        }

        $guardMarker = (string) config('load_test_scale_projection.non_production_guard_marker', 'must not run against production');
        $guardPresent = $guardMarker === '' || str_contains($contents, $guardMarker);
        if (! $guardPresent) {
            $issues[] = 'harness must guard against production/pilot execution';
        }

        $runMarker = (string) config('load_test_scale_projection.harness_expectations.required_run_command_marker', 'php artisan loadtest:scale-projection-run');
        $runPresent = str_contains($contents, $runMarker);
        if (! $runPresent) {
            $issues[] = 'harness must invoke the projection runner (loadtest:scale-projection-run)';
        }

        $evidenceMarker = (string) config('load_test_scale_projection.harness_expectations.required_evidence_dir_marker', 'storage/app/load-test');
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
     * Projection runner posture: loadtest:scale-projection-run is a registered
     * artisan command.
     *
     * @return array<string, mixed>
     */
    public function runnerCommandPosture(): array
    {
        $command = (string) config('load_test_scale_projection.run_command', '');
        $issues = [];

        $registered = false;
        try {
            $all = app(ConsoleKernel::class)->all();
            $registered = $command !== '' && array_key_exists($command, $all);
        } catch (\Throwable) {
            $registered = false;
        }

        if (! $registered) {
            $issues[] = "projection runner command not registered: {$command}";
        }

        return [
            'ok' => $issues === [],
            'run_command' => $command,
            'registered' => $registered,
            'issues' => $issues,
        ];
    }

    /**
     * Tier posture: at least two tiers, monotonic-increasing branch_count,
     * positive scale factors, and coverage of the required branch counts
     * (baseline / 20-branch target / national).
     *
     * @return array<string, mixed>
     */
    public function tiersPosture(): array
    {
        $tiers = (array) config('load_test_scale_projection.tiers', []);
        $required = array_map('intval', (array) config('load_test_scale_projection.required_tier_branch_counts', []));

        $issues = [];
        $branchCounts = [];
        $previous = null;
        $ordered = true;

        foreach ($tiers as $key => $tier) {
            $branches = (int) ($tier['branch_count'] ?? 0);
            $factor = (float) ($tier['scale_factor'] ?? 0.0);
            $branchCounts[] = $branches;

            if ($branches <= 0) {
                $issues[] = "tier {$key} branch_count must be positive";
            }
            if ($factor <= 0) {
                $issues[] = "tier {$key} scale_factor must be positive";
            }
            if ($previous !== null && $branches <= $previous) {
                $ordered = false;
            }
            $previous = $branches;
        }

        if (count($tiers) < 2) {
            $issues[] = 'at least two projection tiers must be declared';
        }
        if (! $ordered) {
            $issues[] = 'projection tiers must be monotonic-increasing by branch_count';
        }

        $missing = array_values(array_filter($required, fn (int $b) => ! in_array($b, $branchCounts, true)));
        if ($missing !== []) {
            $issues[] = 'missing required tier branch count(s): '.implode(', ', $missing);
        }

        return [
            'ok' => $issues === [],
            'tier_count' => count($tiers),
            'branch_counts' => $branchCounts,
            'monotonic' => $ordered,
            'issues' => $issues,
        ];
    }

    /**
     * Baseline linkage posture: the baseline source config resolves and the
     * lowest projection tier matches the ENT-13 baseline branch count.
     *
     * @return array<string, mixed>
     */
    public function baselineLinkagePosture(): array
    {
        $sourceKey = (string) config('load_test_scale_projection.baseline_source', '');
        $issues = [];

        $baselineBranchCount = (int) config("{$sourceKey}.branch_count", 0);
        if ($sourceKey === '' || $baselineBranchCount <= 0) {
            $issues[] = 'baseline_source must resolve to the ENT-13 baseline config with a positive branch_count';
        }

        $tiers = (array) config('load_test_scale_projection.tiers', []);
        $lowest = null;
        foreach ($tiers as $tier) {
            $branches = (int) ($tier['branch_count'] ?? 0);
            if ($lowest === null || $branches < $lowest) {
                $lowest = $branches;
            }
        }

        if ($lowest !== null && $baselineBranchCount > 0 && $lowest !== $baselineBranchCount) {
            $issues[] = "lowest projection tier ({$lowest}) must match baseline branch count ({$baselineBranchCount})";
        }

        return [
            'ok' => $issues === [],
            'baseline_source' => $sourceKey,
            'baseline_branch_count' => $baselineBranchCount,
            'lowest_tier_branch_count' => $lowest,
            'issues' => $issues,
        ];
    }

    /**
     * Bottleneck taxonomy posture: all seven categories are declared for the
     * per-tier capacity model.
     *
     * @return array<string, mixed>
     */
    public function bottleneckTaxonomyPosture(): array
    {
        $categories = (array) config('load_test_scale_projection.bottleneck_categories', []);
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
     * Foundation linkage posture: the projection ties higher tiers to the
     * shipped LB-1 and REPLICA-1 readiness foundations.
     *
     * @return array<string, mixed>
     */
    public function foundationLinkagePosture(): array
    {
        $declared = (array) config('load_test_scale_projection.related_shipped_foundations', []);
        $required = ['LB-1', 'REPLICA-1'];

        $missing = array_values(array_filter($required, fn (string $f) => ! in_array($f, $declared, true)));

        $issues = [];
        if ($missing !== []) {
            $issues[] = 'projection must tie to shipped foundation(s): '.implode(', ', $missing);
        }

        return [
            'ok' => $issues === [],
            'related_shipped_foundations' => array_values($declared),
            'issues' => $issues,
        ];
    }

    /**
     * Release-evidence profile posture: the ENT-14 artifact and the ENT-11..13
     * sibling artifacts must be required in the configured profiles.
     *
     * @return array<string, mixed>
     */
    public function evidenceProfilePosture(): array
    {
        $artifact = (string) config('load_test_scale_projection.evidence.artifact', '');
        $profiles = (array) config('load_test_scale_projection.evidence.required_in_profiles', []);
        $siblings = (array) config('load_test_scale_projection.evidence.required_sibling_artifacts', []);

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
     * Release-safety posture: the pre-deploy gate list includes the ENT-14
     * command.
     *
     * @return array<string, mixed>
     */
    public function releaseSafetyPosture(): array
    {
        $gates = (array) config('release_safety.required_pre_deploy_gates', []);
        $requiredGate = (string) config('load_test_scale_projection.required_pre_deploy_gate_command', '');
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
        foreach ((array) config('load_test_scale_projection.forbidden_destructive_patterns', []) as $pattern) {
            $pattern = strtolower((string) $pattern);
            if ($pattern !== '' && str_contains($lowerContents, $pattern)) {
                $found[] = $pattern;
            }
        }

        return $found;
    }
}
