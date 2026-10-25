<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\RmeInvoice\Interfaces\RmeReceiptSnapshotRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Models\RmeReceiptSnapshot;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoiceDocument;
use App\Modules\RmeInvoice\Support\RmeInvoiceLedger;
use App\Modules\RmeInvoice\Support\RmeInvoiceSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — the ONE writer of receipt
 * snapshots (owner decision PP-5: D7 — "an issued document is never changed in
 * place" — extends to payment receipts).
 *
 * A receipt snapshot is the invoice document exactly as it stood when one payment
 * was recorded: the canonical invoice snapshot (RmeInvoiceSnapshot, schema 1) of
 * items and totals, restricted to the payments up to and including that payment,
 * with the status the ledger had at that moment, plus a `receipt` section naming
 * the payment. It is hashed with the same canonical encoding as frozen invoice
 * versions.
 *
 * TWO bases, never confused:
 *
 *   ISSUED_AT_PAYMENT — written inside the payment transaction, under the invoice
 *     row lock the payment already holds. This is the receipt the patient got.
 *
 *   RECONSTRUCTED_BEFORE_POSTPAYMENT_CORRECTION — a payment recorded before this
 *     release has no snapshot. The first post-payment correction of its invoice
 *     writes one, from the verified pre-correction state, BEFORE it mutates the
 *     invoice. That reconstruction is truthful only because, before this release,
 *     no code path could change a paid invoice's items, totals or correction
 *     version (a pre-payment correction refuses an invoice with any payment), so
 *     the state read now IS the state at each of those payments. It is labelled
 *     a reconstruction and never presented as an identical reprint of the paper.
 *
 * Both are written inside the caller's transaction; a rolled-back payment or
 * correction leaves no snapshot and no audit row.
 */
final class RmeReceiptSnapshotService
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly RmeReceiptSnapshotRepositoryInterface $snapshots,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * Freeze the receipt of a payment that has just been recorded. MUST run inside
     * the payment transaction, after the invoice status has been refreshed, on the
     * invoice row the payment locked.
     */
    public function issueForPayment(RmeInvoice $invoice, RmePayment $payment, User $issuer): RmeReceiptSnapshot
    {
        $this->assertInTransaction();

        return $this->write(
            $invoice,
            $payment,
            RmeReceiptSnapshot::BASIS_ISSUED_AT_PAYMENT,
            (int) $issuer->id,
            RmeFinancialAuditEvent::ACTION_RECEIPT_SNAPSHOT_ISSUED,
            $issuer,
        );
    }

    /**
     * Freeze a RECONSTRUCTED receipt for every payment of the invoice that has
     * none. MUST run inside the post-payment correction transaction, on the locked
     * invoice, BEFORE the correction mutates anything.
     *
     * @return int number of snapshots written
     */
    public function reconstructMissing(RmeInvoice $lockedInvoice, User $actor): int
    {
        $this->assertInTransaction();

        $written = 0;

        foreach ($this->snapshots->paymentIdsWithoutSnapshot((int) $lockedInvoice->id) as $paymentId) {
            $payment = RmePayment::query()->findOrFail($paymentId);

            $this->write(
                $lockedInvoice,
                $payment,
                RmeReceiptSnapshot::BASIS_RECONSTRUCTED,
                null,
                RmeFinancialAuditEvent::ACTION_RECEIPT_SNAPSHOT_RECONSTRUCTED,
                $actor,
            );

            $written++;
        }

        return $written;
    }

    /**
     * The receipt document for one payment, built from FRESH reads of the invoice:
     * every payment up to and including this one, the ledger status at that point,
     * and the receipt section.
     *
     * @return array<string, mixed>
     */
    public function build(RmeInvoice $invoice, RmePayment $payment): array
    {
        $document = RmeInvoiceSnapshot::capture($invoice);

        $payments = array_values(array_filter(
            $document['payments'],
            fn (array $row) => (int) $row['id'] <= (int) $payment->id,
        ));

        $paidCents = array_sum(array_column($payments, 'amount_cents'));
        $grandCents = (int) $document['totals']['grand_total_cents'];

        $document['payments'] = $payments;
        $document['totals']['paid_cents'] = $paidCents;
        // The status the ledger had right after this payment — the same helper the
        // payment path uses, so a reconstruction cannot disagree with an issuance.
        $document['invoice']['status'] = RmeInvoiceLedger::statusAfterMoneyReceived($grandCents, $paidCents);

        $document['receipt'] = [
            'receipt_schema_version' => self::SCHEMA_VERSION,
            'payment_id' => (int) $payment->id,
            'payment_number' => (string) $payment->payment_number,
            'amount_cents' => RmeInvoiceDocument::toCents($payment->amount),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'payment_method_id' => $payment->payment_method_id !== null ? (int) $payment->payment_method_id : null,
            'payment_batch_uuid' => $payment->payment_batch_uuid,
            'outstanding_cents' => RmeInvoiceLedger::outstandingCents($grandCents, $paidCents),
            // Display labels as printed, so a later rename of a payment method or
            // a cashier account never rewrites an issued receipt. (A reconstruction
            // freezes the labels of the moment it ran and is labelled as such.)
            // Patient identity is deliberately NOT frozen: it is personal data a
            // patient may have corrected, and it is not frozen in the invoice
            // document versions either (D7).
            'labels' => [
                'payment_methods' => $this->paymentMethodNames($payments),
                'cashier_name' => $payment->cashier()->value('name'),
            ],
        ];

        return $document;
    }

    /**
     * @param  array<int, array<string, mixed>>  $payments
     * @return array<string, string> payment method id (as string) => name
     */
    private function paymentMethodNames(array $payments): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (array $row) => isset($row['payment_method_id']) ? (int) $row['payment_method_id'] : null,
            $payments,
        ))));

        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach (PaymentMethod::query()->whereIn('id', $ids)->orderBy('id')->pluck('name', 'id') as $id => $name) {
            $names[(string) $id] = (string) $name;
        }

        return $names;
    }

    private function write(
        RmeInvoice $invoice,
        RmePayment $payment,
        string $basis,
        ?int $issuedBy,
        string $auditAction,
        User $auditActor,
    ): RmeReceiptSnapshot {
        if ((int) $payment->rme_invoice_id !== (int) $invoice->id) {
            throw new LogicException('A receipt snapshot must be written for a payment of the same invoice.');
        }

        if ($this->snapshots->findForPayment((int) $payment->id) !== null) {
            throw new LogicException(sprintf('Payment #%d already has a receipt snapshot; a receipt is issued once.', (int) $payment->id));
        }

        $document = $this->build($invoice, $payment);

        $snapshot = $this->snapshots->create([
            'uuid' => (string) Str::uuid(),
            'rme_payment_id' => (int) $payment->id,
            'rme_invoice_id' => (int) $invoice->id,
            'branch_id' => (int) $invoice->branch_id,
            'receipt_number' => (string) $payment->payment_number,
            'invoice_version' => (int) ($invoice->correction_version ?? 0),
            'invoice_status' => (string) $document['invoice']['status'],
            'capture_basis' => $basis,
            'snapshot_schema_version' => self::SCHEMA_VERSION,
            'snapshot' => $document,
            'snapshot_sha256' => RmeInvoiceSnapshot::documentHash($document),
            'issued_by' => $issuedBy,
            'issued_at' => now(),
        ]);

        $this->auditLogs->log(
            RmeFinancialAuditEvent::ENTITY_RECEIPT_SNAPSHOT,
            (int) $snapshot->id,
            $auditAction,
            null,
            RmeFinancialAuditEvent::receiptSnapshotPayload($snapshot),
            $auditActor,
        );

        return $snapshot;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Receipt snapshots are written only inside the transaction that records the fact they describe.');
        }
    }
}
