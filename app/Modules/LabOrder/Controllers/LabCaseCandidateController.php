<?php

namespace App\Modules\LabOrder\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LabOrder\Requests\ConvertLabCaseCandidateRequest;
use App\Modules\LabOrder\Services\LabCaseCandidateConversionService;
use App\Modules\LabService\Services\LabServiceService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Sprint 21 Phase 21.3+21.4 — LabCaseCandidate queue and conversion to LabOrder.
 */
class LabCaseCandidateController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly BranchContext $branchContext,
        private readonly LabCaseCandidateConversionService $conversionService,
        private readonly LabServiceService $labServiceService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', LabCaseCandidate::class);

        $branchId = $this->branchContext->id();
        $search = $request->string('search')->toString() ?: null;
        $status = $request->string('status')->toString() ?: null;

        $candidates = LabCaseCandidate::query()
            ->with(['patient', 'doctor', 'treatment', 'rmeInvoice', 'branch'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->whereHas('patient', fn ($p) => $p->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('doctor', fn ($d) => $d->where('name', 'like', "%{$search}%"))
                        ->orWhere('source_description', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('lab.case-candidates.index', [
            'candidates' => $candidates,
            'statuses' => LabCaseCandidate::STATUSES,
            'filters' => compact('search', 'status'),
        ]);
    }

    public function show(LabCaseCandidate $candidate): View
    {
        $this->authorize('view', $candidate);

        $candidate->loadMissing([
            'branch',
            'clinicVisit',
            'rmeInvoice',
            'rmeInvoiceItem',
            'patient',
            'doctor',
            'treatment',
        ]);

        return view('lab.case-candidates.show', [
            'candidate' => $candidate,
            'labServices' => $this->labServiceService->listAll(),
        ]);
    }

    public function convert(ConvertLabCaseCandidateRequest $request, LabCaseCandidate $candidate): RedirectResponse
    {
        $this->authorize('convert', $candidate);

        $order = $this->conversionService->convertToLabOrder(
            $candidate,
            $request->validated(),
            $request->user(),
        );

        return redirect()
            ->route('lab-orders.show', $order)
            ->with('status', 'Kandidat berhasil dikonversi ke Lab Order.');
    }
}
