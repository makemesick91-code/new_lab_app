<?php

namespace App\Modules\Treatment\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTreatmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'requires_doctor' => $this->boolean('requires_doctor'),
            'requires_room' => $this->boolean('requires_room'),
            'requires_lab' => $this->boolean('requires_lab'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $treatmentId = $this->route('treatment')?->id;

        return [
            'treatment_category_id' => ['required', 'integer', Rule::exists('mst_treatment_categories', 'id')],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('mst_treatments', 'code')->ignore($treatmentId),
            ],
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('mst_treatments', 'name')->ignore($treatmentId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'default_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'requires_doctor' => ['boolean'],
            'requires_room' => ['boolean'],
            'requires_lab' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
