<?php

namespace App\Console\Commands;

use App\Services\Inventory\AmbiguousBatchReviewPackService;
use Illuminate\Console\Command;

class InventoryAmbiguousBatchReviewPackCommand extends Command
{
    protected $signature = 'inventory:ambiguous-batch-review-pack
        {--json : Output JSON report}
        {--format= : Output format: csv or json (default console/json)}
        {--output= : Write report to path (storage/app/reports/dq31 or absolute)}
        {--source=all : Scope: transfer, opname, all}
        {--item-id= : Target a single source item id}';

    protected $description = 'Read-only DQ-3.1 review pack for ambiguous transfer/opname batch rows.';

    public function handle(AmbiguousBatchReviewPackService $service): int
    {
        $report = $service->generate([
            'source' => $this->option('source') ?: 'all',
            'item_id' => $this->option('item-id'),
        ]);

        $format = strtolower((string) ($this->option('format') ?: ($this->option('json') ? 'json' : 'console')));

        if ($format === 'json' || $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printConsole($report);
        }

        $outputPath = $this->resolveOutputPath($format);
        if ($this->option('output') && $outputPath === null) {
            return self::FAILURE;
        }

        if ($outputPath !== null) {
            $this->writeOutput($outputPath, $report, $format);
            $this->info('Review pack written to: '.$outputPath);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $s = $report['summary'];
        $this->info('DQ-3.1 Ambiguous Batch Review Pack');
        $this->line('Generated: '.$report['generated_at']);
        $this->line(sprintf(
            'Transfer: %d | Opname: %d | Total: %d | Candidates: %d | Decision: %s',
            $s['transfer_ambiguous_count'],
            $s['opname_ambiguous_count'],
            $s['total_ambiguous_count'],
            $s['candidate_batch_count'],
            $s['decision'],
        ));
        $this->line('Mapping template: '.$s['mapping_template_csv']);

        if (($s['total_ambiguous_count'] ?? 0) === 0) {
            $this->info('No ambiguous transfer/opname rows — GO.');

            return;
        }

        $rows = [];
        foreach ($report['rows'] as $row) {
            $rows[] = [
                $row['source_type'],
                $row['source_item_id'],
                $row['product_id'],
                $row['ambiguity_reason'],
                implode(',', $row['candidate_inventory_batch_ids'] ?? []),
            ];
        }

        $this->table(['Type', 'Item ID', 'Product', 'Reason', 'Candidate Batches'], $rows);
    }

    private function resolveOutputPath(string $format): ?string
    {
        $raw = $this->option('output');
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

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeOutput(string $path, array $report, string $format): void
    {
        if ($format === 'csv') {
            $handle = fopen($path, 'w');
            fputcsv($handle, [
                'source_type', 'source_item_id', 'source_document_id', 'source_document_code',
                'product_id', 'product_code', 'branch_id', 'quantity',
                'current_inventory_batch_id', 'ambiguity_reason',
                'candidate_movement_ids', 'candidate_inventory_batch_ids', 'recommended_action',
            ]);
            foreach ($report['rows'] as $row) {
                fputcsv($handle, [
                    $row['source_type'],
                    $row['source_item_id'],
                    $row['source_document_id'],
                    $row['source_document_code'],
                    $row['product_id'],
                    $row['product_code'],
                    $row['branch_id'],
                    $row['quantity'],
                    $row['current_inventory_batch_id'],
                    $row['ambiguity_reason'],
                    implode('|', $row['candidate_movement_ids'] ?? []),
                    implode('|', $row['candidate_inventory_batch_ids'] ?? []),
                    $row['recommended_action'],
                ]);
            }
            fclose($handle);

            return;
        }

        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
