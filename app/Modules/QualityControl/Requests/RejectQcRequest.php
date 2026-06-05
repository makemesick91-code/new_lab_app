<?php

namespace App\Modules\QualityControl\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RejectQcRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Default the result to REJECTED when not provided.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['result' => $this->input('result', 'REJECTED')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'result' => ['required', Rule::in(['REJECTED', 'REVISION'])],
            'reason' => ['required', 'string', 'max:100'],
            'notes' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan penolakan wajib diisi.',
            'notes.required' => 'Catatan penolakan wajib diisi.',
        ];
    }
}
