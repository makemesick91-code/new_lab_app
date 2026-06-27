<?php

namespace App\Modules\RmeInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\PaymentMethod\Services\PaymentMethodService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Requests\CreateRmePaymentRequest;
use App\Modules\RmeInvoice\Services\RmeControlReceivableService;
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
        private readonly RmeControlReceivableService $carryOver,
    ) {}

    public function create(Request $request, ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): View|RedirectResponse
    {
        $this->authorize('pay', $rmeInvoice);

        if (! $rmeInvoice->isPayable()) {
            return redirect()
                ->route('rme.cashier.show', [$clinicVisit, $rmeInvoice])
                ->with('error', 'Invoice ini tidak dapat dibayar (status: '.$rmeInvoice->status.').');
        }

        $payableSummary = $this->carryOver->getControlPayableSummary($rmeInvoice);

        // Sprint 62.2 — for ordinary (non control-chain) visits, surface the
        // patient's other outstanding receivables so the cashier may optionally
        // collect them. Control-chain visits keep their dedicated carry-over card.
        $outstandingReceivables = collect();
        if (! $payableSummary['has_carry_over']) {
            $outstandingReceivables = $this->carryOver
                ->getVisitPayableSummary($rmeInvoice)['outstanding_receivables'];
        }

        return view('rme.cashier.payment.create', [
            'visit' => $clinicVisit->load(['patient', 'doctor', 'followUpOf']),
            'invoice' => $rmeInvoice->load(['items.treatment', 'cashier', 'payments.paymentMethod', 'payments.cashier']),
            'paymentMethods' => $this->paymentMethods->listActive(),
            'payableSummary' => $payableSummary,
            'outstandingReceivables' => $outstandingReceivables,
        ]);
    }

    public function store(CreateRmePaymentRequest $request, ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): RedirectResponse
    {
        $this->authorize('pay', $rmeInvoice);

        $validated = $request->validated();
        $payableSummary = $this->carryOver->getControlPayableSummary($rmeInvoice);

        if ($payableSummary['has_carry_over']) {
            $result = $this->service->allocateControlPayment($rmeInvoice, $request->user(), $validated);
            $freshInvoice = $rmeInvoice->fresh();
            $allocation = [
                'allocated_to_parent' => $result->allocatedToParent,
                'allocated_to_control' => $result->allocatedToControl,
                'payment_batch_uuid' => $result->paymentBatchUuid,
            ];

            if ($freshInvoice?->isPaid()) {
                return redirect()
                    ->route('rme.cashier.receipt.show', [$clinicVisit, $freshInvoice])
                    ->with('status', 'Pembayaran selesai, visit selesai total.')
                    ->with('payment_allocation', $allocation);
            }

            $receiptNumber = $result->primaryPayment()?->payment_number ?? '-';

            return redirect()
                ->route('rme.cashier.show', [$clinicVisit, $freshInvoice ?? $rmeInvoice])
                ->with('status', 'Pembayaran berhasil dicatat. No. Kwitansi: '.$receiptNumber)
                ->with('payment_allocation', $allocation);
        }

        // Sprint 62.2 — ordinary new visit collecting selected previous receivables.
        $selectedReceivableIds = array_values(array_filter(
            array_map('intval', (array) $request->input('selected_receivable_ids', [])),
        ));
        $visitSummary = $this->carryOver->getVisitPayableSummary($rmeInvoice, $selectedReceivableIds);

        if ($visitSummary['selected_remaining'] > 0) {
            $result = $this->service->allocateVisitPayment(
                $rmeInvoice,
                $request->user(),
                $validated,
                $selectedReceivableIds,
            );
            $freshInvoice = $rmeInvoice->fresh();
            $allocation = [
                'allocated_to_parent' => $result->allocatedToParent,
                'allocated_to_control' => $result->allocatedToControl,
                'payment_batch_uuid' => $result->paymentBatchUuid,
            ];

            if ($freshInvoice?->isPaid()) {
                return redirect()
                    ->route('rme.cashier.receipt.show', [$clinicVisit, $freshInvoice])
                    ->with('status', 'Pembayaran selesai, visit selesai total.')
                    ->with('payment_allocation', $allocation);
            }

            return redirect()
                ->route('rme.cashier.show', [$clinicVisit, $freshInvoice ?? $rmeInvoice])
                ->with('status', 'Pembayaran berhasil dicatat, visit selesai dan sisa tagihan masuk piutang. No. Kwitansi: '.($result->primaryPayment()?->payment_number ?? '-'))
                ->with('payment_allocation', $allocation);
        }

        $payment = $this->service->pay($rmeInvoice, $request->user(), $validated);
        $freshInvoice = $rmeInvoice->fresh();

        if ($freshInvoice?->isPaid()) {
            return redirect()
                ->route('rme.cashier.receipt.show', [$clinicVisit, $freshInvoice])
                ->with('status', 'Pembayaran selesai, visit selesai total. No. Kwitansi: '.$payment->payment_number);
        }

        return redirect()
            ->route('rme.cashier.show', [$clinicVisit, $freshInvoice ?? $rmeInvoice])
            ->with('status', 'Pembayaran sebagian berhasil, visit selesai dan sisa tagihan masuk piutang. No. Kwitansi: '.$payment->payment_number);
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
        $allocation = session('payment_allocation');
        $batchPayments = collect();

        if (is_array($allocation) && ! empty($allocation['payment_batch_uuid'])) {
            $batchPayments = $this->service->paymentsForBatch($allocation['payment_batch_uuid']);
        } elseif ($payment?->payment_batch_uuid) {
            $batchPayments = $this->service->paymentsForBatch($payment->payment_batch_uuid);
        }

        $allocatedToParent = 0.0;
        $allocatedToControl = 0.0;

        if ($batchPayments->isNotEmpty()) {
            $allocatedToParent = round(
                (float) $batchPayments
                    ->filter(fn ($batchPayment) => (int) $batchPayment->rme_invoice_id !== (int) $rmeInvoice->id)
                    ->sum('amount'),
                2,
            );
            $allocatedToControl = round(
                (float) $batchPayments
                    ->filter(fn ($batchPayment) => (int) $batchPayment->rme_invoice_id === (int) $rmeInvoice->id)
                    ->sum('amount'),
                2,
            );
        }

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
            'allocatedToParent' => $allocatedToParent,
            'allocatedToControl' => $allocatedToControl,
            'hasPaymentAllocation' => $allocatedToParent > 0 || $allocatedToControl > 0,
        ]);
    }
}
