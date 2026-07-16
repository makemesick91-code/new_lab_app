<?php

namespace App\Modules\MedicalRecord\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MedicalRecord\Interfaces\ClinicalDiagnosisRepositoryInterface;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Requests\StoreClinicalDiagnosisRequest;
use App\Modules\MedicalRecord\Services\ClinicalDiagnosisService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * SATUSEHAT-4A — master clinical diagnosis governance (permission-gated at the
 * route: manage_structured_diagnoses). Read-only listing + explicit create +
 * deprecate. No delete — history stays intact.
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
        ]);
    }

    public function store(StoreClinicalDiagnosisRequest $request): RedirectResponse
    {
        $this->service->create($request->validated(), $request->user());

        return back()->with('status', 'Diagnosis master ditambahkan.');
    }

    public function deprecate(Request $request, ClinicalDiagnosis $diagnosis): RedirectResponse
    {
        $this->service->deprecate($diagnosis, $request->user());

        return back()->with('status', 'Diagnosis master dinonaktifkan (deprecated).');
    }
}
