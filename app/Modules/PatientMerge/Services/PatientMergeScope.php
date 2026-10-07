<?php

namespace App\Modules\PatientMerge\Services;

use App\Models\User;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;

/**
 * Which patients a duplicate-resolution user may see and choose.
 *
 * Deliberately the canonical working-branch authority, NOT the global New Visit
 * identity lookup: a merge reads and moves a person's whole history, so a
 * front-office operator pinned to one branch resolves duplicates inside that
 * branch, and a context-bound operator with no working branch sees nothing
 * (fail closed). Governance roles (Supervisor RME, Owner, Super Admin) receive
 * the full active RME branch set from the same authority.
 *
 * Cross-branch resolution is an explicit PRIVILEGE, not a side effect of a
 * wide scope: creating a case whose two patients sit in different branches
 * additionally requires `approve_patient_merge`
 * ({@see PatientMergeCaseService::create}). A legacy patient without a branch
 * is in every scope, exactly as in the patient selector.
 */
class PatientMergeScope
{
    public function __construct(
        private readonly RmeWorkingBranchScope $workingScope,
    ) {}

    /** @return array<int, int> */
    public function branchIdsFor(?User $user): array
    {
        return $this->workingScope->branchIdsFor($user);
    }

    public function covers(?User $user, Patient $patient): bool
    {
        if ($patient->branch_id === null) {
            return $this->branchIdsFor($user) !== [];
        }

        return in_array((int) $patient->branch_id, $this->branchIdsFor($user), true);
    }

    public function isCrossBranch(Patient $a, Patient $b): bool
    {
        return $a->branch_id !== null
            && $b->branch_id !== null
            && (int) $a->branch_id !== (int) $b->branch_id;
    }

    public function mayResolveCrossBranch(?User $user): bool
    {
        return $user !== null && $user->can('approve_patient_merge');
    }
}
