<?php

namespace App\Modules\Invoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Clinic\Services\ClinicService;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Requests\CreateInvoiceRequest;
use App\Modules\Invoice\Requests\IssueInvoiceRequest;
use App\Modules\Invoice\Requests\VoidInvoiceRequest;
use App\Modules\Invoice\Services\InvoiceService;
use App\Modules\Invoice\Services\InvoiceWorkflowService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly InvoiceWorkflowService $workflow,
        private readonly ClinicService $clinics,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Invoice::class);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'clinic_id' => $request->integer('clinic_id') ?: null,
            'invoice_date' => $request->string('invoice_date')->toString() ?: null,
        ];

        return view('invoices.index', [
            'invoices' => $this->invoices->paginate($filters, 10),
            'filters' => $filters,
            'statuses' => Invoice::STATUSES,
            'clinics' => $this->clinics->listAll(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Invoice::class);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'clinic_id' => $request->integer('clinic_id') ?: null,
        ];

        return view('invoices.create', [
            'availableOrders' => $this->invoices->availableOrders($filters),
            'filters' => $filters,
            'clinics' => $this->clinics->listAll(),
        ]);
    }

    public function store(CreateInvoiceRequest $request): RedirectResponse
    {
        $this->authorize('create', Invoice::class);

        $invoice = $this->invoices->create($request->validated(), $request->user());

        return redirect()->route('invoices.show', $invoice)->with('status', 'Invoice berhasil dibuat.');
    }

    public function show(Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        return view('invoices.show', [
            'invoice' => $this->invoices->find($invoice->id),
            'statuses' => Invoice::STATUSES,
        ]);
    }

    public function issue(IssueInvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('issue', $invoice);

        $this->workflow->issue($invoice, $request->validated()['notes'] ?? null, $request->user());

        return redirect()->route('invoices.show', $invoice)->with('status', 'Invoice berhasil diterbitkan.');
    }

    public function void(VoidInvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('void', $invoice);

        $this->workflow->void($invoice, $request->validated()['notes'], $request->user());

        return redirect()->route('invoices.show', $invoice)->with('status', 'Invoice berhasil divoid.');
    }
}
