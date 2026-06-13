<?php

namespace App\Modules\RmeInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Requests\CreateRmeInvoiceRequest;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\Treatment\Services\TreatmentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RmeInvoiceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RmeInvoiceService $service,
        private readonly TreatmentService $treatments,
        private readonly ClinicVisitService $visits,
    ) {}

    /** @return array<int, string> */
    private function cashierVisitRelations(): array
    {
        return [
            'patient',
            'doctor',
            'branch',
            'initialTreatment',
            'medicalRecord.handwriting',
            'odontogram',
        ];
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', RmeInvoice::class);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
        ];

        return view('rme.cashier.index', [
            'visits' => $this->service->paginatePendingVisits($filters),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request, ClinicVisit $clinicVisit): View
    {
        $this->authorize('create', RmeInvoice::class);

        $existingInvoice = $this->service->findInvoiceForVisit($clinicVisit);
        if ($existingInvoice) {
            return redirect()
                ->route('rme.cashier.show', [$clinicVisit, $existingInvoice])
                ->with('status', 'Tagihan sudah ada untuk kunjungan ini.');
        }

        return view('rme.cashier.create', [
            'visit' => $clinicVisit->load($this->cashierVisitRelations()),
            'treatments' => $this->treatments->listActive(),
        ]);
    }

    public function store(CreateRmeInvoiceRequest $request, ClinicVisit $clinicVisit): RedirectResponse
    {
        $this->authorize('create', RmeInvoice::class);

        $invoice = $this->service->create($clinicVisit, $request->user(), $request->validated());

        return redirect()
            ->route('rme.cashier.show', [$clinicVisit, $invoice])
            ->with('status', 'Tagihan berhasil dibuat.');
    }

    public function show(ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): View
    {
        $this->authorize('view', $rmeInvoice);

        $invoice = $rmeInvoice->load([
            'items.treatment',
            'cashier',
            'medicalRecord',
            'labCaseCandidates.convertedLabOrder',
            'labCaseCandidates.treatment',
        ]);

        return view('rme.cashier.show', [
            'visit' => $clinicVisit->load($this->cashierVisitRelations()),
            'invoice' => $invoice,
            'labCaseCandidates' => $invoice->labCaseCandidates,
        ]);
    }
}
