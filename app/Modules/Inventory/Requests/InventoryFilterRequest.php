<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:150'],
            'product_category_id' => ['nullable', 'integer', 'exists:inv_product_categories,id'],
            'product_unit_id' => ['nullable', 'integer', 'exists:inv_product_units,id'],
            'inventory_location_id' => ['nullable', 'integer', 'exists:inv_inventory_locations,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:inv_suppliers,id'],
            'type' => ['nullable', 'string', Rule::in(InventoryLocation::TYPES)],
            'movement_type' => ['nullable', 'string', Rule::in(InventoryMovement::TYPES)],
            'is_active' => ['nullable', 'boolean'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
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

    public function perPage(int $default = 10): int
    {
        return (int) ($this->validated('per_page') ?: $default);
    }
}
