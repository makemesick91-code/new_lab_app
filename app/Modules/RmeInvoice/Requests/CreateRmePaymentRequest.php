<?php

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeControlReceivableService;
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
            'consent_signed_by_patient' => ['required', 'accepted'],
            'consent_signed_by_doctor' => ['required', 'accepted'],
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

    public function messages(): array
    {
        return [
            'consent_signed_by_patient.accepted' => 'Pembayaran tidak dapat diproses karena Surat Persetujuan Tindakan belum dikonfirmasi ditandatangani oleh pasien dan dokter.',
            'consent_signed_by_doctor.accepted' => 'Pembayaran tidak dapat diproses karena Surat Persetujuan Tindakan belum dikonfirmasi ditandatangani oleh pasien dan dokter.',
            'consent_signed_by_patient.required' => 'Pembayaran tidak dapat diproses karena Surat Persetujuan Tindakan belum dikonfirmasi ditandatangani oleh pasien dan dokter.',
            'consent_signed_by_doctor.required' => 'Pembayaran tidak dapat diproses karena Surat Persetujuan Tindakan belum dikonfirmasi ditandatangani oleh pasien dan dokter.',
        ];
    }
}
