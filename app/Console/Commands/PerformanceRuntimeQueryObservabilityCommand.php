<?php

namespace App\Console\Commands;

use App\Services\Monitoring\RuntimeQueryObservabilityService;
use Illuminate\Console\Command;

class PerformanceRuntimeQueryObservabilityCommand extends Command
{
    private const ALLOWED_ENVIRONMENTS = ['local', 'pilot', 'stress', 'testing'];

    private const UNSAFE_EXIT = 10;

    protected $signature = 'performance:runtime-query-observability
        {--limit=20 : Maximum number of queries to return (max 100)}
        {--min-calls=1 : Minimum call count filter}
        {--sort=total_time : Sort by total_time, mean_time, or calls}
        {--json : Output JSON report}
        {--output= : Write report under storage/app/performance only}
        {--reset-baseline : Reset pg_stat_statements after report (pgsql only)}
        {--force-production : Allow production environment explicitly}';

    protected $description = 'Privacy-safe runtime PostgreSQL query observability via pg_stat_statements.';

    public function handle(RuntimeQueryObservabilityService $service): int
    {
        $guard = $this->guardEnvironment();
        if ($guard !== null) {
            $this->error($guard);

            return self::UNSAFE_EXIT;
        }

        $options = [
            'limit' => (int) $this->option('limit'),
            'min_calls' => (int) $this->option('min-calls'),
            'sort' => (string) $this->option('sort'),
            'reset_baseline' => (bool) $this->option('reset-baseline'),
        ];

        $validation = $service->validateOptions($options);
        if (! $validation['valid']) {
            $this->error($validation['error'] ?? 'Invalid options.');

            return self::FAILURE;
        }

        $report = $service->collect($options);

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

        return self::SUCCESS;
    }

    private function guardEnvironment(): ?string
    {
        $environment = (string) config('app.env');

        if ($environment === 'production' && ! $this->option('force-production')) {
            return 'Refusing to run in production without --force-production.';
        }

        if (! in_array($environment, self::ALLOWED_ENVIRONMENTS, true) && $environment !== 'production') {
            return "Environment [{$environment}] is not in the allowed list.";
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
            $candidate = ltrim($candidate, '/');
            $performancePrefix = 'storage/app/performance/';
            if (str_starts_with($candidate, $performancePrefix)) {
                $candidate = substr($candidate, strlen($performancePrefix));
            }
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
        $this->info('Runtime Query Observability (privacy-safe)');
        $this->line('Captured at: '.$report['captured_at']);
        $this->line('Environment: '.$report['environment']);
        $this->newLine();

        $pg = $report['pg_stat_statements'] ?? [];
        $this->table(
            ['Key', 'Value'],
            [
                ['Driver', $report['metadata']['database_driver'] ?? '-'],
                ['Available', ($pg['available'] ?? false) ? 'yes' : 'no'],
                ['Extension', ($pg['extension_installed'] ?? false) ? 'yes' : 'no'],
                ['Version', $pg['extension_version'] ?? 'n/a'],
                ['Preloaded', ($pg['preloaded'] ?? false) ? 'yes' : 'no'],
                ['Rows inspected', (string) ($report['total_rows_inspected'] ?? 0)],
            ],
        );

        if (! ($pg['available'] ?? false) && ! empty($pg['setup_instructions'])) {
            $this->newLine();
            $this->warn('Setup: '.$pg['setup_instructions']);
        }

        if (! empty($report['queries'])) {
            $this->newLine();
            $this->info('Top queries (sanitized)');
            $rows = [];
            foreach ($report['queries'] as $q) {
                $rows[] = [
                    $q['module_guess'] ?? '-',
                    (string) ($q['calls'] ?? 0),
                    (string) ($q['mean_time_ms'] ?? 0),
                    (string) ($q['total_time_ms'] ?? 0),
                    $q['risk_hint'] ?? '-',
                    mb_substr((string) ($q['query_summary'] ?? ''), 0, 60),
                ];
            }
            $this->table(['Module', 'Calls', 'Mean ms', 'Total ms', 'Risk', 'Summary'], $rows);
        }

        if (! empty($report['warnings'])) {
            $this->newLine();
            $this->warn('Warnings: '.count($report['warnings']));
            foreach ($report['warnings'] as $warning) {
                $this->line('- '.$warning);
            }
        }
    }

    private function writeOutputFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $contents);
        $this->info("Wrote report to {$path}");
    }
}
