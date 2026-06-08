<?php

namespace App\Modules\Tariff\Requests;

use App\Modules\Branch\Services\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTariffRequest extends FormRequest
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
        $branchId = app(BranchContext::class)->requireId();
        $tariffId = $this->route('tariff')?->id;

        return [
            'treatment_id' => [
                'required',
                'integer',
                Rule::exists('mst_treatments', 'id'),
                Rule::unique('mst_tariffs', 'treatment_id')
                    ->where('branch_id', $branchId)
                    ->where('effective_date', $this->input('effective_date'))
                    ->ignore($tariffId),
            ],
            'price' => ['required', 'numeric', 'min:0'],
            'effective_date' => ['required', 'date'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
