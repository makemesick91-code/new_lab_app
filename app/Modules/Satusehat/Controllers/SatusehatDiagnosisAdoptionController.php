<?php

namespace App\Modules\Satusehat\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Services\SatusehatDiagnosisAdoptionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * SATUSEHAT-4B — structured diagnosis adoption dashboard (read-only,
 * PII-free). Route-gated by view_diagnosis_adoption; the requested branch
 * filter is validated against the RME-enabled set inside the service.
 */
class SatusehatDiagnosisAdoptionController extends Controller
{
    public function __construct(
        private readonly SatusehatDiagnosisAdoptionService $adoption,
        private readonly BranchService $branches,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'from' => is_string($request->query('from')) ? $request->query('from') : null,
            'to' => is_string($request->query('to')) ? $request->query('to') : null,
            'branch_id' => $request->query('branch_id') !== null ? (int) $request->query('branch_id') : null,
            'doctor_id' => $request->query('doctor_id') !== null ? (int) $request->query('doctor_id') : null,
        ];

        return view('satusehat.adoption.index', [
            'metrics' => $this->adoption->metrics($filters),
            'filters' => $filters,
            'branches' => $this->branches->listRmeEnabled(),
        ]);
    }
}
