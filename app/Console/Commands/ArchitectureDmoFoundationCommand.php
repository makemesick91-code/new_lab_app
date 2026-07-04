<?php

namespace App\Console\Commands;

use App\Services\Architecture\DmoFoundationService;
use Illuminate\Console\Command;

class ArchitectureDmoFoundationCommand extends Command
{
    private const UNSAFE_EXIT = 10;

    /** @var list<string> */
    private const DOMAINS = ['all', 'foundation', 'rme', 'cashier', 'inventory', 'lab', 'owner', 'system'];

    protected $signature = 'architecture:dmo-foundation
        {--json : Output JSON report}
        {--output= : Write report under storage/app/architecture only}
        {--domain=all : Filter domain: foundation|rme|cashier|inventory|lab|owner|system|all}
        {--include-lineage : Include entity-metric-consumer lineage (default true)}
        {--no-lineage : Omit lineage}
        {--include-backlog : Include DMO backlog (default true)}
        {--no-backlog : Omit backlog}
        {--include-references : Include NSF-4/NSF-5 summary cross-references}';

    protected $description = 'Read-only DMO-1 foundation report consolidating NSF-4 entities and NSF-5 metrics.';

    public function handle(DmoFoundationService $service): int
    {
        $domain = (string) $this->option('domain');

        if (! in_array($domain, self::DOMAINS, true)) {
            $this->error("Invalid domain [{$domain}].");

            return self::FAILURE;
        }

        $options = [
            'domain' => $domain,
            'include_lineage' => ! $this->option('no-lineage'),
            'include_backlog' => ! $this->option('no-backlog'),
            'include_references' => (bool) $this->option('include-references'),
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
        $s = $report['summary'];
        $this->info('DMO-1 Foundation Report');
        $this->line('Generated: '.$report['generated_at']);
        $this->line(sprintf(
            'Entities: %d | Metrics: %d | Workflows: %d | Relationships: %d | Dimensions: %d',
            $s['entities'],
            $s['metrics'],
            $s['workflows'],
            $s['relationships'],
            $s['dimensions'],
        ));
        $this->line(sprintf(
            'DMO ready: entities=%d metrics=%d | Blocked metrics: %d | Backlog: %d | Decision: %s',
            $s['dmo_ready_entities'],
            $s['dmo_ready_metrics'],
            $s['blocked_metrics'],
            $s['backlog_items'],
            $report['readiness']['decision'],
        ));
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
