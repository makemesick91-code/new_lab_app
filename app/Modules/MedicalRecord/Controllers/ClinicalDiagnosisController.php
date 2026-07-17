<?php

namespace App\Modules\MedicalRecord\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MedicalRecord\Interfaces\ClinicalDiagnosisRepositoryInterface;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Requests\DeprecateClinicalDiagnosisRequest;
use App\Modules\MedicalRecord\Requests\ReviewClinicalDiagnosisRequest;
use App\Modules\MedicalRecord\Requests\StoreClinicalDiagnosisRequest;
use App\Modules\MedicalRecord\Services\ClinicalDiagnosisService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * SATUSEHAT-4A/4B — master clinical diagnosis governance.
 *
 * Create/submit is gated by manage_structured_diagnoses at the route; the
 * review actions (approve/reject/activate/deprecate) require the dedicated
 * review_clinical_terminology permission — separation of duties is re-checked
 * server-side in the service. No delete — history stays intact.
 */
class ClinicalDiagnosisController extends Controller
{
    public function __construct(
        private readonly ClinicalDiagnosisService $service,
        private readonly ClinicalDiagnosisRepositoryInterface $diagnoses,
    ) {}

    public function index(Request $request): View
    {
        return view('satusehat.diagnoses.index', [
            'diagnoses' => $this->diagnoses->paginate($request->only(['search', 'status'])),
            'filters' => $request->only(['search', 'status']),
            'activeReplacements' => ClinicalDiagnosis::query()
                ->where('status', ClinicalDiagnosis::STATUS_ACTIVE)
                ->orderBy('code')
                ->limit(200)
                ->get(['id', 'code', 'display']),
        ]);
    }

    public function store(StoreClinicalDiagnosisRequest $request): RedirectResponse
    {
        $this->service->create($request->validated(), $request->user());

        return back()->with('status', 'Diagnosis master ditambahkan sebagai DRAFT — ajukan review untuk aktivasi.');
    }

    public function submitReview(Request $request, ClinicalDiagnosis $diagnosis): RedirectResponse
    {
        $this->service->submitForReview($diagnosis, $request->user());

        return back()->with('status', 'Terminologi diajukan untuk review klinis.');
    }

    public function approve(ReviewClinicalDiagnosisRequest $request, ClinicalDiagnosis $diagnosis): RedirectResponse
    {
        $this->service->approve($diagnosis, $request->user(), (string) $request->validated('reason'));

        return back()->with('status', 'Terminologi disetujui — aktifkan untuk membuka pemilihan klinis.');
    }

    public function reject(ReviewClinicalDiagnosisRequest $request, ClinicalDiagnosis $diagnosis): RedirectResponse
    {
        $this->service->reject($diagnosis, $request->user(), (string) $request->validated('reason'));

        return back()->with('status', 'Terminologi ditolak.');
    }

    public function activate(Request $request, ClinicalDiagnosis $diagnosis): RedirectResponse
    {
        $this->service->activate($diagnosis, $request->user());

        return back()->with('status', 'Terminologi diaktifkan untuk pemilihan klinis.');
    }

    public function deprecate(DeprecateClinicalDiagnosisRequest $request, ClinicalDiagnosis $diagnosis): RedirectResponse
    {
        $this->service->deprecate(
            $diagnosis,
            $request->user(),
            $request->validated('replacement_diagnosis_id') !== null ? (int) $request->validated('replacement_diagnosis_id') : null,
            $request->validated('reason'),
        );

        return back()->with('status', 'Diagnosis master dinonaktifkan (deprecated).');
    }
}
