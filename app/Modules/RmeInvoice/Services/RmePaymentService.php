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
use Illuminate\Validation\ValidationException;

class RmePaymentService
{
    public function __construct(
        private readonly RmePaymentRepositoryInterface $payments,
        private readonly RmeInvoiceRepositoryInterface $invoices,
        private readonly RmePaymentNumberGeneratorService $numberGenerator,
        private readonly ClinicVisitService $visitService,
        private readonly BranchService $branches,
    ) {}

    /**
     * @param  array{payment_method_id?: int|null, amount: numeric, paid_at: string, reference_number?: string|null, notes?: string|null}  $data
     */
    public function pay(RmeInvoice $invoice, User $cashier, array $data): RmePayment
    {
        $payment = DB::transaction(function () use ($invoice, $cashier, $data) {
            $invoice = RmeInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            // Payment stays within the invoice's own "Cabang RME" branch set,
            // never a single BranchContext/MAIN fallback (Sprint 23 Phase 23.10).
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

            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Jumlah pembayaran harus lebih dari 0.',
                ]);
            }

            $grandTotal = round((float) $invoice->grand_total, 2);
            $paidBefore = round((float) $invoice->paidAmount(), 2);
            $remainingBefore = max(0, round($grandTotal - $paidBefore, 2));

            if ($remainingBefore <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Invoice ini tidak memiliki sisa tagihan.',
                ]);
            }

            if ($amount > $remainingBefore) {
                throw ValidationException::withMessages([
                    'amount' => 'Pembayaran tidak boleh melebihi sisa tagihan.',
                ]);
            }

            $visit = $invoice->clinicVisit;
            if (! $visit) {
                throw ValidationException::withMessages([
                    'rme_invoice_id' => 'Kunjungan klinik tidak ditemukan untuk invoice ini.',
                ]);
            }

            $this->applyConsentVerification($visit, $cashier, $data);
            $this->assertConsentVerified($visit);

            $payment = $this->payments->create([
                'branch_id' => $invoice->branch_id,
                'rme_invoice_id' => $invoice->id,
                'clinic_visit_id' => $invoice->clinic_visit_id,
                'patient_id' => $invoice->patient_id,
                'cashier_id' => $cashier->id,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'payment_number' => $this->numberGenerator->generate(),
                'amount' => $amount,
                'paid_at' => $data['paid_at'],
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $paidAfter = round($paidBefore + $amount, 2);
            $remainingAfter = max(0, round($grandTotal - $paidAfter, 2));
            $newStatus = $remainingAfter <= 0 ? RmeInvoice::STATUS_PAID : RmeInvoice::STATUS_PARTIAL;

            $this->invoices->update($invoice, ['status' => $newStatus]);

            if ($newStatus === RmeInvoice::STATUS_PAID && $visit->status === ClinicVisit::STATUS_CASHIER_PENDING) {
                $this->visitService->transitionStatus($visit, ClinicVisit::STATUS_COMPLETED);
            }

            return $payment->refresh();
        });

        // Post-commit: generate lab case candidates for eligible invoice items.
        // Runs after the payment transaction commits so a generation failure never
        // rolls back a successful RME payment. Errors are logged, not re-thrown.
        try {
            $invoice = RmeInvoice::findOrFail($payment->rme_invoice_id);

            if ($invoice->isPaid()) {
                app(RmeLabIntegrationService::class)->generateForPaidInvoice(
                    $invoice,
                    $cashier,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Lab case candidate generation failed after RME payment', [
                'rme_invoice_id' => $payment->rme_invoice_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $payment;
    }

    public function paymentsForInvoice(RmeInvoice $invoice): Collection
    {
        return $this->payments->forInvoice($invoice);
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
