<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Branch\Services\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationProductMinimumRequest extends FormRequest
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
            'product_id' => [
                'required',
                'integer',
                'exists:inv_products,id',
                // Early duplicate feedback. Scoped to an ACTIVE threshold so the service
                // can still reactivate a previously deactivated (branch, location, product)
                // row instead of being blocked here. branch_id comes from BranchContext.
                Rule::unique('inv_location_product_minimums', 'product_id')
                    ->where('branch_id', $branchId)
                    ->where('inventory_location_id', $this->input('inventory_location_id'))
                    ->where('is_active', true),
            ],
            'minimum_stock' => ['required', 'numeric', 'gt:0'],
            'maximum_stock' => ['nullable', 'numeric', 'gte:minimum_stock'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.unique' => 'Konfigurasi stok minimum untuk lokasi dan produk ini sudah ada.',
            'minimum_stock.gt' => 'Stok minimum harus berupa angka lebih besar dari nol.',
            'maximum_stock.gte' => 'Stok maksimum tidak boleh kurang dari stok minimum.',
        ];
    }
}
