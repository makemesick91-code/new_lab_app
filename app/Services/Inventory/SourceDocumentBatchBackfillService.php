<?php

namespace App\Services\Inventory;

use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptItem;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryBatchBackfillLog;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockOpnameItem;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use App\Modules\Inventory\Support\SourceDocumentBatchGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DQ-3 dry-run / execute backfill for missing inventory_batch_id on source-document items.
 */
class SourceDocumentBatchBackfillService
{
    public const STRATEGY_ALREADY_LINKED = 'already_linked';

    public const STRATEGY_LINK_FROM_MOVEMENT = 'link_from_movement';

    public const STRATEGY_LINK_FROM_SOURCE_FIELDS = 'link_from_source_batch_fields';

    public const STRATEGY_LEGACY_FROM_MOVEMENT = 'legacy_placeholder_from_movement';

    public const STRATEGY_AMBIGUOUS = 'ambiguous';

    public const COMMAND = 'inventory:backfill-source-document-batches';

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function preview(array $options = []): array
    {
        $items = $this->missingItems($options);
        $plans = [];
        $counts = [
            'linked_from_movement' => 0,
            'linked_from_source_batch_fields' => 0,
            'linked_legacy_placeholder_from_movement' => 0,
            'ambiguous_skipped' => 0,
            'skipped_already_linked' => 0,
        ];

        foreach ($items as $item) {
            $plan = $this->classifyItem($item, allowLegacy: ! ($options['no_legacy_placeholder'] ?? false));
            $plans[] = $plan;

            match ($plan['strategy']) {
                self::STRATEGY_ALREADY_LINKED => $counts['skipped_already_linked']++,
                self::STRATEGY_LINK_FROM_MOVEMENT => $counts['linked_from_movement']++,
                self::STRATEGY_LINK_FROM_SOURCE_FIELDS => $counts['linked_from_source_batch_fields']++,
                self::STRATEGY_LEGACY_FROM_MOVEMENT => $counts['linked_legacy_placeholder_from_movement']++,
                self::STRATEGY_AMBIGUOUS => $counts['ambiguous_skipped']++,
                default => null,
            };
        }

        return array_merge([
            'scanned' => count($plans),
            'items' => $plans,
        ], $counts);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        $execute = (bool) ($options['execute'] ?? false);
        $preview = $this->preview($options);

        $summary = [
            'mode' => $execute ? 'execute' : 'dry-run',
            'scanned' => $preview['scanned'],
            'skipped_already_linked' => 0,
            'linked_from_movement' => 0,
            'linked_from_source_batch_fields' => 0,
            'created_batch_from_real_source' => 0,
            'linked_legacy_placeholder_from_movement' => 0,
            'ambiguous_skipped' => 0,
            'errors' => 0,
            'items' => [],
        ];

        if (! $execute) {
            $summary['items'] = $preview['items'];

            return $summary;
        }

        SourceDocumentBatchGuard::$bypassForGovernanceBackfill = true;

        try {
            DB::transaction(function () use ($options, $preview, &$summary) {
                foreach ($preview['items'] as $plan) {
                    if ($plan['strategy'] === self::STRATEGY_ALREADY_LINKED) {
                        $summary['skipped_already_linked']++;

                        continue;
                    }

                    if ($plan['strategy'] === self::STRATEGY_AMBIGUOUS) {
                        $summary['ambiguous_skipped']++;
                        $summary['items'][] = $plan;

                        if ($options['fail_on_ambiguous'] ?? false) {
                            throw new \RuntimeException(
                                'Ambiguous source item '.$plan['source_document_type'].'#'.$plan['source_document_item_id'],
                            );
                        }

                        continue;
                    }

                    try {
                        $result = $this->applyPlan($plan);
                        $summary['items'][] = $result;

                        match ($result['strategy']) {
                            self::STRATEGY_LINK_FROM_MOVEMENT => $summary['linked_from_movement']++,
                            self::STRATEGY_LINK_FROM_SOURCE_FIELDS => $summary['linked_from_source_batch_fields']++,
                            self::STRATEGY_LEGACY_FROM_MOVEMENT => $summary['linked_legacy_placeholder_from_movement']++,
                            default => null,
                        };
                    } catch (\Throwable $e) {
                        $summary['errors']++;
                        $summary['items'][] = array_merge($plan, [
                            'applied' => false,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
        } finally {
            SourceDocumentBatchGuard::$bypassForGovernanceBackfill = false;
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return Collection<int, array<string, mixed>>
     */
    private function missingItems(array $options): Collection
    {
        $source = strtolower((string) ($options['source'] ?? 'all'));
        $items = collect();

        if (in_array($source, ['all', 'goods-receipt', 'goods_receipt'], true)) {
            $items = $items->merge($this->missingGoodsReceiptItems($options));
        }

        if (in_array($source, ['all', 'transfer', 'stock-transfer'], true)) {
            $items = $items->merge($this->missingTransferItems($options));
        }

        if (in_array($source, ['all', 'opname', 'stock-opname'], true)) {
            $items = $items->merge($this->missingOpnameItems($options));
        }

        if (! empty($options['limit'])) {
            $items = $items->take((int) $options['limit']);
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return Collection<int, array<string, mixed>>
     */
    private function missingGoodsReceiptItems(array $options): Collection
    {
        if (! Schema::hasTable('trx_goods_receipt_items')) {
            return collect();
        }

        $query = GoodsReceiptItem::query()
            ->whereNull('inventory_batch_id')
            ->where('accepted_qty', '>', 0)
            ->whereHas('product', fn ($q) => $q->where('requires_batch_tracking', true))
            ->with(['product', 'goodsReceipt']);

        if (! empty($options['item_id']) && ($options['source'] ?? 'all') !== 'transfer' && ($options['source'] ?? 'all') !== 'opname') {
            $query->whereKey((int) $options['item_id']);
        }

        return $query->get()->map(fn (GoodsReceiptItem $item) => [
            'source_document_type' => 'trx_goods_receipt_items',
            'source_document_item_id' => $item->id,
            'product_id' => (int) $item->product_id,
            'branch_id' => (int) ($item->goodsReceipt?->branch_id ?? 0),
            'model' => $item,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return Collection<int, array<string, mixed>>
     */
    private function missingTransferItems(array $options): Collection
    {
        if (! Schema::hasTable('trx_stock_transfer_items')) {
            return collect();
        }

        $query = StockTransferItem::query()
            ->whereNull('inventory_batch_id')
            ->whereHas('product', fn ($q) => $q->where('requires_batch_tracking', true))
            ->with(['product', 'stockTransfer']);

        if (! empty($options['item_id']) && in_array($options['source'] ?? '', ['transfer', 'stock-transfer'], true)) {
            $query->whereKey((int) $options['item_id']);
        }

        return $query->get()->map(fn (StockTransferItem $item) => [
            'source_document_type' => 'trx_stock_transfer_items',
            'source_document_item_id' => $item->id,
            'product_id' => (int) $item->product_id,
            'branch_id' => (int) ($item->stockTransfer?->branch_id ?? 0),
            'model' => $item,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return Collection<int, array<string, mixed>>
     */
    private function missingOpnameItems(array $options): Collection
    {
        if (! Schema::hasTable('trx_stock_opname_items')) {
            return collect();
        }

        $query = StockOpnameItem::query()
            ->whereNull('inventory_batch_id')
            ->where('variance_quantity', '!=', 0)
            ->whereHas('product', fn ($q) => $q->where('requires_batch_tracking', true))
            ->with(['product', 'stockOpname']);

        if (! empty($options['item_id']) && in_array($options['source'] ?? '', ['opname', 'stock-opname'], true)) {
            $query->whereKey((int) $options['item_id']);
        }

        return $query->get()->map(fn (StockOpnameItem $item) => [
            'source_document_type' => 'trx_stock_opname_items',
            'source_document_item_id' => $item->id,
            'product_id' => (int) $item->product_id,
            'branch_id' => (int) ($item->stockOpname?->branch_id ?? 0),
            'model' => $item,
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function classifyItem(array $item, bool $allowLegacy = true): array
    {
        $type = (string) $item['source_document_type'];
        $itemId = (int) $item['source_document_item_id'];

        if ($this->hasBackfillLog($type, $itemId)) {
            return $this->plan($type, $itemId, self::STRATEGY_ALREADY_LINKED, null, 'Backfill log exists; idempotent skip.');
        }

        $model = $item['model'];
        if ($model->inventory_batch_id !== null) {
            return $this->plan($type, $itemId, self::STRATEGY_ALREADY_LINKED, null, 'Source item already linked.');
        }

        return match ($type) {
            'trx_goods_receipt_items' => $this->classifyGoodsReceiptItem($item, $allowLegacy),
            'trx_stock_transfer_items' => $this->classifyTransferItem($item, $allowLegacy),
            'trx_stock_opname_items' => $this->classifyOpnameItem($item, $allowLegacy),
            default => $this->plan($type, $itemId, self::STRATEGY_AMBIGUOUS, null, 'Unknown source document type.'),
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function classifyGoodsReceiptItem(array $item, bool $allowLegacy): array
    {
        /** @var GoodsReceiptItem $model */
        $model = $item['model'];
        $type = $item['source_document_type'];
        $itemId = (int) $item['source_document_item_id'];

        if ($model->inventory_movement_id) {
            $movement = InventoryMovement::query()->find($model->inventory_movement_id);
            if ($movement?->inventory_batch_id) {
                return $this->planFromMovement($type, $itemId, $movement, 'Direct goods receipt movement link.');
            }
        }

        if ($model->reversal_movement_id) {
            $movement = InventoryMovement::query()->find($model->reversal_movement_id);
            if ($movement?->inventory_batch_id) {
                return $this->planFromMovement($type, $itemId, $movement, 'Goods receipt reversal movement link.');
            }
        }

        $grTable = (new GoodsReceipt)->getTable();
        $candidates = InventoryMovement::query()
            ->where('reference_type', $grTable)
            ->where('reference_id', $model->goods_receipt_id)
            ->where('product_id', $model->product_id)
            ->where('inventory_location_id', $model->inventory_location_id)
            ->whereNotNull('inventory_batch_id')
            ->get();

        if ($candidates->count() === 1) {
            return $this->planFromMovement($type, $itemId, $candidates->first(), 'Header-level goods receipt movement match.');
        }

        if ($candidates->count() > 1) {
            $qty = (float) $model->accepted_qty;
            $qtyMatches = $candidates->filter(fn ($m) => abs((float) $m->quantity_in - $qty) < 0.0001);
            if ($qtyMatches->count() === 1) {
                return $this->planFromMovement($type, $itemId, $qtyMatches->first(), 'Quantity-matched goods receipt movement.');
            }

            return $this->plan($type, $itemId, self::STRATEGY_AMBIGUOUS, null, 'Multiple goods receipt movements match item.');
        }

        if (filled($model->batch_number)) {
            $batch = $this->findBatchBySourceFields($item['branch_id'], $model->product_id, $model->batch_number, $model->lot_number, $model->expiry_date?->toDateString());
            if ($batch) {
                return $this->plan($type, $itemId, self::STRATEGY_LINK_FROM_SOURCE_FIELDS, $batch->id, 'Matched existing batch from GR item fields.');
            }
        }

        if ($allowLegacy && $model->inventory_movement_id) {
            $movement = InventoryMovement::query()->find($model->inventory_movement_id);
            if ($movement?->inventory_batch_id) {
                $batch = InventoryBatch::query()->find($movement->inventory_batch_id);
                if ($batch && str_starts_with((string) $batch->batch_number, (string) config('inventory_batch_governance.legacy_batch_prefix', 'LEGACY-DQ2'))) {
                    return $this->planFromMovement($type, $itemId, $movement, 'Legacy placeholder from movement.', self::STRATEGY_LEGACY_FROM_MOVEMENT);
                }
            }
        }

        return $this->plan($type, $itemId, self::STRATEGY_AMBIGUOUS, null, 'No deterministic goods receipt batch recovery path.');
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function classifyTransferItem(array $item, bool $allowLegacy): array
    {
        /** @var StockTransferItem $model */
        $model = $item['model'];
        $type = $item['source_document_type'];
        $itemId = (int) $item['source_document_item_id'];
        $transferTable = (new StockTransfer)->getTable();

        $outMovements = InventoryMovement::query()
            ->where('reference_type', $transferTable)
            ->where('reference_id', $model->stock_transfer_id)
            ->where('product_id', $model->product_id)
            ->where('movement_type', InventoryMovement::TYPE_TRANSFER_OUT)
            ->whereNotNull('inventory_batch_id')
            ->get();

        $inMovements = InventoryMovement::query()
            ->where('reference_type', $transferTable)
            ->where('reference_id', $model->stock_transfer_id)
            ->where('product_id', $model->product_id)
            ->where('movement_type', InventoryMovement::TYPE_TRANSFER_IN)
            ->whereNotNull('inventory_batch_id')
            ->get();

        $outBatches = $outMovements->pluck('inventory_batch_id')->unique()->values();
        $inBatches = $inMovements->pluck('inventory_batch_id')->unique()->values();

        if ($outBatches->count() === 1 && $inBatches->count() <= 1) {
            if ($inBatches->isEmpty() || (int) $inBatches->first() === (int) $outBatches->first()) {
                $movement = $outMovements->firstWhere('inventory_batch_id', $outBatches->first());

                return $this->planFromMovement($type, $itemId, $movement, 'Transfer outbound movement batch link.');
            }

            return $this->plan($type, $itemId, self::STRATEGY_AMBIGUOUS, null, 'Transfer OUT/IN batch lineage disagrees.');
        }

        if ($outMovements->count() === 1 && $outMovements->first()?->inventory_batch_id) {
            $movement = $outMovements->first();
            $strategy = self::STRATEGY_LINK_FROM_MOVEMENT;
            if ($allowLegacy) {
                $batch = InventoryBatch::query()->find($movement->inventory_batch_id);
                if ($batch && str_starts_with((string) $batch->batch_number, (string) config('inventory_batch_governance.legacy_batch_prefix', 'LEGACY-DQ2'))) {
                    $strategy = self::STRATEGY_LEGACY_FROM_MOVEMENT;
                }
            }

            return $this->planFromMovement($type, $itemId, $movement, 'Single transfer outbound movement.', $strategy);
        }

        if ($outMovements->count() > 1 || $inMovements->count() > 1) {
            $qty = (float) $model->quantity;
            $qtyMatches = $outMovements->filter(fn ($m) => abs((float) $m->quantity_out - $qty) < 0.0001);
            if ($qtyMatches->count() === 1) {
                return $this->planFromMovement($type, $itemId, $qtyMatches->first(), 'Quantity-matched transfer outbound movement.');
            }

            return $this->plan($type, $itemId, self::STRATEGY_AMBIGUOUS, null, 'Multiple transfer movements match item.');
        }

        return $this->plan($type, $itemId, self::STRATEGY_AMBIGUOUS, null, 'No deterministic transfer batch recovery path.');
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function classifyOpnameItem(array $item, bool $allowLegacy): array
    {
        /** @var StockOpnameItem $model */
        $model = $item['model'];
        $type = $item['source_document_type'];
        $itemId = (int) $item['source_document_item_id'];
        $opnameTable = (new StockOpname)->getTable();
        $variance = (float) $model->variance_quantity;

        $movements = InventoryMovement::query()
            ->where('reference_type', $opnameTable)
            ->where('reference_id', $model->stock_opname_id)
            ->where('product_id', $model->product_id)
            ->whereNotNull('inventory_batch_id')
            ->get();

        if ($variance > 0) {
            $candidates = $movements->where('movement_type', InventoryMovement::TYPE_ADJUSTMENT_IN)
                ->filter(fn ($m) => abs((float) $m->quantity_in - $variance) < 0.0001);
        } else {
            $qtyOut = abs($variance);
            $candidates = $movements->where('movement_type', InventoryMovement::TYPE_ADJUSTMENT_OUT)
                ->filter(fn ($m) => abs((float) $m->quantity_out - $qtyOut) < 0.0001);
        }

        if ($candidates->count() === 1) {
            return $this->planFromMovement($type, $itemId, $candidates->first(), 'Opname adjustment movement match.');
        }

        if ($candidates->count() > 1) {
            return $this->plan($type, $itemId, self::STRATEGY_AMBIGUOUS, null, 'Multiple opname adjustment movements match item.');
        }

        if ($movements->count() === 1) {
            $movement = $movements->first();
            $strategy = self::STRATEGY_LINK_FROM_MOVEMENT;
            if ($allowLegacy) {
                $batch = InventoryBatch::query()->find($movement->inventory_batch_id);
                if ($batch && str_starts_with((string) $batch->batch_number, (string) config('inventory_batch_governance.legacy_batch_prefix', 'LEGACY-DQ2'))) {
                    $strategy = self::STRATEGY_LEGACY_FROM_MOVEMENT;
                }
            }

            return $this->planFromMovement($type, $itemId, $movement, 'Single opname movement batch.', $strategy);
        }

        return $this->plan($type, $itemId, self::STRATEGY_AMBIGUOUS, null, 'No deterministic opname batch recovery path.');
    }

    /**
     * @return array<string, mixed>
     */
    private function applyPlan(array $plan): array
    {
        $batchId = (int) ($plan['inventory_batch_id'] ?? 0);
        if ($batchId <= 0) {
            throw new \RuntimeException('Plan missing inventory_batch_id for '.$plan['source_document_type'].'#'.$plan['source_document_item_id']);
        }

        $type = (string) $plan['source_document_type'];
        $itemId = (int) $plan['source_document_item_id'];

        $updated = match ($type) {
            'trx_goods_receipt_items' => GoodsReceiptItem::query()->whereKey($itemId)->whereNull('inventory_batch_id')->update(['inventory_batch_id' => $batchId]),
            'trx_stock_transfer_items' => StockTransferItem::query()->whereKey($itemId)->whereNull('inventory_batch_id')->update(['inventory_batch_id' => $batchId]),
            'trx_stock_opname_items' => StockOpnameItem::query()->whereKey($itemId)->whereNull('inventory_batch_id')->update(['inventory_batch_id' => $batchId]),
            default => 0,
        };

        if ($updated !== 1) {
            throw new \RuntimeException('Source item not updated (already linked or missing): '.$type.'#'.$itemId);
        }

        $movementId = $plan['movement_id'] ?? null;

        InventoryBatchBackfillLog::query()->create([
            'inventory_movement_id' => $movementId,
            'inventory_batch_id' => $batchId,
            'strategy' => (string) $plan['strategy'],
            'command' => self::COMMAND,
            'source_document_type' => $type,
            'source_document_item_id' => $itemId,
            'evidence' => [
                'message' => $plan['message'] ?? null,
                'movement_id' => $movementId,
            ],
            'executed_at' => now(),
        ]);

        return array_merge($plan, ['applied' => true]);
    }

    private function hasBackfillLog(string $type, int $itemId): bool
    {
        if (! Schema::hasTable('trx_inventory_batch_backfill_logs')
            || ! Schema::hasColumn('trx_inventory_batch_backfill_logs', 'source_document_type')) {
            return false;
        }

        return InventoryBatchBackfillLog::query()
            ->where('source_document_type', $type)
            ->where('source_document_item_id', $itemId)
            ->exists();
    }

    private function findBatchBySourceFields(
        int $branchId,
        int $productId,
        string $batchNumber,
        ?string $lotNumber,
        ?string $expiryDate,
    ): ?InventoryBatch {
        $query = InventoryBatch::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where('batch_number', $batchNumber);

        if ($lotNumber !== null && $lotNumber !== '') {
            $query->where('lot_number', $lotNumber);
        }

        if ($expiryDate !== null) {
            $query->whereDate('expiry_date', $expiryDate);
        }

        return $query->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function planFromMovement(
        string $type,
        int $itemId,
        ?InventoryMovement $movement,
        string $message,
        string $strategy = self::STRATEGY_LINK_FROM_MOVEMENT,
    ): array {
        if ($movement === null || $movement->inventory_batch_id === null) {
            return $this->plan($type, $itemId, self::STRATEGY_AMBIGUOUS, null, 'Movement missing batch.');
        }

        return $this->plan(
            $type,
            $itemId,
            $strategy,
            (int) $movement->inventory_batch_id,
            $message,
            ['movement_id' => $movement->id],
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function plan(
        string $type,
        int $itemId,
        string $strategy,
        ?int $batchId,
        string $message,
        array $meta = [],
    ): array {
        return array_merge([
            'source_document_type' => $type,
            'source_document_item_id' => $itemId,
            'strategy' => $strategy,
            'inventory_batch_id' => $batchId,
            'message' => $message,
        ], $meta);
    }
}
