<?php

namespace App\Modules\Inventory\Enums;

/**
 * Operational actions for near-expiry / expired inventory batches (Sprint 68.41).
 * Recording an action does not change ledger stock.
 */
final class InventoryBatchActionType
{
    public const USE_SOON = 'use_soon';

    public const QUARANTINE = 'quarantine';

    public const RETURN_SUPPLIER = 'return_supplier';

    public const DISPOSAL_PLANNED = 'disposal_planned';

    public const RELEASED = 'released';

    public const NOTE = 'note';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::USE_SOON,
            self::QUARANTINE,
            self::RETURN_SUPPLIER,
            self::DISPOSAL_PLANNED,
            self::RELEASED,
            self::NOTE,
        ];
    }

    public static function label(string $actionType): string
    {
        return match ($actionType) {
            self::USE_SOON => 'Perlu Digunakan Segera',
            self::QUARANTINE => 'Karantina',
            self::RETURN_SUPPLIER => 'Dikembalikan ke Supplier',
            self::DISPOSAL_PLANNED => 'Rencana Pemusnahan',
            self::RELEASED => 'Dirilis / Aktif Kembali',
            self::NOTE => 'Catatan',
            default => $actionType,
        };
    }
}
