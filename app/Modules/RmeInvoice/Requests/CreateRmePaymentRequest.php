<?php

namespace App\Modules\RmeInvoice\Requests;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeControlReceivableService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeInvoiceVersionConflict;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateRmePaymentRequest extends FormRequest
{
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
             * ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-1 (rule 191) — the
             * `correction_version` of every invoice the page displayed, keyed by
             * invoice id. REQUIRED: a form that does not say which version it
             * showed cannot be bound to one. The keys and values are checked
             * strictly in withValidator(); the authoritative comparison happens
             * under the invoice row lock in RmePaymentService::assertFormVersion().
             */
            'invoice_versions' => ['required', 'array'],
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

            if (! $invoice instanceof RmeInvoice) {
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

            if ((float) $this->input('amount') > $summary['total_payable']) {
                $validator->errors()->add('amount', 'Pembayaran tidak boleh melebihi total yang harus dibayar.');
            }
        });
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
        ];
    }
}
