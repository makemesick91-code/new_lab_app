<?php

namespace App\Modules\RmeInvoice\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'consent_signed_by_patient' => ['required', 'accepted'],
            'consent_signed_by_doctor' => ['required', 'accepted'],
        ];
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
