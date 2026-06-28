<?php

namespace App\Modules\MedicalRecord\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Requests\FinalizeMedicalRecordRequest;
use App\Modules\MedicalRecord\Requests\StoreMedicalRecordRequest;
use App\Modules\MedicalRecord\Requests\UpdateMedicalRecordRequest;
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
        $canonicalVisit = $workspace->resolveCanonicalWorkspaceVisit($patientId);
        abort_if($canonicalVisit === null, 404);

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

            $addSheetVisit = $sourceVisit ?? $workspaceVisit;
            $addSheetVisit->loadMissing(['patient', 'doctor', 'initialTreatment', 'branch']);

            return view('rme.visits.medical-record.empty', [
                'workspaceVisit' => $workspaceVisit,
                'sourceVisit' => $sourceVisit,
                'addSheetVisit' => $addSheetVisit,
                'patient' => $addSheetVisit->patient,
                'canEdit' => auth()->user()?->can('create', [MedicalRecord::class, $addSheetVisit]) ?? false,
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
            ? 'Anda membuka RM pasien dari Kunjungan #'.$sourceVisit->visit_number.'. Lembar baru akan ditautkan ke kunjungan ini.'
            : null;

        // Sprint 60.1 — single active RM canvas pagination, now scoped to the
        // ACTIVE sheet's medical record (not the canonical visit's).
        $rmPages = $activeSheet->orderedHandwritingPages();
        $totalRmPages = max($rmPages->count(), 1);

        $activePageNumber = $request->integer('rm_page')
            ?: (int) $request->session()->get('focus_rm_page', 1);
        $activePageNumber = max(1, min($activePageNumber, $totalRmPages));

        $activeRmPage = $rmPages->firstWhere('page_number', $activePageNumber)
            ?? $rmPages->first();

        $rmPageNumbers = $rmPages->pluck('page_number');
        $nextRmPageNumber = ($rmPages->max('page_number') ?? 1) + 1;

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
        ]);
    }

    public function store(StoreMedicalRecordRequest $request, ClinicVisit $clinicVisit): RedirectResponse
    {
        $this->authorize('create', [MedicalRecord::class, $clinicVisit]);

        // Sprint 64.0 — idempotent open/create. UNIQUE(clinic_visit_id) means a
        // visit owns at most one sheet. If this visit already has one (e.g. the
        // empty-state "Buat Lembar RM Pertama" button raced, or "Tambah Lembar"
        // pointed at an existing sheet), just activate it — never create a
        // duplicate. The patient-centric show() then resolves the active sheet.
        $existing = $this->medicalRecords->findByVisitId($clinicVisit->id);

        if ($existing === null) {
            $this->service->createDraft($clinicVisit, auth()->id(), $request->validated());
            $status = 'Rekam medis berhasil dibuat.';
        } else {
            $status = 'Rekam medis sudah ada untuk kunjungan ini.';
        }

        return redirect()
            ->route('rme.visits.medical-record.show', $clinicVisit)
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
