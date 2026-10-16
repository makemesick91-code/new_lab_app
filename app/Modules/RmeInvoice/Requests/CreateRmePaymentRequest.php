<?php

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeControlReceivableService;
use App\Modules\RmeInvoice\Support\RmeInvoiceVersionConflict;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateRmePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
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

            if (! $this->invoiceVersionsAreCurrentFor($invoice, $validator)) {
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
                    $remaining = $invoice->remainingAmount();

                    if ((float) $this->input('amount') > $remaining) {
                        $validator->errors()->add('amount', 'Pembayaran tidak boleh melebihi sisa tagihan.');
                    }

                    return;
                }

                $visitSummary = $carryOver->getVisitPayableSummary($invoice, $selectedIds);

                if ((float) $this->input('amount') > $visitSummary['total_payable']) {
                    $validator->errors()->add('amount', 'Pembayaran tidak boleh melebihi total yang harus dibayar.');
                }

                return;
            }

            $summary = $carryOver->getControlPayableSummary($invoice);

            if ((float) $this->input('amount') > $summary['total_payable']) {
                $validator->errors()->add('amount', 'Pembayaran tidak boleh melebihi total yang harus dibayar.');
            }
        });
    }

    /**
     * Rule 191 — shape of `invoice_versions`, then an EARLY comparison for the
     * invoice being paid. The early comparison exists for the operator: when a
     * correction lowered the total, the stale amount would otherwise be refused
     * as "exceeds the remaining balance", which sends the cashier to fix the
     * wrong field. It is NOT the authority — it reads without a lock, so the
     * payment service compares again under the invoice row lock.
     */
    private function invoiceVersionsAreCurrentFor(RmeInvoice $invoice, Validator $validator): bool
    {
        $declared = $this->input('invoice_versions');

        if (! is_array($declared) || $declared === []) {
            $validator->errors()->add('invoice_versions', RmeInvoiceVersionConflict::messageFor(RmeInvoiceVersionConflict::MISSING));

            return false;
        }

        foreach ($declared as $id => $version) {
            $idIsWhole = is_int($id) ? $id > 0 : (is_string($id) && ctype_digit($id) && (int) $id > 0);
            $versionIsWhole = is_int($version) ? $version >= 0 : (is_string($version) && ctype_digit($version));

            if (! $idIsWhole || ! $versionIsWhole) {
                $validator->errors()->add('invoice_versions', RmeInvoiceVersionConflict::messageFor(RmeInvoiceVersionConflict::MISSING));

                return false;
            }
        }

        if (! array_key_exists((int) $invoice->id, $declared)) {
            $validator->errors()->add('invoice_versions', RmeInvoiceVersionConflict::messageFor(RmeInvoiceVersionConflict::MISSING));

            return false;
        }

        $current = (int) (RmeInvoice::query()->whereKey($invoice->id)->value('correction_version') ?? 0);

        if ((int) $declared[(int) $invoice->id] !== $current) {
            $validator->errors()->add('invoice_versions', RmeInvoiceVersionConflict::messageFor(RmeInvoiceVersionConflict::CHANGED));

            return false;
        }

        return true;
    }

    public function messages(): array
    {
        return [
            'invoice_versions.required' => RmeInvoiceVersionConflict::messageFor(RmeInvoiceVersionConflict::MISSING),
            'invoice_versions.array' => RmeInvoiceVersionConflict::messageFor(RmeInvoiceVersionConflict::MISSING),
        ];
    }
}
