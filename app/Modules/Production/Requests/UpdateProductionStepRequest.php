<?php

namespace App\Modules\Production\Requests;

use App\Modules\Production\Models\ProductionStep;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductionStepRequest extends FormRequest
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
            'status' => ['required', Rule::in(ProductionStep::STATUSES)],
            'notes' => [
                'nullable',
                'string',
                'max:1000',
                Rule::requiredIf(fn () => in_array(
                    $this->input('status'),
                    [ProductionStep::STATUS_SKIPPED, ProductionStep::STATUS_ON_HOLD],
                    true,
                )),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'notes.required' => 'Catatan wajib diisi untuk step yang di-skip atau on hold.',
        ];
    }
}
