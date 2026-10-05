<?php

declare(strict_types=1);

namespace App\Modules\MedicalRecord\Support;

/**
 * Source filter vocabulary for the unified medical-record index.
 *
 * An unknown value normalises to ALL, which is the widest filter the actor's
 * own envelope already allows — a crafted value can narrow nothing it should
 * not and widen nothing at all.
 */
final class UnifiedMedicalRecordSource
{
    public const ALL = 'all';

    public const NATIVE = 'native';

    /** Published Legacy RME OR published Legacy Odontogram. */
    public const LEGACY = 'legacy';

    /** At least one native record AND at least one published legacy source. */
    public const NATIVE_AND_LEGACY = 'native_legacy';

    public const LEGACY_RME = 'legacy_rme';

    public const LEGACY_ODONTOGRAM = 'legacy_odontogram';

    public const ALLOWED = [
        self::ALL,
        self::NATIVE,
        self::LEGACY,
        self::NATIVE_AND_LEGACY,
        self::LEGACY_RME,
        self::LEGACY_ODONTOGRAM,
    ];

    public const LABELS = [
        self::ALL => 'Semua sumber',
        self::NATIVE => 'Native',
        self::LEGACY => 'Legacy',
        self::NATIVE_AND_LEGACY => 'Native + Legacy',
        self::LEGACY_RME => 'Legacy RME',
        self::LEGACY_ODONTOGRAM => 'Legacy Odontogram',
    ];

    public static function normalize(?string $value): string
    {
        return in_array($value, self::ALLOWED, true) ? $value : self::ALL;
    }
}
