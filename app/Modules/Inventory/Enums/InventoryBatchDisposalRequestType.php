<?php

namespace App\Modules\Inventory\Enums;

final class InventoryBatchDisposalRequestType
{
    public const DISPOSAL = 'disposal';

    public const RETURN_SUPPLIER = 'return_supplier';

    public const QUARANTINE_ADJUSTMENT = 'quarantine_adjustment';

    public const DAMAGED = 'damaged';

    public const EXPIRED = 'expired';

    public const OTHER = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::DISPOSAL,
            self::RETURN_SUPPLIER,
            self::QUARANTINE_ADJUSTMENT,
            self::DAMAGED,
            self::EXPIRED,
            self::OTHER,
        ];
    }

    public static function label(string $type): string
    {
        return match ($type) {
            self::DISPOSAL => 'Pemusnahan',
            self::RETURN_SUPPLIER => 'Retur Supplier',
            self::QUARANTINE_ADJUSTMENT => 'Adjustment Karantina',
            self::DAMAGED => 'Rusak',
            self::EXPIRED => 'Kedaluwarsa',
            self::OTHER => 'Lainnya',
            default => $type,
        };
    }
}
