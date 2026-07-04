<?php

namespace App\Console\Commands;

use App\Services\DataQuality\Dq1AuditService;
use Illuminate\Console\Command;

class DataQualityDq1AuditCommand extends Command
{
    protected $signature = 'data-quality:dq1-audit
        {--json : Output JSON report}
        {--output= : Write report under storage/app/architecture only}
        {--fail-on= : Exit non-zero when findings meet threshold: error, warning, any}';

    protected $description = 'Read-only DQ-1 ACID, constraint, and data quality audit (privacy-safe).';

    public function handle(Dq1AuditService $service): int
    {
        $report = $service->audit();

        $outputPath = $this->resolveOutputPath();
        if ($this->option('output') && $outputPath === null) {
            return 10;
        }

        $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($this->option('json')) {
            $this->line($payload);
        } else {
            $this->printConsole($report);
        }

        if ($outputPath !== null) {
            file_put_contents($outputPath, (string) $payload);
            $this->info('Report written to: '.$outputPath);
        }

        return $this->exitForFailOn($report);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $s = $report['summary'];
        $this->info('DQ-1 ACID, Constraint & Data Quality Audit');
        $this->line('Generated: '.$report['generated_at']);
        $this->line(sprintf(
            'Checks: %d | PASS: %d | WARN: %d | FAIL: %d | Decision: %s',
            $s['checks'],
            $s['passed'],
            $s['warnings'],
            $s['errors'],
            $s['decision'],
        ));
        $this->newLine();

        $rows = [];
        foreach ($report['checks'] as $check) {
            $rows[] = [
                $check['check_id'],
                $check['category'],
                $check['status'],
                $check['message'],
            ];
        }

        $this->table(['Check ID', 'Category', 'Status', 'Message'], $rows);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function exitForFailOn(array $report): int
    {
        $threshold = strtolower((string) ($this->option('fail-on') ?: ''));

        if ($threshold === '') {
            return self::SUCCESS;
        }

        $errors = (int) ($report['summary']['errors'] ?? 0);
        $warnings = (int) ($report['summary']['warnings'] ?? 0);

        return match ($threshold) {
            'error' => $errors > 0 ? self::FAILURE : self::SUCCESS,
            'warning', 'warn' => ($errors + $warnings) > 0 ? self::FAILURE : self::SUCCESS,
            'any' => ($errors + $warnings) > 0 ? self::FAILURE : self::SUCCESS,
            default => self::SUCCESS,
        };
    }

    private function resolveOutputPath(): ?string
    {
        $raw = $this->option('output');

        if ($raw === null || $raw === '') {
            return null;
        }

        $architectureRoot = realpath(storage_path('app/architecture')) ?: storage_path('app/architecture');

        if (! is_dir($architectureRoot)) {
            mkdir($architectureRoot, 0775, true);
            $architectureRoot = realpath($architectureRoot) ?: $architectureRoot;
        }

        $candidate = (string) $raw;

        if (! str_starts_with($candidate, '/')) {
            $candidate = ltrim($candidate, '/');
            $prefix = 'storage/app/architecture/';
            if (str_starts_with($candidate, $prefix)) {
                $candidate = substr($candidate, strlen($prefix));
            }
            $candidate = $architectureRoot.'/'.ltrim($candidate, '/');
        }

        $realCandidate = realpath(dirname($candidate));

        if ($realCandidate === false) {
            $parent = dirname($candidate);
            if (! is_dir($parent)) {
                mkdir($parent, 0755, true);
            }
            $realCandidate = realpath($parent);
        }

        $normalizedRoot = rtrim($architectureRoot, DIRECTORY_SEPARATOR);
        $normalizedCandidate = rtrim((string) $realCandidate, DIRECTORY_SEPARATOR);

        if ($normalizedCandidate !== $normalizedRoot && ! str_starts_with($normalizedCandidate.'/', $normalizedRoot.'/')) {
            $this->error('Output must be under storage/app/architecture.');

            return null;
        }

        return $candidate;
    }
}
