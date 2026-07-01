<?php

namespace App\Console\Commands;

use App\Services\Monitoring\PilotPerformanceSnapshotClassifier;
use App\Services\Monitoring\PilotPerformanceSnapshotLogAnalyzer;
use App\Services\Monitoring\PilotPerformanceSnapshotService;
use Illuminate\Console\Command;

class PilotPerformanceSnapshotCommand extends Command
{
    private const ALLOWED_ENVIRONMENTS = ['local', 'pilot', 'stress', 'testing'];

    private const UNSAFE_EXIT = 10;

    protected $signature = 'pilot:performance-snapshot
        {--json : Output JSON}
        {--markdown : Output Markdown}
        {--output= : Optional output file path under storage/app/monitoring only}
        {--since=24h : Log lookback window for fresh error classification}
        {--no-db : Skip database checks}
        {--no-http : Skip basic HTTP checks}
        {--fail-on-watch : Return non-zero exit code for WATCH or worse}
        {--force-production : Allow production environment explicitly}';

    protected $description = 'Collect a privacy-safe, read-only pilot performance snapshot with OK/WATCH/INVESTIGATE/FIX classification.';

    public function handle(PilotPerformanceSnapshotService $service): int
    {
        $guard = $this->guardEnvironment();

        if ($guard !== null) {
            $this->error($guard);

            return self::UNSAFE_EXIT;
        }

        $since = (string) $this->option('since');

        if (PilotPerformanceSnapshotLogAnalyzer::parseSinceDuration($since) === null) {
            $this->error("Invalid --since duration [{$since}]. Supported examples: 1h, 6h, 12h, 24h, 48h, 7d.");

            return self::UNSAFE_EXIT;
        }

        $snapshot = $service->collect([
            'skip_db' => (bool) $this->option('no-db'),
            'skip_http' => (bool) $this->option('no-http'),
            'since' => $since,
        ]);

        if ($this->option('output') !== null && $this->option('output') !== '') {
            $outputPath = $this->resolveOutputPath();
            if ($outputPath === null) {
                return self::UNSAFE_EXIT;
            }
        } else {
            $outputPath = null;
        }

        if ($this->option('json')) {
            $payload = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->line($payload);

            if ($outputPath !== null) {
                $this->writeOutputFile($outputPath, $payload);
            }
        } elseif ($this->option('markdown')) {
            $markdown = $this->formatMarkdown($snapshot);
            $this->line($markdown);

            if ($outputPath !== null) {
                $this->writeOutputFile($outputPath, $markdown);
            }
        } else {
            $this->printConsole($snapshot);

            if ($outputPath !== null) {
                $encoded = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $this->writeOutputFile($outputPath, $encoded);
            }
        }

        return $this->resolveExitCode($snapshot['overall_status']);
    }

    private function guardEnvironment(): ?string
    {
        $environment = app()->environment();

        if (in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
            return null;
        }

        if ($environment === 'production' && $this->option('force-production')) {
            $this->warn('Running in production with --force-production. Read-only checks only.');

            return null;
        }

        if ($environment === 'production') {
            return 'Refusing to run in production without --force-production.';
        }

        return "Refusing to run in unknown environment [{$environment}]. Allowed: ".implode(', ', self::ALLOWED_ENVIRONMENTS).' or production with --force-production.';
    }

    private function resolveOutputPath(): ?string
    {
        $raw = $this->option('output');

        if ($raw === null || $raw === '') {
            return null;
        }

        $monitoringRoot = realpath(storage_path('app/monitoring')) ?: storage_path('app/monitoring');

        if (! is_dir($monitoringRoot)) {
            mkdir($monitoringRoot, 0775, true);
            $monitoringRoot = realpath($monitoringRoot) ?: $monitoringRoot;
        }

        $candidate = $raw;

        if (! str_starts_with($candidate, '/')) {
            $candidate = $monitoringRoot.'/'.ltrim($candidate, '/');
        }

        $realCandidate = realpath(dirname($candidate));

        if ($realCandidate === false) {
            $parent = dirname($candidate);
            if (! is_dir($parent)) {
                mkdir($parent, 0775, true);
            }
            $realCandidate = realpath($parent);
        }

        $normalizedRoot = rtrim($monitoringRoot, DIRECTORY_SEPARATOR);
        $normalizedCandidate = rtrim((string) $realCandidate, DIRECTORY_SEPARATOR);

        if ($normalizedCandidate !== $normalizedRoot && ! str_starts_with($normalizedCandidate.'/', $normalizedRoot.'/')) {
            $this->error('Output path must be under storage/app/monitoring.');

            return null;
        }

        return $candidate;
    }

    private function writeOutputFile(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
        $this->info("Wrote snapshot to {$path}");
    }

    /**
     * @param  array{
     *   checked_at:string,
     *   environment:string,
     *   overall_status:string,
     *   sections:array<string, array{status:string, metrics?:array<string, mixed>}>,
     *   warnings:list<string>,
     *   skipped_checks:list<string>
     * }  $snapshot
     */
    private function printConsole(array $snapshot): void
    {
        $this->info('Pilot Performance Snapshot');
        $this->line('Checked at: '.$snapshot['checked_at']);
        $this->line('Environment: '.$snapshot['environment']);
        $this->line('Overall status: '.$snapshot['overall_status']);
        $this->newLine();

        foreach ($snapshot['sections'] as $name => $section) {
            $label = ucfirst(str_replace('_', ' ', $name));
            $this->line(sprintf('%s: %s', $label, $section['status']));
        }

        $logs = $snapshot['sections']['logs'] ?? null;

        if (is_array($logs)) {
            $logMetrics = $logs['metrics'] ?? [];
            $lookback = $logMetrics['lookback_window'] ?? 'n/a';
            $fresh = $logMetrics['fresh_error_like_count'] ?? 'n/a';
            $historical = $logMetrics['historical_tail_error_like_count'] ?? 'n/a';
            $freshStack = $logMetrics['fresh_stack_trace_line_count'] ?? 0;
            $historicalStack = $logMetrics['historical_stack_trace_line_count'] ?? 0;
            $orphan = $logMetrics['orphan_unparseable_error_like_count'] ?? ($logMetrics['unparseable_error_like_count'] ?? 'n/a');
            $this->newLine();
            $this->line(sprintf('Fresh error events: %s in last %s', (string) $fresh, (string) $lookback));
            $this->line(sprintf('Historical error events: %s', (string) $historical));

            if ((int) $freshStack > 0) {
                $this->line(sprintf('Fresh stack trace continuation lines: %s', (string) $freshStack));
            }

            if ((int) $historicalStack > 0) {
                $this->line(sprintf('Historical stack trace continuation lines: %s', (string) $historicalStack));
            }

            $this->line(sprintf('Orphan unparseable error-like lines: %s', (string) $orphan));

            if (isset($logs['reason']) && is_string($logs['reason'])) {
                $this->line('Reason: '.$logs['reason']);
            }
        }

        $db = $snapshot['sections']['database']['metrics'] ?? [];
        $this->newLine();
        $this->line('Summary');
        $this->table(
            ['Metric', 'Value'],
            array_filter([
                ['DB size (MB)', $db['size_mb'] ?? 'n/a'],
                ['Patients', $db['patients'] ?? 'n/a'],
                ['Visits', $db['visits'] ?? 'n/a'],
                ['Payments', $db['payments'] ?? 'n/a'],
                ['Q5 (ms)', isset($db['q5_ms']) ? (string) $db['q5_ms'] : 'n/a'],
                ['Q6 (ms)', isset($db['q6_ms']) ? (string) $db['q6_ms'] : 'n/a'],
            ]),
        );

        if ($snapshot['warnings'] !== []) {
            $this->newLine();
            $this->warn('Warnings:');
            foreach ($snapshot['warnings'] as $warning) {
                $this->line('- '.$warning);
            }
        }

        if ($snapshot['skipped_checks'] !== []) {
            $this->line('Skipped: '.implode(', ', $snapshot['skipped_checks']));
        }
    }

    /**
     * @param  array{
     *   checked_at:string,
     *   environment:string,
     *   overall_status:string,
     *   sections:array<string, array{status:string, metrics?:array<string, mixed>}>
     * }  $snapshot
     */
    private function formatMarkdown(array $snapshot): string
    {
        $date = substr($snapshot['checked_at'], 0, 10);
        $lines = [
            "# Pilot Performance Snapshot — {$date}",
            '',
            '| Field | Value |',
            '|---|---|',
            '| Checked at | '.$snapshot['checked_at'].' |',
            '| Environment | '.$snapshot['environment'].' |',
            '| Overall status | '.$snapshot['overall_status'].' |',
            '',
            '## Sections',
            '',
            '| Area | Status | Notes |',
            '|---|---|---|',
        ];

        foreach ($snapshot['sections'] as $name => $section) {
            $detail = '-';

            if ($name === 'logs') {
                $metrics = $section['metrics'] ?? [];
                $fresh = $metrics['fresh_error_like_count'] ?? 'n/a';
                $historical = $metrics['historical_tail_error_like_count'] ?? 'n/a';
                $lookback = $metrics['lookback_window'] ?? 'n/a';
                $historicalStack = $metrics['historical_stack_trace_line_count'] ?? 0;
                $orphan = $metrics['orphan_unparseable_error_like_count'] ?? ($metrics['unparseable_error_like_count'] ?? 'n/a');
                $detail = sprintf(
                    'fresh=%s, historical=%s, historical_stack_lines=%s, orphan_unparseable=%s, lookback=%s',
                    (string) $fresh,
                    (string) $historical,
                    (string) $historicalStack,
                    (string) $orphan,
                    (string) $lookback,
                );
            }

            $lines[] = '| '.ucfirst($name).' | '.$section['status'].' | '.$detail.' |';
        }

        $db = $snapshot['sections']['database']['metrics'] ?? [];
        $lines[] = '';
        $lines[] = '## Database Metrics';
        $lines[] = '';
        $lines[] = '| Metric | Value |';
        $lines[] = '|---|---:|';

        foreach (['size_mb' => 'DB size (MB)', 'patients' => 'Patients', 'visits' => 'Visits', 'payments' => 'Payments', 'q5_ms' => 'Q5 (ms)', 'q6_ms' => 'Q6 (ms)'] as $key => $label) {
            if (array_key_exists($key, $db)) {
                $value = $db[$key] === null ? 'n/a' : (string) $db[$key];
                $lines[] = "| {$label} | {$value} |";
            }
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function resolveExitCode(string $overallStatus): int
    {
        if (! $this->option('fail-on-watch')) {
            return self::SUCCESS;
        }

        return PilotPerformanceSnapshotClassifier::exitCodeForStatus($overallStatus);
    }
}
