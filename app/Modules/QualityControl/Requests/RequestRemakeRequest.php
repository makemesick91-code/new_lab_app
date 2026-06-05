<?php

namespace App\Modules\QualityControl\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestRemakeRequest extends FormRequest
{
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
            'reason' => ['required', 'string', 'min:5', 'max:100'],
            'notes' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan perbaikan wajib diisi.',
            'reason.min' => 'Alasan perbaikan minimal 5 karakter.',
            'notes.required' => 'Catatan perbaikan wajib diisi.',
        ];
    }
}
