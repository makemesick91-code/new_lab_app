<?php

namespace App\Modules\Inventory\Policies\Concerns;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Services\InventoryBranchComparisonService;

trait ChecksInventoryAccess
{
    protected function canViewInventory(User $user): bool
    {
        return $user->canAny([
            'view_inventory',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canManageInventory(User $user): bool
    {
        return $user->canAny([
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canViewStockOpname(User $user): bool
    {
        return $user->canAny([
            'view_stock_opname',
            'manage_stock_opname',
            'view_inventory',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canManageStockOpname(User $user): bool
    {
        return $user->canAny([
            'manage_stock_opname',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canViewInventoryBatchLot(User $user): bool
    {
        return $user->canAny([
            'view_inventory_batch_lot',
            'manage_inventory_batch_lot',
            'view_inventory',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canManageInventoryBatchLot(User $user): bool
    {
        return $user->canAny([
            'manage_inventory_batch_lot',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canViewStockAlert(User $user): bool
    {
        return $user->canAny([
            'view_stock_alert',
            'manage_stock_alert',
            'view_inventory',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canManageStockAlert(User $user): bool
    {
        return $user->canAny([
            'manage_stock_alert',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canViewInventoryAnalytics(User $user): bool
    {
        return $user->canAny([
            'view_inventory_analytics',
            'manage_inventory_analytics',
            'view_inventory',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canViewInventoryExecutiveDashboard(User $user): bool
    {
        return $user->canAny([
            'view_inventory_executive_dashboard',
            'view_inventory_analytics',
            'manage_inventory_analytics',
            'view_inventory',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canManageInventoryAnalytics(User $user): bool
    {
        return $user->canAny([
            'manage_inventory_analytics',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canViewCrossBranchInventoryAnalytics(User $user): bool
    {
        return $user->can(InventoryBranchComparisonService::CROSS_BRANCH_PERMISSION);
    }

    protected function canViewStockTransfer(User $user): bool
    {
        return $user->canAny([
            'view_stock_transfer',
            'manage_stock_transfer',
            'view_inventory',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canManageStockTransfer(User $user): bool
    {
        return $user->canAny([
            'manage_stock_transfer',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canDownloadStockTransferChecklist(User $user): bool
    {
        return $user->can('download_stock_transfer_checklist');
    }

    protected function canViewPurchaseRequest(User $user): bool
    {
        return $user->canAny([
            'view_purchase_request',
            'manage_purchase_request',
            'view_inventory',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canManagePurchaseRequest(User $user): bool
    {
        return $user->canAny([
            'manage_purchase_request',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canApprovePurchaseRequest(User $user): bool
    {
        return $user->canAny([
            'approve_inventory_purchase_request',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canViewPurchaseOrder(User $user): bool
    {
        return $user->canAny([
            'view_purchase_order',
            'manage_purchase_order',
            'view_inventory',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canManagePurchaseOrder(User $user): bool
    {
        return $user->canAny([
            'manage_purchase_order',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canApprovePurchaseOrder(User $user): bool
    {
        return $user->canAny([
            'approve_inventory_purchase_order',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canViewGoodsReceipt(User $user): bool
    {
        return $user->canAny([
            'view_goods_receipt',
            'manage_goods_receipt',
            'view_inventory',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function canManageGoodsReceipt(User $user): bool
    {
        return $user->canAny([
            'manage_goods_receipt',
            'manage_inventory',
            'manage master data',
        ]);
    }

    protected function activeBranchId(): ?int
    {
        return app(BranchContext::class)->id();
    }

    protected function belongsToActiveBranch(?int $branchId): bool
    {
        $activeBranchId = $this->activeBranchId();

        return $activeBranchId !== null && $branchId === $activeBranchId;
    }
}
