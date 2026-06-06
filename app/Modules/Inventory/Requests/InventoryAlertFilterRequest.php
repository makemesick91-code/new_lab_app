<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Services\InventoryAlertService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryAlertFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inventory_location_id' => ['nullable', 'integer', 'exists:inv_inventory_locations,id'],
            'severity' => ['nullable', 'string', Rule::in([
                InventoryAlertService::SEVERITY_OUT_OF_STOCK,
                InventoryAlertService::SEVERITY_CRITICAL,
                InventoryAlertService::SEVERITY_LOW,
                InventoryAlertService::SEVERITY_BATCH_EXPIRED,
                InventoryAlertService::SEVERITY_BATCH_EXPIRING_SOON,
            ])],
            'type' => ['nullable', 'string', Rule::in(['stock', 'batch'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function locationId(): ?int
    {
        $value = $this->validated('inventory_location_id');

        return $value !== null ? (int) $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return collect($this->validated())
            ->except(['per_page', 'inventory_location_id'])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();
    }

    public function perPage(int $default = 25): int
    {
        return (int) ($this->validated('per_page') ?: $default);
    }
}
