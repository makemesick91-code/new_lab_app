<?php

namespace App\Modules\Satusehat\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Interfaces\SatusehatDataQualityIssueRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Requests\AssignSatusehatIssueRequest;
use App\Modules\Satusehat\Requests\WaiveSatusehatIssueRequest;
use App\Modules\Satusehat\Services\DataQuality\SatusehatRemediationService;
use App\Modules\Satusehat\Support\SatusehatWorkspaceScope;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * SATUSEHAT-4A — write side of the remediation workspace. Every action is
 * branch-scope-resolved server-side, policy-checked, and state-validated in
 * the service (locked + audited). No action here ever touches the network.
 */
class SatusehatRemediationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly SatusehatRemediationService $remediation,
        private readonly SatusehatDataQualityIssueRepositoryInterface $issues,
        private readonly BranchService $branches,
        private readonly SatusehatWorkspaceScope $scope,
    ) {}

    public function acknowledge(Request $request, int $issue): RedirectResponse
    {
        $record = $this->resolveManaged($request, $issue);
        $this->remediation->acknowledge($record, $request->user());

        return back()->with('status', 'Isu diakui.');
    }

    public function assign(AssignSatusehatIssueRequest $request, int $issue): RedirectResponse
    {
        $record = $this->resolveManaged($request, $issue);
        $this->remediation->assign($record, $request->user(), (int) $request->validated('assigned_to'));

        return back()->with('status', 'Isu ditugaskan.');
    }

    public function start(Request $request, int $issue): RedirectResponse
    {
        $record = $this->resolveManaged($request, $issue);
        $this->remediation->startRemediation($record, $request->user());

        return back()->with('status', 'Perbaikan dimulai.');
    }

    public function requestReview(Request $request, int $issue): RedirectResponse
    {
        $record = $this->resolveManaged($request, $issue);
        $this->remediation->requestClinicalReview($record, $request->user());

        return back()->with('status', 'Review klinis diminta.');
    }

    public function resolve(Request $request, int $issue): RedirectResponse
    {
        $record = $this->resolveManaged($request, $issue);
        $this->remediation->resolve($record, $request->user());

        return back()->with('status', 'Isu tervalidasi selesai.');
    }

    public function waive(WaiveSatusehatIssueRequest $request, int $issue): RedirectResponse
    {
        $record = $this->resolve404($issue);
        $this->authorize('waive', $record);

        $this->remediation->waive(
            $record,
            $request->user(),
            (string) $request->validated('reason'),
            $request->validated('waiver_expires_at'),
        );

        return back()->with('status', 'Isu dikecualikan (waiver) dengan alasan tercatat.');
    }

    public function reopen(Request $request, int $issue): RedirectResponse
    {
        $record = $this->resolveManaged($request, $issue);
        $this->remediation->reopen($record, $request->user());

        return back()->with('status', 'Isu dibuka kembali.');
    }

    private function resolveManaged(Request $request, int $issueId): SatusehatDataQualityIssue
    {
        $record = $this->resolve404($issueId);
        $this->authorize('manage', $record);

        return $record;
    }

    private function resolve404(int $issueId): SatusehatDataQualityIssue
    {
        $record = $this->issues->findInBranches($issueId, $this->scope->branchIdsFor(request()->user()));
        abort_if($record === null, 404);

        return $record;
    }
}
