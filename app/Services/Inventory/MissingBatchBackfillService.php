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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DQ-2 staged dry-run / execute backfill for missing inventory_batch_id on batch-tracked movements.
 */
class MissingBatchBackfillService
{
    public const STRATEGY_ALREADY_LINKED = 'already_linked';

    public const STRATEGY_LINK_EXISTING = 'link_existing_batch';

    public const STRATEGY_CREATE_FROM_SOURCE = 'create_from_source';

    public const STRATEGY_LEGACY_PLACEHOLDER = 'legacy_placeholder';

    public const STRATEGY_AMBIGUOUS = 'ambiguous';

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function preview(array $options = []): array
    {
        $movements = $this->missingMovements($options);
        $items = [];
        $counts = [
            'deterministic_recoverable' => 0,
            'legacy_governance_candidates' => 0,
            'ambiguous_manual' => 0,
        ];

        foreach ($movements as $movement) {
            $plan = $this->classifyMovement($movement, allowLegacy: ! ($options['no_legacy_placeholder'] ?? false));
            $items[] = $plan;

            if (in_array($plan['strategy'], [self::STRATEGY_LINK_EXISTING, self::STRATEGY_CREATE_FROM_SOURCE], true)) {
                $counts['deterministic_recoverable']++;
            } elseif ($plan['strategy'] === self::STRATEGY_LEGACY_PLACEHOLDER) {
                $counts['legacy_governance_candidates']++;
            } elseif ($plan['strategy'] === self::STRATEGY_AMBIGUOUS) {
                $counts['ambiguous_manual']++;
            }
        }

        return [
            'batch_tracked_movements' => app(BatchGovernanceAuditService::class)->countBatchTrackedMovements(),
            'missing_count' => count($items),
            'deterministic_recoverable' => $counts['deterministic_recoverable'],
            'legacy_governance_candidates' => $counts['legacy_governance_candidates'],
            'ambiguous_manual' => $counts['ambiguous_manual'],
            'items' => $items,
        ];
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
            'scanned' => $preview['missing_count'],
            'skipped_already_linked' => 0,
            'linked_existing_batch' => 0,
            'created_batch' => 0,
            'legacy_placeholder_batch' => 0,
            'ambiguous_skipped' => 0,
            'errors' => 0,
            'items' => [],
        ];

        if (! $execute) {
            $summary['items'] = $preview['items'];

            return $summary;
        }

        DB::transaction(function () use ($options, $preview, &$summary) {
            foreach ($preview['items'] as $plan) {
                if ($plan['strategy'] === self::STRATEGY_ALREADY_LINKED) {
                    $summary['skipped_already_linked']++;

                    continue;
                }

                if ($plan['strategy'] === self::STRATEGY_AMBIGUOUS) {
                    $summary['ambiguous_skipped']++;
                    $summary['items'][] = $plan;

                    continue;
                }

                if (($options['fail_on_ambiguous'] ?? false) && $plan['strategy'] === self::STRATEGY_AMBIGUOUS) {
                    throw new \RuntimeException('Ambiguous movement '.$plan['movement_id'].' blocked by --fail-on-ambiguous.');
                }

                try {
                    $result = $this->applyPlan($plan);
                    $summary['items'][] = $result;

                    match ($result['strategy']) {
                        self::STRATEGY_LINK_EXISTING => $summary['linked_existing_batch']++,
                        self::STRATEGY_CREATE_FROM_SOURCE => $summary['created_batch']++,
                        self::STRATEGY_LEGACY_PLACEHOLDER => $summary['legacy_placeholder_batch']++,
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

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return Collection<int, object>
     */
    private function missingMovements(array $options): Collection
    {
        $query = DB::table('trx_inventory_movements as m')
            ->join('inv_products as p', 'p.id', '=', 'm.product_id')
            ->where('p.requires_batch_tracking', true)
            ->whereNull('m.inventory_batch_id')
            ->where(function ($q) {
                $q->where('m.quantity_in', '>', 0)
                    ->orWhere('m.quantity_out', '>', 0);
            })
            ->select([
                'm.id',
                'm.branch_id',
                'm.product_id',
                'm.inventory_location_id',
                'm.supplier_id',
                'm.movement_type',
                'm.movement_date',
                'm.quantity_in',
                'm.quantity_out',
                'm.reference_type',
                'm.reference_id',
            ])
            ->orderBy('m.id');

        if (! empty($options['movement_id'])) {
            $query->where('m.id', (int) $options['movement_id']);
        }

        if (! empty($options['limit'])) {
            $query->limit((int) $options['limit']);
        }

        return $query->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function classifyMovement(object $movement, bool $allowLegacy = true): array
    {
        $movementId = (int) $movement->id;

        if (InventoryMovement::query()->whereKey($movementId)->whereNotNull('inventory_batch_id')->exists()) {
            return $this->plan($movementId, self::STRATEGY_ALREADY_LINKED, null, 'Movement already linked.');
        }

        if (Schema::hasTable('trx_inventory_batch_backfill_logs')
            && InventoryBatchBackfillLog::query()->where('inventory_movement_id', $movementId)->exists()) {
            return $this->plan($movementId, self::STRATEGY_ALREADY_LINKED, null, 'Backfill log exists; idempotent skip.');
        }

        $fromGrItem = $this->resolveFromGoodsReceiptItem($movement);
        if ($fromGrItem !== null) {
            return $fromGrItem;
        }

        $fromTransfer = $this->resolveFromTransferItem($movement);
        if ($fromTransfer !== null) {
            return $fromTransfer;
        }

        $fromOpname = $this->resolveFromOpnameItem($movement);
        if ($fromOpname !== null) {
            return $fromOpname;
        }

        $singleBatch = $this->resolveSingleBatchCandidate($movement);
        if ($singleBatch !== null) {
            return $singleBatch;
        }

        if ($allowLegacy) {
            return $this->plan(
                $movementId,
                self::STRATEGY_LEGACY_PLACEHOLDER,
                null,
                'No recoverable batch identity; legacy governance placeholder candidate.',
                [
                    'legacy_batch_number' => $this->legacyBatchNumber($movement),
                ],
            );
        }

        return $this->plan($movementId, self::STRATEGY_AMBIGUOUS, null, 'No deterministic batch recovery path.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveFromGoodsReceiptItem(object $movement): ?array
    {
        if (! Schema::hasTable('trx_goods_receipt_items')) {
            return null;
        }

        $movementId = (int) $movement->id;

        $direct = GoodsReceiptItem::query()
            ->where(function ($q) use ($movementId) {
                $q->where('inventory_movement_id', $movementId)
                    ->orWhere('reversal_movement_id', $movementId);
            })
            ->first();

        if ($direct !== null) {
            if ($direct->inventory_batch_id) {
                return $this->plan(
                    $movementId,
                    self::STRATEGY_LINK_EXISTING,
                    (int) $direct->inventory_batch_id,
                    'Linked from goods receipt item batch.',
                    ['source' => 'trx_goods_receipt_items', 'item_id' => $direct->id],
                );
            }

            if (filled($direct->batch_number)) {
                return $this->plan(
                    $movementId,
                    self::STRATEGY_CREATE_FROM_SOURCE,
                    null,
                    'Create batch from goods receipt item batch fields.',
                    [
                        'source' => 'trx_goods_receipt_items',
                        'item_id' => $direct->id,
                        'batch_number' => $direct->batch_number,
                        'lot_number' => $direct->lot_number,
                        'expiry_date' => $direct->expiry_date?->toDateString(),
                        'received_date' => $direct->batch_received_date?->toDateString(),
                    ],
                );
            }
        }

        $grTable = (new GoodsReceipt)->getTable();
        if ($movement->reference_type !== $grTable || empty($movement->reference_id)) {
            return null;
        }

        $candidates = GoodsReceiptItem::query()
            ->where('goods_receipt_id', (int) $movement->reference_id)
            ->where('product_id', (int) $movement->product_id)
            ->where('inventory_location_id', (int) $movement->inventory_location_id)
            ->get();

        if ($candidates->count() === 1) {
            $item = $candidates->first();
            if ($item->inventory_batch_id) {
                return $this->plan(
                    $movementId,
                    self::STRATEGY_LINK_EXISTING,
                    (int) $item->inventory_batch_id,
                    'Linked from matching goods receipt line.',
                    ['source' => 'trx_goods_receipt_items', 'item_id' => $item->id],
                );
            }

            if (filled($item->batch_number)) {
                return $this->plan(
                    $movementId,
                    self::STRATEGY_CREATE_FROM_SOURCE,
                    null,
                    'Create batch from matching goods receipt line.',
                    [
                        'source' => 'trx_goods_receipt_items',
                        'item_id' => $item->id,
                        'batch_number' => $item->batch_number,
                        'lot_number' => $item->lot_number,
                        'expiry_date' => $item->expiry_date?->toDateString(),
                        'received_date' => $item->batch_received_date?->toDateString(),
                    ],
                );
            }
        }

        if ($candidates->count() > 1) {
            return $this->plan($movementId, self::STRATEGY_AMBIGUOUS, null, 'Multiple goods receipt lines match movement.');
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveFromTransferItem(object $movement): ?array
    {
        if (! Schema::hasTable('trx_stock_transfer_items')) {
            return null;
        }

        $transferTable = (new StockTransfer)->getTable();
        if ($movement->reference_type !== $transferTable || empty($movement->reference_id)) {
            return null;
        }

        $candidates = StockTransferItem::query()
            ->where('stock_transfer_id', (int) $movement->reference_id)
            ->where('product_id', (int) $movement->product_id)
            ->get();

        $withBatch = $candidates->filter(fn ($item) => $item->inventory_batch_id !== null);

        if ($withBatch->count() === 1) {
            $item = $withBatch->first();

            return $this->plan(
                (int) $movement->id,
                self::STRATEGY_LINK_EXISTING,
                (int) $item->inventory_batch_id,
                'Linked from stock transfer item batch.',
                ['source' => 'trx_stock_transfer_items', 'item_id' => $item->id],
            );
        }

        if ($withBatch->count() > 1) {
            $qty = (float) $movement->quantity_in > 0 ? (float) $movement->quantity_in : (float) $movement->quantity_out;
            $qtyMatch = $withBatch->filter(fn ($item) => abs((float) $item->quantity - $qty) < 0.0001);

            if ($qtyMatch->count() === 1) {
                $item = $qtyMatch->first();

                return $this->plan(
                    (int) $movement->id,
                    self::STRATEGY_LINK_EXISTING,
                    (int) $item->inventory_batch_id,
                    'Linked from stock transfer item by quantity match.',
                    ['source' => 'trx_stock_transfer_items', 'item_id' => $item->id],
                );
            }

            return $this->plan((int) $movement->id, self::STRATEGY_AMBIGUOUS, null, 'Multiple transfer item batch candidates.');
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveFromOpnameItem(object $movement): ?array
    {
        if (! Schema::hasTable('trx_stock_opname_items')) {
            return null;
        }

        $opnameTable = (new StockOpname)->getTable();
        if ($movement->reference_type !== $opnameTable || empty($movement->reference_id)) {
            return null;
        }

        $candidates = StockOpnameItem::query()
            ->where('stock_opname_id', (int) $movement->reference_id)
            ->where('product_id', (int) $movement->product_id)
            ->whereNotNull('inventory_batch_id')
            ->get();

        if ($candidates->count() === 1) {
            $item = $candidates->first();

            return $this->plan(
                (int) $movement->id,
                self::STRATEGY_LINK_EXISTING,
                (int) $item->inventory_batch_id,
                'Linked from stock opname item batch.',
                ['source' => 'trx_stock_opname_items', 'item_id' => $item->id],
            );
        }

        if ($candidates->count() > 1) {
            return $this->plan((int) $movement->id, self::STRATEGY_AMBIGUOUS, null, 'Multiple opname item batch candidates.');
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveSingleBatchCandidate(object $movement): ?array
    {
        $batches = InventoryBatch::query()
            ->where('branch_id', (int) $movement->branch_id)
            ->where('product_id', (int) $movement->product_id)
            ->get();

        if ($batches->count() === 1) {
            return $this->plan(
                (int) $movement->id,
                self::STRATEGY_LINK_EXISTING,
                (int) $batches->first()->id,
                'Single existing batch candidate for product and branch.',
                ['source' => 'inv_inventory_batches'],
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function applyPlan(array $plan): array
    {
        $movementId = (int) $plan['movement_id'];
        $movement = InventoryMovement::query()->lockForUpdate()->findOrFail($movementId);

        if ($movement->inventory_batch_id !== null) {
            return array_merge($plan, ['applied' => false, 'reason' => 'already_linked']);
        }

        $batchId = $plan['target_batch_id'] ?? null;

        if ($batchId === null && $plan['strategy'] === self::STRATEGY_CREATE_FROM_SOURCE) {
            $batchId = $this->createBatchFromSource($movement, $plan['evidence'] ?? []);
        }

        if ($batchId === null && $plan['strategy'] === self::STRATEGY_LEGACY_PLACEHOLDER) {
            $batchId = $this->createLegacyPlaceholderBatch($movement);
        }

        if ($batchId === null) {
            throw new \RuntimeException('No batch resolved for movement '.$movementId);
        }

        $movement->update(['inventory_batch_id' => $batchId]);

        if (Schema::hasTable('trx_inventory_batch_backfill_logs')) {
            InventoryBatchBackfillLog::query()->updateOrCreate(
                ['inventory_movement_id' => $movementId],
                [
                    'inventory_batch_id' => $batchId,
                    'strategy' => (string) $plan['strategy'],
                    'command' => config('inventory_batch_governance.backfill_command'),
                    'evidence' => $plan['evidence'] ?? [],
                    'executed_at' => now(),
                ],
            );
        }

        return array_merge($plan, [
            'applied' => true,
            'inventory_batch_id' => $batchId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function createBatchFromSource(InventoryMovement $movement, array $evidence): int
    {
        $batchNumber = trim((string) ($evidence['batch_number'] ?? ''));
        if ($batchNumber === '') {
            throw new \RuntimeException('Source batch number missing.');
        }

        $lotNumber = isset($evidence['lot_number']) && $evidence['lot_number'] !== ''
            ? trim((string) $evidence['lot_number'])
            : null;

        $existing = InventoryBatch::query()
            ->where('branch_id', $movement->branch_id)
            ->where('product_id', $movement->product_id)
            ->where('batch_number', $batchNumber)
            ->where(function ($q) use ($lotNumber) {
                if ($lotNumber === null) {
                    $q->whereNull('lot_number');
                } else {
                    $q->where('lot_number', $lotNumber);
                }
            })
            ->first();

        if ($existing) {
            return (int) $existing->id;
        }

        $batch = InventoryBatch::create([
            'branch_id' => $movement->branch_id,
            'product_id' => $movement->product_id,
            'supplier_id' => $movement->supplier_id,
            'batch_number' => $batchNumber,
            'lot_number' => $lotNumber,
            'expiry_date' => $evidence['expiry_date'] ?? null,
            'received_date' => $evidence['received_date'] ?? $movement->movement_date?->toDateString(),
            'notes' => 'DQ-2 backfill from source document.',
            'backfill_source' => 'dq2_source_document',
            'backfilled_at' => now(),
            'is_active' => true,
            'created_by' => null,
        ]);

        return (int) $batch->id;
    }

    private function createLegacyPlaceholderBatch(InventoryMovement $movement): int
    {
        $batchNumber = $this->legacyBatchNumber($movement);

        $existing = InventoryBatch::query()
            ->where('branch_id', $movement->branch_id)
            ->where('product_id', $movement->product_id)
            ->where('batch_number', $batchNumber)
            ->whereNull('lot_number')
            ->first();

        if ($existing) {
            return (int) $existing->id;
        }

        $batch = InventoryBatch::create([
            'branch_id' => $movement->branch_id,
            'product_id' => $movement->product_id,
            'supplier_id' => $movement->supplier_id,
            'batch_number' => $batchNumber,
            'lot_number' => null,
            'expiry_date' => null,
            'received_date' => $movement->movement_date?->toDateString(),
            'notes' => 'DQ-2 legacy governance placeholder — not a manufacturer lot.',
            'backfill_source' => 'dq2_legacy_placeholder',
            'backfilled_at' => now(),
            'is_active' => true,
            'created_by' => null,
        ]);

        return (int) $batch->id;
    }

    private function legacyBatchNumber(InventoryMovement|\stdClass $movement): string
    {
        $prefix = (string) config('inventory_batch_governance.legacy_batch_prefix', 'LEGACY-DQ2');
        $date = $movement instanceof InventoryMovement
            ? $movement->movement_date?->format('Ymd')
            : (string) ($movement->movement_date ?? now()->format('Y-m-d'));
        $datePart = str_replace('-', '', $date);

        return sprintf('%s-%d-%d-%s-%d', $prefix, (int) $movement->product_id, (int) $movement->branch_id, $datePart, (int) $movement->id);
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function plan(
        int $movementId,
        string $strategy,
        ?int $targetBatchId,
        string $reason,
        array $evidence = [],
    ): array {
        return [
            'movement_id' => $movementId,
            'strategy' => $strategy,
            'target_batch_id' => $targetBatchId,
            'reason' => $reason,
            'evidence' => $evidence,
        ];
    }
}
