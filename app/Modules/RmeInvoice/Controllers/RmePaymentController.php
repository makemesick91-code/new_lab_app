<?php

namespace App\Modules\RmeInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\PaymentMethod\Services\PaymentMethodService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Requests\CreateRmePaymentRequest;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RmePaymentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RmePaymentService $service,
        private readonly PaymentMethodService $paymentMethods,
    ) {}

    public function create(Request $request, ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): View|RedirectResponse
    {
        $this->authorize('pay', $rmeInvoice);

        if (! $rmeInvoice->isPayable()) {
            return redirect()
                ->route('rme.cashier.show', [$clinicVisit, $rmeInvoice])
                ->with('error', 'Invoice ini tidak dapat dibayar (status: '.$rmeInvoice->status.').');
        }

        return view('rme.cashier.payment.create', [
            'visit' => $clinicVisit->load(['patient', 'doctor']),
            'invoice' => $rmeInvoice->load(['items.treatment', 'cashier', 'payments.paymentMethod', 'payments.cashier']),
            'paymentMethods' => $this->paymentMethods->listActive(),
        ]);
    }

    public function store(CreateRmePaymentRequest $request, ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): RedirectResponse
    {
        $this->authorize('pay', $rmeInvoice);

        $payment = $this->service->pay($rmeInvoice, $request->user(), $request->validated());
        $freshInvoice = $rmeInvoice->fresh();

        if ($freshInvoice?->isPaid()) {
            return redirect()
                ->route('rme.cashier.receipt.show', [$clinicVisit, $freshInvoice])
                ->with('status', 'Pembayaran berhasil dicatat. No. Kwitansi: '.$payment->payment_number);
        }

        return redirect()
            ->route('rme.cashier.show', [$clinicVisit, $freshInvoice ?? $rmeInvoice])
            ->with('status', 'Pembayaran cicilan berhasil dicatat. No. Kwitansi: '.$payment->payment_number);
    }

    public function receipt(Request $request, ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): View|RedirectResponse
    {
        $this->authorize('viewReceipt', $rmeInvoice);

        if (! $rmeInvoice->isPaid()) {
            return redirect()
                ->route('rme.cashier.show', [$clinicVisit, $rmeInvoice])
                ->with('error', 'Kwitansi pelunasan hanya tersedia untuk invoice yang sudah PAID.');
        }

        $payment = $this->service->paymentsForInvoice($rmeInvoice)->first();

        $invoice = $rmeInvoice->load([
            'items.treatment',
            'cashier',
            'branch',
            'labCaseCandidates.convertedLabOrder',
            'labCaseCandidates.treatment',
        ]);

        return view('rme.cashier.receipt.show', [
            'visit' => $clinicVisit->load(['patient', 'doctor', 'initialTreatment']),
            'invoice' => $invoice,
            'payment' => $payment,
            'labCaseCandidates' => $invoice->labCaseCandidates,
        ]);
    }
}
