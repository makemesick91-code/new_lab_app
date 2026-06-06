<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InventoryAnalyticsFilterRequest extends FormRequest
{
    public const TABS = [
        'summary',
        'fast',
        'slow',
        'dead',
        'aging',
        'turnover',
        'value',
        'trend',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tab' => ['nullable', 'string', Rule::in(self::TABS)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'location_id' => ['nullable', 'integer', 'exists:inv_inventory_locations,id'],
            'category_id' => ['nullable', 'integer', 'exists:inv_product_categories,id'],
            'dead_stock_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'slow_moving_threshold' => ['nullable', 'numeric', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'aging_granularity' => ['nullable', 'string', Rule::in(['product', 'batch'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $branchId = app(BranchContext::class)->requireId();

            $this->validateLocationInBranch($validator, $branchId, 'location_id', $this->input('location_id'));
            $this->validateCategoryInBranch($validator, $branchId, 'category_id', $this->input('category_id'));
            $this->validateDateRangeSpan($validator);
        });
    }

    public function tab(): string
    {
        return $this->validated('tab') ?? 'fast';
    }

    /**
     * @return array<string, mixed>
     */
    public function serviceFilters(): array
    {
        $validated = $this->validated();

        return collect([
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'inventory_location_id' => isset($validated['location_id']) ? (int) $validated['location_id'] : null,
            'product_category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            'dead_stock_days' => $validated['dead_stock_days'] ?? null,
            'slow_moving_threshold' => $validated['slow_moving_threshold'] ?? null,
            'limit' => $validated['limit'] ?? null,
            'aging_granularity' => $validated['aging_granularity'] ?? null,
        ])->filter(fn ($value) => $value !== null && $value !== '')->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function viewFilters(): array
    {
        return $this->validated();
    }

    private function validateLocationInBranch(
        Validator $validator,
        int $branchId,
        string $field,
        mixed $locationId,
    ): void {
        if (! is_numeric($locationId)) {
            return;
        }

        $location = InventoryLocation::query()
            ->where('branch_id', $branchId)
            ->whereKey((int) $locationId)
            ->first();

        if (! $location) {
            $validator->errors()->add($field, 'Lokasi persediaan tidak valid untuk cabang aktif.');
        }
    }

    private function validateCategoryInBranch(
        Validator $validator,
        int $branchId,
        string $field,
        mixed $categoryId,
    ): void {
        if (! is_numeric($categoryId)) {
            return;
        }

        $category = ProductCategory::query()
            ->where('branch_id', $branchId)
            ->whereKey((int) $categoryId)
            ->first();

        if (! $category) {
            $validator->errors()->add($field, 'Kategori produk tidak valid untuk cabang aktif.');
        }
    }

    private function validateDateRangeSpan(Validator $validator): void
    {
        $dateFrom = $this->input('date_from');
        $dateTo = $this->input('date_to');

        if (! $dateFrom || ! $dateTo) {
            return;
        }

        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->startOfDay();

        if ($from->diffInDays($to) > 365) {
            $validator->errors()->add('date_to', 'Rentang tanggal tidak boleh lebih dari 365 hari.');
        }
    }
}
