<?php

namespace App\Modules\Satusehat\Policies;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;

/**
 * SATUSEHAT-4A — record-level authorization for data-quality issues.
 * Super Admin bypasses via Gate::before. Branch isolation: an issue outside
 * the RME-enabled branch set is unreachable (IDOR boundary) regardless of
 * permission. Waivers require their own dedicated permission.
 */
class SatusehatDataQualityIssuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['view_satusehat_readiness', 'manage_satusehat_remediation']);
    }

    public function view(User $user, SatusehatDataQualityIssue $issue): bool
    {
        return $user->canAny(['view_satusehat_readiness', 'manage_satusehat_remediation'])
            && $this->inRmeBranch($issue);
    }

    public function manage(User $user, SatusehatDataQualityIssue $issue): bool
    {
        return $user->can('manage_satusehat_remediation') && $this->inRmeBranch($issue);
    }

    public function waive(User $user, SatusehatDataQualityIssue $issue): bool
    {
        return $user->can('manage_satusehat_readiness_waivers') && $this->inRmeBranch($issue);
    }

    private function inRmeBranch(SatusehatDataQualityIssue $issue): bool
    {
        return in_array((int) $issue->branch_id, app(BranchService::class)->rmeEnabledIds(), true);
    }
}
