<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Interfaces\InventoryBatchRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InventoryAlertService
{
    public const SEVERITY_OUT_OF_STOCK = 'out_of_stock';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_BATCH_EXPIRED = 'batch_expired';

    public const SEVERITY_BATCH_EXPIRING_SOON = 'batch_expiring_soon';

    public function __construct(
        private readonly InventoryMovementRepositoryInterface $movements,
        private readonly InventoryBatchRepositoryInterface $batches,
        private readonly InventoryLocationRepositoryInterface $locations,
        private readonly InventoryBatchService $batchService,
        private readonly BranchContext $branchContext,
    ) {}

    public function effectiveReorderPoint(Product $product): float
    {
        $reorderPoint = (float) ($product->reorder_point ?? 0);

        if ($reorderPoint > 0) {
            return $reorderPoint;
        }

        return (float) ($product->minimum_stock ?? 0);
    }

    public function classifyStockSeverity(Product $product, float $currentStock): ?string
    {
        if (! $product->is_active || ! $product->alert_enabled) {
            return null;
        }

        if ($currentStock <= 0) {
            return self::SEVERITY_OUT_OF_STOCK;
        }

        $minimumStock = (float) ($product->minimum_stock ?? 0);

        if ($minimumStock > 0 && $currentStock <= $minimumStock) {
            return self::SEVERITY_CRITICAL;
        }

        $reorderPoint = $this->effectiveReorderPoint($product);

        if ($reorderPoint > 0 && $currentStock <= $reorderPoint) {
            return self::SEVERITY_LOW;
        }

        return null;
    }

    public function classifyBatchSeverity(InventoryBatch $batch): ?string
    {
        if (! $batch->is_active || $batch->expiry_date === null) {
            return null;
        }

        if ((float) ($batch->derived_stock ?? 0) <= 0) {
            return null;
        }

        if ($this->batchService->isExpired($batch)) {
            return self::SEVERITY_BATCH_EXPIRED;
        }

        if ($this->batchService->isExpiringSoon($batch)) {
            return self::SEVERITY_BATCH_EXPIRING_SOON;
        }

        return null;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getStockAlerts(?int $locationId = null, ?string $severityFilter = null, ?int $limit = null): Collection
    {
        $branchId = $this->branchContext->requireId();
        $this->assertLocationInBranch($branchId, $locationId);

        $alerts = $this->movements
            ->productsWithDerivedStock($branchId, $locationId)
            ->map(function (Product $product) use ($locationId) {
                $currentStock = (float) $product->current_stock;
                $severity = $this->classifyStockSeverity($product, $currentStock);

                if ($severity === null) {
                    return null;
                }

                return [
                    'type' => 'stock',
                    'severity' => $severity,
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'unit_symbol' => $product->unit?->symbol,
                    'current_stock' => $currentStock,
                    'minimum_stock' => (float) ($product->minimum_stock ?? 0),
                    'effective_reorder_point' => $this->effectiveReorderPoint($product),
                    'reorder_quantity' => $this->nullableQuantity($product->reorder_quantity),
                    'inventory_location_id' => $locationId,
                    'inventory_location_name' => null,
                ];
            })
            ->filter()
            ->values();

        if ($severityFilter !== null) {
            $alerts = $alerts->where('severity', $severityFilter)->values();
        }

        $alerts = $this->sortStockAlerts($alerts);

        if ($limit !== null) {
            return $alerts->take($limit)->values();
        }

        return $alerts;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getBatchExpiryAlerts(?int $locationId = null, ?string $severityFilter = null, ?int $limit = null): Collection
    {
        $branchId = $this->branchContext->requireId();
        $this->assertLocationInBranch($branchId, $locationId);

        $today = now()->startOfDay();

        $alerts = $this->batches
            ->batchesWithDerivedStockForAlerts($branchId, $locationId)
            ->map(function (InventoryBatch $batch) use ($today) {
                $severity = $this->classifyBatchSeverity($batch);

                if ($severity === null) {
                    return null;
                }

                $daysUntilExpiry = $batch->expiry_date
                    ? (int) $today->diffInDays($batch->expiry_date, false)
                    : 0;

                return [
                    'type' => 'batch',
                    'severity' => $severity,
                    'inventory_batch_id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'lot_number' => $batch->lot_number,
                    'product_id' => $batch->product_id,
                    'product_name' => $batch->product?->name ?? '',
                    'expiry_date' => $batch->expiry_date?->format('Y-m-d'),
                    'days_until_expiry' => $daysUntilExpiry,
                    'batch_stock' => (float) $batch->derived_stock,
                    'inventory_location_id' => null,
                    'inventory_location_name' => null,
                ];
            })
            ->filter()
            ->values();

        if ($severityFilter !== null) {
            $alerts = $alerts->where('severity', $severityFilter)->values();
        }

        $alerts = $this->sortBatchAlerts($alerts);

        if ($limit !== null) {
            return $alerts->take($limit)->values();
        }

        return $alerts;
    }

    /**
     * @return array{
     *     out_of_stock_count: int,
     *     critical_stock_count: int,
     *     low_stock_count: int,
     *     batch_expired_count: int,
     *     batch_expiring_soon_count: int,
     *     total_count: int,
     * }
     */
    public function getAlertSummary(?int $locationId = null): array
    {
        $stockAlerts = $this->getStockAlerts($locationId);
        $batchAlerts = $this->getBatchExpiryAlerts($locationId);

        $outOfStockCount = $stockAlerts->where('severity', self::SEVERITY_OUT_OF_STOCK)->count();
        $criticalCount = $stockAlerts->where('severity', self::SEVERITY_CRITICAL)->count();
        $lowCount = $stockAlerts->where('severity', self::SEVERITY_LOW)->count();
        $batchExpiredCount = $batchAlerts->where('severity', self::SEVERITY_BATCH_EXPIRED)->count();
        $batchExpiringSoonCount = $batchAlerts->where('severity', self::SEVERITY_BATCH_EXPIRING_SOON)->count();

        return [
            'out_of_stock_count' => $outOfStockCount,
            'critical_stock_count' => $criticalCount,
            'low_stock_count' => $lowCount,
            'batch_expired_count' => $batchExpiredCount,
            'batch_expiring_soon_count' => $batchExpiringSoonCount,
            'total_count' => $stockAlerts->count() + $batchAlerts->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function getUnifiedAlerts(?int $locationId = null, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $severityFilter = $filters['severity'] ?? null;
        $typeFilter = $filters['type'] ?? null;

        $stockAlerts = $typeFilter === 'batch'
            ? collect()
            : $this->getStockAlerts($locationId, $severityFilter);

        $batchAlerts = $typeFilter === 'stock'
            ? collect()
            : $this->getBatchExpiryAlerts($locationId, $severityFilter);

        $merged = $stockAlerts
            ->concat($batchAlerts)
            ->sortBy(fn (array $alert) => $this->severitySortKey($alert['severity']))
            ->values();

        $page = max(1, (int) request()->input('page', 1));
        $total = $merged->count();
        $items = $merged->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }

    private function nullableQuantity(mixed $value): ?float
    {
        if ($value === null || (float) $value <= 0) {
            return null;
        }

        return (float) $value;
    }

    private function severitySortKey(string $severity): string
    {
        return match ($severity) {
            self::SEVERITY_OUT_OF_STOCK => '1',
            self::SEVERITY_CRITICAL => '2',
            self::SEVERITY_LOW => '3',
            self::SEVERITY_BATCH_EXPIRED => '4',
            self::SEVERITY_BATCH_EXPIRING_SOON => '5',
            default => '9',
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $alerts
     * @return Collection<int, array<string, mixed>>
     */
    private function sortStockAlerts(Collection $alerts): Collection
    {
        return $alerts
            ->sortBy([
                fn (array $alert) => $this->severitySortKey($alert['severity']),
                fn (array $alert) => mb_strtolower($alert['product_name']),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $alerts
     * @return Collection<int, array<string, mixed>>
     */
    private function sortBatchAlerts(Collection $alerts): Collection
    {
        return $alerts
            ->sortBy([
                fn (array $alert) => $this->severitySortKey($alert['severity']),
                fn (array $alert) => $alert['expiry_date'],
            ])
            ->values();
    }

    private function assertLocationInBranch(int $branchId, ?int $locationId): void
    {
        if ($locationId === null) {
            return;
        }

        $location = $this->locations->findInBranch($branchId, $locationId);

        if (! $location || ! $location->is_active) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'Lokasi persediaan tidak valid untuk cabang aktif.',
            ]);
        }
    }
}
