<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Interfaces\InventoryAnalyticsRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Branch comparison read model for analytics (Sprint 16.8.6).
 *
 * Cross-branch rows are returned only when the user has
 * {@see InventoryBranchComparisonService::CROSS_BRANCH_PERMISSION}.
 */
class InventoryBranchComparisonService
{
    public const CROSS_BRANCH_PERMISSION = 'view_inventory_cross_branch_analytics';

    public function __construct(
        private readonly BranchContext $branchContext,
        private readonly BranchRepositoryInterface $branches,
        private readonly InventoryAnalyticsRepositoryInterface $analyticsRepository,
    ) {}

    public function userCanViewCrossBranch(?User $user): bool
    {
        return $user instanceof User
            && $user->can(self::CROSS_BRANCH_PERMISSION);
    }

    /**
     * @return Collection<int, array{
     *     branch_id: int,
     *     branch_name: string,
     *     inventory_value: float,
     *     active_sku_count: int,
     *     low_stock_count: int,
     *     dead_stock_count: int,
     *     out_of_stock_count: int,
     *     open_po_outstanding_value: float,
     *     total_quantity_on_hand: float,
     *     inventory_accuracy_pct: float|null,
     *     refreshed_at: string|null,
     * }>
     */
    public function getBranchComparison(?User $user): Collection
    {
        $activeBranchId = $this->branchContext->requireId();
        $branchIds = $this->resolveAllowedBranchIds($user, $activeBranchId);

        return $this->branches
            ->listActive()
            ->filter(fn (Branch $branch) => in_array($branch->id, $branchIds, true))
            ->sortBy('name')
            ->values()
            ->map(fn (Branch $branch) => $this->buildBranchRow($branch));
    }

    /**
     * @return array<int, int>
     */
    private function resolveAllowedBranchIds(?User $user, int $activeBranchId): array
    {
        if (! $this->userCanViewCrossBranch($user)) {
            return [$activeBranchId];
        }

        return $this->branches
            ->listActive()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array{
     *     branch_id: int,
     *     branch_name: string,
     *     inventory_value: float,
     *     active_sku_count: int,
     *     low_stock_count: int,
     *     dead_stock_count: int,
     *     out_of_stock_count: int,
     *     open_po_outstanding_value: float,
     *     total_quantity_on_hand: float,
     *     inventory_accuracy_pct: float|null,
     *     refreshed_at: string|null,
     * }
     */
    private function buildBranchRow(Branch $branch): array
    {
        if (config('inventory.analytics_summary_enabled')) {
            return $this->buildRowFromSummary($branch);
        }

        return $this->buildRowFromLiveRepository($branch);
    }

    /**
     * @return array{
     *     branch_id: int,
     *     branch_name: string,
     *     inventory_value: float,
     *     active_sku_count: int,
     *     low_stock_count: int,
     *     dead_stock_count: int,
     *     out_of_stock_count: int,
     *     open_po_outstanding_value: float,
     *     total_quantity_on_hand: float,
     *     inventory_accuracy_pct: float|null,
     *     refreshed_at: string|null,
     * }
     */
    private function buildRowFromSummary(Branch $branch): array
    {
        $snapshot = DB::table('rpt_inventory_branch_summaries')
            ->where('branch_id', $branch->id)
            ->orderByDesc('snapshot_date')
            ->first();

        if ($snapshot === null) {
            return $this->emptyRow($branch);
        }

        return [
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'inventory_value' => (float) $snapshot->inventory_value,
            'active_sku_count' => (int) $snapshot->active_sku_count,
            'low_stock_count' => (int) $snapshot->low_stock_count,
            'dead_stock_count' => (int) $snapshot->dead_stock_count,
            'out_of_stock_count' => (int) $snapshot->out_of_stock_count,
            'open_po_outstanding_value' => (float) $snapshot->open_po_outstanding_value,
            'total_quantity_on_hand' => (float) $snapshot->total_quantity_on_hand,
            'inventory_accuracy_pct' => $snapshot->inventory_accuracy_pct !== null
                ? (float) $snapshot->inventory_accuracy_pct
                : null,
            'refreshed_at' => $snapshot->refreshed_at !== null
                ? (string) $snapshot->refreshed_at
                : null,
        ];
    }

    /**
     * @return array{
     *     branch_id: int,
     *     branch_name: string,
     *     inventory_value: float,
     *     active_sku_count: int,
     *     low_stock_count: int,
     *     dead_stock_count: int,
     *     out_of_stock_count: int,
     *     open_po_outstanding_value: float,
     *     total_quantity_on_hand: float,
     *     inventory_accuracy_pct: float|null,
     *     refreshed_at: string|null,
     * }
     */
    private function buildRowFromLiveRepository(Branch $branch): array
    {
        $branchId = $branch->id;

        return [
            'branch_id' => $branchId,
            'branch_name' => $branch->name,
            'inventory_value' => $this->analyticsRepository->getInventoryValue($branchId),
            'active_sku_count' => $this->analyticsRepository->getActiveSkuCount($branchId),
            'low_stock_count' => $this->analyticsRepository->getLowStockCount($branchId),
            'dead_stock_count' => $this->analyticsRepository->getDeadStockCount($branchId),
            'out_of_stock_count' => 0,
            'open_po_outstanding_value' => 0.0,
            'total_quantity_on_hand' => 0.0,
            'inventory_accuracy_pct' => $this->analyticsRepository->getInventoryAccuracy($branchId),
            'refreshed_at' => null,
        ];
    }

    /**
     * @return array{
     *     branch_id: int,
     *     branch_name: string,
     *     inventory_value: float,
     *     active_sku_count: int,
     *     low_stock_count: int,
     *     dead_stock_count: int,
     *     out_of_stock_count: int,
     *     open_po_outstanding_value: float,
     *     total_quantity_on_hand: float,
     *     inventory_accuracy_pct: float|null,
     *     refreshed_at: string|null,
     * }
     */
    private function emptyRow(Branch $branch): array
    {
        return [
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'inventory_value' => 0.0,
            'active_sku_count' => 0,
            'low_stock_count' => 0,
            'dead_stock_count' => 0,
            'out_of_stock_count' => 0,
            'open_po_outstanding_value' => 0.0,
            'total_quantity_on_hand' => 0.0,
            'inventory_accuracy_pct' => null,
            'refreshed_at' => null,
        ];
    }
}
