<?php

namespace App\Modules\PatientMerge\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PatientMerge\Exceptions\PatientMergeBlockedException;
use App\Modules\PatientMerge\Interfaces\PatientMergeCaseRepositoryInterface;
use App\Modules\PatientMerge\Models\PatientMergeCase;
use App\Modules\PatientMerge\Requests\ApprovePatientMergeCaseRequest;
use App\Modules\PatientMerge\Requests\PatientMergeReasonRequest;
use App\Modules\PatientMerge\Services\PatientMergeCaseService;
use App\Modules\PatientMerge\Services\PatientMergeExecutionService;
use App\Modules\PatientMerge\Services\PatientMergeScope;
use App\Modules\PatientMerge\Support\PatientMergeStatus;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** "Review & Approval": the reviewer's queue, approve-and-merge, reject. */
class PatientMergeReviewController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly PatientMergeCaseRepositoryInterface $caseRepository,
        private readonly PatientMergeCaseService $cases,
        private readonly PatientMergeExecutionService $execution,
        private readonly PatientMergeScope $scope,
    ) {}

    public function index(Request $request): View
    {
        $statuses = [PatientMergeStatus::PENDING_REVIEW, PatientMergeStatus::REVERSAL_REQUIRED];

        return view('patient-merge.review.index', [
            'cases' => $this->caseRepository->paginateScoped(
                $this->scope->branchIdsFor($request->user()),
                $statuses,
                $request->only(['status', 'date_from', 'date_to', 'risk', 'rm', 'requested_by']),
                20,
            ),
            'filters' => $request->only(['status', 'date_from', 'date_to', 'risk', 'rm']),
            'statuses' => $statuses,
        ]);
    }

    public function approve(ApprovePatientMergeCaseRequest $request, PatientMergeCase $patientMergeCase): RedirectResponse
    {
        try {
            $case = $this->execution->approveAndMerge($patientMergeCase, $request->user(), $request->validated('review_note'));
        } catch (PatientMergeBlockedException $blocked) {
            return back()->withErrors(['case' => $blocked->getMessage()]);
        }

        return redirect()->route('patient-merge.cases.show', $case)
            ->with('status', 'Penggabungan '.$case->case_number.' selesai. Data pasien sumber kini menunjuk ke pasien canonical.');
    }

    public function reject(PatientMergeReasonRequest $request, PatientMergeCase $patientMergeCase): RedirectResponse
    {
        $this->authorize('review', $patientMergeCase);
        $this->cases->reject($patientMergeCase, $request->user(), (string) $request->validated('reason'));

        return redirect()->route('patient-merge.cases.show', $patientMergeCase)->with('status', 'Pengajuan ditolak.');
    }
}
