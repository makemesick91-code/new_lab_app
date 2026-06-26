<?php

namespace App\Modules\MedicalRecord\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Requests\FinalizeMedicalRecordRequest;
use App\Modules\MedicalRecord\Requests\StoreMedicalRecordRequest;
use App\Modules\MedicalRecord\Requests\UpdateMedicalRecordRequest;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
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

    public function show(Request $request, ClinicVisit $clinicVisit): View
    {
        $medicalRecord = $this->medicalRecords->findByVisitId($clinicVisit->id);

        abort_if($medicalRecord === null, 404);

        $this->authorize('view', $medicalRecord);

        // Sprint 59.2 — the Medical Record page no longer renders the patient
        // visit history or the typed notes section, so only the relationships
        // still used by the header are eager-loaded. The patientVisitHistory
        // query is dropped here (the removed history card was its only
        // consumer), cutting unnecessary per-page load.
        // Hotfix 60.5 — `branch` is eager-loaded so the RM canvas/template can
        // render the branch-aware official Daengtisia header
        // ("CABANG {BRANCH} KLINIK GIGI DAENGTISIA").
        $clinicVisit->loadMissing(['patient', 'doctor', 'initialTreatment', 'followUpOf', 'branch']);

        // Prev/next arrow navigation restricted to visits that already have a
        // medical record, so the target RM page never 404s (Sprint 59).
        $adjacentVisits = app(ClinicVisitService::class)
            ->adjacentVisits($clinicVisit, requireMedicalRecord: true);

        // Sprint 60.1 — single active RM canvas pagination. The edit page must
        // load/render only ONE RM page canvas at a time; the rest are reached
        // through pagination (?rm_page=). `orderedHandwritingPages()` is still
        // used here for the lightweight page metadata (no <img> is rendered for
        // it), giving the total count and which pages exist. The selected page
        // comes from ?rm_page=, then a flashed `focus_rm_page` (so a save/add
        // lands on the page that was just written without changing the redirect
        // URL), defaulting to Page 1. It is clamped to the available range so a
        // stale/invalid value never renders a non-existent page.
        $rmPages = $medicalRecord->orderedHandwritingPages();
        $totalRmPages = max($rmPages->count(), 1);

        $activePageNumber = $request->integer('rm_page')
            ?: (int) $request->session()->get('focus_rm_page', 1);
        $activePageNumber = max(1, min($activePageNumber, $totalRmPages));

        $activeRmPage = $rmPages->firstWhere('page_number', $activePageNumber)
            ?? $rmPages->first();

        $rmPageNumbers = $rmPages->pluck('page_number');
        $nextRmPageNumber = ($rmPages->max('page_number') ?? 1) + 1;

        return view('rme.visits.medical-record.show', compact(
            'clinicVisit',
            'medicalRecord',
            'adjacentVisits',
            'activeRmPage',
            'activePageNumber',
            'totalRmPages',
            'rmPageNumbers',
            'nextRmPageNumber',
        ));
    }

    public function store(StoreMedicalRecordRequest $request, ClinicVisit $clinicVisit): RedirectResponse
    {
        $this->authorize('create', [MedicalRecord::class, $clinicVisit]);

        $this->service->createDraft($clinicVisit, auth()->id(), $request->validated());

        return redirect()
            ->route('rme.visits.medical-record.show', $clinicVisit)
            ->with('status', 'Rekam medis berhasil dibuat.');
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
