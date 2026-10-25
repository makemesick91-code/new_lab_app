<?php

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\RmeInvoice\Interfaces\RmePaymentSubmissionRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Models\RmePaymentSubmission;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoiceBalanceConflict;
use App\Modules\RmeInvoice\Support\RmePaymentIntent;
use App\Modules\RmeInvoice\Support\RmePaymentIntentRefused;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1 (rule 205) — one logical payment
 * submission, at most one committed payment effect.
 *
 * THE CONTRACT
 *  - A payment form carries a server-issued intent (RmePaymentIntent). Without a
 *    valid one for the invoice being paid, nothing is recorded (fail closed).
 *  - A COMMITTED submission is a row of trx_rme_payment_submissions, written by
 *    RmePaymentService inside the payment transaction, after the payment rows.
 *    No row exists for an intent whose payment rolled back.
 *  - The same intent, the same account, the same payload again is a REPLAY: the
 *    original result is returned and nothing new is written. Anything else under
 *    an already-committed intent is REUSED and refused.
 *  - Replay detection happens UNDER the invoice row lock (authoritative), after
 *    the cashier's authority is re-checked: an intent never outlives the
 *    authority of the account that used it, and is never answered for another
 *    account or another invoice.
 *
 * Holds no locks itself and never decides money: RmePaymentService calls it
 * inside its own transaction, the payment FormRequest calls it early (unlocked)
 * only to answer an obvious replay before re-validating balances that the
 * original payment has already changed.
 */
class RmePaymentSubmissionService
{
    /** Replays / refusals of the same invoice by the same cashier inside this window are audited once. */
    private const AUDIT_WINDOW_MINUTES = 15;

    public const SOURCE_FORM_REQUEST = 'form_request';

    public const SOURCE_SERVICE_LOCK = 'service_lock';

    public const SOURCE_UNIQUE_INDEX = 'unique_index';

    public function __construct(
        private readonly RmePaymentSubmissionRepositoryInterface $submissions,
        private readonly AuditLogService $auditLogs,
    ) {}

    /** A fresh intent token for one payment form of this invoice. */
    public function issueIntent(RmeInvoice $invoice): string
    {
        return RmePaymentIntent::issue((int) $invoice->id);
    }

    /**
     * The intent of a submission: its key and the fingerprint of its payload.
     *
     * @param  array<string, mixed>  $data  the RAW submitted data (before normalization)
     * @return array{key: string, fingerprint: string}
     *
     * @throws RmePaymentIntentRefused MISSING or INVALID
     */
    public function intentFor(RmeInvoice $routeInvoice, User $cashier, array $data): array
    {
        $token = $data[RmePaymentIntent::FIELD] ?? null;

        if ($token === null || $token === '') {
            throw new RmePaymentIntentRefused(RmePaymentIntentRefused::MISSING, null, (int) $routeInvoice->id);
        }

        $key = RmePaymentIntent::keyFrom($token, (int) $routeInvoice->id);

        if ($key === null) {
            throw new RmePaymentIntentRefused(RmePaymentIntentRefused::INVALID, null, (int) $routeInvoice->id);
        }

        return [
            'key' => $key,
            'fingerprint' => RmePaymentIntent::fingerprint((int) $routeInvoice->id, (int) $cashier->id, $data),
        ];
    }

    /**
     * The committed submission of this intent when this is an exact repetition of
     * it; null when the intent has not committed anything.
     *
     * @param  array{key: string, fingerprint: string}  $intent
     *
     * @throws RmePaymentIntentRefused REUSED — the intent recorded something else
     */
    public function committedReplay(array $intent, RmeInvoice $routeInvoice, User $cashier): ?RmePaymentSubmission
    {
        $existing = $this->submissions->findByIntentKey($intent['key']);

        if ($existing === null) {
            return null;
        }

        $same = (int) $existing->cashier_id === (int) $cashier->id
            && (int) $existing->rme_invoice_id === (int) $routeInvoice->id
            && hash_equals((string) $existing->request_fingerprint, $intent['fingerprint']);

        if (! $same) {
            throw new RmePaymentIntentRefused(RmePaymentIntentRefused::REUSED, (int) $existing->id, (int) $routeInvoice->id);
        }

        return $existing;
    }

    /**
     * Records the committed submission. MUST be called inside the payment
     * transaction, after the payment rows it names were written, so the two
     * commit — or roll back — together.
     *
     * @param  array{key: string, fingerprint: string}  $intent
     */
    public function record(
        array $intent,
        RmeInvoice $routeInvoice,
        User $cashier,
        string $paymentPath,
        float $amount,
        RmePayment $primaryPayment,
        ?string $paymentBatchUuid,
        int $paymentCount,
    ): RmePaymentSubmission {
        return $this->submissions->create([
            'intent_key' => $intent['key'],
            'rme_invoice_id' => (int) $routeInvoice->id,
            'branch_id' => (int) $routeInvoice->branch_id,
            'cashier_id' => (int) $cashier->id,
            'payment_path' => $paymentPath,
            'request_fingerprint' => $intent['fingerprint'],
            'amount' => round($amount, 2),
            'rme_payment_id' => (int) $primaryPayment->id,
            'payment_batch_uuid' => $paymentBatchUuid,
            'payment_count' => $paymentCount,
            'committed_at' => now(),
        ]);
    }

    /** Whether this intent has committed a submission (read-only, unlocked). */
    public function findCommitted(string $intentKey): ?RmePaymentSubmission
    {
        return $this->submissions->findByIntentKey($intentKey);
    }

    // ─── Audit (attempts; written at transaction level 0, de-duplicated) ──────

    public function recordReplay(RmePaymentSubmission $submission, string $source, User $cashier): void
    {
        $invoice = RmeInvoice::query()->find((int) $submission->rme_invoice_id);

        if ($invoice instanceof RmeInvoice) {
            $this->recordOnce(
                RmeFinancialAuditEvent::ACTION_PAYMENT_SUBMISSION_REPLAYED,
                $invoice,
                RmeFinancialAuditEvent::paymentSubmissionReplayedPayload($invoice, $submission, $source),
                $cashier,
            );
        }
    }

    /** Only a REUSED intent is evidence worth keeping; a missing or forged one names no submission. */
    public function recordIntentRefusal(RmePaymentIntentRefused $refused, string $paymentPath, User $cashier): void
    {
        if ($refused->reasonCode !== RmePaymentIntentRefused::REUSED || $refused->invoiceId === null) {
            return;
        }

        $invoice = RmeInvoice::query()->find($refused->invoiceId);

        if ($invoice instanceof RmeInvoice) {
            $this->recordOnce(
                RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_INTENT_REUSED,
                $invoice,
                RmeFinancialAuditEvent::paymentIntentReusedPayload($invoice, $refused, $paymentPath),
                $cashier,
            );
        }
    }

    /**
     * Audits a stale-view refusal. The conflict may name an invoice id the CLIENT
     * supplied (a ticked receivable the page claims to have shown), so it is never
     * looked up on its own: the row lands on that invoice only when it is the
     * route invoice or one of the SAME patient at the SAME branch — the only
     * invoices the payment page can display. Anything else is audited on the
     * route invoice, without the foreign id, so a crafted request can never write
     * into another patient's or another branch's audit trail (security review L1).
     */
    public function recordBalanceRefusal(RmeInvoiceBalanceConflict $conflict, string $paymentPath, User $cashier, RmeInvoice $routeInvoice): void
    {
        $invoice = $routeInvoice;

        if ($conflict->invoiceId !== null && $conflict->invoiceId !== (int) $routeInvoice->id) {
            $shown = RmeInvoice::query()
                ->whereKey($conflict->invoiceId)
                ->where('patient_id', $routeInvoice->patient_id)
                ->where('branch_id', $routeInvoice->branch_id)
                ->first();

            if ($shown instanceof RmeInvoice) {
                $invoice = $shown;
            } else {
                $conflict = new RmeInvoiceBalanceConflict($conflict->reasonCode, (int) $routeInvoice->id);
            }
        }

        $this->recordOnce(
            RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_STALE_BALANCE,
            $invoice,
            RmeFinancialAuditEvent::paymentStaleBalancePayload($invoice, $conflict, $paymentPath),
            $cashier,
        );
    }

    /**
     * One audit row per (invoice, cashier, action) inside the window. An audit
     * failure is logged (ids and codes only) and never turns a refusal or a
     * replay into a 500 or a payment.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordOnce(string $action, RmeInvoice $invoice, array $payload, User $cashier): void
    {
        try {
            $alreadyRecorded = DB::table('sys_audit_logs')
                ->where('entity_type', RmeFinancialAuditEvent::ENTITY_INVOICE)
                ->where('entity_id', (int) $invoice->id)
                ->where('action', $action)
                ->where('performed_by', (int) $cashier->id)
                ->where('performed_at', '>=', now()->subMinutes(self::AUDIT_WINDOW_MINUTES))
                ->exists();

            if ($alreadyRecorded) {
                return;
            }

            $this->auditLogs->log(RmeFinancialAuditEvent::ENTITY_INVOICE, (int) $invoice->id, $action, null, $payload, $cashier);
        } catch (\Throwable $e) {
            Log::error('RME payment submission event could not be audited.', [
                'invoice_id' => (int) $invoice->id,
                'action' => $action,
                'exception' => $e::class,
            ]);
        }
    }
}
