<?php

namespace App\Modules\Satusehat\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Satusehat\Interfaces\SatusehatCandidateRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Requests\BulkSatusehatSubmissionRequest;
use App\Modules\Satusehat\Requests\ExcludeSatusehatCandidateRequest;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use App\Modules\Satusehat\Services\SatusehatCandidateService;
use App\Modules\Satusehat\Services\SatusehatFhirPreviewBuilder;
use App\Modules\Satusehat\Services\SatusehatReadinessService;
use App\Modules\Satusehat\Services\SatusehatSubmissionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Controlled SATUSEHAT submission filter/review workspace. Thin controller:
 * authorization, server-side branch scope, and delegation to services. No
 * business logic here, and no external request is ever made.
 */
class SatusehatSubmissionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly SatusehatCandidateRepositoryInterface $candidates,
        private readonly SatusehatCandidateService $candidateService,
        private readonly SatusehatReadinessService $readiness,
        private readonly SatusehatFhirPreviewBuilder $preview,
        private readonly SatusehatSubmissionService $submissions,
        private readonly SatusehatAuditLogger $audit,
        private readonly BranchService $branches,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', SatusehatCandidate::class);

        $branchIds = $this->branches->rmeEnabledIds();
        $filters = $this->filters($request, $branchIds);

        return view('satusehat.submissions.index', [
            'candidates' => $this->candidates->paginate($filters, $branchIds),
            'filters' => $filters,
            'rmeBranches' => $this->branches->listRmeEnabled(),
            'doctors' => Doctor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'readinessStatuses' => SatusehatCandidate::READINESS_STATUSES,
            'reviewStatuses' => SatusehatCandidate::REVIEW_STATUSES,
            'environment' => (string) config('satusehat.environment'),
            'integrationEnabled' => (bool) config('satusehat.enabled'),
        ]);
    }

    public function show(SatusehatCandidate $candidate): View
    {
        $this->authorize('view', $candidate);

        $candidate->load(['clinicVisit', 'patient', 'doctor', 'medicalRecord', 'approvedBy', 'excludedBy', 'reviewedBy']);

        $timeline = SatusehatAuditLog::query()
            ->where('entity_type', 'satusehat_candidate')
            ->where('entity_id', $candidate->id)
            ->with('actor:id,name')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('satusehat.submissions.show', [
            'candidate' => $candidate,
            'timeline' => $timeline,
            'environment' => (string) config('satusehat.environment'),
            'integrationEnabled' => (bool) config('satusehat.enabled'),
        ]);
    }

    public function preview(SatusehatCandidate $candidate): View
    {
        $this->authorize('preview', $candidate);

        $result = $this->readiness->evaluate($candidate->clinicVisit);
        $preview = $this->preview->build($candidate->clinicVisit, $result);

        $this->audit->log('satusehat_candidate', $candidate->id, SatusehatAuditLog::EVENT_PREVIEW_OPENED,
            'Preview FHIR lokal dibuka', [], $candidate->branch_id);

        return view('satusehat.submissions.preview', [
            'candidate' => $candidate,
            'preview' => $preview,
        ]);
    }

    public function refresh(SatusehatCandidate $candidate): RedirectResponse
    {
        // Review-tier: recomputation can revoke an approval on source drift.
        $this->authorize('refresh', $candidate);

        $this->candidateService->refresh($candidate, Auth::user());

        return back()->with('success', 'Readiness kandidat diperbarui.');
    }

    public function approve(SatusehatCandidate $candidate): RedirectResponse
    {
        $this->authorize('approve', $candidate);

        $this->candidateService->approve($candidate, Auth::user());

        return back()->with('success', 'Kandidat disetujui.');
    }

    public function exclude(ExcludeSatusehatCandidateRequest $request, SatusehatCandidate $candidate): RedirectResponse
    {
        $this->authorize('exclude', $candidate);

        $this->candidateService->exclude($candidate, Auth::user(), (string) $request->validated('exclusion_reason'));

        return back()->with('success', 'Kandidat dikecualikan.');
    }

    public function bulk(BulkSatusehatSubmissionRequest $request): RedirectResponse
    {
        $branchIds = $this->branches->rmeEnabledIds();
        $ids = array_map('intval', $request->validated('candidate_ids'));
        $action = (string) $request->validated('action');
        $user = Auth::user();

        // Server-side IDOR boundary: only in-scope candidates survive.
        $resolved = $this->candidates->idsInBranches($ids, $branchIds);

        if ($action === 'prepare') {
            abort_unless($user->can('send_satusehat_submissions'), 403);
            $batch = $this->submissions->prepare($resolved->pluck('id')->all(), $branchIds, $user);

            return back()->with('success', "Disiapkan untuk pengiriman SATUSEHAT-2 (batch #{$batch->id}). Tidak ada data yang dikirim — integrasi eksternal nonaktif.");
        }

        abort_unless($user->can('review_satusehat_submissions'), 403);
        $count = 0;

        foreach ($resolved as $candidate) {
            if ($action === 'approve' && $candidate->canApprove()) {
                $this->candidateService->approve($candidate, $user);
                $count++;
            } elseif ($action === 'exclude') {
                $this->candidateService->exclude($candidate, $user, (string) $request->validated('exclusion_reason'));
                $count++;
            }
        }

        return back()->with('success', "{$count} kandidat diproses ({$action}).");
    }

    /**
     * @param  list<int>  $branchIds
     * @return array<string, mixed>
     */
    private function filters(Request $request, array $branchIds): array
    {
        $branchId = $request->integer('branch_id') ?: null;
        // IDOR-safe: a branch_id outside the allowed set is dropped.
        if ($branchId !== null && ! in_array($branchId, $branchIds, true)) {
            $branchId = null;
        }

        return [
            'search' => $request->string('search')->trim()->toString() ?: null,
            'branch_id' => $branchId,
            'doctor_id' => $request->integer('doctor_id') ?: null,
            'visit_date_from' => $request->string('visit_date_from')->toString() ?: null,
            'visit_date_to' => $request->string('visit_date_to')->toString() ?: null,
            'readiness_status' => $request->string('readiness_status')->toString() ?: null,
            'review_status' => $request->string('review_status')->toString() ?: null,
        ];
    }
}
