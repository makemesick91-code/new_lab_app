<?php

namespace App\Modules\PatientMerge\Support;

use Carbon\CarbonInterface;

/**
 * The identity fields reconciled one by one. Choosing which patient row
 * survives (the canonical patient) does NOT choose these values: each field
 * carries its own source — Patient A, Patient B, a manually verified value,
 * or a confirmed match.
 *
 * `critical` fields identify a person. A conflict on one of them can never be
 * left to a default; it blocks the merge until a human resolves it.
 * `sensitive` fields never render or audit unmasked.
 */
final class PatientMergeField
{
    public const FIELDS = [
        'name' => ['label' => 'Nama', 'critical' => true, 'sensitive' => false],
        'ktp_number' => ['label' => 'NIK / KTP', 'critical' => true, 'sensitive' => true],
        'date_of_birth' => ['label' => 'Tanggal Lahir', 'critical' => true, 'sensitive' => false],
        'gender' => ['label' => 'Jenis Kelamin', 'critical' => true, 'sensitive' => false],
        'phone' => ['label' => 'No. HP', 'critical' => false, 'sensitive' => true],
        'whatsapp_number' => ['label' => 'No. WhatsApp', 'critical' => false, 'sensitive' => true],
        'email' => ['label' => 'Email', 'critical' => false, 'sensitive' => false],
        'address' => ['label' => 'Alamat', 'critical' => false, 'sensitive' => false],
        'occupation' => ['label' => 'Pekerjaan', 'critical' => false, 'sensitive' => false],
    ];

    public const MATCH = 'match';

    public const CONFLICT = 'conflict';

    public const MISSING = 'missing';

    public const SOURCE_A = 'patient_a';

    public const SOURCE_B = 'patient_b';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_MATCHED = 'matched';

    public const SOURCE_EMPTY = 'empty';

    public const SOURCES = [self::SOURCE_A, self::SOURCE_B, self::SOURCE_MANUAL, self::SOURCE_MATCHED, self::SOURCE_EMPTY];

    public const SOURCE_LABELS = [
        self::SOURCE_A => 'Pasien A',
        self::SOURCE_B => 'Pasien B',
        self::SOURCE_MANUAL => 'Verifikasi Manual',
        self::SOURCE_MATCHED => 'Sama (A = B)',
        self::SOURCE_EMPTY => 'Kosong',
    ];

    /** Mirrors StorePatientRequest's `in:Male,Female,Other`. */
    public const GENDERS = ['Male', 'Female', 'Other'];

    public static function fields(): array
    {
        return array_keys(self::FIELDS);
    }

    public static function label(string $field): string
    {
        return self::FIELDS[$field]['label'] ?? $field;
    }

    public static function isCritical(string $field): bool
    {
        return (bool) (self::FIELDS[$field]['critical'] ?? false);
    }

    public static function isSensitive(string $field): bool
    {
        return (bool) (self::FIELDS[$field]['sensitive'] ?? false);
    }

    /**
     * A field value reduced to the comparable string form. Names compare
     * case- and whitespace-insensitively; phone numbers by their digits;
     * everything else trimmed. Empty is null.
     */
    public static function normalize(string $field, mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return match ($field) {
            'name', 'address', 'occupation', 'email', 'gender' => mb_strtolower((string) preg_replace('/\s+/u', ' ', $value)),
            'phone', 'whatsapp_number' => self::digits($value),
            'date_of_birth' => substr($value, 0, 10),
            default => $value,
        };
    }

    public static function digits(string $value): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $value);

        if ($digits === '') {
            return null;
        }

        // 08xx and 628xx are the same Indonesian number.
        if (str_starts_with($digits, '62')) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }
}
