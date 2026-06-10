<?php

namespace App\Modules\Odontogram\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOdontogramPlaceholderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'summary_notes' => ['nullable', 'string', 'max:5000'],
            'tooth_map_payload' => ['nullable', 'array'],
        ];
    }
}
