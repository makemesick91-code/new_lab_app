<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Branch\Services\BranchContext;
use Illuminate\Foundation\Http\FormRequest;

class StoreStockOpnameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = app(BranchContext::class)->requireId();

        return [
            'inventory_location_id' => ['required', 'integer', 'exists:inv_inventory_locations,id'],
            'opname_date' => ['required', 'date'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:inv_products,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
