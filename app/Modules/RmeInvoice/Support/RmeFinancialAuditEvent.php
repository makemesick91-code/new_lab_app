<?php

namespace App\Modules\RmeInvoice\Support;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeInvoiceDocumentVersion;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Models\RmeOverpaymentObligation;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Models\RmePaymentSubmission;
use App\Modules\RmeInvoice\Models\RmeReceiptSnapshot;
use App\Modules\RmeInvoice\Models\RmeRefundRequest;
use App\Modules\RmeInvoice\Models\RmeRefundSettlement;

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

    /** FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — a refused attempt is attached to the visit (no invoice exists). */
    public const ENTITY_VISIT = 'trx_clinic_visits';

    /** A strict-mode line the tariff does not authorize. Written AFTER the rollback (an attempt). */
    public const ACTION_INVOICE_PRICING_REFUSED = 'RME_INVOICE_PRICING_REFUSED';

    /** D10 — a payment refused because the invoice has an active correction. Written AFTER the rollback. */
    public const ACTION_PAYMENT_REFUSED_ACTIVE_CORRECTION = 'RME_PAYMENT_REFUSED_ACTIVE_CORRECTION';

    /**
     * @return array<string, mixed>
     */
    public static function invoiceCreatedPayload(RmeInvoice $invoice, int $itemCount, ?array $pricing = null): array
    {
        return array_merge([
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
        ], $pricing === null ? [] : self::pricingSummary($pricing));
    }

    /**
     * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — how the invoice was priced: the
     * branch mode and how many lines fell into each provenance. For an
     * AUTHORIZED strict exception: line number, treatment, tariff vs billed
     * cents and the LENGTH of the reason — never the reason text, which a
     * cashier may have written a patient's name into.
     *
     * @param  array<string, mixed>  $pricing  RmeInvoicePricingService::price()
     * @return array<string, mixed>
     */
    private static function pricingSummary(array $pricing): array
    {
        $exceptions = [];
        foreach ($pricing['lines'] ?? [] as $index => $line) {
            if (($line['price_source'] ?? null) !== RmeInvoicePriceSource::TARIFF_EXCEPTION_AUTHORIZED) {
                continue;
            }
            $exceptions[] = [
                'line_no' => $index + 1,
                'treatment_id' => $line['treatment_id'],
                'tariff_unit_price_cents' => $line['tariff_unit_price_cents'],
                'unit_price_cents' => $line['unit_price_cents'],
                'discount_cents' => $line['discount_cents'],
                'reason_length' => mb_strlen((string) $line['price_exception_reason']),
                'authorized_by' => $line['price_exception_by'],
            ];
        }

        return array_merge($pricing['summary'] ?? [], ['price_exceptions' => $exceptions]);
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

    // ─── FEATURE-RME-RP0-CONTROL-CONSULTATION-1 (rule 217) ────────────────────
    //
    // "Selesai Tanpa Biaya": written inside the transaction that moves the
    // invoice to NO_CHARGE and the visit to completed, so a rolled-back closure
    // leaves no row. PII-free; it records that NO money moved.

    public const ACTION_INVOICE_ZERO_VALUE_CLOSED = 'RME_INVOICE_ZERO_VALUE_CLOSED';

    /**
     * FEATURE-RME-RP0-CONTROL-CONSULTATION-1 (rule 217). The eligibility inputs
     * are SNAPSHOTTED here — treatment and category codes, prices, the lab flag,
     * the visit type and its parent — because master data can be edited after
     * the closure and the audit row must still explain the decision. Codes and
     * ids only: free-text line descriptions may name a patient and are left out.
     *
     * @param  iterable<RmeInvoiceItem>  $items
     * @return array<string, mixed>
     */
    public static function zeroValueClosedPayload(RmeInvoice $invoice, iterable $items, ClinicVisit $visit, int $paymentCount): array
    {
        $lines = [];
        foreach ($items as $item) {
            $treatment = $item->treatment;
            $lines[] = [
                'rme_invoice_item_id' => (int) $item->id,
                'treatment_id' => $item->treatment_id !== null ? (int) $item->treatment_id : null,
                'treatment_code' => $treatment?->code !== null ? (string) $treatment->code : null,
                'category_code' => $treatment?->category?->code !== null ? (string) $treatment->category->code : null,
                'requires_lab' => $treatment !== null ? (bool) $treatment->requires_lab : null,
                'unit_price' => self::money($item->unit_price),
                'discount' => self::money($item->discount),
            ];
        }

        return [
            'rme_invoice_id' => (int) $invoice->id,
            'invoice_number' => $invoice->invoice_number !== null ? (string) $invoice->invoice_number : null,
            'branch_id' => (int) $invoice->branch_id,
            'clinic_visit_id' => (int) $invoice->clinic_visit_id,
            'patient_id' => (int) $invoice->patient_id,
            'status' => (string) $invoice->status,
            'zero_value_category' => (string) $invoice->zero_value_category,
            'zero_value_closed_by' => (int) $invoice->zero_value_closed_by,
            'zero_value_closed_at' => $invoice->zero_value_closed_at?->toIso8601String(),
            'grand_total' => self::money($invoice->grand_total),
            'item_count' => count($lines),
            'payment_count' => $paymentCount,
            'visit_type' => $visit->visit_type !== null ? (string) $visit->visit_type : null,
            'follow_up_of_visit_id' => $visit->follow_up_of_visit_id !== null ? (int) $visit->follow_up_of_visit_id : null,
            'parent_visit_status' => $visit->follow_up_of_visit_id !== null ? $visit->followUpOf?->status : null,
            'lines' => $lines,
            'visit_status_after' => ClinicVisit::STATUS_COMPLETED,
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
            // Rule 195 / D1a — how an application happened: AUTO_ON_APPROVAL
            // (the checker's approval applied it in the same transaction) or
            // MANUAL_RECOVERY (the legacy apply path for a pre-D1a APPROVED row).
            'application_mode',
            // FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — the ledger outcome of
            // a POST_PAYMENT correction, all server-derived.
            'invoice_status_before',
            'invoice_status_after',
            'paid_total',
            'outstanding_after',
            'overpaid_after',
            'overpayment_obligation_id',
            'receipt_snapshots_reconstructed',
            'owner_approval_reference',
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

    // ─── ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-1 (rule 191) ──────────
    //
    // A payment refused because the form did not carry the CURRENT version of
    // an invoice it would settle. Like ACTION_CORRECTION_REFUSED it records an
    // ATTEMPT, so it is written AFTER the payment transaction rolled back —
    // never inside it, where the rollback would erase it.

    public const ACTION_PAYMENT_REFUSED_INVOICE_VERSION = 'RME_PAYMENT_REFUSED_INVOICE_VERSION';

    /**
     * Ids, codes and version numbers only. Never the amount typed into the
     * stale form, a payment note or a reference number: the note may name a
     * patient and the reference may carry bank-account detail.
     *
     * @return array<string, mixed>
     */
    public static function paymentVersionRefusedPayload(RmeInvoice $invoice, RmeInvoiceVersionConflict $conflict, string $paymentPath): array
    {
        return [
            'invoice_id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'branch_id' => (int) $invoice->branch_id,
            'reason' => $conflict->reasonCode,
            'payment_path' => $paymentPath,
            'expected_version' => $conflict->expectedVersion,
            'current_version' => $conflict->currentVersion,
            'invoice_status' => (string) $invoice->status,
        ];
    }

    /**
     * Ids and codes only — never a price typed by the cashier or a description.
     *
     * @return array<string, mixed>
     */
    public static function invoicePricingRefusedPayload(ClinicVisit $visit, RmeInvoicePricingRefusal $refusal): array
    {
        return [
            'clinic_visit_id' => (int) $visit->id,
            'branch_id' => (int) $visit->branch_id,
            'patient_id' => (int) $visit->patient_id,
            'reason' => $refusal->refusalCode,
            'line_no' => $refusal->lineNo,
            'treatment_id' => $refusal->treatmentId,
        ];
    }

    /**
     * D10 — ids, codes and statuses only. Never the typed amount, a payment note
     * or a reference number.
     *
     * @return array<string, mixed>
     */
    public static function paymentBlockedByCorrectionPayload(RmeInvoice $invoice, RmeInvoicePaymentBlocked $blocked, string $paymentPath): array
    {
        return [
            'invoice_id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'branch_id' => (int) $invoice->branch_id,
            'reason' => RmeInvoicePaymentBlocked::REASON,
            'payment_path' => $paymentPath,
            'correction_id' => $blocked->correctionId,
            'correction_status' => $blocked->correctionStatus,
            'invoice_status' => (string) $invoice->status,
            'invoice_version' => (int) ($invoice->correction_version ?? 0),
        ];
    }

    // ─── IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 (rule 203) ──
    //
    // A payment refused because an invoice it would settle is under an ACTIVE
    // billing dispute (owner OPS-OQ-1: a known-wrong amount is not collected).
    // Written AFTER the rollback (an attempt), and de-duplicated per invoice and
    // cashier inside a short window so a repeated click cannot flood the trail.

    public const ACTION_PAYMENT_REFUSED_ACTIVE_DISPUTE = 'RME_PAYMENT_REFUSED_ACTIVE_DISPUTE';

    /**
     * Ids, codes and statuses only. Never the typed amount, a payment note, a
     * reference number or the dispute description.
     *
     * @return array<string, mixed>
     */
    public static function paymentBlockedByDisputePayload(RmeInvoice $invoice, RmeInvoicePaymentDisputed $disputed, string $paymentPath): array
    {
        return [
            'invoice_id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'branch_id' => (int) $invoice->branch_id,
            'reason' => RmeInvoicePaymentDisputed::REASON,
            'payment_path' => $paymentPath,
            'dispute_id' => $disputed->disputeId,
            'dispute_status' => $disputed->disputeStatus,
            'invoice_status' => (string) $invoice->status,
            'invoice_version' => (int) ($invoice->correction_version ?? 0),
        ];
    }

    // HARDENING-RME-BILLING-DISPUTE-BRANCH-REVIEWER-CONTROL-PAYMENT-1 (owner
    // OPS-OQ-1b, rule 204) — a Kontrol invoice was paid ON ITS OWN while every
    // outstanding carry-over origin invoice of its chain was under an active
    // billing dispute. Written inside the payment transaction, next to the
    // RME_PAYMENT_RECORDED row of the same payment. It records that the origin was
    // HELD (allocated 0), never an allocation to it: no payment row exists on the
    // origin and none is manufactured.

    public const ACTION_PAYMENT_CONTROL_ONLY_ORIGIN_DISPUTED = 'RME_PAYMENT_CONTROL_ONLY_ORIGIN_DISPUTED';

    /**
     * Ids, numbers and amounts only — never a patient field, a note or a
     * reference number.
     *
     * @param  list<int>  $heldOriginInvoiceIds
     * @param  list<int>  $heldDisputeIds
     * @return array<string, mixed>
     */
    public static function controlOnlyPaymentPayload(RmePayment $payment, RmeInvoice $controlInvoice, array $heldOriginInvoiceIds, array $heldDisputeIds): array
    {
        return [
            'payment_id' => (int) $payment->id,
            'payment_number' => (string) $payment->payment_number,
            'control_invoice_id' => (int) $controlInvoice->id,
            'control_invoice_number' => (string) $controlInvoice->invoice_number,
            'branch_id' => (int) $controlInvoice->branch_id,
            'payment_path' => RmeInvoiceVersionConflict::PATH_CONTROL_ONLY,
            'amount' => self::money($payment->amount),
            'allocated_to_control' => self::money($payment->amount),
            'allocated_to_origin' => 0.0,
            'held_origin_invoice_ids' => array_values(array_map('intval', $heldOriginInvoiceIds)),
            'held_dispute_ids' => array_values(array_map('intval', $heldDisputeIds)),
        ];
    }

    // ─── FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1 (rule 205) ─────────────────
    //
    // A payment submission repeated with the same intent (double click, retry
    // after a lost response, resubmission after "back") is answered with the
    // ORIGINAL result and records nothing new; a submission refused because its
    // intent was already used for something else, or because the balances it
    // displayed are no longer current, is refused whole. All three are ATTEMPTS:
    // written after the payment transaction (never inside it, where a rollback
    // would erase them) and de-duplicated per invoice, cashier and action inside a
    // short window, so repeated clicks cannot flood the trail.

    public const ACTION_PAYMENT_SUBMISSION_REPLAYED = 'RME_PAYMENT_SUBMISSION_REPLAYED';

    public const ACTION_PAYMENT_REFUSED_INTENT_REUSED = 'RME_PAYMENT_REFUSED_INTENT_REUSED';

    public const ACTION_PAYMENT_REFUSED_STALE_BALANCE = 'RME_PAYMENT_REFUSED_STALE_BALANCE';

    /**
     * Ids, numbers and the stage that caught the replay only — never the intent
     * token itself, a note or a reference number.
     *
     * @return array<string, mixed>
     */
    public static function paymentSubmissionReplayedPayload(RmeInvoice $invoice, RmePaymentSubmission $submission, string $source): array
    {
        return [
            'invoice_id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'branch_id' => (int) $invoice->branch_id,
            'submission_id' => (int) $submission->id,
            'payment_path' => (string) $submission->payment_path,
            'payment_id' => (int) $submission->rme_payment_id,
            'payment_batch_uuid' => $submission->payment_batch_uuid,
            'payment_count' => (int) $submission->payment_count,
            'detected_by' => $source,
        ];
    }

    /**
     * Ids and codes only. The submission it collided with is named by id; its
     * payload, amount and account are not repeated here.
     *
     * @return array<string, mixed>
     */
    public static function paymentIntentReusedPayload(RmeInvoice $invoice, RmePaymentIntentRefused $refused, string $paymentPath): array
    {
        return [
            'invoice_id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'branch_id' => (int) $invoice->branch_id,
            'reason' => $refused->reasonCode,
            'payment_path' => $paymentPath,
            'submission_id' => $refused->submissionId,
        ];
    }

    /**
     * The balance the page displayed and the balance found under the lock — both
     * server-computed figures, never the amount the cashier typed, a note or a
     * reference number.
     *
     * @return array<string, mixed>
     */
    public static function paymentStaleBalancePayload(RmeInvoice $invoice, RmeInvoiceBalanceConflict $conflict, string $paymentPath): array
    {
        return [
            'invoice_id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'branch_id' => (int) $invoice->branch_id,
            'reason' => $conflict->reasonCode,
            'payment_path' => $paymentPath,
            'declared_remaining' => $conflict->declaredCents !== null ? round($conflict->declaredCents / 100, 2) : null,
            'current_remaining' => $conflict->currentCents !== null ? round($conflict->currentCents / 100, 2) : null,
            'invoice_status' => (string) $invoice->status,
        ];
    }

    // ─── FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 ────────────────────────
    //
    // Receipt snapshots (PP-5) and overpayment obligations (PP-3 A). Written
    // inside the transaction that writes the fact; PII-free payloads.

    public const ENTITY_RECEIPT_SNAPSHOT = 'trx_rme_receipt_snapshots';

    public const ENTITY_OVERPAYMENT_OBLIGATION = 'trx_rme_overpayment_obligations';

    /** A receipt frozen in the payment transaction that issued it. */
    public const ACTION_RECEIPT_SNAPSHOT_ISSUED = 'RME_RECEIPT_SNAPSHOT_ISSUED';

    /** A receipt of a pre-release payment frozen by the first post-payment correction, before it mutated the invoice. */
    public const ACTION_RECEIPT_SNAPSHOT_RECONSTRUCTED = 'RME_RECEIPT_SNAPSHOT_RECONSTRUCTED';

    /** An OPEN obligation recorded because a correction brought the total below the money received. */
    public const ACTION_OVERPAYMENT_OBLIGATION_OPENED = 'RME_OVERPAYMENT_OBLIGATION_OPENED';

    /** FEATURE-RME-REFUND-SETTLEMENT-1 (RFS-4): the obligation was paid back (OPEN → SETTLED). */
    public const ACTION_OVERPAYMENT_OBLIGATION_SETTLED = 'RME_OVERPAYMENT_OBLIGATION_SETTLED';

    /**
     * @return array<string, mixed>
     */
    public static function receiptSnapshotPayload(RmeReceiptSnapshot $snapshot): array
    {
        return [
            'receipt_snapshot_id' => (int) $snapshot->id,
            'rme_payment_id' => (int) $snapshot->rme_payment_id,
            'rme_invoice_id' => (int) $snapshot->rme_invoice_id,
            'branch_id' => (int) $snapshot->branch_id,
            'receipt_number' => (string) $snapshot->receipt_number,
            'invoice_version' => (int) $snapshot->invoice_version,
            'invoice_status' => (string) $snapshot->invoice_status,
            'capture_basis' => (string) $snapshot->capture_basis,
            'snapshot_schema_version' => (int) $snapshot->snapshot_schema_version,
            'snapshot_sha256' => (string) $snapshot->snapshot_sha256,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function overpaymentObligationPayload(RmeOverpaymentObligation $obligation): array
    {
        return [
            'overpayment_obligation_id' => (int) $obligation->id,
            'obligation_number' => $obligation->obligation_number,
            'rme_invoice_id' => (int) $obligation->rme_invoice_id,
            'branch_id' => (int) $obligation->branch_id,
            'clinic_visit_id' => (int) $obligation->clinic_visit_id,
            'patient_id' => (int) $obligation->patient_id,
            'source_correction_id' => (int) $obligation->source_correction_id,
            'amount' => self::money($obligation->amount),
            'invoice_version' => (int) $obligation->invoice_version,
            'status' => (string) $obligation->status,
        ];
    }

    // FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215) — a refund REQUEST and
    // its decision. No action here records money leaving the clinic.
    public const ENTITY_REFUND_REQUEST = 'trx_rme_refund_requests';

    public const ACTION_REFUND_REQUEST_SUBMITTED = 'RME_REFUND_REQUEST_SUBMITTED';

    public const ACTION_REFUND_REQUEST_APPROVED = 'RME_REFUND_REQUEST_APPROVED';

    public const ACTION_REFUND_REQUEST_REJECTED = 'RME_REFUND_REQUEST_REJECTED';

    public const ACTION_REFUND_REQUEST_CANCELLED = 'RME_REFUND_REQUEST_CANCELLED';

    public const ACTION_REFUND_REQUEST_STALE = 'RME_REFUND_REQUEST_STALE';

    public const ACTION_REFUND_REQUEST_REFUSED = 'RME_REFUND_REQUEST_REFUSED';

    /** FEATURE-RME-REFUND-SETTLEMENT-1 — written only by the settlement transaction. */
    public const ACTION_REFUND_REQUEST_SETTLED = 'RME_REFUND_REQUEST_SETTLED';

    /** @var array<string, string> status reached => audit action */
    public const REFUND_REQUEST_ACTIONS = [
        'REQUESTED' => self::ACTION_REFUND_REQUEST_SUBMITTED,
        'APPROVED' => self::ACTION_REFUND_REQUEST_APPROVED,
        'REJECTED' => self::ACTION_REFUND_REQUEST_REJECTED,
        'CANCELLED' => self::ACTION_REFUND_REQUEST_CANCELLED,
        'STALE' => self::ACTION_REFUND_REQUEST_STALE,
        'SETTLED' => self::ACTION_REFUND_REQUEST_SETTLED,
    ];

    /**
     * Ids, cents and codes only: never a name, NIK, RM number, note, patient
     * reference or Owner reference text (TDD RR-PRIV-01).
     *
     * @param  array<string, int|string|null>  $extra
     * @return array<string, mixed>
     */
    public static function refundRequestPayload(RmeRefundRequest $request, ?string $fromStatus, array $extra = []): array
    {
        return array_merge([
            'refund_request_id' => (int) $request->id,
            'request_number' => $request->request_number,
            'rme_invoice_id' => (int) $request->rme_invoice_id,
            'overpayment_obligation_id' => (int) $request->rme_overpayment_obligation_id,
            'branch_id' => (int) $request->branch_id,
            'amount_cents' => RmeInvoiceDocument::toCents($request->amount),
            'invoice_version' => (int) $request->invoice_version,
            'from_status' => $fromStatus,
            'to_status' => (string) $request->status,
        ], $extra);
    }

    // FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216) — the record that money owed back
    // actually left the clinic. Payloads are ids, cents, codes and the evidence hash:
    // never the bank reference, bank name, account digits, recipient, note or reason
    // text, nor any patient identity.
    public const ENTITY_REFUND_SETTLEMENT = 'trx_rme_refund_settlements';

    public const ACTION_REFUND_SETTLEMENT_STARTED = 'RME_REFUND_SETTLEMENT_STARTED';

    public const ACTION_REFUND_SETTLEMENT_OUTCOME_UNKNOWN = 'RME_REFUND_SETTLEMENT_OUTCOME_UNKNOWN';

    public const ACTION_REFUND_SETTLEMENT_SETTLED = 'RME_REFUND_SETTLEMENT_SETTLED';

    public const ACTION_REFUND_SETTLEMENT_ABORTED = 'RME_REFUND_SETTLEMENT_ABORTED';

    public const ACTION_REFUND_SETTLEMENT_REFUSED = 'RME_REFUND_SETTLEMENT_REFUSED';

    /** Opening a private disbursement proof is itself audited. */
    public const ACTION_REFUND_SETTLEMENT_EVIDENCE_VIEWED = 'RME_REFUND_SETTLEMENT_EVIDENCE_VIEWED';

    /** @var array<string, string> status reached => audit action */
    public const REFUND_SETTLEMENT_ACTIONS = [
        'DISBURSING' => self::ACTION_REFUND_SETTLEMENT_STARTED,
        'OUTCOME_UNKNOWN' => self::ACTION_REFUND_SETTLEMENT_OUTCOME_UNKNOWN,
        'SETTLED' => self::ACTION_REFUND_SETTLEMENT_SETTLED,
        'ABORTED' => self::ACTION_REFUND_SETTLEMENT_ABORTED,
    ];

    /**
     * @param  array<string, int|string|null>  $extra
     * @return array<string, mixed>
     */
    public static function refundSettlementPayload(RmeRefundSettlement $settlement, ?string $fromStatus, array $extra = []): array
    {
        return array_merge([
            'refund_settlement_id' => (int) $settlement->id,
            'settlement_number' => $settlement->settlement_number,
            'refund_request_id' => (int) $settlement->rme_refund_request_id,
            'overpayment_obligation_id' => (int) $settlement->rme_overpayment_obligation_id,
            'rme_invoice_id' => (int) $settlement->rme_invoice_id,
            'branch_id' => (int) $settlement->branch_id,
            'amount_cents' => RmeInvoiceDocument::toCents($settlement->amount),
            'method' => (string) $settlement->method,
            'approved_by' => (int) $settlement->approved_by,
            'from_status' => $fromStatus,
            'to_status' => (string) $settlement->status,
            'evidence_sha256' => $settlement->evidence_sha256,
        ], $extra);
    }

    private static function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
