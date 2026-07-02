<?php

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStockOpnameItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'counted_quantity' => ['required', 'numeric', 'min:0'],
            'inventory_batch_id' => ['nullable', 'integer', 'exists:inv_inventory_batches,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
