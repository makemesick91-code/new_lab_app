<?php

namespace App\Console\Commands;

use App\Services\Inventory\SourceDocumentBatchBackfillService;
use Illuminate\Console\Command;

class InventoryBackfillSourceDocumentBatchesCommand extends Command
{
    protected $signature = 'inventory:backfill-source-document-batches
        {--dry-run : Preview only — default unless --execute is passed}
        {--execute : Apply backfill mutations (requires explicit flag)}
        {--limit= : Limit scanned source items}
        {--source= : Scope: goods-receipt, transfer, opname, all}
        {--item-id= : Target a single source item id (requires --source)}
        {--export= : Write result JSON under storage/app/architecture}
        {--json : Output JSON summary}
        {--fail-on-ambiguous : Fail when ambiguous items are encountered during execute}
        {--no-legacy-placeholder : Do not link legacy placeholder batches from movements}';

    protected $description = 'DQ-3 dry-run-first backfill for missing inventory_batch_id on source-document items.';

    public function handle(SourceDocumentBatchBackfillService $service): int
    {
        $execute = (bool) $this->option('execute');

        if ($execute && ! $this->option('json')) {
            $this->warn('EXECUTE MODE: This will set inventory_batch_id on source-document items only.');
            $this->warn('Ensure a database backup exists before proceeding.');
        }

        $options = [
            'execute' => $execute,
            'limit' => $this->option('limit'),
            'source' => $this->option('source') ?: 'all',
            'item_id' => $this->option('item-id'),
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
        $this->info('DQ-3 Source Document Batch Backfill — '.strtoupper((string) ($summary['mode'] ?? 'dry-run')));
        $this->table(
            ['Metric', 'Count'],
            [
                ['Scanned', $summary['scanned'] ?? 0],
                ['Skipped already linked', $summary['skipped_already_linked'] ?? 0],
                ['Linked from movement', $summary['linked_from_movement'] ?? 0],
                ['Linked from source batch fields', $summary['linked_from_source_batch_fields'] ?? 0],
                ['Legacy placeholder from movement', $summary['linked_legacy_placeholder_from_movement'] ?? 0],
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
