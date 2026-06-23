<?php

namespace App\Modules\ClinicVisit\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Requests\AssignRoomRequest;
use App\Modules\ClinicVisit\Requests\StoreClinicVisitRequest;
use App\Modules\ClinicVisit\Requests\TransitionStatusRequest;
use App\Modules\ClinicVisit\Requests\UpdateClinicVisitRequest;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\CrossBranchPatientLookupService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
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
    ) {}

    public function index(Request $request, CrossBranchPatientLookupService $rmLookup): View
    {
        $this->authorize('viewAny', ClinicVisit::class);

        $branchId = $request->integer('branch_id') ?: null;

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'visit_date' => $request->string('visit_date')->toString() ?: null,
            'branch_id' => $branchId,
        ];

        $rmeWidgets = [
            'visits_today' => $this->visits->visitsTodayCount($branchId),
            'waiting' => $this->visits->waitingCount($branchId),
            'in_progress' => $this->visits->inProgressCount($branchId),
            'draft_medical_records' => $this->medicalRecords->draftCount(),
            'finalized_today' => $this->medicalRecords->finalizedTodayCount(),
        ];

        return view('rme.visits.index', [
            'visits' => $this->visits->paginate($filters),
            'filters' => $filters,
            'statuses' => ClinicVisit::STATUSES,
            'rmeWidgets' => $rmeWidgets,
            'rmeBranches' => $this->branchService->listRmeEnabled(),
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

        return view('rme.visits.room-worklist', [
            'visits' => $this->visits->roomWorklist($filters),
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

        return view('rme.patient-queue.index', [
            'visits' => $this->visits->registeredQueue($filters),
            'filters' => $filters,
            'statuses' => ClinicVisit::STATUSES,
            'roomsByBranch' => $this->visits->activeRoomsByRmeBranch(),
        ]);
    }

    public function assignRoom(AssignRoomRequest $request, ClinicVisit $clinicVisit): RedirectResponse
    {
        $this->authorize('update', $clinicVisit);

        $this->visits->assignRoom($clinicVisit, (int) $request->validated()['clinic_room_id']);

        return redirect()
            ->back()
            ->with('status', 'Ruangan pasien berhasil diperbarui.');
    }

    public function create(Request $request): View
    {
        $this->authorize('create', ClinicVisit::class);

        $prefill = [
            'patient_id' => $request->integer('patient_id') ?: null,
            'visit_type' => $request->string('visit_type')->toString() ?: ClinicVisit::VISIT_TYPE_NEW,
            'follow_up_of_visit_id' => $request->integer('follow_up_of_visit_id') ?: null,
            'branch_id' => $request->integer('branch_id') ?: null,
        ];

        return view('rme.visits.create', [
            'patients' => Patient::with('branch')->orderBy('name')->get(),
            'doctors' => Doctor::orderBy('name')->get(),
            'treatments' => Treatment::where('is_active', true)->orderBy('name')->get(),
            'rmeBranches' => $this->branchService->listRmeEnabled(),
            'prefill' => $prefill,
        ]);
    }

    public function patientVisitOptions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ClinicVisit::class);

        $patientId = $request->integer('patient_id');
        abort_if($patientId <= 0, 422, 'patient_id wajib diisi.');

        return response()->json([
            'visits' => $this->visits->patientVisitOptions($patientId),
        ]);
    }

    public function store(StoreClinicVisitRequest $request): RedirectResponse
    {
        $this->authorize('create', ClinicVisit::class);

        $data = $request->validated();

        // Creating a brand-new patient inside the visit flow requires patient
        // management rights in addition to visit management.
        if (($data['patient_mode'] ?? 'existing') === 'new') {
            $this->authorize('create', Patient::class);
        }

        $visit = $this->visits->create($data);

        return redirect()->route('rme.visits.show', $visit)->with('status', 'Kunjungan berhasil didaftarkan.');
    }

    public function show(ClinicVisit $clinicVisit): View
    {
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
        ]);

        $patientVisitHistory = $this->visits->patientVisitHistory(
            (int) $clinicVisit->patient_id,
        );

        return view('rme.visits.show', [
            'visit' => $clinicVisit,
            'patientVisitHistory' => $patientVisitHistory,
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
        $this->visits->update($clinicVisit, $request->validated());

        return redirect()->route('rme.visits.show', $clinicVisit)->with('status', 'Kunjungan berhasil diperbarui.');
    }

    public function transitionStatus(TransitionStatusRequest $request, ClinicVisit $clinicVisit): RedirectResponse
    {
        $this->authorize('transition', $clinicVisit);
        $this->visits->transitionStatus($clinicVisit, $request->validated()['status']);

        return redirect()->route('rme.visits.show', $clinicVisit)->with('status', 'Status kunjungan berhasil diperbarui.');
    }

    public function print(ClinicVisit $clinicVisit): View
    {
        $this->authorize('print', $clinicVisit);

        return view('rme.visits.print', $this->resolvePrintViewData($clinicVisit));
    }

    public function pdf(ClinicVisit $clinicVisit): Response
    {
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
        $clinicVisit->load([
            'patient',
            'doctor',
            'branch',
            'clinic',
            'initialTreatment',
            'medicalRecord.handwriting',
            'medicalRecord.finalizedBy',
            'odontogram',
        ]);

        $paidInvoice = RmeInvoice::query()
            ->where('clinic_visit_id', $clinicVisit->id)
            ->where('status', RmeInvoice::STATUS_PAID)
            ->with([
                'items',
                'payments.paymentMethod',
                'labCaseCandidates.convertedLabOrder',
                'labCaseCandidates.treatment',
            ])
            ->first();

        $payment = $paidInvoice?->payments->first();

        return [
            'visit' => $clinicVisit,
            'paidInvoice' => $paidInvoice,
            'payment' => $payment,
            'labCaseCandidates' => $paidInvoice?->labCaseCandidates ?? collect(),
        ];
    }
}
