<?php

namespace App\Modules\LabOrder\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * LAB-WORKFLOW-V2 — mandatory delivery completion proof (owner rule):
 * recipient signature + recipient name + location/proof photo, all required.
 */
class CompleteDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy + service ownership guards
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'recipient_name' => ['required', 'string', 'max:150'],
            'recipient_role' => ['nullable', 'string', 'max:100'],
            'recipient_signature' => ['required', 'string', 'regex:/^data:image\/png;base64,/', 'max:500000'],
            'location_photo' => ['required', 'file', 'image', 'max:10240'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'recipient_name.required' => 'Nama penerima wajib diisi.',
            'recipient_signature.required' => 'Tanda tangan penerima wajib diisi.',
            'location_photo.required' => 'Foto lokasi/bukti serah terima wajib diunggah.',
        ];
    }
}
