<?php

namespace App\Modules\Inventory\Requests\Concerns;

trait ValidatesInventoryBatchInput
{
    /**
     * @return array<string, mixed>
     */
    protected function batchInputRules(bool $allowCreate = true): array
    {
        $rules = [
            'batch_mode' => ['nullable', 'string', 'in:existing,new'],
            'inventory_batch_id' => ['nullable', 'integer', 'exists:inv_inventory_batches,id'],
        ];

        if ($allowCreate) {
            $rules['batch_number'] = ['nullable', 'string', 'max:100'];
            $rules['lot_number'] = ['nullable', 'string', 'max:100'];
            $rules['received_date'] = ['nullable', 'date', 'before_or_equal:today'];
            $rules['expiry_date'] = ['nullable', 'date', 'after_or_equal:received_date'];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function batchData(): ?array
    {
        if ($this->filled('inventory_batch_id')) {
            return ['inventory_batch_id' => (int) $this->input('inventory_batch_id')];
        }

        if ($this->filled('batch_number')) {
            return [
                'batch_number' => $this->input('batch_number'),
                'lot_number' => $this->input('lot_number'),
                'received_date' => $this->input('received_date'),
                'expiry_date' => $this->input('expiry_date'),
            ];
        }

        return null;
    }
}
