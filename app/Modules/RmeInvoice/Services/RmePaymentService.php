<?php

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmePaymentRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RmePaymentService
{
    public function __construct(
        private readonly RmePaymentRepositoryInterface $payments,
        private readonly RmeInvoiceRepositoryInterface $invoices,
        private readonly RmePaymentNumberGeneratorService $numberGenerator,
        private readonly ClinicVisitService $visitService,
        private readonly BranchService $branches,
        private readonly RmeControlReceivableService $carryOver,
        private readonly RmeWorkingBranchScope $workingBranchScope,
    ) {}

    /**
     * @param  array{payment_method_id?: int|null, amount: numeric, paid_at: string, reference_number?: string|null, notes?: string|null}  $data
     */
    public function pay(RmeInvoice $invoice, User $cashier, array $data): RmePayment
    {
        $payment = DB::transaction(function () use ($invoice, $cashier, $data) {
            $invoice = RmeInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            $this->assertInvoicePayable($invoice);

            $amount = $this->normalizeAmount($data['amount']);
            $remainingBefore = $this->remainingForInvoice($invoice);

            if ($amount > $remainingBefore) {
                throw ValidationException::withMessages([
                    'amount' => 'Pembayaran tidak boleh melebihi sisa tagihan.',
                ]);
            }

            $visit = $this->requireVisit($invoice);
            $this->assertConsentVerified($visit);

            $payment = $this->recordPayment($invoice, $visit, $cashier, $data, $amount);

            $this->refreshInvoiceStatus($invoice);
            $this->completeVisitAfterCashierPayment($invoice, $visit);

            return $payment->refresh();
        });

        $this->generateLabCandidatesIfPaid($payment->rme_invoice_id, $cashier);

        return $payment;
    }

    /**
     * FIFO allocation: parent receivables first, remainder to control invoice.
     *
     * @param  array{payment_method_id?: int|null, amount: numeric, paid_at: string, reference_number?: string|null, notes?: string|null}  $data
     */
    public function allocateControlPayment(RmeInvoice $controlInvoice, User $cashier, array $data): RmeControlPaymentResult
    {
        $summary = $this->carryOver->getControlPayableSummary($controlInvoice);

        if (! $summary['has_carry_over']) {
            throw ValidationException::withMessages([
                'amount' => 'Kunjungan kontrol ini tidak memiliki piutang kunjungan sebelumnya.',
            ]);
        }

        $amount = $this->normalizeAmount($data['amount']);

        if ($amount > $summary['total_payable']) {
            throw ValidationException::withMessages([
                'amount' => 'Pembayaran tidak boleh melebihi total yang harus dibayar.',
            ]);
        }

        $controlInvoice->loadMissing('clinicVisit');
        $controlVisit = $this->requireVisit($controlInvoice);
        $batchUuid = (string) Str::uuid();

        $result = DB::transaction(function () use ($controlInvoice, $controlVisit, $cashier, $data, $amount, $summary, $batchUuid) {
            $controlInvoice = RmeInvoice::query()->lockForUpdate()->findOrFail($controlInvoice->id);
            $this->assertInvoicePayable($controlInvoice);

            $this->assertConsentVerified($controlVisit);

            $remainingPayment = $amount;
            $allocatedToParent = 0.0;
            $parentPayments = collect();

            foreach ($summary['carry_over_invoices'] as $parentInvoice) {
                if ($remainingPayment <= 0) {
                    break;
                }

                $parentInvoice = RmeInvoice::query()->lockForUpdate()->findOrFail($parentInvoice->id);

                if (! $parentInvoice->isPayable()) {
                    continue;
                }

                $parentVisit = $this->requireVisit($parentInvoice);
                $parentRemaining = $this->remainingForInvoice($parentInvoice);
                $allocateAmount = min($remainingPayment, $parentRemaining);

                if ($allocateAmount <= 0) {
                    continue;
                }

                $parentNote = sprintf(
                    'Alokasi pembayaran kontrol dari visit %s',
                    $controlVisit->visit_number,
                );

                $parentPayment = $this->recordPayment(
                    $parentInvoice,
                    $parentVisit,
                    $cashier,
                    $data,
                    $allocateAmount,
                    $batchUuid,
                    $parentNote,
                );

                $this->refreshInvoiceStatus($parentInvoice);
                $this->completeVisitAfterCashierPayment($parentInvoice, $parentVisit);

                $parentPayments->push($parentPayment->refresh());
                $allocatedToParent = round($allocatedToParent + $allocateAmount, 2);
                $remainingPayment = round($remainingPayment - $allocateAmount, 2);
            }

            $controlPayment = null;
            $allocatedToControl = 0.0;

            if ($remainingPayment > 0) {
                $controlRemaining = $this->remainingForInvoice($controlInvoice);
                $allocateAmount = min($remainingPayment, $controlRemaining);

                if ($allocateAmount > 0) {
                    $controlNote = $data['notes'] ?? null;
                    $controlPayment = $this->recordPayment(
                        $controlInvoice,
                        $controlVisit,
                        $cashier,
                        $data,
                        $allocateAmount,
                        $batchUuid,
                        $controlNote,
                    );

                    $this->refreshInvoiceStatus($controlInvoice);
                    $allocatedToControl = $allocateAmount;
                }
            }

            // Phase 27.4.1 completion rule: a control (follow-up) visit completes
            // once its OWN invoice has no remaining balance — including a free
            // follow-up with no additional cost. Previous-visit receivables are
            // payable from the control screen but must never block control-visit
            // completion, so the parent balance is deliberately ignored here. A
            // successful payment in this batch is still required: a free control
            // visit is never auto-completed without a cashier payment action.
            $this->completeControlVisitIfSettled(
                $controlInvoice,
                $controlVisit,
                paymentMade: $allocatedToParent > 0 || $allocatedToControl > 0,
            );

            return new RmeControlPaymentResult(
                parentPayments: $parentPayments,
                controlPayment: $controlPayment?->refresh(),
                allocatedToParent: $allocatedToParent,
                allocatedToControl: $allocatedToControl,
                paymentBatchUuid: $batchUuid,
            );
        });

        foreach ($summary['carry_over_invoices'] as $parentInvoice) {
            $this->generateLabCandidatesIfPaid($parentInvoice->id, $cashier);
        }

        if ($result->controlPayment) {
            $this->generateLabCandidatesIfPaid($result->controlPayment->rme_invoice_id, $cashier);
        }

        return $result;
    }

    /**
     * Sprint 62.2 — generalized FIFO allocation for ANY new visit.
     *
     * Collects the patient's selected previous receivables (oldest-first) then the
     * current visit invoice, recording one {@see RmePayment} per real invoice id
     * under a shared payment_batch_uuid. The current invoice grand_total is never
     * inflated — each invoice keeps its own ledger and identity. The authoritative
     * selection is recomputed server-side via getVisitPayableSummary(), so forged /
     * other-patient / other-branch / already-paid ids are dropped and never paid.
     *
     * @param  array{payment_method_id?: int|null, amount: numeric, paid_at: string, reference_number?: string|null, notes?: string|null}  $data
     * @param  list<int|string>  $selectedReceivableIds
     */
    public function allocateVisitPayment(RmeInvoice $currentInvoice, User $cashier, array $data, array $selectedReceivableIds): RmeControlPaymentResult
    {
        $summary = $this->carryOver->getVisitPayableSummary($currentInvoice, $selectedReceivableIds);

        if ($summary['selected_remaining'] <= 0) {
            throw ValidationException::withMessages([
                'selected_receivable_ids' => 'Tidak ada piutang sebelumnya yang valid untuk ditagihkan.',
            ]);
        }

        $amount = $this->normalizeAmount($data['amount']);

        if ($amount > $summary['total_payable']) {
            throw ValidationException::withMessages([
                'amount' => 'Pembayaran tidak boleh melebihi total yang harus dibayar.',
            ]);
        }

        $currentInvoice->loadMissing('clinicVisit');
        $currentVisit = $this->requireVisit($currentInvoice);
        $batchUuid = (string) Str::uuid();

        $result = DB::transaction(function () use ($currentInvoice, $currentVisit, $cashier, $data, $amount, $summary, $batchUuid) {
            $currentInvoice = RmeInvoice::query()->lockForUpdate()->findOrFail($currentInvoice->id);
            $this->assertInvoicePayable($currentInvoice);

            $this->assertConsentVerified($currentVisit);

            $remainingPayment = $amount;
            $allocatedToParent = 0.0;
            $parentPayments = collect();

            foreach ($summary['selected_receivables'] as $receivable) {
                if ($remainingPayment <= 0) {
                    break;
                }

                $receivable = RmeInvoice::query()->lockForUpdate()->findOrFail($receivable->id);

                if (! $receivable->isPayable()) {
                    continue;
                }

                // Re-assert branch isolation under lock (defence in depth).
                if (! in_array((int) $receivable->branch_id, $this->branches->rmeEnabledIds(), true)) {
                    continue;
                }

                $receivableVisit = $this->requireVisit($receivable);
                $receivableRemaining = $this->remainingForInvoice($receivable);
                $allocateAmount = min($remainingPayment, $receivableRemaining);

                if ($allocateAmount <= 0) {
                    continue;
                }

                $note = sprintf(
                    'Alokasi pembayaran piutang sebelumnya dari visit %s',
                    $currentVisit->visit_number,
                );

                $receivablePayment = $this->recordPayment(
                    $receivable,
                    $receivableVisit,
                    $cashier,
                    $data,
                    $allocateAmount,
                    $batchUuid,
                    $note,
                );

                $this->refreshInvoiceStatus($receivable);
                $this->completeVisitAfterCashierPayment($receivable, $receivableVisit);

                $parentPayments->push($receivablePayment->refresh());
                $allocatedToParent = round($allocatedToParent + $allocateAmount, 2);
                $remainingPayment = round($remainingPayment - $allocateAmount, 2);
            }

            $currentPayment = null;
            $allocatedToControl = 0.0;

            if ($remainingPayment > 0) {
                $currentRemaining = $this->remainingForInvoice($currentInvoice);
                $allocateAmount = min($remainingPayment, $currentRemaining);

                if ($allocateAmount > 0) {
                    $currentPayment = $this->recordPayment(
                        $currentInvoice,
                        $currentVisit,
                        $cashier,
                        $data,
                        $allocateAmount,
                        $batchUuid,
                        $data['notes'] ?? null,
                    );

                    $this->refreshInvoiceStatus($currentInvoice);
                    $allocatedToControl = $allocateAmount;
                }
            }

            // Sprint 62.2 completion rule for ordinary visits: any successful
            // payment in this cashier batch completes the current cashier_pending
            // visit (generalizes the partial-payment-completes-visit hotfix). A
            // partial current invoice stays active piutang; unfilled prior
            // receivables stay active piutang. Prior visits are already completed,
            // so completeVisitAfterCashierPayment above is a no-op guard for them.
            $this->completeVisitAfterCashierBatch(
                $currentVisit,
                paymentMade: $allocatedToParent > 0 || $allocatedToControl > 0,
            );

            return new RmeControlPaymentResult(
                parentPayments: $parentPayments,
                controlPayment: $currentPayment?->refresh(),
                allocatedToParent: $allocatedToParent,
                allocatedToControl: $allocatedToControl,
                paymentBatchUuid: $batchUuid,
            );
        });

        foreach ($summary['selected_receivables'] as $receivable) {
            $this->generateLabCandidatesIfPaid($receivable->id, $cashier);
        }

        if ($result->controlPayment) {
            $this->generateLabCandidatesIfPaid($result->controlPayment->rme_invoice_id, $cashier);
        }

        return $result;
    }

    public function paymentsForInvoice(RmeInvoice $invoice): Collection
    {
        return $this->payments->forInvoice($invoice);
    }

    /**
     * @return Collection<int, RmePayment>
     */
    public function paymentsForBatch(string $paymentBatchUuid): Collection
    {
        return RmePayment::query()
            ->with(['paymentMethod', 'cashier', 'rmeInvoice'])
            ->where('payment_batch_uuid', $paymentBatchUuid)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recordPayment(
        RmeInvoice $invoice,
        ClinicVisit $visit,
        User $cashier,
        array $data,
        float $amount,
        ?string $paymentBatchUuid = null,
        ?string $notes = null,
    ): RmePayment {
        return $this->payments->create([
            'branch_id' => $invoice->branch_id,
            'rme_invoice_id' => $invoice->id,
            'clinic_visit_id' => $visit->id,
            'patient_id' => $invoice->patient_id,
            'cashier_id' => $cashier->id,
            'payment_method_id' => $data['payment_method_id'] ?? null,
            'payment_number' => $this->numberGenerator->generate(),
            'amount' => $amount,
            'paid_at' => $data['paid_at'],
            'reference_number' => $data['reference_number'] ?? null,
            'notes' => $notes ?? ($data['notes'] ?? null),
            'payment_batch_uuid' => $paymentBatchUuid,
        ]);
    }

    private function assertInvoicePayable(RmeInvoice $invoice): void
    {
        if (! in_array((int) $invoice->branch_id, $this->branches->rmeEnabledIds(), true)) {
            throw ValidationException::withMessages([
                'rme_invoice_id' => 'Invoice tidak berada di cabang RME aktif.',
            ]);
        }

        // FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-03) — a cashier may only settle
        // invoices of the branch it is currently working in. Asserted in the
        // service, so every payment path (pay, control allocation and visit
        // allocation all funnel through here) is covered no matter which
        // controller, command or crafted request reached it. Fails closed when
        // the cashier has no valid working context.
        if (! $this->workingBranchScope->allows(Auth::user(), (int) $invoice->branch_id)) {
            throw ValidationException::withMessages([
                'rme_invoice_id' => 'Invoice ini berasal dari cabang lain. Pilih cabang kerja yang sesuai terlebih dahulu.',
            ]);
        }

        if (! $invoice->isPayable()) {
            throw ValidationException::withMessages([
                'rme_invoice_id' => 'Pembayaran hanya dapat dicatat untuk invoice berstatus UNPAID atau PARTIAL.',
            ]);
        }

        $invoice->loadMissing('items');
        if ($invoice->items->isEmpty()) {
            throw ValidationException::withMessages([
                'rme_invoice_id' => 'Invoice tidak memiliki item tindakan.',
            ]);
        }
    }

    private function normalizeAmount(mixed $amount): float
    {
        $normalized = round((float) $amount, 2);

        if ($normalized <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Jumlah pembayaran harus lebih dari 0.',
            ]);
        }

        return $normalized;
    }

    private function remainingForInvoice(RmeInvoice $invoice): float
    {
        $grandTotal = round((float) $invoice->grand_total, 2);
        $paidBefore = round((float) $invoice->paidAmount(), 2);
        $remainingBefore = max(0, round($grandTotal - $paidBefore, 2));

        if ($remainingBefore <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Invoice ini tidak memiliki sisa tagihan.',
            ]);
        }

        return $remainingBefore;
    }

    private function requireVisit(RmeInvoice $invoice): ClinicVisit
    {
        $visit = $invoice->clinicVisit;

        if (! $visit) {
            throw ValidationException::withMessages([
                'rme_invoice_id' => 'Kunjungan klinik tidak ditemukan untuk invoice ini.',
            ]);
        }

        return $visit;
    }

    private function refreshInvoiceStatus(RmeInvoice $invoice): void
    {
        $grandTotal = round((float) $invoice->grand_total, 2);
        $paidAfter = round((float) $invoice->paidAmount(), 2);
        $remainingAfter = max(0, round($grandTotal - $paidAfter, 2));
        $newStatus = $remainingAfter <= 0 ? RmeInvoice::STATUS_PAID : RmeInvoice::STATUS_PARTIAL;

        $this->invoices->update($invoice, ['status' => $newStatus]);
        $invoice->refresh();
    }

    /**
     * Hotfix (rme-partial-payment-completes-visit): any successful cashier
     * payment — full (PAID) or partial (PARTIAL) — completes the current visit
     * ("Selesai Visit"). A partial payment counts as "sudah membayar"; the
     * remaining balance stays an active receivable/piutang (invoice keeps PARTIAL
     * status) and is collected at a future follow-up, not by holding this visit
     * open. A zero-payment / still-UNPAID invoice never reaches here because
     * {@see normalizeAmount()} requires amount > 0, so the visit stays
     * cashier_pending. The Sprint 62.1 doctor→cashier gate is untouched: this
     * only ever runs after consent, payable, and room assertions inside pay().
     */
    private function completeVisitAfterCashierPayment(RmeInvoice $invoice, ClinicVisit $visit): void
    {
        if (! $invoice->isPaid() && ! $invoice->isPartial()) {
            return;
        }

        if ($visit->status === ClinicVisit::STATUS_CASHIER_PENDING) {
            $this->visitService->transitionStatus($visit, ClinicVisit::STATUS_COMPLETED);
        }
    }

    /**
     * Complete a control visit when its own invoice has no remaining balance.
     *
     * Unlike {@see completeVisitAfterCashierPayment()}, this does not require the control
     * invoice to be PAID — a free follow-up (grand_total 0, still UNPAID) settles
     * once a payment has been recorded in the batch. Parent receivables never
     * gate this transition.
     */
    private function completeControlVisitIfSettled(RmeInvoice $controlInvoice, ClinicVisit $controlVisit, bool $paymentMade): void
    {
        if (! $paymentMade) {
            return;
        }

        if ($controlInvoice->refresh()->remainingAmount() > 0) {
            return;
        }

        if ($controlVisit->status === ClinicVisit::STATUS_CASHIER_PENDING) {
            $this->visitService->transitionStatus($controlVisit, ClinicVisit::STATUS_COMPLETED);
        }
    }

    /**
     * Sprint 62.2 — complete the current visit after a generalized cashier batch.
     *
     * Mirrors the partial-payment-completes-visit hotfix for the patient-level
     * carry-over path: any successful payment in the batch (whether allocated to a
     * prior receivable, the current invoice, or both) completes the current
     * cashier_pending visit. The current invoice may remain UNPAID/PARTIAL — its
     * unpaid balance stays active piutang and is collected at a future visit. A
     * zero-payment batch never reaches here (normalizeAmount requires amount > 0).
     * The Sprint 62.1 doctor→cashier gate is untouched: this only runs after
     * consent, payable, and branch assertions inside allocateVisitPayment().
     */
    private function completeVisitAfterCashierBatch(ClinicVisit $visit, bool $paymentMade): void
    {
        if (! $paymentMade) {
            return;
        }

        if ($visit->status === ClinicVisit::STATUS_CASHIER_PENDING) {
            $this->visitService->transitionStatus($visit, ClinicVisit::STATUS_COMPLETED);
        }
    }

    private function generateLabCandidatesIfPaid(int $invoiceId, User $cashier): void
    {
        try {
            $invoice = RmeInvoice::find($invoiceId);

            if ($invoice?->isPaid()) {
                app(RmeLabIntegrationService::class)->generateForPaidInvoice($invoice, $cashier);
            }
        } catch (\Throwable $e) {
            Log::warning('Lab case candidate generation failed after RME payment', [
                'rme_invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — the payment consent gate.
     *
     * This method used to have a sibling, applyConsentVerification(), which
     * wrote consent_signed_by_patient / consent_signed_by_doctor onto the visit
     * straight from the payment request and then let this assertion read back
     * the row it had just written. The gate therefore authored its own evidence:
     * a POST carrying consent_signed_by_patient=1&consent_signed_by_doctor=1
     * satisfied it with no signature, no document and no patient involvement.
     *
     * That write path is deleted. Payment now asks a question it cannot answer
     * itself: does a signed PERSETUJUAN TINDAKAN MEDIS exist for THIS visit,
     * and is it still valid? Only RmeVisitConsentService can create that
     * evidence, and only from a real signature.
     *
     * The two boolean columns are kept as a denormalised mirror for display and
     * backward compatibility, but they are no longer the authority and are only
     * ever written server-side, from a consent that was actually signed.
     *
     * SCOPE — deliberately the CURRENT visit only.
     *
     * allocateControlPayment() and allocateVisitPayment() also settle invoices
     * belonging to EARLIER visits (carry-over receivables). Those are NOT gated
     * on their own consent, and that is the correct behaviour, not an oversight:
     *
     *   - Consent is consent to TREATMENT. A prior visit's treatment already
     *     happened, and from this sprint onward it could not have been paid
     *     without its own signed consent in the first place.
     *   - Collecting an outstanding debt is not a new treatment. Demanding a
     *     fresh signature before accepting money for old, already-consented work
     *     would be meaningless.
     *   - Every receivable that predates this sprint has NO signed consent by
     *     definition. Asserting consent on parent visits would make all
     *     historical debt permanently uncollectable — a far worse outcome than
     *     the one this gate exists to prevent.
     *
     * So: the visit whose treatment is being paid for must have consent; visits
     * whose debt is merely being collected must not be re-gated.
     */
    private function assertConsentVerified(ClinicVisit $visit): void
    {
        if (app(RmeVisitConsentService::class)->hasValidConsent($visit)) {
            return;
        }

        throw ValidationException::withMessages([
            'consent_signed_by_patient' => 'Pembayaran tidak dapat diproses karena Surat Persetujuan Tindakan Medis belum ditandatangani untuk kunjungan ini.',
        ]);
    }
}
