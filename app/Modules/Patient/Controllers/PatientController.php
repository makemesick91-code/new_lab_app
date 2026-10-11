<?php

namespace App\Modules\Patient\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Clinic\Services\ClinicService;
use App\Modules\Doctor\Services\DoctorService;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Requests\StorePatientRequest;
use App\Modules\Patient\Requests\UpdatePatientRequest;
use App\Modules\Patient\Services\KtpScanService;
use App\Modules\Patient\Services\PatientService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PatientController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly PatientService $patientService,
        private readonly ClinicService $clinicService,
        private readonly DoctorService $doctorService,
        private readonly BranchService $branchService,
        private readonly KtpScanService $ktpScans,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Patient::class);

        return view('settings.patients.index', [
            'patients' => $this->patientService->list([
                'search' => $request->string('search')->toString() ?: null,
                'clinic_id' => $request->integer('clinic_id') ?: null,
                'doctor_id' => $request->integer('doctor_id') ?: null,
            ], 10),
            'search' => $request->string('search')->toString(),
            'clinicId' => $request->integer('clinic_id') ?: null,
            'doctorId' => $request->integer('doctor_id') ?: null,
            'clinics' => $this->clinicService->listAll(),
            'doctors' => $this->doctorService->listAll(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Patient::class);

        return view('settings.patients.create', [
            'clinics' => $this->clinicService->listAll(),
            'doctors' => $this->doctorService->listAll(),
            'rmeBranches' => $this->branchService->listRmeEnabled(),
        ]);
    }

    public function store(StorePatientRequest $request): RedirectResponse
    {
        $this->authorize('create', Patient::class);

        $data = $request->validated();
        $ktpScanToken = $data['ktp_scan_token'] ?? null;
        unset($data['ktp_scan_token'], $data['duplicate_override_reason'], $data['ktp_ocr_applied'], $data['ktp_ocr_verified']);

        $patient = $this->patientService->create($data);

        $redirect = redirect()->route('settings.patients.index')->with('status', 'Pasien berhasil dibuat.');

        // Sprint 61.1 — promote a scanned KTP (if any) into the patient's
        // private document folder. Registration never fails because of the
        // scan, but a photo that was NOT archived is reported, never silent
        // (AUDIT-PATIENT-KTP-ARCHIVE-PERSISTENCE-1).
        if (is_string($ktpScanToken) && $ktpScanToken !== ''
            && $this->ktpScans->attachTempToPatient($patient, $ktpScanToken, (int) $request->user()->id) === null) {
            $redirect->with('warning', KtpScanService::NOT_ATTACHED_WARNING);
        }

        return $redirect;
    }

    public function edit(Patient $patient): View|RedirectResponse
    {
        $this->authorize('update', $patient);

        // FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — a merged patient is a
        // read-only pointer; its identity is maintained on the canonical row.
        if ($patient->isMerged()) {
            return redirect()->route('settings.patients.edit', $patient->merged_into_patient_id)
                ->with('status', 'Pasien '.$patient->medical_record_number.' telah digabungkan. Anda dialihkan ke pasien hasil penggabungan.');
        }

        return view('settings.patients.edit', [
            'patient' => $patient,
            'clinics' => $this->clinicService->listAll(),
            'doctors' => $this->doctorService->listAll(),
            'rmeBranches' => $this->branchService->listRmeEnabled(),
        ]);
    }

    public function update(UpdatePatientRequest $request, Patient $patient): RedirectResponse
    {
        $this->authorize('update', $patient);

        $this->patientService->update($patient, $request->validated());

        return redirect()->route('settings.patients.index')->with('status', 'Pasien berhasil diperbarui.');
    }

    public function destroy(Patient $patient): RedirectResponse
    {
        $this->authorize('delete', $patient);

        $this->patientService->delete($patient);

        return redirect()->route('settings.patients.index')->with('status', 'Pasien berhasil dihapus.');
    }

    public function activate(Patient $patient): RedirectResponse
    {
        $this->authorize('update', $patient);

        $this->patientService->activate($patient);

        return redirect()->route('settings.patients.index')->with('status', 'Pasien berhasil diaktifkan.');
    }

    public function deactivate(Patient $patient): RedirectResponse
    {
        $this->authorize('update', $patient);

        $this->patientService->deactivate($patient);

        return redirect()->route('settings.patients.index')->with('status', 'Pasien berhasil dinonaktifkan.');
    }
}
