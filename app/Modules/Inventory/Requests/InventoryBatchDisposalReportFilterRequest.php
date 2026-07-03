<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Enums\InventoryBatchActionType;
use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestStatus;
use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryBatchDisposalReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:mst_branches,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', 'string', Rule::in(InventoryBatchDisposalRequestStatus::values())],
            'request_type' => ['nullable', 'string', Rule::in(InventoryBatchDisposalRequestType::values())],
            'product' => ['nullable', 'string', 'max:100'],
            'batch' => ['nullable', 'string', 'max:100'],
            'location_id' => ['nullable', 'integer', 'exists:inv_inventory_locations,id'],
            'has_movement' => ['nullable', 'string', Rule::in(['yes', 'no'])],
            'action_type' => ['nullable', 'string', Rule::in(InventoryBatchActionType::values())],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return collect($this->validated())
            ->except('per_page')
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();
    }

    public function perPage(int $default = 15): int
    {
        return (int) ($this->validated('per_page') ?: $default);
    }
}
