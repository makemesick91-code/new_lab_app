<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestType;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryBatchDisposalRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryBatchDisposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $batch = $this->route('inventoryBatch');

        if ($batch instanceof InventoryBatch) {
            return $this->user()?->can('createForBatch', [InventoryBatchDisposalRequest::class, $batch]) === true;
        }

        return $this->user()?->can('create', InventoryBatchDisposalRequest::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'inventory_location_id' => ['required', 'integer', 'exists:inv_inventory_locations,id'],
            'inventory_batch_action_log_id' => ['nullable', 'integer', 'exists:trx_inventory_batch_action_logs,id'],
            'request_type' => ['required', 'string', Rule::in(InventoryBatchDisposalRequestType::values())],
            'quantity_requested' => ['required', 'numeric', 'gt:0'],
            'evidence_note' => ['required', 'string', 'min:10', 'max:5000'],
            'evidence_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'inventory_location_id.required' => 'Lokasi wajib dipilih.',
            'request_type.required' => 'Jenis permintaan wajib dipilih.',
            'request_type.in' => 'Jenis permintaan tidak valid.',
            'quantity_requested.required' => 'Jumlah wajib diisi.',
            'quantity_requested.gt' => 'Jumlah harus lebih dari nol.',
            'evidence_note.required' => 'Catatan bukti wajib diisi.',
            'evidence_note.min' => 'Catatan bukti minimal 10 karakter.',
        ];
    }
}
