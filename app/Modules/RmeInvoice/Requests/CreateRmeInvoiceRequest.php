<?php

namespace App\Modules\RmeInvoice\Requests;

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
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.doctor_id' => ['nullable', 'integer', 'exists:mst_doctors,id'],
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
            'items.*.unit_price.required' => 'Harga satuan wajib diisi.',
            'items.*.unit_price.min' => 'Harga satuan tidak boleh negatif.',
            'items.*.discount.min' => 'Diskon tidak boleh negatif.',
        ];
    }
}
