<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryReportFilterRequest extends FormRequest
{
    public const REPORT_TABS = [
        'current_stock',
        'stock_card',
        'low_stock',
        'mutation',
        'valuation',
        'room_stock',
    ];

    public const REPORT_TYPES = self::REPORT_TABS;

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
            'product_id' => [
                Rule::requiredIf($this->input('report_type') === 'stock_card'),
                'nullable',
                'integer',
                'exists:inv_products,id',
            ],
            'category_id' => ['nullable', 'integer', 'exists:inv_product_categories,id'],
            'inventory_location_id' => ['nullable', 'integer', 'exists:inv_inventory_locations,id'],
            'stock_status' => ['nullable', 'string', Rule::in(['normal', 'low', 'empty', 'overstock'])],
            'movement_type' => ['nullable', 'string', Rule::in(InventoryMovement::TYPES)],
            'report_tab' => ['nullable', 'string', Rule::in(self::REPORT_TABS)],
            'report_type' => [
                Rule::requiredIf($this->routeIs('inventory.reports.export')),
                'nullable',
                'string',
                Rule::in(self::REPORT_TYPES),
            ],
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
