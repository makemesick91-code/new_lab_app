<?php

namespace App\Modules\Branch\Services;

use App\Models\User;
use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Support\AccessControl\FrontOfficeBranchPinResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Minimal active-branch resolver for branch-aware features.
 *
 * Resolution priority:
 *   0. REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1 — the pinned branch of an
 *      armed front-desk account, ahead of everything else and NARROWING only.
 *      NULL for every other account, in which case the tiers below run
 *      untouched. Armed-but-undecidable resolves to NULL (fail closed).
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
        /*
         * REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1 — an armed front-desk
         * account resolves to its pinned branch, FIRST and only.
         *
         * WHY THIS IS AHEAD OF THE ONLINE CONTEXT AND NOT BEHIND IT.
         *
         * Refusing a widening SELECTION is not sufficient on its own: an
         * account armed today may already hold an online-context row selected
         * before it was armed, pointing at a different branch. Resolving the
         * context first would hand that account its old branch on the very
         * login the lock just approved for a different one.
         *
         * This can only ever NARROW. It returns a single branch id — the one
         * the cohort pins the account to, already checked active and
         * RME-enabled — or NULL for everybody else, in which case the ordinary
         * resolution below runs untouched. There is no input from the request
         * anywhere in it.
         *
         * Resolved lazily, like the online-context lookup below it, to keep
         * this resolver's dependency graph acyclic.
         */
        $pin = app(FrontOfficeBranchPinResolver::class);
        $pinned = $pin->requiredBranchIdFor($user);

        if ($pinned !== null) {
            return $pinned;
        }

        /*
         * ARMED BUT UNDECIDABLE FAILS CLOSED, AND CLOSED MEANS "NO BRANCH".
         *
         * A duplicated cohort entry, a branch code outside the committed
         * allowlist, an oversized cohort, or a pinned branch that has since
         * lost `is_active` / `is_rme_enabled` all leave an armed account with no
         * decidable branch. Falling through to the ordinary resolution below
         * would hand that account its online context, its `users.branch_id`, or
         * MAIN — every one of them WIDER than the pin the operator intended. So
         * a misconfigured armed account resolves to NOTHING and branch-scoped
         * surfaces refuse loudly, rather than quietly serving a wider scope.
         *
         * The device layer did not need this branch because a covered account
         * with an unusable mapping had already been denied login. This layer is
         * not permitted to deny a login, so it must refuse here instead.
         */
        if ($pin->isMisconfiguredFor($user)) {
            return null;
        }

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
