<?php

namespace App\Services\Inventory;

use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use App\Modules\Inventory\Support\SourceDocumentBatchGuard;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only DQ-3 source-document batch linkage audit.
 */
class SourceDocumentBatchAuditService
{
    public function __construct(
        private readonly BatchGovernanceAuditService $dq2Audit,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function audit(array $options = []): array
    {
        $counts = $this->countMissingBySource();
        $classification = $this->classifyBackfillCandidates();

        $checks = [
            $this->checkGoodsReceiptItems($counts),
            $this->checkTransferItems($counts),
            $this->checkOpnameItems($counts),
            $this->checkBatchProductMismatch(),
            $this->checkBatchBranchMismatch(),
            $this->checkSourceMovementConsistency(),
            $this->checkTransferLineageCoherence(),
            $this->checkOrphanBatchReferences(),
            $this->checkDq2Compatibility(),
            $this->checkGuardRegistered(),
        ];

        $passed = collect($checks)->where('status', 'PASS')->count();
        $warnings = collect($checks)->where('status', 'WARN')->count();
        $errors = collect($checks)->where('status', 'FAIL')->count();

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            'metadata' => [
                'app_name' => (string) config('app.name'),
                'laravel_version' => Application::VERSION,
                'php_version' => PHP_VERSION,
                'database_driver' => (string) config('database.default'),
                'sprint' => config('inventory_source_document_batch_governance.sprint', 'DQ-3'),
                'version' => config('inventory_source_document_batch_governance.version', 'DQ-3'),
            ],
            'summary' => [
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
                'decision' => $this->decision($errors, $warnings),
                'goods_receipt' => $counts['goods_receipt'],
                'stock_transfer' => $counts['stock_transfer'],
                'stock_opname' => $counts['stock_opname'],
                'total_missing' => $counts['total_missing'],
                'deterministic_recoverable' => $classification['deterministic_recoverable'],
                'ambiguous_manual' => $classification['ambiguous_manual'],
            ],
            'backfill_preview' => $classification,
            'checks' => $checks,
            'privacy' => [
                'privacy_safe' => true,
                'row_level_data' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function countMissingBySource(): array
    {
        $gr = $this->countMissingGoodsReceiptItems();
        $transfer = $this->countMissingTransferItems();
        $opname = $this->countMissingOpnameItems();

        return [
            'goods_receipt' => [
                'total_batch_tracked' => $gr['total'],
                'missing' => $gr['missing'],
                'linked' => max(0, $gr['total'] - $gr['missing']),
            ],
            'stock_transfer' => [
                'total_batch_tracked' => $transfer['total'],
                'missing' => $transfer['missing'],
                'linked' => max(0, $transfer['total'] - $transfer['missing']),
            ],
            'stock_opname' => [
                'total_batch_tracked' => $opname['total'],
                'missing' => $opname['missing'],
                'linked' => max(0, $opname['total'] - $opname['missing']),
            ],
            'total_missing' => $gr['missing'] + $transfer['missing'] + $opname['missing'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function classifyBackfillCandidates(): array
    {
        $preview = app(SourceDocumentBatchBackfillService::class)->preview();

        return [
            'total_missing' => $preview['scanned'] ?? 0,
            'deterministic_recoverable' => ($preview['linked_from_movement'] ?? 0)
                + ($preview['linked_from_source_batch_fields'] ?? 0)
                + ($preview['linked_legacy_placeholder_from_movement'] ?? 0),
            'ambiguous_manual' => $preview['ambiguous_skipped'] ?? 0,
            'items' => $preview['items'] ?? [],
        ];
    }

    /**
     * @return array{total: int, missing: int}
     */
    public function countMissingGoodsReceiptItems(): array
    {
        if (! Schema::hasTable('trx_goods_receipt_items')
            || ! Schema::hasColumn('trx_goods_receipt_items', 'inventory_batch_id')) {
            return ['total' => 0, 'missing' => 0];
        }

        $base = DB::table('trx_goods_receipt_items as i')
            ->join('inv_products as p', 'p.id', '=', 'i.product_id')
            ->where('p.requires_batch_tracking', true)
            ->where('i.accepted_qty', '>', 0);

        return [
            'total' => (int) (clone $base)->count(),
            'missing' => (int) (clone $base)->whereNull('i.inventory_batch_id')->count(),
        ];
    }

    /**
     * @return array{total: int, missing: int}
     */
    public function countMissingTransferItems(): array
    {
        if (! Schema::hasTable('trx_stock_transfer_items')
            || ! Schema::hasColumn('trx_stock_transfer_items', 'inventory_batch_id')) {
            return ['total' => 0, 'missing' => 0];
        }

        $base = DB::table('trx_stock_transfer_items as i')
            ->join('inv_products as p', 'p.id', '=', 'i.product_id')
            ->where('p.requires_batch_tracking', true);

        return [
            'total' => (int) (clone $base)->count(),
            'missing' => (int) (clone $base)->whereNull('i.inventory_batch_id')->count(),
        ];
    }

    /**
     * @return array{total: int, missing: int}
     */
    public function countMissingOpnameItems(): array
    {
        if (! Schema::hasTable('trx_stock_opname_items')
            || ! Schema::hasColumn('trx_stock_opname_items', 'inventory_batch_id')) {
            return ['total' => 0, 'missing' => 0];
        }

        $base = DB::table('trx_stock_opname_items as i')
            ->join('inv_products as p', 'p.id', '=', 'i.product_id')
            ->where('p.requires_batch_tracking', true)
            ->where('i.variance_quantity', '!=', 0);

        return [
            'total' => (int) (clone $base)->count(),
            'missing' => (int) (clone $base)->whereNull('i.inventory_batch_id')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $counts
     * @return array<string, mixed>
     */
    private function checkGoodsReceiptItems(array $counts): array
    {
        $missing = (int) ($counts['goods_receipt']['missing'] ?? 0);

        return $this->checkResult(
            'DQ3-SRC-001',
            'DATA',
            (string) config('inventory_source_document_batch_governance.checks.DQ3-SRC-001.title'),
            $missing === 0 ? 'PASS' : 'WARN',
            'warning',
            $missing === 0
                ? 'Batch-tracked goods receipt items have inventory_batch_id.'
                : "{$missing} batch-tracked GR item(s) missing inventory_batch_id.",
            ['missing_count' => $missing],
        );
    }

    /**
     * @param  array<string, mixed>  $counts
     * @return array<string, mixed>
     */
    private function checkTransferItems(array $counts): array
    {
        $missing = (int) ($counts['stock_transfer']['missing'] ?? 0);

        return $this->checkResult(
            'DQ3-SRC-002',
            'DATA',
            (string) config('inventory_source_document_batch_governance.checks.DQ3-SRC-002.title'),
            $missing === 0 ? 'PASS' : 'WARN',
            'warning',
            $missing === 0
                ? 'Batch-tracked transfer items have inventory_batch_id.'
                : "{$missing} batch-tracked transfer item(s) missing inventory_batch_id.",
            ['missing_count' => $missing],
        );
    }

    /**
     * @param  array<string, mixed>  $counts
     * @return array<string, mixed>
     */
    private function checkOpnameItems(array $counts): array
    {
        $missing = (int) ($counts['stock_opname']['missing'] ?? 0);

        return $this->checkResult(
            'DQ3-SRC-003',
            'DATA',
            (string) config('inventory_source_document_batch_governance.checks.DQ3-SRC-003.title'),
            $missing === 0 ? 'PASS' : 'WARN',
            'warning',
            $missing === 0
                ? 'Batch-tracked opname items with variance have inventory_batch_id.'
                : "{$missing} batch-tracked opname item(s) missing inventory_batch_id.",
            ['missing_count' => $missing],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkBatchProductMismatch(): array
    {
        $count = 0;

        foreach (['trx_goods_receipt_items', 'trx_stock_transfer_items', 'trx_stock_opname_items'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'inventory_batch_id')) {
                continue;
            }

            $count += (int) DB::table("{$table} as i")
                ->join('inv_inventory_batches as b', 'b.id', '=', 'i.inventory_batch_id')
                ->whereNotNull('i.inventory_batch_id')
                ->whereColumn('b.product_id', '!=', 'i.product_id')
                ->count();
        }

        return $this->checkResult(
            'DQ3-SRC-004',
            'INTEGRITY',
            (string) config('inventory_source_document_batch_governance.checks.DQ3-SRC-004.title'),
            $count === 0 ? 'PASS' : 'FAIL',
            'error',
            $count === 0
                ? 'All linked source-item batches match product.'
                : "{$count} source item(s) point to batch with mismatched product.",
            ['mismatch_count' => $count],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkBatchBranchMismatch(): array
    {
        $count = 0;

        if (Schema::hasTable('trx_goods_receipt_items') && Schema::hasColumn('trx_goods_receipt_items', 'inventory_batch_id')) {
            $count += (int) DB::table('trx_goods_receipt_items as i')
                ->join('trx_goods_receipts as h', 'h.id', '=', 'i.goods_receipt_id')
                ->join('inv_inventory_batches as b', 'b.id', '=', 'i.inventory_batch_id')
                ->whereNotNull('i.inventory_batch_id')
                ->whereColumn('b.branch_id', '!=', 'h.branch_id')
                ->count();
        }

        if (Schema::hasTable('trx_stock_transfer_items') && Schema::hasColumn('trx_stock_transfer_items', 'inventory_batch_id')) {
            $count += (int) DB::table('trx_stock_transfer_items as i')
                ->join('trx_stock_transfers as h', 'h.id', '=', 'i.stock_transfer_id')
                ->join('inv_inventory_batches as b', 'b.id', '=', 'i.inventory_batch_id')
                ->whereNotNull('i.inventory_batch_id')
                ->whereColumn('b.branch_id', '!=', 'h.branch_id')
                ->count();
        }

        if (Schema::hasTable('trx_stock_opname_items') && Schema::hasColumn('trx_stock_opname_items', 'inventory_batch_id')) {
            $count += (int) DB::table('trx_stock_opname_items as i')
                ->join('trx_stock_opnames as h', 'h.id', '=', 'i.stock_opname_id')
                ->join('inv_inventory_batches as b', 'b.id', '=', 'i.inventory_batch_id')
                ->whereNotNull('i.inventory_batch_id')
                ->whereColumn('b.branch_id', '!=', 'h.branch_id')
                ->count();
        }

        return $this->checkResult(
            'DQ3-SRC-005',
            'INTEGRITY',
            (string) config('inventory_source_document_batch_governance.checks.DQ3-SRC-005.title'),
            $count === 0 ? 'PASS' : 'FAIL',
            'error',
            $count === 0
                ? 'Source-item batch branch scope is compatible.'
                : "{$count} source item(s) have batch branch mismatch.",
            ['mismatch_count' => $count],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkSourceMovementConsistency(): array
    {
        $inconsistent = 0;

        if (Schema::hasTable('trx_goods_receipt_items') && Schema::hasColumn('trx_goods_receipt_items', 'inventory_movement_id')) {
            $inconsistent += (int) DB::table('trx_goods_receipt_items as i')
                ->join('trx_inventory_movements as m', 'm.id', '=', 'i.inventory_movement_id')
                ->whereNotNull('i.inventory_batch_id')
                ->whereNotNull('m.inventory_batch_id')
                ->whereColumn('i.inventory_batch_id', '!=', 'm.inventory_batch_id')
                ->count();
        }

        return $this->checkResult(
            'DQ3-SRC-006',
            'CONSISTENCY',
            (string) config('inventory_source_document_batch_governance.checks.DQ3-SRC-006.title'),
            $inconsistent === 0 ? 'PASS' : 'WARN',
            'warning',
            $inconsistent === 0
                ? 'Source items and linked movements share batch identity.'
                : "{$inconsistent} source item(s) disagree with linked movement batch.",
            ['inconsistent_count' => $inconsistent],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkTransferLineageCoherence(): array
    {
        if (! Schema::hasTable('trx_stock_transfer_items')) {
            return $this->skippedCheck('DQ3-SRC-007', 'Transfer batch lineage');
        }

        $incoherent = 0;
        $transferTable = (new StockTransfer)->getTable();

        $items = StockTransferItem::query()
            ->whereNotNull('inventory_batch_id')
            ->with('product')
            ->limit(500)
            ->get();

        foreach ($items as $item) {
            if (! $item->product?->requires_batch_tracking) {
                continue;
            }

            $outMovements = InventoryMovement::query()
                ->where('reference_type', $transferTable)
                ->where('reference_id', $item->stock_transfer_id)
                ->where('product_id', $item->product_id)
                ->where('movement_type', InventoryMovement::TYPE_TRANSFER_OUT)
                ->whereNotNull('inventory_batch_id')
                ->pluck('inventory_batch_id')
                ->unique()
                ->values();

            if ($outMovements->count() > 1) {
                $incoherent++;

                continue;
            }

            if ($outMovements->count() === 1 && (int) $outMovements->first() !== (int) $item->inventory_batch_id) {
                $incoherent++;
            }
        }

        return $this->checkResult(
            'DQ3-SRC-007',
            'CONSISTENCY',
            (string) config('inventory_source_document_batch_governance.checks.DQ3-SRC-007.title'),
            $incoherent === 0 ? 'PASS' : 'WARN',
            'warning',
            $incoherent === 0
                ? 'Transfer source items align with outbound movement batches.'
                : "{$incoherent} transfer item(s) have incoherent batch lineage.",
            ['incoherent_count' => $incoherent],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkOrphanBatchReferences(): array
    {
        $orphan = 0;

        foreach (['trx_goods_receipt_items', 'trx_stock_transfer_items', 'trx_stock_opname_items'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'inventory_batch_id')) {
                continue;
            }

            $orphan += (int) DB::table("{$table} as i")
                ->leftJoin('inv_inventory_batches as b', 'b.id', '=', 'i.inventory_batch_id')
                ->whereNotNull('i.inventory_batch_id')
                ->whereNull('b.id')
                ->count();
        }

        return $this->checkResult(
            'DQ3-SRC-008',
            'INTEGRITY',
            (string) config('inventory_source_document_batch_governance.checks.DQ3-SRC-008.title'),
            $orphan === 0 ? 'PASS' : 'FAIL',
            'error',
            $orphan === 0
                ? 'No orphan inventory_batch_id on source-document items.'
                : "{$orphan} source item(s) reference missing batch rows.",
            ['orphan_count' => $orphan],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDq2Compatibility(): array
    {
        $dq2 = $this->dq2Audit->audit();
        $checkIds = ['DQ2-BATCH-007', 'DQ2-BATCH-008', 'DQ2-BATCH-009'];
        $warns = collect($dq2['checks'] ?? [])
            ->whereIn('check_id', $checkIds)
            ->where('status', 'WARN')
            ->count();

        return $this->checkResult(
            'DQ3-SRC-009',
            'COMPAT',
            (string) config('inventory_source_document_batch_governance.checks.DQ3-SRC-009.title'),
            $warns === 0 ? 'PASS' : 'WARN',
            'warning',
            $warns === 0
                ? 'DQ2-BATCH-007/008/009 source-document checks are clean.'
                : "DQ2 source-document checks still report {$warns} WARN.",
            ['dq2_source_warns' => $warns],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkGuardRegistered(): array
    {
        $exists = class_exists(SourceDocumentBatchGuard::class);

        return $this->checkResult(
            'DQ3-SRC-010',
            'GUARD',
            (string) config('inventory_source_document_batch_governance.checks.DQ3-SRC-010.title'),
            $exists ? 'PASS' : 'FAIL',
            'error',
            $exists
                ? 'SourceDocumentBatchGuard is registered.'
                : 'SourceDocumentBatchGuard class is missing.',
            ['guard_class' => SourceDocumentBatchGuard::class],
        );
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function checkResult(
        string $checkId,
        string $category,
        string $title,
        string $status,
        string $severity,
        string $message,
        array $details = [],
    ): array {
        return [
            'check_id' => $checkId,
            'category' => $category,
            'title' => $title,
            'status' => $status,
            'severity' => $severity,
            'message' => $message,
            'details' => $details,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function skippedCheck(string $checkId, string $title): array
    {
        return $this->checkResult(
            $checkId,
            'SCHEMA',
            $title,
            'PASS',
            'info',
            'Check skipped — required schema not present.',
        );
    }

    private function decision(int $errors, int $warnings): string
    {
        if ($errors > 0) {
            return 'NO-GO';
        }

        if ($warnings > 0) {
            return 'WATCH';
        }

        return 'GO';
    }
}
