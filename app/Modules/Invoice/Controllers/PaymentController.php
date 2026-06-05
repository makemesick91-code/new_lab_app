<?php

namespace App\Modules\Invoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\Invoice\Requests\CreatePaymentRequest;
use App\Modules\Invoice\Services\PaymentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

class PaymentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    public function store(CreatePaymentRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('create', Payment::class);

        $this->payments->record($invoice, $request->validated(), $request->user());

        return redirect()->route('invoices.show', $invoice)->with('status', 'Pembayaran berhasil dicatat.');
    }
}
