<?php

namespace App\Modules\MedicalRecord\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Requests\GrantDiagnosisOverrideRequest;
use App\Modules\MedicalRecord\Requests\SetDiagnosisRolloutModeRequest;
use App\Modules\MedicalRecord\Services\DiagnosisRolloutService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * SATUSEHAT-4B — branch-scoped diagnosis rollout configuration + emergency
 * override. Configuration is gated by configure_diagnosis_rollout at the
 * route; the override endpoint requires override_diagnosis_requirement AND
 * the medical-record update authority (policy re-check, IDOR-safe).
 */
class DiagnosisRolloutController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly DiagnosisRolloutService $rollout,
    ) {}

    public function index(): View
    {
        return view('satusehat.rollout.index', [
            'board' => $this->rollout->board(),
            'defaultMode' => $this->rollout->defaultMode(),
        ]);
    }

    public function update(SetDiagnosisRolloutModeRequest $request, Branch $branch): RedirectResponse
    {
        $this->rollout->setMode(
            $branch,
            (string) $request->validated('mode'),
            (string) $request->validated('reason'),
            $request->user(),
        );

        return back()->with('status', "Mode rollout cabang {$branch->name} diperbarui.");
    }

    public function override(GrantDiagnosisOverrideRequest $request, ClinicVisit $clinicVisit, MedicalRecord $medicalRecord): RedirectResponse
    {
        abort_if((int) $medicalRecord->clinic_visit_id !== (int) $clinicVisit->id, 404);
        $this->authorize('update', $medicalRecord);

        $this->rollout->grantOverride($medicalRecord, (string) $request->validated('reason'), $request->user());

        return back()->with('status', 'Override darurat tercatat — RME dapat difinalkan; isu diagnosis tetap terbuka untuk review klinis.');
    }
}
