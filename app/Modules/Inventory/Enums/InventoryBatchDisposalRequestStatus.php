<?php

namespace App\Modules\Inventory\Enums;

final class InventoryBatchDisposalRequestStatus
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const ADJUSTMENT_RECORDED = 'adjustment_recorded';

    public const CANCELLED = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::DRAFT,
            self::SUBMITTED,
            self::APPROVED,
            self::REJECTED,
            self::ADJUSTMENT_RECORDED,
            self::CANCELLED,
        ];
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::DRAFT => 'Draft',
            self::SUBMITTED => 'Diajukan',
            self::APPROVED => 'Disetujui',
            self::REJECTED => 'Ditolak',
            self::ADJUSTMENT_RECORDED => 'Adjustment Dicatat',
            self::CANCELLED => 'Dibatalkan',
            default => $status,
        };
    }
}
