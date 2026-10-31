<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

/**
 * FEATURE-RME-RP0-CONTROL-CONSULTATION-1 (rule 217, owner decision S6-D1) — why a
 * closed Rp0 invoice was legitimately free. Persisted on the invoice
 * (`zero_value_category`, backed by a PostgreSQL CHECK) and reported separately
 * (S6-D5), so a free consultation is never read as a paid one.
 */
final class RmeZeroValueCategory
{
    /** Every line is a Konsultasi Dokter (treatment category `consultation`). */
    public const CONSULTATION = 'CONSULTATION';

    /** The visit is a server-derived Kontrol (visit_type control + a parent visit). */
    public const CONTROL = 'CONTROL';

    public const ALL = [self::CONSULTATION, self::CONTROL];

    public static function label(?string $category): string
    {
        return match ($category) {
            self::CONSULTATION => 'Konsultasi Rp0',
            self::CONTROL => 'Kontrol Rp0',
            default => 'Tanpa Biaya',
        };
    }
}
