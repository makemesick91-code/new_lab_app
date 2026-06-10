<?php

namespace App\Modules\ClinicVisit\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Requests\StoreClinicVisitRequest;
use App\Modules\ClinicVisit\Requests\TransitionStatusRequest;
use App\Modules\ClinicVisit\Requests\UpdateClinicVisitRequest;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Patient\Models\Patient;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClinicVisitController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ClinicVisitService $visits,
        private readonly MedicalRecordService $medicalRecords,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ClinicVisit::class);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'visit_date' => $request->string('visit_date')->toString() ?: null,
        ];

        $rmeWidgets = [
            'visits_today' => $this->visits->visitsTodayCount(),
            'waiting' => $this->visits->waitingCount(),
            'in_progress' => $this->visits->inProgressCount(),
            'draft_medical_records' => $this->medicalRecords->draftCount(),
            'finalized_today' => $this->medicalRecords->finalizedTodayCount(),
        ];

        return view('rme.visits.index', [
            'visits' => $this->visits->paginate($filters),
            'filters' => $filters,
            'statuses' => ClinicVisit::STATUSES,
            'rmeWidgets' => $rmeWidgets,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', ClinicVisit::class);

        return view('rme.visits.create', [
            'clinics' => Clinic::orderBy('name')->get(),
            'patients' => Patient::orderBy('name')->get(),
            'doctors' => Doctor::orderBy('name')->get(),
            'clinicRooms' => ClinicRoom::where('status', ClinicRoom::STATUS_ACTIVE)->orderBy('name')->get(),
        ]);
    }

    public function store(StoreClinicVisitRequest $request): RedirectResponse
    {
        $this->authorize('create', ClinicVisit::class);
        $visit = $this->visits->create($request->validated());

        return redirect()->route('rme.visits.show', $visit)->with('status', 'Kunjungan berhasil didaftarkan.');
    }

    public function show(ClinicVisit $clinicVisit): View
    {
        $this->authorize('view', $clinicVisit);
        $clinicVisit->load(['patient', 'doctor', 'clinic', 'clinicRoom', 'branch']);

        return view('rme.visits.show', ['visit' => $clinicVisit]);
    }

    public function edit(ClinicVisit $clinicVisit): View
    {
        $this->authorize('update', $clinicVisit);

        return view('rme.visits.edit', [
            'visit' => $clinicVisit,
            'clinicRooms' => ClinicRoom::where('status', ClinicRoom::STATUS_ACTIVE)->orderBy('name')->get(),
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
}
