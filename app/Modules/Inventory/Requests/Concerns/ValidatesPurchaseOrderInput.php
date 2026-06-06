<?php

namespace App\Modules\Inventory\Requests\Concerns;

trait ValidatesPurchaseOrderInput
{
    protected function prepareForValidation(): void
    {
        $currency = $this->input('currency');

        $this->merge([
            'currency' => ($currency === null || $currency === '') ? 'IDR' : $currency,
            'supplier_id' => $this->filled('supplier_id') ? $this->input('supplier_id') : null,
            'purchase_request_id' => $this->filled('purchase_request_id') ? $this->input('purchase_request_id') : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function purchaseOrderInputRules(): array
    {
        return [
            'order_date' => ['required', 'date'],
            'supplier_id' => ['nullable', 'integer', 'exists:inv_suppliers,id'],
            'purchase_request_id' => ['nullable', 'integer', 'exists:trx_purchase_requests,id'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'supplier_reference_number' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:inv_products,id'],
            'items.*.inventory_location_id' => ['nullable', 'integer', 'exists:inv_inventory_locations,id'],
            'items.*.purchase_request_item_id' => ['nullable', 'integer', 'exists:trx_purchase_request_items,id'],
            'items.*.quantity_ordered' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function purchaseOrderInputMessages(): array
    {
        return [
            'order_date.required' => 'Tanggal pesanan wajib diisi.',
            'order_date.date' => 'Tanggal pesanan tidak valid.',
            'expected_delivery_date.after_or_equal' => 'Tanggal perkiraan kirim tidak boleh sebelum tanggal pesanan.',
            'supplier_reference_number.max' => 'Nomor referensi supplier tidak boleh lebih dari 100 karakter.',
            'currency.max' => 'Mata uang tidak boleh lebih dari 10 karakter.',
            'notes.max' => 'Catatan tidak boleh lebih dari 2000 karakter.',
            'items.required' => 'Minimal satu item diperlukan.',
            'items.min' => 'Minimal satu item diperlukan.',
            'items.*.product_id.required' => 'Produk wajib dipilih.',
            'items.*.product_id.exists' => 'Produk tidak ditemukan.',
            'items.*.quantity_ordered.required' => 'Jumlah pesanan wajib diisi.',
            'items.*.quantity_ordered.gt' => 'Jumlah pesanan harus lebih dari nol.',
            'items.*.unit_price.min' => 'Harga satuan tidak boleh negatif.',
            'items.*.notes.max' => 'Catatan item tidak boleh lebih dari 500 karakter.',
            'purchase_request_id.exists' => 'Permintaan pembelian tidak ditemukan.',
            'items.*.purchase_request_item_id.exists' => 'Item permintaan pembelian tidak ditemukan.',
        ];
    }
}
