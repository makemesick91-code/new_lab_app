<?php

namespace App\Modules\LabOrder\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * LAB-WORKFLOW-V2 — mandatory lab -> courier handover proof (owner rule):
 * the handover photo AND the courier signature are both required in one
 * atomic action. Photo-only or signature-only submissions are rejected here
 * and re-rejected by the service + state machine.
 */
class SubmitDeliveryHandoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy + service ownership guards
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'handover_photo' => ['required', 'file', 'image', 'max:10240'],
            'courier_signature' => ['required', 'string', 'regex:/^data:image\/png;base64,/', 'max:500000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'handover_photo.required' => 'Foto serah terima model dari lab ke kurir wajib diunggah.',
            'courier_signature.required' => 'Tanda tangan kurir wajib diisi.',
            'courier_signature.regex' => 'Tanda tangan kurir harus berupa gambar PNG dari kanvas.',
        ];
    }
}
