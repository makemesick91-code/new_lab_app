<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * ENT-14 — Load Test Scale Projection runner.
 *
 * Extrapolates the ENT-13 5-branch measured baseline to the configured scale
 * tiers (20-branch target + national), producing a MODELED capacity projection
 * per tier with per-tier risks and next actions, and writes a non-sensitive
 * projection pack (tiers + modeled counts + labels — never PII).
 *
 * The projection is analysis-only: it computes an estimated model, never
 * activates replica read routing or multi-node traffic, and never changes
 * production topology. Every projected number is labeled modeled/estimated.
 *
 * SAFETY: only runs in the allowed non-production environments
 * (config('load_test_scale_projection.allowed_environments')); aborts on
 * production/pilot. Read-only — no seed, no write, no query, no schema change.
 */
class LoadTestScaleProjectionRunCommand extends Command
{
    protected $signature = 'loadtest:scale-projection-run
        {--json : Output the projection pack as JSON}
        {--dry-run : Validate environment + tier plan without computing detailed projections}
        {--write-evidence : Also persist the projection pack under storage/app/load-test}';

    protected $description = 'ENT-14 non-production load-test scale projection runner (analysis-only, modeled, guarded).';

    public function handle(): int
    {
        $allowed = (array) config('load_test_scale_projection.allowed_environments', ['local', 'stress', 'testing']);

        if (! app()->environment($allowed)) {
            $this->error('loadtest:scale-projection-run must not run against production/pilot. Allowed: '.implode(', ', $allowed).'.');

            return self::FAILURE;
        }

        $basisLabel = (string) config('load_test_scale_projection.projection_basis', 'modeled');
        $baselineKey = (string) config('load_test_scale_projection.baseline_source', 'load_test_baseline');

        $baselineBranches = (int) config("{$baselineKey}.branch_count", 5);
        $baselineUsers = (int) config("{$baselineKey}.user_mix.clinic_users", 0)
            + (int) config("{$baselineKey}.user_mix.lab_users", 0)
            + (int) config("{$baselineKey}.user_mix.inventory_users", 0);
        $baselinePatientsMin = (int) config("{$baselineKey}.dataset.target_patients_min", 0);
        $baselinePatientsMax = (int) config("{$baselineKey}.dataset.target_patients_max", 0);
        $baselineP50 = (int) config("{$baselineKey}.latency_targets.p50_target_ms", 200);
        $baselineP95 = (int) config("{$baselineKey}.latency_targets.p95_target_ms", 300);

        $tiers = (array) config('load_test_scale_projection.tiers', []);
        $projections = [];

        foreach ($tiers as $key => $tier) {
            $branches = (int) ($tier['branch_count'] ?? 0);
            $factor = $baselineBranches > 0 ? round($branches / $baselineBranches, 2) : 0.0;
            $scaleOut = (string) ($tier['scale_out_expectation'] ?? '');

            if ($this->option('dry-run')) {
                $projections[] = [
                    'tier' => $key,
                    'branch_count' => $branches,
                    'scale_factor' => $factor,
                    'status' => 'planned',
                    'basis' => 'estimated',
                    'scale_out_expectation' => $scaleOut,
                ];

                continue;
            }

            // Linear extrapolation on branch count. Every value is modeled.
            $projectedUsers = (int) round($baselineUsers * $factor);
            $projectedPatientsMin = (int) round($baselinePatientsMin * $factor);
            $projectedPatientsMax = (int) round($baselinePatientsMax * $factor);

            // Naive single-node p95 grows with load; scale-out (LB-1 + REPLICA-1)
            // holds per-node load near the baseline band. Both modeled.
            $projectedP95Naive = (int) round($baselineP95 * $factor);
            $projectedP95ScaledOut = $baselineP95;

            $projections[] = [
                'tier' => $key,
                'label' => (string) ($tier['label'] ?? $key),
                'branch_count' => $branches,
                'scale_factor' => $factor,
                'status' => 'projected',
                'basis' => 'estimated',
                'projected_concurrent_users' => $projectedUsers,
                'projected_patients_min' => $projectedPatientsMin,
                'projected_patients_max' => $projectedPatientsMax,
                'projected_p95_naive_single_node_ms' => $projectedP95Naive,
                'projected_p95_with_scale_out_ms' => $projectedP95ScaledOut,
                'primary_bottleneck_focus' => $factor > 2.0 ? 'db' : 'php',
                'scale_out_expectation' => $scaleOut,
            ];
        }

        $pack = [
            'sprint' => 'ENT-14',
            'generated_at' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'mode' => $this->option('dry-run') ? 'dry_run' : 'projected',
            'projection_basis' => $basisLabel,
            'disclaimer' => 'Projected numbers are modeled/estimated extrapolations of the ENT-13 baseline, not measured production benchmarks.',
            'baseline_inputs' => [
                'source' => $baselineKey,
                'branch_count' => $baselineBranches,
                'concurrent_users' => $baselineUsers,
                'patients_min' => $baselinePatientsMin,
                'patients_max' => $baselinePatientsMax,
                'p50_target_ms' => $baselineP50,
                'p95_target_ms' => $baselineP95,
            ],
            'model_inputs' => [
                'method' => 'linear extrapolation on branch count',
                'basis' => $basisLabel,
                'mitigation_foundations' => (array) config('load_test_scale_projection.related_shipped_foundations', []),
            ],
            'bottleneck_categories' => (array) config('load_test_scale_projection.bottleneck_categories', []),
            'projections' => $projections,
            'risks' => [
                'Naive single-node latency grows roughly linearly with branch count; the 20-branch and national tiers exceed the 200–300ms band without horizontal scale-out.',
                'Heavy report/dashboard pages are the first DB bottleneck at scale; move to summary/async (ENT-3 reporting summary) before the national tier.',
                'Projection is modeled from a single measured baseline; re-measure after each major schema/index change (ENT-2) before trusting a higher tier.',
            ],
            'next_actions' => [
                'Activate LB-1 horizontal scale + REPLICA-1 read routing before the 20-branch target tier (readiness only today; no topology change in this projection).',
                'Re-run the ENT-13 baseline after cache (ENT-4) and reporting-summary (ENT-3) changes and refresh this projection.',
                'Track each over-band tier against the db/cache/queue/php/network/frontend/storage taxonomy in the evidence pack.',
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printConsole($pack);
        }

        if ($this->option('write-evidence')) {
            $this->persistEvidence($pack);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $pack
     */
    private function persistEvidence(array $pack): void
    {
        // Config value is repo-relative (storage/app/load-test).
        $relative = (string) config('load_test_scale_projection.evidence_output_dir', 'storage/app/load-test');
        $dir = base_path($relative);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file = $dir.'/scale-projection-'.now()->format('Ymd-His').'.json';
        file_put_contents($file, (string) json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Wrote projection pack: '.$file);
    }

    /**
     * @param  array<string, mixed>  $pack
     */
    private function printConsole(array $pack): void
    {
        $this->info('ENT-14 Load Test Scale Projection ('.$pack['mode'].', basis: '.$pack['projection_basis'].', env: '.$pack['environment'].')');
        $this->warn($pack['disclaimer']);
        $b = $pack['baseline_inputs'];
        $this->line(sprintf(
            'Baseline: %d branches | %d users | %d–%d patients | p50 %dms / p95 %dms',
            $b['branch_count'], $b['concurrent_users'], $b['patients_min'], $b['patients_max'], $b['p50_target_ms'], $b['p95_target_ms'],
        ));
        $this->newLine();

        $rows = [];
        foreach ($pack['projections'] as $p) {
            $rows[] = [
                $p['tier'],
                $p['branch_count'],
                $p['scale_factor'],
                $p['status'],
                $p['projected_concurrent_users'] ?? '-',
                $p['projected_p95_naive_single_node_ms'] ?? '-',
                $p['projected_p95_with_scale_out_ms'] ?? '-',
            ];
        }
        $this->table(['tier', 'branches', 'x', 'status', 'proj_users', 'p95_naive_ms', 'p95_scaled_ms'], $rows);
    }
}
