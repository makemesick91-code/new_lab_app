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
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MedicalRecordController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MedicalRecordService $service,
        private readonly MedicalRecordRepositoryInterface $medicalRecords,
    ) {}

    public function show(ClinicVisit $clinicVisit): View
    {
        $medicalRecord = $this->medicalRecords->findByVisitId($clinicVisit->id);

        abort_if($medicalRecord === null, 404);

        $this->authorize('view', $medicalRecord);

        return view('rme.visits.medical-record.show', compact('clinicVisit', 'medicalRecord'));
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
