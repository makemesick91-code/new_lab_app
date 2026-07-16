<?php

namespace App\Modules\Satusehat\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Interfaces\SatusehatDataQualityIssueRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Services\DataQuality\SatusehatDataQualityScanService;
use App\Modules\Satusehat\Services\DataQuality\SatusehatOnboardingChecklistService;
use App\Modules\Satusehat\Services\DataQuality\SatusehatOperationalReadinessService;
use App\Modules\Satusehat\Support\SatusehatWorkspaceScope;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * SATUSEHAT-4A — read side of the operational readiness workspace.
 * Branch scope is ALWAYS the server-resolved RME-enabled set — a request
 * branch_id can only narrow within it, never widen (IDOR-safe).
 */
class SatusehatReadinessController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly SatusehatOperationalReadinessService $readiness,
        private readonly SatusehatDataQualityIssueRepositoryInterface $issues,
        private readonly BranchService $branches,
        private readonly SatusehatWorkspaceScope $scope,
    ) {}

    public function index(Request $request): View
    {
        $branchIds = $this->scope->branchIdsFor($request->user());

        $filters = $request->only([
            'search', 'branch_id', 'doctor_id', 'readiness_status',
            'dental_readiness_status', 'review_status', 'visit_date_from', 'visit_date_to',
        ]);

        return view('satusehat.readiness.index', [
            'metrics' => $this->readiness->metrics($branchIds),
            'board' => $this->readiness->candidateBoard($filters, $branchIds),
            'practitioners' => $this->readiness->practitionerReadiness(),
            'orgLocation' => $this->readiness->organizationLocationReadiness(),
            'treatmentSummary' => $this->readiness->treatmentMappingSummary(),
            'checklist' => app(SatusehatOnboardingChecklistService::class)->report(),
            'branches' => $this->branches->listRmeEnabled(),
            'filters' => $filters,
            'integrationEnabled' => (bool) config('satusehat.enabled'),
        ]);
    }

    public function issues(Request $request): View
    {
        $branchIds = $this->scope->branchIdsFor($request->user());

        $filters = $request->only([
            'search', 'branch_id', 'doctor_id', 'status', 'severity',
            'rule_code', 'owner_role', 'assigned_to', 'detected_from', 'detected_to', 'open_only',
        ]);

        return view('satusehat.readiness.issues', [
            'issues' => $this->issues->paginate($filters, $branchIds),
            'aggregates' => $this->issues->aggregates($branchIds),
            'branches' => $this->branches->listRmeEnabled(),
            'ruleCodes' => collect((array) config('satusehat_data_quality.rules', []))
                ->map(fn (string $class) => app($class)->code())->values()->all(),
            'filters' => $filters,
        ]);
    }

    public function issueShow(Request $request, int $issue): View
    {
        $branchIds = $this->scope->branchIdsFor($request->user());
        $record = $this->issues->findInBranches($issue, $branchIds);
        abort_if($record === null, 404);
        $this->authorize('view', $record);

        $record->load(['patient:id,name,medical_record_number', 'doctor:id,name', 'clinicVisit:id,visit_number,visit_date', 'assignedTo:id,name', 'candidate:id,readiness_status,review_status,dental_readiness_status']);

        $timeline = SatusehatAuditLog::query()
            ->where('entity_type', 'data_quality_issue')
            ->where('entity_id', $record->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('satusehat.readiness.issue-show', [
            'issue' => $record,
            'timeline' => $timeline,
        ]);
    }

    /**
     * Bounded, branch-scoped readiness + issue recalculation (never external).
     */
    public function recalculate(Request $request, SatusehatDataQualityScanService $scan): RedirectResponse
    {
        $this->authorize('viewAny', SatusehatDataQualityIssue::class);
        abort_unless($request->user()->can('manage_satusehat_remediation'), 403);

        // Branch-scoped actors may only recalculate their own branch; a
        // requested branch id can only narrow within the resolved scope.
        $scoped = $this->scope->branchIdsFor($request->user());
        if ($scoped === []) {
            return back()->withErrors(['branch_id' => 'Konteks cabang tidak dapat ditentukan.']);
        }
        $branchId = is_numeric($request->input('branch_id')) ? (int) $request->input('branch_id') : null;
        if ($branchId !== null && ! in_array($branchId, $scoped, true)) {
            $branchId = null;
        }
        if ($branchId === null && count($scoped) === 1) {
            $branchId = $scoped[0];
        }
        $limit = is_numeric($request->input('limit')) ? (int) $request->input('limit') : null;

        $summary = $scan->scan(
            branchId: $branchId,
            from: is_string($request->input('from')) ? $request->input('from') : null,
            to: is_string($request->input('to')) ? $request->input('to') : null,
            limit: $limit,
            actor: $request->user(),
        );

        return back()->with('status', sprintf(
            'Kalkulasi ulang selesai: %d kandidat dipindai, %d isu terdeteksi, %d isu baru, %d selesai otomatis.',
            $summary['scanned'], $summary['detected'], $summary['created'], $summary['auto_resolved'],
        ));
    }
}
