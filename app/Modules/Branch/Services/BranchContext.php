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
 * The current schema has no user branch assignment yet, so MAIN is the safe
 * fallback established by BranchSeeder and the Sprint 9 backfill.
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

    private function defaultBranchId(): ?int
    {
        return $this->branches->defaultBranch()?->id;
    }
}
