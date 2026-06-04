<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockCardFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inventory_location_id' => ['nullable', 'integer', 'exists:inv_inventory_locations,id'],
            'movement_type' => ['nullable', 'string', Rule::in(InventoryMovement::TYPES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    public function filters(): array
    {
        return collect($this->validated())
            ->except('inventory_location_id')
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();
    }

    public function locationId(): ?int
    {
        $locationId = $this->validated('inventory_location_id');

        return $locationId ? (int) $locationId : null;
    }
}
