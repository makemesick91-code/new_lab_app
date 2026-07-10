<?php

namespace App\Modules\Branch\Services;

use App\Models\User;
use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Minimal active-branch resolver for branch-aware features.
 *
 * Resolution priority (RME-BRANCH-SUN4):
 *   1. Active RME online context (Doctor / Admin Klinik / Perawat) — the branch
 *      the operator explicitly chose after login always wins while the context
 *      is online, so daily work never depends on a static `users.branch_id` pin.
 *   2. `users.branch_id` (guarded by Schema::hasColumn — older schemas without
 *      the column never 500).
 *   3. User `branches()` relation, when present.
 *   4. The active MAIN branch, otherwise the first active branch.
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
        $branchId = $this->branchIdFromOnlineContext($user)
            ?? $this->branchIdFromUserColumn($user)
            ?? $this->branchIdFromUserRelation($user)
            ?? $this->defaultBranchId();

        return $branchId ? (int) $branchId : null;
    }

    /**
     * The branch of the user's active RME online context (fail closed: only an
     * online, role-matching context on an active RME-enabled branch counts —
     * MAIN can never qualify). Guarded so environments without the Sprint 66
     * table keep resolving through the static fallbacks.
     */
    private function branchIdFromOnlineContext(User $user): ?int
    {
        if (! Schema::hasTable('trx_user_online_contexts')) {
            return null;
        }

        return app(UserOnlineContextService::class)->activeContextBranchId($user);
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
