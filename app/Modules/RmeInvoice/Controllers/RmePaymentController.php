<?php

namespace App\Modules\RmeInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\PaymentMethod\Services\PaymentMethodService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Requests\CreateRmePaymentRequest;
use App\Modules\RmeInvoice\Services\RmeControlReceivableService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeInvoiceDocument;
use App\Support\Clinical\ClinicalClock;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        abort_unless($rmeInvoice->belongsToVisit($clinicVisit), 404);

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
            // FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — D10 display only; the
            // service refuses under the invoice row lock.
            'paymentBlock' => $this->service->activeCorrectionBlocking($rmeInvoice),
            // Rule 203 — OPS-OQ-1 dispute hold, display only; the service refuses
            // under the invoice row lock.
            'disputeHold' => $this->service->activeDisputeHolding($rmeInvoice),
            // Rule 204 (OPS-OQ-1b) — whether the separate Kontrol-only action is
            // offered; display only, the service decides under the locks.
            'controlOnly' => $payableSummary['has_carry_over']
                ? $this->service->controlOnlyEligibility($rmeInvoice)
                : null,
        ]);
    }

    public function store(CreateRmePaymentRequest $request, ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): RedirectResponse
    {
        $this->authorize('pay', $rmeInvoice);
        abort_unless($rmeInvoice->belongsToVisit($clinicVisit), 404);

        $validated = $request->validated();

        // Rule 204 (OPS-OQ-1b) — explicit, confirmed Kontrol-only payment while the
        // origin is disputed. Never inferred: without payment_scope the ordinary
        // rules below apply unchanged.
        if (($validated['payment_scope'] ?? null) === RmePaymentService::PAYMENT_SCOPE_CONTROL_ONLY) {
            $payment = $this->service->payControlInvoiceOnly($rmeInvoice, $request->user(), $validated);
            $freshInvoice = $rmeInvoice->fresh();
            $message = 'Pembayaran tagihan kontrol tercatat. No. Kwitansi: '.$payment->payment_number
                .'. Tagihan kunjungan asal tetap dalam peninjauan dan belum ditagihkan.';

            return $freshInvoice?->isPaid()
                ? redirect()->route('rme.cashier.receipt.show', [$clinicVisit, $freshInvoice])->with('status', $message)
                : redirect()->route('rme.cashier.show', [$clinicVisit, $freshInvoice ?? $rmeInvoice])->with('status', $message);
        }

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
        abort_unless($rmeInvoice->belongsToVisit($clinicVisit), 404);

        if (! $rmeInvoice->isPaid()) {
            return redirect()
                ->route('rme.cashier.show', [$clinicVisit, $rmeInvoice])
                ->with('error', 'Kwitansi pelunasan hanya tersedia untuk invoice yang sudah PAID.');
        }

        return view('rme.cashier.receipt.show', $this->resolveReceiptData($clinicVisit, $rmeInvoice));
    }

    /**
     * FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-06 — the receipt as a PDF.
     *
     * The kwitansi is contracted to be a single page. A browser's print dialog
     * cannot be measured, so the same receipt is also renderable through the
     * project's existing dompdf pipeline, where the page count is a fact that
     * can be asserted in CI and checked in production with pdfinfo.
     *
     * It reuses the same authorisation (viewReceipt), the same paid-only rule
     * and the same assembled data as the on-screen receipt.
     */
    public function receiptPdf(Request $request, ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): Response|RedirectResponse
    {
        $this->authorize('viewReceipt', $rmeInvoice);
        abort_unless($rmeInvoice->belongsToVisit($clinicVisit), 404);

        if (! $rmeInvoice->isPaid()) {
            return redirect()
                ->route('rme.cashier.show', [$clinicVisit, $rmeInvoice])
                ->with('error', 'Kwitansi pelunasan hanya tersedia untuk invoice yang sudah PAID.');
        }

        $data = $this->resolveReceiptData($clinicVisit, $rmeInvoice);
        $filename = 'kwitansi-'.($rmeInvoice->invoice_number ?? $rmeInvoice->id).'.pdf';

        // REVISION-RME-VISIT-DETAIL-INVOICE-REGISTRATION-UX-1 — the invoice follows
        // the clinic's printed landscape form. Same A4 paper, rotated: no cashier
        // hardware assumption changes.
        return Pdf::loadView('rme.cashier.receipt.pdf', $data)
            ->setPaper('a4', 'landscape')
            ->download($filename);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveReceiptData(ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): array
    {
        // Newest first (RmePaymentRepository::forInvoice orders by paid_at desc),
        // so $payment is the settling payment and its date is the payment date.
        $clock = app(ClinicalClock::class);
        $payments = $this->service->paymentsForInvoice($rmeInvoice);
        $payment = $payments->first();
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

        return [
            'visit' => $clinicVisit->load(['patient', 'doctor', 'initialTreatment']),
            'invoice' => $invoice,
            // Every amount the invoice document prints, read from stored
            // canonical fields and reconciled against them.
            'document' => RmeInvoiceDocument::fromInvoice($invoice, $payments),
            'invoiceTerms' => config('rme_invoice'),
            // Printed dates are clinic dates. The application clock is UTC, so a
            // payment taken between 00:00 and 07:59 WITA would otherwise print
            // the previous day on a financial document.
            'paidAtClinical' => $payment?->paid_at !== null
                ? CarbonImmutable::instance($payment->paid_at)->setTimezone($clock->timezone())
                : null,
            'printedAtClinical' => $clock->now(),
            'payment' => $payment,
            'labCaseCandidates' => $invoice->labCaseCandidates,
            'allocatedToParent' => $allocatedToParent,
            'allocatedToControl' => $allocatedToControl,
            'hasPaymentAllocation' => $allocatedToParent > 0 || $allocatedToControl > 0,
        ];
    }
}
