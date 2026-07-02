<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Enums\InventoryBatchActionType;
use App\Modules\Inventory\Models\InventoryBatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryBatchActionLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        $batch = $this->route('inventoryBatch');

        return $batch instanceof InventoryBatch
            && $this->user()?->can('recordAction', $batch) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action_type' => ['required', 'string', Rule::in(InventoryBatchActionType::values())],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action_type.required' => 'Jenis tindakan wajib dipilih.',
            'action_type.in' => 'Jenis tindakan tidak valid.',
            'note.max' => 'Catatan maksimal 2000 karakter.',
        ];
    }
}
