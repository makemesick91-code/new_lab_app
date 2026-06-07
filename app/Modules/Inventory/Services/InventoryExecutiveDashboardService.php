<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\DTOs\InventoryExecutiveSnapshot;
use Illuminate\Support\Carbon;

/**
 * Composes executive dashboard payload from analytics results (Sprint 16.7.5).
 *
 * This service MUST NOT query the database directly — only orchestrate
 * {@see InventoryAnalyticsService} and map to DTO/view models.
 */
class InventoryExecutiveDashboardService
{
    public const VALUATION_NOTE = 'Operational inventory value, not accounting valuation.';

    public const ACCURACY_NOTE = 'Inventory accuracy is null when no completed stock opname exists.';

    public const CONSUMPTION_NOTE = 'Consumption includes all outbound inventory movements.';

    public const ACCURACY_UNAVAILABLE_DISPLAY = 'Belum ada stock opname selesai';

    public const EXECUTIVE_MOVEMENT_LIMIT = 5;

    public function __construct(
        private readonly InventoryAnalyticsService $analytics,
        private readonly BranchContext $branchContext,
    ) {}

    public function getExecutiveSnapshot(int $branchId): InventoryExecutiveSnapshot
    {
        return InventoryExecutiveSnapshot::fromArray(
            $this->analytics->getKpiSummary($branchId),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDashboardCards(int $branchId): array
    {
        return $this->enrichCards($this->getExecutiveSnapshot($branchId));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getDashboardSections(int $branchId): array
    {
        return [
            'trends' => [
                'purchase_trend' => $this->analytics->getPurchaseTrend($branchId),
                'consumption_trend' => $this->analytics->getConsumptionTrend($branchId),
            ],
            'movement' => [
                'fast_moving' => $this->analytics->getFastMovingItems($branchId, limit: self::EXECUTIVE_MOVEMENT_LIMIT),
                'slow_moving' => $this->analytics->getSlowMovingItems($branchId, limit: self::EXECUTIVE_MOVEMENT_LIMIT),
                'dead_stock' => $this->analytics->getDeadStockItems($branchId, limit: self::EXECUTIVE_MOVEMENT_LIMIT),
            ],
            'valuation' => [
                'stock_aging' => $this->analytics->getStockAging($branchId),
            ],
            'supplier' => [
                'supplier_performance' => $this->analytics->getSupplierPerformance($branchId),
            ],
            'reorder' => [
                'reorder_recommendations' => $this->analytics->getReorderRecommendations($branchId),
            ],
        ];
    }

    /**
     * @return array{
     *     snapshot: InventoryExecutiveSnapshot,
     *     cards: array<int, array<string, mixed>>,
     *     sections: array<string, array<string, mixed>>,
     *     meta: array{
     *         branch_id: int,
     *         generated_at: Carbon,
     *         valuation_note: string,
     *         accuracy_note: string,
     *         consumption_note: string,
     *     },
     * }
     */
    public function getExecutiveDashboard(int $branchId): array
    {
        $snapshot = $this->getExecutiveSnapshot($branchId);

        return [
            'snapshot' => $snapshot,
            'cards' => $this->enrichCards($snapshot),
            'sections' => $this->getDashboardSections($branchId),
            'meta' => [
                'branch_id' => $branchId,
                'generated_at' => now(),
                'valuation_note' => self::VALUATION_NOTE,
                'accuracy_note' => self::ACCURACY_NOTE,
                'consumption_note' => self::CONSUMPTION_NOTE,
            ],
        ];
    }

    /**
     * @return array{
     *     snapshot: InventoryExecutiveSnapshot,
     *     cards: array<int, array<string, mixed>>,
     *     sections: array<string, array<string, mixed>>,
     *     meta: array{
     *         branch_id: int,
     *         generated_at: Carbon,
     *         valuation_note: string,
     *         accuracy_note: string,
     *         consumption_note: string,
     *     },
     * }
     */
    public function getExecutiveDashboardForCurrentBranch(): array
    {
        return $this->getExecutiveDashboard($this->branchContext->requireId());
    }

    /**
     * @param  array<int, array{key: string, label: string, value: float|int|null, type: string, note: string|null}>  $cards
     * @return array<int, array<string, mixed>>
     */
    private function enrichCards(InventoryExecutiveSnapshot $snapshot): array
    {
        return array_map(
            fn (array $card) => array_merge($card, [
                'display_value' => $this->resolveDisplayValue($card),
                'tone' => $this->resolveCardTone($card['key'], $snapshot),
                'href' => $this->resolveCardHref($card['key']),
                'empty_state' => $this->resolveEmptyState($card['key'], $card['value']),
            ]),
            $snapshot->toCards(),
        );
    }

    /**
     * @param  array{key: string, label: string, value: float|int|null, type: string, note: string|null}  $card
     */
    private function resolveDisplayValue(array $card): string
    {
        $value = $card['value'];

        return match ($card['type']) {
            'currency' => 'Rp '.number_format((float) $value, 0, ',', '.'),
            'percentage' => $value === null
                ? self::ACCURACY_UNAVAILABLE_DISPLAY
                : number_format((float) $value, 1, ',', '.').'%',
            default => (string) (int) $value,
        };
    }

    private function resolveCardTone(string $key, InventoryExecutiveSnapshot $snapshot): string
    {
        return match ($key) {
            'low_stock_count' => $snapshot->lowStockCount > 0 ? 'warning' : 'neutral',
            'dead_stock_count' => $snapshot->deadStockCount > 0 ? 'warning' : 'neutral',
            'pending_gr' => $snapshot->pendingGr > 0 ? 'info' : 'neutral',
            'in_transit_transfer' => $snapshot->inTransitTransfer > 0 ? 'info' : 'neutral',
            'inventory_accuracy' => $this->resolveAccuracyTone($snapshot->inventoryAccuracy),
            default => 'neutral',
        };
    }

    private function resolveAccuracyTone(?float $accuracy): string
    {
        if ($accuracy === null) {
            return 'neutral';
        }

        if ($accuracy < 90) {
            return 'warning';
        }

        return 'success';
    }

    private function resolveCardHref(string $key): ?string
    {
        return match ($key) {
            'inventory_value' => route('inventory.analytics.index'),
            'active_sku' => route('inventory.stock.index'),
            'dead_stock_count' => route('inventory.analytics.index').'#section-dead',
            'low_stock_count' => route('inventory.alerts.index'),
            'open_pr' => route('inventory.purchase-requests.index'),
            'open_po' => route('inventory.purchase-orders.index'),
            'pending_gr' => route('inventory.goods-receipts.index'),
            'in_transit_transfer' => route('inventory.stock-transfers.index'),
            'inventory_accuracy' => route('inventory.stock-opnames.index'),
            default => null,
        };
    }

    private function resolveEmptyState(string $key, mixed $value): ?string
    {
        if ($key === 'inventory_accuracy' && $value === null) {
            return self::ACCURACY_UNAVAILABLE_DISPLAY;
        }

        if (in_array($key, ['low_stock_count', 'dead_stock_count', 'open_pr', 'open_po', 'pending_gr', 'in_transit_transfer'], true)
            && (int) $value === 0) {
            return 'Tidak ada data';
        }

        return null;
    }
}
