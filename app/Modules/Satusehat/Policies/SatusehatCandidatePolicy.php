<?php

namespace App\Modules\Satusehat\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Models\SatusehatCandidate;

/**
 * Record-level authorization for SATUSEHAT candidates. Super Admin bypasses via
 * Gate::before. Branch isolation: a candidate is only reachable when it belongs
 * to an RME-enabled branch (the shared pilot set; MAIN excluded) — a forged id
 * pointing outside that set is denied (IDOR boundary), on top of the permission
 * gate. Review/queue require their own dedicated permissions.
 */
class SatusehatCandidatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            'view_satusehat_submissions',
            'review_satusehat_submissions',
            'send_satusehat_submissions',
        ]);
    }

    public function view(User $user, SatusehatCandidate $candidate): bool
    {
        return $this->canReadInBranch($user, $candidate, [
            'view_satusehat_submissions',
            'review_satusehat_submissions',
            'send_satusehat_submissions',
        ]);
    }

    public function preview(User $user, SatusehatCandidate $candidate): bool
    {
        return $this->view($user, $candidate);
    }

    public function approve(User $user, SatusehatCandidate $candidate): bool
    {
        return $user->can('review_satusehat_submissions')
            && $this->inRmeBranch($candidate);
    }

    public function exclude(User $user, SatusehatCandidate $candidate): bool
    {
        return $this->approve($user, $candidate);
    }

    public function queue(User $user, SatusehatCandidate $candidate): bool
    {
        return $user->can('send_satusehat_submissions')
            && $this->inRmeBranch($candidate);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function canReadInBranch(User $user, SatusehatCandidate $candidate, array $permissions): bool
    {
        return $user->canAny($permissions) && $this->inRmeBranch($candidate);
    }

    private function inRmeBranch(SatusehatCandidate $candidate): bool
    {
        return in_array((int) $candidate->branch_id, app(BranchService::class)->rmeEnabledIds(), true);
    }
}
