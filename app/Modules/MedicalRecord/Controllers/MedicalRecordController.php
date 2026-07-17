<?php

namespace App\Modules\MedicalRecord\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordHandwritingRepositoryInterface;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Requests\FinalizeMedicalRecordRequest;
use App\Modules\MedicalRecord\Requests\StoreMedicalRecordRequest;
use App\Modules\MedicalRecord\Requests\UpdateMedicalRecordRequest;
use App\Modules\MedicalRecord\Services\DiagnosisRolloutService;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\MedicalRecord\Services\PatientRmWorkspaceResolver;
use App\Modules\Patient\Services\CrossBranchPatientLookupService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MedicalRecordController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MedicalRecordService $service,
        private readonly MedicalRecordRepositoryInterface $medicalRecords,
        private readonly MedicalRecordHandwritingRepositoryInterface $handwritings,
        private readonly DiagnosisRolloutService $diagnosisRollout,
    ) {}

    public function index(Request $request, CrossBranchPatientLookupService $rmLookup): View
    {
        $this->authorize('viewAny', MedicalRecord::class);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'visit_date_from' => $request->string('visit_date_from')->toString() ?: null,
            'visit_date_to' => $request->string('visit_date_to')->toString() ?: null,
        ];

        return view('rme.visits.medical-record.index', [
            'medicalRecords' => $this->service->paginate($filters),
            'filters' => $filters,
            'statuses' => MedicalRecord::STATUSES,
            'rmLookup' => $rmLookup->lookupByMedicalRecordNumberAcrossBranches($request->string('rm_lookup')->toString()),
        ]);
    }

    public function show(Request $request, ClinicVisit $clinicVisit, PatientRmWorkspaceResolver $workspace): View|RedirectResponse
    {
        $clinicVisit->loadMissing('patient');
        $patientId = $clinicVisit->patient_id;

        // Sprint 64.0 — patient-centric redirect. Any visit's RM URL resolves to
        // the patient's canonical workspace: the earliest non-cancelled RME visit
        // REGARDLESS of whether an MR exists yet (zero-MR fix). When the opened
        // visit is NOT canonical, bounce to the canonical URL carrying
        // source_visit_id so a new sheet defaults to the visit the doctor came
        // from. Deterministic anchor → no redirect loop. Null anchor only when
        // the opened visit is not an eligible RME-branch visit → 404 as before.
        // Check eligibility before authorize so non-RME visits 404 instead of 403.
        $canonicalVisit = $workspace->resolveCanonicalWorkspaceVisit($patientId);
        abort_if($canonicalVisit === null, 404);

        $this->authorize('view', $clinicVisit);

        if ($canonicalVisit->id !== $clinicVisit->id) {
            return redirect()->route('rme.visits.medical-record.show', [
                $canonicalVisit,
                'source_visit_id' => $clinicVisit->id,
            ]);
        }

        $workspaceVisit = $canonicalVisit;

        // All sheets of the patient, chronological (swipe order).
        $sheets = $workspace->sheetsForPatient($patientId);

        // The visit the doctor opened the workspace from (IDOR-validated).
        $sourceVisit = $workspace->resolveSourceVisit($patientId, $request->integer('source_visit_id') ?: null);

        // Sprint 64.0 zero-MR fix — the patient has visits but no RM sheet yet.
        // Render a safe empty-state workspace shell (no $medicalRecord) instead
        // of 404ing, with an opt-in "Buat Lembar RM Pertama" control for users
        // who may manage RME. The first sheet is created against the source
        // visit (the one the doctor came from) or the canonical visit otherwise.
        if ($sheets->isEmpty()) {
            $this->authorize('viewAny', MedicalRecord::class);

            $addSheetVisit = $workspaceVisit;
            $addSheetVisit->loadMissing(['patient', 'doctor', 'initialTreatment', 'branch']);

            return view('rme.visits.medical-record.empty', [
                'workspaceVisit' => $workspaceVisit,
                'sourceVisit' => $sourceVisit,
                'addSheetVisit' => $addSheetVisit,
                'patient' => $addSheetVisit->patient,
                'canEdit' => auth()->user()?->can('create', [MedicalRecord::class, $workspaceVisit]) ?? false,
            ]);
        }

        // Active sheet: explicit ?sheet=, else the source visit's sheet, else the
        // canonical visit's sheet, else the first sheet. Always validated against
        // the patient's own sheet set (no cross-patient access).
        $activeSheet = null;
        if ($request->integer('sheet')) {
            $activeSheet = $sheets->firstWhere('id', $request->integer('sheet'));
        }
        if ($activeSheet === null && $sourceVisit !== null) {
            $activeSheet = $sheets->firstWhere('clinic_visit_id', $sourceVisit->id);
        }
        if ($activeSheet === null) {
            $activeSheet = $sheets->firstWhere('clinic_visit_id', $workspaceVisit->id) ?? $sheets->first();
        }

        $this->authorize('view', $activeSheet);

        // The active sheet's OWN visit drives the header + the update/finalize/
        // handwriting forms, so finalize transitions only that visit (gate intact).
        $medicalRecord = $activeSheet;
        $activeVisit = $activeSheet->clinicVisit;
        $activeVisit->loadMissing(['patient', 'doctor', 'initialTreatment', 'followUpOf', 'branch']);

        // "Tambah Lembar RM" target — the source visit when it has no sheet yet.
        $addSheetVisit = ($sourceVisit !== null && $sheets->firstWhere('clinic_visit_id', $sourceVisit->id) === null)
            ? $sourceVisit
            : null;

        $notice = ($sourceVisit !== null && $sourceVisit->id !== $workspaceVisit->id)
            ? 'Anda membuka buku RM tulisan tangan dari Kunjungan #'.$sourceVisit->visit_number.'. Halaman baru ditambahkan ke buku RM kunjungan pertama.'
            : null;

        // Sprint 64.0.2 — one patient = one handwriting RM book on the canonical
        // visit. Virtual navigation merges same-patient pages from all sheets;
        // new pages are always added to the canonical medical record.
        $handwritingBook = $workspace->orderedHandwritingBookForPatient($patientId);
        $handwritingRecord = $workspace->canonicalMedicalRecord($patientId);
        $totalRmPages = max($handwritingBook->count(), 1);

        $activePageNumber = $request->integer('rm_page')
            ?: (int) $request->session()->get('focus_rm_page', 1);
        $activePageNumber = max(1, min($activePageNumber, $totalRmPages));

        $activeRmPage = $handwritingBook->firstWhere('virtual_page_number', $activePageNumber)
            ?? $handwritingBook->first()
            ?? [
                'page_number' => 1,
                'virtual_page_number' => 1,
                'storage_page_number' => 1,
                'is_legacy' => true,
                'preview_url' => null,
                'saved_at' => null,
                'has_content' => false,
                'medical_record_id' => $handwritingRecord?->id ?? $activeSheet->id,
                'clinic_visit_id' => $handwritingRecord?->clinic_visit_id ?? $activeSheet->clinic_visit_id,
            ];

        $handwritingFormRecord = MedicalRecord::query()->find($activeRmPage['medical_record_id'])
            ?? $handwritingRecord
            ?? $activeSheet;
        $handwritingFormVisit = $handwritingFormRecord->clinicVisit ?? $activeVisit;

        $rmPageNumbers = $handwritingBook->pluck('virtual_page_number');
        if ($rmPageNumbers->isEmpty()) {
            $rmPageNumbers = collect([1]);
        }

        $nextRmPageNumber = $handwritingRecord !== null
            ? $this->handwritings->nextPageNumber($handwritingRecord->id)
            : 1;

        $prevRmPage = $activePageNumber > 1 ? $activePageNumber - 1 : null;
        $nextRmPage = $activePageNumber < $totalRmPages ? $activePageNumber + 1 : null;

        return view('rme.visits.medical-record.show', [
            'clinicVisit' => $activeVisit,
            'medicalRecord' => $medicalRecord,
            'workspaceVisit' => $workspaceVisit,
            'sheets' => $sheets,
            'activeSheet' => $activeSheet,
            'sourceVisit' => $sourceVisit,
            'addSheetVisit' => $addSheetVisit,
            'notice' => $notice,
            'patient' => $activeVisit->patient,
            'activeRmPage' => $activeRmPage,
            'activePageNumber' => $activePageNumber,
            'totalRmPages' => $totalRmPages,
            'rmPageNumbers' => $rmPageNumbers,
            'nextRmPageNumber' => $nextRmPageNumber,
            'handwritingRecord' => $handwritingRecord,
            'handwritingFormRecord' => $handwritingFormRecord,
            'handwritingFormVisit' => $handwritingFormVisit,
            'prevRmPage' => $prevRmPage,
            'nextRmPage' => $nextRmPage,
            'hasRequiredHandwriting' => $this->service->hasRequiredHandwriting($activeSheet),
            // SATUSEHAT-4B — branch-scoped rollout state for the active sheet
            // (drives the informational/warning/pilot banner + override form).
            'diagnosisEnforcement' => $this->diagnosisRollout->enforcementStateFor($activeSheet),
        ]);
    }

    public function store(StoreMedicalRecordRequest $request, ClinicVisit $clinicVisit, PatientRmWorkspaceResolver $workspace): RedirectResponse
    {
        $patientId = $clinicVisit->patient_id;
        $canonicalVisit = $workspace->resolveCanonicalWorkspaceVisit($patientId) ?? $clinicVisit;
        $sourceVisitId = $request->integer('source_visit_id') ?: $clinicVisit->id;

        $patientHasSheets = MedicalRecord::query()
            ->where('patient_id', $patientId)
            ->whereIn('branch_id', $workspace->rmeBranchIds())
            ->exists();

        // Sprint 64.0.2 — the handwriting RM book starts on the canonical visit
        // when the patient has zero sheets. Per-visit "Tambah Lembar RM" (when
        // sheets already exist) still creates on the submitted visit.
        $targetVisit = $patientHasSheets ? $clinicVisit : $canonicalVisit;

        $this->authorize('create', [MedicalRecord::class, $targetVisit]);

        $existing = $this->medicalRecords->findByVisitId($targetVisit->id);

        if ($existing === null) {
            $this->service->createDraft($targetVisit, auth()->id(), array_merge(
                $request->validated(),
                ['source_visit_id' => $sourceVisitId],
            ));
            $status = 'Rekam medis berhasil dibuat.';
        } else {
            $status = 'Rekam medis sudah ada untuk kunjungan ini.';
        }

        $redirectVisit = $workspace->resolveCanonicalWorkspaceVisit($patientId) ?? $targetVisit;
        $redirectParams = [$redirectVisit];
        if ($sourceVisitId !== $redirectVisit->id) {
            $redirectParams['source_visit_id'] = $sourceVisitId;
        }

        return redirect()
            ->route('rme.visits.medical-record.show', $redirectParams)
            ->with('status', $status);
    }

    public function update(UpdateMedicalRecordRequest $request, ClinicVisit $clinicVisit, MedicalRecord $medicalRecord): RedirectResponse
    {
        abort_if($medicalRecord->clinic_visit_id !== $clinicVisit->id, 404);

        $this->authorize('update', $medicalRecord);

        $this->service->updateDraft($medicalRecord, $request->validated());

        return redirect()
            ->route('rme.visits.medical-record.show', $clinicVisit)
            ->with('status', 'Rekam medis berhasil diperbarui.');
    }

    public function finalize(FinalizeMedicalRecordRequest $request, ClinicVisit $clinicVisit, MedicalRecord $medicalRecord): RedirectResponse
    {
        abort_if($medicalRecord->clinic_visit_id !== $clinicVisit->id, 404);

        $this->authorize('finalize', $medicalRecord);

        $this->service->finalize($medicalRecord);

        return redirect()
            ->route('rme.visits.medical-record.show', $clinicVisit)
            ->with('status', 'Rekam medis berhasil difinalisasi.');
    }
}
