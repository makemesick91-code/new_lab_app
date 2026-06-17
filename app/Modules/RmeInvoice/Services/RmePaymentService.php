<?php

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmePaymentRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Illuminate\Support\Collection;
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
            $this->applyConsentVerification($visit, $cashier, $data);
            $this->assertConsentVerified($visit);

            $payment = $this->recordPayment($invoice, $visit, $cashier, $data, $amount);

            $this->refreshInvoiceStatus($invoice);
            $this->completeVisitIfPaid($invoice, $visit);

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

            $this->applyConsentVerification($controlVisit, $cashier, $data);
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
                $this->completeVisitIfPaid($parentInvoice, $parentVisit);

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

    private function completeVisitIfPaid(RmeInvoice $invoice, ClinicVisit $visit): void
    {
        if ($invoice->isPaid() && $visit->status === ClinicVisit::STATUS_CASHIER_PENDING) {
            $this->visitService->transitionStatus($visit, ClinicVisit::STATUS_COMPLETED);
        }
    }

    /**
     * Complete a control visit when its own invoice has no remaining balance.
     *
     * Unlike {@see completeVisitIfPaid()}, this does not require the control
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
     * @param  array<string, mixed>  $data
     */
    private function applyConsentVerification(ClinicVisit $visit, User $cashier, array $data): void
    {
        if (! array_key_exists('consent_signed_by_patient', $data)
            && ! array_key_exists('consent_signed_by_doctor', $data)) {
            return;
        }

        $patientSigned = filter_var($data['consent_signed_by_patient'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $doctorSigned = filter_var($data['consent_signed_by_doctor'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $visit->update([
            'consent_signed_by_patient' => $patientSigned,
            'consent_signed_by_doctor' => $doctorSigned,
            'consent_verified_at' => ($patientSigned && $doctorSigned) ? now() : null,
            'consent_verified_by' => ($patientSigned && $doctorSigned) ? $cashier->id : null,
        ]);

        $visit->refresh();
    }

    private function assertConsentVerified(ClinicVisit $visit): void
    {
        if ($visit->hasVerifiedConsent()) {
            return;
        }

        throw ValidationException::withMessages([
            'consent_signed_by_patient' => 'Pembayaran tidak dapat diproses karena Surat Persetujuan Tindakan belum dikonfirmasi ditandatangani oleh pasien dan dokter.',
        ]);
    }
}
