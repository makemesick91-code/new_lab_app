<?php

namespace App\Modules\Branch\Services;

use App\Models\User;
use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Branch\Models\Branch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Minimal active-branch resolver for branch-aware features.
 *
 * The current schema has no user branch assignment (the VPS pilot confirmed
 * `users.branch_id` does not exist), so resolution is defensive:
 *   - `branchIdFromUserColumn()` is guarded by Schema::hasColumn and never
 *     touches a missing column, so it cannot 500 when `users.branch_id` is
 *     absent.
 *   - The generic fallback is the active MAIN branch, otherwise the first
 *     active branch.
 *   - Module-aware fallbacks (RME / Inventory) prefer MAIN when it participates
 *     in that module, otherwise the first active module-enabled branch.
 *
 * Patient-ID branch selection does NOT rely on this fallback — the branch is
 * chosen explicitly in the patient / new-visit form (Sprint 23 Phase 23.8).
 */
class BranchContext
{
    public function __construct(
        private readonly BranchRepositoryInterface $branches,
    ) {}

    public function id(): ?int
    {
        $user = Auth::user();

        if ($user instanceof User) {
            return $this->forUser($user);
        }

        return $this->defaultBranchId();
    }

    public function branch(): ?Branch
    {
        $branchId = $this->id();

        return $branchId ? $this->branches->findById($branchId) : null;
    }

    public function requireId(): int
    {
        $branchId = $this->id();

        if (! $branchId) {
            throw new RuntimeException('No active branch could be resolved. Ensure the MAIN branch is seeded before using branch-aware features.');
        }

        return $branchId;
    }

    public function forUser(User $user): ?int
    {
        $branchId = $this->branchIdFromUserColumn($user)
            ?? $this->branchIdFromUserRelation($user)
            ?? $this->defaultBranchId();

        return $branchId ? (int) $branchId : null;
    }

    private function branchIdFromUserColumn(User $user): ?int
    {
        if (! Schema::hasColumn($user->getTable(), 'branch_id')) {
            return null;
        }

        $branchId = $user->getAttribute('branch_id');

        if (! $branchId) {
            return null;
        }

        $branch = $this->branches->findById((int) $branchId);

        return $branch?->is_active ? $branch->id : null;
    }

    private function branchIdFromUserRelation(User $user): ?int
    {
        if (! method_exists($user, 'branches')) {
            return null;
        }

        $branch = $user->branches()
            ->where('is_active', true)
            ->orderBy('name')
            ->first();

        return $branch?->id;
    }

    /**
     * Fallback branch id for the RME (multi-branch) module. Prefers MAIN when it
     * is active and RME-enabled, otherwise the first active RME-enabled branch.
     */
    public function rmeBranchId(): ?int
    {
        $main = $this->branches->defaultBranch();

        if ($main && $main->is_active && $main->is_rme_enabled) {
            return $main->id;
        }

        return $this->branches->listRmeEnabled()->first()?->id;
    }

    public function requireRmeBranchId(): int
    {
        $branchId = $this->rmeBranchId();

        if (! $branchId) {
            throw new RuntimeException('No active RME-enabled branch could be resolved. Seed a branch with is_rme_enabled = true.');
        }

        return $branchId;
    }

    /**
     * Fallback branch id for the Inventory (multi-branch) module. Prefers MAIN
     * when it is active and inventory-enabled, otherwise the first active
     * inventory-enabled branch.
     */
    public function inventoryBranchId(): ?int
    {
        $main = $this->branches->defaultBranch();

        if ($main && $main->is_active && $main->is_inventory_enabled) {
            return $main->id;
        }

        return Branch::query()
            ->where('is_active', true)
            ->where('is_inventory_enabled', true)
            ->orderBy('name')
            ->value('id');
    }

    private function defaultBranchId(): ?int
    {
        $main = $this->branches->defaultBranch();

        if ($main && $main->is_active) {
            return $main->id;
        }

        // MAIN missing or inactive: fall back to the first active branch so
        // branch-aware features keep working on minimally-seeded environments.
        return $this->branches->listActive()->first()?->id;
    }
}
