<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Requests\Concerns\ValidatesInventoryBatchInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAdjustmentRequest extends FormRequest
{
    use ValidatesInventoryBatchInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'inventory_location_id' => ['required', 'integer', 'exists:inv_inventory_locations,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        if ($this->isAdjustIn()) {
            return array_merge($rules, $this->batchInputRules());
        }

        return array_merge($rules, [
            'inventory_batch_id' => ['nullable', 'integer', 'exists:inv_inventory_batches,id'],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        if (! $this->isAdjustIn()) {
            return;
        }

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

    private function isAdjustIn(): bool
    {
        return $this->routeIs('inventory.products.adjust-in.store');
    }
}
