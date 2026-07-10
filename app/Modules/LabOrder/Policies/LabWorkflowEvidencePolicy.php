<?php

namespace App\Modules\LabOrder\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\LabOrder\Models\LabWorkflowEvidence;

/**
 * LAB-WORKFLOW-V2 — private evidence file access. Super Admin bypasses via Gate::before.
 *
 * Lab staff and couriers may view all workflow evidence (central lab +
 * transport are cross-branch); branch actors only their own branch's evidence.
 */
class LabWorkflowEvidencePolicy
{
    public function __construct(
        private readonly BranchContext $branchContext,
    ) {}

    public function view(User $user, LabWorkflowEvidence $evidence): bool
    {
        if ($user->canAny(['manage_lab_orders', 'view_lab_orders', 'manage_lab_pickups'])) {
            return true;
        }

        if ($user->can('create_lab_branch_requests')) {
            $contextBranchId = $this->branchContext->forUser($user);

            return $evidence->branch_id !== null
                && $contextBranchId !== null
                && (int) $evidence->branch_id === (int) $contextBranchId;
        }

        return false;
    }
}
