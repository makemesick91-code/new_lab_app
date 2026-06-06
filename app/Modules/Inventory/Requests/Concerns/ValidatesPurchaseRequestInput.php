<?php

namespace App\Modules\Inventory\Requests\Concerns;

trait ValidatesPurchaseRequestInput
{
    /**
     * @return array<string, mixed>
     */
    protected function purchaseRequestInputRules(): array
    {
        return [
            'request_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:inv_products,id'],
            'items.*.inventory_location_id' => ['nullable', 'integer', 'exists:inv_inventory_locations,id'],
            'items.*.quantity_requested' => ['required', 'numeric', 'min:0.0001'],
            'items.*.estimated_unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function purchaseRequestInputMessages(): array
    {
        return [
            'request_date.required' => 'Tanggal permintaan wajib diisi.',
            'request_date.date' => 'Tanggal permintaan tidak valid.',
            'items.required' => 'Minimal satu item diperlukan.',
            'items.min' => 'Minimal satu item diperlukan.',
            'items.*.product_id.required' => 'Produk wajib dipilih.',
            'items.*.product_id.exists' => 'Produk tidak ditemukan.',
            'items.*.quantity_requested.required' => 'Jumlah yang diminta wajib diisi.',
            'items.*.quantity_requested.gt' => 'Jumlah yang diminta harus lebih dari nol.',
            'items.*.quantity_requested.min' => 'Jumlah yang diminta harus minimal 0,0001.',
            'items.*.estimated_unit_price.min' => 'Perkiraan harga satuan tidak boleh negatif.',
        ];
    }
}
