<?php

namespace App\Modules\LabOrder\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * LAB-WORKFLOW-V2 — courier pickup confirmation (photo mandatory).
 */
class MarkPickupPickedUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware + service ownership guards
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pickup_photo' => ['required', 'file', 'image', 'max:10240'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pickup_photo.required' => 'Foto model saat pickup wajib diunggah.',
        ];
    }
}
