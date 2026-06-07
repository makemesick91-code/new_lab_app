<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Requests\InventoryAnalyticsFilterRequest;
use Illuminate\Support\Facades\DB;

/**
 * Composes deferred analytics page payloads (Sprint 16.8.6).
 */
class InventoryAnalyticsPageService
{
    public function __construct(
        private readonly InventoryAnalyticsService $analytics,
        private readonly InventoryBranchComparisonService $branchComparison,
        private readonly InventoryLocationService $locations,
        private readonly InventoryProductService $products,
        private readonly BranchContext $branchContext,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildPage(string $tab, array $filters, ?User $user): array
    {
        $branchId = $this->branchContext->requireId();
        $serviceFilters = $this->toServiceFilters($filters);
        $normalizedTab = InventoryAnalyticsFilterRequest::normalizeTab($tab);
        $canViewCrossBranch = $this->branchComparison->userCanViewCrossBranch($user);

        return array_merge(
            [
                'tab' => $normalizedTab,
                'filters' => $filters,
                'summary' => $this->analytics->getAnalyticsSummary($branchId, $serviceFilters),
                'locations' => $this->locations->listActive(),
                'categories' => $this->products->listActiveCategories(),
                'tabs' => $this->tabDefinitions($canViewCrossBranch),
                'meta' => $this->buildMeta($branchId, $canViewCrossBranch),
                'canViewCrossBranch' => $canViewCrossBranch,
            ],
            $this->resolveTabPayload($normalizedTab, $branchId, $serviceFilters, $user),
        );
    }

    /**
     * @return array<int, array{key: string, label: string, id: string}>
     */
    public function tabDefinitions(bool $canViewCrossBranch): array
    {
        $tabs = [
            ['key' => 'summary', 'label' => 'Ringkasan', 'id' => 'section-summary'],
            ['key' => 'movement', 'label' => 'Inteligensi Pergerakan', 'id' => 'section-movement'],
            ['key' => 'aging', 'label' => 'Umur Persediaan', 'id' => 'section-aging'],
            ['key' => 'turnover', 'label' => 'Perputaran Persediaan', 'id' => 'section-turnover'],
            ['key' => 'value', 'label' => 'Nilai per Kategori & Lokasi', 'id' => 'section-value'],
            ['key' => 'trend', 'label' => 'Tren Nilai Keluar', 'id' => 'section-trend'],
            ['key' => 'supplier', 'label' => 'Kinerja Supplier', 'id' => 'section-supplier'],
            ['key' => 'reorder', 'label' => 'Rekomendasi Reorder', 'id' => 'section-reorder'],
            ['key' => 'procurement', 'label' => 'Tren Procurement', 'id' => 'section-procurement'],
        ];

        if ($canViewCrossBranch) {
            $tabs[] = [
                'key' => 'branch-comparison',
                'label' => 'Perbandingan Cabang',
                'id' => 'section-branch-comparison',
            ];
        }

        return $tabs;
    }

    /**
     * @return array{
     *     analytics_mode: string,
     *     analytics_mode_label: string,
     *     refreshed_at: string|null,
     *     refresh_status_label: string,
     * }
     */
    public function buildMeta(int $branchId, bool $canViewCrossBranch): array
    {
        $summaryEnabled = (bool) config('inventory.analytics_summary_enabled');
        $refreshedAt = $summaryEnabled ? $this->latestBranchRefreshedAt($branchId) : null;

        return [
            'analytics_mode' => $summaryEnabled ? 'summary' : 'live',
            'analytics_mode_label' => $summaryEnabled
                ? 'Analytics summary mode aktif'
                : 'Live ledger mode',
            'refreshed_at' => $refreshedAt,
            'refresh_status_label' => $this->resolveRefreshStatusLabel($summaryEnabled, $refreshedAt),
            'can_view_cross_branch' => $canViewCrossBranch,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function resolveTabPayload(
        string $tab,
        int $branchId,
        array $filters,
        ?User $user,
    ): array {
        return match ($tab) {
            'movement', 'fast' => [
                'fastMoving' => $this->analytics->getFastMovingProducts($branchId, $filters),
                'slowMoving' => $this->analytics->getSlowMovingProducts($branchId, $filters),
                'deadStock' => $this->analytics->getDeadStockProducts($branchId, $filters),
            ],
            'slow' => [
                'slowMoving' => $this->analytics->getSlowMovingProducts($branchId, $filters),
            ],
            'dead' => [
                'deadStock' => $this->analytics->getDeadStockProducts($branchId, $filters),
            ],
            'aging' => [
                'aging' => $this->analytics->getInventoryAging($branchId, $filters),
            ],
            'turnover' => [
                'turnover' => $this->analytics->getInventoryTurnover($branchId, $filters),
            ],
            'value' => [
                'valueByCategory' => $this->analytics->getInventoryValueByCategory($branchId, $filters),
                'valueByLocation' => $this->analytics->getInventoryValueByLocation($branchId, $filters),
            ],
            'trend' => [
                'outboundTrend' => $this->analytics->getMonthlyOutboundValueTrend($branchId, $filters),
            ],
            'supplier' => [
                'supplierPerformance' => $this->analytics->getSupplierPerformance($branchId),
            ],
            'reorder' => [
                'reorderRecommendations' => $this->analytics->getReorderRecommendations($branchId),
            ],
            'procurement' => [
                'purchaseTrend' => $this->analytics->getPurchaseTrend($branchId),
            ],
            'branch-comparison' => [
                'branchComparison' => $this->branchComparison->getBranchComparison($user),
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function toServiceFilters(array $filters): array
    {
        return collect([
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
            'inventory_location_id' => isset($filters['location_id']) ? (int) $filters['location_id'] : null,
            'product_category_id' => isset($filters['category_id']) ? (int) $filters['category_id'] : null,
            'dead_stock_days' => $filters['dead_stock_days'] ?? null,
            'slow_moving_threshold' => $filters['slow_moving_threshold'] ?? null,
            'limit' => $filters['limit'] ?? null,
            'aging_granularity' => $filters['aging_granularity'] ?? null,
        ])->filter(fn ($value) => $value !== null && $value !== '')->all();
    }

    private function latestBranchRefreshedAt(int $branchId): ?string
    {
        $refreshedAt = DB::table('rpt_inventory_branch_summaries')
            ->where('branch_id', $branchId)
            ->orderByDesc('snapshot_date')
            ->value('refreshed_at');

        return $refreshedAt !== null ? (string) $refreshedAt : null;
    }

    private function resolveRefreshStatusLabel(bool $summaryEnabled, ?string $refreshedAt): string
    {
        if (! $summaryEnabled) {
            return 'Summary belum di-refresh';
        }

        if ($refreshedAt === null) {
            return 'Summary belum di-refresh';
        }

        return 'Last refreshed: '.format_datetime_id($refreshedAt);
    }
}
