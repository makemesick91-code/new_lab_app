<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Models\InventoryLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventoryLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = app(BranchContext::class)->requireId();
        $location = $this->route('location');

        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('inv_inventory_locations', 'code')
                    ->where('branch_id', $branchId)
                    ->ignore($location?->id),
            ],
            'type' => ['required', 'string', Rule::in(InventoryLocation::TYPES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
