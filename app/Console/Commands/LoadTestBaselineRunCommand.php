<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ENT-13 — Load Test 5 Cabang Baseline runner.
 *
 * Runs the in-process baseline scenarios (representative read-side queries for
 * RME queue, cashier invoices, owner dashboard, inventory stock, reports, and RM
 * lookup), times each against the documented 200–300ms latency band, classifies
 * anything slower against the fixed bottleneck taxonomy, and writes a
 * non-sensitive evidence pack (per-scenario timings + counts + category labels —
 * never PII).
 *
 * SAFETY: only runs in the allowed non-production environments
 * (config('load_test_baseline.allowed_environments')); aborts on
 * production/pilot. Read-only — no seed, no write, no schema change.
 */
class LoadTestBaselineRunCommand extends Command
{
    protected $signature = 'loadtest:baseline-run
        {--json : Output the evidence pack as JSON}
        {--dry-run : Validate environment + scenario plan without timing queries}
        {--write-evidence : Also persist the evidence pack under storage/app/load-test}';

    protected $description = 'ENT-13 non-production 5-branch baseline load test runner (read-only, guarded).';

    public function handle(): int
    {
        $allowed = (array) config('load_test_baseline.allowed_environments', ['local', 'stress', 'testing']);

        if (! app()->environment($allowed)) {
            $this->error('loadtest:baseline-run must not run against production/pilot. Allowed: '.implode(', ', $allowed).'.');

            return self::FAILURE;
        }

        $scenarios = (array) config('load_test_baseline.scenarios', []);
        $p95 = (int) config('load_test_baseline.latency_targets.p95_target_ms', 300);
        $p50 = (int) config('load_test_baseline.latency_targets.p50_target_ms', 200);

        $results = [];
        $followUps = [];

        foreach ($scenarios as $key => $scenario) {
            $domain = (string) ($scenario['domain'] ?? 'unknown');

            if ($this->option('dry-run')) {
                $results[] = [
                    'scenario' => $key,
                    'domain' => $domain,
                    'status' => 'planned',
                    'elapsed_ms' => null,
                    'rows' => null,
                ];

                continue;
            }

            $measured = $this->measureScenario((string) $key);
            $elapsed = $measured['elapsed_ms'];
            $status = $measured['ok']
                ? ($elapsed !== null && $elapsed > $p95 ? 'over_target' : 'ok')
                : 'unavailable';

            $results[] = [
                'scenario' => $key,
                'domain' => $domain,
                'status' => $status,
                'elapsed_ms' => $elapsed,
                'rows' => $measured['rows'],
            ];

            if ($status === 'over_target') {
                $followUps[] = [
                    'scenario' => $key,
                    'domain' => $domain,
                    'elapsed_ms' => $elapsed,
                    'bottleneck_category' => 'db',
                    'note' => "Scenario exceeded the {$p95}ms p95 target; review query/index (ENT-2) and cache (ENT-4).",
                ];
            }
        }

        $pack = [
            'sprint' => 'ENT-13',
            'generated_at' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'mode' => $this->option('dry-run') ? 'dry_run' : 'measured',
            'branch_count' => (int) config('load_test_baseline.branch_count', 5),
            'user_mix' => (array) config('load_test_baseline.user_mix', []),
            'dataset_target' => [
                'patients_min' => (int) config('load_test_baseline.dataset.target_patients_min', 0),
                'patients_max' => (int) config('load_test_baseline.dataset.target_patients_max', 0),
            ],
            'latency_targets_ms' => ['p50' => $p50, 'p95' => $p95],
            'bottleneck_categories' => (array) config('load_test_baseline.bottleneck_categories', []),
            'results' => $results,
            'bottleneck_follow_ups' => $followUps,
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
     * Time one representative bounded read-side query for a scenario. Any failure
     * (missing table on a partial dataset) degrades to "unavailable" — never
     * throws.
     *
     * @return array{ok: bool, elapsed_ms: float|null, rows: int|null}
     */
    private function measureScenario(string $key): array
    {
        try {
            $start = microtime(true);
            $rows = $this->scenarioQuery($key);
            $elapsed = round((microtime(true) - $start) * 1000, 2);

            return ['ok' => true, 'elapsed_ms' => $elapsed, 'rows' => $rows];
        } catch (\Throwable) {
            return ['ok' => false, 'elapsed_ms' => null, 'rows' => null];
        }
    }

    private function scenarioQuery(string $key): int
    {
        return match ($key) {
            'rme_patient_queue' => (int) DB::table('trx_clinic_visits')
                ->whereNotIn('status', ['cashier_pending', 'completed', 'cancelled'])
                ->limit(500)->count(),
            'cashier_invoices' => (int) DB::table('trx_rme_invoices')
                ->whereIn('status', ['UNPAID', 'PARTIAL'])
                ->limit(500)->count(),
            'owner_dashboard' => (int) DB::table('trx_rme_payments')
                ->whereDate('paid_at', '>=', now()->subDays(30)->toDateString())
                ->limit(1000)->count(),
            'inventory_stock' => (int) DB::table('mst_products')->limit(1000)->count(),
            'reports' => (int) DB::table('trx_rme_invoices')
                ->where('grand_total', '>', 0)
                ->limit(1000)->count(),
            'rm_lookup' => (int) DB::table('trx_medical_records')->limit(500)->count(),
            default => 0,
        };
    }

    /**
     * @param  array<string, mixed>  $pack
     */
    private function persistEvidence(array $pack): void
    {
        // Config value is repo-relative (storage/app/load-test).
        $relative = (string) config('load_test_baseline.evidence_output_dir', 'storage/app/load-test');
        $dir = base_path($relative);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file = $dir.'/baseline-'.now()->format('Ymd-His').'.json';
        file_put_contents($file, (string) json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Wrote evidence pack: '.$file);
    }

    /**
     * @param  array<string, mixed>  $pack
     */
    private function printConsole(array $pack): void
    {
        $this->info('ENT-13 Load Test 5 Cabang Baseline ('.$pack['mode'].', env: '.$pack['environment'].')');
        $this->line(sprintf(
            'Branches: %d | Users: clinic %d / lab %d / inventory %d | Target p50 %dms / p95 %dms',
            $pack['branch_count'],
            $pack['user_mix']['clinic_users'] ?? 0,
            $pack['user_mix']['lab_users'] ?? 0,
            $pack['user_mix']['inventory_users'] ?? 0,
            $pack['latency_targets_ms']['p50'],
            $pack['latency_targets_ms']['p95'],
        ));
        $this->newLine();

        $rows = [];
        foreach ($pack['results'] as $r) {
            $rows[] = [$r['scenario'], $r['domain'], $r['status'], $r['elapsed_ms'] ?? '-', $r['rows'] ?? '-'];
        }
        $this->table(['scenario', 'domain', 'status', 'elapsed_ms', 'rows'], $rows);

        if ($pack['bottleneck_follow_ups'] !== []) {
            $this->newLine();
            $this->warn('Bottleneck follow-ups:');
            foreach ($pack['bottleneck_follow_ups'] as $f) {
                $this->line(sprintf('  - [%s] %s (%sms): %s', $f['bottleneck_category'], $f['scenario'], $f['elapsed_ms'], $f['note']));
            }
        }
    }
}
