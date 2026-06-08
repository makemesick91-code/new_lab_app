<?php

namespace App\Modules\TreatmentCategory\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTreatmentCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $categoryId = $this->route('treatmentCategory')?->id;

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('mst_treatment_categories', 'code')->ignore($categoryId),
            ],
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('mst_treatment_categories', 'name')->ignore($categoryId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
