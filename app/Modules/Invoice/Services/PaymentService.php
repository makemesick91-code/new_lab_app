<?php

namespace App\Modules\Invoice\Services;

use App\Models\User;
use App\Modules\Invoice\Interfaces\InvoiceRepositoryInterface;
use App\Modules\Invoice\Interfaces\PaymentRepositoryInterface;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
        private readonly InvoiceRepositoryInterface $invoices,
        private readonly PaymentNumberGeneratorService $numberGenerator,
        private readonly AuditLogService $auditLogs,
    ) {}

    public function record(Invoice $invoice, array $data, ?User $actor = null): Payment
    {
        $actor = $actor ?? auth()->user();

        return DB::transaction(function () use ($invoice, $data, $actor) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! in_array($invoice->status, [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID, Invoice::STATUS_OVERDUE], true)) {
                throw ValidationException::withMessages(['invoice_id' => 'Payment hanya dapat dicatat untuk invoice ISSUED, PARTIALLY_PAID, atau OVERDUE.']);
            }

            if ($invoice->status === Invoice::STATUS_VOID) {
                throw ValidationException::withMessages(['invoice_id' => 'Invoice VOID tidak dapat menerima payment.']);
            }

            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Amount harus lebih dari 0.']);
            }

            if ($amount > round((float) $invoice->outstanding_amount, 2)) {
                throw ValidationException::withMessages(['amount' => 'Payment tidak boleh melebihi outstanding amount.']);
            }

            $payment = $this->payments->create([
                'payment_number' => $this->numberGenerator->generate(),
                'invoice_id' => $invoice->id,
                'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'],
                'amount' => $amount,
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'received_by' => $data['received_by'] ?? $actor?->id,
                'created_by' => $actor?->id,
            ]);

            $paidAmount = round((float) $this->payments->sumForInvoice($invoice), 2);
            $outstanding = max(0, round((float) $invoice->total_amount - $paidAmount, 2));
            $oldStatus = $invoice->status;
            $newStatus = $outstanding <= 0 ? Invoice::STATUS_PAID : Invoice::STATUS_PARTIALLY_PAID;

            $updated = $this->invoices->update($invoice, [
                'paid_amount' => $paidAmount,
                'outstanding_amount' => $outstanding,
                'status' => $newStatus,
            ]);

            $this->auditLogs->log(
                Payment::ENTITY_TYPE,
                $payment->id,
                AuditLog::ACTION_PAYMENT_RECORDED,
                null,
                [
                    'invoice_id' => $invoice->id,
                    'payment_number' => $payment->payment_number,
                    'amount' => $payment->amount,
                ],
                $actor,
            );

            if ($oldStatus !== $newStatus) {
                $this->auditLogs->log(
                    Invoice::ENTITY_TYPE,
                    $updated->id,
                    $newStatus === Invoice::STATUS_PAID ? AuditLog::ACTION_INVOICE_PAID : AuditLog::ACTION_INVOICE_PARTIALLY_PAID,
                    ['status' => $oldStatus, 'paid_amount' => $invoice->paid_amount, 'outstanding_amount' => $invoice->outstanding_amount],
                    ['status' => $newStatus, 'paid_amount' => $paidAmount, 'outstanding_amount' => $outstanding],
                    $actor,
                );
            }

            return $payment->refresh();
        });
    }
}
