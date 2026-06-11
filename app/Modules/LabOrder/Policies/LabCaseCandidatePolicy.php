<?php

namespace App\Modules\LabOrder\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\LabOrder\Models\LabCaseCandidate;

/**
 * Record-level authorization for Lab Case Candidates (read-only in Phase 21.3).
 * Super Admin bypasses via Gate::before.
 * Branch isolation: a user can only view candidates belonging to their active branch.
 */
class LabCaseCandidatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['manage_lab_orders', 'view_lab_orders']);
    }

    public function view(User $user, LabCaseCandidate $candidate): bool
    {
        if (! $user->canAny(['manage_lab_orders', 'view_lab_orders'])) {
            return false;
        }

        $branchId = app(BranchContext::class)->forUser($user);

        // null means no branch resolved (shouldn't happen with BranchSeeder, but safe fallback)
        return $branchId === null || $candidate->branch_id === $branchId;
    }
}
