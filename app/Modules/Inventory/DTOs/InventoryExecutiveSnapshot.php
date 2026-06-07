<?php

namespace App\Modules\Inventory\DTOs;

/**
 * Immutable executive KPI snapshot for the inventory dashboard (Sprint 16.7).
 *
 * Governance:
 * - Inventory value is operational valuation (derived stock × average_cost), not accounting valuation.
 * - inventoryAccuracy is nullable when no completed stock opname exists — never fake 0%.
 * - This DTO must not perform queries; mapping and light formatting only.
 */
final class InventoryExecutiveSnapshot
{
    public function __construct(
        public readonly float $inventoryValue,
        public readonly int $activeSku,
        public readonly int $deadStockCount,
        public readonly int $lowStockCount,
        public readonly int $openPr,
        public readonly int $openPo,
        public readonly int $pendingGr,
        public readonly int $inTransitTransfer,
        public readonly ?float $inventoryAccuracy,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            inventoryValue: self::toFloat($data['inventory_value'] ?? null),
            activeSku: self::toInt($data['active_sku'] ?? null),
            deadStockCount: self::toInt($data['dead_stock_count'] ?? null),
            lowStockCount: self::toInt($data['low_stock_count'] ?? null),
            openPr: self::toInt($data['open_pr'] ?? null),
            openPo: self::toInt($data['open_po'] ?? null),
            pendingGr: self::toInt($data['pending_gr'] ?? null),
            inTransitTransfer: self::toInt($data['in_transit_transfer'] ?? null),
            inventoryAccuracy: self::toNullableFloat($data['inventory_accuracy'] ?? null),
        );
    }

    /**
     * @return array{
     *     inventory_value: float,
     *     active_sku: int,
     *     dead_stock_count: int,
     *     low_stock_count: int,
     *     open_pr: int,
     *     open_po: int,
     *     pending_gr: int,
     *     in_transit_transfer: int,
     *     inventory_accuracy: float|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'inventory_value' => $this->inventoryValue,
            'active_sku' => $this->activeSku,
            'dead_stock_count' => $this->deadStockCount,
            'low_stock_count' => $this->lowStockCount,
            'open_pr' => $this->openPr,
            'open_po' => $this->openPo,
            'pending_gr' => $this->pendingGr,
            'in_transit_transfer' => $this->inTransitTransfer,
            'inventory_accuracy' => $this->inventoryAccuracy,
        ];
    }

    /**
     * Minimal card view models for the executive KPI strip.
     *
     * @return array<int, array{key: string, label: string, value: float|int|null, type: string, note: string|null}>
     */
    public function toCards(): array
    {
        return [
            [
                'key' => 'inventory_value',
                'label' => 'Inventory Value',
                'value' => $this->inventoryValue,
                'type' => 'currency',
                'note' => 'Operational valuation',
            ],
            [
                'key' => 'active_sku',
                'label' => 'Active SKU',
                'value' => $this->activeSku,
                'type' => 'number',
                'note' => null,
            ],
            [
                'key' => 'dead_stock_count',
                'label' => 'Dead Stock',
                'value' => $this->deadStockCount,
                'type' => 'number',
                'note' => null,
            ],
            [
                'key' => 'low_stock_count',
                'label' => 'Low Stock',
                'value' => $this->lowStockCount,
                'type' => 'number',
                'note' => null,
            ],
            [
                'key' => 'open_pr',
                'label' => 'Open PR',
                'value' => $this->openPr,
                'type' => 'number',
                'note' => null,
            ],
            [
                'key' => 'open_po',
                'label' => 'Open PO',
                'value' => $this->openPo,
                'type' => 'number',
                'note' => null,
            ],
            [
                'key' => 'pending_gr',
                'label' => 'Pending GR',
                'value' => $this->pendingGr,
                'type' => 'number',
                'note' => null,
            ],
            [
                'key' => 'in_transit_transfer',
                'label' => 'In Transit Transfer',
                'value' => $this->inTransitTransfer,
                'type' => 'number',
                'note' => null,
            ],
            [
                'key' => 'inventory_accuracy',
                'label' => 'Inventory Accuracy',
                'value' => $this->inventoryAccuracy,
                'type' => 'percentage',
                'note' => null,
            ],
        ];
    }

    private static function toFloat(mixed $value, float $default = 0.0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    private static function toInt(mixed $value, int $default = 0): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private static function toNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
