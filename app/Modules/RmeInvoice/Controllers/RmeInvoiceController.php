<?php

namespace App\Modules\RmeInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Models\Branch;
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

    public function receivables(Request $request): View
    {
        $this->authorize('viewAny', RmeInvoice::class);

        $branches = Branch::query()
            ->where('is_active', true)
            ->where('is_rme_enabled', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $activeBranchIds = $branches->pluck('id')->all();

        $requestedBranchId = $request->integer('branch_id') ?: null;
        $selectedBranchId = $requestedBranchId && in_array($requestedBranchId, $activeBranchIds, true)
            ? $requestedBranchId
            : null;

        $requestedStatus = $request->string('status')->toString();
        $selectedStatus = in_array($requestedStatus, [RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL], true)
            ? $requestedStatus
            : null;

        $dateFrom = (string) $request->input('date_from', '');
        $dateTo = (string) $request->input('date_to', '');

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'branch_id' => $selectedBranchId,
            'status' => $selectedStatus,
            'date_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : null,
            'date_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : null,
        ];

        $query = RmeInvoice::query()
            ->with(['branch', 'patient', 'clinicVisit', 'payments'])
            ->whereIn('status', [RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL])
            ->whereIn('branch_id', $activeBranchIds)
            ->when($filters['branch_id'], fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->when($filters['date_from'], fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'], fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['search'], function ($query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';

                $query->where(function ($query) use ($term): void {
                    $query->whereRaw('LOWER(invoice_number) LIKE ?', [$term])
                        ->orWhereHas('patient', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('clinicVisit', fn ($query) => $query->whereRaw('LOWER(visit_number) LIKE ?', [$term]));
                });
            });

        $summaryInvoices = (clone $query)->get();

        $summary = [
            'invoice_count' => $summaryInvoices->count(),
            'grand_total' => $summaryInvoices->sum(fn (RmeInvoice $invoice) => (float) $invoice->grand_total),
            'paid_total' => $summaryInvoices->sum(fn (RmeInvoice $invoice) => (float) $invoice->payments->sum('amount')),
            'remaining_total' => $summaryInvoices->sum(function (RmeInvoice $invoice): float {
                $paidAmount = (float) $invoice->payments->sum('amount');

                return max(0, round((float) $invoice->grand_total - $paidAmount, 2));
            }),
        ];

        $invoices = $query
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [RmeInvoice::STATUS_PARTIAL])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('rme.cashier.receivables', [
            'branches' => $branches,
            'filters' => $filters,
            'invoices' => $invoices,
            'summary' => $summary,
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
            'payments.paymentMethod',
            'payments.cashier',
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
