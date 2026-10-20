<?php

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\RmeInvoice\Services\RmeInvoicePricingService;
use Illuminate\Foundation\Http\FormRequest;

class CreateRmeInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.treatment_id' => ['nullable', 'integer', 'exists:mst_treatments,id'],
            'items.*.description' => ['required', 'string', 'max:255'],
            // Storage bound (security review F2), not a business cap.
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:'.RmeInvoicePricingService::MAX_QTY],
            // A plain decimal with at most 2 places and within the decimal(15,2)
            // column: exponent notation such as 1.8e17 is numeric but wraps when
            // converted to integer cents (security review, this sprint).
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999999.99'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999999.99'],
            'items.*.doctor_id' => ['nullable', 'integer', 'exists:mst_doctors,id'],
            // FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — the written reason of a STRICT
            // price/discount exception. Read only when exceptions are switched on
            // and the actor holds the specific permission; ignored otherwise.
            'items.*.price_exception_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.required' => 'Minimal satu item tagihan wajib diisi.',
            'items.min' => 'Minimal satu item tagihan wajib diisi.',
            'items.*.description.required' => 'Deskripsi item wajib diisi.',
            'items.*.qty.required' => 'Qty item wajib diisi.',
            'items.*.qty.min' => 'Qty minimal 1.',
            'items.*.qty.max' => 'Qty melebihi batas yang dapat disimpan.',
            'items.*.unit_price.required' => 'Harga satuan wajib diisi.',
            'items.*.unit_price.min' => 'Harga satuan tidak boleh negatif.',
            'items.*.discount.min' => 'Diskon tidak boleh negatif.',
            'items.*.unit_price.decimal' => 'Harga satuan harus berupa angka dengan maksimal 2 desimal.',
            'items.*.discount.decimal' => 'Diskon harus berupa angka dengan maksimal 2 desimal.',
            'items.*.unit_price.max' => 'Harga satuan melebihi batas yang dapat disimpan.',
            'items.*.discount.max' => 'Diskon melebihi batas yang dapat disimpan.',
        ];
    }
}
