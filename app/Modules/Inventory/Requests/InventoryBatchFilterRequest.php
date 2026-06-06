<?php

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryBatchFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:150'],
            'product_id' => ['nullable', 'integer', 'exists:inv_products,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:inv_suppliers,id'],
            'expiry_status' => ['nullable', 'string', Rule::in(['expired', 'expiring_soon', 'valid'])],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function filters(): array
    {
        return collect($this->validated())
            ->except('per_page')
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();
    }

    public function perPage(int $default = 15): int
    {
        return (int) ($this->validated('per_page') ?: $default);
    }
}
