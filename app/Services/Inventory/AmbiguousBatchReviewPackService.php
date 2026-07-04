<?php

namespace App\Services\Inventory;

use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockOpnameItem;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use Illuminate\Support\Collection;

/**
 * Read-only DQ-3.1 review pack for ambiguous transfer/opname source-document rows.
 */
class AmbiguousBatchReviewPackService
{
    public function __construct(
        private readonly SourceDocumentBatchBackfillService $backfill,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function generate(array $options = []): array
    {
        $source = strtolower((string) ($options['source'] ?? 'all'));
        $preview = $this->backfill->preview([
            'source' => $source === 'all' ? 'all' : $source,
            'item_id' => $options['item_id'] ?? null,
            'no_legacy_placeholder' => true,
        ]);

        $rows = collect($preview['items'] ?? [])
            ->filter(fn (array $item) => ($item['strategy'] ?? '') === SourceDocumentBatchBackfillService::STRATEGY_AMBIGUOUS)
            ->filter(fn (array $item) => $this->isAllowedSourceTable((string) ($item['source_document_type'] ?? '')))
            ->when(! empty($options['item_id']), function ($c) use ($options) {
                $itemId = (int) $options['item_id'];

                return $c->filter(fn (array $item) => (int) ($item['source_document_item_id'] ?? 0) === $itemId);
            })
            ->map(fn (array $item) => $this->enrichRow($item))
            ->values()
            ->all();

        $transferCount = collect($rows)->where('source_type', 'transfer')->count();
        $opnameCount = collect($rows)->where('source_type', 'opname')->count();
        $candidateBatchCount = collect($rows)->sum(fn (array $r) => count($r['candidate_inventory_batch_ids'] ?? []));

        $checks = [
            $this->check('DQ31-REVIEW-001', $transferCount >= 0 ? 'PASS' : 'FAIL', "Transfer ambiguous rows: {$transferCount}", ['count' => $transferCount]),
            $this->check('DQ31-REVIEW-002', $opnameCount >= 0 ? 'PASS' : 'FAIL', "Opname ambiguous rows: {$opnameCount}", ['count' => $opnameCount]),
            $this->check(
                'DQ31-REVIEW-003',
                count($rows) === 0 || $candidateBatchCount > 0 ? 'PASS' : 'WARN',
                count($rows) === 0
                    ? 'No ambiguous rows — candidate evidence not required.'
                    : "Candidate batch evidence for {$candidateBatchCount} batch reference(s).",
                ['candidate_batch_count' => $candidateBatchCount],
            ),
            $this->check(
                'DQ31-REVIEW-004',
                is_file(base_path((string) config('inventory_dq31_governance.mapping_template_csv'))) ? 'PASS' : 'WARN',
                'Mapping template CSV documented.',
                ['template' => (string) config('inventory_dq31_governance.mapping_template_csv')],
            ),
            $this->check('DQ31-REVIEW-005', 'PASS', 'Review pack generation is read-only; no mutations performed.', []),
        ];

        $decision = count($rows) === 0 ? 'GO' : 'WATCH';

        return [
            'generated_at' => now()->toIso8601String(),
            'sprint' => config('inventory_dq31_governance.sprint', 'DQ-3.1'),
            'summary' => [
                'transfer_ambiguous_count' => $transferCount,
                'opname_ambiguous_count' => $opnameCount,
                'total_ambiguous_count' => count($rows),
                'candidate_batch_count' => $candidateBatchCount,
                'mapping_template_csv' => (string) config('inventory_dq31_governance.mapping_template_csv'),
                'mapping_template_json' => (string) config('inventory_dq31_governance.mapping_template_json'),
                'decision' => $decision,
                'recommended_action' => count($rows) === 0 ? 'none' : 'manual_review_required',
            ],
            'checks' => $checks,
            'rows' => $rows,
            'privacy' => [
                'privacy_safe' => true,
                'row_level_data' => true,
                'no_pii' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function enrichRow(array $plan): array
    {
        $type = (string) $plan['source_document_type'];
        $itemId = (int) $plan['source_document_item_id'];
        $sourceType = $type === 'trx_stock_transfer_items' ? 'transfer' : 'opname';

        if ($sourceType === 'transfer') {
            return $this->enrichTransferRow($itemId, $plan);
        }

        return $this->enrichOpnameRow($itemId, $plan);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function enrichTransferRow(int $itemId, array $plan): array
    {
        $item = StockTransferItem::query()->with(['product', 'stockTransfer'])->findOrFail($itemId);
        $transfer = $item->stockTransfer;
        $transferTable = (new StockTransfer)->getTable();

        $outMovements = $this->transferMovements($transfer?->id, $item->product_id, InventoryMovement::TYPE_TRANSFER_OUT);
        $inMovements = $this->transferMovements($transfer?->id, $item->product_id, InventoryMovement::TYPE_TRANSFER_IN);

        $candidates = $this->movementCandidates($outMovements->merge($inMovements));

        return [
            'source_type' => 'transfer',
            'source_item_id' => $itemId,
            'source_document_id' => $transfer?->id,
            'source_document_code' => $transfer?->transfer_number,
            'product_id' => (int) $item->product_id,
            'product_code' => $item->product?->sku,
            'product_name' => $item->product?->name,
            'branch_id' => (int) ($transfer?->branch_id ?? 0),
            'location_scope' => [
                'source_location_id' => $transfer?->source_inventory_location_id,
                'destination_location_id' => $transfer?->destination_inventory_location_id,
            ],
            'quantity' => (float) $item->quantity,
            'current_inventory_batch_id' => $item->inventory_batch_id,
            'ambiguity_reason' => (string) ($plan['message'] ?? 'Ambiguous transfer batch linkage.'),
            'candidate_movement_ids' => $candidates->pluck('movement_id')->unique()->values()->all(),
            'candidate_inventory_batch_ids' => $candidates->pluck('inventory_batch_id')->unique()->values()->all(),
            'candidate_batches' => $candidates->unique('inventory_batch_id')->values()->all(),
            'out_movement_batch_ids' => $outMovements->pluck('inventory_batch_id')->unique()->values()->all(),
            'in_movement_batch_ids' => $inMovements->pluck('inventory_batch_id')->unique()->values()->all(),
            'recommended_action' => 'manual_review_required',
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function enrichOpnameRow(int $itemId, array $plan): array
    {
        $item = StockOpnameItem::query()->with(['product', 'stockOpname'])->findOrFail($itemId);
        $opname = $item->stockOpname;
        $opnameTable = (new StockOpname)->getTable();
        $variance = (float) $item->variance_quantity;

        $movements = InventoryMovement::query()
            ->where('reference_type', $opnameTable)
            ->where('reference_id', $opname?->id)
            ->where('product_id', $item->product_id)
            ->whereNotNull('inventory_batch_id')
            ->get();

        $candidates = $this->movementCandidates($movements);

        return [
            'source_type' => 'opname',
            'source_item_id' => $itemId,
            'source_document_id' => $opname?->id,
            'source_document_code' => $opname?->opname_number ?? null,
            'product_id' => (int) $item->product_id,
            'product_code' => $item->product?->sku,
            'product_name' => $item->product?->name,
            'branch_id' => (int) ($opname?->branch_id ?? 0),
            'location_scope' => [
                'inventory_location_id' => $opname?->inventory_location_id,
            ],
            'quantity' => $variance,
            'current_inventory_batch_id' => $item->inventory_batch_id,
            'ambiguity_reason' => (string) ($plan['message'] ?? 'Ambiguous opname batch linkage.'),
            'candidate_movement_ids' => $candidates->pluck('movement_id')->unique()->values()->all(),
            'candidate_inventory_batch_ids' => $candidates->pluck('inventory_batch_id')->unique()->values()->all(),
            'candidate_batches' => $candidates->unique('inventory_batch_id')->values()->all(),
            'recommended_action' => 'manual_review_required',
        ];
    }

    /**
     * @return Collection<int, InventoryMovement>
     */
    private function transferMovements(?int $transferId, int $productId, string $movementType)
    {
        if ($transferId === null) {
            return collect();
        }

        $transferTable = (new StockTransfer)->getTable();

        return InventoryMovement::query()
            ->where('reference_type', $transferTable)
            ->where('reference_id', $transferId)
            ->where('product_id', $productId)
            ->where('movement_type', $movementType)
            ->whereNotNull('inventory_batch_id')
            ->get();
    }

    /**
     * @param  Collection<int, InventoryMovement>  $movements
     * @return Collection<int, array<string, mixed>>
     */
    private function movementCandidates($movements)
    {
        return $movements->map(function (InventoryMovement $movement) {
            $batch = $movement->inventory_batch_id
                ? InventoryBatch::query()->find($movement->inventory_batch_id)
                : null;

            return [
                'movement_id' => $movement->id,
                'inventory_batch_id' => (int) $movement->inventory_batch_id,
                'batch_number' => $batch?->batch_number,
                'expiry_date' => $batch?->expiry_date?->toDateString(),
                'backfill_source' => $batch?->backfill_source,
                'movement_type' => $movement->movement_type,
            ];
        });
    }

    private function isAllowedSourceTable(string $table): bool
    {
        return in_array($table, ['trx_stock_transfer_items', 'trx_stock_opname_items'], true);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function check(string $id, string $status, string $message, array $details): array
    {
        return [
            'check_id' => $id,
            'title' => (string) config("inventory_dq31_governance.checks.{$id}.title", $id),
            'status' => $status,
            'message' => $message,
            'details' => $details,
        ];
    }
}
