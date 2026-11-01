<?php

namespace App\Modules\RmeInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentMethod\Services\PaymentMethodService;
use App\Modules\RmeInvoice\Interfaces\RmeReceiptSnapshotRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Models\RmePaymentSubmission;
use App\Modules\RmeInvoice\Models\RmeReceiptSnapshot;
use App\Modules\RmeInvoice\Requests\CreateRmePaymentRequest;
use App\Modules\RmeInvoice\Services\RmeControlReceivableService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Services\RmePaymentSubmissionService;
use App\Modules\RmeInvoice\Support\RmeInvoiceDocument;
use App\Modules\RmeInvoice\Support\RmePaymentDeclaredView;
use App\Modules\RmeInvoice\Support\RmePaymentSubmissionReplayed;
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
        private readonly RmePaymentSubmissionService $submissions,
        private readonly RmeReceiptSnapshotRepositoryInterface $receiptSnapshots,
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
            // Rule 205 — one server-issued intent per form on this page (the
            // ordinary form and the Kontrol-only form are different payments), and
            // the plan the ordinary form offers. Fresh on every render: a page
            // re-rendered after a refusal is a new view of new balances.
            'paymentIntent' => $this->submissions->issueIntent($rmeInvoice),
            'controlOnlyIntent' => $this->submissions->issueIntent($rmeInvoice),
            'paymentPlan' => RmePaymentDeclaredView::planFor(
                (bool) $payableSummary['has_carry_over'],
                $outstandingReceivables->isNotEmpty(),
            ),
        ]);
    }

    public function store(CreateRmePaymentRequest $request, ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): RedirectResponse
    {
        $this->authorize('pay', $rmeInvoice);
        abort_unless($rmeInvoice->belongsToVisit($clinicVisit), 404);

        // Rule 205 — the same form submitted again (double click, retry after a
        // lost response, resubmission after "back"): the original result, nothing
        // new recorded. Caught early by the FormRequest, or under the invoice lock
        // by the payment service when two copies raced past it.
        if (($replayed = $request->replayedSubmission()) !== null) {
            return $this->replayResponse($clinicVisit, $rmeInvoice, $replayed);
        }

        try {
            return $this->recordPayment($request, $clinicVisit, $rmeInvoice);
        } catch (RmePaymentSubmissionReplayed $replayed) {
            return $this->replayResponse($clinicVisit, $rmeInvoice, $replayed->submission);
        }
    }

    /**
     * Rule 205 — answers a repeated submission exactly where the original one
     * landed (the receipt once the invoice is settled, otherwise the invoice),
     * naming the ORIGINAL receipt number and saying that no new payment was made.
     * Only ever reached for the account that recorded it, which still holds the
     * billing permission and the invoice's working branch (authorized above and
     * re-checked by the service under the lock).
     */
    private function replayResponse(ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice, RmePaymentSubmission $submission): RedirectResponse
    {
        $freshInvoice = $rmeInvoice->fresh() ?? $rmeInvoice;
        $receiptNumber = $submission->payment?->payment_number ?? '-';
        $message = 'Pembayaran ini sudah tercatat sebelumnya — tidak ada pembayaran baru yang dibuat. No. Kwitansi: '.$receiptNumber.'.';

        $response = $freshInvoice->isPaid()
            ? redirect()->route('rme.cashier.receipt.show', [$clinicVisit, $freshInvoice])
            : redirect()->route('rme.cashier.show', [$clinicVisit, $freshInvoice]);

        $response->with('status', $message)->with('payment_replayed', true);

        if ($submission->payment_batch_uuid !== null) {
            $batch = $this->service->paymentsForBatch((string) $submission->payment_batch_uuid);
            $response->with('payment_allocation', [
                'allocated_to_parent' => round((float) $batch->filter(fn ($payment) => (int) $payment->rme_invoice_id !== (int) $rmeInvoice->id)->sum('amount'), 2),
                'allocated_to_control' => round((float) $batch->filter(fn ($payment) => (int) $payment->rme_invoice_id === (int) $rmeInvoice->id)->sum('amount'), 2),
                'payment_batch_uuid' => (string) $submission->payment_batch_uuid,
            ]);
        }

        return $response;
    }

    private function recordPayment(CreateRmePaymentRequest $request, ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): RedirectResponse
    {
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
                ->with('status', $this->heldForZeroValueClosureMessage($clinicVisit, $freshInvoice ?? $rmeInvoice, $receiptNumber) ?? 'Pembayaran berhasil dicatat. No. Kwitansi: '.$receiptNumber)
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

            $receiptNumber = $result->primaryPayment()?->payment_number ?? '-';

            return redirect()
                ->route('rme.cashier.show', [$clinicVisit, $freshInvoice ?? $rmeInvoice])
                ->with('status', $this->heldForZeroValueClosureMessage($clinicVisit, $freshInvoice ?? $rmeInvoice, $receiptNumber) ?? 'Pembayaran berhasil dicatat, visit selesai dan sisa tagihan masuk piutang. No. Kwitansi: '.$receiptNumber)
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
     * FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 (owner decision PP-5) — reprint
     * one issued receipt from its immutable snapshot. Always rendered from the
     * snapshot, never from live rows; stamped "DIREVISI — lihat versi ke-N" when the
     * invoice was corrected after it was issued. Not limited to PAID invoices: a
     * receipt that was issued stays reprintable even if a later correction raised
     * the total and the invoice is PARTIAL again. Same policy as the receipt page.
     */
    public function receiptSnapshot(Request $request, ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice, int $receiptSnapshot): View
    {
        $this->authorize('viewReceipt', $rmeInvoice);
        abort_unless($rmeInvoice->belongsToVisit($clinicVisit), 404);

        $snapshot = RmeReceiptSnapshot::query()->whereKey($receiptSnapshot)->first();
        abort_if($snapshot === null || (int) $snapshot->rme_invoice_id !== (int) $rmeInvoice->id, 404);

        return view('rme.cashier.receipt.show', $this->resolveReceiptData($clinicVisit, $rmeInvoice, $snapshot));
    }

    /**
     * The snapshot a receipt must be rendered from, or null when the live invoice
     * is still the document that receipt described. Before any post-payment
     * correction every receipt carries the current invoice version, so this is
     * null and the receipt renders exactly as it always did.
     */
    private function frozenReceiptFor(RmeInvoice $invoice): ?RmeReceiptSnapshot
    {
        if (! $invoice->isRevised()) {
            return null;
        }

        $latest = $this->receiptSnapshots->latestForInvoice((int) $invoice->id);

        return $latest !== null && (int) $latest->invoice_version < (int) $invoice->correction_version ? $latest : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveReceiptData(ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice, ?RmeReceiptSnapshot $snapshot = null): array
    {
        $snapshot ??= $this->frozenReceiptFor($rmeInvoice);

        if ($snapshot !== null) {
            return $this->snapshotReceiptData($clinicVisit, $rmeInvoice, $snapshot);
        }

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

    /**
     * PP-5 — the receipt exactly as issued. Money, items, the frozen branch header,
     * the payment and its date come from the snapshot; a snapshot that no longer
     * hashes to its recorded value is refused, never printed.
     *
     * @return array<string, mixed>
     */
    private function snapshotReceiptData(ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice, RmeReceiptSnapshot $snapshot): array
    {
        abort_unless($snapshot->integrityHolds(), 409, 'Snapshot kwitansi tidak lolos verifikasi integritas dan tidak dapat dicetak.');

        $clock = app(ClinicalClock::class);
        $document = (array) $snapshot->snapshot;
        $receipt = (array) ($document['receipt'] ?? []);

        $methodIds = array_values(array_filter(array_map(
            fn (array $payment) => $payment['payment_method_id'] ?? null,
            (array) ($document['payments'] ?? []),
        )));
        // Labels frozen at issuance win; a live lookup is only the fallback for a
        // snapshot that carries none.
        $frozenNames = (array) ($receipt['labels']['payment_methods'] ?? []);
        $methodNames = PaymentMethod::query()->whereIn('id', $methodIds === [] ? [0] : $methodIds)->pluck('name', 'id')->all();
        foreach ($frozenNames as $id => $name) {
            $methodNames[(int) $id] = $name;
        }
        $methods = array_map(
            fn (array $payment) => isset($payment['payment_method_id']) && filled($methodNames[$payment['payment_method_id']] ?? null)
                ? (string) $methodNames[$payment['payment_method_id']]
                : 'Tidak tercatat',
            (array) ($document['payments'] ?? []),
        );

        $invoice = $rmeInvoice->load(['cashier', 'branch', 'labCaseCandidates.convertedLabOrder', 'labCaseCandidates.treatment']);
        $payment = RmePayment::query()->with('cashier')->find((int) $snapshot->rme_payment_id);
        $paidAt = isset($receipt['paid_at']) ? CarbonImmutable::parse((string) $receipt['paid_at']) : null;

        return [
            'visit' => $clinicVisit->load(['patient', 'doctor', 'initialTreatment']),
            'invoice' => $invoice,
            'document' => RmeInvoiceDocument::fromReceiptSnapshot($document, $methods),
            'invoiceTerms' => config('rme_invoice'),
            'paidAtClinical' => $paidAt?->setTimezone($clock->timezone()),
            'printedAtClinical' => $clock->now(),
            'payment' => $payment,
            'labCaseCandidates' => $invoice->labCaseCandidates,
            'allocatedToParent' => 0.0,
            'allocatedToControl' => 0.0,
            'hasPaymentAllocation' => false,
            'receiptSnapshot' => $snapshot,
            'supersededByVersion' => (int) $snapshot->invoice_version < (int) $rmeInvoice->correction_version
                ? (int) $rmeInvoice->correction_version
                : null,
            'frozenBranch' => (object) array_merge(['name' => null, 'address' => null, 'phone' => null, 'code' => null], (array) ($document['branch'] ?? [])),
            'frozenCashierName' => isset($receipt['labels']['cashier_name']) ? (string) $receipt['labels']['cashier_name'] : null,
        ];
    }

    /**
     * Rule 217 §9 (batch residual) — a batch that paid only earlier receivables leaves
     * the visit cashier_pending when its own Rp0 invoice is closable "Tanpa Biaya".
     * Tell the cashier the next step instead of announcing a completed visit — only
     * when the service's own hold predicate says so (flag ON, Rp0, closable).
     */
    private function heldForZeroValueClosureMessage(ClinicVisit $clinicVisit, RmeInvoice $invoice, string $receiptNumber): ?string
    {
        $visit = $clinicVisit->fresh();

        if ($visit === null || ! $this->service->isHeldForZeroValueClosure($invoice->fresh() ?? $invoice, $visit)) {
            return null;
        }

        return 'Pembayaran piutang sebelumnya tercatat. No. Kwitansi: '.$receiptNumber
            .'. Tagihan Rp0 kunjungan ini belum selesai — selesaikan dengan "Selesai Tanpa Biaya".';
    }
}
