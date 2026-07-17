<?php

namespace App\Modules\Satusehat\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Interfaces\SatusehatDataQualityIssueRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatBranchPilotProfile;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Requests\BranchReadinessIssueActionRequest;
use App\Modules\Satusehat\Services\Pilot\SatusehatBranchReadinessProfileService;
use App\Modules\Satusehat\Services\Pilot\SatusehatIssueSlaService;
use App\Modules\Satusehat\Services\Pilot\SatusehatPilotOperationsService;
use App\Modules\Satusehat\Support\SatusehatProductionActivationGuard;
use App\Modules\Satusehat\Support\SatusehatWorkspaceScope;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * SATUSEHAT-4C — branch readiness board, per-branch detail, pilot operations
 * dashboard, and issue SLA actions. Read side is branch-scoped server-side
 * (executive → all RME branches; Admin Klinik → pinned branch); nothing is
 * taken from the request branch_id beyond narrowing within scope (IDOR-safe).
 * Never HTTP; NIK/raw notes never rendered.
 */
class SatusehatBranchReadinessController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly SatusehatBranchReadinessProfileService $profiles,
        private readonly SatusehatPilotOperationsService $ops,
        private readonly SatusehatIssueSlaService $sla,
        private readonly SatusehatDataQualityIssueRepositoryInterface $issues,
        private readonly BranchService $branches,
        private readonly SatusehatWorkspaceScope $scope,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', SatusehatBranchPilotProfile::class);
        $branchIds = $this->scope->branchIdsFor($request->user());

        $board = array_values(array_filter(
            $this->profiles->board(),
            fn (array $row) => in_array((int) $row['branch_id'], $branchIds, true),
        ));

        return view('satusehat.branches.index', [
            'board' => $board,
            'aging' => $this->ops->issueAging($branchIds),
            'stages' => SatusehatBranchPilotProfile::STAGES,
            'productionBlocked' => ! app(SatusehatProductionActivationGuard::class)->isProductionAllowed(),
            'satusehat2Watch' => config('satusehat.sandbox_verified') !== true,
        ]);
    }

    public function show(Request $request, int $branch): View
    {
        $branchIds = $this->scope->branchIdsFor($request->user());
        abort_unless(in_array($branch, $branchIds, true), 404);

        $profile = $this->profiles->existingProfile($branch)
            ?? new SatusehatBranchPilotProfile([
                'environment' => (string) config('satusehat.environment'),
                'branch_id' => $branch,
                'pilot_status' => SatusehatBranchPilotProfile::STATUS_NONE,
                'readiness_stage' => SatusehatBranchPilotProfile::STAGE_NOT_STARTED,
            ]);
        $this->authorize('view', $profile);

        $snapshot = $this->profiles->computeSnapshot($branch);
        $eligibility = $this->profiles->eligibilityFor($branch);
        $branchModel = $this->branches->find($branch);

        $openIssues = $this->issues->paginate(['open_only' => true], [$branch]);

        return view('satusehat.branches.show', [
            'branch' => $branchModel,
            'profile' => $profile,
            'snapshot' => $snapshot,
            'eligibility' => $eligibility,
            'issues' => $openIssues,
            'canConfigure' => $request->user()->can('configure_satusehat_internal_pilot'),
            'canApprove' => $request->user()->can('approve_satusehat_internal_pilot'),
            'canRehearse' => $request->user()->can('run_satusehat_pilot_rehearsal'),
            'canRemediate' => $request->user()->can('manage_satusehat_branch_remediation'),
        ]);
    }

    public function pilotOperations(Request $request): View
    {
        abort_unless($request->user()->canAny([
            'view_satusehat_pilot_metrics', 'configure_satusehat_internal_pilot', 'approve_satusehat_internal_pilot',
        ]), 403);

        $branchIds = $this->scope->branchIdsFor($request->user());

        return view('satusehat.branches.pilot-operations', [
            'overview' => $this->ops->overview($branchIds),
        ]);
    }

    public function recalculate(Request $request, int $branch): RedirectResponse
    {
        $branchIds = $this->scope->branchIdsFor($request->user());
        abort_unless(in_array($branch, $branchIds, true), 404);

        $profile = $this->profiles->profileFor($branch);
        $this->authorize('recalculate', $profile);

        $this->profiles->recalculate($branch, $request->user());

        return back()->with('status', 'Kesiapan cabang berhasil dihitung ulang.');
    }

    public function assignIssue(BranchReadinessIssueActionRequest $request, int $issue): RedirectResponse
    {
        $record = $this->resolveIssue($request, $issue);

        $assigneeId = $request->validated('assigned_to');
        abort_if($assigneeId === null, 422, 'Penerima tugas (assigned_to) wajib diisi.');

        $this->sla->assign(
            $record,
            $request->user(),
            (int) $assigneeId,
            $request->validated('priority'),
            $request->validated('assigned_role'),
        );

        return back()->with('status', 'Isu ditugaskan beserta prioritas & SLA.');
    }

    public function escalateIssue(BranchReadinessIssueActionRequest $request, int $issue): RedirectResponse
    {
        $record = $this->resolveIssue($request, $issue);

        $this->sla->escalate($record, $request->user(), $request->validated('escalation_level'));

        return back()->with('status', 'Isu berhasil dieskalasi.');
    }

    public function reviewIssue(BranchReadinessIssueActionRequest $request, int $issue): RedirectResponse
    {
        $record = $this->resolveIssue($request, $issue);

        $this->sla->markReviewed($record, $request->user(), $request->validated('resolution_evidence'));

        return back()->with('status', 'Isu ditandai telah ditinjau.');
    }

    private function resolveIssue(Request $request, int $issue): SatusehatDataQualityIssue
    {
        abort_unless($request->user()->can('manage_satusehat_branch_remediation'), 403);

        $branchIds = $this->scope->branchIdsFor($request->user());
        $record = $this->issues->findInBranches($issue, $branchIds);
        abort_if($record === null, 404);

        return $record;
    }
}
