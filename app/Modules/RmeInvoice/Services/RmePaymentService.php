<?php

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmePaymentRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RmePaymentService
{
    public function __construct(
        private readonly RmePaymentRepositoryInterface $payments,
        private readonly RmeInvoiceRepositoryInterface $invoices,
        private readonly RmePaymentNumberGeneratorService $numberGenerator,
        private readonly ClinicVisitService $visitService,
        private readonly BranchContext $branchContext,
    ) {}

    /**
     * @param  array{payment_method_id?: int|null, amount: numeric, paid_at: string, reference_number?: string|null, notes?: string|null}  $data
     */
    public function pay(RmeInvoice $invoice, User $cashier, array $data): RmePayment
    {
        return DB::transaction(function () use ($invoice, $cashier, $data) {
            $invoice = RmeInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->branch_id !== $this->branchContext->requireId()) {
                throw ValidationException::withMessages([
                    'rme_invoice_id' => 'Invoice tidak ditemukan di cabang aktif.',
                ]);
            }

            if ($invoice->status !== RmeInvoice::STATUS_UNPAID) {
                throw ValidationException::withMessages([
                    'rme_invoice_id' => 'Pembayaran hanya dapat dicatat untuk invoice berstatus UNPAID.',
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

            if ($amount !== $grandTotal) {
                throw ValidationException::withMessages([
                    'amount' => 'Jumlah pembayaran harus sama dengan total tagihan (pembayaran penuh).',
                ]);
            }

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

            $this->invoices->update($invoice, ['status' => RmeInvoice::STATUS_PAID]);

            $visit = $invoice->clinicVisit;
            if ($visit && $visit->status === ClinicVisit::STATUS_CASHIER_PENDING) {
                $this->visitService->transitionStatus($visit, ClinicVisit::STATUS_COMPLETED);
            }

            return $payment->refresh();
        });
    }

    public function paymentsForInvoice(RmeInvoice $invoice): Collection
    {
        return $this->payments->forInvoice($invoice);
    }
}
