<?php

namespace App\Console\Commands;

use App\Services\Monitoring\PilotPerformanceSnapshotClassifier;
use App\Services\Monitoring\SlowQueryAuditService;
use Illuminate\Console\Command;

class SlowQueryAuditCommand extends Command
{
    private const ALLOWED_ENVIRONMENTS = ['local', 'pilot', 'stress', 'testing'];

    private const UNSAFE_EXIT = 10;

    protected $signature = 'performance:slow-query-audit
        {--json : Output JSON report}
        {--output= : Write report under storage/app/performance only}
        {--module=all : Limit audit module: rme,cashier,inventory,owner,all}
        {--skip-benchmarks : Skip EXPLAIN ANALYZE benchmarks}
        {--fail-on-watch : Non-zero exit when any benchmark is WATCH or worse}
        {--force-production : Allow production environment explicitly}';

    protected $description = 'Privacy-safe performance baseline and slow-query audit for RME, cashier, inventory, and owner flows.';

    public function handle(SlowQueryAuditService $service): int
    {
        $guard = $this->guardEnvironment();
        if ($guard !== null) {
            $this->error($guard);

            return self::UNSAFE_EXIT;
        }

        $report = $service->audit([
            'skip_benchmarks' => (bool) $this->option('skip-benchmarks'),
            'module' => (string) $this->option('module'),
        ]);

        $outputPath = $this->resolveOutputPath();
        if ($this->option('output') && $outputPath === null) {
            return self::UNSAFE_EXIT;
        }

        if ($this->option('json')) {
            $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->line($payload);

            if ($outputPath !== null) {
                $this->writeOutputFile($outputPath, $payload);
            }
        } else {
            $this->printConsole($report);

            if ($outputPath !== null) {
                $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $this->writeOutputFile($outputPath, $encoded);
            }
        }

        if ($this->option('fail-on-watch')) {
            $worst = $this->worstBenchmarkStatus($report);

            return PilotPerformanceSnapshotClassifier::exitCodeForStatus($worst);
        }

        return self::SUCCESS;
    }

    private function guardEnvironment(): ?string
    {
        $environment = (string) config('app.env');

        if ($environment === 'production' && ! $this->option('force-production')) {
            return 'Refusing to run in production without --force-production.';
        }

        if (! in_array($environment, self::ALLOWED_ENVIRONMENTS, true) && $environment !== 'production') {
            return "Environment [{$environment}] is not in the allowed audit list.";
        }

        return null;
    }

    private function resolveOutputPath(): ?string
    {
        $raw = $this->option('output');

        if ($raw === null || $raw === '') {
            return null;
        }

        $performanceRoot = realpath(storage_path('app/performance')) ?: storage_path('app/performance');

        if (! is_dir($performanceRoot)) {
            mkdir($performanceRoot, 0775, true);
            $performanceRoot = realpath($performanceRoot) ?: $performanceRoot;
        }

        $candidate = (string) $raw;

        if (! str_starts_with($candidate, '/')) {
            $candidate = $performanceRoot.'/'.ltrim($candidate, '/');
        }

        $realCandidate = realpath(dirname($candidate));

        if ($realCandidate === false) {
            $parent = dirname($candidate);
            if (! is_dir($parent)) {
                mkdir($parent, 0775, true);
            }
            $realCandidate = realpath($parent);
        }

        $normalizedRoot = rtrim($performanceRoot, DIRECTORY_SEPARATOR);
        $normalizedCandidate = rtrim((string) $realCandidate, DIRECTORY_SEPARATOR);

        if ($normalizedCandidate !== $normalizedRoot && ! str_starts_with($normalizedCandidate.'/', $normalizedRoot.'/')) {
            $this->error('Output path must be under storage/app/performance.');

            return null;
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $this->info('Performance Slow Query Audit (privacy-safe)');
        $this->line('Checked at: '.$report['audited_at']);
        $this->line('Environment: '.$report['environment']);
        $this->newLine();

        $meta = $report['metadata'] ?? [];
        $this->table(
            ['Key', 'Value'],
            collect($meta)->map(fn ($value, $key) => [$key, is_scalar($value) ? (string) $value : json_encode($value)])->values()->all(),
        );

        $this->newLine();
        $this->info('Module summary');
        $moduleRows = [];
        foreach ($report['modules'] ?? [] as $module => $stats) {
            $moduleRows[] = [$module, (string) ($stats['tables'] ?? 0), (string) ($stats['indexes_present'] ?? 0), (string) ($stats['indexes_missing'] ?? 0)];
        }
        $this->table(['Module', 'Tables', 'Indexes OK', 'Indexes Missing'], $moduleRows);

        if (! empty($report['benchmarks']) && ! isset($report['benchmarks']['skipped'])) {
            $this->newLine();
            $this->info('Benchmarks (EXPLAIN ANALYZE)');
            $benchRows = [];
            foreach ($report['benchmarks'] as $bench) {
                $benchRows[] = [
                    $bench['id'] ?? '-',
                    $bench['module'] ?? '-',
                    isset($bench['runtime_ms']) ? (string) $bench['runtime_ms'] : 'n/a',
                    $bench['status'] ?? '-',
                    ($bench['seq_scan_warning'] ?? false) ? 'yes' : 'no',
                ];
            }
            $this->table(['ID', 'Module', 'ms', 'Status', 'SeqScan?'], $benchRows);
        }

        if (! empty($report['deferred_index_recommendations'])) {
            $this->newLine();
            $this->warn('Deferred index recommendations (NSF-2+)');
            foreach ($report['deferred_index_recommendations'] as $rec) {
                $this->line('- '.($rec['recommended_index'] ?? '').': '.($rec['reason'] ?? ''));
            }
        }

        if (! empty($report['warnings'])) {
            $this->newLine();
            $this->warn('Warnings: '.count($report['warnings']));
        }
    }

    private function writeOutputFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $contents);
        $this->info("Wrote audit report to {$path}");
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function worstBenchmarkStatus(array $report): string
    {
        $statuses = ['OK'];

        foreach ($report['benchmarks'] ?? [] as $bench) {
            if (is_array($bench) && isset($bench['status'])) {
                $statuses[] = (string) $bench['status'];
            }
        }

        return PilotPerformanceSnapshotClassifier::worst(...$statuses);
    }
}
