<?php

namespace App\Console\Commands;

use App\Services\Inventory\AmbiguousBatchRepairService;
use App\Services\Inventory\AmbiguousBatchReviewPackService;
use Illuminate\Console\Command;

class InventoryRepairAmbiguousBatchLinksCommand extends Command
{
    protected $signature = 'inventory:repair-ambiguous-batch-links
        {--mapping= : Required path to approved CSV or JSON mapping file}
        {--dry-run : Preview repairs without mutation (default)}
        {--execute : Apply approved repairs (requires explicit flag)}
        {--json : Output JSON summary}
        {--source=all : Scope validation: transfer, opname, all}
        {--item-id= : Target a single source item id in mapping validation}
        {--fail-on-ambiguous : Exit non-zero when ambiguous rows remain after execute}
        {--strict : Reject any validation error (default true)}
        {--export= : Write result JSON under storage/app/reports/dq31}
        {--allow-overwrite : Allow replacing existing inventory_batch_id when validated}';

    protected $description = 'DQ-3.1 approved mapping validator and dry-run-first repair for ambiguous batch rows.';

    public function handle(AmbiguousBatchRepairService $service): int
    {
        $mapping = (string) ($this->option('mapping') ?: '');
        if ($mapping === '') {
            $this->error('--mapping is required.');

            return self::FAILURE;
        }

        if (! is_file($mapping)) {
            $this->error("Mapping file not found: {$mapping}");

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');

        if ($execute && ! $this->option('json')) {
            $this->warn('EXECUTE MODE: Updates source-document item inventory_batch_id only.');
            $this->warn('Ensure database backup exists and dry-run passed.');
        }

        try {
            $summary = $service->run($mapping, [
                'execute' => $execute,
                'source' => $this->option('source') ?: 'all',
                'item_id' => $this->option('item-id'),
                'allow_overwrite' => (bool) $this->option('allow-overwrite'),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printConsole($summary);
        }

        $exportPath = $this->resolveExportPath();
        if ($this->option('export') && $exportPath !== null) {
            file_put_contents($exportPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('Export written to: '.$exportPath);
        }

        if (! ($summary['validation']['valid'] ?? false)) {
            return self::FAILURE;
        }

        if ($execute && ($summary['errors'] ?? 0) > 0) {
            return self::FAILURE;
        }

        if ($execute && $this->option('fail-on-ambiguous')) {
            $dq31 = app(AmbiguousBatchReviewPackService::class)->generate();
            if (($dq31['summary']['total_ambiguous_count'] ?? 0) > 0) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function printConsole(array $summary): void
    {
        $this->info('DQ-3.1 Ambiguous Batch Repair — '.strtoupper((string) ($summary['mode'] ?? 'dry-run')));
        $this->line('Mapping: '.($summary['mapping_path'] ?? ''));

        if (! ($summary['validation']['valid'] ?? false)) {
            $this->error('Validation FAILED:');
            foreach ($summary['validation']['errors'] ?? [] as $error) {
                $this->line(' - '.$error);
            }

            return;
        }

        $this->info('Validation PASSED.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Rows submitted', $summary['rows_submitted'] ?? 0],
                ['Applied', $summary['applied'] ?? 0],
                ['Skipped', $summary['skipped'] ?? 0],
                ['Errors', $summary['errors'] ?? 0],
            ],
        );

        if (! empty($summary['post_checks'])) {
            $pc = $summary['post_checks'];
            $this->line(sprintf(
                'Post-checks: DQ-1=%s DQ-2=%s DQ-3=%s DQ-3.1=%s',
                $pc['dq1_decision'] ?? '?',
                $pc['dq2_decision'] ?? '?',
                $pc['dq3_decision'] ?? '?',
                $pc['dq31_decision'] ?? '?',
            ));
        }
    }

    private function resolveExportPath(): ?string
    {
        $raw = $this->option('export');
        if ($raw === null || $raw === '') {
            return null;
        }

        $candidate = (string) $raw;
        if (! str_starts_with($candidate, '/')) {
            $normalized = ltrim($candidate, '/');
            if (str_starts_with($normalized, 'storage/app/')) {
                $candidate = base_path($normalized);
            } else {
                $candidate = storage_path('app/reports/dq31/'.$normalized);
            }
        }

        $parent = dirname($candidate);
        if (! is_dir($parent)) {
            mkdir($parent, 0775, true);
        }

        return $candidate;
    }
}
