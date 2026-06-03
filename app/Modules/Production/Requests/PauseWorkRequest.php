<?php

namespace App\Modules\Production\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PauseWorkRequest extends FormRequest
{
    /** Hold reasons (production_workflow_design §8). */
    public const HOLD_REASONS = [
        'WAITING_MATERIAL',
        'WAITING_APPROVAL',
        'DOCTOR_CONFIRMATION',
        'EQUIPMENT_ISSUE',
        'OTHER',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'hold_reason' => ['nullable', Rule::in(self::HOLD_REASONS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan pause wajib diisi.',
            'reason.min' => 'Alasan pause minimal 5 karakter.',
        ];
    }
}
