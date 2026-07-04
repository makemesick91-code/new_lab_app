<?php

namespace App\Services\Inventory;

use App\Services\DataQuality\Dq1AuditService;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only DQ-2 inventory batch governance audit.
 */
class BatchGovernanceAuditService
{
    public function __construct(
        private readonly Dq1AuditService $dq1Audit,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function audit(array $options = []): array
    {
        $checks = [
            $this->checkSchemaAndFlags(),
            $this->checkMissingBatchOnMovements(),
            $this->checkBatchProductMismatch(),
            $this->checkBatchBranchMismatch(),
            $this->checkOrphanBatchReferences(),
            $this->checkMovementDirection(),
            $this->checkTransferBatchLinkage(),
            $this->checkGoodsReceiptBatchLinkage(),
            $this->checkOpnameBatchLinkage(),
            $this->checkDq1Compatibility(),
        ];

        $classification = $this->classifyBackfillCandidates();

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
                'sprint' => config('inventory_batch_governance.sprint', 'DQ-2'),
                'version' => config('inventory_batch_governance.version', 'DQ-2'),
            ],
            'summary' => [
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
                'decision' => $this->decision($errors, $warnings),
                'batch_tracked_movements' => $classification['batch_tracked_movements'],
                'missing_inventory_batch_id' => $classification['missing_inventory_batch_id'],
                'deterministic_recoverable' => $classification['deterministic_recoverable'],
                'legacy_governance_candidates' => $classification['legacy_governance_candidates'],
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
    public function classifyBackfillCandidates(): array
    {
        $backfill = app(MissingBatchBackfillService::class);
        $preview = $backfill->preview();

        return [
            'batch_tracked_movements' => $preview['batch_tracked_movements'],
            'missing_inventory_batch_id' => $preview['missing_count'],
            'deterministic_recoverable' => $preview['deterministic_recoverable'],
            'legacy_governance_candidates' => $preview['legacy_governance_candidates'],
            'ambiguous_manual' => $preview['ambiguous_manual'],
            'items' => $preview['items'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkSchemaAndFlags(): array
    {
        $ok = Schema::hasTable('inv_inventory_batches')
            && Schema::hasTable('trx_inventory_movements')
            && Schema::hasColumn('trx_inventory_movements', 'inventory_batch_id')
            && Schema::hasColumn('inv_products', 'requires_batch_tracking');

        return $this->checkResult(
            'DQ2-BATCH-001',
            'SCHEMA',
            (string) config('inventory_batch_governance.checks.DQ2-BATCH-001.title'),
            $ok ? 'PASS' : 'FAIL',
            'error',
            $ok
                ? 'Batch governance schema and product flags are present.'
                : 'Required batch governance schema columns are missing.',
            [
                'batch_table' => Schema::hasTable('inv_inventory_batches'),
                'movement_batch_column' => Schema::hasColumn('trx_inventory_movements', 'inventory_batch_id'),
                'product_flag_column' => Schema::hasColumn('inv_products', 'requires_batch_tracking'),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkMissingBatchOnMovements(): array
    {
        $count = $this->countBatchTrackedMovementsMissingBatch();

        return $this->checkResult(
            'DQ2-BATCH-002',
            'DATA',
            (string) config('inventory_batch_governance.checks.DQ2-BATCH-002.title'),
            $count === 0 ? 'PASS' : 'WARN',
            'warning',
            $count === 0
                ? 'All batch-tracked movements have inventory_batch_id.'
                : "{$count} batch-tracked movement(s) missing inventory_batch_id.",
            ['missing_count' => $count],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkBatchProductMismatch(): array
    {
        if (! $this->movementBatchJoinReady()) {
            return $this->skippedCheck('DQ2-BATCH-003', 'Movement batch product match');
        }

        $count = (int) DB::table('trx_inventory_movements as m')
            ->join('inv_inventory_batches as b', 'b.id', '=', 'm.inventory_batch_id')
            ->whereNotNull('m.inventory_batch_id')
            ->whereColumn('m.product_id', '!=', 'b.product_id')
            ->count();

        return $this->checkResult(
            'DQ2-BATCH-003',
            'DATA',
            (string) config('inventory_batch_governance.checks.DQ2-BATCH-003.title'),
            $count === 0 ? 'PASS' : 'FAIL',
            'error',
            $count === 0
                ? 'All movement batch links match product.'
                : "{$count} movement(s) reference batch for a different product.",
            ['mismatch_count' => $count],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkBatchBranchMismatch(): array
    {
        if (! $this->movementBatchJoinReady()) {
            return $this->skippedCheck('DQ2-BATCH-004', 'Movement batch branch scope');
        }

        $count = (int) DB::table('trx_inventory_movements as m')
            ->join('inv_inventory_batches as b', 'b.id', '=', 'm.inventory_batch_id')
            ->whereNotNull('m.inventory_batch_id')
            ->whereColumn('m.branch_id', '!=', 'b.branch_id')
            ->count();

        return $this->checkResult(
            'DQ2-BATCH-004',
            'DATA',
            (string) config('inventory_batch_governance.checks.DQ2-BATCH-004.title'),
            $count === 0 ? 'PASS' : 'FAIL',
            'error',
            $count === 0
                ? 'Movement batch branch scope is compatible.'
                : "{$count} movement(s) reference batch from another branch.",
            ['mismatch_count' => $count],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkOrphanBatchReferences(): array
    {
        if (! Schema::hasColumn('trx_inventory_movements', 'inventory_batch_id')) {
            return $this->skippedCheck('DQ2-BATCH-005', 'Orphan inventory_batch_id');
        }

        $count = (int) DB::table('trx_inventory_movements as m')
            ->leftJoin('inv_inventory_batches as b', 'b.id', '=', 'm.inventory_batch_id')
            ->whereNotNull('m.inventory_batch_id')
            ->whereNull('b.id')
            ->count();

        return $this->checkResult(
            'DQ2-BATCH-005',
            'DATA',
            (string) config('inventory_batch_governance.checks.DQ2-BATCH-005.title'),
            $count === 0 ? 'PASS' : 'FAIL',
            'error',
            $count === 0
                ? 'No orphan inventory_batch_id on movements.'
                : "{$count} movement(s) reference missing batch rows.",
            ['orphan_count' => $count],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkMovementDirection(): array
    {
        $count = $this->dq1Audit->countInvalidInventoryMovements();

        return $this->checkResult(
            'DQ2-BATCH-006',
            'DATA',
            (string) config('inventory_batch_governance.checks.DQ2-BATCH-006.title'),
            $count === 0 ? 'PASS' : 'FAIL',
            'error',
            $count === 0
                ? 'Movement quantity direction is valid.'
                : "{$count} movement(s) violate quantity direction rules.",
            ['invalid_direction' => $count],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkTransferBatchLinkage(): array
    {
        if (! Schema::hasTable('trx_stock_transfer_items')
            || ! Schema::hasColumn('trx_stock_transfer_items', 'inventory_batch_id')) {
            return $this->skippedCheck('DQ2-BATCH-007', 'Transfer batch linkage');
        }

        $count = (int) DB::table('trx_stock_transfer_items as i')
            ->join('inv_products as p', 'p.id', '=', 'i.product_id')
            ->where('p.requires_batch_tracking', true)
            ->whereNull('i.inventory_batch_id')
            ->count();

        return $this->checkResult(
            'DQ2-BATCH-007',
            'DATA',
            (string) config('inventory_batch_governance.checks.DQ2-BATCH-007.title'),
            $count === 0 ? 'PASS' : 'WARN',
            'warning',
            $count === 0
                ? 'Batch-tracked transfer items have inventory_batch_id.'
                : "{$count} batch-tracked transfer item(s) missing inventory_batch_id.",
            ['missing_count' => $count],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkGoodsReceiptBatchLinkage(): array
    {
        if (! Schema::hasTable('trx_goods_receipt_items')
            || ! Schema::hasColumn('trx_goods_receipt_items', 'inventory_batch_id')) {
            return $this->skippedCheck('DQ2-BATCH-008', 'Goods receipt batch linkage');
        }

        $count = (int) DB::table('trx_goods_receipt_items as i')
            ->join('inv_products as p', 'p.id', '=', 'i.product_id')
            ->where('p.requires_batch_tracking', true)
            ->where('i.accepted_qty', '>', 0)
            ->whereNull('i.inventory_batch_id')
            ->count();

        return $this->checkResult(
            'DQ2-BATCH-008',
            'DATA',
            (string) config('inventory_batch_governance.checks.DQ2-BATCH-008.title'),
            $count === 0 ? 'PASS' : 'WARN',
            'warning',
            $count === 0
                ? 'Posted goods receipt items preserve batch identity.'
                : "{$count} batch-tracked GR item(s) missing inventory_batch_id.",
            ['missing_count' => $count],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkOpnameBatchLinkage(): array
    {
        if (! Schema::hasTable('trx_stock_opname_items')
            || ! Schema::hasColumn('trx_stock_opname_items', 'inventory_batch_id')) {
            return $this->skippedCheck('DQ2-BATCH-009', 'Stock opname batch linkage');
        }

        $count = (int) DB::table('trx_stock_opname_items as i')
            ->join('inv_products as p', 'p.id', '=', 'i.product_id')
            ->where('p.requires_batch_tracking', true)
            ->where(function ($query) {
                $query->where('i.variance_quantity', '!=', 0);
            })
            ->whereNull('i.inventory_batch_id')
            ->count();

        return $this->checkResult(
            'DQ2-BATCH-009',
            'DATA',
            (string) config('inventory_batch_governance.checks.DQ2-BATCH-009.title'),
            $count === 0 ? 'PASS' : 'WARN',
            'warning',
            $count === 0
                ? 'Stock opname batch adjustments preserve batch identity.'
                : "{$count} batch-tracked opname item(s) missing inventory_batch_id.",
            ['missing_count' => $count],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDq1Compatibility(): array
    {
        $invalid = $this->dq1Audit->countInvalidInventoryMovements();
        $missing = $this->countBatchTrackedMovementsMissingBatch();

        $status = 'PASS';
        $severity = 'warning';
        $message = 'DQ1-DATA-006 batch governance is clean.';

        if ($invalid > 0) {
            $status = 'FAIL';
            $severity = 'error';
            $message = "DQ1-DATA-006 would FAIL due to {$invalid} invalid direction movement(s).";
        } elseif ($missing > 0) {
            $status = 'WARN';
            $message = "DQ1-DATA-006 would WARN with {$missing} batch-tracked movement(s) missing inventory_batch_id.";
        }

        return $this->checkResult(
            'DQ2-BATCH-010',
            'COMPAT',
            (string) config('inventory_batch_governance.checks.DQ2-BATCH-010.title'),
            $status,
            $severity,
            $message,
            [
                'invalid_direction' => $invalid,
                'batch_missing' => $missing,
                'dq1_check_id' => 'DQ1-DATA-006',
            ],
        );
    }

    public function countBatchTrackedMovementsMissingBatch(): int
    {
        if (! Schema::hasTable('trx_inventory_movements')
            || ! Schema::hasTable('inv_products')
            || ! Schema::hasColumn('trx_inventory_movements', 'inventory_batch_id')
            || ! Schema::hasColumn('inv_products', 'requires_batch_tracking')) {
            return 0;
        }

        return (int) DB::table('trx_inventory_movements as m')
            ->join('inv_products as p', 'p.id', '=', 'm.product_id')
            ->where('p.requires_batch_tracking', true)
            ->whereNull('m.inventory_batch_id')
            ->where(function ($query) {
                $query->where('m.quantity_in', '>', 0)
                    ->orWhere('m.quantity_out', '>', 0);
            })
            ->count();
    }

    public function countBatchTrackedMovements(): int
    {
        if (! Schema::hasTable('trx_inventory_movements') || ! Schema::hasTable('inv_products')) {
            return 0;
        }

        return (int) DB::table('trx_inventory_movements as m')
            ->join('inv_products as p', 'p.id', '=', 'm.product_id')
            ->where('p.requires_batch_tracking', true)
            ->where(function ($query) {
                $query->where('m.quantity_in', '>', 0)
                    ->orWhere('m.quantity_out', '>', 0);
            })
            ->count();
    }

    private function movementBatchJoinReady(): bool
    {
        return Schema::hasTable('trx_inventory_movements')
            && Schema::hasTable('inv_inventory_batches')
            && Schema::hasColumn('trx_inventory_movements', 'inventory_batch_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function skippedCheck(string $checkId, string $title): array
    {
        return $this->checkResult(
            $checkId,
            'DATA',
            $title,
            'PASS',
            'warning',
            'Check skipped — required schema not present.',
            ['skipped' => true],
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
