<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-MASTERDATA-1 — the stable vocabulary for "which patient does this
 * Nomor RM name?".
 *
 * Callers branch on the CODE, never on the message, exactly as they do for
 * {@see LegacyRmeDateRuleResult}. The messages are operator-facing Indonesian
 * and may be reworded; the codes may not.
 *
 * BINDABLE IS NARROWER THAN RESOLVED. A number that resolves to more than one
 * patient IS resolved — the master data answered — but it is not bindable,
 * because choosing one of two identities is a human decision about master data,
 * not something an ambiguous query result may settle.
 */
final class LegacyRmePatientResolution
{
    /** The stored Nomor RM equals the input exactly, and names exactly one patient. */
    public const CODE_EXACT_UNIQUE = 'EXACT_UNIQUE';

    /** The stored Nomor RM equals the input exactly, but names more than one patient. */
    public const CODE_EXACT_AMBIGUOUS = 'EXACT_AMBIGUOUS';

    /** The input equals the WHOLE manual segment of exactly one canonical Nomor RM. */
    public const CODE_SEGMENT_UNIQUE = 'SEGMENT_UNIQUE';

    /** The input equals the whole manual segment of more than one canonical Nomor RM. */
    public const CODE_SEGMENT_AMBIGUOUS = 'SEGMENT_AMBIGUOUS';

    /** No patient carries this Nomor RM, in any branch, including soft-deleted rows. */
    public const CODE_NOT_FOUND = 'NOT_FOUND';

    /** Below the minimum length a suffix search is allowed to run at. */
    public const CODE_TOO_SHORT = 'TOO_SHORT';

    /** Nothing was asked. */
    public const CODE_EMPTY_INPUT = 'EMPTY_INPUT';

    /** Codes for which the master data gave a definite answer about existence. */
    public const RESOLVED = [
        self::CODE_EXACT_UNIQUE,
        self::CODE_EXACT_AMBIGUOUS,
        self::CODE_SEGMENT_UNIQUE,
        self::CODE_SEGMENT_AMBIGUOUS,
    ];

    /**
     * Codes a document may actually be bound to a patient on.
     *
     * Ambiguity is deliberately excluded: a duplicated Nomor RM is a master-data
     * defect, and picking the first row would silently file a patient's history
     * against someone else.
     */
    public const BINDABLE = [
        self::CODE_EXACT_UNIQUE,
        self::CODE_SEGMENT_UNIQUE,
    ];

    public static function isResolved(string $code): bool
    {
        return in_array($code, self::RESOLVED, true);
    }

    public static function isBindable(string $code): bool
    {
        return in_array($code, self::BINDABLE, true);
    }

    public static function explain(string $code): string
    {
        return match ($code) {
            self::CODE_EXACT_UNIQUE => 'Nomor RM cocok persis dengan satu pasien.',
            self::CODE_EXACT_AMBIGUOUS => 'Nomor RM cocok persis dengan lebih dari satu pasien. Perbaiki duplikasi master pasien terlebih dahulu.',
            self::CODE_SEGMENT_UNIQUE => 'Nomor RM manual cocok penuh dengan satu pasien.',
            self::CODE_SEGMENT_AMBIGUOUS => 'Nomor RM manual cocok penuh dengan lebih dari satu pasien. Perbaiki duplikasi master pasien terlebih dahulu.',
            self::CODE_NOT_FOUND => 'Tidak ada pasien dengan Nomor RM tersebut, termasuk pasien yang sudah dihapus. Pasien harus didaftarkan melalui pendaftaran resmi bila memang valid.',
            self::CODE_TOO_SHORT => 'Nomor RM terlalu pendek untuk dicari secara aman.',
            self::CODE_EMPTY_INPUT => 'Nomor RM tidak diisi.',
            default => 'Status resolusi tidak dikenal.',
        };
    }
}
