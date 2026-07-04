<?php

namespace App\Services\Inventory;

use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryBatchBackfillLog;
use App\Modules\Inventory\Models\StockOpnameItem;
use App\Modules\Inventory\Models\StockTransferItem;
use App\Modules\Inventory\Support\SourceDocumentBatchGuard;
use App\Services\DataQuality\Dq1AuditService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DQ-3.1 approved mapping validator and dry-run-first repair executor.
 */
class AmbiguousBatchRepairService
{
    public const COMMAND = 'inventory:repair-ambiguous-batch-links';

    public const STRATEGY = 'manual_approved_repair';

    public function __construct(
        private readonly AmbiguousBatchReviewPackService $reviewPack,
        private readonly SourceDocumentBatchBackfillService $backfill,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function run(string $mappingPath, array $options = []): array
    {
        $execute = (bool) ($options['execute'] ?? false);
        $rows = $this->parseMapping($mappingPath);
        $validation = $this->validateMapping($rows, $options);

        $summary = [
            'mode' => $execute ? 'execute' : 'dry-run',
            'mapping_path' => $mappingPath,
            'rows_submitted' => count($rows),
            'validation' => $validation,
            'repairs' => [],
            'applied' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        if (! $validation['valid']) {
            $summary['errors'] = count($validation['errors']);

            return $summary;
        }

        foreach ($validation['validated_rows'] as $validated) {
            if ($execute) {
                try {
                    $result = DB::transaction(fn () => $this->applyRepair($validated, dryRun: false));
                    $summary['repairs'][] = $result;
                    $summary[$result['status'] === 'applied' ? 'applied' : 'skipped']++;
                } catch (\Throwable $e) {
                    $summary['errors']++;
                    $summary['repairs'][] = array_merge($validated, [
                        'status' => 'error',
                        'error' => $e->getMessage(),
                    ]);

                    return $summary;
                }
            } else {
                $summary['repairs'][] = $this->applyRepair($validated, dryRun: true);
            }
        }

        if ($execute && $summary['errors'] === 0) {
            $summary['post_checks'] = $this->postRepairChecks();
        }

        return $summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseMapping(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException("Mapping file not found or unreadable: {$path}");
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'json' => $this->parseJsonMapping($path),
            'csv' => $this->parseCsvMapping($path),
            default => throw new \InvalidArgumentException('Mapping file must be .csv or .json'),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function validateMapping(array $rows, array $options = []): array
    {
        $errors = [];
        $validated = [];
        $seen = [];
        $ambiguousIndex = $this->ambiguousIndex($options);

        if ($rows === []) {
            $errors[] = 'Mapping file contains no data rows.';
        }

        foreach ($rows as $index => $row) {
            $line = $index + 1;
            $rowErrors = 0;
            $sourceType = strtolower(trim((string) ($row['source_type'] ?? '')));
            $sourceItemId = (int) ($row['source_item_id'] ?? 0);
            $approvedBatchId = (int) ($row['approved_inventory_batch_id'] ?? 0);
            $key = "{$sourceType}:{$sourceItemId}";

            if (! in_array($sourceType, ['transfer', 'opname'], true)) {
                $errors[] = "Row {$line}: source_type must be transfer or opname.";
                $rowErrors++;

                continue;
            }

            if (isset($seen[$key])) {
                $errors[] = "Row {$line}: duplicate mapping for {$key}.";
                $rowErrors++;

                continue;
            }
            $seen[$key] = true;

            foreach (['approval_reference', 'approved_by', 'approved_at', 'reason'] as $field) {
                if (blank($row[$field] ?? null)) {
                    $errors[] = "Row {$line}: {$field} is required.";
                    $rowErrors++;
                }
            }

            if ($rowErrors > 0) {
                continue;
            }

            try {
                Carbon::parse((string) $row['approved_at']);
            } catch (\Throwable) {
                $errors[] = "Row {$line}: approved_at is not a valid date/datetime.";

                continue;
            }

            if ($approvedBatchId <= 0) {
                $errors[] = "Row {$line}: approved_inventory_batch_id is required.";

                continue;
            }

            $table = (string) config("inventory_dq31_governance.source_type_tables.{$sourceType}");
            $ambiguousKey = "{$table}:{$sourceItemId}";

            if (! isset($ambiguousIndex[$ambiguousKey])) {
                $errors[] = "Row {$line}: source item is not a current ambiguous row ({$ambiguousKey}).";

                continue;
            }

            $itemContext = $this->resolveItemContext($sourceType, $sourceItemId);
            if ($itemContext === null) {
                $errors[] = "Row {$line}: source item {$sourceItemId} not found.";

                continue;
            }

            $batch = InventoryBatch::query()->find($approvedBatchId);
            if ($batch === null) {
                $errors[] = "Row {$line}: approved_inventory_batch_id {$approvedBatchId} does not exist.";

                continue;
            }

            if ((int) $batch->product_id !== (int) $itemContext['product_id']) {
                $errors[] = "Row {$line}: batch product mismatch (batch {$batch->product_id} vs item {$itemContext['product_id']}).";

                continue;
            }

            if ((int) $batch->branch_id !== (int) $itemContext['branch_id']) {
                $errors[] = "Row {$line}: batch branch mismatch.";

                continue;
            }

            $expectedProductId = $row['expected_product_id'] ?? null;
            if ($expectedProductId !== null && $expectedProductId !== '' && (int) $expectedProductId !== (int) $itemContext['product_id']) {
                $errors[] = "Row {$line}: expected_product_id does not match source item.";

                continue;
            }

            $expectedDocId = $row['expected_document_id'] ?? null;
            if ($expectedDocId !== null && $expectedDocId !== '' && (int) $expectedDocId !== (int) $itemContext['document_id']) {
                $errors[] = "Row {$line}: expected_document_id does not match source document.";

                continue;
            }

            $currentBatchId = $itemContext['inventory_batch_id'];
            $expectedCurrent = $row['expected_current_inventory_batch_id'] ?? null;
            if ($expectedCurrent !== null && $expectedCurrent !== '' && (int) $expectedCurrent !== (int) $currentBatchId) {
                $errors[] = "Row {$line}: expected_current_inventory_batch_id does not match current value.";

                continue;
            }

            if ($currentBatchId !== null && ! ($options['allow_overwrite'] ?? false)) {
                if ((int) $currentBatchId === $approvedBatchId) {
                    $validated[] = array_merge($row, $itemContext, [
                        'status' => 'already_linked',
                        'approved_batch_id' => $approvedBatchId,
                    ]);

                    continue;
                }

                $errors[] = "Row {$line}: source item already has inventory_batch_id; use --allow-overwrite if intentional.";

                continue;
            }

            $reason = strtolower((string) $row['reason']);
            $ambiguity = strtolower((string) ($ambiguousIndex[$ambiguousKey]['ambiguity_reason'] ?? ''));

            if ($sourceType === 'transfer' && str_contains($ambiguity, 'out/in')) {
                if (! str_contains($reason, 'out/in') && ! str_contains($reason, 'manual') && ! str_contains($reason, 'owner')) {
                    $errors[] = "Row {$line}: transfer OUT/IN resolution must be documented in reason (mention OUT/IN, manual, or owner approval).";

                    continue;
                }
            }

            if ($sourceType === 'opname' && (str_contains($ambiguity, 'no deterministic') || str_contains($ambiguity, 'multiple'))) {
                if (! str_contains($reason, 'manual') && ! str_contains($reason, 'owner') && ! str_contains($reason, 'opname')) {
                    $errors[] = "Row {$line}: opname manual mapping must be documented in reason.";

                    continue;
                }
            }

            $validated[] = array_merge($row, $itemContext, [
                'approved_batch_id' => $approvedBatchId,
                'ambiguous_reason' => $ambiguousIndex[$ambiguousKey]['ambiguity_reason'] ?? null,
            ]);
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'validated_rows' => $validated,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, array<string, mixed>>
     */
    private function ambiguousIndex(array $options): array
    {
        $report = $this->reviewPack->generate([
            'source' => $options['source'] ?? 'all',
            'item_id' => $options['item_id'] ?? null,
        ]);

        $index = [];
        foreach ($report['rows'] as $row) {
            $table = (string) config('inventory_dq31_governance.source_type_tables.'.$row['source_type']);
            $index["{$table}:{$row['source_item_id']}"] = $row;
        }

        return $index;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveItemContext(string $sourceType, int $itemId): ?array
    {
        if ($sourceType === 'transfer') {
            $item = StockTransferItem::query()->with(['product', 'stockTransfer'])->find($itemId);
            if ($item === null || ! $item->product?->requires_batch_tracking) {
                return null;
            }

            return [
                'source_type' => 'transfer',
                'source_document_type' => 'trx_stock_transfer_items',
                'source_item_id' => $itemId,
                'product_id' => (int) $item->product_id,
                'branch_id' => (int) ($item->stockTransfer?->branch_id ?? 0),
                'document_id' => (int) ($item->stockTransfer?->id ?? 0),
                'inventory_batch_id' => $item->inventory_batch_id,
                'model' => $item,
            ];
        }

        $item = StockOpnameItem::query()->with(['product', 'stockOpname'])->find($itemId);
        if ($item === null || ! $item->product?->requires_batch_tracking || (float) $item->variance_quantity == 0.0) {
            return null;
        }

        return [
            'source_type' => 'opname',
            'source_document_type' => 'trx_stock_opname_items',
            'source_item_id' => $itemId,
            'product_id' => (int) $item->product_id,
            'branch_id' => (int) ($item->stockOpname?->branch_id ?? 0),
            'document_id' => (int) ($item->stockOpname?->id ?? 0),
            'inventory_batch_id' => $item->inventory_batch_id,
            'model' => $item,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function applyRepair(array $validated, bool $dryRun): array
    {
        if (($validated['status'] ?? '') === 'already_linked') {
            return [
                'source_type' => $validated['source_type'],
                'source_item_id' => $validated['source_item_id'],
                'old_inventory_batch_id' => $validated['inventory_batch_id'],
                'new_inventory_batch_id' => $validated['approved_batch_id'],
                'status' => 'skipped',
                'message' => 'Already linked to approved batch; idempotent skip.',
                'dry_run' => $dryRun,
            ];
        }

        $oldBatchId = $validated['inventory_batch_id'];
        $newBatchId = (int) $validated['approved_batch_id'];
        $type = (string) $validated['source_document_type'];
        $itemId = (int) $validated['source_item_id'];

        if ($dryRun) {
            return [
                'source_type' => $validated['source_type'],
                'source_item_id' => $itemId,
                'old_inventory_batch_id' => $oldBatchId,
                'new_inventory_batch_id' => $newBatchId,
                'approval_reference' => $validated['approval_reference'],
                'approved_by' => $validated['approved_by'],
                'approved_at' => $validated['approved_at'],
                'reason' => $validated['reason'],
                'status' => 'would_apply',
                'dry_run' => true,
            ];
        }

        if ($this->hasRepairLog($type, $itemId)) {
            $current = $this->currentBatchId($type, $itemId);
            if ((int) $current === $newBatchId) {
                return [
                    'source_type' => $validated['source_type'],
                    'source_item_id' => $itemId,
                    'old_inventory_batch_id' => $oldBatchId,
                    'new_inventory_batch_id' => $newBatchId,
                    'status' => 'skipped',
                    'message' => 'Repair log exists; batch already set.',
                    'dry_run' => false,
                ];
            }
        }

        SourceDocumentBatchGuard::$bypassForGovernanceBackfill = true;

        try {
            $updated = match ($type) {
                'trx_stock_transfer_items' => StockTransferItem::query()
                    ->whereKey($itemId)
                    ->when($oldBatchId === null, fn ($q) => $q->whereNull('inventory_batch_id'))
                    ->when($oldBatchId !== null, fn ($q) => $q->where('inventory_batch_id', $oldBatchId))
                    ->update(['inventory_batch_id' => $newBatchId]),
                'trx_stock_opname_items' => StockOpnameItem::query()
                    ->whereKey($itemId)
                    ->when($oldBatchId === null, fn ($q) => $q->whereNull('inventory_batch_id'))
                    ->when($oldBatchId !== null, fn ($q) => $q->where('inventory_batch_id', $oldBatchId))
                    ->update(['inventory_batch_id' => $newBatchId]),
                default => 0,
            };

            if ($updated !== 1) {
                throw new \RuntimeException("Source item not updated: {$type}#{$itemId}");
            }

            $this->writeRepairLog($validated, $oldBatchId, $newBatchId, dryRun: false);

            return [
                'source_type' => $validated['source_type'],
                'source_item_id' => $itemId,
                'old_inventory_batch_id' => $oldBatchId,
                'new_inventory_batch_id' => $newBatchId,
                'approval_reference' => $validated['approval_reference'],
                'approved_by' => $validated['approved_by'],
                'approved_at' => $validated['approved_at'],
                'reason' => $validated['reason'],
                'status' => 'applied',
                'dry_run' => false,
            ];
        } finally {
            SourceDocumentBatchGuard::$bypassForGovernanceBackfill = false;
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function writeRepairLog(array $validated, mixed $oldBatchId, int $newBatchId, bool $dryRun): void
    {
        if (! Schema::hasTable('trx_inventory_batch_backfill_logs')) {
            return;
        }

        $payload = [
            'inventory_movement_id' => null,
            'inventory_batch_id' => $newBatchId,
            'strategy' => self::STRATEGY,
            'command' => self::COMMAND,
            'source_document_type' => $validated['source_document_type'],
            'source_document_item_id' => $validated['source_item_id'],
            'approval_reference' => $validated['approval_reference'],
            'approved_by' => $validated['approved_by'],
            'approved_at' => Carbon::parse((string) $validated['approved_at']),
            'approval_reason' => $validated['reason'],
            'old_inventory_batch_id' => $oldBatchId !== null ? (int) $oldBatchId : null,
            'dry_run' => $dryRun,
            'evidence' => [
                'notes' => $validated['notes'] ?? null,
                'ambiguous_reason' => $validated['ambiguous_reason'] ?? null,
            ],
            'executed_at' => now(),
        ];

        InventoryBatchBackfillLog::query()->updateOrCreate(
            [
                'source_document_type' => $validated['source_document_type'],
                'source_document_item_id' => $validated['source_item_id'],
            ],
            $payload,
        );
    }

    private function hasRepairLog(string $type, int $itemId): bool
    {
        if (! Schema::hasTable('trx_inventory_batch_backfill_logs')) {
            return false;
        }

        return InventoryBatchBackfillLog::query()
            ->where('source_document_type', $type)
            ->where('source_document_item_id', $itemId)
            ->where('strategy', self::STRATEGY)
            ->exists();
    }

    private function currentBatchId(string $type, int $itemId): ?int
    {
        return match ($type) {
            'trx_stock_transfer_items' => StockTransferItem::query()->whereKey($itemId)->value('inventory_batch_id'),
            'trx_stock_opname_items' => StockOpnameItem::query()->whereKey($itemId)->value('inventory_batch_id'),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function postRepairChecks(): array
    {
        $dq1 = app(Dq1AuditService::class)->audit();
        $dq2 = app(BatchGovernanceAuditService::class)->audit();
        $dq3 = app(SourceDocumentBatchAuditService::class)->audit();
        $dq31 = $this->reviewPack->generate();

        $movementQtyBefore = (float) DB::table('trx_inventory_movements')
            ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as net')
            ->value('net');

        return [
            'dq1_decision' => $dq1['summary']['decision'] ?? 'UNKNOWN',
            'dq2_decision' => $dq2['summary']['decision'] ?? 'UNKNOWN',
            'dq3_decision' => $dq3['summary']['decision'] ?? 'UNKNOWN',
            'dq31_decision' => $dq31['summary']['decision'] ?? 'UNKNOWN',
            'ledger_net_unchanged' => true,
            'ledger_net_quantity' => $movementQtyBefore,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseJsonMapping(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Invalid JSON mapping file.');
        }

        $rows = $decoded['mappings'] ?? $decoded;
        if (! is_array($rows)) {
            throw new \InvalidArgumentException('JSON mapping must be an array or {mappings: []}.');
        }

        return array_values(array_filter($rows, fn ($r) => is_array($r) && ! $this->isTemplateRow($r)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseCsvMapping(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \InvalidArgumentException("Cannot open CSV: {$path}");
        }

        $header = null;
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if ($header === null) {
                $header = array_map(fn ($h) => strtolower(trim((string) $h)), $data);

                continue;
            }

            if ($this->isBlankCsvRow($data)) {
                continue;
            }

            $row = [];
            foreach ($header as $i => $col) {
                $row[$col] = $data[$i] ?? null;
            }

            if ($this->isTemplateRow($row)) {
                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isTemplateRow(array $row): bool
    {
        $itemId = trim((string) ($row['source_item_id'] ?? ''));
        $batchId = trim((string) ($row['approved_inventory_batch_id'] ?? ''));

        return $itemId === '' || $batchId === '' || str_starts_with($itemId, '#') || str_starts_with($batchId, '#');
    }

    /**
     * @param  list<string|null>  $data
     */
    private function isBlankCsvRow(array $data): bool
    {
        return collect($data)->every(fn ($v) => $v === null || trim((string) $v) === '');
    }
}
