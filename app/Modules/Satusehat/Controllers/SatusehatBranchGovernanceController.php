<?php

namespace App\Modules\Satusehat\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Requests\SatusehatBranchTransitionRequest;
use App\Modules\Satusehat\Services\Pilot\SatusehatBranchPromotionService;
use App\Modules\Satusehat\Services\Pilot\SatusehatCrossBranchIssueService;
use App\Modules\Satusehat\Support\SatusehatWorkspaceScope;
use Illuminate\Http\RedirectResponse;

/**
 * SATUSEHAT-4D — branch readiness promotion/demotion + cross-branch bulk issue
 * governance. Every action is branch-scoped server-side (the branch/issues must
 * be inside the actor's resolved RME scope — never trusts a raw request id) and
 * delegated to a transactional+audited service. Nothing enables external send.
 */
class SatusehatBranchGovernanceController extends Controller
{
    public function __construct(
        private readonly SatusehatBranchPromotionService $promotion,
        private readonly SatusehatCrossBranchIssueService $issues,
        private readonly BranchService $branches,
        private readonly SatusehatWorkspaceScope $scope,
    ) {}

    public function promote(SatusehatBranchTransitionRequest $request, int $branch): RedirectResponse
    {
        $this->promotion->promote($this->resolveBranch($request, $branch), (string) $request->validated('reason'), $request->user());

        return back()->with('status', 'Cabang dipromosikan ke siap pilot internal (INTERNAL GO). Kesiapan eksternal tetap terpisah.');
    }

    public function demote(SatusehatBranchTransitionRequest $request, int $branch): RedirectResponse
    {
        $this->promotion->demote(
            $this->resolveBranch($request, $branch),
            (string) $request->validated('trigger'),
            (string) $request->validated('reason'),
            $request->user(),
        );

        return back()->with('status', 'Cabang diturunkan ke remediasi.');
    }

    public function suspend(SatusehatBranchTransitionRequest $request, int $branch): RedirectResponse
    {
        $this->promotion->suspend($this->resolveBranch($request, $branch), (string) $request->validated('reason'), $request->user());

        return back()->with('status', 'Kesiapan cabang ditangguhkan.');
    }

    public function resume(SatusehatBranchTransitionRequest $request, int $branch): RedirectResponse
    {
        $this->promotion->resume($this->resolveBranch($request, $branch), (string) $request->validated('reason'), $request->user());

        return back()->with('status', 'Kesiapan cabang dilanjutkan.');
    }

    public function bulkAssignIssues(SatusehatBranchTransitionRequest $request): RedirectResponse
    {
        $result = $this->issues->bulkAssign(
            (array) $request->validated('issue_ids', []),
            (int) $request->validated('assignee_id'),
            $this->scope->branchIdsFor($request->user()),
            $request->user(),
            $request->validated('priority'),
            $request->validated('assigned_role'),
        );

        return back()->with('status', sprintf(
            'Penugasan massal: %d ditugaskan, %d dilewati (di luar cakupan cabang Anda).',
            $result['assigned'], $result['skipped'],
        ));
    }

    private function resolveBranch(SatusehatBranchTransitionRequest $request, int $branch): Branch
    {
        abort_unless(in_array($branch, $this->scope->branchIdsFor($request->user()), true), 404);
        $model = $this->branches->find($branch);
        abort_if($model === null, 404);

        return $model;
    }
}
