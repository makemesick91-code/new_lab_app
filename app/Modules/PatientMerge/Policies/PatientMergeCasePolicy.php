<?php

namespace App\Modules\PatientMerge\Policies;

use App\Models\User;
use App\Modules\PatientMerge\Models\PatientMergeCase;
use App\Modules\PatientMerge\Services\PatientMergeScope;
use App\Modules\PatientMerge\Support\PatientMergeStatus;

/**
 * Who may act on a merge case. Every ability also requires BOTH patients to be
 * inside the actor's branch scope, so a case on another branch's patients is
 * invisible rather than merely read-only.
 *
 * Maker-checker (an approver who filed the case) is NOT decided here: Super
 * Admin passes every policy through Gate::before, so that rule lives in the
 * services where it cannot be bypassed.
 */
class PatientMergeCasePolicy
{
    public function __construct(
        private readonly PatientMergeScope $scope,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->can('view_patient_duplicate_resolution');
    }

    public function view(User $user, PatientMergeCase $case): bool
    {
        return $user->can('view_patient_duplicate_resolution') && $this->inScope($user, $case);
    }

    public function create(User $user): bool
    {
        return $user->can('request_patient_merge');
    }

    /**
     * Only the requester shapes their own draft. A reviewer who could rewrite
     * someone else's draft would decide the final identity AND approve it —
     * the bypass maker-checker exists to prevent. A reviewer sends a case
     * back by rejecting it.
     */
    public function update(User $user, PatientMergeCase $case): bool
    {
        return $case->isDraft()
            && $user->can('request_patient_merge')
            && $this->inScope($user, $case)
            && (int) $case->requested_by === (int) $user->id;
    }

    public function submit(User $user, PatientMergeCase $case): bool
    {
        return $this->update($user, $case);
    }

    public function withdraw(User $user, PatientMergeCase $case): bool
    {
        return $case->isPendingReview()
            && $user->can('request_patient_merge')
            && (int) $case->requested_by === (int) $user->id
            && $this->inScope($user, $case);
    }

    public function cancel(User $user, PatientMergeCase $case): bool
    {
        if (! in_array($case->status, [PatientMergeStatus::DRAFT, PatientMergeStatus::PENDING_REVIEW], true) || ! $this->inScope($user, $case)) {
            return false;
        }

        return $user->can('approve_patient_merge')
            || ($user->can('request_patient_merge') && (int) $case->requested_by === (int) $user->id);
    }

    public function review(User $user, PatientMergeCase $case): bool
    {
        return $case->isPendingReview() && $user->can('approve_patient_merge') && $this->inScope($user, $case);
    }

    public function reverse(User $user, PatientMergeCase $case): bool
    {
        return in_array($case->status, [PatientMergeStatus::COMPLETED, PatientMergeStatus::REVERSAL_REQUIRED], true)
            && $user->can('approve_patient_merge')
            && $this->inScope($user, $case);
    }

    private function inScope(User $user, PatientMergeCase $case): bool
    {
        $case->loadMissing(['patientA', 'patientB']);

        return $case->patientA !== null
            && $case->patientB !== null
            && $this->scope->covers($user, $case->patientA)
            && $this->scope->covers($user, $case->patientB);
    }
}
