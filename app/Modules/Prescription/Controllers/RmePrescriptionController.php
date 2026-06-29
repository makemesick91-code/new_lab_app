<?php

namespace App\Modules\Prescription\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\Prescription\Requests\StoreRmePrescriptionRequest;
use App\Modules\Prescription\Requests\UpdateRmePrescriptionRequest;
use App\Modules\Prescription\Services\RmePrescriptionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RmePrescriptionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RmePrescriptionService $service,
    ) {}

    public function show(ClinicVisit $clinicVisit): View
    {
        $this->authorize('viewForVisit', [RmePrescription::class, $clinicVisit]);

        $data = $this->service->showDataForVisit($clinicVisit);
        $adjacentVisits = app(ClinicVisitService::class)->adjacentVisits($clinicVisit);
        $canManage = auth()->user()?->can('create', [RmePrescription::class, $clinicVisit]) ?? false;

        return view('rme.visits.prescription.show', [
            'clinicVisit' => $clinicVisit,
            'prescription' => $data['prescription'],
            'defaults' => $data['defaults'],
            'history' => $data['history'],
            'adjacentVisits' => $adjacentVisits,
            'canManage' => $canManage,
            'editMode' => $canManage && (request()->boolean('edit') || $data['prescription'] === null),
        ]);
    }

    public function store(StoreRmePrescriptionRequest $request, ClinicVisit $clinicVisit): RedirectResponse
    {
        $this->authorize('create', [RmePrescription::class, $clinicVisit]);

        $prescription = $this->service->create(
            $clinicVisit,
            $request->validated(),
            $request->user(),
        );

        return redirect()
            ->route('rme.visits.prescription.show', $clinicVisit)
            ->with('status', 'Resep dokter berhasil disimpan.')
            ->with('focus_prescription_id', $prescription->id);
    }

    public function update(UpdateRmePrescriptionRequest $request, RmePrescription $rmePrescription): RedirectResponse
    {
        $this->authorize('update', $rmePrescription);

        $this->service->update(
            $rmePrescription,
            $request->validated(),
            $request->user(),
        );

        return redirect()
            ->route('rme.visits.prescription.show', $rmePrescription->clinic_visit_id)
            ->with('status', 'Resep dokter berhasil diperbarui.');
    }

    public function print(RmePrescription $rmePrescription): View
    {
        $this->authorize('print', $rmePrescription);

        $rmePrescription->load(['clinicVisit.patient', 'clinicVisit.doctor', 'clinicVisit.branch', 'doctor']);
        $clinicVisit = $rmePrescription->clinicVisit;

        $this->service->markPrinted($rmePrescription);

        return view('rme.visits.prescription.print', [
            'prescription' => $rmePrescription,
            'clinicVisit' => $clinicVisit,
        ]);
    }
}
