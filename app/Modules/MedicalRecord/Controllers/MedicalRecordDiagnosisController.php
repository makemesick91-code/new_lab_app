<?php

namespace App\Modules\MedicalRecord\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Interfaces\ClinicalDiagnosisRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\MedicalRecord\Requests\StoreMedicalRecordDiagnosisRequest;
use App\Modules\MedicalRecord\Services\MedicalRecordDiagnosisService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * SATUSEHAT-4A — structured diagnosis entry on the RME page. Authorization
 * reuses MedicalRecordPolicy::update (the same authority that may edit the
 * record); search is a bounded ACTIVE-only master lookup (no PII involved).
 */
class MedicalRecordDiagnosisController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MedicalRecordDiagnosisService $service,
        private readonly ClinicalDiagnosisRepositoryInterface $diagnoses,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $term = is_string($request->query('q')) ? $request->query('q') : '';

        return response()->json([
            'data' => $this->diagnoses->search($term)->map(fn ($dx) => [
                'id' => (int) $dx->id,
                'code_system' => (string) $dx->code_system,
                'code' => (string) $dx->code,
                'display' => (string) $dx->display,
                'label' => $dx->code.' — '.$dx->display,
            ])->values(),
        ]);
    }

    public function store(StoreMedicalRecordDiagnosisRequest $request, ClinicVisit $clinicVisit, MedicalRecord $medicalRecord): RedirectResponse
    {
        abort_if((int) $medicalRecord->clinic_visit_id !== (int) $clinicVisit->id, 404);
        $this->authorize('update', $medicalRecord);

        $this->service->record($medicalRecord, $request->validated(), $request->user());

        return back()->with('status', 'Diagnosis terstruktur tercatat.');
    }

    public function destroy(Request $request, ClinicVisit $clinicVisit, MedicalRecord $medicalRecord, MedicalRecordDiagnosis $diagnosis): RedirectResponse
    {
        abort_if((int) $medicalRecord->clinic_visit_id !== (int) $clinicVisit->id, 404);
        abort_if((int) $diagnosis->medical_record_id !== (int) $medicalRecord->id, 404);
        $this->authorize('update', $medicalRecord);

        $this->service->remove($medicalRecord, $diagnosis, $request->user());

        return back()->with('status', 'Diagnosis terstruktur dihapus.');
    }

    /** SATUSEHAT-4B — explicit primary swap (never silent, audited). */
    public function makePrimary(Request $request, ClinicVisit $clinicVisit, MedicalRecord $medicalRecord, MedicalRecordDiagnosis $diagnosis): RedirectResponse
    {
        abort_if((int) $medicalRecord->clinic_visit_id !== (int) $clinicVisit->id, 404);
        abort_if((int) $diagnosis->medical_record_id !== (int) $medicalRecord->id, 404);
        $this->authorize('update', $medicalRecord);

        $this->service->makePrimary($medicalRecord, $diagnosis, $request->user());

        return back()->with('status', 'Diagnosis utama diperbarui.');
    }
}
