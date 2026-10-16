<?php

namespace App\Modules\RmeInvoice\Support;

use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;

/**
 * FEATURE-RME-BILLING-REFUND-1 / Phase 0 (audit BCF-02) — the vocabulary and
 * payloads of the RME financial audit trail.
 *
 * Until this phase `app/Modules/RmeInvoice` wrote nothing to `sys_audit_logs`:
 * creating an invoice and recording a payment left no trace beyond the rows
 * themselves. A refund workflow cannot be audited against a ledger whose own
 * history is unaudited, so these two events come first.
 *
 * PRIVACY: payloads carry ids, numbers, amounts, statuses and counts only. They
 * never carry a patient name, KTP/NIK, item description, invoice notes, payment
 * notes or a payment reference number — free text written by a cashier may name
 * a patient, and a transfer reference may carry bank-account detail. Whether a
 * reference was recorded is kept as a boolean.
 *
 * The rows are written INSIDE the transaction that writes the fact, so an
 * invoice or payment that commits always has its audit row and one that rolls
 * back has none. An unaudited payment is the state this class exists to end.
 */
final class RmeFinancialAuditEvent
{
    public const ENTITY_INVOICE = 'trx_rme_invoices';

    public const ENTITY_PAYMENT = 'trx_rme_payments';

    public const ACTION_INVOICE_CREATED = 'RME_INVOICE_CREATED';

    public const ACTION_PAYMENT_RECORDED = 'RME_PAYMENT_RECORDED';

    /**
     * @return array<string, mixed>
     */
    public static function invoiceCreatedPayload(RmeInvoice $invoice, int $itemCount): array
    {
        return [
            'invoice_id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'branch_id' => (int) $invoice->branch_id,
            'clinic_visit_id' => (int) $invoice->clinic_visit_id,
            'patient_id' => (int) $invoice->patient_id,
            'medical_record_id' => $invoice->medical_record_id !== null ? (int) $invoice->medical_record_id : null,
            'cashier_id' => (int) $invoice->cashier_id,
            'status' => (string) $invoice->status,
            'item_count' => $itemCount,
            'subtotal' => self::money($invoice->subtotal),
            'discount_total' => self::money($invoice->discount_total),
            'grand_total' => self::money($invoice->grand_total),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function paymentRecordedPayload(RmePayment $payment, float $paidBefore, float $grandTotal): array
    {
        $amount = self::money($payment->amount);

        return [
            'payment_id' => (int) $payment->id,
            'payment_number' => (string) $payment->payment_number,
            'rme_invoice_id' => (int) $payment->rme_invoice_id,
            'branch_id' => (int) $payment->branch_id,
            'clinic_visit_id' => (int) $payment->clinic_visit_id,
            'patient_id' => (int) $payment->patient_id,
            'cashier_id' => (int) $payment->cashier_id,
            'payment_method_id' => $payment->payment_method_id !== null ? (int) $payment->payment_method_id : null,
            'payment_batch_uuid' => $payment->payment_batch_uuid,
            'amount' => $amount,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'invoice_grand_total' => self::money($grandTotal),
            'invoice_paid_before' => self::money($paidBefore),
            'invoice_paid_after' => self::money($paidBefore + $amount),
            'reference_recorded' => filled($payment->reference_number),
        ];
    }

    private static function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
