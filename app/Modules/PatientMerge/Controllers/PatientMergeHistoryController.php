<?php

namespace App\Modules\PatientMerge\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PatientMerge\Exceptions\PatientMergeBlockedException;
use App\Modules\PatientMerge\Interfaces\PatientMergeCaseRepositoryInterface;
use App\Modules\PatientMerge\Models\PatientMergeCase;
use App\Modules\PatientMerge\Requests\PatientMergeReasonRequest;
use App\Modules\PatientMerge\Services\PatientMergeReversalService;
use App\Modules\PatientMerge\Services\PatientMergeScope;
use App\Modules\PatientMerge\Support\PatientMergeStatus;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** "Riwayat Merge" and the Merge Reversal Review actions. */
class PatientMergeHistoryController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly PatientMergeCaseRepositoryInterface $caseRepository,
        private readonly PatientMergeReversalService $reversals,
        private readonly PatientMergeScope $scope,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PatientMergeCase::class);
        $filters = $request->only(['status', 'date_from', 'date_to', 'rm', 'requested_by', 'reviewed_by']);

        return view('patient-merge.history.index', [
            'cases' => $this->caseRepository->paginateScoped($this->scope->branchIdsFor($request->user()), PatientMergeStatus::HISTORY, $filters, 20),
            'filters' => $filters,
            'statuses' => PatientMergeStatus::HISTORY,
        ]);
    }

    public function requestReversal(PatientMergeReasonRequest $request, PatientMergeCase $patientMergeCase): RedirectResponse
    {
        $this->authorize('reverse', $patientMergeCase);
        $case = $this->reversals->request($patientMergeCase, $request->user(), (string) $request->validated('reason'));

        $message = ($case->reversal_assessment['mode'] ?? null) === PatientMergeReversalService::MODE_SAFE
            ? 'Review reversal dibuka. Reversal dinilai aman dan dapat dijalankan oleh peninjau lain.'
            : 'Review reversal dibuka. Reversal TIDAK dapat dijalankan otomatis — diperlukan rekonsiliasi manual yang diawasi.';

        return redirect()->route('patient-merge.cases.show', $case)->with('status', $message);
    }

    public function executeReversal(Request $request, PatientMergeCase $patientMergeCase): RedirectResponse
    {
        $this->authorize('reverse', $patientMergeCase);

        try {
            $case = $this->reversals->execute($patientMergeCase, $request->user());
        } catch (PatientMergeBlockedException $blocked) {
            return back()->withErrors(['case' => $blocked->getMessage()]);
        }

        return redirect()->route('patient-merge.cases.show', $case)->with('status', 'Reversal selesai. Kedua pasien kembali terpisah.');
    }

    public function dismissReversal(Request $request, PatientMergeCase $patientMergeCase): RedirectResponse
    {
        $this->authorize('reverse', $patientMergeCase);
        $case = $this->reversals->dismiss($patientMergeCase, $request->user());

        return redirect()->route('patient-merge.cases.show', $case)->with('status', 'Review reversal ditutup. Penggabungan tetap berlaku.');
    }
}
