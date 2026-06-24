<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Requests\Concerns\ValidatesInventoryBatchInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreOpeningStockRequest extends FormRequest
{
    use ValidatesInventoryBatchInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'inventory_location_id' => ['required', 'integer', 'exists:inv_inventory_locations,id'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], $this->batchInputRules());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('batch_mode') === 'existing' && ! $this->filled('inventory_batch_id')) {
                $validator->errors()->add('inventory_batch_id', 'Pilih batch yang ada atau buat batch baru.');
            }

            if ($this->input('batch_mode') === 'new' && ! $this->filled('batch_number')) {
                $validator->errors()->add('batch_number', 'Nomor batch wajib diisi untuk batch baru.');
            }

            if ($this->input('batch_mode') === 'new' && ! $this->filled('received_date')) {
                $validator->errors()->add('received_date', 'Tanggal terima wajib diisi untuk batch baru.');
            }
        });
    }
}
