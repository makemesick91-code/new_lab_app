<?php

namespace App\Modules\Prescription\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FIX-02 — sending is an explicit operator action. Resending an already
 * delivered prescription must be asked for deliberately.
 */
class SendPrescriptionWhatsAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'confirm' => ['accepted'],
            'resend' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirm.accepted' => 'Konfirmasi pengiriman resep ke WhatsApp pasien terlebih dahulu.',
        ];
    }
}
