<?php

namespace App\Console\Commands;

use App\Services\Architecture\CanonicalEntityInventoryService;
use App\Services\Architecture\CanonicalEntityRegistry;
use Illuminate\Console\Command;

class ArchitectureCanonicalEntityInventoryCommand extends Command
{
    private const UNSAFE_EXIT = 10;

    protected $signature = 'architecture:canonical-entity-inventory
        {--json : Output JSON report}
        {--output= : Write report under storage/app/architecture only}
        {--domain=all : Filter domain: foundation|rme|cashier|inventory|lab|owner|telemetry|all}
        {--include-schema : Include table existence checks (default true)}
        {--no-schema : Skip schema checks}
        {--include-routes : Include route count hints per domain}
        {--include-workflows : Include workflow inventory (default true)}
        {--no-workflows : Omit workflows}';

    protected $description = 'Read-only canonical entity and workflow inventory for DMO bridge (NSF-4).';

    public function handle(CanonicalEntityInventoryService $service): int
    {
        $domain = (string) $this->option('domain');
        $allowed = array_merge(['all'], CanonicalEntityRegistry::domains());

        if (! in_array($domain, $allowed, true)) {
            $this->error("Invalid domain [{$domain}].");

            return self::FAILURE;
        }

        $options = [
            'domain' => $domain,
            'include_schema' => ! $this->option('no-schema'),
            'include_routes' => (bool) $this->option('include-routes'),
            'include_workflows' => ! $this->option('no-workflows'),
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
        $this->info('Canonical Entity & Workflow Inventory (NSF-4)');
        $this->line('Generated: '.$report['generated_at']);
        $this->line('Entities: '.$summary['entity_count'].' | Workflows: '.$summary['workflow_count'].' | Gaps: '.$summary['gap_count']);
        $this->line('DMO ready: '.$summary['dmo_ready_count'].' | needs review: '.$summary['dmo_needs_review_count']);
        $this->newLine();

        foreach ($report['entities'] as $entity) {
            $table = $entity['primary_table'] ?? '—';
            $exists = isset($entity['table_exists']) ? ($entity['table_exists'] ? 'yes' : 'no') : 'n/a';
            $sens = implode(',', $entity['sensitivity']);
            $this->line(sprintf(
                '- %s [%s] table=%s exists=%s scope=%s sens=%s',
                $entity['canonical_name'],
                $entity['domain'],
                $table,
                $exists,
                $entity['scope'],
                $sens
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
