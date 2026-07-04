<?php

namespace App\Console\Commands;

use App\Services\Inventory\MissingBatchBackfillService;
use Illuminate\Console\Command;

class InventoryBackfillMissingBatchesCommand extends Command
{
    protected $signature = 'inventory:backfill-missing-batches
        {--dry-run : Preview only — default unless --execute is passed}
        {--execute : Apply backfill mutations (requires explicit flag)}
        {--limit= : Limit scanned movements}
        {--movement-id= : Target a single movement id}
        {--export= : Write result JSON under storage/app/architecture}
        {--json : Output JSON summary}
        {--fail-on-ambiguous : Fail when ambiguous movements are encountered during execute}
        {--no-legacy-placeholder : Do not classify unresolved rows as legacy placeholder candidates}';

    protected $description = 'DQ-2 dry-run-first backfill for missing inventory_batch_id on batch-tracked movements.';

    public function handle(MissingBatchBackfillService $service): int
    {
        $execute = (bool) $this->option('execute');

        if ($execute) {
            if (! $this->option('json')) {
                $this->warn('EXECUTE MODE: This will mutate inventory_batch_id and may create governance placeholder batches.');
                $this->warn('Ensure a database backup exists before proceeding.');
            }
        }

        $options = [
            'execute' => $execute,
            'limit' => $this->option('limit'),
            'movement_id' => $this->option('movement-id'),
            'fail_on_ambiguous' => (bool) $this->option('fail-on-ambiguous'),
            'no_legacy_placeholder' => (bool) $this->option('no-legacy-placeholder'),
        ];

        $summary = $service->run($options);

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printConsole($summary);
        }

        $exportPath = $this->resolveExportPath();
        if ($this->option('export') && $exportPath === null) {
            return 10;
        }

        if ($exportPath !== null) {
            file_put_contents($exportPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('Export written to: '.$exportPath);
        }

        if ($execute && ($summary['errors'] ?? 0) > 0) {
            return self::FAILURE;
        }

        if ($execute && ($summary['ambiguous_skipped'] ?? 0) > 0 && $this->option('fail-on-ambiguous')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function printConsole(array $summary): void
    {
        $this->info('DQ-2 Missing Batch Backfill — '.strtoupper((string) ($summary['mode'] ?? 'dry-run')));
        $this->table(
            ['Metric', 'Count'],
            [
                ['Scanned', $summary['scanned'] ?? 0],
                ['Skipped already linked', $summary['skipped_already_linked'] ?? 0],
                ['Linked existing batch', $summary['linked_existing_batch'] ?? 0],
                ['Created batch from source', $summary['created_batch'] ?? 0],
                ['Legacy placeholder batch', $summary['legacy_placeholder_batch'] ?? 0],
                ['Ambiguous skipped', $summary['ambiguous_skipped'] ?? 0],
                ['Errors', $summary['errors'] ?? 0],
            ],
        );
    }

    private function resolveExportPath(): ?string
    {
        $raw = $this->option('export');

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

        $parent = dirname($candidate);
        if (! is_dir($parent)) {
            mkdir($parent, 0755, true);
        }

        $normalizedRoot = rtrim($architectureRoot, DIRECTORY_SEPARATOR);
        $realParent = realpath($parent) ?: $parent;
        $normalizedParent = rtrim((string) $realParent, DIRECTORY_SEPARATOR);

        if ($normalizedParent !== $normalizedRoot && ! str_starts_with($normalizedParent.'/', $normalizedRoot.'/')) {
            $this->error('Export must be under storage/app/architecture.');

            return null;
        }

        return $candidate;
    }
}
