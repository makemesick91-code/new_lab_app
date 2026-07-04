<?php

namespace App\Console\Commands;

use App\Services\Architecture\CanonicalMetricReconciliationService;
use App\Services\Architecture\CanonicalMetricRegistry;
use Illuminate\Console\Command;

class ArchitectureCanonicalMetricReconciliationCommand extends Command
{
    private const UNSAFE_EXIT = 10;

    protected $signature = 'architecture:canonical-metric-reconciliation
        {--json : Output JSON report}
        {--output= : Write report under storage/app/architecture only}
        {--domain=all : Filter domain: rme|cashier|inventory|lab|owner|system|all}
        {--include-consumers : Include consumer routes/services (default true)}
        {--no-consumers : Omit consumer details}
        {--include-entity-reference : Cross-reference NSF-4 entity registry}';

    protected $description = 'Read-only canonical KPI/metric reconciliation inventory for DMO bridge (NSF-5).';

    public function handle(CanonicalMetricReconciliationService $service): int
    {
        $domain = (string) $this->option('domain');
        $allowed = array_merge(['all'], CanonicalMetricRegistry::domains());

        if (! in_array($domain, $allowed, true)) {
            $this->error("Invalid domain [{$domain}].");

            return self::FAILURE;
        }

        $options = [
            'domain' => $domain,
            'include_consumers' => ! $this->option('no-consumers'),
            'include_entity_reference' => (bool) $this->option('include-entity-reference'),
        ];

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

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $summary = $report['summary'];
        $this->info('Canonical Metric Reconciliation (NSF-5)');
        $this->line('Generated: '.$report['generated_at']);
        $this->line(sprintf(
            'Metrics: %d | Domains: %d | DMO ready: %d | needs review: %d | duplicate: %d | gaps: %d',
            $summary['metrics'],
            $summary['domains'],
            $summary['dmo_ready'],
            $summary['needs_review'],
            $summary['duplicate'],
            $summary['gap_count'],
        ));
        $this->newLine();

        foreach ($report['metrics'] as $metric) {
            $tables = implode(',', $metric['source_tables']) ?: '—';
            $sens = implode(',', $metric['sensitivity']);
            $this->line(sprintf(
                '- %s [%s] type=%s grain=%s tables=%s sens=%s dmo=%s',
                $metric['canonical_metric_name'],
                $metric['domain'],
                $metric['source_type'],
                $metric['grain'],
                $tables,
                $sens,
                $metric['dmo_readiness'],
            ));
        }
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
                mkdir($parent, 0775, true);
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

    private function writeOutputFile(string $path, string $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $payload);
        $this->info('Wrote: '.$path);
    }
}
