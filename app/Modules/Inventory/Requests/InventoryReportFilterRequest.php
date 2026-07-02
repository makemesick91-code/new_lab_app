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

    public const TAB_KEBAB_ALIASES = [
        'current-stock' => 'current_stock',
        'stock-card' => 'stock_card',
        'low-stock' => 'low_stock',
        'mutation' => 'mutation',
        'valuation' => 'valuation',
        'room-stock' => 'room_stock',
    ];

    public const TAB_TO_KEBAB = [
        'current_stock' => 'current-stock',
        'stock_card' => 'stock-card',
        'low_stock' => 'low-stock',
        'mutation' => 'mutation',
        'valuation' => 'valuation',
        'room_stock' => 'room-stock',
    ];

    public const REPORT_TYPES = self::REPORT_TABS;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->routeIs('inventory.reports.export') || $this->filled('report_type')) {
            return;
        }

        $tab = $this->query('tab');
        if (is_string($tab) && isset(self::TAB_KEBAB_ALIASES[$tab])) {
            $this->merge(['report_type' => self::TAB_KEBAB_ALIASES[$tab]]);

            return;
        }

        $reportTab = $this->query('report_tab');
        if (is_string($reportTab) && in_array($reportTab, self::REPORT_TABS, true)) {
            $this->merge(['report_type' => $reportTab]);
        }
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
            'inventory_batch_id' => ['nullable', 'integer', 'exists:inv_inventory_batches,id'],
            'stock_status' => ['nullable', 'string', Rule::in(['normal', 'low', 'empty', 'overstock'])],
            'movement_type' => ['nullable', 'string', Rule::in(InventoryMovement::TYPES)],
            'tab' => ['nullable', 'string'],
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

    public function resolveActiveTab(): string
    {
        $tab = $this->query('tab');

        if (is_string($tab) && isset(self::TAB_KEBAB_ALIASES[$tab])) {
            return self::TAB_KEBAB_ALIASES[$tab];
        }

        $reportTab = $this->query('report_tab', 'current_stock');

        if (is_string($reportTab) && in_array($reportTab, self::REPORT_TABS, true)) {
            return $reportTab;
        }

        return 'current_stock';
    }

    public function activeTabKebab(string $activeTab): string
    {
        return self::TAB_TO_KEBAB[$activeTab] ?? 'current-stock';
    }
}
