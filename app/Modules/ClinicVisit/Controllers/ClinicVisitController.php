<?php

namespace App\Modules\ClinicVisit\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Requests\AssignRoomRequest;
use App\Modules\ClinicVisit\Requests\PatientSearchRequest;
use App\Modules\ClinicVisit\Requests\StoreClinicVisitRequest;
use App\Modules\ClinicVisit\Requests\TransitionStatusRequest;
use App\Modules\ClinicVisit\Requests\UpdateClinicVisitRequest;
use App\Modules\ClinicVisit\Services\ClinicVisitPrintDataBuilder;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LegacyRme\Services\LegacyRmePatientHistoryService;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\CrossBranchPatientLookupService;
use App\Modules\Patient\Services\KtpScanService;
use App\Modules\Patient\Services\PatientDocumentCaptureBranchResolver;
use App\Modules\Patient\Services\PatientSelectorSearchService;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RME\Services\DoctorRoomScopeService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Modules\Treatment\Models\Treatment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ClinicVisitController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ClinicVisitService $visits,
        private readonly MedicalRecordService $medicalRecords,
        private readonly BranchService $branchService,
        private readonly KtpScanService $ktpScans,
        private readonly UserOnlineContextService $onlineContext,
        private readonly DoctorPatientScopeService $doctorScope,
        private readonly DoctorRoomScopeService $doctorRoomScope,
        private readonly LegacyRmePatientHistoryService $legacyHistory,
        private readonly PatientDocumentCaptureBranchResolver $captureBranches,
    ) {}

    public function index(Request $request, CrossBranchPatientLookupService $rmLookup): View
    {
        $this->authorize('viewAny', ClinicVisit::class);

        $branchId = $request->integer('branch_id') ?: null;

        // FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-06) — "explicit" means the
        // request actually carries a date key. Clearing the field (submitting an
        // empty value) is an explicit request for the full history; arriving with
        // no date key at all gets the clinical-today default.
        $hasExplicitDateFilter = $request->hasAny(['visit_date', 'date_from', 'date_to']);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'visit_date' => $request->string('visit_date')->toString() ?: null,
            'date_from' => $request->string('date_from')->toString() ?: null,
            'date_to' => $request->string('date_to')->toString() ?: null,
            'branch_id' => $branchId,
        ];

        $filters = $this->visits->applyVisitIndexDateDefault($filters, $hasExplicitDateFilter);

        // AUDIT-RME-CLINIC-VISIT-BRANCH-AUTHORITY-1 — the authenticated user is
        // passed EXPLICITLY; the service never reads the session. The medical
        // record widgets aggregate over the same scoped branch set as the visit
        // widgets beside them.
        $actor = $request->user();
        $widgetBranchIds = $this->visits->scopedBranchIdsFor($actor, $branchId);

        $rmeWidgets = [
            'visits_today' => $this->visits->visitsTodayCount($actor, $branchId),
            'waiting' => $this->visits->waitingCount($actor, $branchId),
            'in_progress' => $this->visits->inProgressCount($actor, $branchId),
            'draft_medical_records' => $this->medicalRecords->draftCount($widgetBranchIds),
            'finalized_today' => $this->medicalRecords->finalizedTodayCount($widgetBranchIds),
        ];

        return view('rme.visits.index', [
            'visits' => $this->visits->paginate($actor, $filters),
            'filters' => $filters,
            'statuses' => ClinicVisit::STATUSES,
            'rmeWidgets' => $rmeWidgets,
            // FIX-04 — a context-bound role is never offered a branch it cannot read.
            'rmeBranches' => $this->visits->selectableRmeBranches($actor),
            'roomsByBranch' => $this->visits->activeRoomsByRmeBranch(),
            'rmLookup' => $rmLookup->lookupByMedicalRecordNumberAcrossBranches($request->string('rm_lookup')->toString()),
        ]);
    }

    /**
     * Sprint 58.6 — Doctor/Perawat treatment room worklist. Shows only visits
     * that already have an assigned treatment room and are still in an active
     * (non-terminal) state, scoped to the active RME-enabled branch set.
     */
    public function roomWorklist(Request $request): View
    {
        $this->authorize('viewAny', ClinicVisit::class);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'clinic_room_id' => $request->integer('clinic_room_id') ?: null,
            'branch_id' => $request->integer('branch_id') ?: null,
        ];

        // §16 — a scoped Doctor has exactly one room, resolved server-side. Drop
        // the request-supplied room/branch filters so there is no "Semua
        // Ruangan" capability and a crafted value cannot widen the listing.
        // The service-side room scope is the authority either way.
        $roomScoped = $this->doctorRoomScope->shouldApplyRoomScope($request->user());

        if ($roomScoped) {
            $filters['clinic_room_id'] = null;
            $filters['branch_id'] = null;
        }

        return view('rme.visits.room-worklist', [
            'roomScoped' => $roomScoped,
            'visits' => $this->visits->roomWorklist($request->user(), $filters),
            'filters' => $filters,
            'statuses' => ClinicVisit::STATUSES,
            'rooms' => ClinicRoom::where('status', ClinicRoom::STATUS_ACTIVE)
                ->whereIn('branch_id', $this->branchService->rmeEnabledIds())
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Sprint 58.7 — Antrian Pasien. Dedicated post-registration queue for Admin
     * Klinik: active (non-terminal) RME visits, scoped to the active RME-enabled
     * branch set, including patients with and without an assigned treatment room.
     * Reuses the Sprint 58.6 assign-room route for the per-row room selector.
     */
    public function patientQueue(Request $request): View
    {
        $this->authorize('viewAny', ClinicVisit::class);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'room_status' => $request->string('room_status')->toString() ?: null,
            'visit_date' => $request->string('visit_date')->toString() ?: null,
            'branch_id' => $request->integer('branch_id') ?: null,
        ];

        if ($this->doctorRoomScope->shouldApplyRoomScope($request->user())) {
            $filters['branch_id'] = null;
        }

        return view('rme.patient-queue.index', [
            'visits' => $this->visits->registeredQueue($request->user(), $filters),
            'filters' => $filters,
            'statuses' => ClinicVisit::STATUSES,
            'roomsByBranch' => $this->visits->activeRoomsByRmeBranch(),
        ]);
    }

    public function assignRoom(AssignRoomRequest $request, ClinicVisit $clinicVisit): RedirectResponse
    {
        $this->authorize('update', $clinicVisit);

        $this->visits->assignRoom($clinicVisit, (int) $request->validated()['clinic_room_id'], $request->user());

        return redirect()
            ->back()
            ->with('status', 'Ruangan pasien berhasil diperbarui.');
    }

    public function create(Request $request): View
    {
        $this->authorize('create', ClinicVisit::class);

        $user = $request->user();
        $adminBranchId = $this->onlineContext->resolveActiveBranchForAdmin($user);
        $selectedBranchId = $adminBranchId
            ?? ($request->integer('branch_id') ?: null);

        $prefill = [
            'patient_id' => $request->integer('patient_id') ?: null,
            // The visit type is not prefilled: it is derived server-side on store
            // from whether a prior visit is selected (StoreClinicVisitRequest).
            // "Buat Kontrol" still links here with the prior visit preselected.
            'follow_up_of_visit_id' => $request->integer('follow_up_of_visit_id') ?: null,
            'branch_id' => $selectedBranchId,
        ];

        $doctors = $selectedBranchId
            ? $this->onlineContext->activeDoctorsForBranch((int) $selectedBranchId)
            : collect();

        // REVISION-NEW-VISIT-PATIENT-SEARCH-COMBOBOX-1 — the patient list is NOT
        // preloaded any more. It used to ship every patient row (across every
        // branch, phone numbers included) into this page's HTML for the browser
        // to filter. The combobox now asks `rme.visits.patient-search`, which
        // decides scope server-side and returns at most
        // PatientSelectorSearchService::RESULT_LIMIT identity-only rows.
        //
        // REVISION-NEW-VISIT-GLOBAL-PATIENT-LOOKUP-1 — that scope is now the
        // whole RME patient registry, not this operator's working branch. The
        // page's own `branch_id` (below, from the daily context) is unaffected:
        // it is the branch the VISIT is created at, which is a separate
        // authority from which patients may be found.
        return view('rme.visits.create', [
            'doctors' => $doctors,
            'treatments' => Treatment::where('is_active', true)->orderBy('name')->get(),
            'rmeBranches' => $this->branchService->listRmeEnabled(),
            'prefill' => $prefill,
            'lockedBranchId' => $adminBranchId,
            'noOnlineDoctors' => $selectedBranchId !== null && $doctors->isEmpty() && $adminBranchId === null,
            'hideDoctorSelection' => $adminBranchId !== null,
        ]);
    }

    /**
     * REVISION-NEW-VISIT-GLOBAL-PATIENT-LOOKUP-1 — authorized GLOBAL patient
     * identity lookup for the single "Kunjungan Baru" combobox.
     *
     * Gated by the same `create` ability as the page that uses it, so someone who
     * may not register a visit cannot enumerate the registry through it. Scope,
     * fail-closed behaviour and the result ceiling all live in
     * {@see PatientSelectorSearchService}; this method only hands the request's
     * term over and returns what it is given.
     */
    public function patientSearch(PatientSearchRequest $request, PatientSelectorSearchService $search): JsonResponse
    {
        $this->authorize('create', ClinicVisit::class);

        return response()->json($search->search($request->user(), $request->term()));
    }

    public function patientVisitOptions(Request $request): JsonResponse
    {
        // AUDIT-RME-CLINIC-VISIT-BRANCH-AUTHORITY-1 — this is the prior-visit
        // picker of the "Kunjungan Baru" form and nothing else consumes it, so it
        // requires the registration ability, not mere `viewAny`. With `viewAny` an
        // account that cannot register visits (e.g. a Kasir pinned to one branch)
        // could read any other branch's patient visit history. This closes it
        // ONLY for such accounts: a registrar still sees a patient's visits at
        // every RME branch, deliberately, because a follow-up may link to any of
        // them (open owner decision OD-1). The service re-asserts the same
        // boundary (plus patient selectability) against the explicit actor.
        $this->authorize('create', ClinicVisit::class);

        $patientId = $request->integer('patient_id');
        abort_if($patientId <= 0, 422, 'patient_id wajib diisi.');

        $patient = Patient::query()->findOrFail($patientId);
        $access = $this->doctorScope->authorizePatientAccess($request->user(), $patient);
        if ($access instanceof \Illuminate\Auth\Access\Response) {
            abort_if($access->denied(), 403, $access->message() ?? 'Forbidden');
        } elseif ($access === false) {
            abort(403);
        }

        return response()->json([
            'visits' => $this->visits->patientVisitOptions($request->user(), $patientId),
        ]);
    }

    public function onlineDoctors(Request $request): JsonResponse
    {
        $this->authorize('create', ClinicVisit::class);

        $adminBranchId = $this->onlineContext->resolveActiveBranchForAdmin($request->user());
        $branchId = $adminBranchId ?? $request->integer('branch_id');
        abort_if($branchId <= 0, 422, 'branch_id wajib diisi.');

        $doctors = $this->onlineContext->activeDoctorsForBranch($branchId);

        return response()->json([
            'doctors' => $doctors->map(fn (Doctor $doctor) => [
                'id' => $doctor->id,
                'name' => $doctor->name,
            ])->values(),
        ]);
    }

    public function store(StoreClinicVisitRequest $request): RedirectResponse
    {
        $this->authorize('create', ClinicVisit::class);

        $data = $request->validated();

        // Sprint 61.1.1 — the KTP scan token is a UI-only attachment hint; strip
        // it before it reaches visit/patient creation.
        $ktpScanToken = $data['ktp_scan_token'] ?? null;
        unset($data['ktp_scan_token'], $data['ktp_ocr_applied'], $data['ktp_ocr_verified']);

        $isNewPatient = ($data['patient_mode'] ?? 'existing') === 'new';

        // Creating a brand-new patient inside the visit flow requires patient
        // management rights in addition to visit management.
        if ($isNewPatient) {
            $this->authorize('create', Patient::class);
        }

        $visit = $this->visits->create($data, $request->user());

        // Sprint 61.1.1 — promote a scanned KTP (if any) into the freshly created
        // patient's private document folder. Only for the new-patient flow; an
        // existing-patient visit never attaches. A missing/expired token is a
        // no-op so registration never fails because of the scan.
        // A photo that was NOT archived is reported, never silent
        // (AUDIT-PATIENT-KTP-ARCHIVE-PERSISTENCE-1).
        $redirect = redirect()->route('rme.visits.show', $visit)->with('status', 'Kunjungan berhasil didaftarkan.');

        if ($isNewPatient && is_string($ktpScanToken) && $ktpScanToken !== '') {
            $patient = $visit->patient;
            $document = $patient !== null
                ? $this->ktpScans->attachTempToPatient(
                    $patient,
                    $ktpScanToken,
                    (int) $request->user()->id,
                    $this->captureBranches->forVisitRegistration($visit),
                )
                : null;

            if ($document === null) {
                $redirect->with('warning', KtpScanService::NOT_ATTACHED_WARNING);
            }
        }

        return $redirect;
    }

    public function show(ClinicVisit $clinicVisit): View
    {
        $this->auditOutOfRoomAttempt($clinicVisit);

        $this->authorize('view', $clinicVisit);
        $clinicVisit->load([
            'patient',
            'doctor',
            'clinic',
            'clinicRoom',
            'branch',
            'initialTreatment',
            'followUpOf',
            'followUpVisits.doctor',
            'rmePrescription',
        ]);

        $patientVisitHistory = $this->visits->patientVisitHistory(
            (int) $clinicVisit->patient_id,
        );

        return view('rme.visits.show', [
            'visit' => $clinicVisit,
            'patientVisitHistory' => $patientVisitHistory,
            // LEGACY-RME-PDF-1C — native RME history merged with the patient's
            // PUBLISHED legacy archive. The service resolves the legacy side
            // under its own feature flag, permission and branch scope, so this
            // is an empty collection (and the card is not rendered at all)
            // whenever the archive is off or the operator may not see it.
            'rmeTimeline' => $this->legacyHistory->timelineFor(
                request()->user(),
                (int) $clinicVisit->patient_id,
                $patientVisitHistory,
            ),
            'doctorAccessSummary' => $clinicVisit->patient
                ? $this->doctorScope->doctorsWithAccessSummary($clinicVisit->patient)
                : [],
            // Hotfix Sprint 60.8 — branch-scoped active rooms for the inline
            // room-assignment selector when the visit still has no room.
            'rooms' => $this->visits->activeRoomsForBranch((int) $clinicVisit->branch_id),
        ]);
    }

    public function edit(ClinicVisit $clinicVisit): View
    {
        $this->authorize('update', $clinicVisit);

        return view('rme.visits.edit', [
            'visit' => $clinicVisit,
            'treatments' => Treatment::where('is_active', true)->orderBy('name')->get(),
            'statuses' => ClinicVisit::STATUSES,
        ]);
    }

    public function update(UpdateClinicVisitRequest $request, ClinicVisit $clinicVisit): RedirectResponse
    {
        $this->authorize('update', $clinicVisit);
        $this->visits->update($clinicVisit, $request->validated(), $request->user());

        return redirect()->route('rme.visits.show', $clinicVisit)->with('status', 'Kunjungan berhasil diperbarui.');
    }

    public function transitionStatus(TransitionStatusRequest $request, ClinicVisit $clinicVisit): RedirectResponse
    {
        $this->authorize('transition', $clinicVisit);
        $newStatus = $request->validated()['status'];

        // FIX-05 — the doctor examination-completion action needs its own
        // clinical authority; hiding the button is never the boundary.
        if ($newStatus === ClinicVisit::STATUS_CASHIER_PENDING) {
            $this->authorize('completeExamination', $clinicVisit);
        }

        $this->visits->transitionStatus($clinicVisit, $newStatus, $request->user());

        // Sprint 62.1 — "Selesai Pemeriksaan" handoff: a visit moved to
        // cashier_pending is now waiting at the cashier.
        $message = $newStatus === ClinicVisit::STATUS_CASHIER_PENDING
            ? 'Pemeriksaan selesai, pasien masuk ke kasir.'
            : 'Status kunjungan berhasil diperbarui.';

        return redirect()->route('rme.visits.show', $clinicVisit)->with('status', $message);
    }

    public function print(ClinicVisit $clinicVisit): View
    {
        $this->auditDeniedPrint($clinicVisit);

        $this->authorize('print', $clinicVisit);

        return view('rme.visits.print', $this->resolvePrintViewData($clinicVisit));
    }

    public function pdf(ClinicVisit $clinicVisit): Response
    {
        $this->auditDeniedPrint($clinicVisit);

        $this->authorize('print', $clinicVisit);

        $data = $this->resolvePrintViewData($clinicVisit);
        $filename = 'rme-visit-'.($clinicVisit->visit_number ?? $clinicVisit->id).'.pdf';

        return Pdf::loadView('rme.visits.print-pdf', $data)->download($filename);
    }

    /**
     * @return array{visit: ClinicVisit, paidInvoice: ?RmeInvoice, payment: mixed, labCaseCandidates: Collection}
     */
    private function resolvePrintViewData(ClinicVisit $clinicVisit): array
    {
        // FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — the builder is shared with
        // the read-only oversight print so the two cannot drift apart.
        return app(ClinicVisitPrintDataBuilder::class)->build($clinicVisit);
    }

    /**
     * §23 — record a genuine attempt to open an ACTIVE visit belonging to
     * another treatment room. Called from the controller (not the policy) so a
     * merely hidden `@can` button never manufactures a rejection record.
     */
    private function auditOutOfRoomAttempt(ClinicVisit $clinicVisit): void
    {
        $user = request()->user();

        if ($user !== null && $this->doctorRoomScope->deniesActiveVisitOutsideRoom($user, $clinicVisit)) {
            $this->doctorRoomScope->auditRoomAccessRejected($user, $clinicVisit);
        }
    }

    /**
     * §23 — record a genuine Doctor attempt to print/export an RME.
     */
    private function auditDeniedPrint(ClinicVisit $clinicVisit): void
    {
        $user = request()->user();

        if ($user !== null && $this->doctorRoomScope->deniesClinicalPrint($user)) {
            $this->doctorRoomScope->auditVisitPrintRejected($user, $clinicVisit);
        }
    }
}
