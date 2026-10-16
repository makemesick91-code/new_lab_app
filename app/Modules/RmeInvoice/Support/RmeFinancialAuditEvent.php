<?php

namespace App\Modules\RmeInvoice\Support;

use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeInvoiceDocumentVersion;
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

    // ─── FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 ─────────────────
    //
    // Correction and document-version events follow the same contract as the
    // two above: written inside the transaction that writes the fact (so a
    // rolled-back transition leaves no row), PII-free payloads. A REFUSED
    // attempt (self-approval, out-of-scope actor) is written AFTER the
    // transaction rolled back, because it records an attempt, not a fact.

    public const ENTITY_CORRECTION = 'trx_rme_invoice_corrections';

    public const ENTITY_DOCUMENT_VERSION = 'trx_rme_invoice_document_versions';

    public const ACTION_CORRECTION_SUBMITTED = 'RME_INVOICE_CORRECTION_SUBMITTED';

    public const ACTION_CORRECTION_APPROVED = 'RME_INVOICE_CORRECTION_APPROVED';

    public const ACTION_CORRECTION_REJECTED = 'RME_INVOICE_CORRECTION_REJECTED';

    public const ACTION_CORRECTION_CANCELLED = 'RME_INVOICE_CORRECTION_CANCELLED';

    public const ACTION_CORRECTION_STALE = 'RME_INVOICE_CORRECTION_STALE';

    public const ACTION_CORRECTION_APPLIED = 'RME_INVOICE_CORRECTION_APPLIED';

    public const ACTION_CORRECTION_REFUSED = 'RME_INVOICE_CORRECTION_REFUSED';

    public const ACTION_DOCUMENT_VERSION_ISSUED = 'RME_INVOICE_DOCUMENT_VERSION_ISSUED';

    /** status => audit action for a transition INTO that status. */
    public const CORRECTION_ACTIONS = [
        'SUBMITTED' => self::ACTION_CORRECTION_SUBMITTED,
        'APPROVED' => self::ACTION_CORRECTION_APPROVED,
        'REJECTED' => self::ACTION_CORRECTION_REJECTED,
        'CANCELLED' => self::ACTION_CORRECTION_CANCELLED,
        'STALE' => self::ACTION_CORRECTION_STALE,
        'APPLIED' => self::ACTION_CORRECTION_APPLIED,
    ];

    /**
     * Ids, numbers, amounts, statuses, counts and stable codes. The free-text
     * reason / decision note / cancel reason are represented by their LENGTH
     * only — a cashier may write a patient's name in them.
     *
     * @param  array<string, int|float|string|bool|null>  $extra
     * @return array<string, mixed>
     */
    public static function correctionPayload(RmeInvoiceCorrection $correction, ?string $fromStatus, array $extra = []): array
    {
        $allowedExtra = array_intersect_key($extra, array_flip([
            'code',
            'line_count',
            'invoice_version_before',
            'invoice_version_after',
            'grand_total_before',
            'grand_total_after',
            'note_length',
            'document_version_original_id',
            'document_version_revision_id',
            'latest_version_matched_live_state',
        ]));

        return array_merge([
            'correction_id' => (int) $correction->id,
            'correction_number' => $correction->correction_number,
            'correction_type' => (string) $correction->correction_type,
            'rme_invoice_id' => (int) $correction->rme_invoice_id,
            'branch_id' => (int) $correction->branch_id,
            'clinic_visit_id' => (int) $correction->clinic_visit_id,
            'patient_id' => (int) $correction->patient_id,
            'from_status' => $fromStatus,
            'to_status' => (string) $correction->status,
            'requested_by' => (int) $correction->requested_by,
            'original_grand_total' => self::money($correction->original_grand_total),
            'proposed_grand_total' => self::money($correction->proposed_grand_total),
            'difference_amount' => self::money($correction->difference_amount),
            'paid_total_at_request' => self::money($correction->paid_total_at_request),
            'reason_length' => mb_strlen((string) $correction->reason),
        ], $allowedExtra);
    }

    /**
     * @return array<string, mixed>
     */
    public static function documentVersionPayload(RmeInvoiceDocumentVersion $version): array
    {
        return [
            'document_version_id' => (int) $version->id,
            'rme_invoice_id' => (int) $version->rme_invoice_id,
            'branch_id' => (int) $version->branch_id,
            'version_number' => (int) $version->version_number,
            'version_kind' => (string) $version->version_kind,
            'document_reference' => (string) $version->document_reference,
            'source_correction_id' => $version->source_correction_id !== null ? (int) $version->source_correction_id : null,
            'capture_basis' => (string) $version->capture_basis,
            'snapshot_schema_version' => (int) $version->snapshot_schema_version,
            'snapshot_sha256' => (string) $version->snapshot_sha256,
        ];
    }

    private static function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
