<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Branch\Services\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocationProductMinimumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = app(BranchContext::class)->requireId();
        $minimum = $this->route('locationProductMinimum');

        // Effective location for the duplicate check: incoming value, otherwise the
        // current record's location (covers partial updates that omit the field).
        $locationId = $this->input('inventory_location_id', $minimum?->inventory_location_id);

        // Only enforce the cross-field threshold rule when minimum_stock is being sent;
        // the service re-checks against the stored minimum for partial updates.
        $maximumRules = ['nullable', 'numeric'];
        if ($this->has('minimum_stock')) {
            $maximumRules[] = 'gte:minimum_stock';
        }

        return [
            'inventory_location_id' => ['sometimes', 'required', 'integer', 'exists:inv_inventory_locations,id'],
            'product_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:inv_products,id',
                // Duplicate feedback ignoring the current record. Scoped to an ACTIVE
                // threshold; branch_id comes from BranchContext, never from input.
                Rule::unique('inv_location_product_minimums', 'product_id')
                    ->where('branch_id', $branchId)
                    ->where('inventory_location_id', $locationId)
                    ->where('is_active', true)
                    ->ignore($minimum?->id),
            ],
            'minimum_stock' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'maximum_stock' => $maximumRules,
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
