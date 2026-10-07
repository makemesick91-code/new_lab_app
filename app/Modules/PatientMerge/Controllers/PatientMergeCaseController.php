<?php

namespace App\Modules\PatientMerge\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PatientMerge\Exceptions\PatientMergeBlockedException;
use App\Modules\PatientMerge\Interfaces\PatientMergeCaseRepositoryInterface;
use App\Modules\PatientMerge\Models\PatientMergeCase;
use App\Modules\PatientMerge\Requests\PatientMergeReasonRequest;
use App\Modules\PatientMerge\Requests\ResolvePatientMergeCaseRequest;
use App\Modules\PatientMerge\Requests\StorePatientMergeCaseRequest;
use App\Modules\PatientMerge\Services\PatientIdentityComparator;
use App\Modules\PatientMerge\Services\PatientMergeCaseService;
use App\Modules\PatientMerge\Services\PatientMergeScope;
use App\Modules\PatientMerge\Services\PatientMergeSelectionService;
use App\Modules\PatientMerge\Support\PatientMergeStatus;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Manual selection, the request lifecycle and the case detail page.
 * Thin by design: every decision is in PatientMergeCaseService.
 */
class PatientMergeCaseController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly PatientMergeCaseService $cases,
        private readonly PatientMergeCaseRepositoryInterface $caseRepository,
        private readonly PatientMergeSelectionService $selection,
        private readonly PatientMergeScope $scope,
        private readonly PatientIdentityComparator $comparator,
    ) {}

    /** "Pilih Pasien Manual": two independent server-side searches. */
    public function manual(Request $request): View
    {
        $this->authorize('create', PatientMergeCase::class);
        $user = $request->user();
        $patientA = $this->selection->findSelectable($user, $request->integer('a') ?: null);
        $patientB = $this->selection->findSelectable($user, $request->integer('b') ?: null);
        $samePatient = $patientA !== null && $patientB !== null && $patientA->id === $patientB->id;

        return view('patient-merge.manual', [
            'queryA' => (string) $request->query('qa', ''),
            'queryB' => (string) $request->query('qb', ''),
            'resultsA' => $this->selection->search($user, (string) $request->query('qa', '')),
            'resultsB' => $this->selection->search($user, (string) $request->query('qb', '')),
            'patientA' => $patientA,
            'patientB' => $patientB,
            'samePatient' => $samePatient,
            'crossBranch' => $patientA !== null && $patientB !== null && $this->scope->isCrossBranch($patientA, $patientB),
            // Masked display values only; full values never leave the server.
            'comparison' => $patientA !== null && $patientB !== null && ! $samePatient ? $this->comparator->compare($patientA, $patientB) : null,
            'minLength' => PatientMergeSelectionService::MIN_QUERY_LENGTH,
            'mayCrossBranch' => $this->scope->mayResolveCrossBranch($user),
        ]);
    }

    public function store(StorePatientMergeCaseRequest $request): RedirectResponse
    {
        $case = $this->cases->create(
            $request->user(),
            (int) $request->validated('patient_a_id'),
            (int) $request->validated('patient_b_id'),
            (string) $request->validated('request_reason'),
        );

        return redirect()->route('patient-merge.cases.show', $case)
            ->with('status', 'Pengajuan merge '.$case->case_number.' dibuat. Selesaikan rekonsiliasi identitas lalu kirim untuk review.');
    }

    /** "Pengajuan Merge": open cases in scope. */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', PatientMergeCase::class);

        return view('patient-merge.cases.index', [
            'cases' => $this->caseRepository->paginateScoped(
                $this->scope->branchIdsFor($request->user()),
                [PatientMergeStatus::DRAFT, PatientMergeStatus::PENDING_REVIEW],
                $request->only(['status', 'date_from', 'date_to', 'risk', 'rm']),
                20,
            ),
            'filters' => $request->only(['status', 'date_from', 'date_to', 'risk', 'rm']),
            'statuses' => [PatientMergeStatus::DRAFT, PatientMergeStatus::PENDING_REVIEW],
        ]);
    }

    public function show(Request $request, PatientMergeCase $patientMergeCase): View
    {
        $this->authorize('view', $patientMergeCase);
        $patientMergeCase->load(['requester:id,name', 'reviewer:id,name', 'fieldResolutions.resolver:id,name']);

        return view('patient-merge.cases.show', [
            'case' => $patientMergeCase,
            'preview' => $this->cases->preview($patientMergeCase),
        ]);
    }

    public function resolve(ResolvePatientMergeCaseRequest $request, PatientMergeCase $patientMergeCase): RedirectResponse
    {
        $canonical = $request->validated('canonical_patient_id');

        $this->cases->resolve(
            $patientMergeCase,
            $request->user(),
            $request->fieldChoices(),
            $canonical === null ? null : (int) $canonical,
        );

        return redirect()->route('patient-merge.cases.show', $patientMergeCase)->with('status', 'Rekonsiliasi identitas disimpan.');
    }

    public function submit(Request $request, PatientMergeCase $patientMergeCase): RedirectResponse
    {
        $this->authorize('submit', $patientMergeCase);

        try {
            $this->cases->submit($patientMergeCase, $request->user());
        } catch (PatientMergeBlockedException $blocked) {
            return back()->withErrors(['case' => $blocked->getMessage()]);
        }

        return redirect()->route('patient-merge.cases.show', $patientMergeCase)->with('status', 'Pengajuan dikirim untuk review.');
    }

    public function withdraw(Request $request, PatientMergeCase $patientMergeCase): RedirectResponse
    {
        $this->authorize('withdraw', $patientMergeCase);
        $this->cases->withdraw($patientMergeCase, $request->user());

        return redirect()->route('patient-merge.cases.show', $patientMergeCase)->with('status', 'Pengajuan ditarik kembali ke Draf.');
    }

    public function cancel(PatientMergeReasonRequest $request, PatientMergeCase $patientMergeCase): RedirectResponse
    {
        $this->authorize('cancel', $patientMergeCase);
        $this->cases->cancel($patientMergeCase, $request->user(), (string) $request->validated('reason'));

        return redirect()->route('patient-merge.cases.show', $patientMergeCase)->with('status', 'Pengajuan dibatalkan.');
    }
}
