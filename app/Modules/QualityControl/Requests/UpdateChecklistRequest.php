<?php

namespace App\Modules\QualityControl\Requests;

use App\Modules\QualityControl\Models\QualityControlChecklist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChecklistRequest extends FormRequest
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
            'result' => ['required', Rule::in(QualityControlChecklist::RESULTS)],
            'notes' => [
                'nullable',
                'string',
                'max:1000',
                Rule::requiredIf(fn () => $this->input('result') === QualityControlChecklist::RESULT_FAIL),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'notes.required' => 'Catatan wajib diisi untuk item checklist yang FAIL.',
        ];
    }
}
