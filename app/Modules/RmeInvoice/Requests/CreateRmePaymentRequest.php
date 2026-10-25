<?php

namespace App\Modules\RmeInvoice\Requests;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Interfaces\RmeBillingDisputeRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceCorrectionRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePaymentSubmission;
use App\Modules\RmeInvoice\Services\RmeControlReceivableService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Services\RmePaymentSubmissionService;
use App\Modules\RmeInvoice\Support\RmeInvoiceBalanceConflict;
use App\Modules\RmeInvoice\Support\RmeInvoicePaymentBlocked;
use App\Modules\RmeInvoice\Support\RmeInvoicePaymentDisputed;
use App\Modules\RmeInvoice\Support\RmeInvoiceVersionConflict;
use App\Modules\RmeInvoice\Support\RmePaymentDeclaredView;
use App\Modules\RmeInvoice\Support\RmePaymentIntent;
use App\Modules\RmeInvoice\Support\RmePaymentIntentRefused;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateRmePaymentRequest extends FormRequest
{
    /**
     * FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1 (rule 205) — set when this request
     * repeats a submission that already committed (same intent, same account,
     * same payload). The controller then answers with the original result and the
     * payment service is never called.
     */
    private ?RmePaymentSubmission $replayed = null;

    public function replayedSubmission(): ?RmePaymentSubmission
    {
        return $this->replayed;
    }

    /**
     * ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-1 (rule 191) — authorize BEFORE
     * validation. The validation hooks below read the invoice's remaining balance
     * and correction version, so running them for a caller who may not pay this
     * invoice (another branch's cashier) would answer questions about an invoice
     * they cannot see. The controller keeps the same two checks as a second layer.
     */
    public function authorize(): bool
    {
        $invoice = $this->route('rmeInvoice');
        $visit = $this->route('clinicVisit');

        if (! $invoice instanceof RmeInvoice) {
            return true;
        }

        Gate::forUser($this->user())->authorize('pay', $invoice);

        abort_unless($visit instanceof ClinicVisit && $invoice->belongsToVisit($visit), 404);

        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method_id' => [
                'nullable',
                'integer',
                Rule::exists('mst_payment_methods', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['required', 'date'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'selected_receivable_ids' => ['nullable', 'array'],
            'selected_receivable_ids.*' => ['integer'],
            /*
             * HARDENING-RME-BILLING-DISPUTE-BRANCH-REVIEWER-CONTROL-PAYMENT-1
             * (owner OPS-OQ-1b, rule 204) — the EXPLICIT operator intent to pay
             * a Kontrol invoice on its own while its origin is disputed, plus the
             * operator's confirmation. Without both, a payment follows the
             * ordinary rules (origin first) exactly as before. The request carries
             * no origin id and no allocation: the service derives the chain.
             */
            'payment_scope' => ['nullable', 'string', Rule::in([RmePaymentService::PAYMENT_SCOPE_CONTROL_ONLY])],
            'confirm_control_only' => ['nullable', 'boolean'],
            /*
             * ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-1 (rule 191) — the
             * `correction_version` of every invoice the page displayed, keyed by
             * invoice id. REQUIRED: a form that does not say which version it
             * showed cannot be bound to one. The keys and values are checked
             * strictly in withValidator(); the authoritative comparison happens
             * under the invoice row lock in RmePaymentService::assertFormVersion().
             */
            'invoice_versions' => ['required', 'array'],
            /*
             * FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1 (rule 205) — REQUIRED.
             *  - payment_intent: the server-issued identity of this one payment
             *    form. The same form submitted again (double click, retry after a
             *    lost response) is answered with the original result, never
             *    recorded twice. A form without one (rendered before rule 205) is
             *    refused and the cashier reloads the page: nothing is recorded.
             *  - payment_plan + invoice_balances: what the page displayed. The
             *    payment settles exactly those balances, decided under the row
             *    locks, or nothing (finding F3).
             */
            RmePaymentIntent::FIELD => ['required', 'string', 'max:200'],
            RmePaymentDeclaredView::PLAN_FIELD => ['required', 'string', Rule::in(RmePaymentDeclaredView::PLANS)],
            RmePaymentDeclaredView::BALANCES_FIELD => ['required', 'array'],
            /*
             * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-03 — the
             * consent_signed_by_patient / consent_signed_by_doctor fields are
             * GONE from this request.
             *
             * Consent is no longer any part of payment eligibility, so the
             * cashier form no longer collects it and this request no longer
             * accepts it. Anything a client still submits under those names is
             * simply not validated data here and can never reach the payment
             * service. Nothing may add a consent rule back to this request.
             */
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $invoice = $this->route('rmeInvoice');
            $user = $this->user();

            if (! $invoice instanceof RmeInvoice || ! $user instanceof User) {
                return;
            }

            $submissions = app(RmePaymentSubmissionService::class);

            // Rule 205 — 1. the intent: a form this server issued for THIS invoice.
            try {
                $intent = $submissions->intentFor($invoice, $user, $this->all());
            } catch (RmePaymentIntentRefused $refused) {
                $validator->errors()->add(RmePaymentIntent::FIELD, $refused->operatorMessage());

                return;
            }

            // 2. A replay of a submission that already committed is answered with
            // its original result BEFORE any balance is re-validated: the original
            // payment is exactly what changed those balances.
            if ($this->resolveCommitted($submissions, $intent, $invoice, $user, $validator)) {
                return;
            }

            // 3. A new submission: every early (unlocked) check.
            $this->validatePayment($invoice, $validator);

            // 4. A concurrent duplicate may have committed between the lookup above
            // and a check that then failed BECAUSE of it (its payment changed the
            // balance). Ask once more before answering the cashier with an error for
            // a payment that is in fact recorded.
            if ($validator->errors()->isNotEmpty() && $submissions->findCommitted($intent['key']) !== null) {
                foreach ($validator->errors()->keys() as $key) {
                    $validator->errors()->forget($key);
                }

                $this->resolveCommitted($submissions, $intent, $invoice, $user, $validator);
            }
        });
    }

    /**
     * Rule 205 — true when this intent already committed something: an exact
     * repetition becomes a replay, anything else a REUSED refusal.
     *
     * @param  array{key: string, fingerprint: string}  $intent
     */
    private function resolveCommitted(RmePaymentSubmissionService $submissions, array $intent, RmeInvoice $invoice, User $user, Validator $validator): bool
    {
        try {
            $replay = $submissions->committedReplay($intent, $invoice, $user);
        } catch (RmePaymentIntentRefused $refused) {
            $submissions->recordIntentRefusal($refused, $this->declaredPath(), $user);
            $validator->errors()->add(RmePaymentIntent::FIELD, $refused->operatorMessage());

            return true;
        }

        if ($replay === null) {
            return false;
        }

        $submissions->recordReplay($replay, RmePaymentSubmissionService::SOURCE_FORM_REQUEST, $user);
        $this->replayed = $replay;

        return true;
    }

    /** The payment path this request declares, for audit labels only (never a decision). */
    private function declaredPath(): string
    {
        if ($this->input('payment_scope') === RmePaymentService::PAYMENT_SCOPE_CONTROL_ONLY) {
            return RmeInvoiceVersionConflict::PATH_CONTROL_ONLY;
        }

        return match ($this->input(RmePaymentDeclaredView::PLAN_FIELD)) {
            RmePaymentDeclaredView::PLAN_CONTROL => RmeInvoiceVersionConflict::PATH_CONTROL,
            RmePaymentDeclaredView::PLAN_RECEIVABLES => (array) $this->input('selected_receivable_ids', []) !== []
                ? RmeInvoiceVersionConflict::PATH_VISIT
                : RmeInvoiceVersionConflict::PATH_SINGLE,
            default => RmeInvoiceVersionConflict::PATH_SINGLE,
        };
    }

    /**
     * Rule 205 (F3), EARLY — the plan and balances the page displayed against the
     * current rows. Unlocked reads, never the authority: RmePaymentService decides
     * the same conflicts again under the row locks, through the SAME functions.
     *
     * @param  list<RmeInvoiceBalanceConflict|null>  $conflicts
     */
    private function declaredViewIsCurrent(array $conflicts, string $paymentPath, Validator $validator): bool
    {
        foreach ($conflicts as $conflict) {
            if ($conflict === null) {
                continue;
            }

            app(RmePaymentSubmissionService::class)->recordBalanceRefusal($conflict, $paymentPath, $this->user(), $this->route('rmeInvoice'));
            $validator->errors()->add(RmePaymentDeclaredView::BALANCES_FIELD, $conflict->operatorMessage());

            return false;
        }

        return true;
    }

    /**
     * @param  list<RmeInvoice>  $invoices
     * @return list<RmeInvoiceBalanceConflict|null>
     */
    private function balanceConflictsFor(array $invoices): array
    {
        return array_map(fn (RmeInvoice $invoice) => RmePaymentDeclaredView::balanceConflict($this->all(), $invoice), $invoices);
    }

    private function validatePayment(RmeInvoice $invoice, Validator $validator): void
    {
        if ($this->input('payment_scope') === RmePaymentService::PAYMENT_SCOPE_CONTROL_ONLY) {
            $this->validateControlOnly($invoice, $validator);

            return;
        }

        $carryOver = app(RmeControlReceivableService::class);

        if (! $carryOver->hasCarryOver($invoice)) {
            // Sprint 62.2 — ordinary visit may optionally collect selected
            // previous patient receivables; the cap accounts for them.
            $selectedIds = array_values(array_filter(
                array_map('intval', (array) $this->input('selected_receivable_ids', [])),
            ));

            if ($selectedIds === []) {
                if (! $this->invoiceVersionsAreCurrentFor([$invoice], RmeInvoiceVersionConflict::PATH_SINGLE, $validator)) {
                    return;
                }

                if (! $this->freeOfPaymentHolds([$invoice], RmeInvoiceVersionConflict::PATH_SINGLE, $validator)) {
                    return;
                }

                if (! $this->declaredViewIsCurrent([
                    RmePaymentDeclaredView::singlePlanConflict($this->all(), (int) $invoice->id),
                    ...$this->balanceConflictsFor([$invoice]),
                ], RmeInvoiceVersionConflict::PATH_SINGLE, $validator)) {
                    return;
                }

                $remaining = $invoice->remainingAmount();

                if ((float) $this->input('amount') > $remaining) {
                    $validator->errors()->add('amount', 'Pembayaran tidak boleh melebihi sisa tagihan.');
                }

                return;
            }

            $visitSummary = $carryOver->getVisitPayableSummary($invoice, $selectedIds);

            if (! $this->invoiceVersionsAreCurrentFor(
                [$invoice, ...$visitSummary['selected_receivables']->all()],
                RmeInvoiceVersionConflict::PATH_VISIT,
                $validator,
            )) {
                return;
            }

            if (! $this->freeOfPaymentHolds(
                [$invoice, ...$visitSummary['selected_receivables']->all()],
                RmeInvoiceVersionConflict::PATH_VISIT,
                $validator,
            )) {
                return;
            }

            if (! $this->declaredViewIsCurrent([
                RmePaymentDeclaredView::receivablesPlanConflict(
                    $this->all(),
                    (int) $invoice->id,
                    $visitSummary['selected_receivables']->map(fn (RmeInvoice $receivable) => (int) $receivable->id)->all(),
                ),
                ...$this->balanceConflictsFor([$invoice, ...$visitSummary['selected_receivables']->all()]),
            ], RmeInvoiceVersionConflict::PATH_VISIT, $validator)) {
                return;
            }

            if ((float) $this->input('amount') > $visitSummary['total_payable']) {
                $validator->errors()->add('amount', 'Pembayaran tidak boleh melebihi total yang harus dibayar.');
            }

            return;
        }

        $summary = $carryOver->getControlPayableSummary($invoice);

        if (! $this->invoiceVersionsAreCurrentFor(
            [$invoice, ...$summary['carry_over_invoices']->all()],
            RmeInvoiceVersionConflict::PATH_CONTROL,
            $validator,
        )) {
            return;
        }

        if (! $this->freeOfPaymentHolds(
            [$invoice, ...$summary['carry_over_invoices']->all()],
            RmeInvoiceVersionConflict::PATH_CONTROL,
            $validator,
        )) {
            return;
        }

        if (! $this->declaredViewIsCurrent([
            RmePaymentDeclaredView::controlPlanConflict(
                $this->all(),
                (int) $invoice->id,
                $summary['carry_over_invoices']->map(fn (RmeInvoice $origin) => (int) $origin->id)->all(),
            ),
            ...$this->balanceConflictsFor([$invoice, ...$summary['carry_over_invoices']->all()]),
        ], RmeInvoiceVersionConflict::PATH_CONTROL, $validator)) {
            return;
        }

        if ((float) $this->input('amount') > $summary['total_payable']) {
            $validator->errors()->add('amount', 'Pembayaran tidak boleh melebihi total yang harus dibayar.');
        }
    }

    /**
     * Rule 204 (OPS-OQ-1b), EARLY. Only the Kontrol invoice is settled on this
     * path, so only ITS version and ITS holds are checked here — the origin's
     * dispute is the very condition of the path, not a refusal. Whether every
     * outstanding origin is disputed is asked of the payment service's own rule;
     * the service decides all of it again under the locks.
     */
    private function validateControlOnly(RmeInvoice $invoice, Validator $validator): void
    {
        if (! $this->boolean('confirm_control_only')) {
            $validator->errors()->add(
                'confirm_control_only',
                'Centang konfirmasi bahwa pembayaran ini hanya untuk tagihan kontrol dan tagihan kunjungan asal tetap ditahan.',
            );

            return;
        }

        if (! $this->invoiceVersionsAreCurrentFor([$invoice], RmeInvoiceVersionConflict::PATH_CONTROL_ONLY, $validator)) {
            return;
        }

        if (! $this->freeOfPaymentHolds([$invoice], RmeInvoiceVersionConflict::PATH_CONTROL_ONLY, $validator)) {
            return;
        }

        if (! $this->declaredViewIsCurrent(
            [RmePaymentDeclaredView::controlOnlyPlanConflict($this->all(), (int) $invoice->id)],
            RmeInvoiceVersionConflict::PATH_CONTROL_ONLY,
            $validator,
        )) {
            return;
        }

        $eligibility = app(RmePaymentService::class)->controlOnlyEligibility($invoice);

        if (! $eligibility['eligible']) {
            $validator->errors()->add('payment_scope', (string) $eligibility['message']);

            return;
        }

        if (! $this->declaredViewIsCurrent($this->balanceConflictsFor([$invoice]), RmeInvoiceVersionConflict::PATH_CONTROL_ONLY, $validator)) {
            return;
        }

        if ((float) $this->input('amount') > $invoice->remainingAmount()) {
            $validator->errors()->add('amount', 'Pembayaran tidak boleh melebihi sisa tagihan kontrol.');
        }
    }

    /**
     * Rule 191 — shape of `invoice_versions`, then an EARLY comparison for every
     * invoice this payment would settle (the paid invoice, then the carry-over
     * parents or the selected receivables the amount cap is computed from).
     *
     * It runs BEFORE the amount cap on purpose: when a correction lowered a total,
     * the stale amount would otherwise be refused as "exceeds the remaining
     * balance", which sends the cashier to fix the wrong field. It is NOT the
     * authority — it reads without a lock, so the payment service compares again
     * under the invoice row lock. A refusal here is audited through the same
     * writer the service uses, because this is where the commonest refusal (a
     * page left open across a correction) is caught.
     *
     * @param  list<RmeInvoice>  $involved
     */
    private function invoiceVersionsAreCurrentFor(array $involved, string $paymentPath, Validator $validator): bool
    {
        $declared = $this->input('invoice_versions');

        if (! is_array($declared) || $declared === []) {
            return $this->refuseVersion($validator, null, $paymentPath);
        }

        foreach ($declared as $id => $version) {
            $idIsWhole = is_int($id) ? $id > 0 : (is_string($id) && ctype_digit($id) && (int) $id > 0);
            $versionIsWhole = is_int($version) ? $version >= 0 : (is_string($version) && ctype_digit($version));

            if (! $idIsWhole || ! $versionIsWhole) {
                return $this->refuseVersion($validator, null, $paymentPath);
            }
        }

        foreach ($involved as $invoice) {
            $id = (int) $invoice->id;
            $current = (int) ($invoice->correction_version ?? 0);

            if (! array_key_exists($id, $declared)) {
                return $this->refuseVersion(
                    $validator,
                    new RmeInvoiceVersionConflict(RmeInvoiceVersionConflict::MISSING, $id, null, $current),
                    $paymentPath,
                );
            }

            if ((int) $declared[$id] !== $current) {
                return $this->refuseVersion(
                    $validator,
                    new RmeInvoiceVersionConflict(RmeInvoiceVersionConflict::CHANGED, $id, (int) $declared[$id], $current),
                    $paymentPath,
                );
            }
        }

        return true;
    }

    /**
     * Every payment HOLD, early, in one place: D10 (active correction) first,
     * then OPS-OQ-1 (active billing dispute, rule 203). Unlocked reads, never the
     * authority — RmePaymentService::assertNoPaymentHold() decides both under the
     * invoice row lock.
     *
     * @param  list<RmeInvoice>  $involved
     */
    private function freeOfPaymentHolds(array $involved, string $paymentPath, Validator $validator): bool
    {
        return $this->freeOfActiveCorrection($involved, $paymentPath, $validator)
            && $this->freeOfActiveDispute($involved, $paymentPath, $validator);
    }

    /**
     * IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 (rule 203), EARLY.
     *
     * Refuses before the amount cap when any invoice this payment would settle is
     * under an ACTIVE billing dispute, so the cashier reads "the bill is under
     * review", not an amount error. Audited through the payment service's
     * single (de-duplicated) writer.
     *
     * @param  list<RmeInvoice>  $involved
     */
    private function freeOfActiveDispute(array $involved, string $paymentPath, Validator $validator): bool
    {
        $disputes = app(RmeBillingDisputeRepositoryInterface::class);

        foreach ($involved as $invoice) {
            $active = $disputes->activeForInvoice((int) $invoice->id);

            if ($active === null) {
                continue;
            }

            $disputed = new RmeInvoicePaymentDisputed(
                (int) $invoice->id,
                $invoice->invoice_number !== null ? (string) $invoice->invoice_number : null,
                (int) $active->id,
                (string) $active->status,
            );

            if ($this->user() instanceof User) {
                app(RmePaymentService::class)->recordDisputeHoldRefusal($disputed, $paymentPath, $this->user());
            }

            $validator->errors()->add(RmePaymentService::PAYMENT_BLOCKED_FIELD, $disputed->operatorMessage());

            return false;
        }

        return true;
    }

    /**
     * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — owner decision D10, EARLY.
     *
     * Refuses before the amount cap when any invoice this payment would settle
     * has an active (SUBMITTED / APPROVED) correction, so the cashier reads "the
     * bill is being corrected", not an amount error. Unlocked read, never the
     * authority: RmePaymentService::assertNoActiveCorrection() decides under the
     * invoice row lock. Audited through the same writer.
     *
     * @param  list<RmeInvoice>  $involved
     */
    private function freeOfActiveCorrection(array $involved, string $paymentPath, Validator $validator): bool
    {
        $corrections = app(RmeInvoiceCorrectionRepositoryInterface::class);

        foreach ($involved as $invoice) {
            $active = $corrections->activeForInvoice((int) $invoice->id);

            if ($active === null) {
                continue;
            }

            $blocked = new RmeInvoicePaymentBlocked(
                (int) $invoice->id,
                $invoice->invoice_number !== null ? (string) $invoice->invoice_number : null,
                (int) $active->id,
                (string) $active->status,
            );

            if ($this->user() instanceof User) {
                app(RmePaymentService::class)->recordCorrectionBlockRefusal($blocked, $paymentPath, $this->user());
            }

            $validator->errors()->add(RmePaymentService::PAYMENT_BLOCKED_FIELD, $blocked->operatorMessage());

            return false;
        }

        return true;
    }

    /**
     * A malformed declaration (no conflict object) names no invoice, so it is
     * refused without an audit row — there is no invoice to attach it to.
     */
    private function refuseVersion(Validator $validator, ?RmeInvoiceVersionConflict $conflict, string $paymentPath): bool
    {
        if ($conflict instanceof RmeInvoiceVersionConflict && $this->user() instanceof User) {
            app(RmePaymentService::class)->recordVersionRefusal($conflict, $paymentPath, $this->user());
        }

        $validator->errors()->add(
            'invoice_versions',
            RmeInvoiceVersionConflict::messageFor($conflict?->reasonCode ?? RmeInvoiceVersionConflict::MISSING),
        );

        return false;
    }

    public function messages(): array
    {
        return [
            'invoice_versions.required' => RmeInvoiceVersionConflict::messageFor(RmeInvoiceVersionConflict::MISSING),
            'invoice_versions.array' => RmeInvoiceVersionConflict::messageFor(RmeInvoiceVersionConflict::MISSING),
            RmePaymentIntent::FIELD.'.required' => RmePaymentIntentRefused::messageFor(RmePaymentIntentRefused::MISSING),
            RmePaymentIntent::FIELD.'.string' => RmePaymentIntentRefused::messageFor(RmePaymentIntentRefused::INVALID),
            RmePaymentIntent::FIELD.'.max' => RmePaymentIntentRefused::messageFor(RmePaymentIntentRefused::INVALID),
            RmePaymentDeclaredView::PLAN_FIELD.'.required' => RmeInvoiceBalanceConflict::messageFor(RmeInvoiceBalanceConflict::MISSING),
            RmePaymentDeclaredView::PLAN_FIELD.'.string' => RmeInvoiceBalanceConflict::messageFor(RmeInvoiceBalanceConflict::MISSING),
            RmePaymentDeclaredView::PLAN_FIELD.'.in' => RmeInvoiceBalanceConflict::messageFor(RmeInvoiceBalanceConflict::MISSING),
            RmePaymentDeclaredView::BALANCES_FIELD.'.required' => RmeInvoiceBalanceConflict::messageFor(RmeInvoiceBalanceConflict::MISSING),
            RmePaymentDeclaredView::BALANCES_FIELD.'.array' => RmeInvoiceBalanceConflict::messageFor(RmeInvoiceBalanceConflict::MISSING),
        ];
    }
}
